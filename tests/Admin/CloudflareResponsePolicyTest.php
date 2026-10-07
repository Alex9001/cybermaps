<?php
/** Cloudflare phase-envelope and origin-policy regressions (no provider calls). */
declare(strict_types=1);
namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\CloudflareRuleManager;
use Cybermaps\Admin\CloudflareRulesClient;
use Cybermaps\Core\ConfigurationStore;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/mocks/cloudflare-database.php';

final class CloudflareResponsePolicyTest extends TestCase {
	use \CybermapsCloudflareDatabaseFixture;
	protected function setUp(): void {
		$this->install_cloudflare_database();
		$GLOBALS['cybermaps_mock_home_url'] = 'https://example.com';
		update_option( 'cybermaps_settings', array( 'enable_discovery_hub' => '1', 'static_engine_mode' => 'all' ) );
		delete_option( 'cybermaps_cloudflare_rule_state' );
		ConfigurationStore::reset_memo();
	}

	protected function tearDown(): void {
		$this->restore_cloudflare_database();
		unset( $GLOBALS['cybermaps_mock_home_url'] );
		delete_option( 'cybermaps_cloudflare_rule_state' );
		parent::tearDown();
	}

	private function invoke( string $method, mixed ...$arguments ): mixed {
		return ( new \ReflectionMethod( CloudflareRuleManager::class, $method ) )->invoke( null, ...$arguments );
	}

