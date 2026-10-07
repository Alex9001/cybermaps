<?php
declare(strict_types=1);
namespace Cybermaps\Tests\Discovery;

use Cybermaps\Core\OptionLeaseLock;
use Cybermaps\Discovery\StaticOwnershipStore;

final class StaticOwnershipMigrationTest extends \WP_UnitTestCase {
	private \CybermapsMockStaticOwnershipDatabase $database;
	protected function setUp(): void {
		parent::setUp();
		\cybermaps_mock_reset_cache_runtime();
		$GLOBALS['cybermaps_mock_options'] = array();
		$GLOBALS['cybermaps_mock_options_by_blog'] = array();
		$GLOBALS['wp_hooks'] = array();
		$this->database = \cybermaps_mock_enable_static_ownership_database( true );
	}
	protected function tearDown(): void {
		\cybermaps_mock_disable_static_ownership_database();
		parent::tearDown();
	}
	public function test_changed_source_between_bounded_requests_resets_staging_and_preserves_exact_bytes(): void {
		$legacy = $this->legacy( 601 );
		update_option( StaticOwnershipStore::LEGACY_OPTION, $legacy );
		update_option( 'cybermaps_static_sync_epoch', 9 );
		self::assertFalse( $this->round() );
		self::assertSame( 0, StaticOwnershipStore::current_schema() );
		self::assertLessThanOrEqual( 250, count( $this->database->tables['wp_cybermaps_static_ownership'] ) );
		$first = array_key_first( $legacy );
		$legacy[ $first ] = md5( 'new source' );
		update_option( StaticOwnershipStore::LEGACY_OPTION, $legacy );
		self::assertFalse( $this->round() );
		self::assertSame( 'reset', get_option( StaticOwnershipStore::MIGRATION_OPTION )['phase'] );
		$this->finish();
		$store = new StaticOwnershipStore();
		self::assertSame( $legacy, $store->read_flat_hashes() );
		foreach ( array_keys( $legacy ) as $path ) { self::assertSame( 9, $store->get_generation( $path ) ); }
		foreach ( $this->database->queries as $query ) {
			if ( str_contains( $query['query'], 'SUBSTRING' ) ) { self::assertLessThanOrEqual( 65536, $query['args'][1] ); }
		}
		self::assertFalse( get_option( StaticOwnershipStore::MIGRATION_OPTION ) );
	}
	public function test_mid_copy_database_failure_replays_exact_rows_without_losing_legacy_authority(): void {
		$legacy = $this->legacy( 90 );
		update_option( StaticOwnershipStore::LEGACY_OPTION, $legacy );
		$target = array_keys( $legacy )[40];
		$this->database->failure = static fn( $db, array $query ): bool => str_starts_with( $query['query'], 'INSERT IGNORE INTO %i (path_key' ) && $target === $query['args'][2];
		self::assertFalse( $this->round() );
		self::assertSame( 0, StaticOwnershipStore::current_schema() );
		self::assertSame( $legacy, get_option( StaticOwnershipStore::LEGACY_OPTION ) );
		$this->database->failure = null;
		$this->finish();
		self::assertSame( $legacy, ( new StaticOwnershipStore() )->read_flat_hashes() );
	}
	public function test_cleanup_failure_rolls_back_authority_and_retries_atomic_source_retirement(): void {
		$legacy = $this->legacy( 1 );
		update_option( StaticOwnershipStore::LEGACY_OPTION, $legacy );
		$this->database->failure = static fn( $db, array $query ): bool => str_starts_with( $query['query'], 'DELETE FROM %i WHERE option_name' ) && StaticOwnershipStore::LEGACY_OPTION === ( $query['args'][1] ?? '' );
		self::assertFalse( $this->round() );
		self::assertSame( 0, StaticOwnershipStore::current_schema() );
		self::assertSame( $legacy, ( new StaticOwnershipStore() )->read_flat_hashes() );
		self::assertArrayHasKey( StaticOwnershipStore::LEGACY_OPTION, $GLOBALS['cybermaps_mock_options'] );
		$this->database->failure = null;
		self::assertTrue( $this->round() );
		self::assertArrayNotHasKey( StaticOwnershipStore::LEGACY_OPTION, $GLOBALS['cybermaps_mock_options'] );
	}
	public function test_failed_ready_cutover_revalidates_a_supported_fenced_legacy_source_edit(): void {
		StaticOwnershipStore::register_hooks();
		$path = 'cutover-source.xml';
		update_option( StaticOwnershipStore::LEGACY_OPTION, array( $path => md5( 'old body' ) ) );
		$this->fail_schema_cutover();
		self::assertFalse( $this->round() );
		self::assertSame( 'ready', get_option( StaticOwnershipStore::MIGRATION_OPTION )['phase'] );
		self::assertSame( 0, StaticOwnershipStore::current_schema() );
		$this->database->failure = null;
		$writer = new OptionLeaseLock( 'cybermaps_migration_fixture', 120, 15, true );
		self::assertTrue( $writer->acquire() );
		try { self::assertTrue( ( new StaticOwnershipStore( $writer ) )->set_hash( $path, md5( 'new body' ), 0, true ) ); }
		finally { $writer->release(); }
		self::assertFalse( $this->round() );
		self::assertSame( 0, StaticOwnershipStore::current_schema() );
		$this->finish();
		self::assertSame( md5( 'new body' ), ( new StaticOwnershipStore() )->get_hash( $path ) );
		self::assertArrayNotHasKey( StaticOwnershipStore::MIGRATION_OPTION, $GLOBALS['cybermaps_mock_options'] );
	}

