<?php
declare(strict_types=1);

/**
 * Native two-session fixture. Require from WP-CLI eval in a disposable site:
 * CYBERMAPS_CLOUDFLARE_FIXTURE=1 wp eval 'require "/candidate/tests/integration/cloudflare-persistence.php";'
 * No provider request leaves this process. Never run beside a real edge operation.
 */
if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'CYBERMAPS_CLOUDFLARE_FIXTURE' ) ) {
	throw new RuntimeException( 'Explicit disposable WordPress CLI fixture opt-in is required.' );
}

use Cybermaps\Admin\CloudflareOAuthTransactionStore;
use Cybermaps\Admin\CloudflareOptionStore;
use Cybermaps\Admin\CloudflareRuleManager;
use Cybermaps\Admin\CloudflareRulesClient;
use Cybermaps\Core\DatabaseSessionLock;
use Cybermaps\Core\RawOptionStore;

global $wpdb;
if ( 'wpdb' !== get_class( $wpdb ) || defined( 'CYBERMAPS_PHPUNIT' ) ) {
	throw new RuntimeException( 'This fixture requires unmodified native wpdb sessions, not the PHPUnit adapter.' );
}
$cf_fixture_a = $wpdb;
$cf_fixture_b = new wpdb( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
$cf_fixture_b->set_prefix( $cf_fixture_a->prefix );
$cf_fixture_scope = DB_NAME . '|' . $cf_fixture_a->options;
$cf_fixture_results = array();
$cf_fixture_pointers = array();
$cf_fixture_records = array();
$cf_fixture_locks = array();
$cf_fixture_state_option = 'cybermaps_cloudflare_rule_state';
$cf_fixture_state_before = RawOptionStore::read( $cf_fixture_a, $cf_fixture_state_option );
if ( false === $cf_fixture_state_before ) {
	$cf_fixture_b->close();
	throw new RuntimeException( 'Cannot snapshot the owned rule state.' );
}
$cf_fixture_state_seeded = false;
$cf_fixture_assert = static function ( bool $condition, string $name ) use ( &$cf_fixture_results ): void {
	if ( ! $condition ) { throw new LogicException( $name ); }
	$cf_fixture_results[] = $name;
};
$cf_fixture_transaction = static fn( string $id ): array => array(
	'transaction_id' => $id, 'consume_secret' => 'fixture-consume-secret', 'state' => 'fixture-state-' . $id,
	'client_id' => 'fixture-client', 'redirect_uri' => 'https://example.invalid/fixture-callback',
);
$cf_fixture_requests = 0;
$cf_fixture_mutations = 0;
$cf_fixture_http = static function ( mixed $preempt, array $args, string $url ) use ( &$cf_fixture_requests, &$cf_fixture_mutations ): mixed {
	++$cf_fixture_requests;
	if ( 'GET' !== ( $args['method'] ?? 'GET' ) ) { ++$cf_fixture_mutations; }
	if ( 'GET' === ( $args['method'] ?? 'GET' ) && str_starts_with( $url, 'https://api.cloudflare.com/client/v4/zones?' ) ) {
		return array( 'headers' => array(), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => wp_json_encode( array(
			'success' => true, 'result' => array( array( 'id' => 'fixture-zone', 'name' => CloudflareRuleManager::public_host() ) ),
			'result_info' => array( 'page' => 1, 'total_pages' => 1 ),
		) ) );
	}
	return new WP_Error( 'cybermaps_fixture_http_blocked', 'All external HTTP is blocked by the persistence fixture.' );
};
add_filter( 'pre_http_request', $cf_fixture_http, PHP_INT_MAX, 3 );

try {
	$cf_fixture_assert( $cf_fixture_a->get_var( 'SELECT CONNECTION_ID()' ) !== $cf_fixture_b->get_var( 'SELECT CONNECTION_ID()' ), 'Two distinct native database sessions exist' );
	foreach ( array( false, true ) as $cf_fixture_existing ) {
		$cf_fixture_user = random_int( 100000000, 2000000000 );
		$cf_fixture_pointer = CloudflareOAuthTransactionStore::POINTER_PREFIX . $cf_fixture_user;
		$cf_fixture_assert( null === RawOptionStore::read( $cf_fixture_a, $cf_fixture_pointer ), 'Unique fixture pointer does not exist' );
		$cf_fixture_pointers[] = $cf_fixture_pointer;
		if ( $cf_fixture_existing ) {
			$cf_fixture_assert( 1 === RawOptionStore::insert( $cf_fixture_a, $cf_fixture_pointer, 'fixture-expired-record' ), 'Expired pointer seed succeeds' );
		}
		$cf_fixture_lock_a = new DatabaseSessionLock( 'edge-operation', $cf_fixture_scope );
		$cf_fixture_locks[] = array( $cf_fixture_a, $cf_fixture_lock_a );
		$cf_fixture_assert( $cf_fixture_lock_a->acquire(), 'A acquires the real operation lock' );
		$cf_fixture_fence_a = $cf_fixture_lock_a->get_fence();
		$cf_fixture_store_a = new CloudflareOAuthTransactionStore( $cf_fixture_user, null, $cf_fixture_lock_a );
		$cf_fixture_id_a = 'fixture-a-' . wp_generate_uuid4();
		$cf_fixture_id_b = 'fixture-b-' . wp_generate_uuid4();
		foreach ( array( $cf_fixture_id_a, $cf_fixture_id_b ) as $cf_fixture_id ) {
			$cf_fixture_records[] = 'cybermaps_cf_oauth_' . $cf_fixture_user . '_' . hash( 'sha256', $cf_fixture_id );
		}
		$cf_fixture_fired = false;
		$cf_fixture_store_b = null;
		$cf_fixture_lock_b = null;
		$cf_fixture_interleave = static function ( string $sql ) use (
			&$cf_fixture_fired, &$cf_fixture_store_b, &$cf_fixture_lock_b, &$cf_fixture_locks,
			$cf_fixture_a, $cf_fixture_b, $cf_fixture_scope, $cf_fixture_pointer, $cf_fixture_user,
			$cf_fixture_id_b, $cf_fixture_transaction, $cf_fixture_assert, $cf_fixture_fence_a
		): string {
			if ( $cf_fixture_fired || ! str_contains( $sql, "'{$cf_fixture_pointer}'" ) || ! str_contains( $sql, 'IS_USED_LOCK(' ) || ( ! str_starts_with( $sql, 'INSERT IGNORE ' ) && ! str_starts_with( $sql, 'UPDATE ' ) ) ) { return $sql; }
			$cf_fixture_fired = true;
			// This closes the real physical connection after the production guard,
			// releases its server-side named lock, and resumes the same prepared SQL
			// on a new connection. The captured old connection ID remains in $sql.
			$cf_fixture_assert( $cf_fixture_a->close() && $cf_fixture_a->db_connect( false ), 'A really closes and reconnects before pointer mutation' );
			$cf_fixture_assert( (int) $cf_fixture_a->get_var( 'SELECT CONNECTION_ID()' ) !== $cf_fixture_fence_a['connection_id'], 'A has a different physical connection ID' );
			$GLOBALS['wpdb'] = $cf_fixture_b;
			try {
				$cf_fixture_lock_b = new DatabaseSessionLock( 'edge-operation', $cf_fixture_scope );
				$cf_fixture_locks[] = array( $cf_fixture_b, $cf_fixture_lock_b );
				$cf_fixture_assert( $cf_fixture_lock_b->acquire(), 'B acquires A released named lock' );
				$cf_fixture_store_b = new CloudflareOAuthTransactionStore( $cf_fixture_user, null, $cf_fixture_lock_b );
				$cf_fixture_store_b->begin( $cf_fixture_transaction( $cf_fixture_id_b ), 'fixture-verifier-b', 'install', 'custom', 'example.invalid' );
			} finally { $GLOBALS['wpdb'] = $cf_fixture_a; }
			return $sql;
		};
		add_filter( 'query', $cf_fixture_interleave, PHP_INT_MAX );
		try {
			$cf_fixture_store_a->begin( $cf_fixture_transaction( $cf_fixture_id_a ), 'fixture-verifier-a', 'install', 'custom', 'example.invalid' );
			throw new LogicException( 'The reconnected old owner incorrectly completed begin.' );
		} catch ( RuntimeException $error ) {
			$cf_fixture_assert( $cf_fixture_fired && $cf_fixture_lock_a->is_lost(), 'Old owner fails closed after its pointer SQL resumes' );
		} finally { remove_filter( 'query', $cf_fixture_interleave, PHP_INT_MAX ); }
		$cf_fixture_assert( $cf_fixture_id_b === CloudflareOptionStore::read( $cf_fixture_pointer ), 'Authoritative successor pointer survives stale SQL' );
		$cf_fixture_assert( $cf_fixture_id_b === get_option( $cf_fixture_pointer ), 'Native option cache sees the surviving pointer' );
		$GLOBALS['wpdb'] = $cf_fixture_b;
		try {
			$cf_fixture_assert( $cf_fixture_id_b === $cf_fixture_store_b->current()['transaction_id'], 'Successor transaction remains current' );
			$cf_fixture_assert( $cf_fixture_store_b->authorize_direct( 'fixture-state-' . $cf_fixture_id_b, 'fixture-code', $cf_fixture_id_b ), 'Successor callback is accepted' );
			$cf_fixture_assert( ! $cf_fixture_store_b->authorize_direct( 'fixture-state-' . $cf_fixture_id_a, 'fixture-code', $cf_fixture_id_a ), 'Stale predecessor callback is refused' );
			$cf_fixture_lock_b->release();
		} finally { $GLOBALS['wpdb'] = $cf_fixture_a; }
		$cf_fixture_lock_a->release();
	}

	$cf_fixture_lock = new DatabaseSessionLock( 'edge-operation', $cf_fixture_scope );
	$cf_fixture_locks[] = array( $cf_fixture_a, $cf_fixture_lock );
	$cf_fixture_assert( $cf_fixture_lock->acquire(), 'State test acquires the operation lock' );
	$cf_fixture_phases = ( new ReflectionMethod( CloudflareRuleManager::class, 'desired_phases' ) )->invoke( null );
	$cf_fixture_hash = new ReflectionMethod( CloudflareRuleManager::class, 'rules_fingerprint' );
	$cf_fixture_fingerprints = array();
	foreach ( $cf_fixture_phases as $cf_fixture_key => $cf_fixture_rules ) { $cf_fixture_fingerprints[ $cf_fixture_key ] = $cf_fixture_hash->invoke( null, $cf_fixture_rules ); }
	$cf_fixture_seed = (string) maybe_serialize( array( 'schema' => 4, 'zone_id' => 'fixture-zone', 'phase_fingerprints' => $cf_fixture_fingerprints, 'fingerprint' => CloudflareRuleManager::expected_fingerprint() ) );
	( new CloudflareOptionStore( $cf_fixture_lock ) )->write( $cf_fixture_state_option, $cf_fixture_state_before, $cf_fixture_seed );
	$cf_fixture_state_seeded = true;
	$cf_fixture_failures = 0;
	$cf_fixture_fail = static function ( string $sql ) use ( &$cf_fixture_failures, $cf_fixture_state_option ): string {
		if ( str_starts_with( $sql, 'UPDATE ' ) && str_contains( $sql, "'{$cf_fixture_state_option}'" ) && str_contains( $sql, 'IS_USED_LOCK(' ) ) {
			++$cf_fixture_failures;
			// A real SQL error on the exact owned-option UPDATE, with no other row touched.
			return str_replace( 'SET option_value =', 'SET cybermaps_fixture_nonexistent_column =', $sql );
		}
		return $sql;
	};
	$cf_fixture_suppress = $cf_fixture_a->suppress_errors( true );
	add_filter( 'query', $cf_fixture_fail, PHP_INT_MAX );
	try {
		$cf_fixture_manager = new CloudflareRuleManager( new CloudflareRulesClient( 'fixture-only-token' ), null, $cf_fixture_lock );
		foreach ( array( 'install_cache_rule', 'remove_rules' ) as $cf_fixture_method ) {
			try {
				$cf_fixture_manager->$cf_fixture_method();
				throw new LogicException( 'Failed invalidation was incorrectly accepted.' );
			} catch ( RuntimeException $error ) {
				$cf_fixture_assert( str_contains( $error->getMessage(), 'incomplete' ), $cf_fixture_method . ': persistence failure is reported incomplete' );
			}
		}
	} finally {
		remove_filter( 'query', $cf_fixture_fail, PHP_INT_MAX );
		$cf_fixture_a->suppress_errors( $cf_fixture_suppress );
	}
	$cf_fixture_assert( 2 === $cf_fixture_failures, 'Both install and remove hit an actual failed state SQL write' );
	$cf_fixture_assert( 0 === $cf_fixture_mutations && $cf_fixture_requests > 0, 'Intercepted provider receives zero mutations after failed invalidation' );
	$cf_fixture_assert( $cf_fixture_seed === RawOptionStore::read( $cf_fixture_a, $cf_fixture_state_option ), 'Untouched provider retains the prior coherent local state' );
} finally {
	$GLOBALS['wpdb'] = $cf_fixture_a;
	if ( $cf_fixture_state_seeded ) {
		$current = RawOptionStore::read( $cf_fixture_a, $cf_fixture_state_option );
		if ( is_string( $current ) ) {
			$restored = null === $cf_fixture_state_before
				? RawOptionStore::remove( $cf_fixture_a, $cf_fixture_state_option, $current )
				: RawOptionStore::replace( $cf_fixture_a, $cf_fixture_state_option, $current, $cf_fixture_state_before );
			RawOptionStore::invalidate( $cf_fixture_state_option );
			$cf_fixture_assert( false !== $restored && $cf_fixture_state_before === RawOptionStore::read( $cf_fixture_a, $cf_fixture_state_option ), 'Original exact rule-state bytes restored' );
		}
	}
	foreach ( $cf_fixture_records as $key ) { delete_transient( $key ); }
	foreach ( $cf_fixture_pointers as $option ) {
		delete_option( $option );
		$cf_fixture_assert( null === RawOptionStore::read( $cf_fixture_a, $option ), 'Fixture pointer removed' );
	}
	foreach ( array_reverse( $cf_fixture_locks ) as [ $database, $lock ] ) {
		$GLOBALS['wpdb'] = $database;
		$lock->release();
	}
	$GLOBALS['wpdb'] = $cf_fixture_a;
	$cf_fixture_b->close();
	remove_filter( 'pre_http_request', $cf_fixture_http, PHP_INT_MAX );
}
echo wp_json_encode( array( 'passed' => count( $cf_fixture_results ), 'checks' => $cf_fixture_results, 'provider_mutations' => $cf_fixture_mutations, 'external_object_cache' => wp_using_ext_object_cache() ) ) . "\n";
