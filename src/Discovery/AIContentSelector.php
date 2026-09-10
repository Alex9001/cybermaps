<?php
declare(strict_types=1);

namespace Cybermaps\Discovery;

use Cybermaps\SEO\PublicationEligibility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical content-selection policy for AI sitemap and RAG publications.
 *
 * Both the dynamic endpoints and the static publisher consume this service so
 * the AI sitemap cannot advertise chunks that the publisher considers
 * ineligible (or publish chunks for content excluded from the sitemap).
 */
class AIContentSelector {
	private const QUERY_BATCH_SIZE           = 250;
	private const CACHE_HYDRATION_BATCH_SIZE = 250;
	private const MAX_CANDIDATES_PER_TYPE    = 5000;
	private const INVENTORY_CACHE_KEY        = 'cybermaps_ai_publication_inventory';
	private const INVENTORY_CACHE_TTL        = 15 * MINUTE_IN_SECONDS;

	/**
	 * Current settings snapshot.
	 *
	 * @var array<string, mixed>
	 */
	private array $settings;

	/**
	 * Optional query adapter used by focused unit tests.
	 *
	 * @var callable|null
	 */
	private $post_query;

	/**
	 * Optional taxonomy resolver used by focused unit tests.
	 *
	 * @var callable|null
	 */
	private $taxonomy_query;

	/**
	 * Request-local selected post cache.
	 *
	 * @var array<int, object>|null
	 */
	private ?array $selected_posts = null;

	/**
	 * Request-local ID lookup matching selected_posts.
	 *
	 * @var array<int, bool>
	 */
	private array $selected_lookup = array();

	/**
	 * Request-local lookup for a persisted inventory used by contains().
	 *
	 * This avoids repeatedly normalizing and scanning the same bounded transient
	 * when several RAG routes are authorized during one request.
	 *
	 * @var array<int, bool>|null
	 */
	private ?array $inventory_lookup = null;

	/**
	 * Request-local post type weights.
	 *
	 * @var array<string, float>|null
	 */
	private ?array $weights = null;

	private PublicationEligibility $eligibility;

	/**
	 * @param array<string, mixed>|null $settings Optional settings snapshot.
	 * @param callable|null             $post_query Optional get_posts-compatible query adapter.
	 * @param callable|null             $taxonomy_query Optional get_object_taxonomies-compatible adapter.
	 */
	public function __construct(
		?array $settings = null,
		?callable $post_query = null,
		?callable $taxonomy_query = null,
		?PublicationEligibility $eligibility = null
	) {
		$this->settings       = null === $settings
			? \Cybermaps\Core\ConfigurationStore::settings()
			: $settings;
		$this->post_query     = $post_query;
		$this->taxonomy_query = $taxonomy_query;
		$this->eligibility    = $eligibility ?? new PublicationEligibility( null, $this->settings );
	}

	/**
	 * Return the exact published-post inventory used by AI discovery.
	 *
	 * The configured limit remains a per-post-type sitemap limit. Ordering by
	 * modification time with an ID tie-breaker makes that bounded inventory stable
	 * across the sitemap, static chunk sync, and dynamic chunk authorization.
	 *
	 * @return array<int, object>
	 */
	public function get_posts(): array {
		if ( null !== $this->selected_posts ) {
			return $this->selected_posts;
		}

		$cached_posts = $this->get_cached_posts();
		if ( null !== $cached_posts ) {
			$this->selected_posts = $cached_posts;
			return $this->selected_posts;
		}

		$cache_generation = \Cybermaps\Core\CacheManager::get_generation( 'discovery' );

		$this->selected_lookup = array();

		$limit = PublicationConstraints::ai_sitemap_limit(
			$this->settings['ai_sitemap_limit'] ?? PublicationConstraints::AI_SITEMAP_LIMIT_DEFAULT
		);

		$selected_posts = array();
		foreach ( $this->get_included_post_types() as $post_type ) {
			$selected_posts = array_merge( $selected_posts, $this->select_post_type( $post_type, $limit ) );
		}

		$this->selected_posts = $selected_posts;
		$this->cache_selected_posts( $cache_generation );
		return $this->selected_posts;
	}