	public function test_failed_ready_cutover_with_unchanged_source_can_finish_on_retry(): void {
		$path = 'cutover-unchanged.xml';
		update_option( StaticOwnershipStore::LEGACY_OPTION, array( $path => md5( 'owned body' ) ) );
		$this->fail_schema_cutover();
		self::assertFalse( $this->round() );
		self::assertSame( 'ready', get_option( StaticOwnershipStore::MIGRATION_OPTION )['phase'] );
		$this->database->failure = null;
		self::assertTrue( $this->round() );
		self::assertSame( StaticOwnershipStore::SCHEMA_VERSION, StaticOwnershipStore::current_schema() );
		self::assertSame( md5( 'owned body' ), ( new StaticOwnershipStore() )->get_hash( $path ) );
	}

	public function test_caller_transaction_is_preserved_before_any_schema_ddl_or_staging_write(): void {
		$this->database->query( 'START TRANSACTION' );
		update_option( 'caller_uncommitted', 'preserved' );
		$before = count( $this->database->queries );
		self::assertFalse( $this->round() );
		self::assertSame( 'preserved', get_option( 'caller_uncommitted' ) );
		foreach ( array_slice( $this->database->queries, $before ) as $query ) {
			self::assertNotContains( $query['query'], array( 'START TRANSACTION', 'COMMIT', 'ROLLBACK' ) );
			self::assertStringNotContainsString( 'CREATE TABLE', $query['query'] );
		}
		$this->database->query( 'ROLLBACK' );
		self::assertFalse( get_option( 'caller_uncommitted' ) );
	}

	public function test_failed_commit_rolls_back_marker_and_deleted_sources(): void {
		$legacy = $this->legacy( 1 );
		update_option( StaticOwnershipStore::LEGACY_OPTION, $legacy );
		$this->database->failure = static fn( $db, array $query ): bool => 'COMMIT' === $query['query'];
		self::assertFalse( $this->round() );
		self::assertSame( 0, StaticOwnershipStore::current_schema() );
		self::assertSame( $legacy, get_option( StaticOwnershipStore::LEGACY_OPTION ) );
		$this->database->failure = null;
		self::assertTrue( $this->round() );
		self::assertFalse( get_option( StaticOwnershipStore::LEGACY_OPTION ) );
	}

	public function test_reconnect_during_cutover_cannot_promote_or_retire_sources(): void {
		$legacy = $this->legacy( 1 );
		update_option( StaticOwnershipStore::LEGACY_OPTION, $legacy );
		$this->database->before_query = static function ( $db, array $query ): void {
			if ( str_contains( $query['query'], 'FOR UPDATE' ) ) {
				$db->before_query = null;
				$db->reconnect( $db->connection_id + 1 );
			}
		};
		self::assertFalse( $this->round() );
		self::assertSame( 0, StaticOwnershipStore::current_schema() );
		self::assertSame( $legacy, get_option( StaticOwnershipStore::LEGACY_OPTION ) );
		// The disconnected owner's durable lease remains until expiry; simulate its expiry.
		delete_option( 'cybermaps_migration_fixture' );
		$this->finish();
	}

