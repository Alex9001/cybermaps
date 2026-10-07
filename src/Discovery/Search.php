<?php
/**
 * AI Discovery Search Handler
 *
 * @package Cybermaps\Discovery
 */

declare(strict_types=1);

namespace Cybermaps\Discovery;

use Cybermaps\Core\AtomicMinuteCounter;
use Cybermaps\Core\ClientIPResolver;
use Cybermaps\Core\CacheManager;
use Cybermaps\SEO\PublicationEligibility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Search {
	private const QUERY_BATCH_SIZE = 250;
	private const MAX_CANDIDATES   = 5000;

	/**
	 * Handle an AI discovery search request.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response
	 */
	public function handle_search( $request ) {
		$generation = CacheManager::get_generation( 'discovery', true );
		if ( $generation < 0 ) {
			return self::unavailable_response();
		}
		try {
			$settings = \Cybermaps\Core\ConfigurationStore::publication_settings();
			\Cybermaps\Core\ConfigurationStore::publication_discovery();
		} catch ( \Cybermaps\Core\BuildUnavailableException $error ) {
			return self::unavailable_response();
		}
		if ( empty( $settings['enable_discovery_hub'] ) ) {
			return new \WP_Error(
				'discovery_hub_disabled',
				__( 'AI Publication Hub is disabled.', 'cybermaps' ),
				array( 'status' => 404 )
			);
		}

		$query_str = self::normalize_query( $request->get_param( 'q' ) );
		if ( '' === $query_str ) {
			return rest_ensure_response(
				array(
					'error'   => __( 'Missing search query.', 'cybermaps' ),
					'results' => array(),
				)
			);
		}

		if ( $this->rate_limit_exceeded() ) {
			return new \WP_Error(
				'rate_limit_exceeded',
				__( 'Too many search requests. Please slow down.', 'cybermaps' ),
				array( 'status' => 429 )
			);
		}

		$inventory = new PublicationInventory( $settings );
		$limit     = self::normalize_limit( $request->get_param( 'limit' ) );
		if ( ! $inventory->has_included_post_types() ) {
			return rest_ensure_response(
				array(
					'query'   => $query_str,
					'total'   => 0,
					'results' => array(),
				)
			);
		}

		try {
			$results = $this->find_results( $query_str, $inventory, $settings, $limit );
		} catch ( \Cybermaps\Core\BuildUnavailableException $error ) {
			return self::unavailable_response();
		}
		if ( CacheManager::get_generation( 'discovery', true ) !== $generation ) {
			return self::unavailable_response();
		}
		return rest_ensure_response(
			array(
				'query'   => $query_str,
				'total'   => count( $results ),
				'results' => $results,
			)
		);
	}

	/** Preserve the REST and read-only ability error contract without stale hits. */
	private static function unavailable_response(): \WP_Error {
		return new \WP_Error(
			'publication_unavailable',
			__( 'Cybermaps content changed during search. Please retry shortly.', 'cybermaps' ),
			array( 'status' => 503 )
		);
	}

	public static function rate_limit_exceeded(): bool {
		$ip         = ClientIPResolver::get_ip();
		$material   = '' !== $ip ? $ip : AtomicMinuteCounter::UNRESOLVED_CLIENT_BUCKET;
		$rate_key   = AtomicMinuteCounter::requester_bucket( 'search', 'rest', $material );
		$rate_limit = current_user_can( 'edit_posts' ) ? 60 : 30;
		return AtomicMinuteCounter::increment( $rate_key ) > $rate_limit;
	}

	/**
	 * @param array<string,mixed> $settings Current settings.
	 * @return array<int,array<string,string>>
	 */
	private function find_results(
		string $query_str,
		PublicationInventory $inventory,
		array $settings,
		int $limit
	): array {
		$results     = array();
		$seen        = array();
		$inspected   = 0;
		$page        = 1;
		$eligibility = new PublicationEligibility( null, $settings );

		do {
			$batch_size = min( self::QUERY_BATCH_SIZE, self::MAX_CANDIDATES - $inspected );
			$args       = $inventory->get_query_args(
				array(
					's'                      => $query_str,
					'posts_per_page'         => $batch_size,
					'paged'                  => $page,
					'orderby'                => 'relevance',
					'update_post_meta_cache' => true,
					'update_post_term_cache' => true,
				)
			);

			$posts      = \Cybermaps\Sitemap\PublicationQuery::posts( $args );
			$inspected += count( $posts );
			if ( empty( $posts ) ) {
				break;
			}

			$saw_new_candidate = $this->collect_results( $posts, $results, $seen, $eligibility, $limit );
			wp_reset_postdata();

			if (
				count( $results ) >= $limit
				|| $inspected >= self::MAX_CANDIDATES
				|| count( $posts ) < $batch_size
				|| ! $saw_new_candidate
			) {
				break;
			}

			++$page;
		} while ( true );

		return $results;
	}

	/**
	 * @param array<int,array<string,string>> $results Collected results.
	 * @param array<int,bool>                 $seen Seen IDs.
	 */
	private function collect_results(
		array $posts,
		array &$results,
		array &$seen,
		PublicationEligibility $eligibility,
		int $limit
	): bool {
		$saw_new = false;
		foreach ( $posts as $candidate ) {
			$post_id = is_object( $candidate ) ? (int) ( $candidate->ID ?? 0 ) : 0;
			$post    = get_post( $post_id );
			if ( $post_id < 1 || isset( $seen[ $post_id ] ) ) {
				continue;
			}
			$seen[ $post_id ] = true;
			$saw_new          = true;
			if ( ! is_object( $post ) || ! $eligibility->post( $post, PublicationEligibility::AI )->indexable ) {
				continue;
			}
			$results[] = $this->build_result( $post );
			if ( count( $results ) >= $limit ) {
				break;
			}
		}
		return $saw_new;
	}

	/**
	 * @return array<string,string>
	 */
	private function build_result( object $post ): array {
		$post_id = (int) $post->ID;
		$snippet = self::result_snippet( $post );
		return array(
			'title'   => wp_strip_all_tags(
				PublicationConstraints::bounded_text(
					(string) get_the_title( $post_id ),
					PublicationConstraints::SEARCH_TITLE_MAX_LENGTH
				)
			),
			'url'     => \Cybermaps\Core\URLManager::rewrite_url( (string) get_permalink( $post_id ) ),
			'snippet' => PublicationConstraints::bounded_text(
				wp_strip_all_tags( $snippet ),
				PublicationConstraints::AI_SNIPPET_MAX_LENGTH
			),
			'intent'  => IntentEngine::calculate( $post_id, 'post', (string) ( $post->post_type ?? '' ) ),
		);
	}

	/** Keep a search hit available when its optional metadata cannot be built. */
	private static function result_snippet( object $post ): string {
		try {
			$ai_meta = AIMetadata::calculate( (int) $post->ID );
		} catch ( PublicationSizeLimitException $error ) {
			// Do not retry through the excerpt pipeline after rejecting the source.
			return '';
		}
		$raw_snippet = $ai_meta['snippet'] ?? '';
		return is_scalar( $raw_snippet ) && '' !== trim( (string) $raw_snippet )
			? (string) $raw_snippet
			: ( new \Cybermaps\Content\VisibleTextExtractor() )->summary( $post, 40 );
	}

	/**
	 * Normalize the public REST result limit.
	 *
	 * @param mixed $value Requested limit.
	 */
	public static function normalize_limit( $value ): int {
		if ( null === $value || '' === $value ) {
			return 20;
		}

		return max( 1, min( 100, (int) $value ) );
	}

	/**
	 * Normalize and defensively bound one public search query.
	 *
	 * REST schema validation rejects overlong requests. The truncation here
	 * keeps direct integrations from passing an unbounded value into WP_Query.
	 *
	 * @param mixed $value Requested query.
	 */
	public static function normalize_query( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$query = trim( sanitize_text_field( (string) $value ) );
		if (
			self::query_length( $query ) <= PublicationConstraints::SEARCH_QUERY_MAX_LENGTH
			&& strlen( $query ) <= PublicationConstraints::SEARCH_QUERY_MAX_LENGTH
		) {
			return $query;
		}

		return PublicationConstraints::bounded_text(
			$query,
			PublicationConstraints::SEARCH_QUERY_MAX_LENGTH
		);
	}

	/**
	 * Validate the public REST input before its database search executes.
	 *
	 * @param mixed $value Requested query.
	 */
	public static function is_valid_query( $value ): bool {
		if ( ! is_scalar( $value ) ) {
			return false;
		}

		$query = trim( sanitize_text_field( (string) $value ) );
		return '' !== $query
			&& self::query_length( $query ) <= PublicationConstraints::SEARCH_QUERY_MAX_LENGTH
			&& strlen( $query ) <= PublicationConstraints::SEARCH_QUERY_MAX_LENGTH;
	}

	private static function query_length( string $query ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $query ) : strlen( $query );
	}
}
