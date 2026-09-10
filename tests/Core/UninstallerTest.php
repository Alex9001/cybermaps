<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Core;

use Cybermaps\Core\Uninstaller;
use PHPUnit\Framework\TestCase;

final class UninstallerTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'cybermaps/cybermaps.php' );
		}

		global $cybermaps_mock_current_blog_id,
			$cybermaps_mock_deleted_network_options,
			$cybermaps_mock_deleted_post_meta_keys,
			$cybermaps_mock_deleted_user_meta_keys,
			$cybermaps_mock_is_main_site,
			$cybermaps_mock_is_multisite,
			$cybermaps_mock_network_ids,
			$cybermaps_mock_options,
			$cybermaps_mock_options_by_blog,
			$cybermaps_mock_post_meta,
			$cybermaps_mock_scheduled,
			$cybermaps_mock_site_ids,
			$cybermaps_mock_transients,
			$cybermaps_mock_user_meta,
			$wpdb;

		$cybermaps_mock_current_blog_id          = 1;
		$cybermaps_mock_deleted_network_options = array();
		$cybermaps_mock_deleted_post_meta_keys   = array();
		$cybermaps_mock_deleted_user_meta_keys   = array();
		$cybermaps_mock_is_main_site             = true;
		$cybermaps_mock_is_multisite             = false;
		$cybermaps_mock_network_ids              = array( 1 );
		$cybermaps_mock_options                  = array();
		$cybermaps_mock_options_by_blog          = array();
		$cybermaps_mock_post_meta                = array();
		$cybermaps_mock_scheduled                = array();
		$cybermaps_mock_site_ids                 = array( 1 );
		$cybermaps_mock_transients               = array();
		$cybermaps_mock_user_meta                = array();
		$wpdb                                     = new UninstallerWpdbStub();
		unset( $GLOBALS['cybermaps_mock_get_option_observer'] );
	}

	protected function tearDown(): void {
		$GLOBALS['cybermaps_mock_is_multisite'] = false;
		$GLOBALS['cybermaps_mock_is_main_site'] = true;
		$GLOBALS['cybermaps_mock_options_by_blog'] = array();
		unset( $GLOBALS['cybermaps_mock_get_option_observer'] );

		parent::tearDown();
	}

	public function test_uninstall_bootstrap_does_not_load_the_plugin_runtime(): void {
		$contents = (string) file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' );

		$this->assertStringContainsString( '/src/Autoloader.php', $contents );
		$this->assertStringNotContainsString( "require_once __DIR__ . '/cybermaps.php'", $contents );
		$this->assertStringContainsString( 'Uninstaller::run()', $contents );
	}

	public function test_opt_out_preserves_all_data(): void {
		$GLOBALS['cybermaps_mock_options'] = array(
			'cybermaps_settings' => array(
				'delete_data_on_uninstall' => '0',
			),
			'cybermaps_identity_data' => array( 'name' => 'Keep me' ),
		);
		$GLOBALS['cybermaps_mock_post_meta'] = array(
			10 => array( '_cybermaps_ai_meta' => array( 'score' => 1 ) ),
		);
		$GLOBALS['cybermaps_mock_user_meta'] = array(
			2 => array( 'cybermaps_dismiss_logs_well_known_notice' => '1' ),
		);
		$GLOBALS['cybermaps_mock_scheduled'] = array(
			'cybermaps_cleanup_logs_event'                   => 123,
			'cybermaps_cleanup_runtime_counters_event'       => 124,
			'cybermaps_continue_logs_cleanup_event'          => 143,
			'cybermaps_refresh_time_sensitive_static_files' => 173,
			'cybermaps_daily_health_snapshot'                => 223,
			'cybermaps_weekly_health_snapshot'               => 234,
			'cybermaps_weekly_health_check'                  => 244,
			'unrelated_event'                                => 456,
		);

		Uninstaller::run();

		$this->assertArrayHasKey( 'cybermaps_settings', $GLOBALS['cybermaps_mock_options'] );
		$this->assertArrayHasKey( 'cybermaps_identity_data', $GLOBALS['cybermaps_mock_options'] );
		$this->assertArrayHasKey( '_cybermaps_ai_meta', $GLOBALS['cybermaps_mock_post_meta'][10] );
		$this->assertArrayHasKey(
			'cybermaps_dismiss_logs_well_known_notice',
			$GLOBALS['cybermaps_mock_user_meta'][2]
		);
		$this->assertArrayNotHasKey( 'cybermaps_cleanup_logs_event', $GLOBALS['cybermaps_mock_scheduled'] );
		$this->assertArrayNotHasKey( 'cybermaps_cleanup_runtime_counters_event', $GLOBALS['cybermaps_mock_scheduled'] );
		$this->assertArrayNotHasKey( 'cybermaps_continue_logs_cleanup_event', $GLOBALS['cybermaps_mock_scheduled'] );
		$this->assertArrayNotHasKey( 'cybermaps_refresh_time_sensitive_static_files', $GLOBALS['cybermaps_mock_scheduled'] );
		$this->assertArrayNotHasKey( 'cybermaps_daily_health_snapshot', $GLOBALS['cybermaps_mock_scheduled'] );
		$this->assertArrayNotHasKey( 'cybermaps_weekly_health_snapshot', $GLOBALS['cybermaps_mock_scheduled'] );
		$this->assertArrayNotHasKey( 'cybermaps_weekly_health_check', $GLOBALS['cybermaps_mock_scheduled'] );
		$this->assertArrayHasKey( 'unrelated_event', $GLOBALS['cybermaps_mock_scheduled'] );
		$this->assertSame( array(), $GLOBALS['wpdb']->queries );
	}

	public function test_single_site_opt_in_deletes_core_owned_data(): void {
		$GLOBALS['cybermaps_mock_options'] = array(
			'cybermaps_settings' => array(
				'delete_data_on_uninstall' => '1',
			),
			'cybermaps_identity_data' => array( 'name' => 'Delete me' ),
			'cybermaps_runtime_counter_table_ready' => '1',
			'cybermaps_edge_cache_delivery_status' => array( array( 'status' => 'ok' ) ),
			'cybermaps_edge_cache_pending_static'  => array( array( 'id' => 'evt-1' ) ),
			'cybermaps_indexnow_queue_schema'      => '1',
			'cybermaps_static_hashes' => array(),
			'cybermaps_static_generation' => array( 'generation' => 7 ),
			'cybermaps_static_ownership_revision' => 9,
			'cybermaps_static_sync_epoch' => 11,
			'cybermaps_static_hashes_00' => array(
				'owned.txt' => array(
					'hash'       => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
					'generation' => 11,
				),
			),
			'cybermaps_media_audit_generation' => 6,
			'cybermaps_llms_yaml_cache' => array( 'legacy' => true ),
			'cybermaps_llms_full_yaml_cache' => array( 'legacy' => true ),
			'cybermaps_last_time_sensitive_static_refresh' => time() - HOUR_IN_SECONDS,
			'cybermaps_core_transient_inventory' => array(
				'cybermaps_v7_dynamic_sitemap' => array(
					'family'  => 'sitemap',
					'expires' => time() + HOUR_IN_SECONDS,
				),
			),
		);
		$GLOBALS['cybermaps_mock_post_meta'] = array(
			10 => array(
				'_cybermaps_ai_meta' => array( 'score' => 1 ),
				'_cybermaps_media_audit' => array(
					array( 'type' => 'image', 'url' => 'https://example.com/image.jpg' ),
				),
				'_cybermaps_media_audit_generation' => 6,
				'_cybermaps_media_audit_mode' => 'advanced',
				'unrelated_meta'     => 'keep',
			),
		);
		$GLOBALS['cybermaps_mock_user_meta'] = array(
			2 => array(
				'cybermaps_dismiss_logs_well_known_notice' => '1',
				'unrelated_user_meta' => 'keep',
			),
		);
		$GLOBALS['cybermaps_mock_transients'] = array(
			'cybermaps_health_stats' => array( 'score' => 100 ),
			'cybermaps_v7_dynamic_sitemap' => '<xml/>',
			'unrelated_transient'    => 'keep',
		);
		$GLOBALS['cybermaps_mock_scheduled'] = array(
			'cybermaps_cleanup_logs_event'                   => 123,
			'cybermaps_cleanup_runtime_counters_event'       => 124,
			'cybermaps_continue_logs_cleanup_event'          => 143,
			'cybermaps_refresh_time_sensitive_static_files' => 173,
		);

		Uninstaller::run();

		$this->assertArrayNotHasKey( 'cybermaps_settings', $GLOBALS['cybermaps_mock_options'] );
		$this->assertArrayNotHasKey( 'cybermaps_identity_data', $GLOBALS['cybermaps_mock_options'] );
		$this->assertArrayNotHasKey( 'cybermaps_runtime_counter_table_ready', $GLOBALS['cybermaps_mock_options'] );
		$this->assertArrayNotHasKey( 'cybermaps_edge_cache_delivery_status', $GLOBALS['cybermaps_mock_options'] );
		$this->assertArrayNotHasKey( 'cybermaps_edge_cache_pending_static', $GLOBALS['cybermaps_mock_options'] );
		$this->assertArrayNotHasKey( 'cybermaps_indexnow_queue_schema', $GLOBALS['cybermaps_mock_options'] );
		$this->assertArrayNotHasKey( 'cybermaps_static_generation', $GLOBALS['cybermaps_mock_options'] );
		$this->assertArrayNotHasKey( 'cybermaps_static_ownership_revision', $GLOBALS['cybermaps_mock_options'] );
		$this->assertArrayNotHasKey( 'cybermaps_static_sync_epoch', $GLOBALS['cybermaps_mock_options'] );
		$this->assertArrayNotHasKey( 'cybermaps_static_hashes_00', $GLOBALS['cybermaps_mock_options'] );
		$this->assertArrayNotHasKey( 'cybermaps_media_audit_generation', $GLOBALS['cybermaps_mock_options'] );
		$this->assertArrayNotHasKey( 'cybermaps_llms_yaml_cache', $GLOBALS['cybermaps_mock_options'] );
		$this->assertArrayNotHasKey( 'cybermaps_llms_full_yaml_cache', $GLOBALS['cybermaps_mock_options'] );
		$this->assertArrayNotHasKey( 'cybermaps_last_time_sensitive_static_refresh', $GLOBALS['cybermaps_mock_options'] );
		$this->assertArrayNotHasKey( '_cybermaps_ai_meta', $GLOBALS['cybermaps_mock_post_meta'][10] );
		$this->assertArrayNotHasKey( '_cybermaps_media_audit', $GLOBALS['cybermaps_mock_post_meta'][10] );
		$this->assertArrayNotHasKey( '_cybermaps_media_audit_generation', $GLOBALS['cybermaps_mock_post_meta'][10] );
		$this->assertArrayNotHasKey( '_cybermaps_media_audit_mode', $GLOBALS['cybermaps_mock_post_meta'][10] );
		$this->assertSame( 'keep', $GLOBALS['cybermaps_mock_post_meta'][10]['unrelated_meta'] );
		$this->assertArrayNotHasKey(
			'cybermaps_dismiss_logs_well_known_notice',
			$GLOBALS['cybermaps_mock_user_meta'][2]
		);
		$this->assertSame( 'keep', $GLOBALS['cybermaps_mock_user_meta'][2]['unrelated_user_meta'] );
		$this->assertArrayNotHasKey( 'cybermaps_health_stats', $GLOBALS['cybermaps_mock_transients'] );
		$this->assertArrayNotHasKey( 'cybermaps_v7_dynamic_sitemap', $GLOBALS['cybermaps_mock_transients'] );
		$this->assertSame( 'keep', $GLOBALS['cybermaps_mock_transients']['unrelated_transient'] );
		$this->assertArrayNotHasKey( 'cybermaps_cleanup_logs_event', $GLOBALS['cybermaps_mock_scheduled'] );
		$this->assertArrayNotHasKey( 'cybermaps_cleanup_runtime_counters_event', $GLOBALS['cybermaps_mock_scheduled'] );
		$this->assertArrayNotHasKey( 'cybermaps_continue_logs_cleanup_event', $GLOBALS['cybermaps_mock_scheduled'] );
		$this->assertArrayNotHasKey( 'cybermaps_refresh_time_sensitive_static_files', $GLOBALS['cybermaps_mock_scheduled'] );
		$this->assertTrue( $this->queries_contain( 'DROP TABLE IF EXISTS wp_cybermaps_logs' ) );
		$this->assertTrue( $this->queries_contain( 'DROP TABLE IF EXISTS wp_cybermaps_runtime_counters' ) );
		$this->assertTrue( $this->queries_contain( 'DROP TABLE IF EXISTS wp_cybermaps_indexnow_queue' ) );
		$this->assertTrue( $this->queries_contain( 'DROP TABLE IF EXISTS wp_cybermaps_translations' ) );
	}

	public function test_partial_multisite_opt_in_preserves_shared_data(): void {
		$GLOBALS['cybermaps_mock_is_multisite'] = true;
		$GLOBALS['cybermaps_mock_site_ids']     = array( 1, 2 );
		$GLOBALS['cybermaps_mock_options_by_blog'] = array(
			1 => array(
				'cybermaps_settings' => array( 'delete_data_on_uninstall' => '1' ),
				'cybermaps_static_hashes' => array(),
			),
			2 => array(
				'cybermaps_settings' => array( 'delete_data_on_uninstall' => '0' ),
				'cybermaps_core_transient_inventory' => array(
					'cybermaps_v7_remaining_site' => array(
						'family'  => 'sitemap',
						'expires' => time() + HOUR_IN_SECONDS,
					),
				),
			),
		);
		$GLOBALS['cybermaps_mock_transients']['cybermaps_v7_remaining_site'] = '<xml>stale</xml>';
		$GLOBALS['cybermaps_mock_user_meta'] = array(
			2 => array( 'cybermaps_dismiss_logs_well_known_notice' => '1' ),
		);

		Uninstaller::run();

		$this->assertArrayHasKey(
			'cybermaps_dismiss_logs_well_known_notice',
			$GLOBALS['cybermaps_mock_user_meta'][2]
		);
		$this->assertSame( array(), $GLOBALS['cybermaps_mock_deleted_network_options'] );
		$this->assertFalse( $this->queries_contain( 'DROP TABLE IF EXISTS wp_cybermaps_translations' ) );
		$this->assertTrue( $this->queries_contain( 'DELETE FROM wp_cybermaps_translations WHERE site_id = 1' ) );
		$this->assertFalse( get_transient( 'cybermaps_v7_remaining_site' ) );
	}

	public function test_all_multisite_sites_must_opt_in_before_shared_cleanup(): void {
		$GLOBALS['cybermaps_mock_is_multisite'] = true;
		$GLOBALS['cybermaps_mock_site_ids']     = array( 1, 2 );
		$GLOBALS['cybermaps_mock_network_ids']  = array( 1, 4 );
		$GLOBALS['cybermaps_mock_options_by_blog'] = array(
			1 => array(
				'cybermaps_settings' => array( 'delete_data_on_uninstall' => '1' ),
				'cybermaps_static_hashes' => array(),
			),
			2 => array(
				'cybermaps_settings' => array( 'delete_data_on_uninstall' => '1' ),
			),
		);
		$GLOBALS['cybermaps_mock_user_meta'] = array(
			2 => array( 'cybermaps_dismiss_logs_well_known_notice' => '1' ),
		);

		Uninstaller::run();

		$this->assertArrayNotHasKey(
			'cybermaps_dismiss_logs_well_known_notice',
			$GLOBALS['cybermaps_mock_user_meta'][2]
		);
		$this->assertSame(
			array(
				array( 1, 'cybermaps_network_settings' ),
				array( 1, 'cybermaps_translation_schema_upgrade_state' ),
				array( 1, 'cybermaps_translation_schema_version' ),
				array( 4, 'cybermaps_network_settings' ),
				array( 4, 'cybermaps_translation_schema_upgrade_state' ),
				array( 4, 'cybermaps_translation_schema_version' ),
			),
			$GLOBALS['cybermaps_mock_deleted_network_options']
		);
		$this->assertTrue( $this->queries_contain( 'DROP TABLE IF EXISTS wp_cybermaps_translations' ) );
	}

	public function test_busy_static_operation_does_not_block_opted_in_data_cleanup(): void {
		$GLOBALS['cybermaps_mock_options'] = array(
			'cybermaps_settings' => array( 'delete_data_on_uninstall' => '1' ),
			'cybermaps_identity_data' => array( 'name' => 'Delete me' ),
			'cybermaps_static_hashes' => array( 'sitemap.xml' => str_repeat( 'a', 32 ) ),
			'cybermaps_static_operation_lock' => array(
				'token' => 'another-request',
				'time'  => time(),
			),
		);

		Uninstaller::run();

		$this->assertArrayNotHasKey( 'cybermaps_settings', $GLOBALS['cybermaps_mock_options'] );
		$this->assertArrayNotHasKey( 'cybermaps_identity_data', $GLOBALS['cybermaps_mock_options'] );
		$this->assertArrayNotHasKey( 'cybermaps_static_hashes', $GLOBALS['cybermaps_mock_options'] );
		$this->assertArrayNotHasKey( 'cybermaps_static_operation_lock', $GLOBALS['cybermaps_mock_options'] );
		$this->assertTrue( $this->queries_contain( 'DROP TABLE IF EXISTS wp_cybermaps_logs' ) );
		$this->assertTrue( $this->queries_contain( 'DROP TABLE IF EXISTS wp_cybermaps_translations' ) );
	}

	public function test_static_filesystem_error_does_not_block_owned_data_cleanup(): void {
		$GLOBALS['cybermaps_mock_options'] = array(
			'cybermaps_settings' => array( 'delete_data_on_uninstall' => '1' ),
			'cybermaps_identity_data' => array( 'name' => 'Delete me' ),
		);
		$method = new \ReflectionMethod( Uninstaller::class, 'cleanup_owned_site_data' );

		$cleaned = $method->invoke(
			null,
			array(
				'success'  => false,
				'status'   => 'error',
				'retained' => array( 'sitemap.xml' => 'delete_failed' ),
			)
		);

		$this->assertTrue( $cleaned );
		$this->assertArrayNotHasKey( 'cybermaps_settings', $GLOBALS['cybermaps_mock_options'] );
		$this->assertArrayNotHasKey( 'cybermaps_identity_data', $GLOBALS['cybermaps_mock_options'] );
		$this->assertTrue( $this->queries_contain( 'DROP TABLE IF EXISTS wp_cybermaps_logs' ) );
	}

	public function test_failed_local_table_drop_prevents_shared_cleanup(): void {
		$GLOBALS['cybermaps_mock_options'] = array(
			'cybermaps_settings' => array( 'delete_data_on_uninstall' => '1' ),
		);
		$GLOBALS['cybermaps_mock_user_meta'] = array(
			2 => array( 'cybermaps_dismiss_logs_well_known_notice' => '1' ),
		);
		$GLOBALS['wpdb']->fail_drop_table = 'wp_cybermaps_logs';

		Uninstaller::run();

		$this->assertContains( 'wp_cybermaps_logs', $GLOBALS['wpdb']->existing_tables );
		$this->assertFalse( $this->queries_contain( 'DROP TABLE IF EXISTS wp_cybermaps_translations' ) );
		$this->assertArrayHasKey(
			'cybermaps_dismiss_logs_well_known_notice',
			$GLOBALS['cybermaps_mock_user_meta'][2]
		);
	}

	public function test_failed_option_removal_prevents_shared_cleanup(): void {
		$GLOBALS['cybermaps_mock_options'] = array(
			'cybermaps_settings' => array( 'delete_data_on_uninstall' => '1' ),
		);
		$restore_settings = true;
		$GLOBALS['cybermaps_mock_get_option_observer'] = static function ( string $option ) use ( &$restore_settings ): void {
			if (
				$restore_settings
				&& 'cybermaps_settings' === $option
				&& ! array_key_exists( $option, $GLOBALS['cybermaps_mock_options'] )
			) {
				$GLOBALS['cybermaps_mock_options'][ $option ] = array(
					'delete_data_on_uninstall' => '1',
				);
				$restore_settings = false;
			}
		};

		Uninstaller::run();

		$this->assertArrayHasKey( 'cybermaps_settings', $GLOBALS['cybermaps_mock_options'] );
		$this->assertFalse( $this->queries_contain( 'DROP TABLE IF EXISTS wp_cybermaps_translations' ) );
	}

	public function test_retryable_static_failures_are_classified_for_diagnostics(): void {
		$method = new \ReflectionMethod( Uninstaller::class, 'static_purge_allows_data_cleanup' );

		$this->assertFalse(
			$method->invoke(
				null,
				array(
					'success'  => true,
					'status'   => 'partial',
					'retained' => array( 'sitemap.xml' => 'delete_failed' ),
				)
			)
		);
		$this->assertFalse(
			$method->invoke(
				null,
				array(
					'success'  => false,
					'status'   => 'error',
					'retained' => array(),
				)
			)
		);
	}

	public function test_uninstall_data_cleanup_accepts_intentional_static_conflicts(): void {
		$method = new \ReflectionMethod( Uninstaller::class, 'static_purge_allows_data_cleanup' );

		$this->assertTrue(
			$method->invoke(
				null,
				array(
					'success'  => true,
					'status'   => 'partial',
					'retained' => array(
						'sitemap.xml' => 'content_changed',
						'bad-key'     => 'invalid_inventory_hash',
					),
				)
			)
		);
	}

	private function queries_contain( string $needle ): bool {
		foreach ( $GLOBALS['wpdb']->queries as $query ) {
			if ( false !== strpos( $query, $needle ) ) {
				return true;
			}
		}

		return false;
	}
}

