<?php
declare(strict_types=1);
namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\CloudflareOAuthTransactionStore;
use Cybermaps\Admin\CloudflareOptionStore;
use Cybermaps\Admin\CloudflareRuleManager;
use Cybermaps\Admin\CloudflareRulesClient;
use Cybermaps\Core\DatabaseSessionLock;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/mocks/cloudflare-database.php';

final class CloudflarePersistenceBoundaryTest extends TestCase {
	use \CybermapsCloudflareDatabaseFixture;

	protected function setUp(): void {
		parent::setUp();
		$this->install_cloudflare_database();
		$GLOBALS['cybermaps_mock_options'] = array( 'cybermaps_settings' => array( 'enable_discovery_hub' => '1', 'static_engine_mode' => 'all' ) );
		$GLOBALS['cybermaps_mock_transients'] = array();
		$GLOBALS['cybermaps_mock_transient_expirations'] = array();
		$GLOBALS['cybermaps_mock_home_url'] = 'https://example.com';
		\Cybermaps\Core\ConfigurationStore::reset_memo();
	}

	protected function tearDown(): void {
		$this->restore_cloudflare_database();
		unset( $GLOBALS['cybermaps_mock_home_url'] );
		parent::tearDown();
	}

	private function transaction( string $id ): array {
		return array( 'transaction_id' => $id, 'consume_secret' => 'secret', 'state' => 'state-' . $id, 'client_id' => 'client', 'redirect_uri' => 'https://example.com/callback' );
	}

	/** @dataProvider pointer_rows */
	public function test_reconnect_inside_pointer_sql_cannot_overwrite_successor( bool $existing ): void {
		if ( $existing ) { $GLOBALS['cybermaps_mock_options']['cybermaps_cf_oauth_pointer_42'] = 'expired-id'; }
		$old_lock = $this->cloudflare_lock();
		$old = new CloudflareOAuthTransactionStore( 42, null, $old_lock );
		$successor = null;
		$next_lock = null;
		$GLOBALS['wpdb']->before_query = function( string $sql, array $args, $database ) use ( &$successor, &$next_lock ): void {
			if ( ! str_starts_with( $sql, 'INSERT IGNORE' ) && ! str_starts_with( $sql, 'UPDATE ' ) ) { return; }
			if ( 'cybermaps_cf_oauth_pointer_42' !== $args[ str_starts_with( $sql, 'UPDATE ' ) ? 2 : 1 ] ) { return; }
			$database->before_query = null;
			$database->reconnect();
			$next_lock = new DatabaseSessionLock( 'edge-operation', ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . '|wp_options' );
			self::assertTrue( $next_lock->acquire() );
			$successor = new CloudflareOAuthTransactionStore( 42, null, $next_lock );
			$successor->begin( $this->transaction( 'b' ), 'verifier', 'install', 'custom' );
		};
		try {
			$old->begin( $this->transaction( 'a' ), 'verifier', 'install', 'custom' );
			self::fail( 'A reconnected old owner published its pointer.' );
		} catch ( \RuntimeException $error ) {
			self::assertStringContainsString( 'database lock', $error->getMessage() );
		}
		try {
			self::assertSame( 'b', $successor->current()['transaction_id'] );
			self::assertTrue( $successor->authorize_direct( 'state-b', 'code-b', 'b' ) );
			self::assertFalse( $successor->authorize_direct( 'state-a', 'code-a', 'a' ) );
			self::assertSame( 'b', $GLOBALS['cybermaps_mock_options']['cybermaps_cf_oauth_pointer_42'] );
			self::assertArrayNotHasKey( 'cybermaps_cf_oauth_42', $GLOBALS['cybermaps_mock_transients'] );
		} finally { $next_lock?->release(); }
	}

	public static function pointer_rows(): array { return array( 'absent' => array( false ), 'expired-existing' => array( true ) ); }

