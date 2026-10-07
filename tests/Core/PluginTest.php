<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Core {

use Cybermaps\Core\Container;
use Cybermaps\Core\Lifecycle;
use Cybermaps\Core\Plugin;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/mocks/configuration-database.php';

final class PluginTest extends TestCase {
	private mixed $previous_database;

	protected function setUp(): void {
		parent::setUp();
		$this->previous_database = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new \CybermapsConfigurationDatabase();

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
		$GLOBALS['wpdb'] = $this->previous_database;
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

	public function test_family_invalidation_does_not_claim_complete_url_inventory(): void {
		$method = new \ReflectionMethod( Plugin::class, 'edge_invalidation_plan' );
		foreach ( array( 'discovery', 'sitemap', 'chunks' ) as $family ) {
			$plan = $method->invoke( null, $family );
			$this->assertFalse( $plan['url_scope_complete'] );
		}
	}

	public function test_production_invalidation_deduplication_is_scoped_to_blog_family_and_generation(): void {
		$process = proc_open(
			array( PHP_BINARY, dirname( __DIR__ ) . '/fixtures/plugin-blog-invalidation.php' ),
			array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
			$pipes
		);
		$this->assertIsResource( $process );
		$output = stream_get_contents( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$this->assertSame( 0, proc_close( $process ), $error );
		$this->assertSame( array( 1, 1, 1, 2, 3 ), json_decode( $output, true, 512, JSON_THROW_ON_ERROR ) );
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
