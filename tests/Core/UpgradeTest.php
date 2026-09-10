<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Core;

use Cybermaps\Core\OptionLeaseLock;
use Cybermaps\Core\Upgrade;
use Cybermaps\Discovery\StaticOwnershipStore;
use Cybermaps\MCP\OAuth\WpdbOAuthRepository;

final class UpgradeTest extends \WP_UnitTestCase {
	private $previous_wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->previous_wpdb               = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']                   = new UpgradeWpdbStub(
			array(
				array(
					'meta_id'    => 1,
					'post_id'    => 10,
					'meta_key'   => '_cybermaps_exclude_search',
					'meta_value' => '1',
				),
				array(
					'meta_id'    => 2,
					'post_id'    => 20,
					'meta_key'   => '_cybermaps_exclude_search',
					'meta_value' => '1',
				),
				array(
					'meta_id'    => 3,
					'post_id'    => 20,
					'meta_key'   => '_cybermaps_exclude_sitemap',
					'meta_value' => '0',
				),
			)
		);
		$GLOBALS['cybermaps_mock_options'] = array(
			'cybermaps_data_version'         => '5.1.1',
			'cybermaps_settings'             => array(
				'static_engine_mode'     => 'off',
				'rss_sitemap_post_types' => 'post, Product, post',
				'llms_exclude_ids'       => '12, 45',
				'ai_sitemap_exclude_ids' => '45, 102',
			),
			'cybermaps_discovery_center'     => wp_json_encode(
				array(
					'archetype'    => 'blog',
					'overrides'    => array(
						'post' => 0.8,
						'tag'  => 0.4,
					),
					'type_intents' => array(
						'post' => 'navigational',
						'tag'  => 'commercial',
					),
					'retired'      => 'value',
				)
			),
			'cybermaps_llms_yaml_cache'      => 'legacy summary',
			'cybermaps_llms_full_yaml_cache' => 'legacy full',
		);
		$GLOBALS['cybermaps_mock_site_options']['cybermaps_translation_schema_version'] = '3';
		$GLOBALS['cybermaps_mock_scheduled']                 = array(
			'cybermaps_weekly_health_snapshot' => 100,
			'unrelated_event'                  => 300,
		);
		$GLOBALS['cybermaps_mock_transients']                = array(
			'cybermaps_llms_yaml_cache' => 'legacy transient',
		);
		$GLOBALS['cybermaps_mock_rewrite_rules']             = array();
		$GLOBALS['cybermaps_mock_post_types']                = array( 'post' );
		$GLOBALS['cybermaps_mock_post_type_objects']['post'] = (object) array(
			'name'   => 'post',
			'public' => true,
		);
		unset( $GLOBALS['cybermaps_mock_update_option_behavior'] );
		unset( $GLOBALS['cybermaps_mock_add_option_behavior'] );
		unset( $GLOBALS['cybermaps_mock_get_option_observer'] );
		$GLOBALS['cybermaps_mock_dbdelta_queries'] = array();
		$GLOBALS['cybermaps_mock_dbdelta_callback'] = static function ( string $queries ): array {
			if ( preg_match( '/CREATE TABLE\s+([^\s(]+)/i', $queries, $matches ) ) {
				$GLOBALS['wpdb']->existing_tables[] = $matches[1];
				$GLOBALS['wpdb']->existing_tables   = array_values( array_unique( $GLOBALS['wpdb']->existing_tables ) );
			}

			return array( $queries );
		};
		unset( $GLOBALS['cybermaps_mock_option_autoload_values'] );
		unset( $GLOBALS['cybermaps_test_database_session_lock_use_sql'] );
		( new \ReflectionProperty( Upgrade::class, 'upgrade_lock' ) )->setValue( null, null );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['cybermaps_mock_update_option_behavior'] );
		unset( $GLOBALS['cybermaps_mock_add_option_behavior'] );
		unset( $GLOBALS['cybermaps_mock_get_option_observer'] );
		unset( $GLOBALS['cybermaps_mock_dbdelta_callback'] );
		unset( $GLOBALS['cybermaps_test_database_session_lock_use_sql'] );
		( new \ReflectionProperty( Upgrade::class, 'upgrade_lock' ) )->setValue( null, null );
		$GLOBALS['wpdb'] = $this->previous_wpdb;
		parent::tearDown();
	}

	public function test_upgrade_migrates_canonical_meta_and_removes_retired_state(): void {
		Upgrade::run();

		$this->assertSame( '6.6.0', get_option( 'cybermaps_data_version' ) );
		$this->assertFalse( get_option( 'cybermaps_llms_yaml_cache', false ) );
		$this->assertFalse( get_option( 'cybermaps_llms_full_yaml_cache', false ) );
		$this->assertFalse( get_transient( 'cybermaps_llms_yaml_cache' ) );
		$this->assertArrayNotHasKey( 'cybermaps_weekly_health_snapshot', $GLOBALS['cybermaps_mock_scheduled'] );
		$this->assertArrayHasKey( 'unrelated_event', $GLOBALS['cybermaps_mock_scheduled'] );
		$this->assertArrayHasKey( 'cybermaps_cleanup_logs_event', $GLOBALS['cybermaps_mock_scheduled'] );
		$this->assertArrayHasKey( \Cybermaps\Core\Lifecycle::RUNTIME_COUNTER_CLEANUP_HOOK, $GLOBALS['cybermaps_mock_scheduled'] );
		$this->assertSame( '1', get_option( \Cybermaps\Core\RuntimeCounterStore::READY_OPTION, '' ) );
		$settings = get_option( 'cybermaps_settings', array() );
		$this->assertSame( array( 'post', 'product' ), $settings['rss_sitemap_types'] );
		$this->assertArrayNotHasKey( 'rss_sitemap_post_types', $settings );
		$this->assertSame( '12, 45, 102', $settings['llms_exclude_ids'] );
		$this->assertArrayNotHasKey( 'ai_sitemap_exclude_ids', $settings );
		$this->assertSame(
			array(
				'archetype'    => 'blog',
				'overrides'    => array(
					'post_type:post' => 0.8,
					'post_tag'       => 0.4,
				),
				'type_intents' => array(
					'post_type:post' => 'informational',
					'post_tag'       => 'transactional',
				),
				'disabled'     => array(),
			),
			json_decode( (string) get_option( 'cybermaps_discovery_center', '' ), true )
		);

		$rows = $GLOBALS['wpdb']->postmeta_rows;
		$this->assertSame(
			array(),
			array_values(
				array_filter(
					$rows,
					static fn( array $row ): bool => '_cybermaps_exclude_search' === $row['meta_key']
				)
			)
		);
		$canonical = array_values(
			array_filter(
				$rows,
				static fn( array $row ): bool => '_cybermaps_exclude_sitemap' === $row['meta_key']
			)
		);
		$this->assertCount( 2, $canonical );
		$this->assertSame( '1', $this->canonical_value( $canonical, 10 ) );
		$this->assertSame( '0', $this->canonical_value( $canonical, 20 ) );
		$this->assertNotEmpty( $GLOBALS['cybermaps_mock_rewrite_rules'] );
		$this->assertSame(
			'^sitemap\\.xml$',
			$GLOBALS['cybermaps_mock_rewrite_rules'][0]['regex'] ?? ''
		);
		$this->assertSame(
			array(
				\Cybermaps\Core\RuntimeCounterStore::READY_OPTION => false,
				'cybermaps_settings'         => false,
				'cybermaps_discovery_center' => false,
				'cybermaps_robots_manager'   => false,
				'cybermaps_identity_data'    => false,
				\Cybermaps\Discovery\IndexNowQueue::OPTION => false,
				\Cybermaps\Discovery\IndexNowQueueSchema::VERSION_OPTION => false,
				\Cybermaps\Discovery\IndexNowQueueRepository::LAST_RESULT_OPTION => false,
				'cybermaps_edge_cache_delivery_status' => false,
				'cybermaps_edge_cache_pending_static'  => false,
			),
			$GLOBALS['cybermaps_mock_option_autoload_values']
		);
		$this->assertStringContainsString( 'cybermaps_runtime_counters', implode( "\n", $GLOBALS['cybermaps_mock_dbdelta_queries'] ) );
		$this->assertStringContainsString( 'cybermaps_indexnow_queue', implode( "\n", $GLOBALS['cybermaps_mock_dbdelta_queries'] ) );
		$this->assertStringContainsString( 'cybermaps_mcp_oauth_devices', implode( "\n", $GLOBALS['cybermaps_mock_dbdelta_queries'] ) );
	}

	public function test_upgrade_from_5_0_applies_every_cumulative_retired_setting(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_data_version'] = '5.0.3';
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']    += array(
			'enable_static_engine'        => '1',
			'enterprise_features_enabled' => '1',
			'show_cybermaps_attribution'  => '1',
			'manual_intent_conversion'    => 'transactional',
			'llms_targeted_bots_manual'   => 'GPTBot',
		);

		Upgrade::run();

		$this->assertSame( '6.6.0', get_option( 'cybermaps_data_version' ) );
		$settings = get_option( 'cybermaps_settings', array() );
		foreach (
			array(
				'enable_static_engine',
				'enterprise_features_enabled',
				'show_cybermaps_attribution',
				'manual_intent_conversion',
				'llms_targeted_bots_manual',
			) as $retired_key
		) {
			$this->assertArrayNotHasKey( $retired_key, $settings );
		}
	}

	public function test_missing_data_version_runs_the_full_upgrade(): void {
		unset( $GLOBALS['cybermaps_mock_options']['cybermaps_data_version'] );
		unset( $GLOBALS['cybermaps_mock_options']['cybermaps_settings']['static_engine_mode'] );

		Upgrade::run();

		$this->assertSame( '6.6.0', get_option( 'cybermaps_data_version' ) );
		$this->assertSame(
			'well_known',
			get_option( 'cybermaps_settings', array() )['static_engine_mode'] ?? ''
		);
		$this->assertSame(
			\Cybermaps\Audit\AuditRunRepository::SCHEMA_VERSION,
			get_option( 'cybermaps_audit_schema_version', '' )
		);
		$this->assertSame( WpdbOAuthRepository::SCHEMA_VERSION, get_option( WpdbOAuthRepository::SCHEMA_OPTION, '' ) );
	}

	public function test_current_version_reprovisions_runtime_tables_and_cleanup_schedule(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_data_version'] = '6.6.0';
		$GLOBALS['wpdb']->existing_tables                           = array();
		unset( $GLOBALS['cybermaps_mock_options'][ \Cybermaps\Core\RuntimeCounterStore::READY_OPTION ] );

		Upgrade::run();

		$this->assertSame( '6.6.0', get_option( 'cybermaps_data_version' ) );
		$this->assertSame( '1', get_option( \Cybermaps\Core\RuntimeCounterStore::READY_OPTION, '' ) );
		$this->assertArrayHasKey( \Cybermaps\Core\Lifecycle::RUNTIME_COUNTER_CLEANUP_HOOK, $GLOBALS['cybermaps_mock_scheduled'] );
		$this->assertStringContainsString( 'cybermaps_runtime_counters', implode( "\n", $GLOBALS['cybermaps_mock_dbdelta_queries'] ) );
		$this->assertStringContainsString( 'cybermaps_indexnow_queue', implode( "\n", $GLOBALS['cybermaps_mock_dbdelta_queries'] ) );
	}

	public function test_65_upgrade_creates_the_oauth_device_schema_once(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_data_version'] = '6.5.0';

		Upgrade::run();

		$this->assertSame( '6.6.0', get_option( 'cybermaps_data_version' ) );
		$this->assertSame( WpdbOAuthRepository::SCHEMA_VERSION, get_option( WpdbOAuthRepository::SCHEMA_OPTION, '' ) );
		$oauth_queries = array_values(
			array_filter(
				$GLOBALS['cybermaps_mock_dbdelta_queries'],
				static fn( string $query ): bool => str_contains( $query, 'cybermaps_mcp_oauth_' )
			)
		);
		$this->assertCount( 5, $oauth_queries );
		$this->assertStringContainsString( 'cybermaps_mcp_oauth_devices', implode( "\n", $oauth_queries ) );

		Upgrade::run();
		$this->assertSame( $oauth_queries, array_values( array_filter( $GLOBALS['cybermaps_mock_dbdelta_queries'], static fn( string $query ): bool => str_contains( $query, 'cybermaps_mcp_oauth_' ) ) ) );
	}

	public function test_version_6_0_install_runs_the_6_1_static_ownership_migration(): void {
		$path = 'legacy-upgrade-owned.txt';
		$hash = \str_repeat( 'a', 32 );
		$GLOBALS['cybermaps_mock_options']['cybermaps_data_version'] = '6.0.0';
		update_option( StaticOwnershipStore::LEGACY_OPTION, array( $path => $hash ), false );
		update_option( 'cybermaps_static_sync_epoch', 7, false );

		Upgrade::run();

		$this->assertSame( '6.6.0', get_option( 'cybermaps_data_version' ) );
		$this->assertSame( StaticOwnershipStore::SCHEMA_VERSION, StaticOwnershipStore::current_schema() );
		$this->assertSame( $hash, ( new StaticOwnershipStore() )->get_hash( $path ) );
	}

	public function test_unsupported_wpdb_subclass_defers_only_static_ownership_and_retries_later(): void {
		$path            = 'deferred-static-owned.txt';
		$hash            = \str_repeat( 'b', 32 );
		$GLOBALS['wpdb'] = new UpgradeDropInWpdbStub( $GLOBALS['wpdb']->postmeta_rows );
		$GLOBALS['cybermaps_test_database_session_lock_use_sql']     = 'validate-production';
		$GLOBALS['cybermaps_mock_options']['cybermaps_data_version'] = '6.0.0';
		update_option( StaticOwnershipStore::LEGACY_OPTION, array( $path => $hash ), false );
		update_option( 'cybermaps_static_sync_epoch', 8, false );

		Upgrade::run();

		$this->assertSame( '6.6.0', get_option( 'cybermaps_data_version' ) );
		$this->assertSame( 0, StaticOwnershipStore::current_schema() );
		$this->assertSame( array( $path => $hash ), get_option( StaticOwnershipStore::LEGACY_OPTION ) );
		$this->assertSame(
			\Cybermaps\Audit\AuditRunRepository::SCHEMA_VERSION,
			get_option( 'cybermaps_audit_schema_version', '' )
		);
		$this->assertNotEmpty( $GLOBALS['cybermaps_mock_rewrite_rules'] );
		$status = Upgrade::get_status();
		$this->assertSame( 1, $status['static_pending'] );
		$this->assertSame( 1, $status['static_attempts'] );
		$this->assertGreaterThan( time(), $status['static_next_retry'] );
		$this->assertStringContainsString( 'wpdb', (string) $status['static_last_error'] );
		$this->assertNotFalse( wp_next_scheduled( Upgrade::RETRY_HOOK ) );
		$postmeta_queries = $GLOBALS['wpdb']->postmeta_query_count;

		$status['static_next_retry'] = 0;
		update_option( 'cybermaps_upgrade_state', $status, false );
		unset( $GLOBALS['cybermaps_test_database_session_lock_use_sql'] );
		Upgrade::run();

		$this->assertSame( StaticOwnershipStore::SCHEMA_VERSION, StaticOwnershipStore::current_schema() );
		$this->assertSame( $hash, ( new StaticOwnershipStore() )->get_hash( $path ) );
		$this->assertSame( 0, Upgrade::get_status()['static_pending'] );
		$this->assertFalse( get_option( 'cybermaps_upgrade_state', false ) );
		$this->assertSame( $postmeta_queries, $GLOBALS['wpdb']->postmeta_query_count );
	}

	public function test_failed_settings_write_does_not_stamp_the_upgrade_and_can_retry(): void {
		$GLOBALS['cybermaps_mock_update_option_behavior'] = static function ( string $option, $value, string $stage ): void {
			unset( $value );
			if ( 'cybermaps_settings' === $option && 'after' === $stage ) {
				$GLOBALS['cybermaps_mock_options'][ $option ] = array(
					'static_engine_mode' => 'invalid',
				);
			}
		};

		Upgrade::run();

		$this->assertSame( '5.1.1', get_option( 'cybermaps_data_version' ) );
		$this->assertSame( '', get_option( 'cybermaps_audit_schema_version', '' ) );
		$this->assertGreaterThan( time(), Upgrade::get_status()['next_retry'] );
		$this->assertNotFalse( wp_next_scheduled( Upgrade::RETRY_HOOK ) );

		unset( $GLOBALS['cybermaps_mock_update_option_behavior'] );
		Upgrade::run();
		$this->assertSame( '5.1.1', get_option( 'cybermaps_data_version' ) );
		$this->make_retry_due();
		Upgrade::run();

		$this->assertSame( '6.6.0', get_option( 'cybermaps_data_version' ) );
		$this->assertSame(
			'well_known',
			get_option( 'cybermaps_settings', array() )['static_engine_mode'] ?? ''
		);
	}

	public function test_failed_discovery_write_does_not_stamp_the_upgrade_and_can_retry(): void {
		$legacy = get_option( 'cybermaps_discovery_center', '' );
		$GLOBALS['cybermaps_mock_update_option_behavior'] = static function ( string $option, $value, string $stage ) use ( $legacy ): void {
			unset( $value );
			if ( 'cybermaps_discovery_center' === $option && 'after' === $stage ) {
				$GLOBALS['cybermaps_mock_options'][ $option ] = $legacy;
			}
		};

		Upgrade::run();

		$this->assertSame( '5.1.1', get_option( 'cybermaps_data_version' ) );
		$this->assertSame( $legacy, get_option( 'cybermaps_discovery_center', '' ) );
		$this->assertSame( '', get_option( 'cybermaps_audit_schema_version', '' ) );

		unset( $GLOBALS['cybermaps_mock_update_option_behavior'] );
		$this->make_retry_due();
		Upgrade::run();

		$this->assertSame( '6.6.0', get_option( 'cybermaps_data_version' ) );
		$this->assertNotSame( $legacy, get_option( 'cybermaps_discovery_center', '' ) );
	}

	public function test_failed_audit_schema_does_not_stamp_the_upgrade_and_can_retry(): void {
		$statements                                 = 0;
		$GLOBALS['cybermaps_mock_dbdelta_callback'] = static function ( string $queries ) use ( &$statements ): array {
			++$statements;
			if ( preg_match( '/CREATE TABLE\s+([^\s(]+)/i', $queries, $matches ) ) {
				$table = $matches[1];
				if ( str_contains( $table, 'cybermaps_audit_' ) ) {
					$GLOBALS['wpdb']->last_error = 'Audit table creation failed';
					return array( $queries );
				}
				$GLOBALS['wpdb']->existing_tables[] = $table;
				$GLOBALS['wpdb']->existing_tables   = array_values( array_unique( $GLOBALS['wpdb']->existing_tables ) );
			}
			$GLOBALS['wpdb']->last_error = '';
			return array( $queries );
		};

		Upgrade::run();

		$this->assertSame( 5, $statements );
		$this->assertSame( '', get_option( 'cybermaps_audit_schema_version', '' ) );
		$this->assertSame( '5.1.1', get_option( 'cybermaps_data_version' ) );

		unset( $GLOBALS['cybermaps_mock_dbdelta_callback'] );
		$this->make_retry_due();
		Upgrade::run();

		$this->assertSame(
			\Cybermaps\Audit\AuditRunRepository::SCHEMA_VERSION,
			get_option( 'cybermaps_audit_schema_version', '' )
		);
		$this->assertSame( '6.6.0', get_option( 'cybermaps_data_version' ) );
	}

	public function test_failed_oauth_schema_does_not_stamp_the_upgrade_and_can_retry(): void {
		$GLOBALS['cybermaps_mock_dbdelta_callback'] = static function ( string $queries ): array {
			if ( preg_match( '/CREATE TABLE\s+([^\s(]+)/i', $queries, $matches ) ) {
				$table = $matches[1];
				$GLOBALS['wpdb']->existing_tables[] = $table;
				$GLOBALS['wpdb']->existing_tables   = array_values( array_unique( $GLOBALS['wpdb']->existing_tables ) );
				$GLOBALS['wpdb']->last_error        = str_contains( $table, 'cybermaps_mcp_oauth_devices' )
					? 'OAuth device table creation failed'
					: '';
			}
			return array( $queries );
		};

		Upgrade::run();

		$this->assertSame( '5.1.1', get_option( 'cybermaps_data_version' ) );
		$this->assertSame( '', get_option( WpdbOAuthRepository::SCHEMA_OPTION, '' ) );
		$this->assertGreaterThan( time(), Upgrade::get_status()['next_retry'] );

		unset( $GLOBALS['cybermaps_mock_dbdelta_callback'] );
		$GLOBALS['wpdb']->last_error = '';
		$this->make_retry_due();
		Upgrade::run();

		$this->assertSame( '6.6.0', get_option( 'cybermaps_data_version' ) );
		$this->assertSame( WpdbOAuthRepository::SCHEMA_VERSION, get_option( WpdbOAuthRepository::SCHEMA_OPTION, '' ) );
	}

	public function test_failed_meta_migration_does_not_stamp_the_upgrade_and_can_retry(): void {
		$GLOBALS['wpdb']->fail_next_insert = true;

		Upgrade::run();

		$this->assertSame( '5.1.1', get_option( 'cybermaps_data_version' ) );
		$this->assertArrayHasKey(
			'rss_sitemap_post_types',
			get_option( 'cybermaps_settings', array() )
		);

		$this->make_retry_due();
		Upgrade::run();

		$this->assertSame( '6.6.0', get_option( 'cybermaps_data_version' ) );
		$this->assertArrayNotHasKey(
			'rss_sitemap_post_types',
			get_option( 'cybermaps_settings', array() )
		);
	}

	public function test_fresh_install_stamp_skips_historical_migrations(): void {
		$GLOBALS['cybermaps_mock_options'] = array();
		$GLOBALS['wpdb']->fail_next_insert = true;

		Upgrade::stamp_fresh_install();
		Upgrade::run();

		$this->assertSame( '6.6.0', get_option( 'cybermaps_data_version' ) );
		$this->assertTrue( $GLOBALS['wpdb']->fail_next_insert );
		$this->assertFalse( get_option( 'cybermaps_settings', false ) );
	}

	public function test_fresh_install_stamp_never_skips_existing_configuration(): void {
		unset( $GLOBALS['cybermaps_mock_options']['cybermaps_data_version'] );

		Upgrade::stamp_fresh_install();

		$this->assertFalse( get_option( 'cybermaps_data_version', false ) );
	}

	public function test_fresh_install_stamp_preserves_an_existing_upgrade_checkpoint_and_retry(): void {
		$GLOBALS['cybermaps_mock_options'] = array();
		$checkpoint                      = Upgrade::get_status();
		$checkpoint['step']              = 4;
		$checkpoint['last_error']        = 'A prior worker owns this checkpoint.';
		update_option( 'cybermaps_upgrade_state', $checkpoint, false );
		$retry = time() + 600;
		$GLOBALS['cybermaps_mock_scheduled'][ Upgrade::RETRY_HOOK ] = $retry;

		Upgrade::stamp_fresh_install();

		$this->assertFalse( get_option( 'cybermaps_data_version', false ) );
		$this->assertSame( $checkpoint, get_option( 'cybermaps_upgrade_state' ) );
		$this->assertSame( $retry, wp_next_scheduled( Upgrade::RETRY_HOOK ) );
	}

	public function test_fresh_install_stamp_cannot_enter_while_an_upgrade_lease_is_live(): void {
		$GLOBALS['cybermaps_mock_options'] = array();
		$foreign_lock                    = array(
			'token' => 'active-upgrade-worker',
			'time'  => time(),
		);
		update_option( 'cybermaps_upgrade_lock', $foreign_lock, false );

		Upgrade::stamp_fresh_install();

		$this->assertFalse( get_option( 'cybermaps_data_version', false ) );
		$this->assertSame( $foreign_lock, get_option( 'cybermaps_upgrade_lock' ) );
	}

	public function test_fresh_install_stamp_cannot_overwrite_a_future_version_inserted_during_cas(): void {
		$GLOBALS['cybermaps_mock_options'] = array();
		$interleaved                     = false;
		$GLOBALS['cybermaps_mock_add_option_behavior'] = static function ( string $option, mixed $value, string $stage ) use ( &$interleaved ): void {
			unset( $value );
			if ( $interleaved || 'before' !== $stage || 'cybermaps_data_version' !== $option ) {
				return;
			}
			$interleaved = true;
			$GLOBALS['cybermaps_mock_options']['cybermaps_data_version'] = '7.0.0';
		};

		Upgrade::stamp_fresh_install();

		$this->assertTrue( $interleaved );
		$this->assertSame( '7.0.0', get_option( 'cybermaps_data_version' ) );
		$this->assertFalse( get_option( 'cybermaps_upgrade_state', false ) );
	}

	public function test_live_upgrade_lock_prevents_concurrent_migration(): void {
		update_option(
			'cybermaps_upgrade_lock',
			array(
				'token' => 'another-request',
				'time'  => time(),
			),
			false
		);

		Upgrade::run();

		$this->assertSame( '5.1.1', get_option( 'cybermaps_data_version' ) );
		$this->assertArrayHasKey( 'rss_sitemap_post_types', get_option( 'cybermaps_settings', array() ) );
	}

	public function test_state_is_reloaded_after_outer_lease_acquisition(): void {
		$GLOBALS['wpdb']->fail_next_insert = true;
		$advanced_state                   = Upgrade::get_status();
		$advanced_state['step']           = 2;
		$injected                         = false;
		$GLOBALS['cybermaps_mock_get_option_observer'] = static function ( string $option ) use ( &$injected, $advanced_state ): void {
			if ( $injected || 'cybermaps_upgrade_lock' !== $option ) {
				return;
			}
			$injected = true;
			$GLOBALS['cybermaps_mock_options']['cybermaps_upgrade_state'] = $advanced_state;
		};

		Upgrade::run();

		$this->assertTrue( $injected );
		$this->assertTrue( $GLOBALS['wpdb']->fail_next_insert );
		$this->assertSame( '6.6.0', get_option( 'cybermaps_data_version' ) );
	}

	public function test_waiter_does_not_replay_migrations_when_predecessor_completed_during_acquire(): void {
		$GLOBALS['wpdb']->fail_next_insert = true;
		$settled_state                    = Upgrade::get_status();
		$settled_state['step'] = 10;
		$completed                        = false;
		$GLOBALS['cybermaps_mock_get_option_observer'] = static function ( string $option ) use ( &$completed, $settled_state ): void {
			if ( $completed || 'cybermaps_upgrade_lock' !== $option ) {
				return;
			}
			$completed = true;
			$GLOBALS['cybermaps_mock_options']['cybermaps_data_version']  = '6.6.0';
			$GLOBALS['cybermaps_mock_options']['cybermaps_upgrade_state'] = $settled_state;
		};

		Upgrade::run();

		$this->assertTrue( $completed );
		$this->assertTrue( $GLOBALS['wpdb']->fail_next_insert );
		$this->assertArrayHasKey( 'rss_sitemap_post_types', get_option( 'cybermaps_settings', array() ) );
		$this->assertSame( '6.6.0', get_option( 'cybermaps_data_version' ) );
		$this->assertFalse( get_option( 'cybermaps_upgrade_state', false ) );
	}

	public function test_lost_upgrade_lease_preserves_the_successor_lock(): void {
		$successor = array(
			'token' => 'successor-upgrade-request',
			'time'  => time(),
		);
		$successor_state               = Upgrade::get_status();
		$successor_state['step']       = 8;
		$successor_state['last_error'] = 'successor checkpoint';
		$stolen                        = false;
		$GLOBALS['cybermaps_mock_update_option_behavior'] = static function ( string $option, mixed $value, string $stage ) use ( &$stolen, $successor, $successor_state ): void {
			unset( $value );
			if ( ! $stolen && 'cybermaps_upgrade_state' === $option && 'after' === $stage ) {
				$stolen = true;
				$GLOBALS['cybermaps_mock_options']['cybermaps_upgrade_lock']  = $successor;
				$GLOBALS['cybermaps_mock_options']['cybermaps_upgrade_state'] = $successor_state;
			}
		};

		Upgrade::run();

		$this->assertTrue( $stolen );
		$this->assertSame( '5.1.1', get_option( 'cybermaps_data_version' ) );
		$this->assertSame( $successor_state, get_option( 'cybermaps_upgrade_state' ) );
		$this->assertSame( $successor, get_option( 'cybermaps_upgrade_lock' ) );
	}

	public function test_successful_step_that_loses_lease_cannot_checkpoint_or_stamp(): void {
		$starting_state         = Upgrade::get_status();
		$starting_state['step'] = 2;
		update_option( 'cybermaps_upgrade_state', $starting_state, false );
		$successor = array(
			'token' => 'successful-step-successor',
			'time'  => time(),
		);
		$successor_state               = $starting_state;
		$successor_state['step']       = 7;
		$successor_state['last_error'] = 'successor owns the checkpoint';
		$GLOBALS['cybermaps_mock_update_option_behavior'] = static function ( string $option, mixed $value, string $stage ) use ( $successor, $successor_state ): void {
			unset( $value );
			if ( 'cybermaps_settings' === $option && 'after' === $stage ) {
				$GLOBALS['cybermaps_mock_options']['cybermaps_upgrade_lock']  = $successor;
				$GLOBALS['cybermaps_mock_options']['cybermaps_upgrade_state'] = $successor_state;
			}
		};

		Upgrade::run();

		$this->assertSame( '5.1.1', get_option( 'cybermaps_data_version' ) );
		$this->assertSame( $successor_state, get_option( 'cybermaps_upgrade_state' ) );
		$this->assertSame( $successor, get_option( 'cybermaps_upgrade_lock' ) );
		$this->assertFalse( wp_next_scheduled( Upgrade::RETRY_HOOK ) );
	}

	public function test_failing_step_that_loses_lease_cannot_record_failure(): void {
		$starting_state         = Upgrade::get_status();
		$starting_state['step'] = 2;
		update_option( 'cybermaps_upgrade_state', $starting_state, false );
		$successor = array(
			'token' => 'failing-step-successor',
			'time'  => time(),
		);
		$successor_state               = $starting_state;
		$successor_state['step']       = 5;
		$successor_state['last_error'] = 'successor failure evidence';
		$GLOBALS['cybermaps_mock_update_option_behavior'] = static function ( string $option, mixed $value, string $stage ) use ( $successor, $successor_state ): void {
			unset( $value );
			if ( 'cybermaps_settings' === $option && 'after' === $stage ) {
				$GLOBALS['cybermaps_mock_options']['cybermaps_settings']      = array( 'static_engine_mode' => 'invalid' );
				$GLOBALS['cybermaps_mock_options']['cybermaps_upgrade_lock']  = $successor;
				$GLOBALS['cybermaps_mock_options']['cybermaps_upgrade_state'] = $successor_state;
			}
		};

		Upgrade::run();

		$this->assertSame( '5.1.1', get_option( 'cybermaps_data_version' ) );
		$this->assertSame( $successor_state, get_option( 'cybermaps_upgrade_state' ) );
		$this->assertSame( $successor, get_option( 'cybermaps_upgrade_lock' ) );
		$this->assertFalse( wp_next_scheduled( Upgrade::RETRY_HOOK ) );
	}

	public function test_throwing_step_that_loses_lease_cannot_record_failure(): void {
		$starting_state         = Upgrade::get_status();
		$starting_state['step'] = 2;
		update_option( 'cybermaps_upgrade_state', $starting_state, false );
		$successor = array(
			'token' => 'throwing-step-successor',
			'time'  => time(),
		);
		$successor_state               = $starting_state;
		$successor_state['step']       = 6;
		$successor_state['last_error'] = 'successor exception evidence';
		$GLOBALS['cybermaps_mock_update_option_behavior'] = static function ( string $option, mixed $value, string $stage ) use ( $successor, $successor_state ): void {
			unset( $value );
			if ( 'cybermaps_settings' === $option && 'before' === $stage ) {
				$GLOBALS['cybermaps_mock_options']['cybermaps_upgrade_lock']  = $successor;
				$GLOBALS['cybermaps_mock_options']['cybermaps_upgrade_state'] = $successor_state;
				throw new \RuntimeException( 'The old worker paused and lost its lease.' );
			}
		};

		Upgrade::run();

		$this->assertSame( '5.1.1', get_option( 'cybermaps_data_version' ) );
		$this->assertSame( $successor_state, get_option( 'cybermaps_upgrade_state' ) );
		$this->assertSame( $successor, get_option( 'cybermaps_upgrade_lock' ) );
		$this->assertFalse( wp_next_scheduled( Upgrade::RETRY_HOOK ) );
	}

	public function test_lease_stolen_immediately_before_version_stamp_preserves_successor_state(): void {
		$starting_state         = Upgrade::get_status();
		$starting_state['step'] = 10;
		update_option( 'cybermaps_upgrade_state', $starting_state, false );
		$successor = array(
			'token' => 'pre-stamp-successor',
			'time'  => time(),
		);
		$successor_state               = $starting_state;
		$successor_state['last_error'] = 'successor reached stamp boundary';
		$data_version_reads            = 0;
		$GLOBALS['cybermaps_mock_get_option_observer'] = static function ( string $option ) use ( &$data_version_reads, $successor, $successor_state ): void {
			if ( 'cybermaps_data_version' !== $option || 3 !== ++$data_version_reads ) {
				return;
			}
			$GLOBALS['cybermaps_mock_options']['cybermaps_upgrade_lock']  = $successor;
			$GLOBALS['cybermaps_mock_options']['cybermaps_upgrade_state'] = $successor_state;
		};

		Upgrade::run();

		$this->assertSame( 3, $data_version_reads );
		$this->assertSame( '5.1.1', get_option( 'cybermaps_data_version' ) );
		$this->assertSame( $successor_state, get_option( 'cybermaps_upgrade_state' ) );
		$this->assertSame( $successor, get_option( 'cybermaps_upgrade_lock' ) );
	}

	public function test_future_data_version_observed_before_stamp_is_never_downgraded_or_cleaned(): void {
		$state         = Upgrade::get_status();
		$state['step'] = 10;
		update_option( 'cybermaps_upgrade_state', $state, false );
		$data_version_reads = 0;
		$GLOBALS['cybermaps_mock_get_option_observer'] = static function ( string $option ) use ( &$data_version_reads ): void {
			if ( 'cybermaps_data_version' === $option && 3 === ++$data_version_reads ) {
				$GLOBALS['cybermaps_mock_options']['cybermaps_data_version'] = '7.0.0';
			}
		};

		Upgrade::run();

		$this->assertSame( 3, $data_version_reads );
		$this->assertSame( '7.0.0', get_option( 'cybermaps_data_version' ) );
		$this->assertSame( $state, get_option( 'cybermaps_upgrade_state' ) );
		$this->assertFalse( get_option( 'cybermaps_upgrade_lock', false ) );
	}

	public function test_completed_upgrade_cleanup_preserves_foreign_state_and_lock(): void {
		$foreign = array(
			'token' => 'foreign-completed-upgrade-request',
			'time'  => time(),
		);
		$GLOBALS['cybermaps_mock_options']['cybermaps_data_version'] = '6.6.0';
		update_option( 'cybermaps_upgrade_state', array( 'target' => '6.6.0' ), false );
		update_option( 'cybermaps_upgrade_lock', $foreign, false );

		Upgrade::run();

		$this->assertSame( array( 'target' => '6.6.0' ), get_option( 'cybermaps_upgrade_state', false ) );
		$this->assertSame( $foreign, get_option( 'cybermaps_upgrade_lock' ) );
	}

	public function test_deferred_static_entry_preserves_foreign_target_state_at_current_data_version(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_data_version'] = '6.6.0';
		$pending_state                      = Upgrade::get_status();
		$pending_state['step'] = 10;
		$pending_state['static_pending']    = 1;
		$pending_state['static_next_retry'] = 0;
		update_option( 'cybermaps_upgrade_state', $pending_state, false );
		$future_state                      = $pending_state;
		$future_state['target']            = '7.0.0';
		$future_state['static_last_error'] = 'future worker owns this retry';
		$future_retry                      = time() + 900;
		$GLOBALS['cybermaps_mock_scheduled'][ Upgrade::RETRY_HOOK ] = time() + 60;
		$advanced = false;
		$GLOBALS['cybermaps_mock_get_option_observer'] = static function ( string $option ) use ( &$advanced, $future_state, $future_retry ): void {
			if ( $advanced || 'cybermaps_upgrade_lock' !== $option ) {
				return;
			}
			$advanced = true;
			$GLOBALS['cybermaps_mock_options']['cybermaps_upgrade_state']             = $future_state;
			$GLOBALS['cybermaps_mock_scheduled'][ Upgrade::RETRY_HOOK ] = $future_retry;
		};

		Upgrade::run();

		$this->assertTrue( $advanced );
		$this->assertSame( '6.6.0', get_option( 'cybermaps_data_version' ) );
		$this->assertSame( $future_state, get_option( 'cybermaps_upgrade_state' ) );
		$this->assertSame( $future_retry, wp_next_scheduled( Upgrade::RETRY_HOOK ) );
	}

	public function test_cleanup_entry_preserves_foreign_target_state_at_current_data_version(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_data_version'] = '6.6.0';
		$completed_state         = Upgrade::get_status();
		$completed_state['step'] = 10;
		update_option( 'cybermaps_upgrade_state', $completed_state, false );
		$future_state               = $completed_state;
		$future_state['target']     = '7.0.0';
		$future_state['last_error'] = 'future cleanup evidence';
		$future_retry               = time() + 1200;
		$GLOBALS['cybermaps_mock_scheduled'][ Upgrade::RETRY_HOOK ] = time() + 60;
		$advanced = false;
		$GLOBALS['cybermaps_mock_get_option_observer'] = static function ( string $option ) use ( &$advanced, $future_state, $future_retry ): void {
			if ( $advanced || 'cybermaps_upgrade_lock' !== $option ) {
				return;
			}
			$advanced = true;
			$GLOBALS['cybermaps_mock_options']['cybermaps_upgrade_state']             = $future_state;
			$GLOBALS['cybermaps_mock_scheduled'][ Upgrade::RETRY_HOOK ] = $future_retry;
		};

		Upgrade::run();

		$this->assertTrue( $advanced );
		$this->assertSame( '6.6.0', get_option( 'cybermaps_data_version' ) );
		$this->assertSame( $future_state, get_option( 'cybermaps_upgrade_state' ) );
		$this->assertSame( $future_retry, wp_next_scheduled( Upgrade::RETRY_HOOK ) );
	}

	public function test_old_data_version_never_replays_or_overwrites_foreign_target_coordination(): void {
		$foreign_state = array(
			'target'            => '7.0.0',
			'step'              => 4,
			'attempts'          => 2,
			'next_retry'        => 0,
			'last_step'         => 'future_step',
			'last_error'        => 'future worker checkpoint',
			'updated_at'        => time(),
			'static_pending'    => 1,
			'static_attempts'   => 1,
			'static_next_retry' => time() + 600,
			'static_last_error' => 'future static evidence',
			'static_updated_at' => time(),
		);
		update_option( 'cybermaps_upgrade_state', $foreign_state, false );
		$foreign_retry = time() + 600;
		$GLOBALS['cybermaps_mock_scheduled'][ Upgrade::RETRY_HOOK ] = $foreign_retry;
		$GLOBALS['wpdb']->fail_next_insert = true;

		Upgrade::run();

		$this->assertSame( '5.1.1', get_option( 'cybermaps_data_version' ) );
		$this->assertSame( $foreign_state, get_option( 'cybermaps_upgrade_state' ) );
		$this->assertSame( $foreign_retry, wp_next_scheduled( Upgrade::RETRY_HOOK ) );
		$this->assertTrue( $GLOBALS['wpdb']->fail_next_insert );
		$this->assertFalse( get_option( 'cybermaps_upgrade_lock', false ) );
	}

	public function test_database_state_cas_cannot_overwrite_successor_after_lease_observation(): void {
		$initial_state         = Upgrade::get_status();
		$initial_state['step'] = 3;
		$database              = new UpgradeOptionCasWpdbStub(
			array(
				'cybermaps_upgrade_state' => \serialize( $initial_state ),
			)
		);
		$GLOBALS['wpdb'] = $database;
		$lock            = new OptionLeaseLock( 'cybermaps_upgrade_lock', 300, 60 );
		$this->assertTrue( $lock->acquire() );
		( new \ReflectionProperty( Upgrade::class, 'upgrade_lock' ) )->setValue( null, $lock );
		$successor = array(
			'token' => 'database-cas-successor',
			'time'  => time(),
		);
		$successor_state               = $initial_state;
		$successor_state['step']       = 8;
		$successor_state['last_error'] = 'successor database snapshot';
		$database->before_guarded_mutation = static function ( UpgradeOptionCasWpdbStub $wpdb ) use ( $successor, $successor_state ): void {
			$wpdb->rows['cybermaps_upgrade_lock']  = \serialize( $successor );
			$wpdb->rows['cybermaps_upgrade_state'] = \serialize( $successor_state );
		};
		$candidate         = $initial_state;
		$candidate['step'] = 4;
		$method            = new \ReflectionMethod( Upgrade::class, 'lease_guarded_replace_option' );

		$this->assertFalse(
			$method->invoke( null, 'cybermaps_upgrade_state', $candidate, $initial_state, true )
		);
		$this->assertSame( $successor, maybe_unserialize( $database->rows['cybermaps_upgrade_lock'] ) );
		$this->assertSame( $successor_state, maybe_unserialize( $database->rows['cybermaps_upgrade_state'] ) );
		$this->assertStringContainsString( 'INNER JOIN %i AS lease', $database->guarded_queries[0]['query'] ?? '' );
		$this->assertStringContainsString( 'BINARY target.option_value = BINARY %s', $database->guarded_queries[0]['query'] ?? '' );
		$lock->release();
	}

	public function test_database_cleanup_cas_uses_exact_lease_and_state_arguments(): void {
		$state         = Upgrade::get_status();
		$state['step'] = 10;
		$database      = new UpgradeOptionCasWpdbStub(
			array(
				'cybermaps_upgrade_state' => \serialize( $state ),
			)
		);
		$GLOBALS['wpdb'] = $database;
		$lock            = new OptionLeaseLock( 'cybermaps_upgrade_lock', 300, 60 );
		$this->assertTrue( $lock->acquire() );
		( new \ReflectionProperty( Upgrade::class, 'upgrade_lock' ) )->setValue( null, $lock );
		$lease_raw = $database->rows['cybermaps_upgrade_lock'];
		$method    = new \ReflectionMethod( Upgrade::class, 'lease_guarded_delete_option' );

		$this->assertTrue( $method->invoke( null, 'cybermaps_upgrade_state', $state, true ) );
		$this->assertArrayNotHasKey( 'cybermaps_upgrade_state', $database->rows );
		$this->assertCount( 1, $database->guarded_queries );
		$this->assertSame(
			array(
				'wp_options',
				'wp_options',
				'cybermaps_upgrade_lock',
				$lease_raw,
				'cybermaps_upgrade_state',
				\serialize( $state ),
			),
			$database->guarded_queries[0]['args']
		);
		$lock->release();
	}

	private function make_retry_due(): void {
		$state               = Upgrade::get_status();
		$state['next_retry'] = 0;
		update_option( 'cybermaps_upgrade_state', $state, false );
	}

	private function canonical_value( array $rows, int $post_id ): string {
		foreach ( $rows as $row ) {
			if ( (int) $row['post_id'] === $post_id ) {
				return (string) $row['meta_value'];
			}
		}
		return '';
	}
}

