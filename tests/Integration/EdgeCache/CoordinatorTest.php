<?php
declare(strict_types=1);

namespace {
	if ( ! function_exists( 'wp_generate_uuid4' ) ) {
		function wp_generate_uuid4(): string {
			return '00000000-0000-4000-8000-000000000001';
		}
	}
}

namespace Cybermaps\Tests\Integration\EdgeCache {

use Cybermaps\Integration\EdgeCache\Coordinator;
use PHPUnit\Framework\TestCase;

final class CoordinatorTest extends TestCase {
	protected function setUp(): void {
		delete_option( 'cybermaps_edge_cache_delivery_status' );
		delete_option( 'cybermaps_edge_cache_pending_static' );
		$GLOBALS['cybermaps_mock_filter_callbacks'] = array();
		parent::setUp();
	}

	protected function tearDown(): void {
		$GLOBALS['cybermaps_mock_filter_callbacks'] = array();
		parent::tearDown();
	}

	public function test_dynamic_invalidation_is_stored_even_without_a_vendor_adapter(): void {
		$coordinator = new Coordinator();
		$result      = $coordinator->invalidate( 'sitemap', array( home_url( '/sitemap_index.xml' ) ) );

		$this->assertSame( 'dispatched', $result['status'] );
		$this->assertSame( 'sitemap', $result['family'] );
		$this->assertSame( 1, $result['url_count'] );
		$this->assertNotEmpty( $coordinator->get_status() );
	}

	public function test_hostile_or_cross_origin_targets_are_removed_before_any_adapter_can_forward_them(): void {
		$coordinator = new Coordinator();
		$result      = $coordinator->invalidate(
			'discovery',
			array(
				home_url( '/ai.json?revision=1' ),
				'https://evil.example/ai.json',
				'https://user:pass@example.com/ai.json',
				'https://example.com/ai.json%0dX-Test:injected',
				'javascript:alert(1)',
			)
		);

		$this->assertSame( 1, $result['url_count'] );
	}

	public function test_static_delivery_waits_for_a_complete_conflict_free_sync(): void {
		$coordinator = new Coordinator();
		$result      = $coordinator->invalidate( 'discovery', array(), true );
		$this->assertSame( 'pending_static_sync', $result['status'] );

		$coordinator->on_static_sync_complete( array( 'status' => 'partial', 'conflicted' => 0, 'failed' => 0, 'retained' => 0 ) );
		$this->assertSame( 'pending_static_sync', $coordinator->get_status()[0]['status'] );

		$coordinator->on_static_sync_complete( array( 'status' => 'complete', 'conflicted' => 0, 'failed' => 0, 'retained' => 0 ) );
		$this->assertSame( 'dispatched', $coordinator->get_status()[0]['status'] );
	}

	public function test_failed_static_delivery_is_retained_with_a_bounded_retry_token(): void {
		$GLOBALS['cybermaps_mock_filter_callbacks']['cybermaps_varnish_purge_enabled'] = array(
			static fn(): bool => true,
		);
		$GLOBALS['cybermaps_mock_filter_callbacks']['cybermaps_varnish_purge_url'] = array(
			static fn(): string => home_url( '/' ),
		);
		$GLOBALS['cybermaps_mock_filter_callbacks']['cybermaps_varnish_purge_token'] = array(
			static fn(): string => 'test-secret',
		);

		$coordinator = new Coordinator();
		$coordinator->invalidate( 'discovery', array( home_url( '/ai.json' ) ), true );
		$coordinator->on_static_sync_complete( array( 'status' => 'complete', 'conflicted' => 0, 'failed' => 0, 'retained' => 0 ) );

		$this->assertSame( 'retry_pending', $coordinator->get_status()[0]['status'] );
		$pending = get_option( 'cybermaps_edge_cache_pending_static', array() );
		$this->assertCount( 1, $pending );
		$this->assertSame( 1, $pending[0]['attempts'] );
		$this->assertNotEmpty( $pending[0]['id'] );
	}
}
}
