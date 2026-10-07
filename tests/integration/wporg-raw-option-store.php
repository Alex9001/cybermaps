<?php
declare(strict_types=1);
/** Native option CAS/fence regression. This fixture flushes only a disposable validation cache. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'CYBERMAPS_STATE_FIXTURE_DISPOSABLE' ) ) { throw new RuntimeException( 'Requires explicitly disposable WP-CLI validation.' ); }

use Cybermaps\Core\CacheManager;
use Cybermaps\Core\ConfigurationStore;
use Cybermaps\Core\DatabaseSessionLock;
use Cybermaps\Core\RawOptionStore;

global $wpdb;
$main = $wpdb;
$original = $main->options;
$table = $main->prefix . 'raw_cas_' . bin2hex( random_bytes( 4 ) );
$option = 'cybermaps_raw_native';
$prior_errors = $main->suppress_errors( true );
$checks = array();
$receipts = array();
$filter = null;
$lock = null;
$successor = null;
$assert = static function ( bool $condition, string $message ) use ( &$checks ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } $checks[] = $message; };
$mutate = static function ( string $verb, ?array $fence, string $expected = '', string $next = 'next' ) use ( $main, $option ): int|false {
	return match ( $verb ) {
		'insert' => RawOptionStore::insert( $main, $option, $next, $fence ),
		'replace' => RawOptionStore::replace( $main, $option, $expected, $next, $fence ),
		'remove' => RawOptionStore::remove( $main, $option, $expected, $fence ),
	};
};
$reset = static function ( bool $existing ) use ( $main, $table, $option, $assert ): void {
	$assert( false !== $main->query( $main->prepare( 'DELETE FROM %i WHERE option_name = %s', $table, $option ) ), 'Fixture row reset' );
	if ( $existing ) { $assert( 1 === RawOptionStore::insert( $main, $option, '' ), 'Existing empty fixture row seeded' ); }
	RawOptionStore::invalidate( $option );
};
try {
	$assert( wp_cache_flush(), 'Disposable cache cleared before isolation' );
	CacheManager::reset_runtime(); ConfigurationStore::reset_memo();
	$assert( false !== $main->query( $main->prepare( 'CREATE TABLE %i LIKE %i', $table, $original ) ), 'Isolated options table created' );
	$main->options = $table;
	$assert( 1 === $main->query( $main->prepare( 'INSERT INTO %i (option_name,option_value,autoload) VALUES (%s,%s,%s)', $table, 'fixture_autoload_control', 'retained', 'on' ) ), 'Normal autoload control seeded' );
	foreach ( array( 'insert', 'replace', 'remove' ) as $verb ) {
		foreach ( array( 'unfenced', 'owned', 'released', 'reconnected', 'unfenced_error', 'owned_error', 'missing_fence' ) as $case ) {
			$reset( 'insert' !== $verb );
			$before = RawOptionStore::read( $main, $option );
			$assert( ( 'insert' === $verb ? null : '' ) === $before, 'Initial raw authority was read exactly for ' . $verb . '/' . $case );
			$fence = null;
			if ( in_array( $case, array( 'owned', 'released', 'reconnected', 'owned_error' ), true ) ) {
				$lock = new DatabaseSessionLock( 'raw-native', DB_NAME . '|' . $table );
				$assert( $lock->acquire(), 'Real advisory lock acquired for ' . $verb . '/' . $case );
				$fence = $lock->get_fence();
				$assert( is_array( $fence ), 'Captured physical owner for ' . $verb . '/' . $case );
			}
			if ( 'missing_fence' === $case ) { $fence = array(); }
			$hits = 0;
			$filter = static function ( string $sql ) use ( $main, $table, $option, $case, $fence, $lock, $assert, &$successor, &$hits ): string {
				if ( ! preg_match( '/^(INSERT IGNORE|UPDATE|DELETE)/', $sql ) || ! str_contains( $sql, "'" . $option . "'" ) ) { return $sql; }
				++$hits;
				if ( $hits > 1 ) { throw new RuntimeException( 'Unexpected mutation retry.' ); }
				if ( 'released' === $case ) { $lock->release(); }
				if ( 'reconnected' === $case ) {
					$main->close();
					$assert( $main->db_connect( false ), 'Physical reconnect succeeds' );
					$successor = new DatabaseSessionLock( 'raw-native', DB_NAME . '|' . $table );
					$assert( $successor->acquire(), 'Successor owns the same advisory lock' );
					$assert( $successor->get_fence()['connection_id'] !== $fence['connection_id'], 'Reconnect changed physical connection' );
				}
				return str_ends_with( $case, '_error' ) ? 'CYBERMAPS_FIXTURE_INJECTED_SQL_FAILURE' : $sql;
			};
			add_filter( 'query', $filter );
			$result = $mutate( $verb, $fence );
			$sql_error = (string) $main->last_error;
			remove_filter( 'query', $filter ); $filter = null;
			$expected_result = str_ends_with( $case, '_error' ) ? false : ( in_array( $case, array( 'unfenced', 'owned' ), true ) ? 1 : 0 );
			$matches = $expected_result === $result && ( false === $expected_result ? '' !== $sql_error : '' === $sql_error );
			if ( 'missing_fence' === $case ) {
				// MariaDB rejects the empty owner with zero rows; MySQL rejects its
				// empty lock name with this explicit SQL error. Neither grants authority.
				$matches = ( 0 === $result && '' === $sql_error )
					|| ( false === $result && str_starts_with( $sql_error, "Incorrect user-level lock name ''." ) );
			}
			$assert( $matches && 1 === $hits, 'Exact affected/failure result and one mutation for ' . $verb . '/' . $case );
			$expected = 1 === $expected_result ? ( 'remove' === $verb ? null : 'next' ) : $before;
			$after = RawOptionStore::read( $main, $option );
			$assert( $expected === $after, 'Exact raw authority preserved for ' . $verb . '/' . $case );
			$receipts[] = array( 'verb' => $verb, 'case' => $case, 'result' => $result, 'result_type' => get_debug_type( $result ), 'sql_error' => $sql_error, 'before' => $before, 'after' => $after, 'mutation_statements' => $hits );
			$successor?->release(); $successor = null;
			$lock?->release(); $lock = null;
		}
	}
	$reset( false );
	$assert( 1 === RawOptionStore::insert( $main, $option, 'Case ' ), 'Exact-byte conflict row inserted' );
	$assert( 0 === RawOptionStore::insert( $main, $option, 'competitor' ), 'Competing insert is zero' );
	$assert( 0 === $mutate( 'replace', null, 'Case ', 'Case ' ), 'Same bytes return zero' );
	$assert( 0 === $mutate( 'replace', null, 'case ', 'lost' ), 'Case mismatch is zero' );
	$assert( 0 === $mutate( 'remove', null, 'Case' ), 'Trailing-space mismatch is zero' );
	$assert( 'Case ' === RawOptionStore::read( $main, $option ), 'Every conflicting mutation preserves exact bytes' );
} finally {
	if ( null !== $filter ) { remove_filter( 'query', $filter ); }
	$successor?->release(); $lock?->release();
	$dropped = $main->query( $main->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
	$remaining = $main->get_var( $main->prepare( 'SHOW TABLES LIKE %s', $main->esc_like( $table ) ) );
	$cleanup_ok = false !== $dropped && null === $remaining && '' === $main->last_error;
	$main->options = $original;
	$cache_ok = wp_cache_flush();
	CacheManager::reset_runtime(); ConfigurationStore::reset_memo();
	$main->suppress_errors( $prior_errors );
}
$assert( $cleanup_ok && $original === $main->options && $GLOBALS['wpdb'] === $main, 'Owned table removed and routing restored' );
$assert( $cache_ok, 'Disposable cache cleared after restoration' );
echo wp_json_encode( array( 'complete' => true, 'raw_option_store_passed' => true, 'database' => $main->db_version(), 'checks' => $checks, 'mutations' => $receipts ) ) . "\n";
