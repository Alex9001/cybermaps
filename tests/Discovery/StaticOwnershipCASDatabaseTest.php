<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Core\OptionLeaseLock;
use Cybermaps\Discovery\StaticOwnershipStore;

final class StaticOwnershipCASDatabaseTest extends \WP_UnitTestCase {
	private bool $had_wpdb;
	private mixed $previous_wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->had_wpdb      = \array_key_exists( 'wpdb', $GLOBALS );
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
	}

	protected function tearDown(): void {
		unset( $GLOBALS['cybermaps_test_static_ownership_use_sql'] );
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		parent::tearDown();
	}

	public function test_stale_direct_update_cannot_erase_an_interleaved_successor(): void {
		$option                              = StaticOwnershipStore::shard_option_name( 0 );
		$paths                               = $this->filenames_for_shard( 0, 3 );
		$base                                = $this->records( $paths[0], 'a', 1 );
		$desired                             = $this->records( $paths[1], 'b', 2 );
		$successor                           = $this->records( $paths[2], 'c', 3 );
		$database                            = $this->database( array( $option => $this->serialize_records( $base ) ) );
		$database->replacement_before_update = $this->serialize_records( $successor );
		$GLOBALS['wpdb']                     = $database;

		$this->assertFalse( $this->compare_and_swap( $option, $this->observation( $base ), $desired ) );
		$this->assertSame( $this->serialize_records( $successor ), $database->rows[ $option ] );
	}

	public function test_stale_direct_delete_cannot_erase_an_interleaved_successor(): void {
		$option                              = StaticOwnershipStore::shard_option_name( 0 );
		$paths                               = $this->filenames_for_shard( 0, 2 );
		$base                                = $this->records( $paths[0], 'd', 4 );
		$successor                           = $this->records( $paths[1], 'e', 5 );
		$database                            = $this->database( array( $option => $this->serialize_records( $base ) ) );
		$database->replacement_before_delete = $this->serialize_records( $successor );
		$GLOBALS['wpdb']                     = $database;

		$this->assertFalse( $this->compare_and_swap( $option, $this->observation( $base ), array() ) );
		$this->assertSame( $this->serialize_records( $successor ), $database->rows[ $option ] );
	}

	public function test_uncontended_direct_insert_update_and_delete_succeed(): void {
		$option          = StaticOwnershipStore::shard_option_name( 0 );
		$paths           = $this->filenames_for_shard( 0, 2 );
		$inserted        = $this->records( $paths[0], 'f', 6 );
		$updated         = $this->records( $paths[1], '1', 7 );
		$database        = $this->database();
		$GLOBALS['wpdb'] = $database;

		$this->assertTrue( $this->compare_and_swap( $option, $this->missing_observation(), $inserted ) );
		$this->assertSame( $this->serialize_records( $inserted ), $database->rows[ $option ] );
		$this->assertTrue( $this->compare_and_swap( $option, $this->observation( $inserted ), $updated ) );
		$this->assertSame( $this->serialize_records( $updated ), $database->rows[ $option ] );
		$this->assertTrue( $this->compare_and_swap( $option, $this->observation( $updated ), array() ) );
		$this->assertArrayNotHasKey( $option, $database->rows );
	}

	public function test_uncontended_direct_exact_no_op_succeeds(): void {
		$option          = StaticOwnershipStore::shard_option_name( 0 );
		$path            = $this->filenames_for_shard( 0, 1 )[0];
		$records         = $this->records( $path, '2', 8 );
		$raw             = $this->serialize_records( $records );
		$database        = $this->database( array( $option => $raw ) );
		$GLOBALS['wpdb'] = $database;

		$this->assertTrue( $this->compare_and_swap( $option, $this->observation( $records ), $records ) );
		$this->assertSame( $raw, $database->rows[ $option ] );
	}

	public function test_direct_mutations_include_and_enforce_the_database_fence_predicate(): void {
		$option                              = StaticOwnershipStore::shard_option_name( 0 );
		$paths                               = $this->filenames_for_shard( 0, 2 );
		$inserted                            = $this->records( $paths[0], '6', 12 );
		$updated                             = $this->records( $paths[1], '7', 13 );
		$fence                               = array(
			'name'          => 'cybermaps:test-fence',
			'connection_id' => 41,
		);
		$database                            = $this->database();
		$database->held_fence_name           = $fence['name'];
		$database->fence_owner_connection_id = 41;
		$database->current_connection_id     = 41;
		$GLOBALS['wpdb']                     = $database;

		$this->assertTrue( $this->compare_and_swap( $option, $this->missing_observation(), $inserted, $fence ) );
		$this->assertTrue( $this->compare_and_swap( $option, $this->observation( $inserted ), $updated, $fence ) );
		$this->assertTrue( $this->compare_and_swap( $option, $this->observation( $updated ), array(), $fence ) );

		$mutations = \array_values(
			\array_filter(
				$database->queries,
				static fn( string $query ): bool => \str_starts_with( $query, 'INSERT IGNORE' )
					|| \str_starts_with( $query, 'UPDATE' )
					|| \str_starts_with( $query, 'DELETE' )
			)
		);
		$this->assertCount( 3, $mutations );
		foreach ( $mutations as $query ) {
			$this->assertStringContainsString( 'IS_USED_LOCK(%s) = CONNECTION_ID()', $query );
			$this->assertStringContainsString( 'CONNECTION_ID() = %d', $query );
		}
	}

	public function test_direct_mutation_rejects_a_lost_or_reconnected_fence(): void {
		$option                              = StaticOwnershipStore::shard_option_name( 0 );
		$paths                               = $this->filenames_for_shard( 0, 2 );
		$base                                = $this->records( $paths[0], '8', 14 );
		$desired                             = $this->records( $paths[1], '9', 15 );
		$fence                               = array(
			'name'          => 'cybermaps:test-fence-loss',
			'connection_id' => 51,
		);
		$database                            = $this->database( array( $option => $this->serialize_records( $base ) ) );
		$database->held_fence_name           = $fence['name'];
		$database->fence_owner_connection_id = null;
		$database->current_connection_id     = 51;
		$GLOBALS['wpdb']                     = $database;

		$this->assertFalse( $this->compare_and_swap( $option, $this->observation( $base ), $desired, $fence ) );
		$this->assertSame( $this->serialize_records( $base ), $database->rows[ $option ] );

		$database->fence_owner_connection_id = 51;
		$database->current_connection_id     = 52;
		$this->assertFalse( $this->compare_and_swap( $option, $this->observation( $base ), $desired, $fence ) );
		$this->assertSame( $this->serialize_records( $base ), $database->rows[ $option ] );
	}

	public function test_fenced_no_op_verification_rejects_fence_loss_after_the_update(): void {
		$option                              = StaticOwnershipStore::shard_option_name( 0 );
		$path                                = $this->filenames_for_shard( 0, 1 )[0];
		$records                             = $this->records( $path, 'a', 16 );
		$fence                               = array(
			'name'          => 'cybermaps:test-no-op-fence',
			'connection_id' => 61,
		);
		$database                            = $this->database( array( $option => $this->serialize_records( $records ) ) );
		$database->held_fence_name           = $fence['name'];
		$database->fence_owner_connection_id = 61;
		$database->current_connection_id     = 61;
		$database->lose_fence_after_no_op    = true;
		$GLOBALS['wpdb']                     = $database;

		$this->assertFalse( $this->compare_and_swap( $option, $this->observation( $records ), $records, $fence ) );
		$this->assertSame( $this->serialize_records( $records ), $database->rows[ $option ] );
	}

	public function test_constructor_injected_lock_fails_closed_before_acquisition(): void {
		$path = $this->filenames_for_shard( 0, 1 )[0];
		\update_option( StaticOwnershipStore::SCHEMA_OPTION, StaticOwnershipStore::SCHEMA_VERSION, false );
		$lock  = new OptionLeaseLock( 'cybermaps-test-unacquired-ownership-fence', 300, 60, true );
		$store = new StaticOwnershipStore( $lock );

		$this->assertFalse( $store->set_hash( $path, \str_repeat( 'b', 32 ), 17, true ) );
		$this->assertArrayNotHasKey(
			StaticOwnershipStore::shard_option_name( 0 ),
			$GLOBALS['cybermaps_mock_options']
		);
	}

	public function test_constructor_injected_lock_binds_write_shard_to_its_captured_connection(): void {
		$shard           = 0;
		$option          = StaticOwnershipStore::shard_option_name( $shard );
		$paths           = $this->filenames_for_shard( $shard, 2 );
		$inserted        = $this->records( $paths[0], 'c', 18 );
		$reconnected     = $this->records( $paths[1], 'd', 19 );
		$database        = $this->database();
		$GLOBALS['wpdb'] = $database;
		$lock            = new OptionLeaseLock( 'cybermaps-test-owned-shard-fence', 300, 60, true );

		$this->assertTrue( $lock->acquire() );
		try {
			$fence = $lock->get_database_fence();
			$this->assertNotNull( $fence );
			$database->held_fence_name                          = $fence['name'];
			$database->fence_owner_connection_id                = $fence['connection_id'];
			$database->current_connection_id                    = $fence['connection_id'];
			$GLOBALS['cybermaps_test_static_ownership_use_sql'] = true;

			$store = new StaticOwnershipStore( $lock );
			( new \ReflectionProperty( StaticOwnershipStore::class, 'schema_cache' ) )->setValue( $store, StaticOwnershipStore::SCHEMA_VERSION );
			( new \ReflectionProperty( StaticOwnershipStore::class, 'loaded_shards' ) )->setValue( $store, array( $shard => array() ) );
			( new \ReflectionProperty( StaticOwnershipStore::class, 'shard_observations' ) )->setValue(
				$store,
				array( $shard => $this->missing_observation() )
			);

			$this->assertTrue( $this->write_shard( $store, $shard, $inserted ) );
			$this->assertSame( $this->serialize_records( $inserted ), $database->rows[ $option ] );

			$database->current_connection_id = $fence['connection_id'] + 1;
			$this->assertFalse( $this->write_shard( $store, $shard, $reconnected ) );
			$this->assertSame( $this->serialize_records( $inserted ), $database->rows[ $option ] );
		} finally {
			unset( $GLOBALS['cybermaps_test_static_ownership_use_sql'] );
			$lock->release();
		}
	}

	public function test_same_instance_retry_cannot_overwrite_a_valid_post_cas_successor(): void {
		$shard                                = 0;
		$option                               = StaticOwnershipStore::shard_option_name( $shard );
		$paths                                = $this->filenames_for_shard( $shard, 3 );
		$base                                 = $this->records( $paths[0], '3', 9 );
		$desired                              = $this->records( $paths[1], '4', 10 );
		$successor                            = $this->records( $paths[2], '5', 11 );
		$database                             = $this->database( array( $option => $this->serialize_records( $base ) ) );
		$database->replacement_after_update   = $this->serialize_records( $successor );
		$database->mirror_option_after_update = $option;
		$database->mirror_value_after_update  = $successor;
		$GLOBALS['wpdb']                      = $database;

		$store = new StaticOwnershipStore();
		( new \ReflectionProperty( StaticOwnershipStore::class, 'schema_cache' ) )->setValue( $store, StaticOwnershipStore::SCHEMA_VERSION );
		( new \ReflectionProperty( StaticOwnershipStore::class, 'loaded_shards' ) )->setValue( $store, array( $shard => $base ) );
		( new \ReflectionProperty( StaticOwnershipStore::class, 'shard_observations' ) )->setValue(
			$store,
			array( $shard => $this->observation( $base ) )
		);

		try {
			$this->assertFalse( $this->write_shard( $store, $shard, $desired ) );
			$this->assertSame( $this->serialize_records( $successor ), $database->rows[ $option ] );
			$this->assertSame( $successor, $GLOBALS['cybermaps_mock_options'][ $option ] );

			$this->assertFalse( $this->write_shard( $store, $shard, $desired ) );
			$this->assertSame( $this->serialize_records( $successor ), $database->rows[ $option ] );
			$this->assertSame( $successor, $GLOBALS['cybermaps_mock_options'][ $option ] );
		} finally {
			unset( $GLOBALS['cybermaps_mock_options'][ $option ] );
		}
	}

	/**
	 * @param array<string,array{hash:string,generation:int}> $observed
	 * @param array<string,array{hash:string,generation:int}> $records
	 */
	private function compare_and_swap( string $option, array $observed, array $records, ?array $fence = null ): bool {
		return (bool) ( new \ReflectionMethod( StaticOwnershipStore::class, 'compare_and_swap_option' ) )->invoke(
			null,
			$option,
			$observed,
			$records,
			$fence
		);
	}

	/** @param array<string,array{hash:string,generation:int}> $records */
	private function write_shard( StaticOwnershipStore $store, int $shard, array $records ): bool {
		return (bool) ( new \ReflectionMethod( StaticOwnershipStore::class, 'write_shard' ) )->invoke(
			$store,
			$shard,
			$records
		);
	}

	/**
	 * @param array<string,array{hash:string,generation:int}> $records
	 * @return array{exists:bool,value:mixed,raw:string|null,direct:bool,failed:bool}
	 */
	private function observation( array $records ): array {
		return array(
			'exists' => true,
			'value'  => $records,
			'raw'    => $this->serialize_records( $records ),
			'direct' => true,
			'failed' => false,
		);
	}

	/** @return array{exists:bool,value:mixed,raw:string|null,direct:bool,failed:bool} */
	private function missing_observation(): array {
		return array(
			'exists' => false,
			'value'  => null,
			'raw'    => null,
			'direct' => true,
			'failed' => false,
		);
	}

	/** @return array<string,array{hash:string,generation:int}> */
	private function records( string $path, string $hash_character, int $generation ): array {
		return array(
			$path => array(
				'hash'       => \str_repeat( $hash_character, 32 ),
				'generation' => $generation,
			),
		);
	}

	/** @param array<string,array{hash:string,generation:int}> $records */
	private function serialize_records( array $records ): string {
		return \function_exists( 'maybe_serialize' )
			? (string) \maybe_serialize( $records )
			: \serialize( $records ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
	}

	/** @param array<string,string> $rows */
	private function database( array $rows = array() ): object {
		return new class( $rows ) {
			public string $options    = 'wp_options';
			public string $last_error = '';
			/** @var array<string,string> */
			public array $rows;
			/** @var string[] */
			public array $queries                      = array();
			public ?string $held_fence_name            = null;
			public ?int $fence_owner_connection_id     = null;
			public int $current_connection_id          = 0;
			public bool $lose_fence_after_no_op        = false;
			public ?string $replacement_before_update  = null;
			public ?string $replacement_before_delete  = null;
			public ?string $replacement_after_update   = null;
			public ?string $mirror_option_after_update = null;
			public mixed $mirror_value_after_update    = null;

			/** @param array<string,string> $rows */
			public function __construct( array $rows ) {
				$this->rows = $rows;
			}

			/** @return array{query:string,args:array<int,mixed>} */
			public function prepare( string $query, mixed ...$args ): array {
				return array(
					'query' => $query,
					'args'  => $args,
				);
			}

			/** @param array{query:string,args:array<int,mixed>} $prepared */
			public function get_var( array $prepared ): ?string {
				$this->queries[] = $prepared['query'];
				if ( ! $this->fence_allows( $prepared ) ) {
					return null;
				}
				$option = (string) ( $prepared['args'][1] ?? '' );
				return $this->rows[ $option ] ?? null;
			}

			/** @param array{query:string,args:array<int,mixed>} $prepared */
			public function query( array $prepared ): int|false {
				$query           = \ltrim( $prepared['query'] );
				$args            = $prepared['args'];
				$this->queries[] = $query;
				if ( ! $this->fence_allows( $prepared ) ) {
					return 0;
				}
				if ( \str_starts_with( $query, 'INSERT IGNORE' ) ) {
					$option = (string) ( $args[1] ?? '' );
					if ( \array_key_exists( $option, $this->rows ) ) {
						return 0;
					}
					$this->rows[ $option ] = (string) ( $args[2] ?? '' );
					return 1;
				}
				if ( \str_starts_with( $query, 'UPDATE' ) ) {
					$option = (string) ( $args[3] ?? '' );
					if ( null !== $this->replacement_before_update ) {
						$this->rows[ $option ]           = $this->replacement_before_update;
						$this->replacement_before_update = null;
					}
					if ( ! isset( $this->rows[ $option ] ) || ( $args[4] ?? null ) !== $this->rows[ $option ] ) {
						return 0;
					}
					$new_raw = (string) ( $args[1] ?? '' );
					if ( $new_raw === $this->rows[ $option ] ) {
						if ( $this->lose_fence_after_no_op ) {
							$this->fence_owner_connection_id = null;
							$this->lose_fence_after_no_op    = false;
						}
						return 0;
					}
					$this->rows[ $option ] = $new_raw;
					if ( null !== $this->replacement_after_update ) {
						$this->rows[ $option ]          = $this->replacement_after_update;
						$this->replacement_after_update = null;
						if ( null !== $this->mirror_option_after_update ) {
							$GLOBALS['cybermaps_mock_options'][ $this->mirror_option_after_update ] = $this->mirror_value_after_update;
						}
					}
					return 1;
				}
				if ( \str_starts_with( $query, 'DELETE' ) ) {
					$option = (string) ( $args[1] ?? '' );
					if ( null !== $this->replacement_before_delete ) {
						$this->rows[ $option ]           = $this->replacement_before_delete;
						$this->replacement_before_delete = null;
					}
					if ( ! isset( $this->rows[ $option ] ) || ( $args[2] ?? null ) !== $this->rows[ $option ] ) {
						return 0;
					}
					unset( $this->rows[ $option ] );
					return 1;
				}

				return false;
			}

			/** @param array{query:string,args:array<int,mixed>} $prepared */
			private function fence_allows( array $prepared ): bool {
				if ( ! \str_contains( $prepared['query'], 'IS_USED_LOCK(%s) = CONNECTION_ID()' ) ) {
					return true;
				}
				// OptionLeaseLock's own acquisition/release statement proves the
				// session owner but does not carry StaticOwnershipStore's captured-ID
				// predicate. Its database-session behavior has separate focused tests.
				if ( ! \str_contains( $prepared['query'], 'CONNECTION_ID() = %d' ) ) {
					return true;
				}

				$args          = $prepared['args'];
				$captured_id   = (int) ( $args[ \count( $args ) - 1 ] ?? 0 );
				$captured_name = (string) ( $args[ \count( $args ) - 2 ] ?? '' );
				return null !== $this->held_fence_name
					&& $captured_name === $this->held_fence_name
					&& $this->fence_owner_connection_id === $this->current_connection_id
					&& $captured_id === $this->current_connection_id;
			}
		};
	}

	/** @return string[] */
	private function filenames_for_shard( int $shard, int $count ): array {
		$filenames = array();
		$attempt   = 0;
		$found     = 0;
		while ( $count > $found ) {
			$filename = \sprintf( 'cas-owned-%05d.txt', $attempt );
			if ( StaticOwnershipStore::shard_for_path( $filename ) === $shard ) {
				$filenames[] = $filename;
				++$found;
			}
			++$attempt;
		}
		return $filenames;
	}
}