	/**
	 * Return a valid persisted inventory when default query adapters are active.
	 *
	 * @return array<int, object>|null
	 */
	private function get_cached_posts(): ?array {
		if ( ! $this->uses_default_queries() ) {
			return null;
		}
		$cached_ids = \Cybermaps\Core\CacheManager::get( self::INVENTORY_CACHE_KEY, 'discovery', $cache_found );
		if ( ! $cache_found || ! \is_array( $cached_ids ) ) {
			return null;
		}

		return $this->hydrate_cached_posts( $this->normalize_cached_ids( $cached_ids ) );
	}

	/**
	 * Select a bounded inventory for one post type.
	 *
	 * @return array<int, object>
	 */
	private function select_post_type( string $post_type, int $limit ): array {
		if ( $this->should_skip_post_type( $post_type ) ) {
			return array();
		}

		$selected  = array();
		$seen      = array();
		$page      = 1;
		$inspected = 0;
		do {
			$batch_size = min( self::QUERY_BATCH_SIZE, self::MAX_CANDIDATES_PER_TYPE - $inspected );
			$posts      = $this->query_selection_batch( $post_type, $page, $batch_size );
			$inspected += \count( $posts );
			if ( empty( $posts ) ) {
				break;
			}

			$saw_new = $this->append_selection_batch( $selected, $seen, $posts, $post_type, $limit );
			if ( $this->selection_is_complete( \count( $selected ), $limit, $inspected, \count( $posts ), $batch_size, $saw_new ) ) {
				break;
			}
			++$page;
		} while ( true );

		return $selected;
	}

	/**
	 * Append eligible, previously unseen candidates from one page.
	 *
	 * @param array<int, object> $selected Selected posts.
	 * @param array<int, bool>   $seen     Seen IDs.
	 * @param array<int, object> $posts    Candidate page.
	 */
	private function append_selection_batch( array &$selected, array &$seen, array $posts, string $post_type, int $limit ): bool {
		$saw_new = false;
		foreach ( $posts as $post ) {
			$post_id = \is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : 0;
			if ( $post_id < 1 || isset( $seen[ $post_id ] ) ) {
				continue;
			}
			$seen[ $post_id ] = true;
			$saw_new          = true;
			if ( ! $this->candidate_is_eligible( $post, $post_type ) ) {
				continue;
			}
			$selected[] = $post;

			$this->selected_lookup[ $post_id ] = true;
			if ( \count( $selected ) >= $limit ) {
				break;
			}
		}

		return $saw_new;
	}

	/**
	 * Determine whether another page can contribute to this post type.
	 */
	private function selection_is_complete( int $selected, int $limit, int $inspected, int $post_count, int $batch_size, bool $saw_new ): bool {
		return $selected >= $limit
			|| $inspected >= self::MAX_CANDIDATES_PER_TYPE
			|| $post_count < $batch_size
			|| ! $saw_new;
	}

	/**
	 * Persist the selected IDs only for production query adapters.
	 */
	private function cache_selected_posts( int $generation ): void {
		if ( ! $this->uses_default_queries() ) {
			return;
		}
		\Cybermaps\Core\CacheManager::set_if_current(
			self::INVENTORY_CACHE_KEY,
			\array_map( static fn( object $post ): int => (int) ( $post->ID ?? 0 ), $this->selected_posts ?? array() ),
			self::INVENTORY_CACHE_TTL,
			'discovery',
			$generation
		);
	}

