<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Core;

use Cybermaps\Core\Lifecycle;
use PHPUnit\Framework\TestCase;

final class LifecycleTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		global $cybermaps_mock_current_blog_id,
				$cybermaps_mock_current_network_id,
				$cybermaps_mock_dbdelta_queries,
				$cybermaps_mock_flush_count,
				$cybermaps_mock_flushes,
				$cybermaps_mock_network_option_calls,
				$cybermaps_mock_network_options_by_network,
				$cybermaps_mock_is_main_site,
				$cybermaps_mock_is_multisite,
				$cybermaps_mock_options,
				$cybermaps_mock_options_by_blog,
				$cybermaps_mock_scheduled,
				$cybermaps_mock_site_options,
				$cybermaps_mock_site_ids,
				$cybermaps_mock_blog_stack,
				$cybermaps_mock_switched_blogs,
				$cybermaps_mock_transients,
				$wpdb;

		$cybermaps_mock_current_blog_id            = 1;
		$cybermaps_mock_current_network_id         = 1;
		$cybermaps_mock_dbdelta_queries            = array();
		$cybermaps_mock_flush_count                = 0;
		$cybermaps_mock_flushes                    = array();
		$cybermaps_mock_network_option_calls       = array();
		$cybermaps_mock_network_options_by_network = array();
		$cybermaps_mock_is_main_site               = false;
		$cybermaps_mock_is_multisite               = true;
		$cybermaps_mock_options                    = array(
			'cybermaps_settings' => array(
				'static_engine_mode' => 'off',
			),
		);
		$cybermaps_mock_options_by_blog = array();
		$cybermaps_mock_scheduled       = array();
		$cybermaps_mock_site_options    = array();
		$cybermaps_mock_site_ids        = array( 1 );
		$cybermaps_mock_blog_stack      = array();
		$cybermaps_mock_switched_blogs  = array();
		$cybermaps_mock_transients      = array();
		$wpdb                            = new LifecycleWpdbStub();
		\Cybermaps\Core\CacheManager::reset_runtime();
		$GLOBALS['cybermaps_mock_dbdelta_callback'] = static function ( string $queries ): array {
			if ( preg_match( '/CREATE TABLE\s+([^\s(]+)/i', $queries, $matches ) ) {
				$GLOBALS['wpdb']->existing_tables[] = $matches[1];
				$GLOBALS['wpdb']->existing_tables   = array_values( array_unique( $GLOBALS['wpdb']->existing_tables ) );
			}

			return array( $queries );
		};
	}

	protected function tearDown(): void {
		global $cybermaps_mock_is_main_site, $cybermaps_mock_is_multisite;
		$cybermaps_mock_is_main_site = true;
		$cybermaps_mock_is_multisite = false;
		\Cybermaps\Core\CacheManager::reset_runtime();
		unset( $GLOBALS['cybermaps_mock_dbdelta_callback'] );

		parent::tearDown();
	}

	public function test_new_site_is_ignored_when_plugin_is_not_network_active(): void {
		Lifecycle::initialize_site( (object) array( 'blog_id' => 7 ) );

		$this->assertSame( array(), $GLOBALS['cybermaps_mock_switched_blogs'] );
		$this->assertSame( 0, $GLOBALS['cybermaps_mock_flush_count'] );
		$this->assertSame( array(), $GLOBALS['cybermaps_mock_scheduled'] );
	}

	public function test_network_active_plugin_provisions_only_the_new_site(): void {
		$GLOBALS['cybermaps_mock_site_options']['active_sitewide_plugins'] = array(
			CYBERMAPS_PLUGIN_BASENAME => 123456789,
		);

		Lifecycle::initialize_site( (object) array( 'blog_id' => 7 ) );

		$this->assertSame( array( 7 ), $GLOBALS['cybermaps_mock_switched_blogs'] );
		$this->assertSame( 1, $GLOBALS['cybermaps_mock_current_blog_id'] );
		$this->assertSame( 1, $GLOBALS['cybermaps_mock_flush_count'] );
		$this->assertSame( array( false ), $GLOBALS['cybermaps_mock_flushes'] );
		$this->assertArrayHasKey( 'cybermaps_cleanup_logs_event', $GLOBALS['cybermaps_mock_scheduled'] );
		$this->assertArrayHasKey( Lifecycle::RUNTIME_COUNTER_CLEANUP_HOOK, $GLOBALS['cybermaps_mock_scheduled'] );
		$this->assertArrayNotHasKey( 'cybermaps_daily_health_snapshot', $GLOBALS['cybermaps_mock_scheduled'] );
		$this->assertStringContainsString( 'cybermaps_runtime_counters', implode( "\n", $GLOBALS['cybermaps_mock_dbdelta_queries'] ) );
		$this->assertStringContainsString( 'cybermaps_indexnow_queue', implode( "\n", $GLOBALS['cybermaps_mock_dbdelta_queries'] ) );
	}

	public function test_network_activation_provisions_each_site_with_soft_rewrite_flushes(): void {
		$GLOBALS['cybermaps_mock_site_ids'] = array( 1, 2, 7 );

		Lifecycle::activate( true );

		$this->assertSame( array( 1, 2, 7 ), $GLOBALS['cybermaps_mock_switched_blogs'] );
		$this->assertSame( array( false, false, false ), $GLOBALS['cybermaps_mock_flushes'] );
		$this->assertSame( 1, $GLOBALS['cybermaps_mock_current_blog_id'] );
		$this->assertSame( array(), $GLOBALS['cybermaps_mock_blog_stack'] );
	}

	public function test_new_site_uses_its_own_network_activation_state(): void {
		$GLOBALS['cybermaps_mock_network_options_by_network'][4] = array(
			'active_sitewide_plugins' => array(
				CYBERMAPS_PLUGIN_BASENAME => 123456789,
			),
		);

		Lifecycle::initialize_site(
			(object) array(
				'blog_id' => 7,
				'site_id' => 4,
			)
		);

		$this->assertSame( array( array( 4, 'active_sitewide_plugins' ) ), $GLOBALS['cybermaps_mock_network_option_calls'] );
		$this->assertSame( array( 7 ), $GLOBALS['cybermaps_mock_switched_blogs'] );
	}

	public function test_new_site_is_not_provisioned_from_another_networks_activation(): void {
		$GLOBALS['cybermaps_mock_network_options_by_network'][1] = array(
			'active_sitewide_plugins' => array(
				CYBERMAPS_PLUGIN_BASENAME => 123456789,
			),
		);
		$GLOBALS['cybermaps_mock_network_options_by_network'][4] = array(
			'active_sitewide_plugins' => array(),
		);

		Lifecycle::initialize_site(
			(object) array(
				'blog_id'    => 7,
				'network_id' => 4,
			)
		);

		$this->assertSame( array( array( 4, 'active_sitewide_plugins' ) ), $GLOBALS['cybermaps_mock_network_option_calls'] );
		$this->assertSame( array(), $GLOBALS['cybermaps_mock_switched_blogs'] );
	}

	public function test_main_plugin_registers_the_new_site_lifecycle_hook(): void {
		$main = (string) file_get_contents( dirname( __DIR__, 2 ) . '/cybermaps.php' );

		$this->assertStringContainsString( "'wp_initialize_site'", $main );
		$this->assertStringContainsString( "Lifecycle::class, 'initialize_site'", $main );
		$this->assertStringContainsString( "'wpmu_drop_tables'", $main );
		$this->assertStringContainsString( "Lifecycle::class, 'include_site_tables_for_deletion'", $main );
		$this->assertStringContainsString( "'wp_uninitialize_site'", $main );
		$this->assertStringContainsString( "Lifecycle::class, 'cleanup_uninitialized_site'", $main );
	}

	public function test_multisite_teardown_includes_every_site_local_core_table_once(): void {
		$tables = Lifecycle::include_site_tables_for_deletion(
			array( 'wp_7_posts', 'wp_7_cybermaps_logs' ),
			7
		);

		$this->assertSame(
			array(
				'wp_7_posts',
				'wp_7_cybermaps_logs',
				'wp_7_cybermaps_runtime_counters',
				'wp_7_cybermaps_indexnow_queue',
				'wp_7_cybermaps_audit_findings',
				'wp_7_cybermaps_audit_resources',
				'wp_7_cybermaps_audit_runs',
				'wp_7_cybermaps_mcp_oauth_clients',
				'wp_7_cybermaps_mcp_oauth_codes',
				'wp_7_cybermaps_mcp_oauth_devices',
				'wp_7_cybermaps_mcp_oauth_grants',
				'wp_7_cybermaps_mcp_oauth_tokens',
				'wp_7_cybermaps_mcp_tasks',
			),
			$tables
		);
	}

	public function test_multisite_teardown_removes_shared_translation_rows(): void {
		$GLOBALS['cybermaps_mock_site_ids'] = array( 1, 2, 7 );
		$GLOBALS['cybermaps_mock_options_by_blog'] = array(
			1 => array(
				'cybermaps_core_transient_inventory' => array(
					'cybermaps_trans_1_100_post' => array(
						'family'  => 'translations',
						'expires' => time() + HOUR_IN_SECONDS,
					),
					'cybermaps_v7_site_1' => array(
						'family'  => 'sitemap',
						'expires' => time() + HOUR_IN_SECONDS,
					),
				),
			),
			2 => array(
				'cybermaps_core_transient_inventory' => array(
					'cybermaps_trans_2_200_post' => array(
						'family'  => 'translations',
						'expires' => time() + HOUR_IN_SECONDS,
					),
					'cybermaps_v7_site_2' => array(
						'family'  => 'sitemap',
						'expires' => time() + HOUR_IN_SECONDS,
					),
				),
			),
		);
		$GLOBALS['cybermaps_mock_transients'] = array(
			'cybermaps_trans_1_100_post' => array( 'cached' ),
			'cybermaps_trans_2_200_post' => array( 'cached' ),
			'cybermaps_v7_site_1'        => '<xml>stale one</xml>',
			'cybermaps_v7_site_2'        => '<xml>stale two</xml>',
		);

		Lifecycle::cleanup_uninitialized_site( (object) array( 'blog_id' => 7 ) );

		$this->assertStringContainsString(
			'DELETE FROM wp_cybermaps_translations WHERE site_id = 7',
			implode( "\n", $GLOBALS['wpdb']->queries )
		);
		$this->assertFalse( get_transient( 'cybermaps_trans_1_100_post' ) );
		$this->assertFalse( get_transient( 'cybermaps_trans_2_200_post' ) );
		$this->assertFalse( get_transient( 'cybermaps_v7_site_1' ) );
		$this->assertFalse( get_transient( 'cybermaps_v7_site_2' ) );
		$this->assertSame( array( 2 ), $GLOBALS['cybermaps_mock_switched_blogs'] );
		$this->assertSame( 1, $GLOBALS['cybermaps_mock_current_blog_id'] );
		$this->assertSame( array(), $GLOBALS['cybermaps_mock_blog_stack'] );
	}

	public function test_deactivation_clears_current_and_retired_crons(): void {
		$GLOBALS['cybermaps_mock_is_multisite'] = false;
		$GLOBALS['cybermaps_mock_scheduled'] = array_fill_keys(
				array(
					'cybermaps_cleanup_logs_event',
					Lifecycle::RUNTIME_COUNTER_CLEANUP_HOOK,
					'cybermaps_continue_logs_cleanup_event',
					'cybermaps_bg_sync_static_files',
					'cybermaps_refresh_time_sensitive_static_files',
					'cybermaps_weekly_health_snapshot',
					'cybermaps_edge_cache_retry',
				'unrelated_event',
			),
			123
		);

		Lifecycle::deactivate();

		$this->assertArrayNotHasKey( 'cybermaps_cleanup_logs_event', $GLOBALS['cybermaps_mock_scheduled'] );
		$this->assertArrayNotHasKey( Lifecycle::RUNTIME_COUNTER_CLEANUP_HOOK, $GLOBALS['cybermaps_mock_scheduled'] );
		$this->assertArrayNotHasKey( 'cybermaps_continue_logs_cleanup_event', $GLOBALS['cybermaps_mock_scheduled'] );
		$this->assertArrayNotHasKey( 'cybermaps_bg_sync_static_files', $GLOBALS['cybermaps_mock_scheduled'] );
		$this->assertArrayNotHasKey( 'cybermaps_refresh_time_sensitive_static_files', $GLOBALS['cybermaps_mock_scheduled'] );
		$this->assertArrayNotHasKey( 'cybermaps_weekly_health_snapshot', $GLOBALS['cybermaps_mock_scheduled'] );
		$this->assertArrayNotHasKey( 'cybermaps_edge_cache_retry', $GLOBALS['cybermaps_mock_scheduled'] );
		$this->assertArrayHasKey( 'unrelated_event', $GLOBALS['cybermaps_mock_scheduled'] );
	}

	public function test_network_deactivation_uses_soft_rewrite_flushes_for_each_site(): void {
		$GLOBALS['cybermaps_mock_site_ids'] = array( 1, 2, 7 );

		Lifecycle::deactivate( true );

		$this->assertSame( array( 1, 2, 7 ), $GLOBALS['cybermaps_mock_switched_blogs'] );
		$this->assertSame( array( false, false, false ), $GLOBALS['cybermaps_mock_flushes'] );
		$this->assertSame( 1, $GLOBALS['cybermaps_mock_current_blog_id'] );
		$this->assertSame( array(), $GLOBALS['cybermaps_mock_blog_stack'] );
	}
}

/**
 * Minimal wpdb surface used by activation.
 */
final class LifecycleWpdbStub {
	public string $prefix = 'wp_';
	public string $base_prefix = 'wp_';
	public string $options = 'wp_options';
	public string $last_error = '';
	/** @var string[] */
	public array $queries = array();
	/** @var string[] */
	public array $existing_tables = array();

	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4';
	}

	public function esc_like( string $value ): string {
		return addcslashes( $value, '_%\\' );
	}

	public function get_var( string $query, int $column = 0 ) {
		unset( $column );
		if ( str_starts_with( $query, 'SHOW TABLES LIKE' ) ) {
			$table = stripslashes( trim( substr( $query, strlen( 'SHOW TABLES LIKE ' ) ), "' " ) );
			return in_array( $table, $this->existing_tables, true ) ? $table : null;
		}
		if ( str_starts_with( $query, 'SHOW INDEX FROM' ) ) {
			return 'site_item_type';
		}

		return null;
	}

	public function get_blog_prefix( int $site_id ): string {
		return 1 === $site_id ? 'wp_' : 'wp_' . $site_id . '_';
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

	public function query( string $query ): int {
		$this->queries[] = $query;
		return 1;
	}
}