	public function test_nontransactional_options_engine_prevents_cutover(): void {
		$legacy = $this->legacy( 1 );
		update_option( StaticOwnershipStore::LEGACY_OPTION, $legacy );
		$this->database->engines['wp_options'] = 'MyISAM';
		self::assertFalse( $this->round() );
		self::assertSame( 0, StaticOwnershipStore::current_schema() );
		self::assertSame( $legacy, get_option( StaticOwnershipStore::LEGACY_OPTION ) );
		$this->database->engines['wp_options'] = 'InnoDB';
		self::assertTrue( $this->round() );
	}

	public function test_uncooperative_source_aba_during_binary_chunk_read_cannot_publish_unpinned_bytes(): void {
		$original = array( 'aba.xml' => md5( 'authoritative A' ) );
		update_option( StaticOwnershipStore::LEGACY_OPTION, $original );
		$this->database->before_query = static function ( $db, array $query ) use ( $original ): void {
			if ( ! str_contains( $query['query'], 'SELECT SUBSTRING' ) ) { return; }
			$db->before_query = null;
			update_option( StaticOwnershipStore::LEGACY_OPTION, array( 'aba.xml' => md5( 'temporary B' ) ) );
			$db->after_query = static function ( $db, array $query ) use ( $original ): void {
				if ( ! str_contains( $query['query'], 'SELECT SUBSTRING' ) ) { return; }
				$db->after_query = null;
				update_option( StaticOwnershipStore::LEGACY_OPTION, $original );
			};
		};
		self::assertFalse( $this->round() );
		self::assertSame( 0, StaticOwnershipStore::current_schema() );
		self::assertSame( $original, get_option( StaticOwnershipStore::LEGACY_OPTION ) );
		$this->finish();
		self::assertSame( $original, ( new StaticOwnershipStore() )->read_flat_hashes() );
	}

	public function test_row_lock_timeout_is_scoped_and_restored_after_commit_and_failure(): void {
		$this->database->lock_wait = 79;
		$this->fail_schema_cutover();
		self::assertFalse( $this->round() );
		self::assertSame( 79, $this->database->lock_wait );
		$this->database->failure = null;
		self::assertTrue( $this->round() );
		self::assertSame( 79, $this->database->lock_wait );
	}

	private function fail_schema_cutover(): void {
		$this->database->failure = static fn( $db, array $query ): bool =>
			( str_starts_with( $query['query'], 'INSERT IGNORE INTO %i (option_name' ) || str_starts_with( $query['query'], 'UPDATE %i SET option_value' ) )
			&& in_array( StaticOwnershipStore::SCHEMA_OPTION, $query['args'], true );
	}

	public function test_malformed_source_and_future_schema_are_preserved(): void {
		update_option( StaticOwnershipStore::LEGACY_OPTION, array( 'bad' => array( 'nested' => md5( 'a' ) ) ) );
		self::assertFalse( $this->round() );
		self::assertSame( 0, StaticOwnershipStore::current_schema() );
		self::assertSame( array( 'bad' => array( 'nested' => md5( 'a' ) ) ), get_option( StaticOwnershipStore::LEGACY_OPTION ) );
		update_option( StaticOwnershipStore::SCHEMA_OPTION, StaticOwnershipStore::SCHEMA_VERSION + 1 );
		$before = $GLOBALS['cybermaps_mock_options'];
		self::assertFalse( $this->round() );
		self::assertSame( $before, $GLOBALS['cybermaps_mock_options'] );
	}
	private function round(): bool {
		$lease = new OptionLeaseLock( 'cybermaps_migration_fixture', 120, 15, true );
		self::assertTrue( $lease->acquire() );
		try { return ( new StaticOwnershipStore( $lease ) )->migrate_if_needed( static fn(): bool => $lease->maintain() ); }
		finally { $lease->release(); }
	}
	private function finish(): void {
		for ( $i = 0; $i < 20; ++$i ) { if ( $this->round() ) { return; } }
		self::fail( 'Migration did not finish within bounded fixture rounds.' );
	}
	private function legacy( int $count ): array {
		$records = array();
		for ( $i = 0; $i < $count; ++$i ) { $records[ '旧/雪-' . str_pad( (string) $i, 4, '0', STR_PAD_LEFT ) . '.json' ] = md5( (string) $i ); }
		uksort( $records, static fn( $a, $b ) => strcmp( hash( 'sha256', $a ), hash( 'sha256', $b ) ) );
		return $records;
	}
}