/**
 * Minimal wpdb surface used by uninstall.
 */
final class UninstallerWpdbStub {
	public string $prefix = 'wp_';
	public string $base_prefix = 'wp_';
	public string $options = 'wp_options';
	public string $postmeta = 'wp_postmeta';
	public string $last_error = '';
	public string $fail_drop_table = '';

	/** @var string[] */
	public array $existing_tables = array(
		'wp_cybermaps_logs',
		'wp_cybermaps_runtime_counters',
		'wp_cybermaps_indexnow_queue',
		'wp_cybermaps_audit_findings',
		'wp_cybermaps_audit_resources',
		'wp_cybermaps_audit_runs',
		'wp_cybermaps_translations',
	);

	/**
	 * @var string[]
	 */
	public array $queries = array();

	public function query( string $query ): int|false {
		$this->queries[] = $query;
		if ( str_starts_with( $query, 'DROP TABLE IF EXISTS ' ) ) {
			$table = trim( substr( $query, strlen( 'DROP TABLE IF EXISTS ' ) ), "`' " );
			if ( $table === $this->fail_drop_table ) {
				$this->last_error = 'Drop failed';
				return false;
			}
			$this->existing_tables = array_values(
				array_filter(
					$this->existing_tables,
					static fn( string $existing ): bool => $existing !== $table
				)
			);
		}
		return 1;
	}

