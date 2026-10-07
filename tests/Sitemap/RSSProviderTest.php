<?php
declare(strict_types=1);

namespace Cybermaps\Sitemap {
	/** Match WordPress mysql2date: the stored string is parsed in the site timezone. */
	function mysql2date( string $format, string $date, bool $translate = true ): string {
		return ( new \DateTimeImmutable( $date, new \DateTimeZone( 'America/Los_Angeles' ) ) )->format( $format );
	}
	/** Test-only rendering callback; normal readers use the shared WP stub. */
	function get_bloginfo( string $show ): string {
		$callback = $GLOBALS['cybermaps_rss_bloginfo_callback'] ?? null;
		if ( is_callable( $callback ) ) {
			$callback( $show );
		}
		return \get_bloginfo( $show );
	}

}

namespace Cybermaps\Tests\Sitemap {

use Cybermaps\Sitemap\RSSProvider;
use PHPUnit\Framework\TestCase;

final class RSSProviderTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		\cybermaps_mock_reset_cache_runtime();
		$GLOBALS['cybermaps_mock_options']           = array();
		$GLOBALS['cybermaps_mock_transients']        = array();
		$GLOBALS['cybermaps_mock_post_types']        = array( 'post', 'page' );
		$GLOBALS['cybermaps_mock_post_type_objects'] = array(
			'post' => (object) array(
				'name'   => 'post',
				'public' => true,
			),
			'page' => (object) array(
				'name'   => 'page',
				'public' => true,
			),
		);
		$GLOBALS['cybermaps_mock_posts']             = array();
		$GLOBALS['cybermaps_mock_permalinks']        = array();
		$GLOBALS['cybermaps_mock_home_url']          = 'https://example.com';
		$GLOBALS['cybermaps_mock_wp_query_args']     = array();
		$GLOBALS['cybermaps_mock_wp_query_callback'] = static fn( array $args ): array => array();
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['cybermaps_mock_wp_query_callback'],
			$GLOBALS['cybermaps_mock_post_type_objects'],
			$GLOBALS['cybermaps_mock_posts'],
			$GLOBALS['cybermaps_mock_permalinks'],
			$GLOBALS['cybermaps_mock_home_url'],
			$GLOBALS['cybermaps_rss_bloginfo_callback'],
			$GLOBALS['cybermaps_mock_get_transient_observer'],
			$GLOBALS['cybermaps_mock_set_transient_observer']
		);
		parent::tearDown();
	}

	public function test_generation_uses_bounded_queries_for_public_configured_types(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'rss_sitemap_types' => array( 'post', 'private_type', 'POST' ),
			'rss_sitemap_limit' => 1000,
		);

		$output = ( new RSSProvider() )->generate();

		$this->assertStringContainsString( '<rss version="2.0"', $output );
		$this->assertCount( 1, $GLOBALS['cybermaps_mock_wp_query_args'] );
		$args = $GLOBALS['cybermaps_mock_wp_query_args'][0];
		$this->assertSame( array( 'post' ), $args['post_type'] );
		$this->assertSame( 250, $args['posts_per_page'] );
		$this->assertSame( 1, $args['paged'] );
		$this->assertTrue( $args['no_found_rows'] );
		$this->assertNotSame( -1, $args['posts_per_page'] );
	}

	public function test_generation_does_not_query_non_public_or_unknown_types(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'rss_sitemap_types' => array( 'private_type', 'missing' ),
		);

		$output = ( new RSSProvider() )->generate();

		$this->assertStringContainsString( '<channel>', $output );
		$this->assertStringNotContainsString( '<lastBuildDate>', $output );
		$this->assertSame( array(), $GLOBALS['cybermaps_mock_wp_query_args'] );
	}

	public function test_disabled_cache_is_neither_read_nor_written(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'enable_caching'    => '0',
			'rss_sitemap_types' => array(),
		);
		set_transient( 'cybermaps_rss_sitemap', '<stale-cache/>', HOUR_IN_SECONDS );

		$output = ( new RSSProvider() )->generate();

		$this->assertStringContainsString( '<rss version="2.0"', $output );
		$this->assertNotSame( '<stale-cache/>', $output );
		$this->assertSame( '<stale-cache/>', get_transient( 'cybermaps_rss_sitemap' ) );
	}

	public function test_enabled_cache_is_read_before_querying(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'enable_caching'    => '1',
			'rss_sitemap_types' => array( 'post' ),
		);
		\Cybermaps\Core\CacheManager::set_if_current( 'cybermaps_rss_sitemap_v2', '<cached-rss/>', HOUR_IN_SECONDS, 'sitemap', \Cybermaps\Core\CacheManager::get_generation( 'sitemap', true ) );

		$output = ( new RSSProvider() )->generate();

		$this->assertSame( '<cached-rss/>', $output );
		$this->assertSame( array(), $GLOBALS['cybermaps_mock_wp_query_args'] );
	}

	public function test_enabled_cache_stores_generated_output(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'enable_caching'    => '1',
			'rss_sitemap_types' => array(),
		);

		$output = ( new RSSProvider() )->generate();

		$this->assertSame( $output, \Cybermaps\Core\CacheManager::get( 'cybermaps_rss_sitemap_v2', 'sitemap' ) );
		$this->assertFalse( get_transient( 'cybermaps_rss_sitemap' ) );
	}

	public function test_malformed_settings_object_fails_closed_without_runtime_warnings(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = (object) array(
			'enable_rss_sitemap' => '1',
			'rss_sitemap_types'  => array( 'post' ),
		);

		set_error_handler(
			static function ( int $severity, string $message, string $file, int $line ): never {
				throw new \ErrorException( $message, 0, $severity, $file, $line );
			}
		);
		try {
			$output = ( new RSSProvider() )->generate();
		} finally {
			restore_error_handler();
		}

		$this->assertStringContainsString( '<rss version="2.0"', $output );
		$this->assertCount( 1, $GLOBALS['cybermaps_mock_wp_query_args'] );
		$this->assertSame(
			array( 'post' ),
			$GLOBALS['cybermaps_mock_wp_query_args'][0]['post_type']
		);
		$this->assertFalse( get_transient( 'cybermaps_rss_sitemap' ) );
	}

	public function test_rss_item_links_use_the_configured_headless_frontend(): void {
		$post = (object) array(
			'ID'                => 7,
			'post_type'         => 'post',
			'post_status'       => 'publish',
			'post_password'     => '',
			'post_title'        => 'Launch',
			'post_excerpt'      => 'Launch details.',
			'post_content'      => 'Launch details.',
			'post_date_gmt'     => '2026-07-30 12:00:00',
			'post_modified_gmt' => '2026-07-30 12:00:00',
		);
		$GLOBALS['cybermaps_mock_home_url']                      = 'https://backend.example/blog';
		$GLOBALS['cybermaps_mock_posts'][7]                      = $post;
		$GLOBALS['cybermaps_mock_permalinks'][7]                 = 'https://backend.example/blog/launch?source=rss#details';
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'frontend_base_url' => 'https://frontend.example/app',
			'rss_sitemap_types' => array( 'post' ),
			'rss_sitemap_limit' => 10,
		);
		$GLOBALS['cybermaps_mock_wp_query_callback'] = static function ( array $args ) use ( $post ): array {
			return 1 === (int) ( $args['paged'] ?? 0 ) ? array( $post ) : array();
		};

		$output = ( new RSSProvider() )->generate();

		$this->assertStringContainsString(
			'<link>https://frontend.example/app/launch?source=rss#details</link>',
			$output
		);
		$this->assertStringContainsString(
			'<guid isPermaLink="true">https://frontend.example/app/launch?source=rss#details</guid>',
			$output
		);
	}

	public function test_rendering_preserves_the_ambient_post_context(): void {
		$post = (object) array(
			'ID'                => 71,
			'post_title'        => 'Queried post',
			'post_content'      => 'Queried excerpt',
			'post_modified_gmt' => '2026-08-01 12:00:00',
		);
		$GLOBALS['cybermaps_mock_posts'][71]       = $post;
		$GLOBALS['cybermaps_mock_permalinks'][71]  = 'https://example.com/queried';
		$GLOBALS['cybermaps_mock_current_post_id'] = 99;
		$method = new \ReflectionMethod( RSSProvider::class, 'render_rss' );
		try {
			$output = $method->invoke( new RSSProvider(), array( $post ) );
			$this->assertStringContainsString( '<title>Queried post</title>', $output );
			$this->assertStringContainsString( '<link>https://example.com/queried</link>', $output );
			$this->assertStringContainsString( '<description>Queried excerpt</description>', $output );
			$this->assertSame( 99, $GLOBALS['cybermaps_mock_current_post_id'] );
		} finally {
			unset( $GLOBALS['cybermaps_mock_current_post_id'] );
		}
	}
	public function test_rss_dates_preserve_stored_utc_instants_in_a_non_utc_process(): void {
		$post = (object) array( 'ID' => 71, 'post_modified_gmt' => '2026-08-01 12:00:00' );
		$GLOBALS['cybermaps_mock_posts'][71] = $post;
		$timezone = date_default_timezone_get();
		date_default_timezone_set( 'America/Los_Angeles' );
		try {
			$output = ( new \ReflectionMethod( RSSProvider::class, 'render_rss' ) )->invoke( new RSSProvider(), array( $post ) );
			$this->assertStringContainsString( '<lastBuildDate>Sat, 01 Aug 2026 12:00:00 +0000</lastBuildDate>', $output );
			$this->assertStringContainsString( '<pubDate>Sat, 01 Aug 2026 12:00:00 +0000</pubDate>', $output );
		} finally {
			date_default_timezone_set( $timezone );
		}
	}

	public function test_exhausted_selection_is_unavailable_and_never_cached(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array( 'enable_caching' => '1', 'rss_sitemap_types' => array( 'post' ) );
		$post = (object) array( 'ID' => 1, 'post_type' => 'post', 'post_status' => 'publish', 'post_password' => '' );
		$GLOBALS['cybermaps_mock_wp_query_callback'] = static function ( array $args ) use ( $post ): array {
			// A later eligible sentinel exists, but cannot be selected within this request's budget.
			return (int) $args['paged'] <= 20 ? array_fill( 0, 250, $post ) : array( (object) array( 'ID' => 5001 ) );
		};
		$GLOBALS['cybermaps_mock_filter_callbacks']['cybermaps_publication_eligibility'] = array(
			static fn( $decision ) => $decision->with_reasons( array( 'test_exclusion' ) ),
		);
		try {
			( new RSSProvider() )->generate();
			$this->fail( 'A complete-looking feed must not be returned.' );
		} catch ( \Cybermaps\Core\BuildUnavailableException ) {
			$this->assertCount( 20, $GLOBALS['cybermaps_mock_wp_query_args'] );
			$this->assertFalse( get_transient( 'cybermaps_rss_sitemap' ) );
		} finally {
			$GLOBALS['cybermaps_mock_filter_callbacks'] = array();
		}
	}

	public function test_generation_change_during_render_never_returns_private_content(): void {
		foreach ( array( '0', '1' ) as $cache_enabled ) {
			\cybermaps_mock_reset_cache_runtime();
			$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array( 'enable_caching' => $cache_enabled, 'rss_sitemap_types' => array( 'post' ) );
			$post = (object) array( 'ID' => 7, 'post_type' => 'post', 'post_status' => 'publish', 'post_password' => '', 'post_title' => 'Private now', 'post_content' => 'Private excerpt', 'post_excerpt' => '', 'post_modified_gmt' => '2026-10-01 00:00:00' );
			$GLOBALS['cybermaps_mock_posts'][7] = $post;
			$GLOBALS['cybermaps_mock_wp_query_callback'] = static fn( array $args ): array => array( $post );
			$GLOBALS['cybermaps_rss_bloginfo_callback'] = static function ( string $show ) use ( $post ): void {
				unset( $GLOBALS['cybermaps_rss_bloginfo_callback'] );
				$post->post_status = 'private';
				\Cybermaps\Core\CacheManager::clear_family( 'sitemap' );
			};
			$this->assertGenerationUnavailable();
			$this->assertSame( 'private', $post->post_status );
			$this->assertFalse( \Cybermaps\Core\CacheManager::get( 'cybermaps_rss_sitemap_v2', 'sitemap' ) );
			$this->assertFalse( get_transient( 'cybermaps_rss_sitemap' ) );
		}
	}

	public function test_cache_hit_is_rejected_if_generation_changes_during_cache_read(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array( 'enable_caching' => '1' );
		$cache = '<cached-private-rss/>';
		\Cybermaps\Core\CacheManager::set_if_current( 'cybermaps_rss_sitemap_v2', $cache, HOUR_IN_SECONDS, 'sitemap', \Cybermaps\Core\CacheManager::get_generation( 'sitemap', true ) );
		$GLOBALS['cybermaps_mock_get_transient_observer'] = static function ( string $key ) use ( $cache ): void {
			if ( ( $GLOBALS['cybermaps_mock_transients'][ $key ]['value'] ?? null ) === $cache ) {
				unset( $GLOBALS['cybermaps_mock_get_transient_observer'] );
				\Cybermaps\Core\CacheManager::clear_family( 'sitemap' );
			}
		};
		$this->assertGenerationUnavailable();
	}

	public function test_generation_change_during_cache_write_never_returns_stale_content(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array( 'enable_caching' => '1', 'rss_sitemap_types' => array() );
		$GLOBALS['cybermaps_mock_set_transient_observer'] = static function ( string $key, mixed $value, int $expiry, string $phase ): void {
			if ( 'after' === $phase && is_array( $value ) && str_contains( (string) ( $value['value'] ?? '' ), '<rss' ) ) {
				unset( $GLOBALS['cybermaps_mock_set_transient_observer'] );
				\Cybermaps\Core\CacheManager::clear_family( 'sitemap' );
			}
		};
		$this->assertGenerationUnavailable();
		$this->assertFalse( \Cybermaps\Core\CacheManager::get( 'cybermaps_rss_sitemap_v2', 'sitemap' ) );
	}

	public function test_late_legacy_transient_is_never_replayed_and_remains_family_cleanup_target(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array( 'enable_caching' => '1', 'rss_sitemap_types' => array() );
		\Cybermaps\Core\CacheManager::clear_family( 'sitemap' );
		set_transient( 'cybermaps_rss_sitemap', '<rss>private legacy bytes</rss>', HOUR_IN_SECONDS );
		$output = ( new RSSProvider() )->generate();
		$this->assertStringNotContainsString( 'private legacy bytes', $output );
		$this->assertSame( $output, \Cybermaps\Core\CacheManager::get( 'cybermaps_rss_sitemap_v2', 'sitemap' ) );
		\Cybermaps\Core\CacheManager::clear_family( 'sitemap' );
		$this->assertFalse( get_transient( 'cybermaps_rss_sitemap' ) );
	}

	public function test_initial_generation_precedes_configuration_filter_observation(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array( 'enable_caching' => '1', 'rss_sitemap_types' => array() );
		$GLOBALS['cybermaps_mock_filter_callbacks']['pre_option_cybermaps_settings'] = array(
			static function ( mixed $pre ): mixed {
				$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['exclude_post_ids'] = '7';
				\Cybermaps\Core\CacheManager::clear_family( 'sitemap' );
				return $pre;
			},
		);
		try {
			$this->assertGenerationUnavailable();
			$this->assertFalse( \Cybermaps\Core\CacheManager::get( 'cybermaps_rss_sitemap_v2', 'sitemap' ) );
		} finally {
			$GLOBALS['cybermaps_mock_filter_callbacks'] = array();
		}
	}

	private function assertGenerationUnavailable(): void {
		try {
			( new RSSProvider() )->generate();
			$this->fail( 'A changed generation must not return selected RSS bytes.' );
		} catch ( \Cybermaps\Core\BuildUnavailableException ) {
			$this->addToAssertionCount( 1 );
		}
	}

}
}
