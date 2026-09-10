<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Core\OptionLeaseLock;
use Cybermaps\Discovery\StaticWriteIntentStore;

final class StaticWriteIntentStoreTest extends \WP_UnitTestCase {
	private bool $had_wpdb;
	private mixed $previous_wpdb;

	protected function setUp(): void {
		parent::setUp();

		$this->had_wpdb      = \array_key_exists( 'wpdb', $GLOBALS );
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		unset( $GLOBALS['wpdb'] );
		unset( $GLOBALS['cybermaps_test_database_session_lock_use_sql'] );
		\delete_option( StaticWriteIntentStore::OPTION );
	}

	protected function tearDown(): void {
		\delete_option( StaticWriteIntentStore::OPTION );
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		unset( $GLOBALS['cybermaps_test_database_session_lock_use_sql'] );

		parent::tearDown();
	}

	public function test_create_read_and_exact_delete_preserve_one_strict_intent(): void {
		$store  = new StaticWriteIntentStore( null, true );
		$record = $store->create(
			'intent\\target.txt',
			false,
			'',
			-1,
			\str_repeat( 'a', 32 ),
			4,
			5,
			'lease-owner-one'
		);

		$this->assertIsArray( $record );
		$this->assertSame( 'intent/target.txt', $record['filename'] );
		$this->assertSame( $record, $store->read() );
		$this->assertSame( $record, \get_option( StaticWriteIntentStore::OPTION ) );

		$this->assertNull(
			$store->create(
				'intent/other.txt',
				false,
				'',
				-1,
				\str_repeat( 'b', 32 ),
				4,
				5,
				'lease-owner-two'
			)
		);
		$this->assertSame( $record, $store->read() );

		$wrong              = $record;
		$wrong['intent_id'] = 'different-intent';
		$this->assertFalse( $store->delete_exact( $wrong ) );
		$this->assertSame( $record, $store->read() );

		$this->assertTrue( $store->delete_exact( $record ) );
		$this->assertNull( $store->read() );
		$this->assertArrayNotHasKey( StaticWriteIntentStore::OPTION, $GLOBALS['cybermaps_mock_options'] );
	}

	public function test_create_rejects_invalid_records_without_persisting_partial_evidence(): void {
		$store = new StaticWriteIntentStore( null, true );

		$this->assertNull( $store->create( '', false, '', -1, \str_repeat( 'a', 32 ), 1, 1, 'lease' ) );
		$this->assertNull( $store->create( 'invalid-hash.txt', false, '', -1, 'not-an-md5', 1, 1, 'lease' ) );
		$this->assertNull(
			$store->create(
				'invalid-old-hash.txt',
				true,
				'not-an-md5',
				1,
				\str_repeat( 'b', 32 ),
				1,
				1,
				'lease'
			)
		);
		$this->assertNull( $store->read() );
		$this->assertArrayNotHasKey( StaticWriteIntentStore::OPTION, $GLOBALS['cybermaps_mock_options'] );
	}

	public function test_delete_tombstone_is_typed_strict_and_body_free_in_status(): void {
		$store  = new StaticWriteIntentStore( null, true );
		$record = $store->create_delete(
			'delete\\target.txt',
			\str_repeat( 'b', 32 ),
			8,
			9,
			10,
			'delete-owner'
		);

		$this->assertIsArray( $record );
		$this->assertSame( 'delete', StaticWriteIntentStore::operation( $record ) );
		$this->assertSame( '', $record['new_hash'] );
		$this->assertSame(
			array(
				'status'     => 'pending',
				'schema'     => StaticWriteIntentStore::SCHEMA,
				'type'       => 'delete',
				'intent_id'  => $record['intent_id'],
				'path'       => 'delete/target.txt',
				'created_at' => $record['created_at'],
			),
			$store->describe()
		);
		$this->assertArrayNotHasKey( 'old_hash', $store->describe() );
		$this->assertArrayNotHasKey( 'new_hash', $store->describe() );
		$this->assertTrue( $store->delete_exact( $record ) );
	}