	/**
	 * Return a bounded page of selected IDs plus a constant-size continuation.
	 *
	 * Static chunk publication uses this path so it never has to materialize the
	 * complete selected post inventory or persist every selected ID in cron state.
	 * Content mutations advance the static generation, making the paged ordering
	 * stable for the lifetime of one continuation.
	 *
	 * @param array<string,mixed> $cursor Opaque selector cursor from a prior call.
	 * @return array{ids:int[],cursor:array<string,int>,complete:bool}
	 */
	public function get_id_batch( array $cursor = array(), int $limit = 25 ): array {
		$this->assert_valid_id_cursor( $cursor );
		$types          = $this->get_included_post_types();
		$state          = $this->initial_batch_state( $cursor, $limit );
		$selected_limit = PublicationConstraints::ai_sitemap_limit(
			$this->settings['ai_sitemap_limit'] ?? PublicationConstraints::AI_SITEMAP_LIMIT_DEFAULT
		);
		$type_count     = \count( $types );
		while ( $this->batch_has_capacity( $state, $type_count ) ) {
			$this->process_batch_type( $types, $state, $selected_limit );
		}

		return array(
			'ids'      => $state['ids'],
			'cursor'   => array(
				'type_index'    => $state['type_index'],
				'page'          => $state['page'],
				'position'      => $state['position'],
				'type_selected' => $state['type_selected'],
				'inspected'     => $state['inspected'],
			),
			'complete' => $state['type_index'] >= $type_count,
		);
	}

	/**
	 * Create mutable state for bounded ID selection.
	 *
	 * @param array<string, mixed> $cursor Validated continuation cursor.
	 * @return array<string, mixed>
	 */
	private function initial_batch_state( array $cursor, int $limit ): array {
		return array(
			'type_index'    => max( 0, (int) ( $cursor['type_index'] ?? 0 ) ),
			'page'          => max( 1, (int) ( $cursor['page'] ?? 1 ) ),
			'position'      => max( 0, (int) ( $cursor['position'] ?? 0 ) ),
			'type_selected' => max( 0, (int) ( $cursor['type_selected'] ?? 0 ) ),
			'inspected'     => max( 0, (int) ( $cursor['inspected'] ?? 0 ) ),
			'requested'     => max( 1, min( self::QUERY_BATCH_SIZE, $limit ) ),
			'id_count'      => 0,
			'ids'           => array(),
			'seen_ids'      => array(),
		);
	}

	/**
	 * Determine whether the current request can consume another candidate page.
	 *
	 * @param array<string, mixed> $state Batch state.
	 */
	private function batch_has_capacity( array $state, int $type_count ): bool {
		return $state['type_index'] < $type_count && $state['id_count'] < $state['requested'];
	}

	/**
	 * Process one page or advance one completed post type.
	 *
	 * @param string[]             $types Included post types.
	 * @param array<string, mixed> $state Mutable batch state.
	 */
	private function process_batch_type( array $types, array &$state, int $selected_limit ): void {
		$post_type = (string) $types[ $state['type_index'] ];
		if ( $this->batch_type_is_complete( $post_type, $state, $selected_limit ) ) {
			$this->advance_batch_type( $state );
			return;
		}

		$batch_size = min( self::QUERY_BATCH_SIZE, self::MAX_CANDIDATES_PER_TYPE - $state['inspected'] );
		$posts      = $this->query_selection_batch( $post_type, $state['page'], $batch_size );
		$post_count = \count( $posts );
		if ( 0 === $post_count ) {
			$this->advance_batch_type( $state );
			return;
		}

		$this->collect_batch_ids( $posts, $post_type, $state );
		if ( $state['type_selected'] >= $selected_limit ) {
			$this->advance_batch_type( $state );
			return;
		}
		if ( $state['position'] >= $post_count ) {
			$this->advance_batch_page( $state, $post_count, $batch_size );
		}
	}

	/**
	 * Determine whether a cursor has exhausted its current post type.
	 *
	 * @param array<string, mixed> $state Batch state.
	 */
	private function batch_type_is_complete( string $post_type, array $state, int $selected_limit ): bool {
		return $this->should_skip_post_type( $post_type )
			|| $state['type_selected'] >= $selected_limit
			|| $state['inspected'] >= self::MAX_CANDIDATES_PER_TYPE;
	}

