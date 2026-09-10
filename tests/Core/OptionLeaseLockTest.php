<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Core;

use Cybermaps\Core\OptionLeaseLock;

final class OptionLeaseLockTest extends \WP_UnitTestCase {
	private const OPTION = 'cybermaps_test_option_lease';

	private bool $had_wpdb;
	private mixed $previous_wpdb;
	private bool $had_sql_switch;
	private mixed $previous_sql_switch;

	protected function setUp(): void {
		parent::setUp();
		$this->had_wpdb      = \array_key_exists( 'wpdb', $GLOBALS );
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->had_sql_switch      = \array_key_exists( 'cybermaps_test_database_session_lock_use_sql', $GLOBALS );
		$this->previous_sql_switch = $GLOBALS['cybermaps_test_database_session_lock_use_sql'] ?? null;
		\delete_option( self::OPTION );
	}

	protected function tearDown(): void {
		\delete_option( self::OPTION );
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		if ( $this->had_sql_switch ) {
			$GLOBALS['cybermaps_test_database_session_lock_use_sql'] = $this->previous_sql_switch;
		} else {
			unset( $GLOBALS['cybermaps_test_database_session_lock_use_sql'] );
		}
		parent::tearDown();
	}

	public function test_affected_direct_insert_is_ownership_proof_without_add_option_or_verification_read(): void {
		$cached_lock = array(
			'token' => 'stale-wordpress-option-cache',
			'time'  => \time(),
		);
		\update_option( self::OPTION, $cached_lock, false );
		$database        = $this->database( null, true );
		$GLOBALS['wpdb'] = $database;
		$lock            = new OptionLeaseLock( self::OPTION, 300, 60 );

		$this->assertTrue( $lock->acquire() );
		$this->assertSame( 1, $database->insert_count );
		$this->assertSame( 0, $database->select_count );
		$this->assertSame( $cached_lock, $GLOBALS['cybermaps_mock_options'][ self::OPTION ] );
		$this->assertStringContainsString( 'INSERT IGNORE INTO %i', $database->queries[0]['query'] );
		$stored = \maybe_unserialize( (string) $database->raw );
		$this->assertIsArray( $stored );
		$this->assertSame( $lock->get_token(), $stored['token'] );
		$this->assertLessThanOrEqual( 2, \abs( \time() - (int) $stored['time'] ) );
		$lock->reset_local_state();
	}

