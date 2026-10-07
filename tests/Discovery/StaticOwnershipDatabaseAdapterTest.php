<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Core\DatabaseSessionLock;
use Cybermaps\Core\AtomicOptionSequence;
use Cybermaps\Core\OptionLeaseLock;
use Cybermaps\Discovery\StaticOwnershipStore;
use Cybermaps\Discovery\StaticOwnershipTable;

/** Focused executor tests; real database integration remains a separate gate. */
final class StaticOwnershipDatabaseAdapterTest extends \WP_UnitTestCase {
	private array $saved = array();
	private \CybermapsMockStaticOwnershipDatabase $database;

	protected function setUp(): void {
		parent::setUp();
		foreach ( array( 'cybermaps_mock_options', 'cybermaps_mock_options_by_blog', 'cybermaps_mock_current_blog_id', 'cybermaps_mock_blog_stack', 'cybermaps_mock_switched_blogs', 'cybermaps_mock_update_option_behavior', 'cybermaps_mock_dbdelta_callback', 'cybermaps_mock_dbdelta_queries', 'wp_hooks' ) as $name ) {
			$this->saved[ $name ] = array( array_key_exists( $name, $GLOBALS ), $GLOBALS[ $name ] ?? null );
		}
		$GLOBALS['cybermaps_mock_options'] = array();
		$GLOBALS['cybermaps_mock_options_by_blog'] = array();
		$GLOBALS['cybermaps_mock_current_blog_id'] = 1;
		$GLOBALS['cybermaps_mock_blog_stack'] = array();
		$GLOBALS['wp_hooks'] = array();
		unset( $GLOBALS['cybermaps_mock_update_option_behavior'], $GLOBALS['cybermaps_mock_dbdelta_callback'] );
		$this->database = \cybermaps_mock_enable_static_ownership_database();
	}

	protected function tearDown(): void {
		\cybermaps_mock_disable_static_ownership_database();
		foreach ( $this->saved as $name => $record ) {
			if ( $record[0] ) {
				$GLOBALS[ $name ] = $record[1];
			} else {
				unset( $GLOBALS[ $name ] );
			}
		}
		parent::tearDown();
	}

	public function test_opt_in_restores_prior_database_and_flags_exactly(): void {
		\cybermaps_mock_disable_static_ownership_database();
		$names = array( 'wpdb', 'cybermaps_test_static_ownership_use_sql', 'cybermaps_test_database_session_lock_use_sql' );
		$prior = array();
		foreach ( $names as $name ) {
			$prior[ $name ] = array( array_key_exists( $name, $GLOBALS ), $GLOBALS[ $name ] ?? null );
		}
		try {
			$sentinel = new \stdClass();
			$GLOBALS['wpdb'] = $sentinel;
			$GLOBALS['cybermaps_test_static_ownership_use_sql'] = null;
			$GLOBALS['cybermaps_test_database_session_lock_use_sql'] = true;
			\cybermaps_mock_enable_static_ownership_database( false );
			$this->assertTrue( $GLOBALS['cybermaps_test_static_ownership_use_sql'] );
			$this->assertFalse( $GLOBALS['cybermaps_test_database_session_lock_use_sql'] );
			\cybermaps_mock_disable_static_ownership_database();
			$this->assertSame( $sentinel, $GLOBALS['wpdb'] );
			$this->assertArrayHasKey( 'cybermaps_test_static_ownership_use_sql', $GLOBALS );
			$this->assertNull( $GLOBALS['cybermaps_test_static_ownership_use_sql'] );
			$this->assertTrue( $GLOBALS['cybermaps_test_database_session_lock_use_sql'] );
		} finally {
			\cybermaps_mock_disable_static_ownership_database();
			foreach ( $prior as $name => $record ) {
				if ( $record[0] ) {
					$GLOBALS[ $name ] = $record[1];
				} else {
					unset( $GLOBALS[ $name ] );
				}
			}
		}
	}

