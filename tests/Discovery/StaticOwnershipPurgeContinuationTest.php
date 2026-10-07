<?php
declare(strict_types=1);
namespace Cybermaps\Tests\Discovery;

use Cybermaps\Discovery\StaticBridge;
use Cybermaps\Discovery\StaticOwnershipStore;

final class StaticOwnershipPurgeContinuationTest extends \WP_UnitTestCase {
	private StaticBridge $bridge;
	private \CybermapsMockStaticOwnershipDatabase $database;
	private array $paths = array();
	protected function setUp(): void {
		parent::setUp();
		\cybermaps_mock_reset_cache_runtime();
		$GLOBALS['cybermaps_mock_options'] = array( 'cybermaps_settings' => array( 'static_engine_mode' => 'all' ) );
		$GLOBALS['cybermaps_mock_options_by_blog'] = array();
		$GLOBALS['cybermaps_mock_is_multisite'] = false;
		$GLOBALS['cybermaps_mock_scheduled'] = array();
		$GLOBALS['wp_hooks'] = array();
		( new \ReflectionProperty( StaticBridge::class, 'instance' ) )->setValue( null, null );
		$this->database = \cybermaps_mock_enable_static_ownership_database( true );
		$this->bridge = StaticBridge::get_instance();
	}
	protected function tearDown(): void {
		foreach ( $this->paths as $path ) { if ( file_exists( ABSPATH . $path ) ) { unlink( ABSPATH . $path ); } }
		( new \ReflectionProperty( StaticBridge::class, 'instance' ) )->setValue( null, null );
		\cybermaps_mock_disable_static_ownership_database();
		parent::tearDown();
	}
	public function test_large_purge_has_bounded_samples_exact_aggregate_counts_and_retains_foreign_edits(): void {
		$this->seed( 321 );
		file_put_contents( ABSPATH . $this->paths[0], 'foreign edit' );
		$first = $this->bridge->purge_all();
		self::assertFalse( $first['success'] );
		self::assertSame( 'incomplete', $first['status'] );
		self::assertTrue( $first['continuation'] );
		self::assertLessThanOrEqual( 300, array_sum( $first['counts'] ) );
		self::assertCount( 100, $first['deleted'] );
		$second = $this->bridge->purge_all();
		self::assertTrue( $second['success'] );
		self::assertSame( 'partial', $second['status'] );
		self::assertSame( array( 'deleted' => 320, 'retained' => 1 ), $second['counts'] );
		self::assertSame( 'content_changed', $second['retained'][ $this->paths[0] ] );
		self::assertSame( 'foreign edit', file_get_contents( ABSPATH . $this->paths[0] ) );
		self::assertCount( 1, ( new StaticOwnershipStore() )->read_records_page() );
		self::assertNull( ( new StaticOwnershipStore() )->read_purge_checkpoint( 'all' ) );
		$report = array( 'deleted' => array(), 'retained' => array() );
		$merge = new \ReflectionMethod( StaticBridge::class, 'merge_purge_result' );
		$merge->invokeArgs( $this->bridge, array( &$report, $first ) );
		$merge->invokeArgs( $this->bridge, array( &$report, $second ) );
		self::assertSame( 320, $report['_aggregate_counts']['deleted'] );
		self::assertSame( 1, $report['_aggregate_counts']['retained'] );
	}
	public function test_obsolete_cron_checkpoint_preserves_new_generation_and_queues_other_scope(): void {
		$this->seed( 301 );
		self::assertFalse( $this->bridge->purge_all()['success'] );
		$store = new StaticOwnershipStore();
		$old = $store->read_purge_checkpoint( 'all' );
		self::assertIsArray( $old );
		$remaining = $store->read_records_page()[0]['path'];
		$this->bridge->invalidate();
		self::assertTrue( $this->bridge->write_file( $remaining, 'new generation' ) );
		// A separate queued scope must not starve when the first is discarded.
		$other = $old;
		$other['args']['scope'] = 'xml';
		update_option( 'cybermaps_static_purge_xml', $other );
		wp_clear_scheduled_hook( StaticBridge::PURGE_CONTINUATION_HOOK );
		$this->bridge->resume_pending_purge();
		self::assertSame( 'new generation', file_get_contents( ABSPATH . $remaining ) );
		self::assertNull( $store->read_purge_checkpoint( 'all' ) );
		self::assertNotFalse( wp_next_scheduled( StaticBridge::PURGE_CONTINUATION_HOOK ) );
	}
	public function test_retryable_ownership_failure_does_not_double_count_retained_records(): void {
		$this->seed( 1 );
		unlink( ABSPATH . $this->paths[0] );
		$this->database->failure = static fn( $db, array $query ): bool => str_starts_with( $query['query'], 'DELETE FROM %i WHERE path_key' );
		$first = $this->bridge->purge_all();
		$retry = $this->bridge->purge_all();
		self::assertFalse( $first['success'] );
		self::assertFalse( $retry['success'] );
		self::assertSame( array( 'deleted' => 0, 'retained' => 0 ), $retry['counts'] );
		self::assertSame( '', ( new StaticOwnershipStore() )->read_purge_checkpoint( 'all' )['after'] );
		$this->database->failure = null;
		$last = $this->bridge->purge_all();
		self::assertTrue( $last['success'] );
		self::assertSame( array( 'deleted' => 0, 'retained' => 0 ), $last['counts'] );
		self::assertTrue( ( new StaticOwnershipStore() )->is_empty() );
	}
	public function test_terminal_lifecycle_drains_more_than_one_batch_without_scheduling_removed_code(): void {
		$this->seed( 321 );
		$result = $this->bridge->cancel_and_purge( 'all', true, true );
		self::assertTrue( $result['success'] );
		self::assertSame( 321, $result['counts']['deleted'] );
		self::assertFalse( $result['continuation'] );
		self::assertFalse( wp_next_scheduled( StaticBridge::PURGE_CONTINUATION_HOOK ) );
		foreach ( $this->paths as $path ) { self::assertFileDoesNotExist( ABSPATH . $path ); }
	}
	public function test_deactivation_invocation_drains_321_files_and_clears_callbacks(): void {
		$this->seed( 321 );
		\Cybermaps\Core\Lifecycle::deactivate();
		foreach ( $this->paths as $path ) { self::assertFileDoesNotExist( ABSPATH . $path ); }
		self::assertFalse( wp_next_scheduled( StaticBridge::PURGE_CONTINUATION_HOOK ) );
	}

