<?php
declare(strict_types=1);

namespace {
	if ( ! function_exists( 'current_time' ) ) {
		function current_time( $type, $gmt = 0 ) {
			unset( $gmt );
			return 'mysql' === $type ? '2026-07-26 12:00:00' : time();
		}
	}

	if ( ! function_exists( 'is_admin' ) ) {
		function is_admin() {
			return false;
		}
	}

	if ( ! function_exists( 'is_user_logged_in' ) ) {
		function is_user_logged_in() {
			return ! empty( $GLOBALS['cybermaps_mock_logged_in'] );
		}
	}

	if ( ! function_exists( 'get_current_user_id' ) ) {
		function get_current_user_id() {
			return (int) ( $GLOBALS['cybermaps_mock_user_id'] ?? 0 );
		}
	}

	if ( ! function_exists( 'wp_hash' ) ) {
		function wp_hash( $data, $scheme = 'auth', $algo = 'md5' ) {
			return hash_hmac( $algo, (string) $data, 'test-' . (string) $scheme . '-salt' );
		}
	}
}

namespace Cybermaps\Tests\Admin {

	use Cybermaps\Admin\CrawlerAnalyticsRecorder;
	use Cybermaps\Admin\Logs;

	final class CrawlerAnalyticsRecorderTest extends \WP_UnitTestCase {

		protected function setUp(): void {
			parent::setUp();

			$GLOBALS['cybermaps_mock_options'] = array(
				'cybermaps_settings' => array(
					'enable_analytics'        => '1',
					'anonymize_analytics_ips' => '1',
				),
			);
			$GLOBALS['cybermaps_mock_transients'] = array();
			$GLOBALS['cybermaps_mock_object_cache'] = array();
			$GLOBALS['cybermaps_mock_using_ext_object_cache'] = false;
			\Cybermaps\Core\CacheManager::reset_runtime();
			$GLOBALS['cybermaps_mock_logged_in'] = false;
			$GLOBALS['cybermaps_mock_user_id'] = 0;
			$GLOBALS['wpdb'] = new AnalyticsWpdbStub();
			$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; OAI-SearchBot/1.0)';
			$_SERVER['REMOTE_ADDR']     = '203.0.113.42';
			$_SERVER['REQUEST_URI']     = '/llms.txt';
			$_SERVER['REQUEST_METHOD']  = 'GET';
			$_SERVER['HTTP_ACCEPT']     = 'application/json, text/plain;q=0.8';
		}

		protected function tearDown(): void {
			unset(
				$_SERVER['HTTP_USER_AGENT'],
				$_SERVER['REMOTE_ADDR'],
				$_SERVER['REQUEST_URI'],
				$_SERVER['REQUEST_METHOD'],
				$_SERVER['HTTP_ACCEPT'],
				$_SERVER['HTTP_CF_CONNECTING_IP'],
				$_SERVER['HTTP_X_CYBERMAPS_DIAGNOSTIC']
			);
			unset( $GLOBALS['cybermaps_mock_logged_in'], $GLOBALS['cybermaps_mock_user_id'] );
			$GLOBALS['cybermaps_mock_using_ext_object_cache'] = false;
			$GLOBALS['cybermaps_mock_object_cache'] = array();
			parent::tearDown();
		}

		public function test_successful_php_observation_is_classified_and_recorded_once(): void {
			$GLOBALS['cybermaps_mock_transients']['cybermaps_logs_kpis'] = array( 'stale' => true );
			$recorder = new CrawlerAnalyticsRecorder();

			$this->assertTrue( $recorder->record_current_request( '', 201 ) );
			$this->assertFalse( $recorder->record_current_request( '', 201 ) );
			$this->assertCount( 1, $GLOBALS['wpdb']->inserts );

			$row = $GLOBALS['wpdb']->inserts[0];
			$this->assertSame( 'OAI-SearchBot', $row['bot'] );
			$this->assertSame( 'ai-search', $row['category'] );
			$this->assertSame( 'endpoint', $row['request_kind'] );
			$this->assertSame( 'llms', $row['endpoint_id'] );
			$this->assertSame( 201, $row['response_status'] );
			$this->assertSame( 'php', $row['delivery'] );
			$this->assertSame( 1, $row['recognized'] );
			$this->assertSame( '203.0.113.0', $row['ip_address'] );
			$this->assertSame( 'oai-searchbot', $row['crawler_id'] );
			$this->assertSame( 'claimed', $row['identity_status'] );
			$this->assertSame( 'ua_signature', $row['verification_method'] );
			$this->assertSame( 'crawler', $row['client_type'] );
			$this->assertSame( 'GET', $row['request_method'] );
			$this->assertSame( 'json', $row['accept_type'] );
			$this->assertSame( 'direct', $row['ip_source'] );
			$this->assertSame( 'anonymized', $row['ip_storage'] );
			$this->assertSame( 16, strlen( $row['requester_key'] ) );
			$this->assertArrayNotHasKey( 'cybermaps_logs_kpis', $GLOBALS['cybermaps_mock_transients'] );
			$this->assertNotEmpty(
				array_filter(
					array_keys( $GLOBALS['cybermaps_mock_options'] ),
					static fn( string $key ): bool => str_starts_with( $key, 'cybermaps_cache_lease_' )
				)
			);
		}

