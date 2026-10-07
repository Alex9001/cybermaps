<?php
declare(strict_types=1);

namespace {
	if ( ! function_exists( 'wp_nonce_url' ) ) {
		function wp_nonce_url( string $url, string $action ): string {
			return add_query_arg( '_wpnonce', wp_create_nonce( $action ), $url );
		}
	}
}

namespace Cybermaps\Tests\Admin {

use Cybermaps\Admin\AIDiscoveryStatus;
use Cybermaps\Admin\CrawlerAnalyticsRecorder;
use Cybermaps\Admin\Logs;
use PHPUnit\Framework\TestCase;

final class AnalyticsAvailabilityDisplayTest extends TestCase {
	private mixed $prior_wpdb;
	private array $prior_capabilities;

	protected function setUp(): void {
		$this->prior_wpdb = $GLOBALS['wpdb'] ?? null;
		$this->prior_capabilities = $GLOBALS['cybermaps_mock_current_user_capabilities'] ?? array();
		$GLOBALS['wpdb'] = new AvailabilityDisplayWpdbStub();
		$GLOBALS['cybermaps_mock_current_user_capabilities'] = array( 'manage_options' );
		$GLOBALS['cybermaps_mock_transients'] = array();
		cybermaps_mock_reset_cache_runtime();
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->prior_wpdb;
		$GLOBALS['cybermaps_mock_current_user_capabilities'] = $this->prior_capabilities;
		$GLOBALS['cybermaps_mock_transients'] = array();
		cybermaps_mock_reset_cache_runtime();
	}

	public function test_widget_distinguishes_database_failure_from_empty_history(): void {
		$GLOBALS['wpdb']->fail = true;
		ob_start();
		( new Logs() )->render_widget_content();
		$failed = (string) ob_get_clean();
		self::assertStringContainsString( 'observations are unavailable', $failed );
		self::assertStringNotContainsString( 'No endpoint observations', $failed );
		$GLOBALS['wpdb']->fail = false;
		ob_start();
		( new Logs() )->render_widget_content();
		$empty = (string) ob_get_clean();
		self::assertStringContainsString( 'No endpoint observations', $empty );
		self::assertStringNotContainsString( 'observations are unavailable', $empty );
	}

	public function test_endpoint_failure_propagates_to_display_without_fabricated_zero_counts(): void {
		$GLOBALS['wpdb']->fail = true;
		$context = ( new \ReflectionMethod( AIDiscoveryStatus::class, 'page_context' ) )->invoke( null, array() );
		self::assertFalse( $context['observations_available'] );
		ob_start();
		( new \ReflectionMethod( AIDiscoveryStatus::class, 'render_php_observation_cell' ) )->invoke( null, 'dynamic', array( 'available' => $context['observations_available'] ) );
		$html = (string) ob_get_clean();
		self::assertStringContainsString( 'Unavailable', $html );
		self::assertStringContainsString( 'Request counts are unknown', $html );
		self::assertStringNotContainsString( '0 PHP', $html );
		self::assertStringNotContainsString( 'No PHP-observed request', $html );
	}

	public function test_successful_empty_and_cached_observations_remain_available(): void {
		self::assertSame( array(), CrawlerAnalyticsRecorder::get_endpoint_observations( $available ) );
		self::assertTrue( $available );
		$GLOBALS['wpdb']->fail = true;
		self::assertSame( array(), CrawlerAnalyticsRecorder::get_endpoint_observations( $available ) );
		self::assertTrue( $available, 'A valid cached projection is distinct from a failed current read.' );
		cybermaps_mock_reset_cache_runtime();
		$GLOBALS['cybermaps_mock_transients'] = array();
		self::assertSame( array(), CrawlerAnalyticsRecorder::get_endpoint_observations( $available ) );
		self::assertFalse( $available );
	}
}

final class AvailabilityDisplayWpdbStub {
	public string $prefix = 'wp_';
	public string $last_error = '';
	public bool $fail = false;

	public function prepare( string $query, mixed ...$args ): string {
		return $query;
	}

	public function get_results( string $query, string $output ): ?array {
		$this->last_error = $this->fail ? 'Database read failed' : '';
		return $this->fail ? null : array();
	}
}

}
