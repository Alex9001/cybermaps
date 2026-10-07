<?php
declare(strict_types=1);

/** Native, isolated two-connection fixture; no HTTP delivery or installed-site data edits. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'CYBERMAPS_STATE_FIXTURE_DISPOSABLE' ) ) {
	throw new RuntimeException( 'Requires explicitly disposable WP-CLI runtime.' );
}

use Cybermaps\Core\OptionLeaseLock;
use Cybermaps\Core\TranslationRegistry;
use Cybermaps\Discovery\StaticOwnershipStore;
use Cybermaps\Discovery\StaticBridge;
use Cybermaps\Discovery\StaticOwnershipTable;
use Cybermaps\Discovery\IndexNowQueueRepository;
use Cybermaps\Discovery\IndexNowQueueSchema;

// WP-CLI may require this fixture from an evaluation closure.
global $wpdb;

function cybermaps_cutover_assert( bool $ok, string $message ): void {
	if ( ! $ok ) {
		global $wpdb;
		$diagnostic = array( 'message' => $message, 'last_round' => $GLOBALS['cybermaps_cutover_last_round'] ?? null, 'attempts' => $GLOBALS['cybermaps_cutover_attempts'] ?? array(), 'error' => $wpdb->last_error, 'query' => $wpdb->last_query );
		$state = get_option( StaticOwnershipStore::MIGRATION_OPTION );
		$diagnostic['checkpoint'] = is_array( $state ) ? array_intersect_key( $state, array_flip( array( 'schema', 'phase', 'source', 'offset', 'remaining' ) ) ) : $state;
		$diagnostic['raw_authority'] = $wpdb->get_results( $wpdb->prepare( 'SELECT option_name,LEFT(option_value,1024) AS raw_value FROM %i WHERE option_name IN (%s,%s) LIMIT 2', $wpdb->options, StaticOwnershipStore::SCHEMA_OPTION, StaticOwnershipStore::LEGACY_OPTION ), ARRAY_A );
		$diagnostic['raw_error'] = $wpdb->last_error;
		echo wp_json_encode( array( 'complete' => false, 'assertion_diagnostic' => $diagnostic ) ) . "\n";
		throw new RuntimeException( $message );
	}
}
function cybermaps_cutover_seed( string $name, mixed $value ): void {
	global $wpdb;
	$raw = is_array( $value ) ? serialize( $value ) : (string) $value;
	cybermaps_cutover_assert( false !== $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (option_name,option_value,autoload) VALUES (%s,%s,%s) ON DUPLICATE KEY UPDATE option_value=VALUES(option_value)', $wpdb->options, $name, $raw, 'off' ) ), 'Seed failed.' );
	foreach ( array( $name, 'alloptions', 'notoptions' ) as $key ) { wp_cache_delete( $key, 'options' ); }
}
function cybermaps_cutover_round(): bool {
	global $wpdb;
	$lease = new OptionLeaseLock( StaticBridge::OPERATION_LOCK_OPTION, 120, 15, true );
	cybermaps_cutover_assert( $lease->acquire(), 'Native lease unavailable.' );
	try {
		$store = new StaticOwnershipStore( $lease );
		$started = microtime( true );
		$result = $store->migrate_if_needed( static fn(): bool => $lease->maintain() );
		$GLOBALS['cybermaps_cutover_last_round'] = array( 'result' => $result, 'pending' => $store->has_pending_migration(), 'seconds' => microtime( true ) - $started, 'error' => $wpdb->last_error, 'query' => $wpdb->last_query );
		return $result;
	}
	finally { $lease->release(); }
}
/** Fixed fixture rows only; raw comparison cannot be satisfied by a cached option. */
function cybermaps_cutover_authority(): array {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT option_name,LEFT(option_value,1024) AS raw_value FROM %i WHERE option_name IN (%s,%s) ORDER BY option_name LIMIT 2', $wpdb->options, StaticOwnershipStore::SCHEMA_OPTION, StaticOwnershipStore::LEGACY_OPTION ), ARRAY_A );
	cybermaps_cutover_assert( is_array( $rows ) && 2 === count( $rows ) && '' === $wpdb->last_error, 'Cannot inspect both fixture authority rows.' );
	return $rows;
}
/** Resume only explicit pending work, with fresh leases and at most five attempts. */
function cybermaps_cutover_settle(): bool {
	$authority = cybermaps_cutover_authority();
	$deadline = microtime( true ) + 20;
	$GLOBALS['cybermaps_cutover_attempts'] = array();
	for ( $attempt = 1; $attempt <= 5; ++$attempt ) {
		$result = cybermaps_cutover_round();
		$round = $GLOBALS['cybermaps_cutover_last_round'];
		$GLOBALS['cybermaps_cutover_attempts'][] = array_intersect_key( $round, array_flip( array( 'result', 'pending', 'seconds' ) ) );
		if ( $result || ! $round['pending'] ) { return $result; }
		cybermaps_cutover_assert( $authority === cybermaps_cutover_authority(), 'Pending migration changed raw source authority.' );
		$GLOBALS['cybermaps_cutover_continuations'] = ( $GLOBALS['cybermaps_cutover_continuations'] ?? 0 ) + 1;
		cybermaps_cutover_assert( microtime( true ) < $deadline, 'Pending fixture preparation exceeded its deadline.' );
	}
	cybermaps_cutover_assert( false, 'Pending fixture preparation exceeded five attempts.' );
	return false;
}
function cybermaps_cutover_legacy_preserved( string $hash ): bool {
	return array(
		array( 'option_name' => StaticOwnershipStore::LEGACY_OPTION, 'raw_value' => serialize( array( 'fixture.xml' => $hash ) ) ),
		array( 'option_name' => StaticOwnershipStore::SCHEMA_OPTION, 'raw_value' => '0' ),
	) === cybermaps_cutover_authority();
}
function cybermaps_cutover_reset( string $hash ): void {
	global $wpdb;
	foreach ( StaticOwnershipStore::all_option_names() as $name ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s', $wpdb->options, $name ) );
		wp_cache_delete( $name, 'options' );
	}
	cybermaps_cutover_seed( StaticOwnershipStore::SCHEMA_OPTION, 0 );
	cybermaps_cutover_seed( StaticOwnershipStore::LEGACY_OPTION, array( 'fixture.xml' => $hash ) );
}
function cybermaps_cutover_promotes( string $sql ): bool {
	return ( str_starts_with( $sql, 'UPDATE ' ) || str_starts_with( $sql, 'INSERT IGNORE ' ) ) && str_contains( $sql, "'" . StaticOwnershipStore::SCHEMA_OPTION . "'" );
}

