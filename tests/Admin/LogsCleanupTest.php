<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\CrawlerAnalyticsRecorder;
use Cybermaps\Admin\Logs;

final class LogsCleanupTest extends \WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		\cybermaps_mock_reset_cache_runtime();

		$GLOBALS['cybermaps_mock_current_time_mysql'] = '2026-07-30 12:00:00';
		$GLOBALS['cybermaps_mock_options'] = array(
			'cybermaps_settings' => array(
				'log_retention_days' => 30,
			),
		);
		$GLOBALS['cybermaps_mock_scheduled'] = array();
		unset( $GLOBALS['cybermaps_mock_schedule_event_result'] );
		$GLOBALS['cybermaps_mock_settings_errors'] = array();
		$GLOBALS['cybermaps_mock_transients'] = array(
			'cybermaps_analytics_overview_v2' => array( 'stale' => true ),
		);
		$GLOBALS['wpdb'] = new LogsCleanupWpdbStub();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['cybermaps_mock_current_time_mysql'] );
		unset( $GLOBALS['cybermaps_mock_schedule_event_result'] );
		parent::tearDown();
	}

	public function test_missing_daily_cleanup_schedule_self_heals_on_admin_init(): void {
		Logs::ensure_cleanup_schedule();

		$this->assertArrayHasKey(
			'cybermaps_cleanup_logs_event',
			$GLOBALS['cybermaps_mock_scheduled']
		);
		$this->assertSame( array(), $GLOBALS['cybermaps_mock_settings_errors'] );

		$scheduled_at = $GLOBALS['cybermaps_mock_scheduled']['cybermaps_cleanup_logs_event'];
		Logs::ensure_cleanup_schedule();
		$this->assertSame(
			$scheduled_at,
			$GLOBALS['cybermaps_mock_scheduled']['cybermaps_cleanup_logs_event']
		);
	}

	public function test_cleanup_schedule_failure_is_visible_and_retried(): void {
		$GLOBALS['cybermaps_mock_schedule_event_result'] = false;

		Logs::ensure_cleanup_schedule();

		$this->assertArrayNotHasKey(
			'cybermaps_cleanup_logs_event',
			$GLOBALS['cybermaps_mock_scheduled']
		);
		$this->assertCount( 1, $GLOBALS['cybermaps_mock_settings_errors'] );
		$this->assertSame(
			'cybermaps_cleanup_schedule_failed',
			$GLOBALS['cybermaps_mock_settings_errors'][0]['code']
		);
	}

	public function test_cleanup_is_bounded_and_schedules_a_continuation_for_a_backlog(): void {
		$GLOBALS['wpdb']->delete_results = array_fill( 0, 5, 1000 );

		( new Logs() )->cleanup_old_logs();

		$this->assertCount( 5, $GLOBALS['wpdb']->queries );
		$this->assertCount( 5, $GLOBALS['wpdb']->prepared_arguments );
		foreach ( $GLOBALS['wpdb']->prepared_arguments as $arguments ) {
			$this->assertSame( array( 'wp_cybermaps_logs', '2026-06-30 12:00:00', 1000 ), $arguments );
		}
		$this->assertStringContainsString( 'ORDER BY time ASC', $GLOBALS['wpdb']->queries[0] );
		$this->assertStringContainsString( 'LIMIT %d', $GLOBALS['wpdb']->queries[0] );
		$this->assertArrayHasKey(
			'cybermaps_continue_logs_cleanup_event',
			$GLOBALS['cybermaps_mock_scheduled']
		);
		$this->assertArrayNotHasKey(
			'cybermaps_analytics_overview_v2',
			$GLOBALS['cybermaps_mock_transients']
		);
	}

	public function test_cleanup_stops_after_the_first_short_batch_without_rescheduling(): void {
		$GLOBALS['wpdb']->delete_results = array( 1000, 25 );

		( new Logs() )->cleanup_old_logs();

		$this->assertCount( 2, $GLOBALS['wpdb']->queries );
		$this->assertArrayNotHasKey(
			'cybermaps_continue_logs_cleanup_event',
			$GLOBALS['cybermaps_mock_scheduled']
		);
		$this->assertSame( '', CrawlerAnalyticsRecorder::get_health_error() );
	}

	public function test_partial_cleanup_failure_invalidates_deleted_data_and_records_health(): void {
		$GLOBALS['wpdb']->delete_results = array( 1000, false );

		( new Logs() )->cleanup_old_logs();

		$this->assertCount( 2, $GLOBALS['wpdb']->queries );
		$this->assertArrayNotHasKey(
			'cybermaps_analytics_overview_v2',
			$GLOBALS['cybermaps_mock_transients']
		);
		$this->assertNotSame( '', CrawlerAnalyticsRecorder::get_health_error() );
		$this->assertArrayNotHasKey(
			'cybermaps_continue_logs_cleanup_event',
			$GLOBALS['cybermaps_mock_scheduled']
		);
	}
}

final class LogsCleanupWpdbStub {
	public string $prefix = 'wp_';

	/** @var array<int,int|false> */
	public array $delete_results = array();

	/** @var string[] */
	public array $queries = array();

	/** @var array<int,array<int,mixed>> */
	public array $prepared_arguments = array();

	public function prepare( string $query, ...$arguments ): string {
		$this->prepared_arguments[] = $arguments;
		return $query;
	}

	public function query( string $query ): int|false {
		$this->queries[] = $query;
		return array_shift( $this->delete_results );
	}
}
