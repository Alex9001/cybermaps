<?php
/** Cloudflare complete-inventory and phase-coherence regressions. */
declare(strict_types=1);
namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\CloudflareRuleManager;
use Cybermaps\Admin\CloudflareRulesClient;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/mocks/cloudflare-database.php';

final class CloudflarePhaseStateTest extends TestCase {
	use \CybermapsCloudflareDatabaseFixture;
	protected function setUp(): void {
		$this->install_cloudflare_database();
		$GLOBALS['cybermaps_mock_home_url'] = 'https://child.example.com';
		update_option( 'cybermaps_settings', array( 'enable_discovery_hub' => '1', 'static_engine_mode' => 'all' ) );
		delete_option( 'cybermaps_cloudflare_rule_state' );
		\Cybermaps\Core\ConfigurationStore::reset_memo();
	}

	protected function tearDown(): void {
		$this->restore_cloudflare_database();
		unset( $GLOBALS['cybermaps_mock_home_url'] );
		delete_option( 'cybermaps_cloudflare_rule_state' );
		parent::tearDown();
	}

	private function reply( mixed $result, array $info = array() ): array {
		return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'success' => true, 'result' => $result, 'result_info' => $info ) ) );
	}

	public function test_incomplete_inventory_never_selects_an_early_parent_or_mutates(): void {
		foreach ( array( array( array( 'id' => 'parent', 'name' => 'example.com' ) ), array(), array( array( 'id' => 'exact-a', 'name' => 'child.example.com' ) ) ) as $early ) {
			$calls = array();
			$client = new CloudflareRulesClient( 'token', function ( string $url, array $args ) use ( &$calls, $early ): array {
				$calls[] = $args['method'];
				parse_str( parse_url( $url, PHP_URL_QUERY ), $query );
				return $this->reply( $early, array( 'page' => (int) $query['page'], 'total_pages' => 21 ) );
			} );
			try {
				( new CloudflareRuleManager( $client, null, $this->cloudflare_lock() ) )->install_cache_rule();
				self::fail( 'Incomplete inventory must not become a mutation target.' );
			} catch ( \RuntimeException $error ) {
				self::assertStringContainsString( 'inventory is incomplete', $error->getMessage() );
			}
			self::assertSame( array( 'GET' ), $calls );
		}
	}

	public function test_complete_inventory_selects_late_child_and_rejects_late_ambiguity(): void {
		foreach ( array( false, true ) as $ambiguous ) {
			$client = new CloudflareRulesClient( 'token', function ( string $url ) use ( $ambiguous ): array {
				parse_str( parse_url( $url, PHP_URL_QUERY ), $query );
				$page = (int) $query['page'];
				$rows = 1 === $page ? array( array( 'id' => 'parent', 'name' => 'example.com' ) ) : array( array( 'id' => 'child', 'name' => 'child.example.com' ) );
				if ( $ambiguous && 2 === $page ) { $rows[] = array( 'id' => 'other-account-child', 'name' => 'child.example.com' ); }
				return $this->reply( $rows, array( 'page' => $page, 'total_pages' => 2 ) );
			} );
			try {
				self::assertSame( 'child', $client->zone_for_host( 'child.example.com' )['id'] );
				self::assertFalse( $ambiguous );
			} catch ( \RuntimeException $error ) {
				self::assertTrue( $ambiguous );
				self::assertStringContainsString( 'More than one', $error->getMessage() );
			}
		}
	}

	private function manager( bool &$fail, bool &$change_config ): CloudflareRuleManager {
		return new CloudflareRuleManager( new CloudflareRulesClient( 'token', function ( string $url, array $args ) use ( &$fail, &$change_config ): array {
			if ( str_contains( $url, '/zones?' ) ) {
				return $this->reply( array( array( 'id' => 'zone', 'name' => 'example.com' ) ), array( 'page' => 1, 'total_pages' => 1 ) );
			}
			if ( 'GET' === $args['method'] ) { return array( 'response' => array( 'code' => 404 ), 'body' => '' ); }
			if ( $fail ) { return array( 'response' => array( 'code' => 500 ), 'body' => '{"success":false}' ); }
			$body = json_decode( $args['body'], true );
			$rules = array_map( static fn( array $rule ): array => $rule + array( 'id' => 'rule-' . $rule['ref'] ), $body['rules'] );
			if ( $change_config ) { $GLOBALS['cybermaps_mock_home_url'] = 'https://changed.example.com'; }
			return $this->reply( array( 'id' => 'ruleset', 'phase' => $body['phase'], 'rules' => $rules ) );
		} ), null, $this->cloudflare_lock() );
	}

	private function install_phases( CloudflareRuleManager $manager ): void {
		$desired = ( new \ReflectionMethod( CloudflareRuleManager::class, 'desired_phases' ) )->invoke( null );
		$method = new \ReflectionMethod( CloudflareRuleManager::class, 'sync_recorded_phase' );
		foreach ( $desired as $key => $rules ) {
			$method->invoke( $manager, array( 'id' => 'zone', 'name' => 'example.com' ), 'fixture-phase', $key, 'Fixture', $rules );
		}
	}

	public function test_partial_phase_repair_cannot_hide_stale_phase_records(): void {
		update_option( 'cybermaps_cloudflare_rule_state', array( 'zone_id' => 'zone', 'fingerprint' => 'stale', 'header_rules' => array( array( 'id' => 'old-header' ) ), 'origin_rule' => array( 'id' => 'old-origin' ) ) );
		$fail = false; $change = false;
		$this->manager( $fail, $change )->install_cache_rule();
		$state = CloudflareRuleManager::state();
		self::assertSame( '', $state['fingerprint'] );
		self::assertSame( array( 'cache_rule' ), array_keys( $state['phase_fingerprints'] ) );
		self::assertSame( 'old-origin', $state['origin_rule']['id'] );
	}

	public function test_all_phase_success_is_current_but_failed_repair_is_not(): void {
		$fail = false; $change = false;
		$manager = $this->manager( $fail, $change );
		$this->install_phases( $manager );
		self::assertSame( CloudflareRuleManager::expected_fingerprint(), CloudflareRuleManager::state()['fingerprint'] );
		$fail = true;
		try { $manager->install_cache_rule(); self::fail( 'Expected mutation failure.' ); } catch ( \RuntimeException $error ) { self::assertStringContainsString( 'Cloudflare API error', $error->getMessage() ); }
		self::assertSame( '', CloudflareRuleManager::state()['fingerprint'] );
		self::assertArrayNotHasKey( 'cache_rule', CloudflareRuleManager::state()['phase_fingerprints'] );
	}

	public function test_configuration_change_during_remote_operation_cannot_stamp_current(): void {
		$fail = false; $change = false;
		$manager = $this->manager( $fail, $change );
		$this->install_phases( $manager );
		$change = true;
		$manager->install_cache_rule();
		self::assertSame( '', CloudflareRuleManager::state()['fingerprint'] );
	}
}