	public function test_production_table_rejects_stale_update_delete_and_path_collision(): void {
		$table = $this->table();
		$this->assertTrue( $table->install() );
		$old = array( 'hash' => str_repeat( 'a', 32 ), 'generation' => 1 );
		$new = array( 'hash' => str_repeat( 'b', 32 ), 'generation' => 2 );
		$this->assertTrue( $table->replace( 'owned.xml', null, $old ) );
		$this->assertTrue( $table->replace( 'owned.xml', $old, $new ) );
		$this->assertFalse( $table->replace( 'owned.xml', $old, null ) );
		$this->assertFalse( $table->replace( 'owned.xml', $old, $old ) );
		$this->assertSame( $new, $table->read( 'owned.xml' ) );
		$this->assertTrue( $table->replace( 'owned.xml', $new, $new ) );
		$this->assertSame( 0, $this->database->rows_affected );
		$key = hash( 'sha256', 'owned.xml' );
		$this->database->tables['wp_cybermaps_static_ownership'][ $key ]['path'] = 'different.xml';
		$this->assertFalse( $table->read( 'owned.xml' ) );
		$this->assertFalse( $table->replace( 'owned.xml', $new, null ) );
		$this->assertArrayHasKey( $key, $this->database->tables['wp_cybermaps_static_ownership'] );
	}

	public function test_pages_use_binary_keys_and_bounded_cursors_and_preserve_malformed_rows(): void {
		$table = $this->table();
		for ( $i = 0; $i < 105; ++$i ) {
			$this->assertTrue( $table->replace( 'page-' . $i . '.xml', null, array( 'hash' => str_repeat( 'c', 32 ), 'generation' => 3 ) ) );
		}
		$page = $table->page( -1, '', 1000 );
		$this->assertCount( 100, $page );
		$keys = array_column( $page, 'key' );
		$sorted = $keys;
		sort( $sorted, SORT_STRING );
		$this->assertSame( $sorted, $keys );
		$this->assertCount( 5, $table->page( -1, $keys[99] ) );
		$shard = StaticOwnershipStore::shard_for_path( 'page-0.xml' );
		foreach ( $table->page( $shard ) as $row ) {
			$this->assertSame( $shard, StaticOwnershipStore::shard_for_path( $row['path'] ) );
		}
		$this->database->tables['wp_cybermaps_static_ownership'][ $keys[0] ]['generation'] = '01';
		$this->assertFalse( $table->page( -1 ) );
	}

