<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Core;

use Cybermaps\Core\AtomicOptionSequence;

final class AtomicOptionSequenceTest extends \WP_UnitTestCase {
	private const OPTION = 'cybermaps_test_atomic_sequence';
	private bool $had_wpdb;
	private mixed $previous_wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->had_wpdb      = \array_key_exists( 'wpdb', $GLOBALS );
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		unset( $GLOBALS['wpdb'] );
		\delete_option( self::OPTION );
	}

	protected function tearDown(): void {
		\delete_option( self::OPTION );
		if ( $this->had_wpdb ) {
			$GLOBALS['wpdb'] = $this->previous_wpdb;
		} else {
			unset( $GLOBALS['wpdb'] );
		}
		parent::tearDown();
	}

	public function test_increment_creates_and_advances_a_monotonic_sequence(): void {
		$this->assertSame( 1, AtomicOptionSequence::increment( self::OPTION ) );
		$this->assertSame( 2, AtomicOptionSequence::increment( self::OPTION ) );
		$this->assertSame( 2, (int) \get_option( self::OPTION, 0 ) );
	}

	public function test_empty_option_name_fails_closed(): void {
		$this->assertSame( 0, AtomicOptionSequence::increment( '' ) );
		$this->assertSame( 0, AtomicOptionSequence::current( '' ) );
	}

	public function test_production_increment_uses_one_atomic_upsert_write(): void {
		\update_option( self::OPTION, 999, false );
		$database        = $this->database( 41 );
		$GLOBALS['wpdb'] = $database;

		$this->assertSame( 42, AtomicOptionSequence::increment( self::OPTION ) );
		$this->assertSame( 1, $database->write_count );
		$this->assertSame( 1, $database->select_count );
		$this->assertCount( 1, $database->write_queries );
		$this->assertStringContainsString( 'INSERT INTO %i', $database->write_queries[0]['query'] );
		$this->assertStringContainsString( 'ON DUPLICATE KEY UPDATE', $database->write_queries[0]['query'] );
		$this->assertStringNotContainsString( 'UPDATE %i SET', $database->write_queries[0]['query'] );
		$this->assertSame( 999, $GLOBALS['cybermaps_mock_options'][ self::OPTION ] );
	}

	public function test_current_reads_the_database_instead_of_stale_option_cache(): void {
		\update_option( self::OPTION, 999, false );
		$database        = $this->database( 7 );
		$GLOBALS['wpdb'] = $database;

		$this->assertSame( 7, AtomicOptionSequence::current( self::OPTION ) );
		$this->assertSame( 0, $database->write_count );
		$this->assertSame( 1, $database->select_count );
		$this->assertStringContainsString( 'SELECT option_value FROM %i', $database->select_queries[0]['query'] );
		$this->assertSame( 999, $GLOBALS['cybermaps_mock_options'][ self::OPTION ] );
	}

	public function test_direct_query_errors_fail_closed(): void {
		$database        = $this->database( 7, false, true );
		$GLOBALS['wpdb'] = $database;
		$this->assertSame( -1, AtomicOptionSequence::current( self::OPTION ) );
		$this->assertSame( 1, $database->select_count );

		$database        = $this->database( 7, true, false );
		$GLOBALS['wpdb'] = $database;
		$this->assertSame( 0, AtomicOptionSequence::increment( self::OPTION ) );
		$this->assertSame( 1, $database->write_count );
		$this->assertSame( 0, $database->select_count );
		$this->assertSame( -1, AtomicOptionSequence::advance_to( self::OPTION, 12 ) );

		$database        = $this->database( 7, false, true );
		$GLOBALS['wpdb'] = $database;
		$this->assertSame( -1, AtomicOptionSequence::advance_to( self::OPTION, 12 ) );
		$this->assertSame( 12, $database->value );
		$this->assertSame( 1, $database->write_count );
		$this->assertSame( 1, $database->select_count );
	}

	public function test_advance_to_never_moves_a_sequence_backwards(): void {
		\update_option( self::OPTION, 10, false );

		$this->assertSame( 10, AtomicOptionSequence::advance_to( self::OPTION, 5 ) );
		$this->assertSame( 10, (int) \get_option( self::OPTION, 0 ) );
		$this->assertSame( 15, AtomicOptionSequence::advance_to( self::OPTION, 15 ) );
		$this->assertSame( 15, AtomicOptionSequence::advance_to( self::OPTION, 12 ) );
		$this->assertSame( 15, (int) \get_option( self::OPTION, 0 ) );
		$this->assertSame( -1, AtomicOptionSequence::advance_to( '', 1 ) );
		$this->assertSame( -1, AtomicOptionSequence::advance_to( self::OPTION, -1 ) );
	}

	public function test_production_advance_to_uses_an_atomic_monotonic_upsert(): void {
		\update_option( self::OPTION, 999, false );
		$database        = $this->database( 41 );
		$GLOBALS['wpdb'] = $database;

		$this->assertSame( 50, AtomicOptionSequence::advance_to( self::OPTION, 50 ) );
		$this->assertSame( 50, AtomicOptionSequence::advance_to( self::OPTION, 45 ) );
		$this->assertSame( 2, $database->write_count );
		$this->assertSame( 2, $database->select_count );
		$this->assertStringContainsString( 'ON DUPLICATE KEY UPDATE', $database->write_queries[0]['query'] );
		$this->assertStringContainsString( 'GREATEST', $database->write_queries[0]['query'] );
		$this->assertSame( 999, $GLOBALS['cybermaps_mock_options'][ self::OPTION ] );
	}

	private function database( ?int $value, bool $fail_write = false, bool $fail_read = false ): object {
		return new class( $value, $fail_write, $fail_read ) {
			public string $options = 'wp_options';
			public ?int $value;
			public bool $fail_write;
			public bool $fail_read;
			public string $last_error = '';
			public int $write_count   = 0;
			public int $select_count  = 0;
			/** @var array<int,array{query:string,args:array<int,mixed>}> */
			public array $write_queries = array();
			/** @var array<int,array{query:string,args:array<int,mixed>}> */
			public array $select_queries = array();

			public function __construct( ?int $value, bool $fail_write, bool $fail_read ) {
				$this->value      = $value;
				$this->fail_write = $fail_write;
				$this->fail_read  = $fail_read;
			}

			/** @return array{query:string,args:array<int,mixed>} */
			public function prepare( string $query, mixed ...$args ): array {
				return array(
					'query' => $query,
					'args'  => $args,
				);
			}

			/** @param array{query:string,args:array<int,mixed>} $prepared */
			public function query( array $prepared ): int|false {
				$this->write_queries[] = $prepared;
				++$this->write_count;
				if ( $this->fail_write ) {
					$this->last_error = 'simulated write failure';
					return false;
				}
				if ( ! \str_starts_with( \ltrim( $prepared['query'] ), 'INSERT INTO' ) ) {
					return false;
				}

				$inserted = null === $this->value;
				if ( \str_contains( $prepared['query'], 'GREATEST' ) ) {
					$minimum     = (int) ( $prepared['args'][4] ?? 0 );
					$this->value = $inserted ? $minimum : max( (int) $this->value, $minimum );
				} else {
					$this->value = $inserted ? 1 : (int) $this->value + 1;
				}
				return $inserted ? 1 : 2;
			}

			/** @param array{query:string,args:array<int,mixed>} $prepared */
			public function get_var( array $prepared ): string|false|null {
				$this->select_queries[] = $prepared;
				++$this->select_count;
				if ( $this->fail_read ) {
					$this->last_error = 'simulated read failure';
					return false;
				}
				$this->last_error = '';
				return null === $this->value ? null : (string) $this->value;
			}
		};
	}
}