	/**
	 * Collect eligible IDs until the current page or requested slice is full.
	 *
	 * @param array<int, object>   $posts Candidate page.
	 * @param array<string, mixed> $state Mutable batch state.
	 */
	private function collect_batch_ids( array $posts, string $post_type, array &$state ): void {
		$post_count = \count( $posts );
		while ( $state['position'] < $post_count && $state['id_count'] < $state['requested'] ) {
			$post = $posts[ $state['position'] ];
			++$state['position'];
			++$state['inspected'];
			$post_id = \is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : 0;
			if ( $post_id < 1 || isset( $state['seen_ids'][ $post_id ] ) || ! $this->candidate_is_eligible( $post, $post_type ) ) {
				continue;
			}
			$state['ids'][]                = $post_id;
			$state['seen_ids'][ $post_id ] = true;
			++$state['id_count'];
			++$state['type_selected'];
		}
	}

	/**
	 * Advance to the next post type and reset type-local continuation state.
	 *
	 * @param array<string, mixed> $state Mutable batch state.
	 */
	private function advance_batch_type( array &$state ): void {
		++$state['type_index'];
		$state['page']          = 1;
		$state['position']      = 0;
		$state['type_selected'] = 0;
		$state['inspected']     = 0;
	}

	/**
	 * Advance after consuming a complete candidate page.
	 *
	 * @param array<string, mixed> $state Mutable batch state.
	 */
	private function advance_batch_page( array &$state, int $post_count, int $batch_size ): void {
		if ( $post_count < $batch_size || $state['inspected'] >= self::MAX_CANDIDATES_PER_TYPE ) {
			$this->advance_batch_type( $state );
			return;
		}
		++$state['page'];
		$state['position'] = 0;
	}

	/**
	 * Decide whether a post type can contribute to AI publications.
	 */
	private function should_skip_post_type( string $post_type ): bool {
		return ( \function_exists( 'post_type_exists' ) && ! \post_type_exists( $post_type ) )
			|| $this->get_weight( $post_type ) <= 0;
	}

	/**
	 * Fill missing query fields before applying the shared eligibility policy.
	 *
	 * @param mixed $post Candidate post.
	 */
	private function candidate_is_eligible( $post, string $post_type ): bool {
		if ( ! \is_object( $post ) ) {
			return false;
		}
		$candidate = clone $post;
		if ( ! isset( $candidate->post_type ) ) {
			$candidate->post_type = $post_type;
		}
		if ( ! isset( $candidate->post_status ) ) {
			$candidate->post_status = 'publish';
		}
		if ( ! isset( $candidate->post_password ) ) {
			$candidate->post_password = '';
		}
		return $this->eligibility->post( $candidate, PublicationEligibility::AI )->indexable;
	}

	/**
	 * Whether production WordPress query adapters are in use.
	 */
	private function uses_default_queries(): bool {
		return null === $this->post_query && null === $this->taxonomy_query;
	}

	/**
	 * Reject corrupt caller state instead of coercing it into silent progress.
	 *
	 * @param array<string,mixed> $cursor Cursor to validate.
	 */
	private function assert_valid_id_cursor( array $cursor ): void {
		$bounds = array(
			'type_index'    => array( 0, 10000 ),
			'page'          => array( 1, 1000000 ),
			'position'      => array( 0, self::QUERY_BATCH_SIZE ),
			'type_selected' => array( 0, 100000 ),
			'inspected'     => array( 0, self::MAX_CANDIDATES_PER_TYPE ),
		);
		if ( array() !== \array_diff( \array_keys( $cursor ), \array_keys( $bounds ) ) ) {
			throw new \InvalidArgumentException( 'The AI content selection cursor contains unknown fields.' );
		}
		foreach ( $bounds as $key => $range ) {
			if ( ! \array_key_exists( $key, $cursor ) ) {
				continue;
			}
			if (
				! \is_int( $cursor[ $key ] )
				|| $cursor[ $key ] < $range[0]
				|| $cursor[ $key ] > $range[1]
			) {
				throw new \InvalidArgumentException( 'The AI content selection cursor is invalid.' );
			}
		}
	}

