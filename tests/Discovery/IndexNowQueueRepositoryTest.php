<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Discovery\IndexNowQueueRepository;
use PHPUnit\Framework\TestCase;

final class IndexNowQueueRepositoryTest extends TestCase {
	private mixed $previous_wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';
			public array $queries = array();
			public function prepare( string $query, mixed ...$args ): array {
				return array( 'sql' => $query, 'args' => $args );
			}
			public function get_results( array $query, string $format ): array {
				$this->queries[] = $query;
				return array( array( 'id' => 17 ) );
			}
			public function query( array $query ): int {
				$this->queries[] = $query;
				return 0;
			}
		};
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->previous_wpdb;
		parent::tearDown();
	}

	public function test_admission_lock_is_mysql_compatible_and_site_specific(): void {
		$repository = new IndexNowQueueRepository();
		$method = new \ReflectionMethod( $repository, 'admission_lock_name' );
		$first = $method->invoke( $repository );
		$this->assertLessThanOrEqual( 64, strlen( $first ) );
		$this->assertSame( $first, $method->invoke( $repository ) );
		$GLOBALS['wpdb']->prefix = 'wp_2_';
		$this->assertNotSame( $first, $method->invoke( $repository ) );
	}

	public function test_force_bypasses_retry_delay_but_preserves_lease_at_both_query_boundaries(): void {
		$repository = new IndexNowQueueRepository();
		( new \ReflectionMethod( $repository, 'candidate_ids' ) )->invoke( $repository, 10, 1000, true );
		// A candidate can acquire another worker's lease between selection and UPDATE.
		( new \ReflectionMethod( $repository, 'claim_id_chunk' ) )->invoke( $repository, array( 17 ), 'new-worker', 1000, 300, true );
		[ $select, $update ] = $GLOBALS['wpdb']->queries;
		foreach ( array( $select, $update ) as $query ) {
			$this->assertStringContainsString( 'state = %s AND next_attempt_at <= %d', $query['sql'] );
			$this->assertStringContainsString( 'state = %s AND lease_expires_at > 0 AND lease_expires_at <= %d', $query['sql'] );
		}
		$this->assertSame( array( 'queued', PHP_INT_MAX, 'claimed', 1000, 10 ), array_slice( $select['args'], 1 ) );
		$this->assertSame( array( 'queued', PHP_INT_MAX, 'claimed', 1000 ), array_slice( $update['args'], -4 ) );
	}
}
