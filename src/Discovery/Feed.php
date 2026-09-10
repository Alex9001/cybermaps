<?php
declare(strict_types=1);
namespace Cybermaps\Discovery;

use Cybermaps\SEO\PublicationEligibility;
use Cybermaps\Sitemap\PriorityEngine;
use Cybermaps\Sitemap\ProviderIdentity;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI Feed Handler
 *
 * Handles requests for /feed.json (JSON Feed v1.1).
 */
class Feed {
	/**
	 * Bound candidate scanning when early posts are excluded from AI output.
	 */
	private const QUERY_BATCH_SIZE = 250;
	private const MAX_CANDIDATES   = 5000;

	/**
	 * Handle requests for /feed.json.
	 *
	 * @return void
	 */
	public function handle() {
		$path = \Cybermaps\Core\URLManager::get_request_path();
		if ( '/feed.json' !== $path ) {
			return;
		}

		$settings = \Cybermaps\Core\ConfigurationStore::settings();
		if ( empty( $settings['enable_discovery_hub'] ) ) {
			return;
		}

		$output = $this->get_json_content();

		Integrity::send_headers( $output, 15 * MINUTE_IN_SECONDS );
		header( 'Content-Type: application/feed+json; charset=utf-8' );

		if ( ! empty( $settings['enable_websub'] ) ) {
			( new WebSub() )->send_discovery_headers(
				\Cybermaps\Core\EndpointRegistry::get_instance()->get_url( 'feed' )
			);
		}

		if ( ! \Cybermaps\Core\ReadOnlyRequest::is_head() ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $output;
		}
		exit;
	}

	/**
	 * Build JSON Feed document (for HTTP and static file sync).
	 *
	 * @return string
	 */
	public function get_json_content(): string {
		$settings = \Cybermaps\Core\ConfigurationStore::settings();

		$full_content    = ! empty( $settings['ai_feed_full_content'] );
		$include_authors = ! empty( $settings['ai_feed_include_authors'] );
		$limit           = PublicationConstraints::feed_limit(
			$settings['ai_feed_limit'] ?? PublicationConstraints::FEED_LIMIT_DEFAULT
		);
		$items           = $this->collect_items( $settings, $full_content, $include_authors, $limit );
		$feed            = array(
			'version'       => 'https://jsonfeed.org/version/1.1',
			'title'         => get_bloginfo( 'name' ),
			'home_page_url' => \Cybermaps\Core\URLManager::get_home_url( '/' ),
			'feed_url'      => \Cybermaps\Core\EndpointRegistry::get_instance()->get_url( 'feed' ),
			'items'         => $items,
		);
		$this->append_hubs( $feed, $settings );
		$feed = apply_filters( 'cybermaps_ai_feed_data', $feed );
		return (string) wp_json_encode( $feed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * @param array<string,mixed> $settings Current settings.
	 * @return array<int,array<string,mixed>>
	 */
	private function collect_items( array $settings, bool $full_content, bool $include_authors, int $limit ): array {
		$items       = array();
		$item_count  = 0;
		$page        = 1;
		$scanned     = 0;
		$eligibility = new PublicationEligibility( null, $settings );

		$posts_published = PriorityEngine::calculate( ProviderIdentity::post_type( 'post' ) ) > 0;
		while ( $posts_published && $item_count < $limit && $scanned < self::MAX_CANDIDATES ) {
			$batch_size = min( self::QUERY_BATCH_SIZE, self::MAX_CANDIDATES - $scanned );
			$query      = new \WP_Query(
				array(
					'post_type'              => 'post',
					'posts_per_page'         => $batch_size,
					'paged'                  => $page,
					'post_status'            => 'publish',
					'orderby'                => array(
						'date' => 'DESC',
						'ID'   => 'DESC',
					),
					'no_found_rows'          => true,
					'update_post_meta_cache' => true,
					'update_post_term_cache' => true,
				)
			);
			$returned   = count( (array) $query->posts );
			$scanned   += $returned;

			while ( $query->have_posts() ) {
				$query->the_post();
				$post = get_post( get_the_ID() );
				if ( ! is_object( $post ) || ! $eligibility->post( $post, PublicationEligibility::AI )->indexable ) {
					continue;
				}

				$items[] = $this->build_item( $post, $full_content, $include_authors );
				++$item_count;
				if ( $item_count >= $limit ) {
					break;
				}
			}
			wp_reset_postdata();

			if ( $returned < $batch_size ) {
				break;
			}
			++$page;
		}
		return $items;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function build_item( object $post, bool $full_content, bool $include_authors ): array {
		$post_id = (int) $post->ID;
		$item    = array(
			'id'             => (string) $post_id,
			'url'            => \Cybermaps\Core\URLManager::rewrite_url( get_permalink( $post_id ) ),
			'title'          => get_the_title( $post_id ),
			'date_published' => get_the_date( 'c', $post_id ),
			'date_modified'  => get_the_modified_date( 'c', $post_id ),
		);
		if ( $full_content ) {
			$item['content_html'] = get_the_content( null, false, $post );
		} else {
			$item['summary'] = get_the_excerpt( $post );
		}
		if ( $include_authors ) {
			$item['authors'] = array(
				array(
					'name' => get_the_author_meta( 'display_name', (int) ( $post->post_author ?? 0 ) ),
				),
			);
		}
		return $item;
	}

	/**
	 * @param array<string,mixed> $feed Feed document.
	 * @param array<string,mixed> $settings Current settings.
	 */
	private function append_hubs( array &$feed, array $settings ): void {
		if ( ! empty( $settings['enable_websub'] ) ) {
			$websub = new WebSub();
			$hubs   = $websub->get_hubs();
			if ( ! empty( $hubs ) ) {
				$feed['hubs'] = array();
				foreach ( $hubs as $hub_url ) {
					$feed['hubs'][] = array(
						'type' => 'WebSub',
						'url'  => $hub_url,
					);
				}
			}
		}
	}
}