	public function test_direct_acquisition_rejects_a_live_contender_without_deleting_it(): void {
		$contender       = array(
			'token' => 'live-contender',
			'time'  => \time(),
		);
		$raw             = \serialize( $contender ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		$database        = $this->database( $raw );
		$GLOBALS['wpdb'] = $database;
		$lock            = new OptionLeaseLock( self::OPTION, 300, 60 );

		$this->assertFalse( $lock->acquire() );
		$this->assertNull( $lock->get_token() );
		$this->assertSame( 1, $database->insert_count );
		$this->assertSame( 1, $database->select_count );
		$this->assertSame( 0, $database->delete_count );
		$this->assertSame( $raw, $database->raw );
	}

	public function test_far_future_lock_timestamp_is_reclaimed_as_stale(): void {
		$future          = array(
			'token' => 'clock-skewed-contender',
			'time'  => \time() + 3600,
		);
		$database        = $this->database( \serialize( $future ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		$GLOBALS['wpdb'] = $database;
		$lock            = new OptionLeaseLock( self::OPTION, 300, 60 );

		$this->assertTrue( $lock->acquire() );
		$this->assertSame( 2, $database->insert_count );
		$this->assertSame( 1, $database->select_count );
		$this->assertSame( 1, $database->delete_count );
		$stored = \maybe_unserialize( (string) $database->raw );
		$this->assertIsArray( $stored );
		$this->assertNotSame( $future['token'], $stored['token'] );
		$this->assertSame( $lock->get_token(), $stored['token'] );
		$this->assertLessThanOrEqual( 2, \abs( \time() - (int) $stored['time'] ) );
		$lock->reset_local_state();
	}

	public function test_required_database_fence_fails_before_option_insert_when_busy(): void {
		$GLOBALS['cybermaps_test_database_session_lock_use_sql'] = true;
		$database        = $this->fenced_database( null, array( 'get_lock' => array( '0' ) ) );
		$GLOBALS['wpdb'] = $database;
		$lock            = new OptionLeaseLock( self::OPTION, 300, 60, true );

		$this->assertTrue( $lock->requires_database_fence() );
		$this->assertFalse( $lock->acquire() );
		$this->assertSame( 0, $database->insert_count );
		$this->assertNull( $lock->get_database_fence() );
	}

	public function test_stale_takeover_guard_blocks_delete_and_releases_database_fence(): void {
		$GLOBALS['cybermaps_test_database_session_lock_use_sql'] = true;
		$stale           = \serialize(
			array(
				'token' => 'stale-owner',
				'time'  => \time() - 600,
			)
		); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		$database        = $this->fenced_database(
			$stale,
			array(
				'get_lock'      => array( '1' ),
				'connection_id' => array( '410' ),
				'used_lock'     => array( '1', '1', '1' ),
				'release_lock'  => array( '1' ),
			)
		);
		$GLOBALS['wpdb'] = $database;
		$guard_calls     = 0;
		$lock            = new OptionLeaseLock(
			self::OPTION,
			300,
			60,
			true,
			static function () use ( &$guard_calls ): bool {
				++$guard_calls;
				return false;
			}
		);

		$this->assertFalse( $lock->acquire() );
		$this->assertSame( 1, $guard_calls );
		$this->assertSame( 0, $database->delete_count );
		$this->assertSame( $stale, $database->raw );
		$this->assertSame( 1, $this->fenced_query_count( $database, 'RELEASE_LOCK' ) );
	}

	public function test_required_fence_identity_is_exposed_and_release_orders_option_first(): void {
		$GLOBALS['cybermaps_test_database_session_lock_use_sql'] = true;
		$database        = $this->fenced_database(
			null,
			array(
				'get_lock'      => array( '1' ),
				'connection_id' => array( '515' ),
				'used_lock'     => array( '1', '1', '1', '1', '1', '1' ),
				'release_lock'  => array( '1' ),
			)
		);
		$GLOBALS['wpdb'] = $database;
		$lock            = new OptionLeaseLock( self::OPTION, 300, 60, true );

		$this->assertTrue( $lock->acquire() );
		$fence = $lock->get_database_fence();
		$this->assertIsArray( $fence );
		$this->assertSame( 515, $fence['connection_id'] );
		$this->assertLessThanOrEqual( 64, \strlen( $fence['name'] ) );
		$lock->release();

		$delete_index  = $this->fenced_query_index( $database, 'DELETE FROM' );
		$release_index = $this->fenced_query_index( $database, 'RELEASE_LOCK' );
		$this->assertNotNull( $delete_index );
		$this->assertNotNull( $release_index );
		$this->assertLessThan( $release_index, $delete_index );
		$this->assertNull( $database->raw );
		$insert_index = $this->fenced_query_index( $database, 'INSERT IGNORE' );
		$this->assertNotNull( $insert_index );
		$this->assertStringContainsString( 'IS_USED_LOCK(%s) = %d AND CONNECTION_ID() = %d', $database->queries[ $insert_index ]['query'] );
		$this->assertSame( array( 515, 515 ), \array_slice( $database->queries[ $insert_index ]['args'], -2 ) );
		$this->assertStringContainsString( 'IS_USED_LOCK(%s) = %d AND CONNECTION_ID() = %d', $database->queries[ $delete_index ]['query'] );
		$this->assertSame( array( 515, 515 ), \array_slice( $database->queries[ $delete_index ]['args'], -2 ) );
	}

	public function test_direct_renewal_is_guarded_by_same_statement_database_fence(): void {
		$GLOBALS['cybermaps_test_database_session_lock_use_sql'] = true;
		$database        = $this->fenced_database(
			null,
			array(
				'get_lock'      => array( '1' ),
				'connection_id' => array( '570' ),
				'used_lock'     => array( '1', '1', '1', '1', '1', '1', '1' ),
				'release_lock'  => array( '1' ),
			)
		);
		$GLOBALS['wpdb'] = $database;
		$lock            = new OptionLeaseLock( self::OPTION, 300, 60, true );

		$this->assertTrue( $lock->acquire() );
		$database->raw = \serialize(
			array(
				'token' => $lock->get_token(),
				'time'  => \time() - 120,
			)
		); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		$this->assertTrue( $lock->maintain() );
		$this->assertSame( 1, $database->update_count );
		$update_index = $this->fenced_query_index( $database, 'UPDATE ' );
		$this->assertNotNull( $update_index );
		$this->assertStringContainsString( 'IS_USED_LOCK(%s) = %d AND CONNECTION_ID() = %d', $database->queries[ $update_index ]['query'] );
		$this->assertSame( array( 570, 570 ), \array_slice( $database->queries[ $update_index ]['args'], -2 ) );
		$lock->release();
	}

	public function test_lost_database_fence_prevents_option_delete(): void {
		$GLOBALS['cybermaps_test_database_session_lock_use_sql'] = true;
		$database        = $this->fenced_database(
			null,
			array(
				'get_lock'      => array( '1' ),
				'connection_id' => array( '620' ),
				'used_lock'     => array( '1', '1', '0', '0' ),
			)
		);
		$GLOBALS['wpdb'] = $database;
		$lock            = new OptionLeaseLock( self::OPTION, 300, 60, true );

		$this->assertTrue( $lock->acquire() );
		$this->assertFalse( $lock->maintain() );
		$this->assertTrue( $lock->is_lost() );
		$stored = $database->raw;
		$lock->release();
		$this->assertSame( $stored, $database->raw );
		$this->assertSame( 0, $database->delete_count );
		$this->assertSame( 0, $this->fenced_query_count( $database, 'RELEASE_LOCK' ) );
	}

	private function database( ?string $raw, bool $fail_reads = false ): object {
		return new class( $raw, $fail_reads ) {
			public string $options = 'wp_options';
			public ?string $raw;
			public bool $fail_reads;
			public int $insert_count = 0;
			public int $select_count = 0;
			public int $delete_count = 0;
			/** @var array<int,array{query:string,args:array<int,mixed>}> */
			public array $queries = array();

			public function __construct( ?string $raw, bool $fail_reads ) {
				$this->raw        = $raw;
				$this->fail_reads = $fail_reads;
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
				$this->queries[] = $prepared;
				++$this->select_count;
				return $this->fail_reads ? null : $this->raw;
			}

			/** @param array{query:string,args:array<int,mixed>} $prepared */
			public function query( array $prepared ): int|false {
				$this->queries[] = $prepared;
				$query           = \ltrim( $prepared['query'] );
				$args            = $prepared['args'];

				if ( \str_starts_with( $query, 'INSERT IGNORE' ) ) {
					++$this->insert_count;
					if ( null !== $this->raw ) {
						return 0;
					}
					$this->raw = (string) ( $args[2] ?? '' );
					return 1;
				}

				if ( \str_starts_with( $query, 'DELETE' ) ) {
					++$this->delete_count;
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

	/**
	 * @param array<string,array<int,int|string|null>> $session_responses
	 */
	private function fenced_database( ?string $raw, array $session_responses ): object {
		return new class( $raw, $session_responses ) {
			public string $options = 'wp_options';
			public ?string $raw;
			public int $insert_count = 0;
			public int $select_count = 0;
			public int $delete_count = 0;
			public int $update_count = 0;
			/** @var array<int,array{query:string,args:array<int,mixed>}> */
			public array $queries = array();
			/** @var array<string,array<int,int|string|null>> */
			private array $session_responses;

			/** @param array<string,array<int,int|string|null>> $session_responses */
			public function __construct( ?string $raw, array $session_responses ) {
				$this->raw               = $raw;
				$this->session_responses = $session_responses;
			}

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
					return \array_shift( $this->session_responses['get_lock'] );
				}
				if ( \str_contains( $query, 'IS_USED_LOCK' ) ) {
					return \array_shift( $this->session_responses['used_lock'] );
				}
				if ( \str_contains( $query, 'CONNECTION_ID' ) ) {
					return \array_shift( $this->session_responses['connection_id'] );
				}
				if ( \str_contains( $query, 'RELEASE_LOCK' ) ) {
					return \array_shift( $this->session_responses['release_lock'] );
				}

				++$this->select_count;
				return $this->raw;
			}

			/** @param array{query:string,args:array<int,mixed>} $prepared */
			public function query( array $prepared ): int|false {
				$this->queries[] = $prepared;
				$query           = \ltrim( $prepared['query'] );
				$args            = $prepared['args'];

				if ( \str_starts_with( $query, 'INSERT IGNORE' ) ) {
					++$this->insert_count;
					if ( null !== $this->raw ) {
						return 0;
					}
					$this->raw = (string) ( $args[2] ?? '' );
					return 1;
				}

				if ( \str_starts_with( $query, 'DELETE' ) ) {
					++$this->delete_count;
					if ( null === $this->raw || ( $args[2] ?? null ) !== $this->raw ) {
						return 0;
					}
					$this->raw = null;
					return 1;
				}

				if ( \str_starts_with( $query, 'UPDATE' ) ) {
					++$this->update_count;
					if ( null === $this->raw || ( $args[3] ?? null ) !== $this->raw ) {
						return 0;
					}
					$this->raw = (string) ( $args[1] ?? '' );
					return 1;
				}

				return false;
			}
		};
	}

	private function fenced_query_count( object $database, string $needle ): int {
		return \count(
			\array_filter(
				$database->queries,
				static fn ( array $query ): bool => \str_contains( $query['query'], $needle )
			)
		);
	}

	private function fenced_query_index( object $database, string $needle ): ?int {
		foreach ( $database->queries as $index => $query ) {
			if ( \str_contains( $query['query'], $needle ) ) {
				return $index;
			}
		}
		return null;
	}
}