	private function response( array $result ): array {
		return array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'success' => true, 'result' => $result ) ) );
	}

	public function test_new_phase_requires_every_owned_id_and_matching_rule_semantics(): void {
		$rules = $this->invoke( 'header_rules', 'example.com' );
		$valid = array( 'id' => 'ruleset', 'phase' => 'response-phase', 'rules' => array_map( static fn( array $rule, int $index ): array => $rule + array( 'id' => 'rule-' . $index ), $rules, array_keys( $rules ) ) );
		$cases = array( array( 'id' => 'ruleset' ), $valid );
		$cases[1]['rules'] = array();
		foreach ( array( 'id' => '', 'ref' => 'unrelated', 'expression' => 'true', 'enabled' => ! $valid['rules'][0]['enabled'], 'action_parameters' => array() ) as $field => $value ) {
			$case = $valid;
			$case['rules'][0][$field] = $value;
			$cases[] = $case;
		}
		$case = $valid; $case['rules'][1]['id'] = $case['rules'][0]['id']; $cases[] = $case;
		$case = $valid; $case['phase'] = 'wrong-phase'; $cases[] = $case;
		$case = $valid; array_pop( $case['rules'] ); $cases[] = $case;
		foreach ( $cases as $result ) {
			$client = new CloudflareRulesClient( 'fixture-token', fn(): array => $this->response( $result ) );
			try { $client->create_phase_ruleset( 'zone', 'response-phase', 'Fixture', $rules ); self::fail( 'Malformed phase was accepted.' ); }
			catch ( \RuntimeException $error ) { self::assertStringContainsString( 'Cloudflare', $error->getMessage() ); }
		}
		$client = new CloudflareRulesClient( 'fixture-token', fn(): array => $this->response( $valid ) );
		self::assertSame( $valid, $client->create_phase_ruleset( 'zone', 'response-phase', 'Fixture', $rules ) );
	}

	public function test_empty_success_never_advances_any_phase_fingerprint(): void {
		$client = new CloudflareRulesClient( 'fixture-token', function ( string $url, array $args ): array {
			if ( 'GET' === $args['method'] ) { return array( 'response' => array( 'code' => 404 ), 'body' => '' ); }
			return $this->response( array( 'id' => 'ruleset-with-no-returned-rules' ) );
		} );
		$manager = new CloudflareRuleManager( $client, null, $this->cloudflare_lock() );
		$sync = new \ReflectionMethod( CloudflareRuleManager::class, 'sync_recorded_phase' );
		foreach ( $this->invoke( 'desired_phases' ) as $key => $rules ) {
			try { $sync->invoke( $manager, array( 'id' => 'zone', 'name' => 'example.com' ), 'phase', $key, 'Fixture', $rules ); self::fail( 'Missing rules accepted.' ); }
			catch ( \RuntimeException $error ) { self::assertStringContainsString( 'complete created ruleset', $error->getMessage() ); }
			$state = CloudflareRuleManager::state();
			self::assertSame( '', $state['fingerprint'] );
			self::assertArrayNotHasKey( $key, $state['phase_fingerprints'] );
		}
	}

	public function test_plan_preserves_origin_headers_errors_and_dynamic_endpoints(): void {
		$rules = $this->invoke( 'header_rules', 'example.com' );
		self::assertCount( 6, $rules );
		$enabled = array_filter( $rules, static fn( array $rule ): bool => $rule['enabled'] );
		self::assertNotEmpty( $enabled );
		foreach ( $rules as $rule ) {
			self::assertSame( array( 'Content-Type', 'X-Cybermaps-Cloudflare-Rule' ), array_keys( $rule['action_parameters']['headers'] ) );
			self::assertStringContainsString( 'http.response.code eq 200', $rule['expression'] );
			self::assertStringContainsString( 'http.request.uri.query eq ""', $rule['expression'] );
			self::assertStringContainsString( 'not http.request.headers.truncated', $rule['expression'] );
			self::assertStringContainsString( 'not any(lower(http.request.headers.names[*])[*] in {"authorization" "cookie"})', $rule['expression'] );
			self::assertStringContainsString( 'not any(lower(http.response.headers.names[*])[*] in {"content-type" "set-cookie" "vary" "cache-control" "cdn-cache-control" "cloudflare-cdn-cache-control" "pragma" "expires"})', $rule['expression'] );
			self::assertStringNotContainsString( '"/ai-discovery"', $rule['expression'] );
			self::assertStringNotContainsString( '"/.well-known/api-catalog"', $rule['expression'] );
		}
		self::assertSame( array( 'cache' => false ), $this->invoke( 'cache_safety_rule', 'example.com' )['action_parameters'] );
	}

	public function test_empty_profiles_replace_unsafe_owned_rules_with_disabled_records(): void {
		$desired = $this->invoke( 'header_rule', 'example.com', 'api_catalog', array() );
		$unsafe = $desired;
		$unsafe['id'] = 'owned-id'; $unsafe['enabled'] = true; $unsafe['expression'] = '(http.host eq "example.com" and http.request.uri.path eq "/ai.json")';
		$unsafe['action_parameters']['headers']['Cache-Control'] = array( 'operation' => 'set', 'value' => 'public, max-age=300' );
		$other = array( 'id' => 'unrelated', 'ref' => 'other-owner', 'expression' => 'true' );
		$ruleset = array( 'id' => 'ruleset', 'rules' => array( $unsafe, $other ) );
		$client = new CloudflareRulesClient( 'fixture-token', function ( string $url, array $args ) use ( &$ruleset ): array {
			self::assertSame( 'PATCH', $args['method'] );
			self::assertStringEndsWith( '/rules/owned-id', $url );
			$ruleset['rules'][0] = json_decode( $args['body'], true ) + array( 'id' => 'owned-id' );
			return $this->response( $ruleset );
		} );
		$result = ( new \ReflectionMethod( CloudflareRuleManager::class, 'upsert_rules' ) )->invoke( new CloudflareRuleManager( $client, null, $this->cloudflare_lock() ), 'zone', $ruleset, array( $desired ) );
		self::assertFalse( $result[0]['enabled'] );
		self::assertSame( $other, $ruleset['rules'][1] );
		self::assertArrayNotHasKey( 'Cache-Control', $ruleset['rules'][0]['action_parameters']['headers'] );
	}

	public function test_native_correct_mime_passes_without_forced_cache_cors_or_marker(): void {
		$response = array( 'headers' => array( 'content-type' => 'application/json; charset=UTF-8', 'cache-control' => 'private, no-store' ) );
		self::assertTrue( $this->invoke( 'valid_headers', $response, 'json' ) );
		$response['headers']['content-type'] = 'application/json-incorrect';
		self::assertFalse( $this->invoke( 'valid_headers', $response, 'json' ) );
	}
	public function test_public_preflight_bounds_responses_before_parsing(): void {
		$GLOBALS['cybermaps_mock_safe_remote_get_calls'] = array();
		$GLOBALS['cybermaps_mock_safe_remote_get_response'] = array( 'response' => array( 'code' => 200 ), 'headers' => array( 'content-type' => 'text/markdown' ), 'body' => str_repeat( 'x', 4 * 1024 * 1024 + 1 ) );
		try {
			$result = $this->invoke( 'verify_policy', array( 'path' => '/llms.txt', 'mime' => 'text/markdown' ), true );
			self::assertFalse( $result['ok'] );
			$GLOBALS['cybermaps_mock_safe_remote_get_response']['body'] = '# Valid Markdown';
			self::assertTrue( $this->invoke( 'verify_policy', array( 'path' => '/llms.txt', 'mime' => 'text/markdown' ), true )['ok'] );
			$call = $GLOBALS['cybermaps_mock_safe_remote_get_calls'][0];
			self::assertSame( 4 * 1024 * 1024 + 1, $call['args']['limit_response_size'] );
			self::assertSame( 'https://example.com/llms.txt', $call['url'] );
		} finally { unset( $GLOBALS['cybermaps_mock_safe_remote_get_response'], $GLOBALS['cybermaps_mock_safe_remote_get_calls'] ); }
	}

}
