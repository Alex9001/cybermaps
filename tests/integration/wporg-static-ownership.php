<?php
declare(strict_types=1);

/** Real database fixture; invoke only in a disposable WP-CLI validation runtime. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'CYBERMAPS_STATE_FIXTURE_DISPOSABLE' ) ) {
	throw new RuntimeException( 'Set CYBERMAPS_STATE_FIXTURE_DISPOSABLE=1 in the disposable validation runtime.' );
}

use Cybermaps\Core\ConfigurationStore;
use Cybermaps\Core\OptionLeaseLock;
use Cybermaps\Discovery\StaticBridge;
use Cybermaps\Discovery\StaticOwnershipStore;
use Cybermaps\Discovery\StaticOwnershipTable;

// WP-CLI may require this fixture from an evaluation closure.
global $wpdb;

function cybermaps_state_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function cybermaps_state_seed_option( string $name, mixed $value ): void {
	global $wpdb;
	$raw = is_array( $value ) ? serialize( $value ) : (string) $value;
	cybermaps_state_assert( false !== $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (option_name,option_value,autoload) VALUES (%s,%s,%s) ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)', $wpdb->options, $name, $raw, 'off' ) ), 'Fixture option write failed.' );
	wp_cache_delete( $name, 'options' );
	wp_cache_delete( 'notoptions', 'options' );
}
function cybermaps_state_migrate( string $lock_name, int $minimum_rounds = 1 ): int {
	$complete = false;
	for ( $round = 1; $round <= 40; ++$round ) {
		$lease = new OptionLeaseLock( $lock_name, 120, 15, true );
		cybermaps_state_assert( $lease->acquire(), 'Could not acquire real ownership lease.' );
		try {
			$store = new StaticOwnershipStore( $lease );
			$complete = $store->migrate_if_needed( static fn(): bool => $lease->maintain() );
		} finally { $lease->release(); }
		if ( $complete ) { break; }
	}
	cybermaps_state_assert( $complete && $round >= $minimum_rounds, 'Migration did not finish through expected bounded continuations.' );
	return $round;
}
function cybermaps_state_inventory(): array {
	$store = new StaticOwnershipStore();
	$after = '';
	$records = array();
	do {
		$page = $store->read_records_page( -1, $after );
		cybermaps_state_assert( is_array( $page ) && count( $page ) <= 100, 'Cursor read failed or exceeded its bound.' );
		foreach ( $page as $row ) { $records[ $row['path'] ] = array( 'hash' => $row['hash'], 'generation' => $row['generation'] ); $after = $row['key']; }
	} while ( 100 === count( $page ) );
	ksort( $records );
	return $records;
}

$original_prefix = $wpdb->prefix;
$original_options = $wpdb->options;
$prefix = $original_prefix . 'state_fixture_' . bin2hex( random_bytes( 4 ) ) . '_';
$options = $prefix . 'options';
$table = $prefix . StaticOwnershipTable::SUFFIX;
$home = home_url();
$siteurl = site_url();
$results = array();
$lease = null;
$root = rtrim( sys_get_temp_dir(), '/' ) . '/cybermaps-state-' . bin2hex( random_bytes( 6 ) );
$root_filter = static fn(): string => $root;
$block_http = static fn() => new WP_Error( 'state_fixture_http_blocked', 'External delivery disabled by fixture.' );
$instance = new ReflectionProperty( StaticBridge::class, 'instance' );
$previous_bridge = $instance->getValue();

try {
	cybermaps_state_assert( false !== $wpdb->query( $wpdb->prepare( 'CREATE TABLE %i LIKE %i', $options, $original_options ) ), 'Fixture options table could not be created.' );
	$wpdb->prefix = $prefix;
	$wpdb->options = $options;
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	foreach ( array( 'home' => $home, 'siteurl' => $siteurl, 'blog_public' => '1', 'cybermaps_static_sync_epoch' => 7 ) as $name => $value ) { cybermaps_state_seed_option( $name, $value ); }
	add_filter( 'pre_http_request', $block_http );
	$legacy = array();
	$expected = array();
	for ( $i = 0; $i < 601; ++$i ) {
		$path = 'fixture/雪-' . str_pad( (string) $i, 4, '0', STR_PAD_LEFT ) . '.txt';
		$legacy[ $path ] = md5( 'legacy-' . $i );
		$expected[ $path ] = array( 'hash' => $legacy[ $path ], 'generation' => 7 );
	}
	ksort( $expected );
	cybermaps_state_seed_option( StaticOwnershipStore::LEGACY_OPTION, $legacy );
	$results['schema_zero_rounds'] = cybermaps_state_migrate( $prefix . 'lease', 2 );
	cybermaps_state_assert( $expected === cybermaps_state_inventory(), 'Schema-zero migration changed exact bytes/hash/generation.' );
	cybermaps_state_assert( '0' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE option_name = %s', $options, StaticOwnershipStore::LEGACY_OPTION ) ), 'Legacy source was not retired after promotion.' );

	// Restart a new schema-two migration from persisted options, not request memory.
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) );
	cybermaps_state_seed_option( StaticOwnershipStore::SCHEMA_OPTION, 2 );
	$shards = array_fill( 0, 64, array() );
	foreach ( $expected as $path => $record ) { $shards[ StaticOwnershipStore::shard_for_path( $path ) ][ $path ] = $record; }
	foreach ( $shards as $shard => $records ) { cybermaps_state_seed_option( StaticOwnershipStore::shard_option_name( $shard ), $records ); }
	$results['schema_two_rounds'] = cybermaps_state_migrate( $prefix . 'lease', 2 );
	cybermaps_state_assert( $expected === cybermaps_state_inventory(), 'Schema-two migration lost rows.' );

	// A source changed between continuations must reset staging before promotion.
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) );
	cybermaps_state_seed_option( StaticOwnershipStore::SCHEMA_OPTION, 0 );
	cybermaps_state_seed_option( StaticOwnershipStore::LEGACY_OPTION, $legacy );
	$lease = new OptionLeaseLock( $prefix . 'lease', 120, 15, true );
	cybermaps_state_assert( $lease->acquire(), 'Could not acquire interrupted-copy lease.' );
	cybermaps_state_assert( ! ( new StaticOwnershipStore( $lease ) )->migrate_if_needed(), 'Large migration unexpectedly completed in one batch.' );
	$lease->release();
	$path = array_key_first( $legacy );
	$legacy[ $path ] = md5( 'changed-between-requests' );
	$expected[ $path ]['hash'] = $legacy[ $path ];
	cybermaps_state_seed_option( StaticOwnershipStore::LEGACY_OPTION, $legacy );
	$results['source_change_rounds'] = cybermaps_state_migrate( $prefix . 'lease', 2 );
	cybermaps_state_assert( $expected === cybermaps_state_inventory(), 'Changed source mixed old and new ownership rows.' );

	$lease = new OptionLeaseLock( $prefix . 'lease', 120, 15, true );
	cybermaps_state_assert( $lease->acquire(), 'Could not acquire CAS fixture lease.' );
	$first = new StaticOwnershipStore( $lease );
	$second = new StaticOwnershipStore( $lease );
	cybermaps_state_assert( $first->set_hash( 'case-sensitive/A.txt', md5( 'first' ), 1, true ), 'Initial row write failed.' );
	cybermaps_state_assert( $first->set_hash( 'case-sensitive/A.txt', md5( 'staged' ), 2 ), 'Could not stage old CAS observation.' );
	cybermaps_state_assert( $second->set_hash( 'case-sensitive/A.txt', md5( 'successor' ), 3, true ), 'Successor row write failed.' );
	cybermaps_state_assert( ! $first->flush(), 'Stale CAS replaced successor row.' );
	cybermaps_state_assert( $second->set_hash( 'case-sensitive/a.txt', md5( 'lowercase' ), 3, true ), 'Exact binary path identity collapsed case.' );
	$fence = $lease->get_database_fence();
	cybermaps_state_assert( is_array( $fence ), 'Real SQL fence missing.' );
	$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $fence['name'] ) );
	cybermaps_state_assert( ! $second->set_hash( 'after-loss.txt', md5( 'must-not-persist' ), 4, true ), 'Lost lease mutated ownership.' );
	$lease->release();
	$results['cas_and_fence'] = true;

	// Real filesystem and database: a modified owned body must survive purge.
	$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $table ) );
	cybermaps_state_seed_option( 'cybermaps_settings', array( 'static_engine_mode' => 'all', 'enable_discovery_hub' => '1' ) );
	ConfigurationStore::reset_memo();
	wp_mkdir_p( $root );
	add_filter( 'cybermaps_static_publication_root', $root_filter );
	$instance->setValue( null, null );
	$bridge = StaticBridge::get_instance();
	cybermaps_state_assert( $bridge->write_file( 'ai.json', '{"owned":true}' ), 'Real static write failed.' );
	require_once ABSPATH . 'wp-admin/includes/file.php';
	cybermaps_state_assert( WP_Filesystem(), 'Filesystem initialization failed.' );
	$GLOBALS['wp_filesystem']->put_contents( $root . '/ai.json', '{"host":"modified"}' );
	$purge = $bridge->purge_all();
	cybermaps_state_assert( isset( $purge['retained']['ai.json'] ) && 'partial' === $purge['status'], 'Foreign modification was not surfaced as retained.' );
	cybermaps_state_assert( '{"host":"modified"}' === $GLOBALS['wp_filesystem']->get_contents( $root . '/ai.json' ), 'Purge changed the foreign body.' );
	$results['foreign_body_retained'] = true;
	$results['database'] = $wpdb->db_version();
	echo wp_json_encode( array( 'complete' => true, 'cases' => $results ) ) . "\n";
} finally {
	if ( $lease instanceof OptionLeaseLock ) { $lease->release(); }
	remove_filter( 'pre_http_request', $block_http );
	remove_filter( 'cybermaps_static_publication_root', $root_filter );
	if ( isset( $GLOBALS['wp_filesystem'] ) && is_object( $GLOBALS['wp_filesystem'] ) ) { $GLOBALS['wp_filesystem']->delete( $root, true ); }
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $options ) );
	$wpdb->prefix = $original_prefix;
	$wpdb->options = $original_options;
	foreach ( array_merge( StaticOwnershipStore::all_option_names(), array( 'home', 'siteurl', 'blog_public', 'cybermaps_settings', 'alloptions', 'notoptions', 'cron' ) ) as $name ) { wp_cache_delete( $name, 'options' ); }
	ConfigurationStore::reset_memo();
	$instance->setValue( null, $previous_bridge );
}
