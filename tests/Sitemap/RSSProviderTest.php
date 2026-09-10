<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Sitemap;

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
			$GLOBALS['cybermaps_mock_home_url']
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
		set_transient( 'cybermaps_rss_sitemap', '<cached-rss/>', HOUR_IN_SECONDS );

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

		$this->assertSame( $output, get_transient( 'cybermaps_rss_sitemap' ) );
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
}