class UpgradeWpdbStub {
	public string $prefix      = 'wp_';
	public string $base_prefix = 'wp_';
	public string $postmeta    = 'wp_postmeta';
	public string $last_error  = '';

	/** @var array<int,array<string,mixed>> */
	public array $postmeta_rows;
	/** @var string[] */
	public array $existing_tables = array();
	public bool $fail_next_insert    = false;
	public int $postmeta_query_count = 0;

	public function __construct( array $rows ) {
		$this->postmeta_rows = $rows;
	}

	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4';
	}

	public function prepare( string $query, mixed ...$args ): string {
		foreach ( $args as $arg ) {
			if ( false !== strpos( $query, '%i' ) ) {
				$query = (string) preg_replace( '/%i/', (string) $arg, $query, 1 );
				continue;
			}
			if ( false !== strpos( $query, '%d' ) ) {
				$query = (string) preg_replace( '/%d/', (string) (int) $arg, $query, 1 );
				continue;
			}
			$query = (string) preg_replace( '/%s/', "'" . (string) $arg . "'", $query, 1 );
		}
		return $query;
	}

	public function get_var( mixed $query ): mixed {
		if ( is_string( $query ) && str_starts_with( $query, 'SHOW TABLES LIKE ' ) ) {
			$table = stripslashes( trim( substr( $query, strlen( 'SHOW TABLES LIKE ' ) ), "' " ) );
			return in_array( $table, $this->existing_tables, true ) ? $table : null;
		}

		return null;
	}

	public function query( string $query ) {
		if ( str_starts_with( trim( $query ), 'INSERT INTO wp_postmeta' ) ) {
			++$this->postmeta_query_count;
			if ( $this->fail_next_insert ) {
				$this->fail_next_insert = false;
				return false;
			}
			$legacy = array();
			foreach ( $this->postmeta_rows as $row ) {
				if ( '_cybermaps_exclude_search' === $row['meta_key'] ) {
					$legacy[ (int) $row['post_id'] ][] = (string) $row['meta_value'];
				}
			}
			foreach ( $legacy as $post_id => $values ) {
				$has_canonical = false;
				foreach ( $this->postmeta_rows as $row ) {
					if ( (int) $row['post_id'] === $post_id && '_cybermaps_exclude_sitemap' === $row['meta_key'] ) {
						$has_canonical = true;
						break;
					}
				}
				if ( ! $has_canonical ) {
					$this->postmeta_rows[] = array(
						'meta_id'    => count( $this->postmeta_rows ) + 1,
						'post_id'    => $post_id,
						'meta_key'   => '_cybermaps_exclude_sitemap',
						'meta_value' => max( $values ),
					);
				}
			}
			return 1;
		}
		if ( str_starts_with( trim( $query ), 'DELETE FROM wp_postmeta' ) ) {
			++$this->postmeta_query_count;
			$this->postmeta_rows = array_values(
				array_filter(
					$this->postmeta_rows,
					static fn( array $row ): bool => '_cybermaps_exclude_search' !== $row['meta_key']
				)
			);
			return 1;
		}
		return 1;
	}
}