	public function test_raw_option_fingerprints_and_chunks_are_binary_and_cas_is_exact(): void {
		$raw = "é\0A ";
		$this->assertSame( 1, $this->sql( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', array( 'wp_options', 'legacy', $raw, 'no' ) ) );
		$this->assertSame( 1, $this->sql( 'SELECT LENGTH(option_value) AS bytes, SHA2(option_value,256) AS digest FROM %i WHERE option_name = %s LIMIT 1', array( 'wp_options', 'legacy' ) ) );
		$this->assertSame( (string) strlen( $raw ), $this->database->last_result[0]->bytes );
		$this->assertSame( hash( 'sha256', $raw ), $this->database->last_result[0]->digest );
		$this->sql( 'SELECT SUBSTRING(BINARY option_value,%d,%d) AS chunk FROM %i WHERE option_name = %s AND LENGTH(option_value) = %d AND SHA2(option_value,256) = %s LIMIT 1', array( 2, 3, 'wp_options', 'legacy', strlen( $raw ), hash( 'sha256', $raw ) ) );
		$this->assertSame( substr( $raw, 1, 3 ), $this->database->last_result[0]->chunk );
		$update = 'UPDATE %i SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s';
		$this->assertSame( 0, $this->sql( $update, array( 'wp_options', 'replacement', 'legacy', rtrim( $raw ) ) ) );
		$this->assertSame( 0, $this->sql( $update, array( 'wp_options', $raw, 'legacy', $raw ) ) );
		$this->assertSame( $raw, \get_option( 'legacy' ) );
		$this->assertSame( 1, $this->sql( $update, array( 'wp_options', serialize( array( 'x' => 1 ) ), 'legacy', $raw ) ) );
		$this->assertSame( array( 'x' => 1 ), \get_option( 'legacy' ) );
		$this->assertSame( 0, $this->sql( 'DELETE FROM %i WHERE option_name = %s AND LENGTH(option_value) = %d AND SHA2(option_value,256) = %s', array( 'wp_options', 'legacy', strlen( $raw ), hash( 'sha256', $raw ) ) ) );
	}

	public function test_existing_option_failure_hook_can_interleave_a_successor(): void {
		$GLOBALS['cybermaps_mock_options']['state'] = 'old';
		$GLOBALS['cybermaps_mock_update_option_behavior'] = static function ( string $name, mixed $value, string $phase ): void {
			if ( 'state' === $name && 'before' === $phase ) {
				$GLOBALS['cybermaps_mock_options']['state'] = 'successor';
			}
		};
		$this->assertSame( 0, $this->sql( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s', array( 'wp_options', 'desired', 'state', 'old' ) ) );
		$this->assertSame( 'successor', \get_option( 'state' ) );
	}

	public function test_same_statement_fences_fail_reads_and_mutations_after_connection_loss(): void {
		$this->assertSame( '1', $this->database->get_var( $this->database->prepare( 'SELECT GET_LOCK(%s, 0)', 'fixture' ) ) );
		$table = $this->table( array( 'name' => 'fixture', 'connection_id' => 41 ) );
		$record = array( 'hash' => str_repeat( 'd', 32 ), 'generation' => 4 );
		$this->assertTrue( $table->replace( 'fenced.xml', null, $record ) );
		$this->database->before_query = static function ( \CybermapsMockStaticOwnershipDatabase $db, array $prepared ): void {
			if ( str_starts_with( $prepared['query'], 'DELETE' ) ) {
				$db->reconnect( 42 );
			}
		};
		$this->assertFalse( $table->replace( 'fenced.xml', $record, null ) );
		$this->assertSame( $record, $this->table()->read( 'fenced.xml' ) );
		$this->assertNull( $table->read( 'fenced.xml', true ) );
		$this->assertArrayNotHasKey( 'fixture', $this->database->locks );
	}

	public function test_production_database_session_lock_detects_reconnect(): void {
		$GLOBALS['cybermaps_test_database_session_lock_use_sql'] = true;
		$lock = new DatabaseSessionLock( 'adapter-session', 'explicit-shared-scope' );
		$this->assertTrue( $lock->acquire() );
		$fence = $lock->get_fence();
		$this->assertSame( 41, $fence['connection_id'] );
		$this->assertSame( 41, $this->database->locks[ $fence['name'] ] );
		$this->database->reconnect( 42 );
		$this->assertFalse( $lock->maintain() );
		$this->assertNull( $lock->get_fence() );
		$this->assertTrue( $lock->is_lost() );
		$lock->release();
	}

	public function test_transaction_rollback_restores_rows_and_options_but_preserves_advisory_lock(): void {
		$this->database->get_var( $this->database->prepare( 'SELECT GET_LOCK(%s, 0)', 'transaction-lock' ) );
		$this->database->query( 'START TRANSACTION' );
		$this->table()->replace( 'rolled-back.xml', null, array( 'hash' => str_repeat( 'e', 32 ), 'generation' => 5 ) );
		$this->sql( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', array( 'wp_options', 'rolled-back', 'value', 'no' ) );
		$this->database->query( 'ROLLBACK' );
		$this->assertNull( $this->table()->read( 'rolled-back.xml' ) );
		$this->assertFalse( \get_option( 'rolled-back' ) );
		$this->assertSame( 41, $this->database->locks['transaction-lock'] );
		$this->database->query( 'START TRANSACTION' );
		$this->table()->replace( 'committed.xml', null, array( 'hash' => str_repeat( 'f', 32 ), 'generation' => 6 ) );
		$this->database->query( 'COMMIT' );
		$this->assertNotNull( $this->table()->read( 'committed.xml' ) );
	}

	public function test_reconnect_rolls_back_active_transaction_and_keeps_other_sessions_locks(): void {
		$this->database->get_var( $this->database->prepare( 'SELECT GET_LOCK(%s, 0)', 'owned-session-lock' ) );
		$this->database->locks['other-session-lock'] = 99;
		$this->database->query( 'START TRANSACTION' );
		$this->table()->replace( 'interrupted.xml', null, array( 'hash' => str_repeat( 'a', 32 ), 'generation' => 1 ) );
		$this->database->reconnect( 42 );
		$this->assertNull( $this->table()->read( 'interrupted.xml' ) );
		$this->assertArrayNotHasKey( 'owned-session-lock', $this->database->locks );
		$this->assertSame( 99, $this->database->locks['other-session-lock'] );
		$this->assertSame( '0', $this->database->get_var( $this->database->prepare( 'SELECT GET_LOCK(%s, 0)', 'other-session-lock' ) ) );
		$this->assertSame( '0', $this->database->get_var( $this->database->prepare( 'SELECT RELEASE_LOCK(%s)', 'other-session-lock' ) ) );
	}

	public function test_reentrant_advisory_lock_requires_matching_releases(): void {
		for ( $i = 0; $i < 2; ++$i ) {
			$this->assertSame( '1', $this->database->get_var( $this->database->prepare( 'SELECT GET_LOCK(%s, 0)', 'reentrant' ) ) );
		}
		$this->assertSame( '1', $this->database->get_var( $this->database->prepare( 'SELECT RELEASE_LOCK(%s)', 'reentrant' ) ) );
		$this->assertSame( 41, $this->database->locks['reentrant'] );
		$this->assertSame( '1', $this->database->get_var( $this->database->prepare( 'SELECT RELEASE_LOCK(%s)', 'reentrant' ) ) );
		$this->assertArrayNotHasKey( 'reentrant', $this->database->locks );
		$this->assertNull( $this->database->get_var( $this->database->prepare( 'SELECT RELEASE_LOCK(%s)', 'reentrant' ) ) );
	}

	public function test_real_shaped_schema_metadata_is_validated_instead_of_assumed(): void {
		$GLOBALS['cybermaps_mock_dbdelta_callback'] = static fn(): array => array();
		$this->database->columns['wp_cybermaps_static_ownership'][1]['Null'] = 'YES';
		$this->assertFalse( $this->table()->install() );
		$this->database->install_ownership_table( 'wp_cybermaps_static_ownership' );
		$this->database->indexes['wp_cybermaps_static_ownership'][2]['Column_name'] = 'body_hash';
		$this->assertFalse( $this->table()->install() );
		$this->database->install_ownership_table( 'wp_cybermaps_static_ownership' );
		$this->assertTrue( $this->table()->install() );
	}

	public function test_blog_switch_keeps_tables_and_options_isolated(): void {
		$GLOBALS['cybermaps_mock_options']['root-only'] = 'root';
		$this->table()->replace( 'root.xml', null, array( 'hash' => str_repeat( 'a', 32 ), 'generation' => 1 ) );
		\switch_to_blog( 2 );
		try {
			$this->assertSame( 'wp_2_', $this->database->prefix );
			\update_option( 'native-child-write', 'native-child' );
			$this->assertSame( 'native-child', \get_option( 'native-child-write' ) );
			$this->assertFalse( \get_option( 'root-only' ) );
			$this->assertFalse( $this->table()->read( 'root.xml' ) );
			$this->assertTrue( $this->table()->install() );
			$this->sql( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', array( 'wp_2_options', 'child-only', 'child', 'no' ) );
			$this->assertSame( 'child', \get_option( 'child-only' ) );
		} finally {
			\restore_current_blog();
		}
		$this->assertSame( 'wp_', $this->database->prefix );
		$this->assertSame( 'root', \get_option( 'root-only' ) );
		$this->assertFalse( \get_option( 'child-only' ) );
		$this->assertFalse( \get_option( 'native-child-write' ) );
		$this->assertNotNull( $this->table()->read( 'root.xml' ) );
	}

	public function test_production_store_migrates_legacy_options_and_preserves_new_row_updates(): void {
		$GLOBALS['cybermaps_mock_options'][ StaticOwnershipStore::LEGACY_OPTION ] = array( 'legacy.xml' => str_repeat( 'a', 32 ) );
		$store = new StaticOwnershipStore();
		$this->assertTrue( $store->migrate_if_needed() );
		$this->assertSame( (string) StaticOwnershipStore::SCHEMA_VERSION, \get_option( StaticOwnershipStore::SCHEMA_OPTION ) );
		$this->assertSame( str_repeat( 'a', 32 ), $store->get_hash( 'legacy.xml' ) );
		$this->assertFalse( \get_option( StaticOwnershipStore::LEGACY_OPTION ) );
		$this->assertTrue( $store->set_hash( 'legacy.xml', str_repeat( 'b', 32 ), 2, true ) );
		$this->assertSame( 2, $store->get_generation( 'legacy.xml' ) );
		$this->assertSame( str_repeat( 'b', 32 ), ( new StaticOwnershipStore() )->get_hash( 'legacy.xml' ) );
	}

	public function test_schema_two_copy_resumes_after_a_bounded_checkpoint(): void {
		$GLOBALS['cybermaps_mock_options'][ StaticOwnershipStore::SCHEMA_OPTION ] = StaticOwnershipStore::SHARD_SCHEMA_VERSION;
		$expected = array();
		for ( $i = 0; $i < 260; ++$i ) {
			$path = 'migrating-' . $i . '.xml';
			$expected[ $path ] = str_repeat( 'c', 32 );
			$option = StaticOwnershipStore::shard_option_name( StaticOwnershipStore::shard_for_path( $path ) );
			$GLOBALS['cybermaps_mock_options'][ $option ][ $path ] = array( 'hash' => str_repeat( 'c', 32 ), 'generation' => 7 );
		}
		$store = new StaticOwnershipStore();
		$this->assertFalse( $store->migrate_if_needed() );
		$this->assertTrue( $store->has_pending_migration() );
		$this->assertSame( StaticOwnershipStore::SHARD_SCHEMA_VERSION, \get_option( StaticOwnershipStore::SCHEMA_OPTION ) );
		$this->assertIsArray( \get_option( StaticOwnershipStore::MIGRATION_OPTION ) );
		$attempts = 0;
		do {
			$store = new StaticOwnershipStore();
			$done = $store->migrate_if_needed();
			++$attempts;
		} while ( ! $done && $store->has_pending_migration() && $attempts < 10 );
		$this->assertTrue( $done );
		$actual = $store->read_flat_hashes();
		ksort( $expected );
		ksort( $actual );
		$this->assertSame( $expected, $actual );
		$this->assertFalse( \get_option( StaticOwnershipStore::MIGRATION_OPTION ) );
		$this->assertSame( 7, $store->get_generation( 'migrating-0.xml' ) );
		foreach ( $this->database->queries as $query ) {
			if ( str_contains( $query['query'], 'SUBSTRING(BINARY' ) ) {
				$this->assertGreaterThan( 0, $query['args'][0] );
				$this->assertLessThanOrEqual( 8192, $query['args'][1] );
			}
		}
	}

	public function test_stale_store_flush_preserves_an_interleaved_row_successor(): void {
		$GLOBALS['cybermaps_mock_options'][ StaticOwnershipStore::SCHEMA_OPTION ] = StaticOwnershipStore::SCHEMA_VERSION;
		$old = new StaticOwnershipStore();
		$this->assertTrue( $old->set_hash( 'shared.xml', str_repeat( 'a', 32 ), 1, true ) );
		$this->assertTrue( $old->set_hash( 'shared.xml', str_repeat( 'b', 32 ), 2 ) );
		$new = new StaticOwnershipStore();
		$this->assertTrue( $new->set_hash( 'shared.xml', str_repeat( 'c', 32 ), 3, true ) );
		$this->assertFalse( $old->flush() );
		$this->assertSame( str_repeat( 'c', 32 ), ( new StaticOwnershipStore() )->get_hash( 'shared.xml' ) );
		$this->assertFalse( $old->flush() );
	}

	public function test_production_sequences_do_not_move_backwards_or_accept_malformed_values(): void {
		$this->assertSame( 1, AtomicOptionSequence::increment( 'sequence' ) );
		$this->assertSame( 2, AtomicOptionSequence::increment( 'sequence' ) );
		$this->assertSame( 9, AtomicOptionSequence::advance_to( 'sequence', 9 ) );
		$this->assertSame( 9, AtomicOptionSequence::advance_to( 'sequence', 3 ) );
		$GLOBALS['cybermaps_mock_options']['sequence'] = '009';
		$this->assertSame( -1, AtomicOptionSequence::current( 'sequence' ) );
		$this->assertSame( 0, AtomicOptionSequence::increment( 'sequence' ) );
		$this->assertSame( -1, AtomicOptionSequence::advance_to( 'sequence', 10 ) );
		$this->assertSame( '009', \get_option( 'sequence' ) );
		$GLOBALS['cybermaps_mock_options']['sequence'] = '9223372036854775807';
		$this->assertSame( 0, AtomicOptionSequence::increment( 'sequence' ) );
		$this->assertSame( '9223372036854775808', \get_option( 'sequence' ) );
		$GLOBALS['cybermaps_mock_options']['sequence'] = '18446744073709551615';
		$this->assertSame( 0, AtomicOptionSequence::increment( 'sequence' ) );
		$this->assertSame( '18446744073709551615', \get_option( 'sequence' ) );
		$this->assertNotSame( '', $this->database->last_error );
	}

	public function test_production_option_lease_uses_explicit_owner_fences(): void {
		$GLOBALS['cybermaps_test_database_session_lock_use_sql'] = true;
		$lease = new OptionLeaseLock( 'adapter-option-lease', 300, 60, true );
		$this->assertTrue( $lease->acquire() );
		try {
			$fence = $lease->get_database_fence();
			$this->assertSame( 41, $fence['connection_id'] );
			$this->assertTrue( $lease->maintain() );
			$this->database->reconnect( 42 );
			$this->assertFalse( $lease->maintain() );
		} finally {
			$lease->release();
		}
	}

	public function test_read_failure_clears_rows_and_production_table_fails_closed(): void {
		$table = $this->table();
		$this->assertTrue( $table->replace( 'failure.xml', null, array( 'hash' => str_repeat( 'a', 32 ), 'generation' => 1 ) ) );
		$this->database->fail_next = true;
		$this->assertFalse( $table->read( 'failure.xml' ) );
		$this->assertSame( array(), $this->database->last_result );
		$this->assertNotSame( '', $this->database->last_error );
		$this->assertNotNull( $table->read( 'failure.xml' ) );
		$this->assertSame( '', $this->database->last_error );
	}

	public function test_unknown_sql_is_rejected_instead_of_reporting_success(): void {
		$this->expectException( \UnexpectedValueException::class );
		$this->database->query( 'DELETE FROM arbitrary_table' );
	}

	public function test_placeholder_count_mismatch_is_rejected(): void {
		$this->expectException( \UnexpectedValueException::class );
		$this->database->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', 'wp_options' );
	}

	public function test_unknown_sql_remains_rejected_when_its_fence_is_lost(): void {
		$this->expectException( \UnexpectedValueException::class );
		$this->sql( 'DELETE FROM arbitrary_table WHERE IS_USED_LOCK(%s) = CONNECTION_ID() AND CONNECTION_ID() = %d', array( 'not-owned', 41 ) );
	}

	private function sql( string $sql, array $args ): int|false {
		return $this->database->query( $this->database->prepare( $sql, $args ) );
	}

	private function table( ?array $fence = null ): StaticOwnershipTable {
		return new StaticOwnershipTable(
			$this->database->prefix . StaticOwnershipTable::SUFFIX,
			function ( string $sql, array $args, bool $rows, bool $fenced ) use ( $fence ): array|int|false {
				return ( new \ReflectionMethod( StaticOwnershipStore::class, 'direct_query' ) )->invoke( null, $this->database, $sql, $args, $fenced ? $fence : null, $rows );
			}
		);
	}
}