	public function prepare( string $query, ...$args ): string {
		foreach ( $args as $arg ) {
			if ( false !== strpos( $query, '%i' ) ) {
				$query = (string) preg_replace( '/%i/', (string) $arg, $query, 1 );
				continue;
			}

			$replacement = is_int( $arg ) ? (string) $arg : "'" . (string) $arg . "'";
			$query       = (string) preg_replace( '/%[ds]/', $replacement, $query, 1 );
		}

		return $query;
	}

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	/**
	 * @return array<int,array<int|string,mixed>>
	 */
	public function get_results( string $query, string $output ): array {
		unset( $output );
		$this->queries[] = $query;
		$this->last_error = '';
		if ( str_starts_with( $query, 'SHOW TABLES LIKE ' ) ) {
			$table = stripslashes( trim( substr( $query, strlen( 'SHOW TABLES LIKE ' ) ), "'" ) );
			return in_array( $table, $this->existing_tables, true )
				? array( array( $table ) )
				: array();
		}
		if ( str_contains( $query, 'SELECT meta_key FROM wp_postmeta' ) ) {
			foreach ( (array) $GLOBALS['cybermaps_mock_post_meta'] as $metadata ) {
				foreach ( array_keys( (array) $metadata ) as $meta_key ) {
					if ( str_starts_with( (string) $meta_key, '_cybermaps_' ) ) {
						return array( array( 'meta_key' => $meta_key ) );
					}
				}
			}
		}

		return array();
	}
}