/** Exact wp_options byte-store used to exercise Upgrade's lease-bound SQL. */
final class UpgradeOptionCasWpdbStub {
	public string $options = 'wp_options';
	/** @var array<string,string> */
	public array $rows;
	/** @var array<int,array{query:string,args:array<int,mixed>}> */
	public array $guarded_queries = array();
	/** @var callable(self):void|null */
	public mixed $before_guarded_mutation = null;

	/** @param array<string,string> $rows */
	public function __construct( array $rows ) {
		$this->rows = $rows;
	}

	/** @return array{query:string,args:array<int,mixed>} */
	public function prepare( string $query, mixed ...$args ): array {
		return array(
			'query' => $query,
			'args'  => $args,
		);
	}

	/** @param array{query:string,args:array<int,mixed>} $prepared */
	public function get_var( array $prepared ): ?string {
		$args   = $prepared['args'];
		$option = (string) ( $args[1] ?? '' );

		return $this->rows[ $option ] ?? null;
	}

	/** @param array{query:string,args:array<int,mixed>} $prepared */
	public function query( array $prepared ): int|false {
		$query = ltrim( $prepared['query'] );
		$args  = $prepared['args'];

		if ( str_starts_with( $query, 'UPDATE %i AS target' ) ) {
			$this->before_guarded_mutation( $prepared );
			$lease_option = (string) ( $args[2] ?? '' );
			$target_option = (string) ( $args[6] ?? '' );
			if (
				( $this->rows[ $lease_option ] ?? null ) !== ( $args[3] ?? null )
				|| ( $this->rows[ $target_option ] ?? null ) !== ( $args[7] ?? null )
			) {
				return 0;
			}
			$this->rows[ $target_option ] = (string) ( $args[4] ?? '' );
			return 1;
		}

		if ( str_starts_with( $query, 'DELETE target' ) ) {
			$this->before_guarded_mutation( $prepared );
			$lease_option  = (string) ( $args[2] ?? '' );
			$target_option = (string) ( $args[4] ?? '' );
			if (
				( $this->rows[ $lease_option ] ?? null ) !== ( $args[3] ?? null )
				|| ( $this->rows[ $target_option ] ?? null ) !== ( $args[5] ?? null )
			) {
				return 0;
			}
			unset( $this->rows[ $target_option ] );
			return 1;
		}

		if ( str_starts_with( $query, 'INSERT IGNORE' ) && str_contains( $query, ' FROM %i AS lease ' ) ) {
			$this->before_guarded_mutation( $prepared );
			$target_option = (string) ( $args[1] ?? '' );
			$lease_option  = (string) ( $args[5] ?? '' );
			if (
				isset( $this->rows[ $target_option ] )
				|| ( $this->rows[ $lease_option ] ?? null ) !== ( $args[6] ?? null )
			) {
				return 0;
			}
			$this->rows[ $target_option ] = (string) ( $args[2] ?? '' );
			return 1;
		}

		if ( str_starts_with( $query, 'INSERT IGNORE' ) ) {
			$option = (string) ( $args[1] ?? '' );
			if ( isset( $this->rows[ $option ] ) ) {
				return 0;
			}
			$this->rows[ $option ] = (string) ( $args[2] ?? '' );
			return 1;
		}

		if ( str_starts_with( $query, 'UPDATE %i SET option_value' ) ) {
			$option = (string) ( $args[2] ?? '' );
			if ( ( $this->rows[ $option ] ?? null ) !== ( $args[3] ?? null ) ) {
				return 0;
			}
			$this->rows[ $option ] = (string) ( $args[1] ?? '' );
			return 1;
		}

		if ( str_starts_with( $query, 'DELETE FROM' ) ) {
			$option = (string) ( $args[1] ?? '' );
			if ( ( $this->rows[ $option ] ?? null ) !== ( $args[2] ?? null ) ) {
				return 0;
			}
			unset( $this->rows[ $option ] );
			return 1;
		}

		return false;
	}

	/** @param array{query:string,args:array<int,mixed>} $prepared */
	private function before_guarded_mutation( array $prepared ): void {
		$this->guarded_queries[] = $prepared;
		$callback                = $this->before_guarded_mutation;
		$this->before_guarded_mutation = null;
		if ( is_callable( $callback ) ) {
			$callback( $this );
		}
	}
}

/** wpdb-routing drop-in analogue: query-capable, but not the exact Core wpdb class. */
final class UpgradeDropInWpdbStub extends UpgradeWpdbStub {
	public function get_var( mixed $query ): mixed {
		if ( is_string( $query ) && str_starts_with( $query, 'SHOW TABLES LIKE ' ) ) {
			return parent::get_var( $query );
		}

		unset( $query );
		return null;
	}
}
