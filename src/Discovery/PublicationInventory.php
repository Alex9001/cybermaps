<?php
declare(strict_types=1);

namespace Cybermaps\Discovery;

use Cybermaps\SEO\PublicationEligibility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Complete, deterministic inventory shared by literal AI text publications.
 */
final class PublicationInventory {
	private const QUERY_BATCH_SIZE = 100;

	private array $settings;
	private PublicationEligibility $eligibility;
	private string $language;

	public function __construct(
		?array $settings = null,
		?PublicationEligibility $eligibility = null,
		string $language = ''
	) {
		$this->settings    = $settings ?? \Cybermaps\Core\ConfigurationStore::settings();
		$this->eligibility = $eligibility ?? new PublicationEligibility( null, $this->settings );
		$this->language    = sanitize_key( $language );
	}

	/**
	 * Iterate through the complete eligible inventory without retaining every
	 * post object in one PHP array.
	 *
	 * Posts are fetched in small, non-caching batches. Iterating one configured
	 * type at a time preserves the documented type priority followed by
	 * modified-descending, ID-ascending order without a whole-corpus sort or an
	 * N+1 query for every post.
	 *
	 * @param int[] $skip_ids Eligible IDs the caller already emitted.
	 * @return \Generator<int, object>
	 */
	public function iterate_posts( array $skip_ids = array() ): \Generator {
		$post_types = $this->post_types();
		if ( empty( $post_types ) ) {
			return;
		}

		$skip = array_fill_keys(
			array_values(
				array_filter(
					array_map( 'absint', $skip_ids )
				)
			),
			true
		);
		foreach ( $post_types as $post_type ) {
			yield from $this->iterate_post_type( $post_type, $skip );
		}
	}

	/**
	 * @param array<int,bool> $skip IDs already emitted.
	 * @return \Generator<int,object>
	 */
	private function iterate_post_type( string $post_type, array $skip ): \Generator {
		$snapshot_id    = $this->snapshot_max_id( $post_type );
		$after_modified = '';
		$after_id       = 0;
		if ( $snapshot_id < 1 ) {
			return;
		}
		do {
			$previous_modified = $after_modified;
			$previous_id       = $after_id;
			$candidates        = $this->get_keyset_posts(
				$this->batch_args( $post_type, $snapshot_id, $after_modified, $after_id )
			);
			if ( ! is_array( $candidates ) ) {
				break;
			}
			$candidate_count = count( $candidates );
			$this->prime_candidates( $candidates, $post_type );
			foreach ( $candidates as $candidate ) {
				$state = $this->candidate_state( $candidate, $skip );
				if ( $state['eligible'] ) {
					yield $state['post'];
				}
				if ( null !== $state['modified'] ) {
					$after_modified = $state['modified'];
					$after_id       = $state['id'];
				}
			}
			$this->require_cursor_progress( $candidate_count, $previous_modified, $previous_id, $after_modified, $after_id );
			$this->flush_runtime_cache();
			$this->heartbeat_static_operation();
		} while ( self::QUERY_BATCH_SIZE === $candidate_count );
	}

