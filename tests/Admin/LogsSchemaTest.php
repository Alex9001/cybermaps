<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\Logs;

final class LogsSchemaTest extends \WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['cybermaps_mock_options'] = array(
			'cybermaps_logs_schema_version' => '3',
		);
		$GLOBALS['cybermaps_mock_transients'] = array();
		$GLOBALS['cybermaps_mock_dbdelta_queries'] = array();
		unset( $GLOBALS['cybermaps_mock_dbdelta_callback'] );
		$GLOBALS['wpdb'] = new LogsSchemaWpdbStub();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['cybermaps_mock_dbdelta_callback'] );
		parent::tearDown();
	}

	public function test_version_three_upgrade_adds_columns_without_rewriting_existing_rows(): void {
		Logs::create_table();

		$this->assertSame( array(), $GLOBALS['wpdb']->queries );
		$this->assertSame( '5', get_option( 'cybermaps_logs_schema_version' ) );
	}

	public function test_older_schema_keeps_the_bounded_recognized_flag_backfill(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_logs_schema_version'] = '2';

		Logs::create_table();

		$this->assertCount( 1, $GLOBALS['wpdb']->queries );
		$this->assertStringContainsString( 'SET recognized = 1', $GLOBALS['wpdb']->queries[0] );
	}

	public function test_failed_legacy_backfill_does_not_stamp_the_new_schema(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_logs_schema_version'] = '2';
		$GLOBALS['wpdb']->query_result = false;

		Logs::create_table();

		$this->assertSame( '2', get_option( 'cybermaps_logs_schema_version' ) );
		$this->assertNotSame(
			'',
			\Cybermaps\Admin\CrawlerAnalyticsRecorder::get_health_error()
		);
	}

	public function test_current_schema_health_repair_is_attempted_at_most_once_per_hour(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_logs_schema_version'] = '5';
		$GLOBALS['cybermaps_mock_options']['cybermaps_analytics_db_error'] = array(
			'message' => 'Persistent database error.',
			'time'    => time(),
		);
		$GLOBALS['cybermaps_mock_dbdelta_callback'] = static function (): array {
			$GLOBALS['wpdb']->last_error = 'persistent failure';
			return array();
		};

		Logs::maybe_upgrade_table();
		Logs::maybe_upgrade_table();

		$this->assertCount( 1, $GLOBALS['cybermaps_mock_dbdelta_queries'] );
		$this->assertSame( '5', get_transient( 'cybermaps_logs_schema_repair' ) );
	}

	public function test_failed_version_upgrade_does_not_retry_before_repair_window_expires(): void {
		$GLOBALS['cybermaps_mock_dbdelta_callback'] = static function (): array {
			$GLOBALS['wpdb']->last_error = 'upgrade failure';
			return array();
		};

		Logs::maybe_upgrade_table();
		Logs::maybe_upgrade_table();

		$this->assertCount( 1, $GLOBALS['cybermaps_mock_dbdelta_queries'] );
		$this->assertSame( '3', get_option( 'cybermaps_logs_schema_version' ) );
	}

	public function test_new_schema_target_bypasses_an_older_repair_guard(): void {
		set_transient( 'cybermaps_logs_schema_repair', '3', HOUR_IN_SECONDS );

		Logs::maybe_upgrade_table();

		$this->assertCount( 1, $GLOBALS['cybermaps_mock_dbdelta_queries'] );
		$this->assertSame( '5', get_option( 'cybermaps_logs_schema_version' ) );
	}
}

final class LogsSchemaWpdbStub {
	public string $prefix = 'wp_';
	public string $last_error = '';
	public int|false $query_result = 0;

	/** @var string[] */
	public array $queries = array();

	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4';
	}

	public function prepare( string $query, mixed ...$args ): string {
		foreach ( $args as $arg ) {
			$query = (string) preg_replace( '/%i/', (string) $arg, $query, 1 );
		}
		return $query;
	}

	public function query( string $query ): int|false {
		$this->queries[] = $query;
		if ( false === $this->query_result ) {
			$this->last_error = 'backfill failed';
		}
		return $this->query_result;
	}
}
