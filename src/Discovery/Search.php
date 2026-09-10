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
		if ( ! Integrity::is_hub_enabled() ) {
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

		$settings  = \Cybermaps\Core\ConfigurationStore::settings();
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

		$results = $this->find_results( $query_str, $inventory, $settings, $limit );
		return rest_ensure_response(
			array(
				'query'   => $query_str,
				'total'   => count( $results ),
				'results' => $results,
			)
		);
	}

	private function rate_limit_exceeded(): bool {
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

			$query      = new \WP_Query( $args );
			$posts      = is_array( $query->posts ) ? $query->posts : array();
			$inspected += count( $posts );
			if ( empty( $posts ) ) {
				break;
			}

			$saw_new_candidate = $this->collect_results( $query, $results, $seen, $eligibility, $limit );
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
		\WP_Query $query,
		array &$results,
		array &$seen,
		PublicationEligibility $eligibility,
		int $limit
	): bool {
		$saw_new = false;
		while ( $query->have_posts() ) {
			$query->the_post();
			$post_id = get_the_ID();
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
		$post_id     = (int) $post->ID;
		$ai_meta     = AIMetadata::calculate( $post_id );
		$raw_snippet = $ai_meta['snippet'] ?? '';
		$snippet     = is_scalar( $raw_snippet ) && '' !== trim( (string) $raw_snippet )
			? (string) $raw_snippet
			: wp_strip_all_tags( (string) get_the_excerpt( $post ) );
		return array(
			'title'   => PublicationConstraints::bounded_text(
				wp_strip_all_tags( (string) get_the_title( $post_id ) ),
				PublicationConstraints::SEARCH_TITLE_MAX_LENGTH
			),
			'url'     => \Cybermaps\Core\URLManager::rewrite_url( (string) get_permalink( $post_id ) ),
			'snippet' => PublicationConstraints::bounded_text(
				wp_strip_all_tags( $snippet ),
				PublicationConstraints::AI_SNIPPET_MAX_LENGTH
			),
			'intent'  => IntentEngine::calculate( $post_id, 'post', (string) ( $post->post_type ?? '' ) ),
		);
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