	/** Fail explicitly if another query filter prevents the next batch advancing. */
	private function require_cursor_progress( int $count, string $previous, int $previous_id, string $current, int $current_id ): void {
		if ( self::QUERY_BATCH_SIZE !== $count ) {
			return;
		}
		if ( '' === $current || $current_id < 1 || ( '' !== $previous && ( $current > $previous || ( $current === $previous && $current_id <= $previous_id ) ) ) ) {
			throw new \RuntimeException( esc_html__( 'Cybermaps stopped publication because content pagination did not advance. No complete publication was produced.', 'cybermaps' ) );
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	private function batch_args( string $post_type, int $snapshot_id, string $modified, int $after_id ): array {
		return $this->get_query_args(
			array(
				'post_type'                => array( $post_type ),
				'posts_per_page'           => self::QUERY_BATCH_SIZE,
				'orderby'                  => array(
					'modified' => 'DESC',
					'ID'       => 'ASC',
				),
				'cybermaps_snapshot_id'    => $snapshot_id,
				'cybermaps_after_modified' => $modified,
				'cybermaps_after_id'       => $after_id,
				'cache_results'            => false,
				'update_post_meta_cache'   => false,
				'update_post_term_cache'   => false,
			)
		);
	}

	/**
	 * @param array<int,mixed> $candidates Candidate posts or IDs.
	 */
	private function prime_candidates( array $candidates, string $post_type ): void {
		$ids = array();
		foreach ( $candidates as $candidate ) {
			$post_id = is_object( $candidate ) && isset( $candidate->ID )
				? (int) $candidate->ID
				: absint( $candidate );
			if ( $post_id > 0 ) {
				$ids[] = $post_id;
			}
		}
		if ( empty( $ids ) ) {
			return;
		}
		update_postmeta_cache( $ids );
		if ( $this->batch_requires_term_cache() ) {
			update_object_term_cache( $ids, $post_type );
		}
	}

	/**
	 * @param array<int,bool> $skip IDs already emitted.
	 * @return array{post:mixed,id:int,modified:?string,eligible:bool}
	 */
	private function candidate_state( mixed $candidate, array $skip ): array {
		$post     = is_object( $candidate ) ? $candidate : get_post( absint( $candidate ) );
		$post_id  = is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : 0;
		$modified = is_object( $post )
			? (string) ( $post->post_modified ?? $post->post_modified_gmt ?? $post->post_date_gmt ?? '' )
			: null;
		$eligible = $post_id > 0
			&& ! isset( $skip[ $post_id ] )
			&& $this->eligibility->post( $post, PublicationEligibility::AI )->indexable;
		return array(
			'post'     => $post,
			'id'       => $post_id,
			'modified' => $modified,
			'eligible' => $eligible,
		);
	}

	/**
	 * Freeze one type at its current maximum ID so new inserts cannot extend a
	 * long-running publication while keyset batches are being consumed.
	 */
	private function snapshot_max_id( string $post_type ): int {
		$ids = get_posts(
			$this->get_query_args(
				array(
					'post_type'              => array( $post_type ),
					'fields'                 => 'ids',
					'posts_per_page'         => 1,
					'orderby'                => 'ID',
					'order'                  => 'DESC',
					'cache_results'          => false,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			)
		);
		return \is_array( $ids ) && isset( $ids[0] ) ? max( 0, (int) $ids[0] ) : 0;
	}

	/**
	 * Query the next deterministic keyset slice without OFFSET.
	 *
	 * @param array<string,mixed> $args Query arguments.
	 * @return object[]
	 */
	private function get_keyset_posts( array $args ): array {
		// get_posts() suppresses posts_where by default, which would repeat page one.
		$args['suppress_filters'] = false;
		$filter                   = static function ( string $where, $query ): string {
			if ( ! \is_object( $query ) || ! \method_exists( $query, 'get' ) ) {
				return $where;
			}
			$snapshot = (int) $query->get( 'cybermaps_snapshot_id' );
			$modified = (string) $query->get( 'cybermaps_after_modified' );
			$after_id = (int) $query->get( 'cybermaps_after_id' );
			if ( $snapshot < 1 ) {
				return $where;
			}

			global $wpdb;
			$where .= $wpdb->prepare( ' AND %i.ID <= %d', $wpdb->posts, $snapshot );
			if ( '' !== $modified ) {
				$where .= $wpdb->prepare(
					' AND (%i.post_modified < %s OR (%i.post_modified = %s AND %i.ID > %d))',
					$wpdb->posts,
					$modified,
					$wpdb->posts,
					$modified,
					$wpdb->posts,
					$after_id
				);
			}
			return $where;
		};

		\add_filter( 'posts_where', $filter, 10, 2 );
		try {
			$candidates = get_posts( $args );
		} finally {
			\remove_filter( 'posts_where', $filter, 10 );
		}
		return \is_array( $candidates ) ? \array_values( $candidates ) : array();
	}

	/**
	 * Cheap upper bound on published candidates for header width reservation.
	 */
	public function published_candidate_upper_bound(): int {
		$total = 0;
		foreach ( $this->post_types() as $post_type ) {
			$counts = \wp_count_posts( $post_type );
			if ( \is_object( $counts ) && isset( $counts->publish ) ) {
				$total += max( 0, (int) $counts->publish );
			}
		}

		return $total;
	}

	/**
	 * Count every eligible resource without retaining its post object.
	 */
	public function count_posts(): int {
		$count = 0;
		foreach ( $this->iterate_posts() as $post ) {
			unset( $post );
			++$count;
		}

		return $count;
	}

	/**
	 * Iterate a bounded priority list in the administrator's exact ID order.
	 *
	 * @param int[] $ids Candidate post IDs.
	 * @return \Generator<int, object>
	 */
	public function iterate_posts_by_ids( array $ids ): \Generator {
		$ids = array_values(
			array_unique(
				array_filter(
					array_map( 'absint', $ids )
				)
			)
		);
		if ( empty( $ids ) || empty( $this->post_types() ) ) {
			return;
		}

		foreach ( array_chunk( $ids, self::QUERY_BATCH_SIZE ) as $batch_ids ) {
			$args       = $this->get_query_args(
				array(
					'post__in'               => $batch_ids,
					'posts_per_page'         => count( $batch_ids ),
					'orderby'                => 'post__in',
					'cache_results'          => false,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);
			$candidates = get_posts( $args );
			if ( ! is_array( $candidates ) ) {
				continue;
			}

			$eligible_by_id = array();
			foreach ( $candidates as $candidate ) {
				$post = is_object( $candidate )
					? $candidate
					: get_post( absint( $candidate ) );
				if (
					is_object( $post )
					&& isset( $post->ID )
					&& $this->eligibility->post( $post, PublicationEligibility::AI )->indexable
				) {
					$eligible_by_id[ (int) $post->ID ] = $post;
				}
			}

			foreach ( $batch_ids as $id ) {
				if ( isset( $eligible_by_id[ $id ] ) ) {
					yield $eligible_by_id[ $id ];
				}
			}

			unset( $candidates, $eligible_by_id );
			$this->flush_runtime_cache();
			$this->heartbeat_static_operation();
		}
	}

	/**
	 * Build the canonical LLMS publication query policy.
	 *
	 * Search-like consumers may add literal query arguments while retaining the
	 * configured content types, explicit ID exclusions, taxonomy filter and
	 * active translation context.
	 *
	 * @param array<string, mixed> $overrides Query-specific arguments.
	 * @return array<string, mixed>
	 */
	public function get_query_args( array $overrides = array() ): array {
		$post_types           = $this->post_types();
		$requested_post_types = $post_types;
		if ( array_key_exists( 'post_type', $overrides ) ) {
			$requested            = is_array( $overrides['post_type'] )
				? $overrides['post_type']
				: array( $overrides['post_type'] );
			$requested_post_types = array_values(
				array_intersect(
					\Cybermaps\Core\PublicationPostTypes::filter_names( $requested ),
					$post_types
				)
			);
		}
		$args                = array_merge(
			array(
				'post_type'           => $post_types,
				'post_status'         => 'publish',
				'has_password'        => false,
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
			),
			$overrides
		);
		$args['post_type']   = $requested_post_types;
		$args['post_status'] = 'publish';

		$stored_exclusions = $this->settings['llms_exclude_ids'] ?? '';
		$excluded_ids      = $this->comma_separated_ids(
			is_scalar( $stored_exclusions ) ? (string) $stored_exclusions : ''
		);
		if ( ! empty( $excluded_ids ) ) {
			// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
			$args['post__not_in'] = $excluded_ids;
		}

		$tax_query = $this->taxonomy_filter();
		if ( ! empty( $tax_query ) ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			$args['tax_query'] = $tax_query;
		}

		if ( function_exists( 'pll_current_language' ) ) {
			$args['lang'] = '' !== $this->language
				? $this->language
				: sanitize_key( (string) pll_current_language() );
		} elseif ( defined( 'ICL_SITEPRESS_VERSION' ) || defined( 'ICL_LANGUAGE_CODE' ) || class_exists( 'SitePress' ) ) {
			// WPML filters get_posts() only when filters are not suppressed. The
			// localized handlers switch WPML's global language before this query.
			$args['suppress_filters'] = false;
		}

		return $args;
	}

	/**
	 * Release post/meta/term objects accumulated by a completed iterator slice.
	 *
	 * WordPress 7 provides a runtime-only flush. Object-cache drop-ins without
	 * that capability are left untouched rather than flushing persistent data.
	 */
	private function flush_runtime_cache(): void {
		if (
			function_exists( 'wp_cache_flush_runtime' )
			&& (
				! function_exists( 'wp_cache_supports' )
				|| wp_cache_supports( 'flush_runtime' )
			)
		) {
			wp_cache_flush_runtime();
		}
	}

	/**
	 * Prime term caches only when exclusions or taxonomy filters need them.
	 */
	private function batch_requires_term_cache(): bool {
		if ( '' !== trim( (string) ( $this->settings['ai_sitemap_exclude_terms'] ?? '' ) ) ) {
			return true;
		}

		return ! empty( $this->taxonomy_filter() );
	}

	/**
	 * Keep a static reconciliation's owner token alive between bounded batches.
	 */
	private function heartbeat_static_operation(): void {
		if ( ! StaticBridge::get_instance()->heartbeat() ) {
			throw new \RuntimeException(
				__( 'Static publication stopped because this request no longer owned its operation lock.', 'cybermaps' ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Static-sync exceptions become status data; the admin view escapes at its output boundary.
			);
		}
	}

	/**
	 * Whether the site explicitly publishes at least one LLMS content type.
	 */
	public function has_included_post_types(): bool {
		return ! empty( $this->post_types() );
	}

	/**
	 * @return string[]
	 */
	private function post_types(): array {
		$types = isset( $this->settings['llms_included_types'] )
			&& is_array( $this->settings['llms_included_types'] )
			? $this->settings['llms_included_types']
			: array( 'post', 'page' );
		$types = array_values(
			array_intersect(
				\Cybermaps\Core\PublicationPostTypes::filter_names( $types ),
				\Cybermaps\Core\PublicationPostTypes::names()
			)
		);
		$types = array_values(
			array_filter(
				$types,
				static fn( string $post_type ): bool => \Cybermaps\Sitemap\PriorityEngine::calculate(
					\Cybermaps\Sitemap\ProviderIdentity::post_type( $post_type )
				) > 0
			)
		);

		return $types;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function taxonomy_filter(): array {
		$stored_taxonomies = $this->settings['llms_filter_taxonomies'] ?? '';
		$taxonomy_text     = is_scalar( $stored_taxonomies )
			? (string) $stored_taxonomies
			: '';
		$taxonomies        = array_values(
			array_filter(
				array_map(
					'sanitize_key',
					array_map( 'trim', explode( ',', $taxonomy_text ) )
				),
				static fn( string $taxonomy ): bool => '' !== $taxonomy
					&& function_exists( 'taxonomy_exists' )
					&& taxonomy_exists( $taxonomy )
			)
		);
		if ( empty( $taxonomies ) ) {
			return array();
		}

		$query = array( 'relation' => 'OR' );
		foreach ( $taxonomies as $taxonomy ) {
			$query[] = array(
				'taxonomy' => $taxonomy,
				'operator' => 'EXISTS',
			);
		}

		return count( $query ) > 1 ? $query : array();
	}

	/**
	 * @return int[]
	 */
	private function comma_separated_ids( string $value ): array {
		return array_values( array_filter( array_map( 'absint', array_map( 'trim', explode( ',', $value ) ) ) ) );
	}
}
