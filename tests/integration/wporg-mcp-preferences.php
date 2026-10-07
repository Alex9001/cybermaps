<?php
declare(strict_types=1);
/** Disposable native MCP retirement: exact settings CAS and preserved 8.0 consent. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'CYBERMAPS_STATE_FIXTURE_DISPOSABLE' ) ) { throw new RuntimeException( 'Requires explicitly disposable WP-CLI validation.' ); }

use Cybermaps\Admin\ConfigurationMutationStore;
use Cybermaps\Core\CacheManager;
use Cybermaps\Core\ConfigurationStore;
use Cybermaps\Core\MCPMigration;
use Cybermaps\Core\OptionLeaseLock;
use Cybermaps\Core\RawOptionStore;

global $wpdb;
$main = $wpdb;
$previous = array( $wpdb->prefix, $wpdb->base_prefix, $wpdb->options );
$prefix = $wpdb->prefix . 'mcp_cas_' . bin2hex( random_bytes( 4 ) ) . '_';
$options = $prefix . 'options';
$legacy_table = $prefix . 'cybermaps_mcp_tasks';
$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$other->prefix = $prefix; $other->base_prefix = $prefix; $other->options = $options;
$other->suppress_errors( true );
$prior_errors = $main->suppress_errors( true );
$filter = null;
$checks = array();
$block_http = static fn() => new WP_Error( 'fixture_no_http', 'MCP fixture blocks delivery.' );
add_filter( 'pre_http_request', $block_http );
$assert = static function ( bool $condition, string $message ) use ( &$checks ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } $checks[] = $message; };
$seed = static function ( string $name, mixed $value ) use ( $main, $options, $assert ): void {
	$assert( false !== $main->query( $main->prepare( 'INSERT INTO %i (option_name,option_value,autoload) VALUES (%s,%s,%s) ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)', $options, $name, maybe_serialize( $value ), 'off' ) ), 'Fixture seed succeeds' );
	RawOptionStore::invalidate( $name );
};
$reset = static function ( mixed $settings ) use ( $seed, $main, $legacy_table, $assert ): void {
	$seed( 'cybermaps_settings', $settings );
	$seed( MCPMigration::DONE_OPTION, '1' );
	$seed( 'cybermaps_mcp_migration_notice', '1' );
	$assert( false !== $main->query( $main->prepare( 'CREATE TABLE IF NOT EXISTS %i (id bigint unsigned NOT NULL PRIMARY KEY) ENGINE=InnoDB', $legacy_table ) ), 'Legacy fixture table exists' );
	wp_schedule_single_event( time() + 600, 'cybermaps_mcp_run_task' );
};
$retryable = static function () use ( $main, $legacy_table, $assert ): void {
	$assert( '1' === RawOptionStore::read( $main, MCPMigration::DONE_OPTION ), 'Failure does not mark retirement complete' );
	$assert( false !== wp_next_scheduled( 'cybermaps_mcp_run_task' ), 'Failure preserves legacy scheduled work' );
	$assert( $legacy_table === $main->get_var( $main->prepare( 'SHOW TABLES LIKE %s', $main->esc_like( $legacy_table ) ) ), 'Failure preserves legacy table' );
};
$cleanup_only = static function () use ( $assert ): bool {
	$lock = new OptionLeaseLock( 'cybermaps_mcp_retirement_lock', 300, 30, true );
	$assert( $lock->acquire(), 'Native retirement lease acquired' );
	try { return ( new ReflectionMethod( MCPMigration::class, 'remove_settings' ) )->invoke( null, $lock ); }
	finally { $lock->release(); }
};
$is_settings_write = static fn( string $sql ): bool => str_starts_with( $sql, 'UPDATE ' ) && str_contains( $sql, "'cybermaps_settings'" ) && str_contains( $sql, 'BINARY option_value' );
$base = array( 'static_engine_mode' => 'off', 'mcp_mode' => 'read_only', 'agent_registration_mode' => 'off', 'enable_llms_full' => '1', 'sitemap_posts_per_page' => 123 );
try {
	// Table-prefix isolation does not change WordPress/Redis cache namespaces.
	// This explicitly disposable runtime permits a full flush, including observer inventories.
	$assert( wp_cache_flush(), 'Disposable cache was cleared before isolation' );
	CacheManager::reset_runtime();
	ConfigurationStore::reset_memo();
	$assert( false !== $main->query( $main->prepare( 'CREATE TABLE %i LIKE %i', $options, $previous[2] ) ), 'Isolated options table created' );
	$main->prefix = $prefix; $main->base_prefix = $prefix; $main->options = $options;
	$assert( 1 === $main->query( $main->prepare( 'INSERT INTO %i (option_name,option_value,autoload) VALUES (%s,%s,%s)', $options, 'fixture_autoload_control', 'retained', 'on' ) ), 'Normal autoload control seeded' );
	wp_cache_delete( 'alloptions', 'options' ); wp_cache_delete( 'notoptions', 'options' );

	// A competing request loses the MCP-only lock but can still save reviewed settings.
	$reset( $base );
	ConfigurationStore::settings(); get_option( 'cybermaps_settings' );
	$winner = array( 'static_engine_mode' => 'off', 'enable_mcp_adapter' => '0', 'enable_llms_full' => '0', 'sitemap_posts_per_page' => 777 );
	$interleaved = false;
	$filter = static function ( string $sql ) use ( $is_settings_write, &$interleaved, $main, $other, $winner, $assert ): string {
		if ( ! $interleaved && $is_settings_write( $sql ) ) {
			$interleaved = true;
			$GLOBALS['wpdb'] = $other;
			try {
				$assert( ! MCPMigration::run(), 'Competing request cannot acquire the MCP lease' );
				$before = ConfigurationMutationStore::read( 'cybermaps_settings' );
				$after = ConfigurationMutationStore::target( $winner );
				$assert( ConfigurationMutationStore::write( 'cybermaps_settings', $before, $after ), 'Competing reviewed configuration write succeeds' );
				ConfigurationMutationStore::notify( 'cybermaps_settings', $before, $after );
			} finally { $GLOBALS['wpdb'] = $main; }
		}
		return $sql;
	};
	add_filter( 'query', $filter );
	$assert( ! MCPMigration::run(), 'Stale retirement CAS is rejected' );
	remove_filter( 'query', $filter ); $filter = null;
	$assert( $interleaved, 'Native concurrent writer seam was reached' );
	$assert( $winner === maybe_unserialize( RawOptionStore::read( $main, 'cybermaps_settings' ) ), 'Concurrent opt-outs and unrelated settings survive exactly' );
	$assert( $winner === get_option( 'cybermaps_settings' ) && $winner === ConfigurationStore::settings(), 'API caches and runtime memo observe the surviving winner' );
	$retryable();
	$assert( MCPMigration::run(), 'Fresh cleanup retries successfully' );
	$assert( $winner === get_option( 'cybermaps_settings' ), 'Retry preserves canonical adapter opt-out' );

	// Read, write and session failures must stop before destructive cleanup.
	foreach ( array( 'read', 'write', 'reconnect' ) as $fault ) {
		$reset( $base );
		$hit = false;
		$filter = static function ( string $sql ) use ( $fault, &$hit, $is_settings_write, $main, $assert ): string {
			$matches = 'read' === $fault ? str_starts_with( $sql, 'SELECT option_value' ) && str_contains( $sql, "'cybermaps_settings'" ) : $is_settings_write( $sql );
			if ( ! $hit && $matches ) {
				$hit = true;
				if ( 'reconnect' === $fault ) { $main->close(); $assert( $main->db_connect( false ), 'Native reconnect succeeds' ); return $sql; }
				return 'CYBERMAPS_FIXTURE_INJECTED_SQL_FAILURE';
			}
			return $sql;
		};
		add_filter( 'query', $filter );
		$assert( ! MCPMigration::run(), 'Injected ' . $fault . ' failure is rejected' );
		remove_filter( 'query', $filter ); $filter = null;
		$assert( $hit, 'Injected ' . $fault . ' seam was reached' );
		$assert( $base === maybe_unserialize( RawOptionStore::read( $main, 'cybermaps_settings' ) ), 'Injected ' . $fault . ' preserves raw settings' );
		$retryable();
		// Lost sessions correctly leave their option lease for expiry; remove fixture-only lease.
		$main->query( $main->prepare( 'DELETE FROM %i WHERE option_name=%s', $options, 'cybermaps_mcp_retirement_lock' ) );
		RawOptionStore::invalidate( 'cybermaps_mcp_retirement_lock' );
	}

	$reset( $base );
	$assert( MCPMigration::run(), '8.0 preference cleanup succeeds' );
	$cleaned = get_option( 'cybermaps_settings' );
	$assert( ! array_key_exists( 'mcp_mode', $cleaned ) && ! array_key_exists( 'agent_registration_mode', $cleaned ) && '1' === $cleaned['enable_mcp_adapter'], '8.0 read-only consent converts and retired keys disappear' );
	$assert( false === get_option( 'cybermaps_mcp_migration_notice', false ), 'Obsolete migration notice disappears' );
	$reset( $base + array( 'enable_mcp_adapter' => '0' ) );
	$assert( MCPMigration::run() && '0' === get_option( 'cybermaps_settings' )['enable_mcp_adapter'], 'Explicit current opt-out wins over legacy read-only mode' );
	$reset( $base ); $seed( MCPMigration::DONE_OPTION, '0' );
	$assert( MCPMigration::run() && ! array_key_exists( 'enable_mcp_adapter', get_option( 'cybermaps_settings' ) ), 'Older read-only mode grants no new adapter consent' );

	$main->query( $main->prepare( 'DELETE FROM %i WHERE option_name=%s', $options, 'cybermaps_settings' ) ); RawOptionStore::invalidate( 'cybermaps_settings' );
	$assert( $cleanup_only() && null === RawOptionStore::read( $main, 'cybermaps_settings' ), 'Absent settings remain absent' );
	$seed( 'cybermaps_settings', array() );
	$assert( $cleanup_only() && array() === get_option( 'cybermaps_settings' ), 'Serialized empty array remains a valid no-op' );
	foreach ( array( '', 'broken', 42 ) as $invalid ) {
		$reset( $invalid );
		$raw = RawOptionStore::read( $main, 'cybermaps_settings' );
		$assert( ! MCPMigration::run() && $raw === RawOptionStore::read( $main, 'cybermaps_settings' ), 'Malformed settings remain intact and retryable' );
		$retryable();
	}
} finally {
	if ( null !== $filter ) { remove_filter( 'query', $filter ); }
	$GLOBALS['wpdb'] = $main;
	$other->close();
	$cleanup_failed = array();
	foreach ( array( $legacy_table, $prefix . 'cybermaps_static_ownership', $options ) as $table ) {
		$dropped = $main->query( $main->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		$remaining = $main->get_var( $main->prepare( 'SHOW TABLES LIKE %s', $main->esc_like( $table ) ) );
		if ( false === $dropped || null !== $remaining || '' !== $main->last_error ) { $cleanup_failed[] = $table; }
	}
	list( $main->prefix, $main->base_prefix, $main->options ) = $previous;
	// Notifications also cache inventories and diagnostic options outside an option-name list.
	$cache_restored = wp_cache_flush();
	CacheManager::reset_runtime();
	ConfigurationStore::reset_memo();
	$main->suppress_errors( $prior_errors );
	remove_filter( 'pre_http_request', $block_http );
}

$assert( array() === $cleanup_failed, 'Every isolated fixture table was removed' );
$assert( $previous === array( $main->prefix, $main->base_prefix, $main->options ) && $GLOBALS['wpdb'] === $main, 'Native database routing was restored' );
$assert( $cache_restored, 'Disposable cache was cleared after restoring database routing' );
echo wp_json_encode( array( 'complete' => true, 'database' => $main->db_version(), 'checks' => $checks ) ) . "\n";
