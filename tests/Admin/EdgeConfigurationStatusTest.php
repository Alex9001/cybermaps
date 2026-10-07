<?php
/** Truthful read-only configuration and compatibility-mode status regressions. */
declare(strict_types=1);
namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\SitemapStatus;
use Cybermaps\Admin\SystemStatusCollector;
use Cybermaps\Integration\EdgeCache\VarnishAdapter;
use PHPUnit\Framework\TestCase;

final class EdgeConfigurationStatusTest extends TestCase {
	protected function tearDown(): void {
		foreach ( array( 'enabled', 'url', 'token', 'hosts' ) as $suffix ) { unset( $GLOBALS['cybermaps_mock_filter_callbacks']['cybermaps_varnish_purge_' . $suffix] ); }
		parent::tearDown();
	}

	private function filter( string $name, callable $callback ): void {
		$GLOBALS['cybermaps_mock_filter_callbacks'][$name] = array( $callback );
	}

	public function test_registered_false_filter_does_not_claim_enabled_transport(): void {
		$this->filter( 'cybermaps_varnish_purge_enabled', static fn(): bool => false );
		$this->filter( 'cybermaps_varnish_purge_url', static fn(): string => 'https://example.com' );
		$projection = ( new VarnishAdapter() )->configuration_status();
		self::assertFalse( $projection['enabled_for_probe'] );
		self::assertTrue( $projection['configured'] );
		self::assertFalse( $projection['valid'] );
		$item = ( new \ReflectionMethod( SystemStatusCollector::class, 'varnish_item' ) )->invoke( null );
		self::assertStringContainsString( 'Not enabled for diagnostic context', wp_json_encode( $item ) );
		self::assertStringNotContainsString( '"active"', wp_json_encode( $item ) );
	}

	public function test_endpoint_token_and_per_event_enablement_are_projected_without_secrets(): void {
		$this->filter( 'cybermaps_varnish_purge_enabled', static fn( bool $default, array $event ): bool => 'configuration_status' === $event['reason'] );
		$this->filter( 'cybermaps_varnish_purge_url', static fn(): string => home_url( '/' ) );
		$adapter = new VarnishAdapter();
		self::assertFalse( $adapter->configuration_status()['valid'] );
		$this->filter( 'cybermaps_varnish_purge_token', static fn(): string => 'sensitive-config-token' );
		self::assertSame( array( 'enabled_for_probe' => true, 'configured' => true, 'valid' => true ), $adapter->configuration_status() );
		$item = ( new \ReflectionMethod( SystemStatusCollector::class, 'varnish_item' ) )->invoke( null );
		self::assertStringContainsString( 'Enabled for diagnostic context', wp_json_encode( $item ) );
		self::assertStringNotContainsString( 'sensitive-config-token', wp_json_encode( $item ) );
		self::assertSame( 'disabled', $adapter->purge( array( 'reason' => 'actual_update', 'urls' => array() ) )['status'] );
		$this->filter( 'cybermaps_varnish_purge_url', static fn(): string => 'https://unapproved.example/purge?secret=hidden' );
		self::assertFalse( $adapter->configuration_status()['valid'] );
	}

	public function test_compatibility_mode_explains_dynamic_protocols_and_disabled_hub(): void {
		$method = new \ReflectionMethod( SitemapStatus::class, 'render_well_known_mode_notice' );
		foreach ( array( true, false ) as $enabled ) {
			ob_start(); $method->invoke( null, $enabled ); $output = ob_get_clean();
			self::assertStringContainsString( 'API Catalog and Discovery Index', $output );
			self::assertStringContainsString( $enabled ? 'remain dynamic' : 'no compatibility publications are materialized', $output );
		}
	}
}
