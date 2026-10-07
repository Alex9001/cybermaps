<?php
declare(strict_types=1);
namespace Cybermaps\Sitemap;

use Cybermaps\SEO\PublicationEligibility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * RSS 2.0 Sitemap Provider.
 *
 * Generates an RSS-formatted sitemap listing recent published posts
 * with title, link, description, and publication date. RSS sitemaps
 * are accepted by Google, Bing, and other search engines as an
 * alternative to XML sitemaps.
 */
class RSSProvider {
	private const CACHE_KEY        = 'cybermaps_rss_sitemap_v2';
	private const QUERY_BATCH_SIZE = 250;
	private const MAX_SCAN_POSTS   = 5000;

	/**
	 * Handle requests for /sitemap-rss.xml or custom base.
	 */
	public function handle(): void {
		$path     = \Cybermaps\Core\URLManager::get_request_path();
		$settings = \Cybermaps\Core\ConfigurationStore::settings();

		if ( empty( $settings['enable_rss_sitemap'] ) ) {
			return;
		}

		$base     = Orchestrator::get_rss_sitemap_base();
		$expected = '/' . $base . '.xml';

		if ( $path !== $expected ) {
			return;
		}

		\Cybermaps\Core\ReadOnlyRequest::enforce();
		\Cybermaps\Discovery\StaticBridge::get_instance()->request_repair_for_filename(
			\ltrim( $path, '/' ),
			'all'
		);
		try {
			$output = $this->generate();
		} catch ( \Cybermaps\Core\BuildUnavailableException $error ) {
			\Cybermaps\Discovery\PublicationRequestGuard::serve_unavailable( $error );
		}

		\Cybermaps\Discovery\Integrity::send_headers( $output, 3600 );
		header( 'Content-Type: application/rss+xml; charset=utf-8' );
		if ( ! \Cybermaps\Core\ReadOnlyRequest::is_head() ) {
			\Cybermaps\Core\ProtocolOutput::emit( $output, 'xml' );
		}
		exit;
	}

	/**
	 * Generate the RSS 2.0 sitemap content.
	 */
	public function generate(): string {
		$generation = \Cybermaps\Core\CacheManager::get_generation( 'sitemap', true );
		$settings   = \Cybermaps\Core\ConfigurationStore::publication_settings();
		\Cybermaps\Core\ConfigurationStore::publication_discovery();
		$cache_enabled = ! empty( $settings['enable_caching'] );
		$cached        = $this->get_cached_sitemap( $cache_enabled );
		if ( null !== $cached ) {
			$this->assert_current_generation( $generation );
			return $cached;
		}

		$post_types = $this->get_post_types( $settings );
		$limit      = max( 1, min( 1000, isset( $settings['rss_sitemap_limit'] ) ? (int) $settings['rss_sitemap_limit'] : 100 ) );
		$posts      = $this->get_posts( $post_types, $limit, $settings );
		$xml        = $this->render_rss( $posts );
		$this->assert_current_generation( $generation );

		if ( $cache_enabled ) {
			\Cybermaps\Core\CacheManager::set_if_current(
				self::CACHE_KEY,
				$xml,
				12 * HOUR_IN_SECONDS,
				'sitemap',
				$generation
			);
		}

		$this->assert_current_generation( $generation );
		return $xml;
	}

	/** Never return bytes selected before a publication/privacy invalidation. */
	private function assert_current_generation( int $generation ): void {
		if ( $generation < 0 || \Cybermaps\Core\CacheManager::get_generation( 'sitemap', true ) !== $generation ) {
			throw new \Cybermaps\Core\BuildUnavailableException( esc_html__( 'Cybermaps stopped RSS publication because its content changed during selection. Please retry shortly.', 'cybermaps' ) );
		}
	}

	/**
	 * Return the current cached document when caching is enabled.
	 */
	private function get_cached_sitemap( bool $cache_enabled ): ?string {
		if ( ! $cache_enabled ) {
			return null;
		}

		$cached = \Cybermaps\Core\CacheManager::get(
			self::CACHE_KEY,
			'sitemap',
			$cache_found
		);
		return $cache_found && is_string( $cached ) && '' !== $cached ? $cached : null;
	}

	/**
	 * Resolve enabled, publishable post types.
	 *
	 * @param array<string, mixed> $settings Plugin settings.
	 * @return string[]
	 */
	private function get_post_types( array $settings ): array {
		$public_types = array_fill_keys(
			\Cybermaps\Core\PublicationPostTypes::names(),
			true
		);
		$configured   = isset( $settings['rss_sitemap_types'] ) ? (array) $settings['rss_sitemap_types'] : array( 'post' );
		$post_types   = array_values(
			array_unique(
				array_filter(
					array_map( 'sanitize_key', $configured ),
					static fn( string $post_type ): bool => isset( $public_types[ $post_type ] )
						&& PriorityEngine::calculate( ProviderIdentity::post_type( $post_type ) ) > 0
				)
			)
		);

		return $post_types;
	}