	public function test_legacy_schema_one_write_intent_remains_recoverable(): void {
		$store  = new StaticWriteIntentStore( null, true );
		$record = $store->create(
			'legacy-write-intent.txt',
			false,
			'',
			-1,
			\str_repeat( 'c', 32 ),
			3,
			4,
			'legacy-owner'
		);
		$this->assertIsArray( $record );

		$legacy           = $record;
		$legacy['schema'] = 1;
		unset( $legacy['operation'] );
		\update_option( StaticWriteIntentStore::OPTION, $legacy, false );

		$this->assertSame( $legacy, $store->read() );
		$this->assertSame( 'write', StaticWriteIntentStore::operation( $legacy ) );
		$this->assertSame( 'write', $store->describe()['type'] );
	}

	public function test_malformed_storage_is_reported_and_preserved(): void {
		$malformed = array(
			'schema'    => StaticWriteIntentStore::SCHEMA,
			'intent_id' => 'incomplete-evidence',
		);
		\update_option( StaticWriteIntentStore::OPTION, $malformed, false );

		$store = new StaticWriteIntentStore();
		$this->assertFalse( $store->read() );
		$this->assertFalse( $store->delete_exact( $malformed ) );
		$this->assertSame( $malformed, \get_option( StaticWriteIntentStore::OPTION ) );
	}