	public function test_pointer_cas_conflict_preserves_other_start_and_orphan_expires(): void {
		$store = new CloudflareOAuthTransactionStore( 42, null, $this->cloudflare_lock() );
		$GLOBALS['wpdb']->before_query = static function( string $sql, array $args, $database ): void {
			if ( str_starts_with( $sql, 'INSERT IGNORE' ) && 'cybermaps_cf_oauth_pointer_42' === $args[1] ) {
				$database->before_query = null;
				$GLOBALS['cybermaps_mock_options']['cybermaps_cf_oauth_pointer_42'] = 'competitor';
			}
		};
		try { $store->begin( $this->transaction( 'a' ), 'verifier', 'install' ); self::fail( 'CAS conflict accepted.' ); }
		catch ( \RuntimeException $error ) { self::assertStringContainsString( 'could not be saved', $error->getMessage() ); }
		self::assertSame( 'competitor', $GLOBALS['cybermaps_mock_options']['cybermaps_cf_oauth_pointer_42'] );
		$key = 'cybermaps_cf_oauth_42_' . hash( 'sha256', 'a' );
		self::assertLessThanOrEqual( time() + 300, $GLOBALS['cybermaps_mock_transient_expirations'][ $key ] );
	}

	private function manager( int &$mutations, bool $fail_final = false ): CloudflareRuleManager {
		$client = new CloudflareRulesClient( 'fixture-token', function( string $url, array $args ) use ( &$mutations, $fail_final ): array {
			if ( str_contains( $url, '/zones?' ) ) { return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'success' => true, 'result' => array( array( 'id' => 'zone', 'name' => 'example.com' ) ), 'result_info' => array( 'page' => 1, 'total_pages' => 1 ) ) ) ); }
			if ( 'GET' === $args['method'] ) { return array( 'response' => array( 'code' => 404 ), 'body' => '' ); }
			++$mutations;
			self::assertSame( '', CloudflareRuleManager::state()['fingerprint'] );
			$body = json_decode( $args['body'], true );
			$rules = array_map( static fn( array $rule ): array => $rule + array( 'id' => 'rule-' . $rule['ref'] ), $body['rules'] );
			if ( $fail_final ) { $GLOBALS['wpdb']->before_query = self::fail_state_writes( ... ); }
			return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'success' => true, 'result' => array( 'id' => 'ruleset', 'phase' => $body['phase'], 'rules' => $rules ) ) ) );
		} );
		return new CloudflareRuleManager( $client, null, $this->cloudflare_lock() );
	}

	private static function fail_state_writes( string $sql, array $args ): ?bool {
		return str_starts_with( $sql, 'UPDATE ' ) && 'cybermaps_cloudflare_rule_state' === $args[2] ? false : null;
	}

	private function seed_coherent_state(): void {
		$phases = ( new \ReflectionMethod( CloudflareRuleManager::class, 'desired_phases' ) )->invoke( null );
		$hash = new \ReflectionMethod( CloudflareRuleManager::class, 'rules_fingerprint' );
		$fingerprints = array();
		foreach ( $phases as $key => $rules ) { $fingerprints[ $key ] = $hash->invoke( null, $rules ); }
		update_option( 'cybermaps_cloudflare_rule_state', array( 'zone_id' => 'zone', 'phase_fingerprints' => $fingerprints, 'fingerprint' => CloudflareRuleManager::expected_fingerprint() ) );
	}

	public function test_failed_initial_read_does_not_discard_saved_phase_fingerprints_on_retry(): void {
		$this->seed_coherent_state();
		$before = get_option( 'cybermaps_cloudflare_rule_state' );
		$mutations = 0;
		$manager = $this->manager( $mutations );
		$GLOBALS['wpdb']->before_query = static function( string $sql, array $args, $database ): ?bool {
			if ( str_starts_with( $sql, 'SELECT option_value ' ) && 'cybermaps_cloudflare_rule_state' === $args[1] ) {
				$database->before_query = null;
				return false;
			}
			return null;
		};
		try { $manager->install_cache_rule(); self::fail( 'Unavailable state must stop before mutation.' ); }
		catch ( \RuntimeException $error ) { self::assertStringContainsString( 'history is unavailable', $error->getMessage() ); }
		self::assertSame( 0, $mutations );
		self::assertSame( $before, CloudflareRuleManager::state() );
	}

	public function test_failed_initial_state_write_prevents_all_remote_mutations(): void {
		$this->seed_coherent_state();
		$mutations = 0;
		$manager = $this->manager( $mutations );
		$GLOBALS['wpdb']->before_query = self::fail_state_writes( ... );
		foreach ( array( 'install_cache_rule', 'remove_rules' ) as $method ) {
			try { $manager->$method(); self::fail( 'Failed invalidation allowed remote operation.' ); }
			catch ( \RuntimeException $error ) { self::assertStringContainsString( 'could not be saved', $error->getMessage() ); }
		}
		self::assertSame( 0, $mutations );
		self::assertSame( CloudflareRuleManager::expected_fingerprint(), CloudflareRuleManager::state()['fingerprint'] );
	}

	public function test_failed_final_save_preserves_invalid_state_and_reports_incomplete(): void {
		$this->seed_coherent_state();
		$mutations = 0;
		$manager = $this->manager( $mutations, true );
		try { $manager->install_cache_rule(); self::fail( 'Unrecorded operation returned success.' ); }
		catch ( \RuntimeException $error ) { self::assertStringContainsString( 'incomplete', $error->getMessage() ); }
		self::assertSame( 1, $mutations );
		self::assertSame( '', CloudflareRuleManager::state()['fingerprint'] );
		self::assertArrayNotHasKey( 'cache_rule', CloudflareRuleManager::state()['phase_fingerprints'] );
		$GLOBALS['wpdb']->before_query = null;
		$manager->record_credential_disposition( 'api_token', 'discarded' );
		self::assertSame( '', CloudflareRuleManager::state()['fingerprint'] );
	}

	public function test_reconnected_fenced_delete_cannot_remove_successor_bytes(): void {
		$store = new CloudflareOptionStore( $this->cloudflare_lock() );
		$store->write( 'cybermaps_cf_test', null, 'owned' );
		$GLOBALS['wpdb']->before_query = static function( string $sql, array $args, $database ): void {
			if ( ! str_starts_with( $sql, 'DELETE ' ) ) { return; }
			$database->before_query = null;
			$database->reconnect();
			$GLOBALS['cybermaps_mock_options']['cybermaps_cf_test'] = 'successor';
		};
		try { $store->write( 'cybermaps_cf_test', 'owned', null ); self::fail( 'Lost owner deleted successor.' ); }
		catch ( \RuntimeException $error ) { self::assertStringContainsString( 'database lock', $error->getMessage() ); }
		self::assertSame( 'successor', CloudflareOptionStore::read( 'cybermaps_cf_test' ) );
	}

	public function test_owned_store_accepts_exact_noop_but_rejects_missing_fence_and_stale_bytes(): void {
		$store = new CloudflareOptionStore( $this->cloudflare_lock() );
		$store->write( 'cybermaps_cf_test', null, '' );
		$store->write( 'cybermaps_cf_test', '', '' );
		self::assertSame( '', CloudflareOptionStore::read( 'cybermaps_cf_test' ) );
		try { $store->write( 'cybermaps_cf_test', 'stale', 'next' ); self::fail( 'Stale bytes replaced current.' ); }
		catch ( \RuntimeException $error ) { self::assertStringContainsString( 'could not be saved', $error->getMessage() ); }
		try { ( new CloudflareOptionStore( null ) )->write( 'cybermaps_cf_test', '', 'next' ); self::fail( 'Missing fence accepted.' ); }
		catch ( \RuntimeException $error ) { self::assertStringContainsString( 'database lock', $error->getMessage() ); }
		$store->write( 'cybermaps_cf_test', '', null );
		self::assertNull( CloudflareOptionStore::read( 'cybermaps_cf_test' ) );
	}
}
