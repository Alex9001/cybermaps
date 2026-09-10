<?php
/**
 * Cloudflare rule manager tests.
 *
 * @package Cybermaps\Tests\Admin
 */

declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\CloudflareRuleManager;
use PHPUnit\Framework\TestCase;

final class CloudflareRuleManagerTest extends TestCase {
	public function test_origin_bypass_rule_isolates_the_canonical_index_by_host_and_version(): void {
		$host   = 'www.example.com';
		$method = new \ReflectionMethod( CloudflareRuleManager::class, 'origin_bypass_rule' );
		$method->setAccessible( true );
		$rule = $method->invoke( null, $host );

		self::assertIsArray( $rule );
		self::assertSame( 'rewrite', $rule['action'] );
		self::assertSame( 'cybermaps_discovery_origin_bypass_v1', $rule['ref'] );
		self::assertSame(
			'cybermaps_origin=' . rawurlencode( strtolower( (string) CYBERMAPS_VERSION ) . '-' . substr( hash( 'sha256', $host ), 0, 16 ) ),
			$rule['action_parameters']['uri']['query']['value']
		);
		self::assertStringContainsString( 'http.host eq "www.example.com"', $rule['expression'] );
		self::assertStringContainsString( 'http.request.uri.path eq "/ai-discovery"', $rule['expression'] );
		self::assertStringContainsString( 'http.request.uri.query eq ""', $rule['expression'] );
	}
}
