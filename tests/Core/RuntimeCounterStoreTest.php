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
}
