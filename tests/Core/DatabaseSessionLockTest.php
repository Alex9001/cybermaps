<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Core;

use Cybermaps\Core\DatabaseSessionLock;

final class DatabaseSessionLockTest extends \WP_UnitTestCase {
	private bool $had_wpdb;
	private mixed $previous_wpdb;
	private bool $had_sql_switch;
	private mixed $previous_sql_switch;

	protected function setUp(): void {
		parent::setUp();
		$this->had_wpdb            = \array_key_exists( 'wpdb', $GLOBALS );
		$this->previous_wpdb       = $GLOBALS['wpdb'] ?? null;
		$this->had_sql_switch      = \array_key_exists( 'cybermaps_test_database_session_lock_use_sql', $GLOBALS );
		$this->previous_sql_switch = $GLOBALS['cybermaps_test_database_session_lock_use_sql'] ?? null;
		$GLOBALS['cybermaps_test_database_session_lock_use_sql'] = true;
	}

	protected function tearDown(): void {
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

	public function test_acquires_verifies_and_releases_on_the_same_connection(): void {
		$database        = $this->database(
			array(
				'get_lock'      => array( '1' ),
				'connection_id' => array( '7041' ),
				'used_lock'     => array( '1', '1', '1' ),
				'release_lock'  => array( '1' ),
			)
		);
		$GLOBALS['wpdb'] = $database;
		$lock            = new DatabaseSessionLock( 'cybermaps-test-session-fence' );

		$this->assertTrue( $lock->acquire() );
		$fence = $lock->get_fence();
		$this->assertIsArray( $fence );
		$this->assertSame( 7041, $fence['connection_id'] );
		$this->assertLessThanOrEqual( 64, \strlen( $fence['name'] ) );
		$this->assertMatchesRegularExpression( '/^cybermaps:[a-f0-9]{48}$/', $fence['name'] );

		$lock->release();
		$this->assertStringContainsString( 'RELEASE_LOCK', $database->queries[ \array_key_last( $database->queries ) ]['query'] );
	}

	public function test_busy_lock_fails_without_reading_connection_identity(): void {
		$database        = $this->database( array( 'get_lock' => array( '0' ) ) );
		$GLOBALS['wpdb'] = $database;
		$lock            = new DatabaseSessionLock( 'cybermaps-test-busy-fence' );

		$this->assertFalse( $lock->acquire() );
		$this->assertCount( 1, $database->queries );
		$this->assertStringContainsString( 'GET_LOCK', $database->queries[0]['query'] );
	}

	public function test_failed_post_acquire_proof_releases_lock_and_can_retry(): void {
		$database        = $this->database(
			array(
				'get_lock'      => array( '1', '1' ),
				'connection_id' => array( '81', '81' ),
				'used_lock'     => array( '0', '1', '1' ),
				'release_lock'  => array( '0', '1' ),
			)
		);
		$GLOBALS['wpdb'] = $database;
		$lock            = new DatabaseSessionLock( 'cybermaps-test-proof-fence' );

		$this->assertFalse( $lock->acquire() );
		$this->assertSame( 1, $this->query_count( $database, 'RELEASE_LOCK' ) );
		$this->assertTrue( $lock->acquire() );
		$lock->release();
		$this->assertSame( 2, $this->query_count( $database, 'RELEASE_LOCK' ) );
	}

	public function test_reconnect_permanently_loses_current_ownership_attempt(): void {
		$database        = $this->database(
			array(
				'get_lock'      => array( '1' ),
				'connection_id' => array( '101' ),
				'used_lock'     => array( '1', '0', '0' ),
			)
		);
		$GLOBALS['wpdb'] = $database;
		$lock            = new DatabaseSessionLock( 'cybermaps-test-reconnect-fence' );

		$this->assertTrue( $lock->acquire() );
		$this->assertFalse( $lock->maintain() );
		$this->assertTrue( $lock->is_lost() );
		$failed_proof = $database->queries[ \array_key_last( $database->queries ) ];
		$this->assertStringContainsString( 'CONNECTION_ID() = %d', $failed_proof['query'] );
		$this->assertSame( array( 101, 101 ), \array_slice( $failed_proof['args'], -2 ) );
		$query_count = \count( $database->queries );
		$this->assertFalse( $lock->maintain() );
		$this->assertCount( $query_count, $database->queries );
		$lock->release();
		$this->assertSame( 0, $this->query_count( $database, 'RELEASE_LOCK' ) );
	}

	public function test_ownership_read_error_fails_closed_and_stays_lost(): void {
		$database        = $this->database(
			array(
				'get_lock'      => array( '1' ),
				'connection_id' => array( '301' ),
				'used_lock'     => array( '1', null, null ),
			)
		);
		$GLOBALS['wpdb'] = $database;
		$lock            = new DatabaseSessionLock( 'cybermaps-test-error-fence' );

		$this->assertTrue( $lock->acquire() );
		$this->assertNull( $lock->get_fence() );
		$this->assertTrue( $lock->is_lost() );
		$query_count = \count( $database->queries );
		$this->assertFalse( $lock->maintain() );
		$this->assertCount( $query_count, $database->queries );
		$lock->release();
	}

	public function test_non_core_database_drop_in_fails_closed_without_querying(): void {
		$GLOBALS['cybermaps_test_database_session_lock_use_sql'] = 'validate-production';
		$database        = $this->database( array( 'get_lock' => array( '1' ) ) );
		$GLOBALS['wpdb'] = $database;
		$lock            = new DatabaseSessionLock( 'cybermaps-test-drop-in-fence' );

		$this->assertFalse( $lock->acquire() );
		$this->assertSame( array(), $database->queries );
	}

	public function test_default_phpunit_path_uses_request_local_fence(): void {
		unset( $GLOBALS['cybermaps_test_database_session_lock_use_sql'], $GLOBALS['wpdb'] );
		$first  = new DatabaseSessionLock( 'cybermaps-test-local-fence' );
		$second = new DatabaseSessionLock( 'cybermaps-test-local-fence' );

		$this->assertTrue( $first->acquire() );
		$this->assertFalse( $second->acquire() );
		$this->assertNotNull( $first->get_fence() );
		$first->release();
		$this->assertTrue( $second->acquire() );
		$second->release();
	}

	public function test_explicit_table_scope_is_shared_across_blog_contexts(): void {
		unset( $GLOBALS['cybermaps_test_database_session_lock_use_sql'], $GLOBALS['wpdb'] );
		$original_blog_id = $GLOBALS['cybermaps_mock_current_blog_id'] ?? 1;
		$GLOBALS['cybermaps_mock_current_blog_id'] = 1;
		$first = new DatabaseSessionLock( 'translation-schema', 'table:wp_cybermaps_translations' );
		$this->assertTrue( $first->acquire() );

		$GLOBALS['cybermaps_mock_current_blog_id'] = 200;
		$same_table = new DatabaseSessionLock( 'translation-schema', 'table:wp_cybermaps_translations' );
		$other_table = new DatabaseSessionLock( 'translation-schema', 'table:other_cybermaps_translations' );
		$this->assertFalse( $same_table->acquire() );
		$this->assertTrue( $other_table->acquire() );

		$other_table->release();
		$first->release();
		$GLOBALS['cybermaps_mock_current_blog_id'] = $original_blog_id;
	}

	/**
	 * @param array<string,array<int,int|string|null>> $responses
	 */
	private function database( array $responses ): object {
		return new class( $responses ) {
			/** @var array<string,array<int,int|string|null>> */
			private array $responses;
			/** @var array<int,array{query:string,args:array<int,mixed>}> */
			public array $queries = array();

			/** @param array<string,array<int,int|string|null>> $responses */
			public function __construct( array $responses ) {
				$this->responses = $responses;
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

				$key = match ( true ) {
					\str_contains( $query, 'GET_LOCK' )       => 'get_lock',
					\str_contains( $query, 'IS_USED_LOCK' )   => 'used_lock',
					\str_contains( $query, 'CONNECTION_ID' )  => 'connection_id',
					\str_contains( $query, 'RELEASE_LOCK' )   => 'release_lock',
					default                                   => 'unknown',
				};
				return \array_shift( $this->responses[ $key ] );
			}
		};
	}

	private function query_count( object $database, string $needle ): int {
		return \count(
			\array_filter(
				$database->queries,
				static fn ( array $query ): bool => \str_contains( $query['query'], $needle )
			)
		);
	}
}
