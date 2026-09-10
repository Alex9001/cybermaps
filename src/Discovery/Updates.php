<?php
declare(strict_types=1);

namespace Cybermaps\Discovery;

use Cybermaps\SEO\PublicationEligibility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Publishes the bounded ADP 3.0 recent-update stream.
 */
final class Updates {
	private const CACHE_KEY        = 'cybermaps_adp_updates_v3';
	private const QUERY_BATCH_SIZE = 250;
	private const MAX_CANDIDATES   = 5000;
	private const WINDOW_SECONDS   = 7 * DAY_IN_SECONDS;

	/**
	 * Serve /updates.json.
	 */
	public function handle(): void {
		if ( '/updates.json' !== \Cybermaps\Core\URLManager::get_request_path() ) {
			return;
		}

		$output = $this->get_json_content();
		Integrity::send_headers( $output, 15 * MINUTE_IN_SECONDS );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'X-Update-Frequency: daily' );
		if ( ! \Cybermaps\Core\ReadOnlyRequest::is_head() ) {
			echo $output; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Deliberate JSON response.
		}
		exit;
	}

	/**
	 * Return the canonical JSON body used by dynamic and static delivery.
	 */
	public function get_json_content(): string {
		$output = \wp_json_encode( $this->get_updates_data(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( ! \is_string( $output ) ) {
			return '{}';
		}

		return $output;
	}

	/**
	 * Build or retrieve a stable seven-day change window.
	 *
	 * The feed reports current public items only. It does not infer deletion
	 * events from the absence of content or retain a historical event ledger.
	 *
	 * @return array<string,mixed>
	 */
	public function get_updates_data(): array {
		$cached = \get_transient( self::CACHE_KEY );
		if ( \is_array( $cached ) ) {
			return $cached;
		}
		if ( false !== $cached ) {
			\delete_transient( self::CACHE_KEY );
		}

		$settings = \Cybermaps\Core\ConfigurationStore::settings();
		$limit    = PublicationConstraints::feed_limit(
			$settings['ai_feed_limit'] ?? PublicationConstraints::FEED_LIMIT_DEFAULT
		);
		$data     = array(
			'version'      => '3.0',
			'generatedAt'  => \gmdate( 'c' ),
			'updateWindow' => '7d',
			'updates'      => $this->collect_updates( $settings, $limit ),
		);
		\Cybermaps\Core\CacheManager::set( self::CACHE_KEY, $data, 15 * MINUTE_IN_SECONDS, 'discovery' );
		return $data;
	}

	/**
	 * @param array<string,mixed> $settings Current settings.
	 * @return array<int,array<string,mixed>>
	 */
	private function collect_updates( array $settings, int $limit ): array {
		$post_types   = \Cybermaps\Core\PublicationPostTypes::names();
		$eligibility  = new PublicationEligibility( null, $settings );
		$updates      = array();
		$page         = 1;
		$scanned      = 0;
		$window       = \time() - self::WINDOW_SECONDS;
		$update_count = 0;

		while ( ! empty( $post_types ) && $update_count < $limit && $scanned < self::MAX_CANDIDATES ) {
			$batch_size = \min( self::QUERY_BATCH_SIZE, self::MAX_CANDIDATES - $scanned );
			$query      = new \WP_Query(
				array(
					'post_type'              => $post_types,
					'posts_per_page'         => $batch_size,
					'paged'                  => $page,
					'post_status'            => 'publish',
					'orderby'                => array(
						'modified' => 'DESC',
						'ID'       => 'DESC',
					),
					'date_query'             => array(
						array(
							'column'    => 'post_modified_gmt',
							'after'     => \gmdate( 'Y-m-d H:i:s', $window ),
							'inclusive' => true,
						),
					),
					'has_password'           => false,
					'ignore_sticky_posts'    => true,
					'no_found_rows'          => true,
					'update_post_meta_cache' => true,
					'update_post_term_cache' => true,
				)
			);
			$posts      = \array_values( \array_filter( (array) $query->posts, 'is_object' ) );
			$returned   = \count( $posts );
			$scanned   += $returned;

			foreach ( $posts as $post ) {
				if ( ! $eligibility->post( $post, PublicationEligibility::AI )->indexable ) {
					continue;
				}

				$post_id      = isset( $post->ID ) ? (int) $post->ID : 0;
				$published_ts = self::gmt_timestamp( $post->post_date_gmt ?? '' );
				$modified_ts  = self::gmt_timestamp( $post->post_modified_gmt ?? '' );
				if ( $post_id < 1 || $modified_ts < $window ) {
					continue;
				}
				$updates[] = $this->update_entry( $post, $post_id, $published_ts, $modified_ts );
				++$update_count;
				if ( $update_count >= $limit ) {
					break;
				}
			}

			if ( $returned < $batch_size ) {
				break;
			}
			++$page;
		}
		\wp_reset_postdata();
		return $updates;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function update_entry( object $post, int $post_id, int $published_ts, int $modified_ts ): array {
		$is_created = $published_ts > 0 && $published_ts === $modified_ts;
		return array(
			'url'         => \Cybermaps\Core\URLManager::rewrite_url( (string) \get_permalink( $post_id ) ),
			'title'       => (string) \get_the_title( $post_id ),
			'type'        => $is_created ? 'created' : 'modified',
			'timestamp'   => \gmdate( 'c', $is_created ? $published_ts : $modified_ts ),
			'contentType' => \sanitize_key( (string) ( $post->post_type ?? 'post' ) ),
		);
	}

	/**
	 * Convert a WordPress GMT database date to a Unix timestamp.
	 */
	private static function gmt_timestamp( mixed $value ): int {
		if ( ! \is_scalar( $value ) ) {
			return 0;
		}

		$value = \trim( (string) $value );
		if ( '' === $value || '0000-00-00 00:00:00' === $value ) {
			return 0;
		}
		$timestamp = \strtotime( $value . ' UTC' );
		return false === $timestamp ? 0 : $timestamp;
	}
}