	public function test_terminal_cleanup_drains_interrupted_legacy_migration_before_removing_files(): void {
		$legacy = array();
		for ( $i = 0; $i < 601; ++$i ) { $legacy[ 'legacy-terminal-' . $i . '.txt' ] = md5( 'body' ); }
		$path = array_key_first( $legacy );
		$this->paths[] = $path;
		file_put_contents( ABSPATH . $path, 'body' );
		update_option( StaticOwnershipStore::LEGACY_OPTION, $legacy );
		$result = $this->bridge->cancel_and_purge( 'all', true );
		self::assertTrue( $result['success'], json_encode( $result ) );
		self::assertSame( 1, $result['counts']['deleted'] );
		self::assertSame( StaticOwnershipStore::SCHEMA_VERSION, StaticOwnershipStore::current_schema() );
		self::assertFileDoesNotExist( ABSPATH . $path );
		self::assertFalse( wp_next_scheduled( StaticBridge::PURGE_CONTINUATION_HOOK ) );
	}

	public function test_terminal_deadline_retains_checkpoint_and_reports_unprocessed_work(): void {
		$this->seed( 1 );
		$result = ( new \ReflectionMethod( StaticBridge::class, 'drain_terminal_purge' ) )->invoke( $this->bridge, 'all', microtime( true ) - 1.0 );
		self::assertFalse( $result['success'] );
		self::assertTrue( $result['deadline_reached'] );
		self::assertSame( 1, $result['pending_lower_bound'] );
		self::assertSame( array( 'deleted' => 0, 'retained' => 0 ), $result['counts'] );
		self::assertIsArray( ( new StaticOwnershipStore() )->read_purge_checkpoint( 'all' ) );
		self::assertFileExists( ABSPATH . $this->paths[0] );
		self::assertFalse( wp_next_scheduled( StaticBridge::PURGE_CONTINUATION_HOOK ) );
	}
	private function seed( int $count ): void {
		for ( $i = 0; $i < $count; ++$i ) {
			$path = sprintf( 'bounded-purge-%04d.txt', $i );
			$this->paths[] = $path;
			self::assertTrue( $this->bridge->write_file( $path, 'owned ' . $i ), $path );
		}
	}
}
