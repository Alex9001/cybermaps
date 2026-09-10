<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Discovery\WellKnownRoutingBridge;
use PHPUnit\Framework\TestCase;

final class WellKnownRoutingBridgeTest extends TestCase {
	private string $root;

	protected function setUp(): void {
		parent::setUp();
		$this->root = sys_get_temp_dir() . '/cybermaps-well-known-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->root . '/.well-known', 0777, true );
		$GLOBALS['cybermaps_mock_options'] = array(
			'cybermaps_settings' => array(
				'enable_discovery_hub' => '1',
				'mcp_mode'             => 'off',
				'static_engine_mode'   => 'well_known',
			),
		);
		$GLOBALS['cybermaps_mock_filter_callbacks']['cybermaps_static_publication_root'] = array( fn(): string => $this->root );
		$GLOBALS['cybermaps_mock_rewrite_rules'] = array();
		$GLOBALS['wp_rewrite'] = new class() {
			public array $extra_rules_top = array();
			public array $extra_rules = array();
			public array $non_wp_rules = array();
			public function add_external_rule( string $regex, string $query ): void {
				$this->non_wp_rules[ $regex ] = $query;
			}
		};
		$this->reset_registry();
		$this->reset_hooks();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['cybermaps_mock_filter_callbacks']['cybermaps_static_publication_root'], $GLOBALS['wp_rewrite'] );
		$this->reset_hooks();
		$this->remove_tree( $this->root );
		parent::tearDown();
	}

	public function test_reconcile_uses_native_global_rules_without_creating_nested_configuration(): void {
		$status = ( new WellKnownRoutingBridge() )->reconcile( false );

		$this->assertSame( 'native_rewrite', $status['status'] );
		$this->assertSame( 'wordpress_global_rewrite', $status['delivery'] );
		$this->assertFileDoesNotExist( $this->root . '/.well-known/.htaccess' );
		$this->assertArrayHasKey( '^\\.well\\-known/api\\-catalog$', $GLOBALS['wp_rewrite']->non_wp_rules );
	}

	public function test_remove_deletes_only_a_hash_verified_legacy_marker_block(): void {
		$file  = $this->root . '/.well-known/.htaccess';
		$block = "# BEGIN Cybermaps well-known routing\nRewriteEngine On\n# END Cybermaps well-known routing\n";
		file_put_contents( $file, "# Existing ACME rule\n" . $block );
		update_option( 'cybermaps_well_known_routing_hash', hash( 'sha256', $block ), false );

		$status = ( new WellKnownRoutingBridge() )->remove();

		$this->assertSame( 'removed', $status['status'] );
		$this->assertSame( "# Existing ACME rule\n", file_get_contents( $file ) );
	}

	public function test_external_legacy_edit_is_preserved_as_conflict(): void {
		$file  = $this->root . '/.well-known/.htaccess';
		$block = "# BEGIN Cybermaps well-known routing\nRewriteEngine Off\n# END Cybermaps well-known routing\n";
		file_put_contents( $file, $block );
		update_option( 'cybermaps_well_known_routing_hash', hash( 'sha256', str_replace( 'Off', 'On', $block ) ), false );

		$status = ( new WellKnownRoutingBridge() )->remove();

		$this->assertSame( 'conflict', $status['status'] );
		$this->assertSame( $block, file_get_contents( $file ) );
	}

	public function test_hook_registration_is_process_idempotent(): void {
		$GLOBALS['wp_hooks'] = array();
		WellKnownRoutingBridge::register_hooks();
		WellKnownRoutingBridge::register_hooks();

		$this->assertSame( 1, $this->hook_count( WellKnownRoutingBridge::RECONCILE_HOOK ) );
		$this->assertSame( 1, $this->hook_count( WellKnownRoutingBridge::VERIFY_HOOK ) );
	}

	private function reset_registry(): void {
		$registry = new \ReflectionProperty( \Cybermaps\Core\EndpointRegistry::class, 'instance' );
		$registry->setValue( null, null );
	}

	private function reset_hooks(): void {
		( new \ReflectionProperty( WellKnownRoutingBridge::class, 'hooks_registered' ) )->setValue( null, false );
	}

	private function hook_count( string $hook ): int {
		return count( array_filter( $GLOBALS['wp_hooks'] ?? array(), static fn( array $entry ): bool => $hook === ( $entry['hook'] ?? '' ) ) );
	}

	private function remove_tree( string $path ): void {
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( (array) scandir( $path ) as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$child = $path . '/' . $item;
			is_dir( $child ) ? $this->remove_tree( $child ) : unlink( $child );
		}
		rmdir( $path );
	}
}