		public function test_successful_observations_coalesce_analytics_cache_invalidation(): void {
			$GLOBALS['cybermaps_mock_transients']['cybermaps_analytics_overview_v2'] = array( 'first' => true );

			$this->assertTrue( ( new CrawlerAnalyticsRecorder() )->record_current_request() );
			$this->assertArrayNotHasKey(
				'cybermaps_analytics_overview_v2',
				$GLOBALS['cybermaps_mock_transients']
			);

			$GLOBALS['cybermaps_mock_transients']['cybermaps_analytics_overview_v2'] = array( 'second' => true );
			$this->assertTrue( ( new CrawlerAnalyticsRecorder() )->record_current_request() );
			$this->assertSame(
				array( 'second' => true ),
				$GLOBALS['cybermaps_mock_transients']['cybermaps_analytics_overview_v2']
			);

			foreach ( array_keys( $GLOBALS['cybermaps_mock_options'] ) as $key ) {
				if ( str_starts_with( $key, 'cybermaps_cache_lease_' ) ) {
					$GLOBALS['cybermaps_mock_options'][ $key ] = time() - 1;
				}
			}
			\Cybermaps\Core\CacheManager::reset_runtime();
			$this->assertTrue( ( new CrawlerAnalyticsRecorder() )->record_current_request() );
			$this->assertArrayNotHasKey(
				'cybermaps_analytics_overview_v2',
				$GLOBALS['cybermaps_mock_transients']
			);
		}

		public function test_external_cache_applies_an_atomic_anonymous_requester_backpressure_limit(): void {
			$GLOBALS['cybermaps_mock_using_ext_object_cache'] = true;

			for ( $request = 1; $request <= 120; ++$request ) {
				$this->assertTrue( ( new CrawlerAnalyticsRecorder() )->record_current_request() );
			}

			$this->assertFalse( ( new CrawlerAnalyticsRecorder() )->record_current_request() );
			$this->assertCount( 120, $GLOBALS['wpdb']->inserts );
		}

		public function test_logs_registers_frontend_shutdown_and_rest_response_observers(): void {
			$GLOBALS['wp_hooks'] = array();
			( new Logs() )->register_hooks();

			$callbacks = array();
			foreach ( $GLOBALS['wp_hooks'] as $hook ) {
				if ( ! is_array( $hook['callback'] ) ) {
					continue;
				}
					$callbacks[ $hook['hook'] ] = array(
						'method'        => $hook['callback'][1],
						'priority'      => $hook['priority'],
						'accepted_args' => $hook['accepted_args'],
					);
				}

				$this->assertSame( 'begin_frontend_observation', $callbacks['init']['method'] );
				$this->assertSame( 6, $callbacks['init']['priority'] );
				$this->assertSame( 'observe_rest_response', $callbacks['rest_post_dispatch']['method'] );
			$this->assertSame( 3, $callbacks['rest_post_dispatch']['accepted_args'] );
			$this->assertSame(
				'cleanup_old_logs',
				$callbacks['cybermaps_continue_logs_cleanup_event']['method']
			);
		}

		public function test_cybermaps_rest_response_is_recorded_and_deduplicated(): void {
			$_SERVER['HTTP_USER_AGENT'] = 'ChatGPT-User/1.0';
			$_SERVER['REQUEST_URI']     = '/wp-json/cybermaps/v1/discovery';
			$recorder = new CrawlerAnalyticsRecorder();
			$response = new AnalyticsRestResponseStub( 207 );
			$request  = new AnalyticsRestRequestStub( '/cybermaps/v1/discovery' );

			$this->assertSame( $response, $recorder->observe_rest_response( $response, null, $request ) );
			$recorder->record_frontend_response();

			$this->assertCount( 1, $GLOBALS['wpdb']->inserts );
			$this->assertSame( 'rest_root', $GLOBALS['wpdb']->inserts[0]['endpoint_id'] );
			$this->assertSame( 207, $GLOBALS['wpdb']->inserts[0]['response_status'] );
		}

