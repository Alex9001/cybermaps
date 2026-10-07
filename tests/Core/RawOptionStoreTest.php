<?php
declare(strict_types=1);
namespace Cybermaps\Tests\Core;

use Cybermaps\Core\RawOptionStore;

require_once dirname( __DIR__ ) . '/mocks/static-ownership-db.php';

final class RawOptionStoreTest extends \WP_UnitTestCase {
	private \CybermapsMockStaticOwnershipDatabase $database;
	private array $options;
	private array $blogs;

	protected function setUp(): void {
		parent::setUp();
		$this->options = $GLOBALS['cybermaps_mock_options'];
		$this->blogs = $GLOBALS['cybermaps_mock_options_by_blog'] ?? array();
		$GLOBALS['cybermaps_mock_options'] = array();
		$GLOBALS['cybermaps_mock_options_by_blog'] = array();
		$this->database = cybermaps_mock_enable_static_ownership_database( true );
	}

	protected function tearDown(): void {
		cybermaps_mock_disable_static_ownership_database();
		$GLOBALS['cybermaps_mock_options'] = $this->options;
		$GLOBALS['cybermaps_mock_options_by_blog'] = $this->blogs;
		parent::tearDown();
	}

	public function test_unfenced_exact_bytes_empty_absence_and_conflicts_use_one_statement_each(): void {
		self::assertNull( RawOptionStore::read( $this->database, 'cybermaps_raw_test' ) );
		self::assertSame( 1, $this->operation( 'insert', null, '' ) );
		self::assertSame( '', RawOptionStore::read( $this->database, 'cybermaps_raw_test' ) );
		self::assertSame( 0, $this->operation( 'insert', null, 'competitor' ) );
		self::assertSame( 1, $this->operation( 'replace', null, 'Case ' ) );
		self::assertSame( 0, RawOptionStore::replace( $this->database, 'cybermaps_raw_test', 'case ', 'lost' ) );
		self::assertSame( 0, RawOptionStore::remove( $this->database, 'cybermaps_raw_test', 'Case' ) );
		self::assertSame( 0, RawOptionStore::replace( $this->database, 'cybermaps_raw_test', 'Case ', 'Case ' ) );
		self::assertSame( 1, RawOptionStore::remove( $this->database, 'cybermaps_raw_test', 'Case ' ) );
		self::assertNull( RawOptionStore::read( $this->database, 'cybermaps_raw_test' ) );
	}

	/** @dataProvider mutations */
	public function test_required_fence_is_checked_at_the_mutation_statement( string $verb, string $fault ): void {
		if ( 'insert' !== $verb ) { self::assertSame( 1, $this->operation( 'insert', null, '' ) ); }
		$fence = array( 'name' => 'fixture-raw-owner', 'connection_id' => $this->database->connection_id );
		$this->database->locks[ $fence['name'] ] = $fence['connection_id'];
		$before = RawOptionStore::read( $this->database, 'cybermaps_raw_test' );
		$this->database->before_query = static function( $database, array $prepared ) use ( $fault, $fence ): void {
			if ( str_starts_with( $prepared['query'], 'SELECT ' ) ) { return; }
			$database->before_query = null;
			if ( 'released' === $fault ) { unset( $database->locks[ $fence['name'] ] ); }
			if ( 'reconnected' === $fault ) { $database->reconnect( $fence['connection_id'] + 1 ); $database->locks[ $fence['name'] ] = $database->connection_id; }
		};
		self::assertSame( 'none' === $fault ? 1 : 0, $this->operation( $verb, $fence, 'next' ) );
		$expected = 'none' !== $fault ? $before : ( 'remove' === $verb ? null : 'next' );
		self::assertSame( $expected, RawOptionStore::read( $this->database, 'cybermaps_raw_test' ) );
	}

	public static function mutations(): array {
		$cases = array();
		foreach ( array( 'insert', 'replace', 'remove' ) as $verb ) {
			foreach ( array( 'none', 'released', 'reconnected' ) as $fault ) { $cases[ $verb . '-' . $fault ] = array( $verb, $fault ); }
		}
		return $cases;
	}

	/** @dataProvider verbs */
	public function test_sql_failure_stays_false_and_empty_fence_never_disables_ownership( string $verb ): void {
		if ( 'insert' !== $verb ) { self::assertSame( 1, $this->operation( 'insert', null, '' ) ); }
		$before = RawOptionStore::read( $this->database, 'cybermaps_raw_test' );
		self::assertSame( 0, $this->operation( $verb, array(), 'next' ) );
		$this->database->fail_next = true;
		self::assertFalse( $this->operation( $verb, null, 'next' ) );
		self::assertSame( $before, RawOptionStore::read( $this->database, 'cybermaps_raw_test' ) );
	}

	public static function verbs(): array { return array( array( 'insert' ), array( 'replace' ), array( 'remove' ) ); }

	private function operation( string $verb, ?array $fence, string $next ): int|false {
		$count = count( $this->database->queries );
		$result = match ( $verb ) {
			'insert' => RawOptionStore::insert( $this->database, 'cybermaps_raw_test', $next, $fence ),
			'replace' => RawOptionStore::replace( $this->database, 'cybermaps_raw_test', '', $next, $fence ),
			'remove' => RawOptionStore::remove( $this->database, 'cybermaps_raw_test', '', $fence ),
		};
		self::assertCount( $count + 1, $this->database->queries, 'Each mutation keeps a single SQL statement.' );
		return $result;
	}
}