	public function test_unfenced_test_fixture_exact_delete_cannot_erase_an_interleaved_successor_intent(): void {
		$database        = $this->database();
		$GLOBALS['wpdb'] = $database;
		$store           = new StaticWriteIntentStore( null, true );
		$record          = $store->create(
			'intent-cas.txt',
			false,
			'',
			-1,
			\str_repeat( 'c', 32 ),
			6,
			7,
			'first-lease'
		);

		$this->assertIsArray( $record );
		$this->assertTrue( $store->is_current_exact( $record ) );
		$successor                           = $record;
		$successor['intent_id']              = 'successor-intent';
		$serialized_successor                = \function_exists( 'maybe_serialize' )
			? \maybe_serialize( $successor )
			: \serialize( $successor ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		$database->replacement_before_delete = $serialized_successor;

		$this->assertFalse( $store->delete_exact( $record ) );
		$this->assertSame( $serialized_successor, $database->raw );
		$this->assertSame( $successor, $store->read() );
	}

	public function test_production_mutations_fail_closed_without_an_acquired_database_fence(): void {
		$store = new StaticWriteIntentStore();
		$this->assertNull(
			$store->create(
				'unfenced-create.txt',
				false,
				'',
				-1,
				\str_repeat( 'd', 32 ),
				1,
				1,
				'unfenced-token'
			)
		);

		$bypass = new StaticWriteIntentStore( null, true );
		$record = $bypass->create(
			'unfenced-delete.txt',
			false,
			'',
			-1,
			\str_repeat( 'e', 32 ),
			1,
			1,
			'unfenced-token'
		);
		$this->assertIsArray( $record );
		$this->assertFalse( $store->delete_exact( $record ) );
		$this->assertSame( $record, $store->read() );
	}

	public function test_production_insert_and_delete_bind_the_exact_database_fence_in_each_statement(): void {
		$GLOBALS['cybermaps_test_database_session_lock_use_sql'] = true;
		$database        = $this->fenced_database();
		$GLOBALS['wpdb'] = $database;
		$lock            = new OptionLeaseLock( 'intent-store-fence', 300, 60, true );
		$this->assertTrue( $lock->acquire(), \wp_json_encode( $database->queries ) );
		$token = (string) $lock->get_token();
		$fence = $lock->get_database_fence();
		$this->assertIsArray( $fence );
		$store  = new StaticWriteIntentStore( $lock );
		$record = $store->create(
			'guarded-intent.txt',
			false,
			'',
			-1,
			\str_repeat( 'f', 32 ),
			2,
			3,
			$token
		);

		$this->assertIsArray( $record );
		$this->assertTrue( StaticWriteIntentStore::matches_lease_token( $record, $token ) );
		$this->assertFalse( StaticWriteIntentStore::matches_lease_token( $record, 'different-token' ) );
		$this->assertTrue( $store->is_current_exact( $record ) );
		$this->assertTrue( $store->delete_exact( $record ) );
		$delete_record = $store->create_delete(
			'guarded-delete-intent.txt',
			\str_repeat( '2', 32 ),
			2,
			3,
			3,
			$token
		);
		$this->assertIsArray( $delete_record );
		$this->assertTrue( $store->is_current_exact( $delete_record ) );
		$this->assertTrue( $store->delete_exact( $delete_record ) );

		$intent_mutations = \array_values(
			\array_filter(
				$database->queries,
				static fn ( array $query ): bool => \in_array(
					StaticWriteIntentStore::OPTION,
					$query['args'],
					true
				) && ( \str_starts_with( \ltrim( $query['query'] ), 'INSERT' ) || \str_starts_with( \ltrim( $query['query'] ), 'DELETE' ) )
			)
		);
		$this->assertCount( 4, $intent_mutations );
		foreach ( $intent_mutations as $mutation ) {
			$this->assertStringContainsString( 'IS_USED_LOCK(%s) = CONNECTION_ID()', $mutation['query'] );
			$this->assertStringContainsString( 'CONNECTION_ID() = %d', $mutation['query'] );
			$this->assertContains( $fence['name'], $mutation['args'] );
			$this->assertContains( $fence['connection_id'], $mutation['args'] );
		}
		$lock->release();
	}

	public function test_guarded_insert_fails_when_the_write_statement_uses_another_connection(): void {
		$GLOBALS['cybermaps_test_database_session_lock_use_sql'] = true;
		$database        = $this->fenced_database();
		$GLOBALS['wpdb'] = $database;
		$lock            = new OptionLeaseLock( 'intent-store-split-write', 300, 60, true );
		$this->assertTrue( $lock->acquire(), \wp_json_encode( $database->queries ) );
		$database->write_connection_id = $database->connection_id + 1;

		$record = ( new StaticWriteIntentStore( $lock ) )->create(
			'split-write-intent.txt',
			false,
			'',
			-1,
			\str_repeat( '1', 32 ),
			2,
			3,
			(string) $lock->get_token()
		);

		$this->assertNull( $record );
		$this->assertArrayNotHasKey( StaticWriteIntentStore::OPTION, $database->rows );
		$lock->release();
	}

	private function fenced_database(): object {
		return new class() {
			public string $options = 'wp_options';
			public string $last_error = '';
			public int $connection_id = 7301;
			public int $write_connection_id = 7301;
			public ?string $advisory_name = null;
			public ?int $advisory_owner = null;
			/** @var array<string,string> */
			public array $rows = array();
			/** @var array<int,array{query:string,args:array<int,mixed>}> */
			public array $queries = array();

			/** @return array{query:string,args:array<int,mixed>} */
			public function prepare( string $query, mixed ...$args ): array {
				return array(
					'query' => $query,
					'args'  => $args,
				);
			}

			/** @param array{query:string,args:array<int,mixed>}|string $prepared */
			public function get_var( array|string $prepared ): int|string|null {
				$query = \is_array( $prepared ) ? $prepared['query'] : $prepared;
				$args  = \is_array( $prepared ) ? $prepared['args'] : array();
				$this->queries[] = array(
					'query' => $query,
					'args'  => $args,
				);

				if ( \str_contains( $query, 'GET_LOCK' ) ) {
					$name = (string) ( $args[0] ?? '' );
					if ( null !== $this->advisory_owner ) {
						return 0;
					}
					$this->advisory_name  = $name;
					$this->advisory_owner = $this->connection_id;
					return 1;
				}
				if ( \str_contains( $query, 'SELECT (IS_USED_LOCK' ) ) {
					return ( $args[0] ?? null ) === $this->advisory_name
						&& ( $args[1] ?? null ) === $this->advisory_owner
						&& ( $args[2] ?? null ) === $this->connection_id
						? 1
						: 0;
				}
				if ( \str_contains( $query, 'IS_USED_LOCK' ) ) {
					return ( $args[0] ?? null ) === $this->advisory_name
						? $this->advisory_owner
						: null;
				}
				if ( \str_contains( $query, 'RELEASE_LOCK' ) ) {
					if ( ( $args[0] ?? null ) !== $this->advisory_name ) {
						return 0;
					}
					$this->advisory_name  = null;
					$this->advisory_owner = null;
					return 1;
				}
				if ( 'SELECT CONNECTION_ID()' === \trim( $query ) ) {
					return $this->connection_id;
				}
				if ( \str_contains( $query, 'SELECT option_value' ) ) {
					$option = (string) ( $args[1] ?? '' );
					return $this->rows[ $option ] ?? null;
				}

				return null;
			}

			/** @param array{query:string,args:array<int,mixed>} $prepared */
			public function query( array $prepared ): int|false {
				$this->queries[] = $prepared;
				$query           = \ltrim( $prepared['query'] );
				$args            = $prepared['args'];
				if ( \str_starts_with( $query, 'INSERT' ) ) {
					if ( ! $this->guard_allows_mutation( $query, $args ) ) {
						return 0;
					}
					$option = (string) ( $args[1] ?? '' );
					if ( isset( $this->rows[ $option ] ) ) {
						return 0;
					}
					$this->rows[ $option ] = (string) ( $args[2] ?? '' );
					return 1;
				}
				if ( \str_starts_with( $query, 'DELETE' ) ) {
					if ( ! $this->guard_allows_mutation( $query, $args ) ) {
						return 0;
					}
					$option = (string) ( $args[1] ?? '' );
					if ( ! isset( $this->rows[ $option ] ) || $this->rows[ $option ] !== ( $args[2] ?? null ) ) {
						return 0;
					}
					unset( $this->rows[ $option ] );
					return 1;
				}

				return false;
			}

			/** @param array<int,mixed> $args */
			private function guard_allows_mutation( string $query, array $args ): bool {
				if ( ! \str_contains( $query, 'IS_USED_LOCK' ) ) {
					return true;
				}

				if ( \str_contains( $query, 'IS_USED_LOCK(%s) = %d' ) ) {
					$fence_name         = $args[ \count( $args ) - 3 ] ?? null;
					$expected_owner      = $args[ \count( $args ) - 2 ] ?? null;
					$captured_connection = $args[ \count( $args ) - 1 ] ?? null;
					return $fence_name === $this->advisory_name
						&& $expected_owner === $this->advisory_owner
						&& $this->advisory_owner === $this->write_connection_id
						&& $captured_connection === $this->write_connection_id;
				}

				if ( ! \str_contains( $query, 'CONNECTION_ID() = %d' ) ) {
					$fence_name = $args[ \count( $args ) - 1 ] ?? null;
					return $fence_name === $this->advisory_name
						&& $this->advisory_owner === $this->write_connection_id;
				}

				$fence_name         = $args[ \count( $args ) - 2 ] ?? null;
				$captured_connection = $args[ \count( $args ) - 1 ] ?? null;
				return $fence_name === $this->advisory_name
					&& $this->advisory_owner === $this->write_connection_id
					&& $captured_connection === $this->write_connection_id;
			}
		};
	}

	private function database(): object {
		return new class() {
			public string $options                    = 'wp_options';
			public string $last_error                 = '';
			public ?string $raw                       = null;
			public ?string $replacement_before_delete = null;

			/** @return array{query:string,args:array<int,mixed>} */
			public function prepare( string $query, mixed ...$args ): array {
				return array(
					'query' => $query,
					'args'  => $args,
				);
			}

			/** @param array{query:string,args:array<int,mixed>} $prepared */
			public function get_var( array $prepared ): ?string {
				unset( $prepared );
				return $this->raw;
			}

			/** @param array{query:string,args:array<int,mixed>} $prepared */
			public function query( array $prepared ): int|false {
				$query = \ltrim( $prepared['query'] );
				$args  = $prepared['args'];
				if ( \str_starts_with( $query, 'INSERT IGNORE' ) ) {
					if ( null !== $this->raw ) {
						return 0;
					}
					$this->raw = (string) ( $args[2] ?? '' );
					return 1;
				}
				if ( \str_starts_with( $query, 'DELETE' ) ) {
					if ( null !== $this->replacement_before_delete ) {
						$this->raw                       = $this->replacement_before_delete;
						$this->replacement_before_delete = null;
					}
					if ( null === $this->raw || ( $args[2] ?? null ) !== $this->raw ) {
						return 0;
					}
					$this->raw = null;
					return 1;
				}

				return false;
			}
		};
	}
}
