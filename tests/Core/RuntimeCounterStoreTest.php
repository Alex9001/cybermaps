<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Core;

use Cybermaps\Core\RuntimeCounterStore;
use PHPUnit\Framework\TestCase;

final class RuntimeCounterStoreTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_options'] = array();
	}

	public function test_uninstalled_table_fails_open_to_the_compatibility_backend(): void {
		$this->assertNull( RuntimeCounterStore::increment( 'bucket', 70 ) );
	}

	public function test_schema_is_bounded_and_has_expiry_index(): void {
		$sql = RuntimeCounterStore::schema_sql();

		$this->assertStringContainsString( 'cybermaps_runtime_counters', $sql );
		$this->assertStringContainsString( 'PRIMARY KEY  (bucket_key)', $sql );
		$this->assertStringContainsString( 'KEY expires_at (expires_at)', $sql );
	}
	public function test_cleanup_drains_backlog_across_bounded_continuations_and_preserves_live_rows(): void {
		$previous = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['cybermaps_mock_options'][ RuntimeCounterStore::READY_OPTION ] = true;
		$GLOBALS['cybermaps_mock_scheduled'] = array();
		$database = new class() {
			public string $prefix = 'wp_';
			public array $expires;
			public array $limits = array();
			public function __construct() {
				$this->expires = array_merge( array_fill( 0, 25001, time() - 60 ), array_fill( 0, 7, time() + 3600 ) );
			}
			public function prepare( string $sql, mixed ...$args ): array {
				return array( $sql, $args );
			}
			public function query( array $prepared ): int {
				[ $sql, $args ] = $prepared;
				if ( 'DELETE FROM %i WHERE expires_at <= %d LIMIT %d' !== $sql ) {
					throw new \RuntimeException( 'Unexpected SQL' );
				}
				$this->limits[] = $args[2];
				$deleted = 0;
				foreach ( $this->expires as $id => $expires ) {
					if ( $expires <= $args[1] && $deleted < $args[2] ) {
						unset( $this->expires[ $id ] );
						++$deleted;
					}
				}
				return $deleted;
			}
		};
		$GLOBALS['wpdb'] = $database;
		try {
			\Cybermaps\Core\Plugin::cleanup_runtime_counters();
			$this->assertNotFalse( wp_next_scheduled( RuntimeCounterStore::CLEANUP_CONTINUATION_HOOK ) );
			$this->assertLessThanOrEqual( 10, count( $database->limits ) );
			for ( $attempt = 0; $attempt < 30 && wp_next_scheduled( RuntimeCounterStore::CLEANUP_CONTINUATION_HOOK ); ++$attempt ) {
				unset( $GLOBALS['cybermaps_mock_scheduled'][ RuntimeCounterStore::CLEANUP_CONTINUATION_HOOK ] );
				\Cybermaps\Core\Plugin::cleanup_runtime_counters();
			}
			$this->assertCount( 7, $database->expires );
			$this->assertFalse( wp_next_scheduled( RuntimeCounterStore::CLEANUP_CONTINUATION_HOOK ) );
			$this->assertSame( array( 1000 ), array_values( array_unique( $database->limits ) ) );
		} finally {
			$GLOBALS['wpdb'] = $previous;
		}
	}

}
