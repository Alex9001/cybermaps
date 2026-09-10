<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Discovery\StaticBridge;

class StaticBridgePurgeTest extends \WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();

		global $cybermaps_mock_options;
		$cybermaps_mock_options = array(
				'cybermaps_settings' => array(
					'static_engine_mode'   => 'all',
					'enable_discovery_hub' => '1',
				),
		);
		$GLOBALS['cybermaps_mock_is_multisite'] = false;

		foreach ( \Cybermaps\Discovery\StaticOwnershipStore::all_option_names() as $option_name ) {
			\delete_option( $option_name );
		}
		\delete_option( 'cybermaps_static_operation_lock' );
		\delete_option( 'cybermaps_static_sync_state' );
		$this->reset_bridge_lock_state();

		$this->delete_test_file( 'robots.txt' );
		$this->delete_test_file( 'ai.json' );
		$this->delete_test_file( 'llms.txt' );
		$this->delete_test_file( 'owned-static.txt' );
		$this->delete_test_file( 'discovery/chunks/1.json' );
		$this->delete_test_file( \Cybermaps\Sitemap\Orchestrator::get_sitemap_base() . '.xml' );
	}

	protected function tearDown(): void {
		$this->delete_test_file( 'robots.txt' );
		$this->delete_test_file( 'ai.json' );
		$this->delete_test_file( 'llms.txt' );
		$this->delete_test_file( 'owned-static.txt' );
		$this->delete_test_file( 'discovery/chunks/1.json' );
		$this->delete_test_file( \Cybermaps\Sitemap\Orchestrator::get_sitemap_base() . '.xml' );

		parent::tearDown();
	}

	public function test_purge_all_deletes_root_ai_json(): void {
		$bridge = StaticBridge::get_instance();
		$base   = \Cybermaps\Sitemap\Orchestrator::get_sitemap_base();

		$bridge->write_file( $base . '.xml', '<xml/>' );
		$bridge->write_file( 'ai.json', '{}' );
		$bridge->write_file( 'discovery/chunks/1.json', '{}' );

		$result = $bridge->purge_all();

		$this->assertTrue( $result['success'] );
		$this->assertContains( 'ai.json', $result['deleted'] );
		$this->assertFileDoesNotExist( ABSPATH . 'ai.json' );
	}

	public function test_purge_all_retains_untracked_robots_file(): void {
		file_put_contents( ABSPATH . 'robots.txt', "User-agent: *\nDisallow: /private/\n" );

		$result = StaticBridge::get_instance()->purge_all();

		$this->assertTrue( $result['success'] );
		$this->assertFileExists( ABSPATH . 'robots.txt' );
		$this->assertNotContains( 'robots.txt', $result['deleted'] );
	}

	public function test_purge_all_retains_tracked_file_when_contents_changed(): void {
		$bridge = StaticBridge::get_instance();
		$bridge->write_file( 'robots.txt', "User-agent: *\nAllow: /\n" );
		file_put_contents( ABSPATH . 'robots.txt', "User-agent: *\nDisallow: /private/\n" );

		$result = $bridge->purge_all();

		$this->assertTrue( $result['success'] );
		$this->assertFileExists( ABSPATH . 'robots.txt' );
		$this->assertSame( 'content_changed', $result['retained']['robots.txt'] );
	}

	public function test_write_refuses_to_replace_untracked_existing_file(): void {
		$original = "User-agent: *\nDisallow: /private/\n";
		file_put_contents( ABSPATH . 'robots.txt', $original );

		$bridge = StaticBridge::get_instance();
		$result = $bridge->write_file( 'robots.txt', "User-agent: *\nAllow: /\n" );

		$this->assertFalse( $result );
		$this->assertSame( $original, file_get_contents( ABSPATH . 'robots.txt' ) );
		$this->assertArrayNotHasKey( 'robots.txt', get_option( 'cybermaps_static_hashes', array() ) );

		$last_result = $bridge->get_last_write_result();
		$this->assertSame( 'conflict', $last_result['status'] );
		$this->assertSame( 'untracked_existing_file', $last_result['code'] );

		$errors = get_option( 'cybermaps_static_write_errors', array() );
		$this->assertSame( 'untracked_existing_file', $errors['robots.txt']['code'] );
		$this->assertArrayHasKey( 'current_hash', $errors['robots.txt'] );
		$this->assertArrayHasKey( 'desired_hash', $errors['robots.txt'] );
	}

	public function test_write_refuses_to_replace_modified_owned_file(): void {
		$bridge = StaticBridge::get_instance();
		$this->assertTrue( $bridge->write_file( 'owned-static.txt', 'generated-v1' ) );

		$owned_hash = get_option( 'cybermaps_static_hashes', array() )['owned-static.txt'];
		file_put_contents( ABSPATH . 'owned-static.txt', 'user-edited' );

		$this->assertFalse( $bridge->write_file( 'owned-static.txt', 'generated-v2' ) );
		$this->assertSame( 'user-edited', file_get_contents( ABSPATH . 'owned-static.txt' ) );
		$this->assertSame(
			$owned_hash,
			get_option( 'cybermaps_static_hashes', array() )['owned-static.txt']
		);
		$this->assertSame( 'owned_file_modified', $bridge->get_last_write_result()['code'] );
	}

	public function test_write_updates_file_when_recorded_hash_matches_disk(): void {
		$bridge = StaticBridge::get_instance();
		$this->assertTrue( $bridge->write_file( 'owned-static.txt', 'generated-v1' ) );
		$this->assertTrue( $bridge->write_file( 'owned-static.txt', 'generated-v2' ) );

		$this->assertSame( 'generated-v2', file_get_contents( ABSPATH . 'owned-static.txt' ) );
		$this->assertSame(
			md5( 'generated-v2' ),
			get_option( 'cybermaps_static_hashes', array() )['owned-static.txt']
		);
		$this->assertSame( 'written', $bridge->get_last_write_result()['code'] );
	}

	public function test_unchanged_desired_content_does_not_hide_disk_modification(): void {
		$bridge = StaticBridge::get_instance();
		$this->assertTrue( $bridge->write_file( 'owned-static.txt', 'generated-v1' ) );
		file_put_contents( ABSPATH . 'owned-static.txt', 'user-edited' );

		$this->assertFalse( $bridge->write_file( 'owned-static.txt', 'generated-v1' ) );
		$this->assertSame( 'user-edited', file_get_contents( ABSPATH . 'owned-static.txt' ) );
		$this->assertSame( 'owned_file_modified', $bridge->get_last_write_result()['code'] );
	}

	public function test_disabling_engine_preserves_modified_file_ownership_evidence(): void {
		global $cybermaps_mock_options;
		$cybermaps_mock_options['cybermaps_settings'] = array(
			'static_engine_mode'   => 'all',
			'enable_discovery_hub' => '1',
		);

		$bridge = StaticBridge::get_instance();
		$this->assertTrue( $bridge->write_file( 'robots.txt', "User-agent: *\nAllow: /\n" ) );
		$owned_hash = get_option( 'cybermaps_static_hashes', array() )['robots.txt'];
		file_put_contents( ABSPATH . 'robots.txt', "User-agent: *\nDisallow: /private/\n" );

		$settings = new \Cybermaps\Admin\Settings();
		$settings->on_settings_updated(
			array(
				'static_engine_mode' => 'all',
			),
			array(
				'static_engine_mode' => 'off',
			)
		);

		$this->assertFileExists( ABSPATH . 'robots.txt' );
		$this->assertSame(
			$owned_hash,
			get_option( 'cybermaps_static_hashes', array() )['robots.txt']
		);
		$this->assertSame(
			'purge_owned_file_modified',
			get_option( 'cybermaps_static_write_errors', array() )['robots.txt']['code']
		);
	}

	public function test_disabling_discovery_hub_purges_only_discovery_output(): void {
		global $cybermaps_mock_options;
		$cybermaps_mock_options['cybermaps_settings'] = array(
			'static_engine_mode'   => 'all',
			'enable_discovery_hub' => '1',
		);

		$bridge = StaticBridge::get_instance();
		$this->assertTrue( $bridge->write_file( 'sitemap.xml', '<sitemapindex/>' ) );
		$this->assertTrue( $bridge->write_file( 'llms.txt', '# Site' ) );

		( new \Cybermaps\Admin\Settings() )->on_settings_updated(
				array(
					'static_engine_mode'   => 'all',
					'enable_discovery_hub' => '1',
				),
				array(
					'static_engine_mode'   => 'all',
					'enable_discovery_hub' => '0',
				)
		);

		$this->assertFileExists( ABSPATH . 'sitemap.xml' );
		$this->assertFileDoesNotExist( ABSPATH . 'llms.txt' );
		$this->assertArrayHasKey( 'sitemap.xml', get_option( 'cybermaps_static_hashes', array() ) );
		$this->assertArrayNotHasKey( 'llms.txt', get_option( 'cybermaps_static_hashes', array() ) );

		$this->delete_test_file( 'sitemap.xml' );
	}

	public function test_analytics_only_settings_save_does_not_schedule_static_publication(): void {
		$GLOBALS['cybermaps_mock_scheduled'] = array();

		( new \Cybermaps\Admin\Settings() )->on_settings_updated(
			array(
				'static_engine_mode'   => 'all',
				'enable_discovery_hub' => '1',
				'enable_analytics'     => '0',
				'log_retention_days'   => 30,
			),
			array(
				'static_engine_mode'   => 'all',
				'enable_discovery_hub' => '1',
				'enable_analytics'     => '1',
				'log_retention_days'   => 45,
			)
		);

		$this->assertArrayNotHasKey( 'cybermaps_bg_sync_static_files', $GLOBALS['cybermaps_mock_scheduled'] );
	}

	public function test_analytics_ip_anonymization_save_does_not_schedule_static_publication(): void {
		$GLOBALS['cybermaps_mock_scheduled'] = array();

		( new \Cybermaps\Admin\Settings() )->on_settings_updated(
			array(
				'static_engine_mode'      => 'all',
				'enable_discovery_hub'    => '1',
				'anonymize_analytics_ips' => '1',
			),
			array(
				'static_engine_mode'      => 'all',
				'enable_discovery_hub'    => '1',
				'anonymize_analytics_ips' => '0',
			)
		);

		$this->assertArrayNotHasKey( 'cybermaps_bg_sync_static_files', $GLOBALS['cybermaps_mock_scheduled'] );
	}

	public function test_stale_reconciliation_keeps_desired_and_modified_files(): void {
		$bridge = StaticBridge::get_instance();
		$this->assertTrue( $bridge->write_file( 'ai.json', '{"current":true}' ) );
		$this->assertTrue( $bridge->write_file( 'llms.txt', '# Stale' ) );
		$this->assertTrue( $bridge->write_file( 'robots.txt', "User-agent: *\nAllow: /\n" ) );
		file_put_contents( ABSPATH . 'robots.txt', "User-agent: *\nDisallow: /private/\n" );

		$result = $bridge->purge_all( '', '', 'stale', array( 'ai.json' ) );

		$this->assertSame( 'partial', $result['status'] );
		$this->assertFileExists( ABSPATH . 'ai.json' );
		$this->assertFileDoesNotExist( ABSPATH . 'llms.txt' );
		$this->assertFileExists( ABSPATH . 'robots.txt' );
		$this->assertContains( 'llms.txt', $result['deleted'] );
		$this->assertSame( 'content_changed', $result['retained']['robots.txt'] );
	}

	public function test_suspension_fence_blocks_generation_until_resumed(): void {
		$bridge = StaticBridge::get_instance();
		$bridge->cancel_and_purge( 'all', true );

		$this->assertFalse( $bridge->write_file( 'owned-static.txt', 'blocked' ) );
		$this->assertSame( 'generation_suspended', $bridge->get_last_write_result()['code'] );
		$this->assertFileDoesNotExist( ABSPATH . 'owned-static.txt' );

		$bridge->resume();
		$this->assertTrue( $bridge->write_file( 'owned-static.txt', 'restored' ) );
	}

	private function delete_test_file( string $relative_path ): void {
		$path = ABSPATH . $relative_path;
		if ( file_exists( $path ) ) {
			unlink( $path );
		}
	}

	private function reset_bridge_lock_state(): void {
		$bridge = StaticBridge::get_instance();
		foreach (
			array(
				'operation_lock_token' => null,
				'operation_generation' => null,
				'operation_lock_lost'  => false,
			) as $property => $value
		) {
			$reflection = new \ReflectionProperty( StaticBridge::class, $property );
			$reflection->setValue( $bridge, $value );
		}
		$lock = new \ReflectionProperty( StaticBridge::class, 'operation_lock' );
		$lock->getValue( $bridge )->reset_local_state();
		$store = new \ReflectionProperty( StaticBridge::class, 'ownership_store' );
		$store->getValue( $bridge )->clear_local_cache();
	}
}
