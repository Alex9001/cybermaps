<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\CloudflareRuleManager;
use Cybermaps\Admin\SystemStatusCollector;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/mocks/cloudflare-database.php';

final class CloudflareStateAvailabilityTest extends TestCase {
	use \CybermapsCloudflareDatabaseFixture;
	private array $prior_options;
	private array $prior_server;
	private mixed $prior_home;

	protected function setUp(): void {
		$this->prior_options = $GLOBALS['cybermaps_mock_options'] ?? array();
		$this->prior_server = $_SERVER;
		$this->prior_home = $GLOBALS['cybermaps_mock_home_url'] ?? null;
		$this->install_cloudflare_database();
		$GLOBALS['cybermaps_mock_options'] = array();
		$GLOBALS['cybermaps_mock_home_url'] = 'https://example.com';
		unset( $_SERVER['HTTP_CF_RAY'] );
		\Cybermaps\Core\ConfigurationStore::reset_memo();
	}

	protected function tearDown(): void {
		$this->restore_cloudflare_database();
		$GLOBALS['cybermaps_mock_options'] = $this->prior_options;
		$_SERVER = $this->prior_server;
		if ( null === $this->prior_home ) { unset( $GLOBALS['cybermaps_mock_home_url'] ); }
		else { $GLOBALS['cybermaps_mock_home_url'] = $this->prior_home; }
		\Cybermaps\Core\ConfigurationStore::reset_memo();
	}

	private function row( string $method ): array {
		return ( new \ReflectionMethod( SystemStatusCollector::class, $method ) )->invoke( null );
	}

	public function test_absent_state_is_available_and_legacy_array_api_is_preserved(): void {
		self::assertSame( array( 'available' => true, 'state' => array() ), CloudflareRuleManager::state_observation() );
		self::assertSame( array(), CloudflareRuleManager::state() );
		self::assertSame( 'Not installed', $this->row( 'cloudflare_rules_item' )['usage'] );
		self::assertSame( 'No authorization performed', $this->row( 'cloudflare_credentials_item' )['usage'] );
	}

	public function test_unavailable_state_never_implies_absent_rules_or_no_authorization_and_recovers(): void {
		$stored = array( 'header_rules' => array( array( 'id' => 'existing-rule' ) ), 'credential_method' => 'oauth', 'credential_disposition' => 'revoke_failed' );
		update_option( 'cybermaps_cloudflare_rule_state', $stored );
		self::assertSame( 'Revocation unconfirmed', $this->row( 'cloudflare_credentials_item' )['usage'] );
		self::assertSame( 'Installed state found', $this->row( 'cloudflare_rules_item' )['availability'] );
		$GLOBALS['wpdb']->before_query = static fn( string $sql ): ?bool => str_starts_with( $sql, 'SELECT option_value ' ) ? false : null;
		self::assertFalse( CloudflareRuleManager::state_observation()['available'] );
		self::assertSame( array(), CloudflareRuleManager::state(), 'Existing callers retain the array return contract.' );
		foreach ( array( 'cloudflare_item', 'cloudflare_rules_item', 'cloudflare_credentials_item' ) as $method ) {
			$row = $this->row( $method );
			self::assertSame( 'unknown', $row['state'] );
			self::assertSame( 'Unknown', $row['usage'] );
			self::assertStringContainsString( 'could not be read', $row['detail'] );
			self::assertStringNotContainsString( 'Injected', $row['detail'] );
		}
		self::assertSame( $stored, $GLOBALS['cybermaps_mock_options']['cybermaps_cloudflare_rule_state'] );
		$GLOBALS['wpdb']->before_query = null;
		self::assertSame( $stored, CloudflareRuleManager::state_observation()['state'] );
		self::assertSame( 'Revocation unconfirmed', $this->row( 'cloudflare_credentials_item' )['usage'] );
	}

	public function test_malformed_stored_state_is_unknown_rather_than_absent(): void {
		update_option( 'cybermaps_cloudflare_rule_state', 'invalid stored state' );
		self::assertFalse( CloudflareRuleManager::state_observation()['available'] );
		self::assertSame( 'unknown', $this->row( 'cloudflare_rules_item' )['state'] );
	}
}
