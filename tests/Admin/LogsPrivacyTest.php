<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\CrawlerAnalyticsRecorder;
use Cybermaps\Admin\Logs;

final class LogsPrivacyTest extends \WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['cybermaps_mock_options'] = array();
		$GLOBALS['cybermaps_mock_transients'] = array();
		$GLOBALS['cybermaps_mock_users_by_email'] = array(
			'person@example.com' => (object) array( 'ID' => 17 ),
		);
		$GLOBALS['wpdb'] = new LogsPrivacyWpdbStub();
	}

	public function test_exporter_returns_a_wordpress_error_when_the_read_fails(): void {
		$GLOBALS['wpdb']->fail_export_read = true;

		$result = Logs::export_personal_data( 'person@example.com' );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertNotSame( '', CrawlerAnalyticsRecorder::get_health_error() );
	}

	public function test_eraser_reports_retained_data_when_the_id_read_fails(): void {
		$GLOBALS['wpdb']->fail_read = true;

		$result = Logs::erase_personal_data( 'person@example.com' );

		$this->assertFalse( $result['items_removed'] );
		$this->assertTrue( $result['items_retained'] );
		$this->assertTrue( $result['done'] );
		$this->assertNotEmpty( $result['messages'] );
		$this->assertNotSame( '', CrawlerAnalyticsRecorder::get_health_error() );
	}

	public function test_eraser_reports_retained_data_when_the_delete_fails(): void {
		$GLOBALS['wpdb']->ids = array( 31, 32 );
		$GLOBALS['wpdb']->fail_delete = true;

		$result = Logs::erase_personal_data( 'person@example.com' );

		$this->assertFalse( $result['items_removed'] );
		$this->assertTrue( $result['items_retained'] );
		$this->assertTrue( $result['done'] );
		$this->assertNotEmpty( $result['messages'] );
	}

	public function test_eraser_keeps_paging_after_a_full_successful_batch(): void {
		$GLOBALS['wpdb']->ids = range( 1, 100 );
		$GLOBALS['wpdb']->delete_result = 100;

		$result = Logs::erase_personal_data( 'person@example.com' );

		$this->assertTrue( $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertFalse( $result['done'] );
		$this->assertSame( array(), $result['messages'] );
	}

	public function test_deleted_user_failure_is_recorded_for_administrators(): void {
		$GLOBALS['wpdb']->fail_delete = true;

		( new Logs() )->delete_user_observations( 17 );

		$this->assertNotSame( '', CrawlerAnalyticsRecorder::get_health_error() );
	}
}

final class LogsPrivacyWpdbStub {
	public string $prefix = 'wp_';
	public string $last_error = '';
	public bool $fail_read = false;
	public bool $fail_export_read = false;
	public bool $fail_delete = false;
	public int $delete_result = 0;

	/** @var int[] */
	public array $ids = array();

	public function prepare( string $query, mixed ...$arguments ): string {
		unset( $arguments );
		return $query;
	}

	/**
	 * @return int[]
	 */
	public function get_col( string $query ): array {
		unset( $query );
		if ( $this->fail_read ) {
			$this->last_error = 'read failed';
			return array();
		}
		return $this->ids;
	}

	public function get_results( string $query, string $output ): array|false {
		unset( $query, $output );
		if ( $this->fail_export_read ) {
			$this->last_error = 'export read failed';
			return false;
		}
		return array();
	}

	public function query( string $query ): int|false {
		unset( $query );
		if ( $this->fail_delete ) {
			$this->last_error = 'delete failed';
			return false;
		}
		return $this->delete_result;
	}
}