$original_prefix = $wpdb->prefix;
$original_base_prefix = $wpdb->base_prefix;
$original_options = $wpdb->options;
$prefix = $original_prefix . 'cutover_' . bin2hex( random_bytes( 4 ) ) . '_';
$options = $prefix . 'options';
$table = $prefix . StaticOwnershipTable::SUFFIX;
$queue_table = $prefix . IndexNowQueueSchema::TABLE_SUFFIX;
$translation_table = $prefix . 'cybermaps_translations';
$block_http = static fn() => new WP_Error( 'fixture_no_http', 'Native state fixture blocks delivery.' );
add_filter( 'pre_http_request', $block_http );
$other = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$other->suppress_errors( true );
$previous_errors = $wpdb->suppress_errors( true );
$active_filter = null;
$results = array();
$fault_observations = array();
$GLOBALS['cybermaps_cutover_continuations'] = 0;
try {
	cybermaps_cutover_assert( false !== $wpdb->query( $wpdb->prepare( 'CREATE TABLE %i LIKE %i', $options, $original_options ) ), 'Options fixture creation failed.' );
	cybermaps_cutover_assert( false !== $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ENGINE=InnoDB', $options ) ), 'InnoDB unavailable.' );
	$wpdb->prefix = $prefix;
	$wpdb->base_prefix = $prefix;
	$wpdb->options = $options;
	// Normal WP tables retain autoloaded Core options. Without one, wp_load_alloptions()
	// falls back to caching every off row, which native delete_option() does not evict.
	cybermaps_cutover_assert( 1 === $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (option_name,option_value,autoload) VALUES (%s,%s,%s)', $options, 'fixture_autoload_control', 'retained-control', 'on' ) ), 'Autoload control seed failed.' );
	StaticOwnershipStore::register_hooks();
	foreach ( array( 'alloptions', 'notoptions' ) as $key ) { wp_cache_delete( $key, 'options' ); }

	// Failed ready promotion followed by the supported fenced Core writer, sharing the migration resource lock.
	cybermaps_cutover_reset( md5( 'old' ) );
	$promotion_hits = 0;
	$active_filter = static function ( string $sql ) use ( &$promotion_hits ): string {
		if ( cybermaps_cutover_promotes( $sql ) ) { ++$promotion_hits; return 'CYBERMAPS_FIXTURE_INJECTED_SQL_FAILURE'; }
		return $sql;
	};
	add_filter( 'query', $active_filter );
	cybermaps_cutover_assert( ! cybermaps_cutover_settle(), 'Injected promotion failure reported success.' );
	remove_filter( 'query', $active_filter ); $active_filter = null;
	cybermaps_cutover_assert( $promotion_hits > 0, 'Ready-promotion fault seam was never reached.' );
	cybermaps_cutover_assert( 0 === StaticOwnershipStore::current_schema() && 'ready' === get_option( StaticOwnershipStore::MIGRATION_OPTION )['phase'] && cybermaps_cutover_legacy_preserved( md5( 'old' ) ), 'Failed ready cutover changed legacy authority.' );
	$fault_observations['ready_promotion_hits'] = $promotion_hits;
	$writer = new OptionLeaseLock( StaticBridge::OPERATION_LOCK_OPTION, 120, 15, true );
	cybermaps_cutover_assert( $writer->acquire(), 'Supported legacy writer could not acquire the shared resource lock.' );
	try { cybermaps_cutover_assert( ( new StaticOwnershipStore( $writer ) )->set_hash( 'fixture.xml', md5( 'new' ), 0, true ), 'Supported fenced legacy write failed.' ); }
	finally { $writer->release(); }
	cybermaps_cutover_assert( ! cybermaps_cutover_round() && true === $GLOBALS['cybermaps_cutover_last_round']['pending'] && 0 === StaticOwnershipStore::current_schema() && cybermaps_cutover_legacy_preserved( md5( 'new' ) ), 'Changed ready source was promoted.' );
	cybermaps_cutover_assert( cybermaps_cutover_settle() && md5( 'new' ) === ( new StaticOwnershipStore() )->get_hash( 'fixture.xml' ), 'Restart failed to preserve new source.' );
	$results['ready_pin_restart'] = true;

	// A caller's uncommitted row must stay invisible to another session and roll back.
	cybermaps_cutover_reset( md5( 'caller' ) );
	$wpdb->query( 'START TRANSACTION' );
	cybermaps_cutover_seed( 'caller_uncommitted', 'private' );
	cybermaps_cutover_assert( ! cybermaps_cutover_settle(), 'Nested caller transaction was accepted.' );
	cybermaps_cutover_assert( null === $other->get_var( $other->prepare( 'SELECT option_value FROM %i WHERE option_name=%s', $options, 'caller_uncommitted' ) ), 'Migration implicitly committed caller work.' );
	$wpdb->query( 'ROLLBACK' );
	cybermaps_cutover_assert( null === $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name=%s', $options, 'caller_uncommitted' ) ), 'Caller rollback no longer works.' );
	$results['caller_transaction_preserved'] = true;

	// Adversarial raw writes: existing row and absent-name gap stay locked during promotion.
	// Arbitrary unfenced writes after commit are outside the Core writer contract.
	cybermaps_cutover_reset( md5( 'locked' ) );
	$other->query( 'SET SESSION innodb_lock_wait_timeout=1' );
	$observed = array();
	$active_filter = static function ( string $sql ) use ( $other, $options, &$observed ): string {
		if ( ! cybermaps_cutover_promotes( $sql ) || $observed ) { return $sql; }
		$existing = $other->query( $other->prepare( 'UPDATE %i SET option_value=%s WHERE option_name=%s', $options, serialize( array( 'fixture.xml' => md5( 'racing edit' ) ) ), StaticOwnershipStore::LEGACY_OPTION ) );
		$existing_error = $other->last_error;
		$missing = $other->query( $other->prepare( 'INSERT INTO %i (option_name,option_value,autoload) VALUES (%s,%s,%s)', $options, StaticOwnershipStore::shard_option_name( 63 ), serialize( array() ), 'off' ) );
		$observed = array( 'existing_blocked' => false === $existing && '' !== $existing_error, 'missing_blocked' => false === $missing && '' !== $other->last_error );
		return $sql;
	};
	add_filter( 'query', $active_filter );
	cybermaps_cutover_assert( cybermaps_cutover_settle(), 'Locked-source cutover failed.' );
	remove_filter( 'query', $active_filter ); $active_filter = null;
	cybermaps_cutover_assert( array( 'existing_blocked' => true, 'missing_blocked' => true ) === $observed, 'Existing-row or absent-name gap lock did not exclude the contender.' );
	cybermaps_cutover_assert( 0 === $other->query( $other->prepare( 'UPDATE %i SET option_value=%s WHERE option_name=%s', $options, 'stale update already past WP filter', StaticOwnershipStore::LEGACY_OPTION ) ), 'Queued legacy update found a retired row.' );
	$results['native_source_and_gap_exclusion'] = $observed;

	// Scoped row-lock timeout must fail closed when another transaction owns an existing source row.
	cybermaps_cutover_reset( md5( 'busy' ) );
	// Prepare before timing the lock wait; normal copy slices are unrelated to that bound.
	$busy_promotion_hits = 0;
	$active_filter = static function ( string $sql ) use ( &$busy_promotion_hits ): string {
		if ( cybermaps_cutover_promotes( $sql ) ) { ++$busy_promotion_hits; return 'CYBERMAPS_FIXTURE_INJECTED_SQL_FAILURE'; }
		return $sql;
	};
	add_filter( 'query', $active_filter );
	cybermaps_cutover_assert( ! cybermaps_cutover_settle(), 'Contended-source preparation promoted unexpectedly.' );
	remove_filter( 'query', $active_filter ); $active_filter = null;
	cybermaps_cutover_assert( $busy_promotion_hits > 0 && 'ready' === get_option( StaticOwnershipStore::MIGRATION_OPTION )['phase'] && cybermaps_cutover_legacy_preserved( md5( 'busy' ) ), 'Contended-source preparation did not preserve ready authority.' );
	$fault_observations['busy_preparation_hits'] = $busy_promotion_hits;
	$other->query( 'START TRANSACTION' );
	$other->get_var( $other->prepare( 'SELECT option_id FROM %i WHERE option_name=%s FOR UPDATE', $options, StaticOwnershipStore::LEGACY_OPTION ) );
	$started = microtime( true );
	cybermaps_cutover_assert( ! cybermaps_cutover_round() && false === $GLOBALS['cybermaps_cutover_last_round']['pending'], 'Contended source did not reach a terminal lock failure.' );
	$elapsed = microtime( true ) - $started;
	cybermaps_cutover_assert( $elapsed < 5 && 0 === StaticOwnershipStore::current_schema(), 'Row-lock timeout did not return within bounded fixture tolerance.' );
	$other->query( 'ROLLBACK' );
	cybermaps_cutover_assert( cybermaps_cutover_settle(), 'Contended-source retry failed.' );
	$results['row_lock_wait_seconds'] = $elapsed;

	// A failed COMMIT rolls back marker, revision, source retirement and checkpoint.
	cybermaps_cutover_reset( md5( 'commit' ) );
	$commit_hits = 0;
	$active_filter = static function ( string $sql ) use ( &$commit_hits ): string {
		if ( 'COMMIT' === $sql ) { ++$commit_hits; return 'CYBERMAPS_FIXTURE_INJECTED_SQL_FAILURE'; }
		return $sql;
	};
	add_filter( 'query', $active_filter );
	cybermaps_cutover_assert( ! cybermaps_cutover_settle(), 'Failed COMMIT was reported successful.' );
	remove_filter( 'query', $active_filter ); $active_filter = null;
	cybermaps_cutover_assert( $commit_hits > 0, 'COMMIT fault seam was never reached.' );
	cybermaps_cutover_assert( 0 === StaticOwnershipStore::current_schema() && array( 'fixture.xml' => md5( 'commit' ) ) === get_option( StaticOwnershipStore::LEGACY_OPTION ) && cybermaps_cutover_legacy_preserved( md5( 'commit' ) ), 'Failed COMMIT lost legacy authority.' );
	$fault_observations['commit_hits'] = $commit_hits;
	cybermaps_cutover_assert( cybermaps_cutover_settle(), 'Unchanged ready retry failed.' );
	$results['commit_rollback_and_unchanged_retry'] = true;

	// An actual wpdb reconnection after pins were locked must lose both transaction and fence.
	cybermaps_cutover_reset( md5( 'reconnect' ) );
	$reconnected = false;
	$reconnect_sessions = array();
	$active_filter = static function ( string $sql ) use ( &$reconnected, &$reconnect_sessions ): string {
		global $wpdb;
		if ( cybermaps_cutover_promotes( $sql ) && ! $reconnected ) {
			$reconnected = true;
			$reconnect_sessions['before'] = $wpdb->get_var( 'SELECT CONNECTION_ID()' );
			$wpdb->close();
			cybermaps_cutover_assert( $wpdb->db_connect( false ), 'Fixture reconnection failed.' );
			$reconnect_sessions['after'] = $wpdb->get_var( 'SELECT CONNECTION_ID()' );
		}
		return $sql;
	};
	add_filter( 'query', $active_filter );
	$reconnect_result = cybermaps_cutover_settle();
	$reconnect_diagnostic = array( 'round_result' => $reconnect_result, 'reconnected' => $reconnected, 'sessions' => $reconnect_sessions, 'round_error' => $wpdb->last_error, 'round_query' => $wpdb->last_query );
	remove_filter( 'query', $active_filter ); $active_filter = null;
	$reconnect_diagnostic['schema'] = StaticOwnershipStore::current_schema();
	$reconnect_diagnostic['legacy_api'] = get_option( StaticOwnershipStore::LEGACY_OPTION );
	$reconnect_state = get_option( StaticOwnershipStore::MIGRATION_OPTION );
	$reconnect_diagnostic['migration_phase'] = is_array( $reconnect_state ) ? ( $reconnect_state['phase'] ?? null ) : null;
	$reconnect_diagnostic['raw_authority'] = $wpdb->get_results( $wpdb->prepare( 'SELECT option_name,LEFT(option_value,1024) AS raw_value FROM %i WHERE option_name IN (%s,%s) LIMIT 2', $options, StaticOwnershipStore::SCHEMA_OPTION, StaticOwnershipStore::LEGACY_OPTION ), ARRAY_A );
	$reconnect_diagnostic['raw_error'] = $wpdb->last_error;
	cybermaps_cutover_assert( $reconnected && $reconnect_sessions['before'] !== $reconnect_sessions['after'], 'Reconnect fault seam did not change the physical connection.' );
	$reconnect_preserved = 0 === $reconnect_diagnostic['schema'] && array( 'fixture.xml' => md5( 'reconnect' ) ) === $reconnect_diagnostic['legacy_api'] && cybermaps_cutover_legacy_preserved( md5( 'reconnect' ) );
	if ( $reconnect_result || ! $reconnect_preserved ) { echo wp_json_encode( array( 'complete' => false, 'database' => $wpdb->db_version(), 'cases' => $results, 'reconnect_diagnostic' => $reconnect_diagnostic ) ) . "\n"; }
	cybermaps_cutover_assert( ! $reconnect_result, 'Reconnected cutover was reported successful.' );
	cybermaps_cutover_assert( $reconnect_preserved, 'Reconnect changed source authority.' );
	$results['actual_reconnect_closed'] = true;
	$fault_observations['reconnect_sessions'] = $reconnect_sessions;

	// Collision/deletion read failures on the real transactional registry preserve rows.
	cybermaps_cutover_assert( false !== $wpdb->query( $wpdb->prepare( 'CREATE TABLE %i LIKE %i', $translation_table, $original_base_prefix . 'cybermaps_translations' ) ), 'Translation fixture creation failed.' );
	cybermaps_cutover_assert( 1 === $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (id,group_id,site_id,item_id,item_type,lang_code) VALUES (1,1000000000002,1,100,%s,%s)', $translation_table, 'post', 'en' ) ), 'Translation seed failed.' );
	$collision_hits = 0;
	$active_filter = static function ( string $sql ) use ( $translation_table, &$collision_hits ): string {
		if ( str_contains( $sql, $translation_table ) && str_contains( $sql, 'id <>' ) ) { ++$collision_hits; return 'CYBERMAPS_FIXTURE_INJECTED_SQL_FAILURE'; }
		return $sql;
	};
	add_filter( 'query', $active_filter );
	cybermaps_cutover_assert( 0 === ( new TranslationRegistry() )->update_relationship( 0, 1, 200, 'fr' ), 'Failed collision lookup joined an unrelated group.' );
	remove_filter( 'query', $active_filter ); $active_filter = null;
	cybermaps_cutover_assert( $collision_hits > 0, 'Translation collision fault seam was never reached.' );
	$fault_observations['translation_collision_hits'] = $collision_hits;
	cybermaps_cutover_assert( '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $translation_table ) ), 'Failed collision lookup committed a new registry row.' );
	$deletion_hits = 0;
	$active_filter = static function ( string $sql ) use ( $translation_table, &$deletion_hits ): string {
		if ( str_contains( $sql, $translation_table ) && str_contains( $sql, 'SELECT group_id' ) ) { ++$deletion_hits; return 'CYBERMAPS_FIXTURE_INJECTED_SQL_FAILURE'; }
		return $sql;
	};
	add_filter( 'query', $active_filter );
	cybermaps_cutover_assert( ! ( new TranslationRegistry() )->delete_relationship( 1, 100 ), 'Failed prior-group lookup deleted the relationship.' );
	remove_filter( 'query', $active_filter ); $active_filter = null;
	cybermaps_cutover_assert( $deletion_hits > 0, 'Translation deletion fault seam was never reached.' );
	$fault_observations['translation_deletion_hits'] = $deletion_hits;
	cybermaps_cutover_assert( '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $translation_table ) ), 'Failed deletion lookup changed registry rows.' );
	$results['translation_failed_reads_preserved'] = true;

	// Real 49,999-row capacity: successor fills the last slot after admission lock loss.
	cybermaps_cutover_assert( IndexNowQueueSchema::create_table(), 'Queue fixture schema failed.' );
	for ( $offset = 0; $offset < 49999; $offset += 500 ) {
		$values = array(); $args = array( $queue_table );
		for ( $i = $offset; $i < min( 49999, $offset + 500 ); ++$i ) {
			$values[] = '(%s,%s,%s,0,0,%s,0,0,0,%s,0,0)';
			array_push( $args, hash( 'sha256', 'seed-' . $i ), 'https://example.org/seed-' . $i, 'queued', '', '' );
		}
		cybermaps_cutover_assert( false !== $wpdb->query( $wpdb->prepare( 'INSERT INTO %i (url_hash,url,state,attempts,next_attempt_at,claim_token,lease_expires_at,queued_again,last_status,last_error,created_at,updated_at) VALUES ' . implode( ',', $values ), ...$args ) ), 'Queue fixture batch seed failed.' );
	}
	$lock_name = 'cybermaps_indexnow_' . substr( hash( 'sha256', $queue_table ), 0, 40 );
	$count_seen = false; $filled = false;
	$active_filter = static function ( string $sql ) use ( &$count_seen, &$filled, $other, $queue_table, $lock_name ): string {
		global $wpdb;
		if ( str_contains( $sql, 'COUNT(*)' ) && str_contains( $sql, 'state IN' ) && str_contains( $sql, $queue_table ) ) { $count_seen = true; }
		if ( ! $count_seen || $filled || ! str_contains( $sql, 'SELECT *' ) || ! str_contains( $sql, $queue_table ) ) { return $sql; }
		$filled = true;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		cybermaps_cutover_assert( '1' === (string) $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s,0)', $lock_name ) ), 'Successor did not acquire dropped admission lock.' );
		cybermaps_cutover_assert( 1 === $other->query( $other->prepare( 'INSERT INTO %i (url_hash,url,state,attempts,next_attempt_at,claim_token,lease_expires_at,queued_again,last_status,last_error,created_at,updated_at) VALUES (%s,%s,%s,0,0,%s,0,0,0,%s,0,0)', $queue_table, hash( 'sha256', 'successor' ), 'https://example.org/successor', 'queued', '', '' ) ), 'Successor failed to fill final slot.' );
		$other->get_var( $other->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		return $sql;
	};
	add_filter( 'query', $active_filter );
	$admitted = ( new IndexNowQueueRepository() )->enqueue( array( 'https://example.org/stale-admission' ) );
	remove_filter( 'query', $active_filter ); $active_filter = null;
	$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $queue_table ) );
	cybermaps_cutover_assert( $filled && 0 === $admitted['accepted'] && 1 === $admitted['rejected'] && 50000 === $count, 'Lost admission session exceeded capacity.' );
	$results['native_admission_capacity'] = array( 'accepted' => $admitted['accepted'], 'rejected' => $admitted['rejected'], 'count' => $count );
	// The uninstall selector must delete only exact numeric pointer names on this DB engine.
	foreach ( array( 'cybermaps_cf_oauth_pointer_1', 'cybermaps_cf_oauth_pointer_22', 'cybermaps_cf_oauth_pointer_extension', 'CYBERMAPS_cf_oauth_pointer_3', 'cybermaps_cf_oauth_pointer_4_extra' ) as $name ) { cybermaps_cutover_seed( $name, 'fixture-pointer' ); }
	$pointer_cleanup = ( new ReflectionMethod( \Cybermaps\Core\Uninstaller::class, 'remove_oauth_pointers' ) )->invoke( null );
	if ( ! $pointer_cleanup ) {
		$pointer_diagnostic = array( 'cleanup_error' => $wpdb->last_error, 'cleanup_query' => $wpdb->last_query );
		$pointer_diagnostic['selected'] = ( new ReflectionMethod( \Cybermaps\Core\Uninstaller::class, 'oauth_pointer_rows' ) )->invoke( null, '', 100 );
		$pointer_diagnostic['selection_error'] = $wpdb->last_error;
		$pointer_diagnostic['selection_query'] = $wpdb->last_query;
		$pointer_diagnostic['raw_remaining'] = $wpdb->get_results( $wpdb->prepare( 'SELECT option_name,option_value FROM %i WHERE option_name IN (%s,%s,%s,%s,%s) ORDER BY option_name LIMIT 5', $options, 'cybermaps_cf_oauth_pointer_1', 'cybermaps_cf_oauth_pointer_22', 'cybermaps_cf_oauth_pointer_extension', 'CYBERMAPS_cf_oauth_pointer_3', 'cybermaps_cf_oauth_pointer_4_extra' ), ARRAY_A );
		echo wp_json_encode( array( 'complete' => false, 'database' => $wpdb->db_version(), 'cases' => $results, 'pointer_diagnostic' => $pointer_diagnostic ) ) . "\n";
	}
	cybermaps_cutover_assert( (bool) $pointer_cleanup, 'Native OAuth pointer cleanup failed.' );
	foreach ( array( 'cybermaps_cf_oauth_pointer_1', 'cybermaps_cf_oauth_pointer_22' ) as $name ) { cybermaps_cutover_assert( false === get_option( $name ), 'Numeric owned pointer remained.' ); }
	foreach ( array( 'cybermaps_cf_oauth_pointer_extension', 'CYBERMAPS_cf_oauth_pointer_3', 'cybermaps_cf_oauth_pointer_4_extra' ) as $name ) { cybermaps_cutover_assert( 'fixture-pointer' === get_option( $name ), 'Pointer selector removed a foreign name.' ); }
	cybermaps_cutover_assert( 'retained-control' === get_option( 'fixture_autoload_control' ), 'Unrelated autoload control was changed.' );
	$results['native_pointer_cleanup'] = true;
	$results['fault_observations'] = $fault_observations;
	$results['bounded_continuations'] = $GLOBALS['cybermaps_cutover_continuations'];
	echo wp_json_encode( array( 'complete' => true, 'database' => $wpdb->db_version(), 'cases' => $results ) ) . "\n";
} finally {
	unset( $GLOBALS['cybermaps_cutover_last_round'], $GLOBALS['cybermaps_cutover_attempts'], $GLOBALS['cybermaps_cutover_continuations'] );
	if ( null !== $active_filter ) { remove_filter( 'query', $active_filter ); }
	$wpdb->query( 'ROLLBACK' ); $other->query( 'ROLLBACK' ); $other->close();
	foreach ( array( $translation_table, $queue_table, $table, $options ) as $owned_table ) { $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $owned_table ) ); }
	$wpdb->prefix = $original_prefix; $wpdb->base_prefix = $original_base_prefix; $wpdb->options = $original_options;
	remove_filter( 'pre_http_request', $block_http );
	$wpdb->suppress_errors( $previous_errors );
	foreach ( array_merge( StaticOwnershipStore::all_option_names(), array( IndexNowQueueSchema::VERSION_OPTION, 'fixture_autoload_control', 'alloptions', 'notoptions' ) ) as $key ) { wp_cache_delete( $key, 'options' ); }
}