	/**
	 * Query one bounded candidate page using the canonical selection filters.
	 *
	 * @return array<int,object>
	 */
	private function query_selection_batch( string $post_type, int $page, int $batch_size ): array {
		$args = array(
			'post_type'           => $post_type,
			'posts_per_page'      => $batch_size,
			'paged'               => $page,
			'post_status'         => 'publish',
			'has_password'        => false,
			'orderby'             => array(
				'modified' => 'DESC',
				'ID'       => 'DESC',
			),
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'meta_query'          => array(
				'relation' => 'OR',
				array(
					'key'     => '_cybermaps_exclude_ai',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_cybermaps_exclude_ai',
					'value'   => '1',
					'compare' => '!=',
				),
			),
		);

		$exclude_ids = $this->get_excluded_ids();
		if ( ! empty( $exclude_ids ) ) {
			// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
			$args['post__not_in'] = $exclude_ids;
		}

		$taxonomies    = null !== $this->taxonomy_query
			? (array) \call_user_func( $this->taxonomy_query, $post_type )
			: ( \function_exists( 'get_object_taxonomies' ) ? (array) \get_object_taxonomies( $post_type ) : array() );
		$exclude_terms = $this->get_excluded_terms();
		if ( ! empty( $exclude_terms ) && ! empty( $taxonomies ) ) {
			$tax_query = array( 'relation' => 'AND' );
			foreach ( $taxonomies as $taxonomy ) {
				$tax_query[] = array(
					'taxonomy' => (string) $taxonomy,
					'field'    => 'slug',
					'terms'    => $exclude_terms,
					'operator' => 'NOT IN',
				);
			}
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			$args['tax_query'] = $tax_query;
		}

		$posts = null !== $this->post_query
			? \call_user_func( $this->post_query, $args )
			: \get_posts( $args );
		return \is_array( $posts ) ? \array_values( $posts ) : array();
	}

	/**
	 * Determine whether a post is in the exact advertised inventory.
	 */
	public function contains( int $post_id ): bool {
		if ( $post_id < 1 ) {
			return false;
		}

		if ( $this->uses_default_queries() ) {
			$post = \get_post( $post_id );
			if ( ! $this->runtime_post_is_eligible( $post ) ) {
				return false;
			}
			$cached_result = $this->cached_inventory_contains( $post_id );
			if ( null !== $cached_result ) {
				return $cached_result;
			}
		}

		$this->get_posts();
		return isset( $this->selected_lookup[ $post_id ] );
	}

	/**
	 * Apply cheap runtime guards before loading or rebuilding the inventory.
	 *
	 * @param mixed $post Candidate post.
	 */
	private function runtime_post_is_eligible( $post ): bool {
		if ( ! \is_object( $post ) ) {
			return false;
		}
		$post_type = (string) ( $post->post_type ?? '' );
		return \in_array( $post_type, $this->get_included_post_types(), true )
			&& $this->get_weight( $post_type ) > 0
			&& $this->eligibility->post( $post, PublicationEligibility::AI )->indexable;
	}

	/**
	 * Return null when no persisted inventory is available.
	 */
	private function cached_inventory_contains( int $post_id ): ?bool {
		if ( null === $this->inventory_lookup ) {
			$cached_ids = \Cybermaps\Core\CacheManager::get( self::INVENTORY_CACHE_KEY, 'discovery', $cache_found );
			if ( $cache_found && \is_array( $cached_ids ) ) {
				$this->inventory_lookup = \array_fill_keys( $this->normalize_cached_ids( $cached_ids ), true );
			}
		}
		return null === $this->inventory_lookup ? null : isset( $this->inventory_lookup[ $post_id ] );
	}

	/**
	 * Normalize a persisted inventory without changing its publication order.
	 *
	 * @param array<int, mixed> $cached_ids Stored transient payload.
	 * @return int[]
	 */
	private function normalize_cached_ids( array $cached_ids ): array {
		$normalized = array();
		$seen       = array();

		foreach ( $cached_ids as $cached_id ) {
			if ( ! \is_scalar( $cached_id ) ) {
				continue;
			}

			$post_id = \absint( $cached_id );
			if ( $post_id < 1 || isset( $seen[ $post_id ] ) ) {
				continue;
			}

			$seen[ $post_id ] = true;
			$normalized[]     = $post_id;
		}

		return $normalized;
	}

