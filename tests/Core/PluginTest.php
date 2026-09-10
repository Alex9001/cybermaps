<?php
declare(strict_types=1);

namespace {
	if ( ! function_exists( 'wp_generate_uuid4' ) ) {
		function wp_generate_uuid4(): string {
			return '00000000-0000-4000-8000-000000000001';
		}
	}
}

namespace Cybermaps\Tests\Core {

use Cybermaps\Core\Container;
use Cybermaps\Core\Lifecycle;
use Cybermaps\Core\Plugin;
use PHPUnit\Framework\TestCase;

final class PluginTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['cybermaps_mock_is_multisite'] = false;
		$GLOBALS['cybermaps_mock_options']      = array(
			'cybermaps_settings' => array(
				'static_engine_mode' => 'all',
			),
		);
		$GLOBALS['wp_hooks'] = array();
		$this->reset_runtime_state();
	}

	protected function tearDown(): void {
		$this->reset_runtime_state();
		parent::tearDown();
	}

	public function test_bootstrap_registers_edge_cache_hooks_once(): void {
		( new Plugin( new Container() ) )->run();
		( new Plugin( new Container() ) )->run();

		$this->assertSame( 1, $this->hook_count( 'cybermaps_static_sync_complete' ) );
		$this->assertSame( 1, $this->hook_count( 'cybermaps_edge_cache_retry' ) );
		$this->assertSame( 1, $this->hook_count( 'cybermaps_cache_family_invalidated' ) );
		$this->assertSame( 1, $this->hook_count( Lifecycle::RUNTIME_COUNTER_CLEANUP_HOOK ) );
	}

	public function test_public_cache_family_invalidation_is_deduplicated_per_request(): void {
		( new Plugin( new Container() ) )->run();

		Plugin::on_cache_family_invalidated( 'discovery', 7 );
		Plugin::on_cache_family_invalidated( 'discovery', 7 );

		$history = get_option( 'cybermaps_edge_cache_delivery_status', array() );
		$pending = get_option( 'cybermaps_edge_cache_pending_static', array() );

		$this->assertCount( 1, $history );
		$this->assertSame( 'pending_static_sync', $history[0]['status'] ?? '' );
		$this->assertSame( 'discovery', $history[0]['family'] ?? '' );
		$this->assertGreaterThan( 0, (int) ( $history[0]['url_count'] ?? 0 ) );
		$this->assertIsArray( $pending );
		$this->assertCount( 1, $pending );
	}

	private function hook_count( string $hook ): int {
		return count(
			array_filter(
				$GLOBALS['wp_hooks'],
				static fn( array $entry ): bool => $hook === ( $entry['hook'] ?? '' )
			)
		);
	}

	private function reset_runtime_state(): void {
		( new \ReflectionProperty( Plugin::class, 'edge_cache_coordinator' ) )->setValue( null, null );
		( new \ReflectionProperty( Plugin::class, 'edge_cache_hooks_registered' ) )->setValue( null, false );
		( new \ReflectionProperty( Plugin::class, 'edge_invalidation_signatures' ) )->setValue( null, array() );
		( new \ReflectionProperty( \Cybermaps\Discovery\WellKnownRoutingBridge::class, 'hooks_registered' ) )->setValue( null, false );
	}
}
}
