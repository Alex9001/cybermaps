<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class StaticBridgeTest extends \WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_is_multisite'] = false;
		$GLOBALS['cybermaps_mock_options']      = array(
				'cybermaps_settings' => array(
					'static_engine_mode'   => 'all',
					'enable_discovery_hub' => '1',
				),
		);
		$GLOBALS['cybermaps_mock_filter_callbacks'] = array();
		$GLOBALS['cybermaps_mock_scheduled']        = array();
	}

	public function test_get_instance() {
		$instance = \Cybermaps\Discovery\StaticBridge::get_instance();
		$this->assertInstanceOf( \Cybermaps\Discovery\StaticBridge::class, $instance );
	}

	public function test_get_file_path() {
		$bridge = \Cybermaps\Discovery\StaticBridge::get_instance();
		$path = $bridge->get_file_path( 'sitemap.xml' );
		$this->assertEquals( ABSPATH . 'sitemap.xml', $path );
	}

	public function test_write_file() {
		$bridge = \Cybermaps\Discovery\StaticBridge::get_instance();
		$filename = 'test-static.txt';
		$content = 'hello world';
		$result = $bridge->write_file( $filename, $content );

		$path = $bridge->get_file_path( $filename );
		$this->assertTrue( $result );
		$this->assertFileExists( $path );
		$this->assertEquals( $content, file_get_contents( $path ) );

		wp_delete_file( $path );
	}

	public function test_write_file_refuses_an_oversized_complete_llms_body(): void {
		$bridge   = \Cybermaps\Discovery\StaticBridge::get_instance();
		$filename = 'llms-full.txt';
		$path     = $bridge->get_file_path( $filename );
		wp_delete_file( $path );

		$result = $bridge->write_file(
			$filename,
			str_repeat( 'x', \Cybermaps\Discovery\LLMS::FULL_OUTPUT_MAX_BYTES + 1 )
		);

		$this->assertFalse( $result );
		$this->assertSame( 'publication_too_large', $bridge->get_last_write_result()['code'] );
		$this->assertFileDoesNotExist( $path );
	}

	public function test_ownership_revision_changes_only_when_inventory_is_persisted(): void {
		$bridge   = \Cybermaps\Discovery\StaticBridge::get_instance();
		$filename = 'test-static-revision.txt';
		$path     = $bridge->get_file_path( $filename );

		$this->assertTrue( $bridge->write_file( $filename, 'version one' ) );
		$written_revision = (int) get_option( 'cybermaps_static_ownership_revision', 0 );
		$this->assertGreaterThan( 0, $written_revision );

		$this->assertTrue( $bridge->write_file( $filename, 'version one' ) );
		$this->assertSame(
			$written_revision,
			(int) get_option( 'cybermaps_static_ownership_revision', 0 ),
			'An unchanged file does not persist a new ownership inventory.'
		);

		$this->assertTrue( $bridge->write_file( $filename, 'version two' ) );
		$this->assertGreaterThan(
			$written_revision,
			(int) get_option( 'cybermaps_static_ownership_revision', 0 )
		);

		$updated_revision = (int) get_option( 'cybermaps_static_ownership_revision', 0 );
		$purge            = $bridge->purge_all( '', $filename );
		$this->assertTrue( $purge['success'] );
		$this->assertGreaterThan(
			$updated_revision,
			(int) get_option( 'cybermaps_static_ownership_revision', 0 )
		);
		$this->assertFileDoesNotExist( $path );
	}

	public function test_schedule_sync() {
		$bridge = \Cybermaps\Discovery\StaticBridge::get_instance();
		$bridge->request_sync();
		$this->assertNotFalse( wp_next_scheduled( 'cybermaps_bg_sync_static_files' ) );
	}

	public function test_missing_static_publication_queues_one_repair_and_keeps_dynamic_delivery_available(): void {
		$bridge   = \Cybermaps\Discovery\StaticBridge::get_instance();
		$filename = 'ai.json';
		$path     = $bridge->get_file_path( $filename );
		wp_delete_file( $path );

		$this->assertTrue( $bridge->request_repair_for_path( '/ai.json' ) );
		$scheduled = wp_next_scheduled( 'cybermaps_bg_sync_static_files' );
		$this->assertNotFalse( $scheduled );

		$this->assertTrue( $bridge->request_repair_for_path( '/ai.json' ) );
		$this->assertSame( $scheduled, wp_next_scheduled( 'cybermaps_bg_sync_static_files' ) );
	}

	public function test_existing_readable_static_publication_does_not_queue_repair(): void {
		$bridge   = \Cybermaps\Discovery\StaticBridge::get_instance();
		$filename = 'repair-current.json';
		$this->assertTrue( $bridge->write_file( $filename, '{"current":true}' ) );
		$GLOBALS['cybermaps_mock_scheduled'] = array();

		$this->assertFalse( $bridge->request_repair_for_filename( $filename, 'all' ) );
		$this->assertFalse( wp_next_scheduled( 'cybermaps_bg_sync_static_files' ) );

		wp_delete_file( $bridge->get_file_path( $filename ) );
	}

	public function test_repair_is_disabled_when_static_mode_is_off_or_multisite(): void {
		$bridge = \Cybermaps\Discovery\StaticBridge::get_instance();
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['static_engine_mode'] = 'off';
		$this->assertFalse( $bridge->request_repair_for_filename( 'sitemap.xml', 'all' ) );

		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['static_engine_mode'] = 'all';
		$GLOBALS['cybermaps_mock_is_multisite'] = true;
		$this->assertFalse( $bridge->request_repair_for_filename( 'sitemap.xml', 'all' ) );
		$this->assertFalse( wp_next_scheduled( 'cybermaps_bg_sync_static_files' ) );
	}

	public function test_untrusted_legacy_continuation_cannot_skip_publication(): void {
		$bridge   = \Cybermaps\Discovery\StaticBridge::get_instance();
		$filename = 'test-static-resume.json';
		$this->assertTrue( $bridge->write_file( $filename, '{"generation":1}' ) );
		update_option(
			'cybermaps_static_sync_state',
			array(
				'generation' => 0,
				'completed'  => array( $filename ),
				'omitted'    => array(),
			),
			false
		);

		$acquire = new \ReflectionMethod( $bridge, 'acquire_operation_lock' );
		$begin   = new \ReflectionMethod( $bridge, 'begin_sync_run' );
		$publish = new \ReflectionMethod( $bridge, 'publish_for_sync' );
		$release = new \ReflectionMethod( $bridge, 'release_operation_lock' );
		$active  = new \ReflectionProperty( $bridge, 'sync_active' );
		$report  = array(
			'generation' => 0,
			'started_at' => gmdate( 'c' ),
			'desired'    => array(),
			'written'    => array(),
			'unchanged'  => array(),
			'failed'     => array(),
			'skipped'    => array(),
			'conflicted' => array(),
		);
		$generated = false;

		$this->assertTrue( $acquire->invoke( $bridge ) );
		try {
			$begin->invoke( $bridge, $report );
			$args = array(
				&$report,
				$filename,
				static function () use ( &$generated ): string {
					$generated = true;
					return '{"generation":2}';
				},
				false,
			);
			$publish->invokeArgs( $bridge, $args );
		} finally {
			$active->setValue( $bridge, false );
			$release->invoke( $bridge );
		}

		$this->assertTrue( $generated );
		$this->assertContains( $filename, $report['written'] );
		$this->assertSame( '{"generation":2}', file_get_contents( $bridge->get_file_path( $filename ) ) );
		$bridge->purge_all( '', $filename );
	}

	public function test_custom_publication_root_rejects_relative_and_symlink_escape_paths(): void {
		$bridge = \Cybermaps\Discovery\StaticBridge::get_instance();
		$GLOBALS['cybermaps_mock_filter_callbacks']['cybermaps_static_publication_root'] = array(
			static fn(): string => 'relative/publication-root',
		);

		$this->assertFalse( $bridge->write_file( 'ai.json', '{}' ) );
		$this->assertSame( 'invalid_publication_root', $bridge->get_last_write_result()['code'] );

		$root    = sys_get_temp_dir() . '/cybermaps-root-' . uniqid( '', true );
		$outside = sys_get_temp_dir() . '/cybermaps-outside-' . uniqid( '', true );
		mkdir( $root, 0755, true );
		mkdir( $outside, 0755, true );
		symlink( $outside, $root . '/discovery' );
		$GLOBALS['cybermaps_mock_filter_callbacks']['cybermaps_static_publication_root'] = array(
			static fn(): string => $root,
		);
		try {
			$this->assertFalse( $bridge->write_file( 'discovery/test.json', '{}' ) );
			$this->assertSame( 'publication_path_escape', $bridge->get_last_write_result()['code'] );
		} finally {
			unlink( $root . '/discovery' );
			rmdir( $root );
			rmdir( $outside );
		}
	}

	public function test_purge_all() {
		$bridge = \Cybermaps\Discovery\StaticBridge::get_instance();

		// Create some dummy files to purge
		$base = \Cybermaps\Sitemap\Orchestrator::get_sitemap_base();
		$bridge->write_file( $base . '.xml', '<xml></xml>' );
		$bridge->write_file( 'ai.json', '{}' );
		$bridge->write_file( 'discovery/chunks/1.json', '{}' );

		$this->assertFileExists( ABSPATH . $base . '.xml' );
		$this->assertFileExists( ABSPATH . 'ai.json' );
		$this->assertFileExists( ABSPATH . 'discovery/chunks/1.json' );

		$result = $bridge->purge_all();

		$this->assertTrue( $result['success'] );
		$this->assertContains( $base . '.xml', $result['deleted'] );
		$this->assertContains( 'ai.json', $result['deleted'] );
		$this->assertContains( 'discovery/chunks/1.json', $result['deleted'] );

		$this->assertFileDoesNotExist( ABSPATH . $base . '.xml' );
		$this->assertFileDoesNotExist( ABSPATH . 'ai.json' );
		$this->assertFileDoesNotExist( ABSPATH . 'discovery/chunks/1.json' );
	}
}
