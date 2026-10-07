<?php
declare(strict_types=1);
namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\CrawlerAnalyticsRepository;
use Cybermaps\Admin\CrawlerAnalyticsRecorder;
use Cybermaps\Admin\Logs;
use PHPUnit\Framework\TestCase;

final class AdminDatabaseFailuresTest extends TestCase {
	public function test_failed_empty_database_results_are_not_cached_or_exported(): void {
		$previous = $GLOBALS['wpdb'] ?? null;
		cybermaps_mock_reset_cache_runtime();
		$GLOBALS['cybermaps_mock_transients'] = array();
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';
			public string $last_error = '';
			public function prepare( string $query, mixed ...$args ): string { return $query; }
			public function get_row( string $query, string $output ): ?array { $this->last_error = 'SQL failed'; return null; }
			public function get_results( string $query, string $output ): array { $this->last_error = 'SQL failed'; return array(); }
		};
		try {
			$repository = new CrawlerAnalyticsRepository();
			$this->assertFalse( $repository->get_overview()['available'] );
			$this->assertSame( array(), $repository->get_recent_activity() );
			$this->assertSame( array(), $repository->get_widget_activity() );
			$this->assertSame( array(), CrawlerAnalyticsRecorder::get_endpoint_observations() );
			$this->assertNotSame( '', CrawlerAnalyticsRecorder::get_health_error() );
			$this->assertArrayNotHasKey( 'cybermaps_analytics_overview_v3', $GLOBALS['cybermaps_mock_transients'] );
			$this->assertArrayNotHasKey( 'cybermaps_analytics_activity_v3', $GLOBALS['cybermaps_mock_transients'] );
			$this->assertNull( ( new \ReflectionMethod( Logs::class, 'get_export_batch' ) )->invoke( null, null, 500 ) );
		} finally { $GLOBALS['wpdb'] = $previous; }
	}
}
