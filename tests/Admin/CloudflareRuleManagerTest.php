<?php
/**
 * Cloudflare rule ownership and provider-envelope tests.
 *
 * @package Cybermaps\Tests\Admin
 */

declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\CloudflareRuleManager;
use Cybermaps\Admin\CloudflareRulesClient;
use PHPUnit\Framework\TestCase;

final class CloudflareRuleManagerTest extends TestCase {
	protected function tearDown(): void {
		unset( $GLOBALS['cybermaps_mock_home_url'] );
		parent::tearDown();
	}

	private function rule( string $home, string $method = 'origin_bypass_rule' ): array {
		$GLOBALS['cybermaps_mock_home_url'] = $home;
		return ( new \ReflectionMethod( CloudflareRuleManager::class, $method ) )->invoke( null, parse_url( $home, PHP_URL_HOST ) );
	}

	private function invoke( CloudflareRuleManager $manager, string $method, mixed ...$args ): mixed {
		return ( new \ReflectionMethod( CloudflareRuleManager::class, $method ) )->invoke( $manager, ...$args );
	}

	private function client( array &$ruleset, array &$calls, int $fail_on = 0, bool $fail_rollback = false ): CloudflareRulesClient {
		return new CloudflareRulesClient(
			str_repeat( 't', 32 ),
			static function ( string $url, array $args ) use ( &$ruleset, &$calls, $fail_on, $fail_rollback ): array {
				$method  = $args['method'];
				$body    = isset( $args['body'] ) ? json_decode( $args['body'], true ) : null;
				$calls[] = array( 'method' => $method, 'url' => $url, 'body' => $body );
				if ( count( $calls ) === $fail_on || ( $fail_rollback && 'DELETE' === $method ) ) {
					return array( 'response' => array( 'code' => 500 ), 'body' => '{"success":false}' );
				}
				if ( 'POST' === $method ) {
					$ruleset['rules'][] = array_merge( $body, array( 'id' => 'rule-' . count( $calls ) ) );
				}
				foreach ( $ruleset['rules'] as $index => $rule ) {
					if ( ! str_ends_with( $url, '/rules/' . $rule['id'] ) ) {
						continue;
					}
					if ( 'DELETE' === $method ) {
						unset( $ruleset['rules'][ $index ] );
					} elseif ( 'PATCH' === $method ) {
						$ruleset['rules'][ $index ] = array_merge( $body, array( 'id' => $rule['id'] ) );
					}
				}
				$ruleset['rules'] = array_values( $ruleset['rules'] );
				return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'success' => true, 'result' => $ruleset ) ) );
			}
		);
	}

	public function test_origin_rule_tracks_public_path_and_site_identity(): void {
		$a = $this->rule( 'https://www.example.com/first' );
		$b = $this->rule( 'https://www.example.com/second' );
		self::assertNotSame( $a['ref'], $b['ref'] );
		self::assertStringStartsWith( 'cybermaps_discovery_origin_bypass_v1_', $a['ref'] );
		self::assertStringContainsString( 'http.request.uri.path eq "/first/ai-discovery"', $a['expression'] );
		self::assertStringContainsString( 'http.request.uri.query eq ""', $a['expression'] );
		$cache = $this->rule( 'https://www.example.com/first', 'cache_safety_rule' );
		self::assertStringContainsString( 'starts_with(http.request.uri.path, "/first/")', $cache['expression'] );
	}

	public function test_cache_bypass_case_folds_every_accept_header_value(): void {
		$cache = $this->rule( 'https://example.com/site', 'cache_safety_rule' );
		self::assertStringContainsString( 'any(lower(http.request.headers["accept"][*])[*] contains "text/markdown")', $cache['expression'] );
		self::assertSame( array( 'cache' => false ), $cache['action_parameters'] );
		foreach ( array( 'text/markdown', 'Text/Markdown', 'TEXT/MARKDOWN; q=1, text/html;q=0.5' ) as $accept ) {
			self::assertTrue( \Cybermaps\Discovery\AcceptNegotiator::prefers_markdown( $accept ), $accept );
			self::assertTrue( str_contains( strtolower( $accept ), 'text/markdown' ), $accept );
		}
		self::assertFalse( str_contains( strtolower( 'application/json, text/html' ), 'text/markdown' ) );
		self::assertStringContainsString( 'http.host eq "example.com"', $cache['expression'] );
		self::assertStringContainsString( 'starts_with(http.request.uri.path, "/site/")', $cache['expression'] );
	}

	public function test_install_update_and_remove_preserve_another_sites_rule(): void {
		foreach ( array( array( 'https://a.example.com', 'https://b.example.com' ), array( 'https://example.com/a', 'https://example.com/b' ) ) as $homes ) {
			$a       = $this->rule( $homes[0] ) + array( 'id' => 'site-a' );
			$b       = $this->rule( $homes[1] );
			$ruleset = array( 'id' => 'ruleset-id', 'rules' => array( $a ) );
			$calls   = array();
			$manager = new CloudflareRuleManager( $this->client( $ruleset, $calls ) );
			$records = $this->invoke( $manager, 'upsert_rules', 'zone', $ruleset, array( $b ) );
			self::assertSame( 'rule-1', $records[0]['id'] );
			self::assertSame( $b['ref'], $records[0]['ref'] );
			$this->invoke( $manager, 'upsert_rules', 'zone', $ruleset, array( $b ) );
			$this->invoke( $manager, 'remove_owned_from_ruleset', 'zone', $ruleset );
			self::assertSame( array( $a ), $ruleset['rules'] );
			self::assertSame( array( 'POST', 'PATCH', 'DELETE' ), array_column( $calls, 'method' ) );
		}
	}

	public function test_exact_legacy_rule_is_adopted_but_sibling_legacy_is_preserved(): void {
		$desired = $this->rule( 'https://a.example.com' );
		$legacy  = array_merge( $desired, array( 'id' => 'old-a', 'ref' => 'cybermaps_discovery_origin_bypass_v1' ) );
		$ruleset = array( 'id' => 'ruleset-id', 'rules' => array( $legacy ) );
		$calls   = array();
		$manager = new CloudflareRuleManager( $this->client( $ruleset, $calls ) );
		$this->invoke( $manager, 'upsert_rules', 'zone', $ruleset, array( $desired ) );
		self::assertSame( 'PATCH', $calls[0]['method'] );
		self::assertSame( $desired['ref'], $ruleset['rules'][0]['ref'] );
		$b = $this->rule( 'https://b.example.com' );
		$ruleset['rules'] = array( $legacy );
		$calls = array();
		$this->invoke( $manager, 'upsert_rules', 'zone', $ruleset, array( $b ) );
		self::assertSame( $legacy, $ruleset['rules'][0] );
		self::assertSame( 'POST', $calls[0]['method'] );
	}

	public function test_changed_legacy_scope_is_a_conflict_without_mutations(): void {
		$desired = $this->rule( 'https://a.example.com' );
		$legacy  = array_merge( $desired, array( 'id' => 'old-a', 'ref' => 'cybermaps_discovery_origin_bypass_v1', 'expression' => '(http.host eq "a.example.com" and true)' ) );
		$ruleset = array( 'id' => 'ruleset-id', 'rules' => array( $legacy ) );
		$calls   = array();
		$manager = new CloudflareRuleManager( $this->client( $ruleset, $calls ) );
		try {
			$this->invoke( $manager, 'upsert_rules', 'zone', $ruleset, array( $desired ) );
			self::fail( 'Expected ownership conflict.' );
		} catch ( \RuntimeException $error ) {
			self::assertStringContainsString( 'cannot be safely adopted', $error->getMessage() );
		}
		self::assertSame( array(), $calls );
	}

	public function test_rollback_deletes_created_rule_id_not_ruleset_id(): void {
		$desired = $this->rule( 'https://a.example.com' );
		$second = $this->rule( 'https://a.example.com', 'cache_safety_rule' );
		$ruleset = array( 'id' => 'ruleset-id', 'rules' => array() );
		$calls = array();
		$manager = new CloudflareRuleManager( $this->client( $ruleset, $calls, 2 ) );
		try {
			$this->invoke( $manager, 'upsert_rules', 'zone', $ruleset, array( $desired, $second ) );
			self::fail( 'Expected provider failure.' );
		} catch ( \RuntimeException $error ) {
			self::assertStringContainsString( 'Cloudflare API error', $error->getMessage() );
		}
		self::assertSame( 'DELETE', $calls[2]['method'] );
		self::assertStringEndsWith( '/rules/rule-1', $calls[2]['url'] );
		self::assertSame( array(), $ruleset['rules'] );
	}

	public function test_rollback_failure_is_reported_for_manual_reconciliation(): void {
		$desired = $this->rule( 'https://a.example.com' );
		$second = $this->rule( 'https://a.example.com', 'cache_safety_rule' );
		$ruleset = array( 'id' => 'ruleset-id', 'rules' => array() );
		$calls = array();
		$manager = new CloudflareRuleManager( $this->client( $ruleset, $calls, 2, true ) );
		$this->expectExceptionMessage( 'some changes could not be restored' );
		$this->invoke( $manager, 'upsert_rules', 'zone', $ruleset, array( $desired, $second ) );
	}

	public function test_lost_operation_guard_prevents_provider_mutation(): void {
		$desired = $this->rule( 'https://a.example.com' );
		$ruleset = array( 'id' => 'ruleset-id', 'rules' => array() );
		$calls = array();
		$manager = new CloudflareRuleManager( $this->client( $ruleset, $calls ), static function (): never {
			throw new \RuntimeException( 'Operation lock lost.' );
		} );
		try {
			$this->invoke( $manager, 'upsert_rules', 'zone', $ruleset, array( $desired ) );
			self::fail( 'A lost lock must abort the provider operation.' );
		} catch ( \RuntimeException $error ) {
			self::assertSame( 'Operation lock lost.', $error->getMessage() );
		}
		self::assertSame( array(), $calls );
	}

	public function test_missing_or_duplicate_mutated_rule_is_rejected(): void {
		foreach ( array( array(), array( array( 'ref' => 'expected', 'id' => 'one' ), array( 'ref' => 'expected', 'id' => 'two' ) ) ) as $rules ) {
			$client = new CloudflareRulesClient( 'token', static fn(): array => array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'success' => true, 'result' => array( 'id' => 'ruleset', 'rules' => $rules ) ) ) ) );
			try {
				$client->create_rule( 'zone', 'ruleset', array( 'ref' => 'expected' ) );
				self::fail( 'Missing or ambiguous rule must not be accepted.' );
			} catch ( \RuntimeException $error ) {
				self::assertStringContainsString( 'did not identify', $error->getMessage() );
			}
		}
	}
}