	/**
	 * Hydrate cached IDs in bounded queries instead of one get_post() query per
	 * resource after the request-local WordPress object cache has reset.
	 *
	 * @param int[] $cached_ids Normalized cached publication order.
	 * @return array<int, object>
	 */
	private function hydrate_cached_posts( array $cached_ids ): array {
		$this->selected_lookup = array();
		if ( empty( $cached_ids ) ) {
			return array();
		}

		$post_types = $this->get_included_post_types();
		if ( empty( $post_types ) ) {
			return array();
		}

		$posts_by_id = array();
		foreach ( \array_chunk( $cached_ids, self::CACHE_HYDRATION_BATCH_SIZE ) as $batch ) {
			$posts_by_id += $this->hydrate_post_batch( $batch, $post_types );
		}

		$hydrated = array();
		foreach ( $cached_ids as $post_id ) {
			if ( ! isset( $posts_by_id[ $post_id ] ) ) {
				continue;
			}

			$hydrated[]                        = $posts_by_id[ $post_id ];
			$this->selected_lookup[ $post_id ] = true;
		}

		return $hydrated;
	}

	/**
	 * Hydrate one bounded ID batch into an ID-keyed map.
	 *
	 * @param int[]    $batch      Post IDs.
	 * @param string[] $post_types Included post types.
	 * @return array<int, object>
	 */
	private function hydrate_post_batch( array $batch, array $post_types ): array {
		$posts = \get_posts(
			array(
				'post_type'              => $post_types,
				'post_status'            => 'publish',
				'post__in'               => $batch,
				'posts_per_page'         => \count( $batch ),
				'orderby'                => 'post__in',
				'order'                  => 'ASC',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
				'suppress_filters'       => false,
			)
		);

		$posts_by_id = array();
		foreach ( \is_array( $posts ) ? $posts : array() as $post ) {
			$post_id = \is_object( $post ) ? (int) ( $post->ID ?? 0 ) : 0;
			if ( $post_id > 0 ) {
				$posts_by_id[ $post_id ] = $post;
			}
		}

		return $posts_by_id;
	}

	/**
	 * Return the AI sitemap weight for a post type.
	 */
	public function get_weight( string $post_type ): float {
		if ( null === $this->weights ) {
			$this->weights = array();
		}
		if ( ! \array_key_exists( $post_type, $this->weights ) ) {
			$this->weights[ $post_type ] = (float) \Cybermaps\Sitemap\PriorityEngine::calculate(
				\Cybermaps\Sitemap\ProviderIdentity::post_type( $post_type )
			);
		}

		return $this->weights[ $post_type ] ?? 0.5;
	}

	/**
	 * Return normalized post types in their configured order.
	 *
	 * @return string[]
	 */
	private function get_included_post_types(): array {
		$types      = isset( $this->settings['ai_sitemap_types'] )
			? (array) $this->settings['ai_sitemap_types']
			: array( 'post', 'page' );
		$normalized = array();

		foreach ( $types as $type ) {
			$type = \sanitize_key( (string) $type );
			if ( '' !== $type && ! \in_array( $type, $normalized, true ) ) {
				$normalized[] = $type;
			}
		}

		return array_values(
			array_intersect(
				\Cybermaps\Core\PublicationPostTypes::filter_names( $normalized ),
				\Cybermaps\Core\PublicationPostTypes::names()
			)
		);
	}

	/**
	 * @return int[]
	 */
	private function get_excluded_ids(): array {
		return \Cybermaps\Core\PositiveIdList::parse( $this->settings['llms_exclude_ids'] ?? '' );
	}

	/**
	 * @return string[]
	 */
	private function get_excluded_terms(): array {
		$value = $this->settings['ai_sitemap_exclude_terms'] ?? '';
		$terms = \is_array( $value ) ? $value : \explode( ',', (string) $value );

		return \array_values(
			\array_unique(
				\array_filter(
					\array_map(
						static fn( $term ): string => \sanitize_title( \trim( (string) $term ) ),
						$terms
					)
				)
			)
		);
	}
}