		public function test_failed_insert_persists_health_error_and_does_not_clear_caches(): void {
			$GLOBALS['wpdb']->fail_insert = true;
			$GLOBALS['cybermaps_mock_transients']['cybermaps_logs_kpis'] = array( 'keep' => true );
			$recorder = new CrawlerAnalyticsRecorder();

			$this->assertFalse( $recorder->record_current_request() );
			$this->assertNotSame( '', CrawlerAnalyticsRecorder::get_health_error() );
			$this->assertArrayHasKey( 'cybermaps_logs_kpis', $GLOBALS['cybermaps_mock_transients'] );
		}

		public function test_unknown_endpoint_retains_diagnostic_evidence(): void {
			$_SERVER['HTTP_USER_AGENT'] = 'OrdinaryBrowser/1.0';

			$this->assertTrue( ( new CrawlerAnalyticsRecorder() )->record_current_request() );
			$this->assertCount( 1, $GLOBALS['wpdb']->inserts );
			$row = $GLOBALS['wpdb']->inserts[0];
			$this->assertSame( 'Unrecognized request', $row['bot'] );
			$this->assertSame( 'unknown', $row['identity_status'] );
			$this->assertSame( 0, $row['recognized'] );
			$this->assertSame( '203.0.113.0', $row['ip_address'] );
			$this->assertSame( 'OrdinaryBrowser/1.0', $row['user_agent'] );
			$this->assertNotSame( '', $row['requester_key'] );
		}

		public function test_ip_anonymization_can_be_disabled_for_future_records(): void {
			$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['anonymize_analytics_ips'] = '0';

			$this->assertTrue( ( new CrawlerAnalyticsRecorder() )->record_current_request() );
			$row = $GLOBALS['wpdb']->inserts[0];

			$this->assertSame( '203.0.113.42', $row['ip_address'] );
			$this->assertSame( 'full', $row['ip_storage'] );
		}

		public function test_logged_in_endpoint_records_wordpress_user_without_ip_or_user_agent(): void {
			$GLOBALS['cybermaps_mock_logged_in'] = true;
			$GLOBALS['cybermaps_mock_user_id'] = 17;

			$this->assertTrue( ( new CrawlerAnalyticsRecorder() )->record_current_request() );
			$row = $GLOBALS['wpdb']->inserts[0];

			$this->assertSame( 'Logged-in site user', $row['bot'] );
			$this->assertSame( 'internal', $row['category'] );
			$this->assertSame( 'internal', $row['identity_status'] );
			$this->assertSame( 17, $row['wp_user_id'] );
			$this->assertSame( '', $row['ip_address'] );
			$this->assertSame( '', $row['user_agent'] );
			$this->assertSame( '', $row['requester_key'] );
			$this->assertSame( 'none', $row['ip_storage'] );
		}

		public function test_unknown_page_user_agent_is_not_recorded(): void {
			$_SERVER['HTTP_USER_AGENT'] = 'OrdinaryBrowser/1.0';
			$_SERVER['REQUEST_URI']     = '/ordinary-page/';

			$this->assertFalse( ( new CrawlerAnalyticsRecorder() )->record_current_request() );
			$this->assertSame( array(), $GLOBALS['wpdb']->inserts );
		}

		public function test_unregistered_bot_candidate_on_a_page_is_recorded(): void {
			$_SERVER['HTTP_USER_AGENT'] = 'IndependentResearchCrawler/2.0';
			$_SERVER['REQUEST_URI']     = '/ordinary-page/';

			$this->assertTrue( ( new CrawlerAnalyticsRecorder() )->record_current_request() );
			$row = $GLOBALS['wpdb']->inserts[0];

			$this->assertSame( 'Unregistered crawler candidate', $row['bot'] );
			$this->assertSame( 'unregistered-bot', $row['category'] );
			$this->assertSame( 'unregistered-bot', $row['identity_status'] );
			$this->assertSame( 0, $row['recognized'] );
		}

		public function test_cloudflare_resolution_is_used_before_ip_storage(): void {
			$_SERVER['REMOTE_ADDR']           = '104.16.0.25';
			$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.77';

			$this->assertTrue( ( new CrawlerAnalyticsRecorder() )->record_current_request() );
			$row = $GLOBALS['wpdb']->inserts[0];

			$this->assertSame( '198.51.100.0', $row['ip_address'] );
			$this->assertSame( 'cloudflare', $row['ip_source'] );
		}