	/**
	 * Collect the newest eligible posts within the bounded scan window.
	 *
	 * @param string[]            $post_types Post types.
	 * @param int                 $limit Maximum result count.
	 * @param array<string,mixed> $settings Plugin settings.
	 * @return object[]
	 */
	private function get_posts( array $post_types, int $limit, array $settings ): array {
		if ( empty( $post_types ) ) {
			return array();
		}

		$eligibility = new PublicationEligibility( null, $settings );
		$posts       = array();
		$post_count  = 0;
		$page        = 1;
		$inspected   = 0;

		while ( $post_count < $limit && $inspected < self::MAX_SCAN_POSTS ) {
			$remaining_scan = self::MAX_SCAN_POSTS - $inspected;
			$batch_size     = min( self::QUERY_BATCH_SIZE, $remaining_scan );
			$rows           = PublicationQuery::posts(
				array(
					'post_type'              => $post_types,
					'posts_per_page'         => $batch_size,
					'paged'                  => $page,
					'post_status'            => 'publish',
					'orderby'                => array(
						'modified' => 'DESC',
						'ID'       => 'DESC',
					),
					'no_found_rows'          => true,
					'has_password'           => false,
					'ignore_sticky_posts'    => true,
					'update_post_term_cache' => true,
					'update_post_meta_cache' => true,
				)
			);
			$batch          = array_values( array_filter( array_slice( $rows, 0, $batch_size ), 'is_object' ) );
			$batch_count    = count( $batch );
			$inspected     += $batch_count;

			foreach ( $batch as $post ) {
				if ( ! $eligibility->post( $post, PublicationEligibility::SITEMAP )->indexable ) {
					continue;
				}
				$posts[] = $post;
				++$post_count;
				if ( $post_count >= $limit ) {
					break;
				}
			}

			if ( $batch_count < $batch_size ) {
				break;
			}
			++$page;
		}

		if ( $post_count < $limit && $inspected >= self::MAX_SCAN_POSTS && $batch_count === $batch_size ) {
			throw new \Cybermaps\Core\BuildUnavailableException( esc_html__( 'Cybermaps could not finish RSS eligibility selection within its request budget. No partial feed was produced.', 'cybermaps' ) );
		}

		return $posts;
	}

	/**
	 * Render the RSS document.
	 *
	 * @param object[] $posts Eligible posts.
	 */
	private function render_rss( array $posts ): string {

		$site_name  = get_bloginfo( 'name' );
		$site_url   = \Cybermaps\Core\URLManager::get_home_url( '/' );
		$rss_url    = \Cybermaps\Core\URLManager::get_home_url( '/' . Orchestrator::get_rss_sitemap_base() . '.xml' );
		$build_date = ! empty( $posts )
			? $this->utc_date( (string) ( $posts[0]->post_modified_gmt ?? '' ) )
			: '';

		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n";
		$xml .= '<channel>' . "\n";
		$xml .= "\t" . '<title>' . esc_xml( $site_name ) . '</title>' . "\n";
		$xml .= "\t" . '<link>' . esc_url( $site_url ) . '</link>' . "\n";
		$xml .= "\t" . '<description>' . esc_xml( sprintf( /* translators: %s: site name */ __( 'Latest content from %s', 'cybermaps' ), $site_name ) ) . '</description>' . "\n";
		if ( '' !== $build_date ) {
			$xml .= "\t" . '<lastBuildDate>' . esc_xml( $build_date ) . '</lastBuildDate>' . "\n";
		}
		$xml .= "\t" . '<atom:link href="' . esc_url( $rss_url ) . '" rel="self" type="application/rss+xml"/>' . "\n";

		if ( ! empty( $posts ) ) {
			foreach ( $posts as $post ) {
				$xml .= $this->render_item( $post );
			}
		}

		$xml .= '</channel>' . "\n";
		$xml .= '</rss>';

		return $xml;
	}

	/** Format a stored GMT value without applying the site's local timezone. */
	private function utc_date( string $date ): string {
		$timestamp = '' === trim( $date ) || '0000-00-00 00:00:00' === $date ? false : strtotime( $date . ' UTC' );
		return false === $timestamp ? '' : gmdate( 'r', $timestamp );
	}

	/**
	 * Render one RSS item.
	 */
	private function render_item( object $post ): string {
		$post_id    = (int) $post->ID;
		$post_url   = \Cybermaps\Core\URLManager::rewrite_url( (string) get_permalink( $post_id ) );
		$post_title = get_the_title( $post_id );
		$modified   = isset( $post->post_modified_gmt ) && is_scalar( $post->post_modified_gmt )
			? (string) $post->post_modified_gmt
			: '';
		$post_date  = '' !== $modified && '0000-00-00 00:00:00' !== $modified
			? $this->utc_date( $modified )
			: get_post_time( 'r', true, $post_id );
		$excerpt    = has_excerpt( $post_id )
			? get_the_excerpt( $post_id )
			: wp_trim_words( wp_strip_all_tags( get_the_content( '', false, $post_id ) ), 50, '...' );

		$xml  = "\t" . '<item>' . "\n";
		$xml .= "\t\t" . '<title>' . esc_xml( $post_title ) . '</title>' . "\n";
		$xml .= "\t\t" . '<link>' . esc_url( $post_url ) . '</link>' . "\n";
		$xml .= "\t\t" . '<pubDate>' . esc_xml( $post_date ) . '</pubDate>' . "\n";
		$xml .= "\t\t" . '<guid isPermaLink="true">' . esc_url( $post_url ) . '</guid>' . "\n";
		$xml .= "\t\t" . '<description>' . esc_xml( $excerpt ) . '</description>' . "\n";
		$xml .= "\t" . '</item>' . "\n";
		return $xml;
	}
}
