<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Core\EndpointRegistry;
use Cybermaps\Discovery\LLMS;
use Cybermaps\Discovery\PublicationSizeLimitException;
use Cybermaps\Discovery\StaticBridge;

class StaticBridgeSyncReportTest extends \WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		$registry_instance = new \ReflectionProperty( EndpointRegistry::class, 'instance' );
		$registry_instance->setValue( null, null );
		unset( $GLOBALS['cybermaps_mock_home_url'] );
		$GLOBALS['cybermaps_mock_is_multisite'] = false;
		$GLOBALS['cybermaps_mock_scheduled']    = array();
		$GLOBALS['cybermaps_mock_status_headers'] = array();
		$GLOBALS['cybermaps_mock_options']      = array(
			'cybermaps_settings' => array(
				'static_engine_mode'   => 'well_known',
				'enable_discovery_hub' => '1',
			),
		);
		unset( $GLOBALS['cybermaps_mock_wp_filesystem_put_contents_callback'] );
		$this->delete_generated_files();
	}

	protected function tearDown(): void {
		$this->delete_generated_files();
		unset(
			$GLOBALS['cybermaps_mock_home_url'],
			$GLOBALS['cybermaps_mock_wp_filesystem_put_contents_callback']
		);
		parent::tearDown();
	}

	public function test_well_known_inventory_comes_from_registry(): void {
		$report   = StaticBridge::get_instance()->sync_all();
		$expected = \array_column(
			EndpointRegistry::get_instance()->get_static_targets( 'well_known' ),
			'filename'
		);

		\sort( $expected );
		$desired = $report['desired'];
		\sort( $desired );

		$this->assertSame( 'complete', $report['status'] );
		$this->assertTrue( $report['success'] );
		$this->assertSame( $expected, $desired );
		$this->assertNotContains( 'robots.txt', $desired );
		$this->assertContains( 'ai.json', $desired );
		$this->assertSame(
			(int) \get_option( 'cybermaps_static_generation', 0 ),
			$report['generation']
		);
	}

	public function test_transient_full_sync_failure_schedules_a_fresh_retry_that_can_succeed(): void {
		$settings   = $GLOBALS['cybermaps_mock_options']['cybermaps_settings'];
		$generation = (int) get_option( 'cybermaps_static_generation', 0 );
		$GLOBALS['cybermaps_mock_wp_filesystem_put_contents_callback'] = static fn (): bool => false;

		$failed = StaticBridge::get_instance()->sync_all();
		$retry  = get_option( 'cybermaps_static_failed_retry', false );

		$this->assertFalse( $failed['success'] );
		$this->assertGreaterThan( 0, $failed['counts']['failed'] );
		$this->assertContains(
			'temporary_write_failed',
			array_column( $failed['failed'], 'code' )
		);
		$this->assertIsArray( $retry );
		$this->assertSame( 'scheduled', $retry['status'] );
		$this->assertSame( 1, $retry['attempts'] );
		$this->assertSame( $generation, $retry['generation'] );
		$this->assertNotFalse( wp_next_scheduled( 'cybermaps_bg_sync_static_files' ) );
		$this->assertSame( $settings, get_option( 'cybermaps_settings' ) );
		$this->assertSame( $generation, (int) get_option( 'cybermaps_static_generation', 0 ) );

		unset( $GLOBALS['cybermaps_mock_wp_filesystem_put_contents_callback'] );
		// WordPress removes a single event before invoking its callback.
		unset( $GLOBALS['cybermaps_mock_scheduled']['cybermaps_bg_sync_static_files'] );
		$succeeded = StaticBridge::get_instance()->sync_all();

		$this->assertTrue( $succeeded['success'] );
		$this->assertSame( 'complete', $succeeded['status'] );
		$this->assertFalse( get_option( 'cybermaps_static_failed_retry', false ) );
		$this->assertSame( $settings, get_option( 'cybermaps_settings' ) );
		$this->assertSame( $generation, (int) get_option( 'cybermaps_static_generation', 0 ) );
	}

	public function test_full_static_sync_omits_filtered_empty_children_and_prunes_the_materialized_index(): void {
		$previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$child_one     = 'site-map-posts-post-1.xml';
		$child_two     = 'site-map-posts-post-2.xml';
		$index_file    = 'site-map.xml';

		$GLOBALS['cybermaps_mock_options'] = array(
			'blog_public'        => '1',
			'cybermaps_settings' => array(
				'static_engine_mode'   => 'all',
				'enable_discovery_hub' => '0',
				'include_homepage'     => '0',
				'include_authors'      => '0',
				'include_archives'     => '0',
				'sitemap_url_base'     => 'site-map',
			),
		);
		$GLOBALS['cybermaps_mock_post_types'] = array( 'post' );
		$GLOBALS['cybermaps_mock_post_type_objects'] = array(
			'post' => (object) array(
				'name'   => 'post',
				'public' => true,
			),
		);
		$GLOBALS['cybermaps_mock_taxonomies'] = array();
		$GLOBALS['cybermaps_mock_wp_query_args'] = array();
		$GLOBALS['cybermaps_mock_wp_query_callback'] = static function ( array $args ): array {
			$per_page = max( 1, (int) ( $args['posts_per_page'] ?? 1 ) );
			$page     = max( 1, (int) ( $args['paged'] ?? 1 ) );
			$count    = 1 === $page ? $per_page : min( 1, $per_page );
			$start    = 4001 - ( ( $page - 1 ) * $per_page );
			$rows     = array();
			for ( $offset = 0; $offset < $count; ++$offset ) {
				$id     = max( 1, $start - $offset );
				$rows[] = (object) array(
					'ID'                => $id,
					'post_type'         => 'post',
					'post_status'       => 'publish',
					'post_password'     => '',
					'post_title'        => 'Post ' . $id,
					'post_modified_gmt' => '2026-07-30 10:00:00',
				);
			}
			return $rows;
		};
		$GLOBALS['cybermaps_mock_filter_callbacks']['cybermaps_publication_eligibility'] = array(
			static fn ( $decision ) => $decision->with_reasons( array( 'test_exclusion' ) ),
		);
		$GLOBALS['wpdb'] = new class() {
			public string $posts = 'wp_posts';

			public function prepare( string $query, mixed ...$args ): array {
				return array( 'query' => $query, 'args' => $args );
			}

			public function get_var( mixed $query ): int {
				unset( $query );
				return 4000;
			}
		};

		$bridge = StaticBridge::get_instance();
		try {
			$this->assertTrue( $bridge->write_file( $child_one, '<urlset><url /></urlset>' ) );
			$this->assertTrue( $bridge->write_file( $child_two, '<urlset><url /></urlset>' ) );

			$report = $bridge->sync_all();
			$index  = file_get_contents( ABSPATH . $index_file );

			$this->assertSame( 'complete', $report['status'] );
			$this->assertNotContains( $child_one, $report['desired'] );
			$this->assertNotContains( $child_two, $report['desired'] );
			$this->assertContains( $child_one, $report['deleted'] );
			$this->assertContains( $child_two, $report['deleted'] );
			$this->assertFileDoesNotExist( ABSPATH . $child_one );
			$this->assertFileDoesNotExist( ABSPATH . $child_two );
			$this->assertIsString( $index );
			$this->assertStringNotContainsString( $child_one, (string) $index );
			$this->assertStringNotContainsString( $child_two, (string) $index );
			$this->assertNotContains( 404, $GLOBALS['cybermaps_mock_status_headers'] ?? array() );
			$this->assertGreaterThanOrEqual( 2, count( $GLOBALS['cybermaps_mock_wp_query_args'] ) );
		} finally {
			wp_delete_file( ABSPATH . $child_one );
			wp_delete_file( ABSPATH . $child_two );
			wp_delete_file( ABSPATH . $index_file );
			unset( $GLOBALS['cybermaps_mock_wp_query_callback'] );
			$GLOBALS['cybermaps_mock_filter_callbacks'] = array();
			$GLOBALS['wpdb'] = $previous_wpdb;
		}
	}

	public function test_partial_sync_preserves_last_success_and_skips_indexes(): void {
		$last_success = '2026-01-02T03:04:05+00:00';
		\update_option( 'cybermaps_last_static_sync', $last_success, false );
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['static_engine_mode'] = 'all';
		$this->assertTrue( StaticBridge::get_instance()->write_file( 'robots.txt', 'legacy-owned' ) );
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['static_engine_mode'] = 'well_known';
		\file_put_contents( ABSPATH . 'ai-usage.json', '{"owned_by":"someone_else"}' );

		$report = StaticBridge::get_instance()->sync_all();

		$this->assertSame( 'partial', $report['status'] );
		$this->assertFalse( $report['success'] );
		$this->assertSame(
			'untracked_existing_file',
			$report['conflicted']['ai-usage.json']['code']
		);
		$this->assertSame( 'dependency_failed', $report['skipped']['stale_reconciliation']['code'] );
		$this->assertFileExists( ABSPATH . 'robots.txt' );
		$this->assertSame( $last_success, \get_option( 'cybermaps_last_static_sync' ) );
		$this->assertNotFalse( \get_option( 'cybermaps_last_static_sync_attempt', false ) );
		$this->assertSame(
			'partial',
			\get_option( 'cybermaps_last_static_sync_report', array() )['status']
		);
	}

	public function test_owned_legacy_extensionless_publications_are_removed(): void {
		$bridge = StaticBridge::get_instance();
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['static_engine_mode'] = 'all';
		$legacy_files = array(
			'.well-known/.htaccess'     => "# BEGIN Cybermaps\nRewriteEngine On\n# END Cybermaps\n",
			'.well-known/ai-discovery'  => '{}',
			'.well-known/api-catalog'   => '{}',
			'ai-discovery'              => '{}',
		);
		foreach ( $legacy_files as $filename => $content ) {
			$this->assertTrue( $bridge->write_file( $filename, $content ) );
		}
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['static_engine_mode'] = 'well_known';

		$report = $bridge->sync_all();

		$this->assertSame( 'complete', $report['status'] );
		foreach ( array( '.well-known/.htaccess', '.well-known/ai-discovery' ) as $filename ) {
			$this->assertNotContains( $filename, $report['desired'] );
			$this->assertContains( $filename, $report['deleted'] );
			$this->assertFileDoesNotExist( ABSPATH . $filename );
			$this->assertArrayNotHasKey(
				$filename,
				\get_option( 'cybermaps_static_hashes', array() )
			);
		}
		$this->assertContains( 'ai-discovery', $report['desired'] );
		$this->assertContains( 'ai-discovery', $report['written'] );
		$this->assertFileExists( ABSPATH . 'ai-discovery' );
		$this->assertArrayHasKey( 'ai-discovery', \get_option( 'cybermaps_static_hashes', array() ) );
		$this->assertContains( '.well-known/api-catalog', $report['desired'] );
		$this->assertContains( '.well-known/api-catalog', $report['written'] );
		$this->assertFileExists( ABSPATH . '.well-known/api-catalog' );
		$this->assertArrayHasKey( '.well-known/api-catalog', \get_option( 'cybermaps_static_hashes', array() ) );
	}

	public function test_modified_legacy_publication_is_retained(): void {
		$bridge = StaticBridge::get_instance();
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['static_engine_mode'] = 'all';
		$this->assertTrue( $bridge->write_file( '.well-known/.htaccess', "# Core rules\n" ) );
		\file_put_contents( ABSPATH . '.well-known/.htaccess', "# Host-edited rules\n" );
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['static_engine_mode'] = 'well_known';

		$report = $bridge->sync_all();

		$this->assertSame( 'partial', $report['status'] );
		$this->assertSame(
			'content_changed',
			$report['retained']['.well-known/.htaccess']
		);
		$this->assertSame(
			"# Host-edited rules\n",
			\file_get_contents( ABSPATH . '.well-known/.htaccess' )
		);
	}

	public function test_owned_legacy_robots_file_is_removed_but_never_regenerated(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['static_engine_mode'] = 'all';
		$bridge = StaticBridge::get_instance();
		$this->assertTrue( $bridge->write_file( 'robots.txt', "User-agent: *\nAllow: /\n" ) );

		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['enable_discovery_hub'] = '0';
		$report = $bridge->sync_all();

		$this->assertSame( 'complete', $report['status'] );
		$this->assertNotContains( 'robots.txt', $report['desired'] );
		$this->assertContains( 'robots.txt', $report['deleted'] );
		$this->assertFileDoesNotExist( ABSPATH . 'robots.txt' );
	}

	public function test_owned_feed_file_is_removed_now_that_websub_feed_delivery_is_dynamic(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['static_engine_mode'] = 'all';
		$bridge = StaticBridge::get_instance();
		$this->assertTrue( $bridge->write_file( 'feed.json', '{"version":"old-static-copy"}' ) );

		$report = $bridge->sync_all();

		$this->assertNotContains( 'feed.json', $report['desired'] );
		$this->assertContains( 'feed.json', $report['deleted'] );
		$this->assertFileDoesNotExist( ABSPATH . 'feed.json' );
		$this->assertArrayNotHasKey(
			'feed.json',
			get_option( 'cybermaps_static_hashes', array() )
		);
	}

	public function test_content_changes_do_not_schedule_when_mode_is_off(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['static_engine_mode'] = 'off';

		$result = StaticBridge::get_instance()->request_sync();

		$this->assertNull( $result );
		$this->assertFalse( \wp_next_scheduled( 'cybermaps_bg_sync_static_files' ) );
	}

	public function test_remote_frontend_requires_explicit_physical_root(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['frontend_base_url'] = 'https://frontend.example';

		$result = StaticBridge::get_instance()->write_file( 'ai.json', '{}' );

		$this->assertFalse( $result );
		$this->assertSame(
			'headless_publication_root_required',
			StaticBridge::get_instance()->get_last_write_result()['code']
		);
	}

	public function test_stale_reconciliation_clears_orphaned_write_diagnostics(): void {
		\update_option(
			'cybermaps_static_write_errors',
			array(
				'ai.json' => array(
					'status' => 'error',
					'code'   => 'old_failure',
				),
			),
			false
		);

		$result = StaticBridge::get_instance()->purge_all( '', '', 'stale', array() );

		$this->assertTrue( $result['success'] );
		$this->assertSame( array(), \get_option( 'cybermaps_static_write_errors', array() ) );
	}

	public function test_subdirectory_small_static_publication_uses_the_site_root(): void {
		$GLOBALS['cybermaps_mock_home_url'] = 'https://example.com/blog';

		$result = StaticBridge::get_instance()->write_file( 'ai.json', '{}' );

		$this->assertTrue( $result );
		$this->assertFileExists( ABSPATH . 'ai.json' );
	}

	public function test_oversized_generation_removes_a_previous_unchanged_owned_full_file(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['static_engine_mode'] = 'all';
		$bridge = StaticBridge::get_instance();
		$this->assertTrue( $bridge->write_file( 'llms-full.txt', "# Previous complete body\n" ) );

		$report = $this->invoke_oversized_full_publication( $bridge );

		$this->assertSame( 'publication_too_large', $report['failed']['llms-full.txt']['code'] );
		$this->assertContains( 'llms-full.txt', $report['deleted'] );
		$this->assertFileDoesNotExist( ABSPATH . 'llms-full.txt' );
		$this->assertArrayNotHasKey( 'llms-full.txt', get_option( 'cybermaps_static_hashes', array() ) );
	}

	public function test_oversized_generation_retains_a_user_modified_full_file(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['static_engine_mode'] = 'all';
		$bridge = StaticBridge::get_instance();
		$this->assertTrue( $bridge->write_file( 'llms-full.txt', "# Previous complete body\n" ) );
		file_put_contents( ABSPATH . 'llms-full.txt', "# Administrator-modified body\n" );

		$report = $this->invoke_oversized_full_publication( $bridge );

		$this->assertSame( 'publication_too_large', $report['failed']['llms-full.txt']['code'] );
		$this->assertSame( 'content_changed', $report['retained']['llms-full.txt'] );
		$this->assertFileExists( ABSPATH . 'llms-full.txt' );
		$this->assertSame( "# Administrator-modified body\n", file_get_contents( ABSPATH . 'llms-full.txt' ) );
	}

	public function test_oversized_generation_surfaces_an_untracked_existing_full_file(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['static_engine_mode'] = 'all';
		file_put_contents( ABSPATH . 'llms-full.txt', "# Untracked server file\n" );

		$report = $this->invoke_oversized_full_publication( StaticBridge::get_instance() );

		$this->assertSame( 'publication_too_large', $report['failed']['llms-full.txt']['code'] );
		$this->assertSame( 'untracked_existing_file', $report['retained']['llms-full.txt'] );
		$this->assertFileExists( ABSPATH . 'llms-full.txt' );
		$this->assertSame(
			'untracked_existing_file',
			get_option( 'cybermaps_static_write_errors', array() )['llms-full.txt']['code']
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function invoke_oversized_full_publication( StaticBridge $bridge ): array {
		$acquire = new \ReflectionMethod( StaticBridge::class, 'acquire_operation_lock' );
		$release = new \ReflectionMethod( StaticBridge::class, 'release_operation_lock' );
		$this->assertTrue( $acquire->invoke( $bridge ) );
		$new_report = new \ReflectionMethod( StaticBridge::class, 'new_sync_report' );
		$report     = $new_report->invoke( $bridge, 'all' );
		$publish    = new \ReflectionMethod( StaticBridge::class, 'publish_for_sync' );
		$factory    = static function (): string {
			throw new PublicationSizeLimitException( 'llms-full.txt', LLMS::OUTPUT_MAX_BYTES );
		};
		$arguments = array( &$report, 'llms-full.txt', $factory );
		try {
			$publish->invokeArgs( $bridge, $arguments );
		} finally {
			$release->invoke( $bridge );
		}

		return $report;
	}

	private function delete_generated_files(): void {
		$files = array(
			'robots.txt',
			'sitemap.xml',
			'site-map.xml',
			'site-map-posts-post-1.xml',
			'site-map-posts-post-2.xml',
			'ai.json',
			'ai-discovery',
			'.well-known/.htaccess',
			'.well-known/ai-discovery',
			'.well-known/api-catalog',
		);
		foreach ( EndpointRegistry::get_instance()->get_static_targets( 'all' ) as $target ) {
			$files[] = (string) $target['filename'];
		}

		foreach ( \array_unique( $files ) as $file ) {
			$path = ABSPATH . $file;
			if ( \is_file( $path ) ) {
				\unlink( $path );
			}
		}
	}
}