		public function test_requester_key_is_site_specific_and_rejects_invalid_addresses(): void {
			$this->assertSame(
				CrawlerAnalyticsRecorder::get_requester_key( '203.0.113.42' ),
				CrawlerAnalyticsRecorder::get_requester_key( '203.0.113.42' )
			);
			$this->assertNotSame(
				CrawlerAnalyticsRecorder::get_requester_key( '203.0.113.42' ),
				CrawlerAnalyticsRecorder::get_requester_key( '203.0.113.43' )
			);
			$this->assertSame( '', CrawlerAnalyticsRecorder::get_requester_key( 'invalid' ) );
		}

		public function test_internal_health_probe_is_not_recorded(): void {
			$_SERVER['HTTP_X_CYBERMAPS_DIAGNOSTIC'] = '1';

			$this->assertFalse( ( new CrawlerAnalyticsRecorder() )->record_current_request() );
			$this->assertSame( array(), $GLOBALS['wpdb']->inserts );
			unset( $_SERVER['HTTP_X_CYBERMAPS_DIAGNOSTIC'] );
		}

		public function test_malformed_request_headers_fail_closed_without_php_warnings(): void {
			$_SERVER['HTTP_USER_AGENT']             = array( 'GPTBot/1.0' );
			$_SERVER['REQUEST_METHOD']              = new \stdClass();
			$_SERVER['HTTP_ACCEPT']                 = array( 'application/json' );
			$_SERVER['HTTP_X_CYBERMAPS_DIAGNOSTIC'] = array( '1' );

			set_error_handler(
				static function ( int $severity, string $message, string $file, int $line ): never {
					throw new \ErrorException( $message, 0, $severity, $file, $line );
				}
			);
			try {
				$recorded = ( new CrawlerAnalyticsRecorder() )->record_current_request();
			} finally {
				restore_error_handler();
			}

			$this->assertTrue( $recorded );
			$row = $GLOBALS['wpdb']->inserts[0];
			$this->assertSame( '', $row['user_agent'] );
			$this->assertSame( '', $row['request_method'] );
			$this->assertSame( 'none', $row['accept_type'] );
		}

		public function test_endpoint_observations_are_cached_for_one_minute_and_family_invalidated(): void {
			$GLOBALS['wpdb']->endpoint_observation_rows = array(
				array(
					'endpoint_id'         => 'llms',
					'php_requests'        => '8',
					'recognized_requests' => '3',
					'last_php'            => '2026-07-26 11:00:00',
					'last_recognized'     => '2026-07-26 10:00:00',
				),
			);

			$first = CrawlerAnalyticsRecorder::get_endpoint_observations();
			$GLOBALS['wpdb']->endpoint_observation_rows[0]['php_requests'] = '9';
			$cached = CrawlerAnalyticsRecorder::get_endpoint_observations();

			$this->assertSame( 8, $first['llms']['php_requests'] );
			$this->assertSame( $first, $cached );
			$this->assertSame( 1, $GLOBALS['wpdb']->endpoint_observation_queries );

			\Cybermaps\Core\CacheManager::clear_family( 'analytics' );
			$refreshed = CrawlerAnalyticsRecorder::get_endpoint_observations();
			$this->assertSame( 9, $refreshed['llms']['php_requests'] );
			$this->assertSame( 2, $GLOBALS['wpdb']->endpoint_observation_queries );
		}
	}

	final class AnalyticsWpdbStub {
		public string $prefix = 'wp_';
		public string $last_error = '';
		public bool $fail_insert = false;
		public int $endpoint_observation_queries = 0;

		/** @var array<int, array<string, mixed>> */
		public array $inserts = array();

		/** @var array<int, array<string, mixed>> */
		public array $endpoint_observation_rows = array();

		/**
		 * @param array<string, mixed> $data Insert data.
		 */
		public function insert( string $table, array $data ): int|false {
			unset( $table );
			if ( $this->fail_insert ) {
				$this->last_error = 'simulated';
				return false;
			}

			$this->inserts[] = $data;
			return 1;
		}

		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$query = (string) preg_replace( '/%i/', (string) $arg, $query, 1 );
			}
			return $query;
		}

		/**
		 * @return array<int, array<string, mixed>>
		 */
		public function get_results( string $query, string $output = ARRAY_A ): array {
			unset( $query, $output );
			++$this->endpoint_observation_queries;
			return $this->endpoint_observation_rows;
		}
	}

	final class AnalyticsRestRequestStub {
		public function __construct( private readonly string $route ) {}

		public function get_route(): string {
			return $this->route;
		}
	}

	final class AnalyticsRestResponseStub {
		public function __construct( private readonly int $status ) {}

		public function get_status(): int {
			return $this->status;
		}
	}
}
