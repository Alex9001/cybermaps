<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Integration\EdgeCache {

use Cybermaps\Integration\EdgeCache\Coordinator;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/mocks/configuration-database.php';

final class CoordinatorTest extends TestCase {
	private mixed $previous_database;
	protected function setUp(): void {
		$this->previous_database = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new \CybermapsConfigurationDatabase();
		delete_option( 'cybermaps_edge_cache_delivery_status' );
		delete_option( 'cybermaps_edge_cache_pending_static' );
		$GLOBALS['cybermaps_mock_filter_callbacks'] = array();
		wp_clear_scheduled_hook( 'cybermaps_edge_cache_retry' );
		parent::setUp();
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->previous_database;
		unset( $GLOBALS['cybermaps_mock_schedule_failure'] );
		$GLOBALS['cybermaps_mock_filter_callbacks'] = array();
		wp_clear_scheduled_hook( 'cybermaps_edge_cache_retry' );
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
		$this->assertSame( 5, $result['requested_url_count'] );
		$this->assertFalse( $result['url_scope_complete'] );
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

	public function test_transient_delivery_with_rejected_schedule_is_retained_truthfully(): void {
		$GLOBALS['cybermaps_mock_filter_callbacks']['cybermaps_varnish_purge_enabled'] = array( static fn(): bool => true );
		$GLOBALS['cybermaps_mock_filter_callbacks']['cybermaps_varnish_purge_url'] = array( static fn(): string => home_url( '/' ) );
		$GLOBALS['cybermaps_mock_filter_callbacks']['cybermaps_varnish_purge_token'] = array( static fn(): string => 'test-secret' );
		$GLOBALS['cybermaps_mock_schedule_failure'] = true;
		$coordinator = new Coordinator();
		$coordinator->invalidate( 'discovery', array( home_url( '/ai.json' ) ), true );
		$coordinator->on_static_sync_complete( array( 'status' => 'complete' ) );
		$this->assertSame( 'schedule_failed', $coordinator->get_status()[0]['status'] );
		$this->assertCount( 1, get_option( 'cybermaps_edge_cache_pending_static' ) );
		$this->assertFalse( wp_next_scheduled( 'cybermaps_edge_cache_retry' ) );
	}

	public function test_successful_due_retry_preserves_the_future_retry_continuation(): void {
		$future = time() + 120;
		update_option( 'cybermaps_edge_cache_pending_static', array(
			array( 'id' => 'due', 'static_ready' => true, 'next_attempt' => time() - 1 ),
			array( 'id' => 'future', 'static_ready' => true, 'next_attempt' => $future ),
			array( 'id' => 'awaiting-static', 'static_ready' => false, 'next_attempt' => 0 ),
		) );
		$coordinator = new Coordinator();
		$coordinator->retry_pending();

		$this->assertSame( array( 'future', 'awaiting-static' ), array_column( get_option( 'cybermaps_edge_cache_pending_static' ), 'id' ) );
		$this->assertSame( $future, wp_next_scheduled( 'cybermaps_edge_cache_retry' ) );

		$pending = get_option( 'cybermaps_edge_cache_pending_static' );
		$pending[0]['next_attempt'] = time() - 1;
		update_option( 'cybermaps_edge_cache_pending_static', $pending );
		wp_clear_scheduled_hook( 'cybermaps_edge_cache_retry' );
		$coordinator->retry_pending();
		$this->assertSame( array( 'awaiting-static' ), array_column( get_option( 'cybermaps_edge_cache_pending_static' ), 'id' ) );
		$this->assertFalse( wp_next_scheduled( 'cybermaps_edge_cache_retry' ) );
	}

	public function test_earlier_pending_retry_advances_a_later_scheduled_event(): void {
		$future = time() + 120;
		wp_schedule_single_event( $future + 600, 'cybermaps_edge_cache_retry' );
		update_option( 'cybermaps_edge_cache_pending_static', array(
			array( 'id' => 'future', 'static_ready' => true, 'next_attempt' => $future ),
		) );
		( new Coordinator() )->retry_pending();
		$this->assertSame( $future, wp_next_scheduled( 'cybermaps_edge_cache_retry' ) );
	}
	public function test_concurrent_append_is_preserved_by_compare_and_swap_retry(): void {
		$coordinator = new Coordinator();
		$coordinator->invalidate( 'sitemap', array( home_url( '/first.xml' ) ), true );
		$GLOBALS['wpdb']->before_query = static function ( $sql, $option ) use ( $coordinator ): void {
			if ( 'cybermaps_edge_cache_pending_static' === $option ) {
				$GLOBALS['wpdb']->before_query = null;
				$coordinator->invalidate( 'sitemap', array( home_url( '/concurrent.xml' ) ), true );
			}
		};
		$coordinator->invalidate( 'sitemap', array( home_url( '/second.xml' ) ), true );
		$pending = get_option( 'cybermaps_edge_cache_pending_static' );
		$this->assertCount( 3, $pending );
		$this->assertSame( array( home_url( '/first.xml' ), home_url( '/concurrent.xml' ), home_url( '/second.xml' ) ), array_merge( ...array_column( $pending, 'urls' ) ) );
	}

	public function test_queue_overflow_rejects_new_event_without_evicting_coverage(): void {
		$coordinator = new Coordinator();
		for ( $id = 1; $id <= 21; ++$id ) {
			$result = $coordinator->invalidate( 'sitemap', array( home_url( '/' . $id . '.xml' ) ), true );
			$this->assertSame( $id > 20 ? 'queue_full' : 'pending_static_sync', $result['status'] );
		}
		$pending = get_option( 'cybermaps_edge_cache_pending_static' );
		$this->assertCount( 20, $pending );
		$this->assertSame( array( home_url( '/1.xml' ) ), $pending[0]['urls'] );
	}

	public function test_remove_racing_append_preserves_the_new_event(): void {
		$coordinator = new Coordinator();
		$coordinator->invalidate( 'sitemap', array( home_url( '/old.xml' ) ), true );
		$GLOBALS['wpdb']->before_query = static function ( $sql, $option ): void {
			if ( 'cybermaps_edge_cache_pending_static' === $option ) {
				$GLOBALS['wpdb']->before_query = null;
				( new Coordinator() )->invalidate( 'sitemap', array( home_url( '/new.xml' ) ), true );
			}
		};
		( new \ReflectionMethod( Coordinator::class, 'remove_pending_event' ) )->invoke( $coordinator, get_option( 'cybermaps_edge_cache_pending_static' )[0]['id'] );
		$pending = get_option( 'cybermaps_edge_cache_pending_static' );
		$this->assertCount( 1, $pending );
		$this->assertSame( array( home_url( '/new.xml' ) ), $pending[0]['urls'] );
	}

	public function test_rejected_scheduler_is_truthful_and_init_recovery_schedules_once(): void {
		foreach ( array( true, new \WP_Error( 'schedule_failed', 'Rejected' ) ) as $failure ) {
			wp_clear_scheduled_hook( 'cybermaps_edge_cache_retry' );
			update_option( 'cybermaps_edge_cache_pending_static', array( array( 'id' => 'recover', 'static_ready' => true, 'next_attempt' => time() + 60 ) ) );
			$GLOBALS['cybermaps_mock_schedule_failure'] = $failure;
			$coordinator = new Coordinator();
			$coordinator->recover_pending_retry();
			$this->assertSame( 'schedule_failed', $coordinator->get_status()[0]['status'] );
			$this->assertFalse( wp_next_scheduled( 'cybermaps_edge_cache_retry' ) );
			$this->assertCount( 1, get_option( 'cybermaps_edge_cache_pending_static' ) );
			unset( $GLOBALS['cybermaps_mock_schedule_failure'] );
			$coordinator->recover_pending_retry();
			$timestamp = wp_next_scheduled( 'cybermaps_edge_cache_retry' );
			$this->assertIsInt( $timestamp );
			$coordinator->recover_pending_retry();
			$this->assertSame( $timestamp, wp_next_scheduled( 'cybermaps_edge_cache_retry' ) );
		}
	}

}
}
