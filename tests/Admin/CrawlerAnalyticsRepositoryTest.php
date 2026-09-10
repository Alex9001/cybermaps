<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\CrawlerAnalyticsRepository;

final class CrawlerAnalyticsRepositoryTest extends \WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_transients'] = array();
		$GLOBALS['cybermaps_mock_options'] = array();
		$GLOBALS['cybermaps_mock_current_time_mysql'] = '2026-07-26 12:00:00';
		$GLOBALS['wpdb'] = new AnalyticsRepositoryWpdbStub();
	}

	public function test_overview_separates_endpoint_observations_from_recognized_activity(): void {
		$overview = ( new CrawlerAnalyticsRepository() )->get_overview();

		$this->assertSame( 8, $overview['summary']['php_endpoint_requests'] );
		$this->assertSame( 3, $overview['summary']['recognized_endpoint_requests'] );
		$this->assertSame( 5, $overview['summary']['recognized_page_requests'] );
		$this->assertSame( 4, $overview['summary']['distinct_signatures'] );
		$this->assertSame( 8, $overview['summary']['claimed_requests'] );
		$this->assertSame( 3, $overview['summary']['claimed_endpoint_requests'] );
		$this->assertSame( 5, $overview['summary']['claimed_page_requests'] );
		$this->assertSame( 2, $overview['summary']['internal_requests'] );
		$this->assertSame( 6, $overview['summary']['unknown_requests'] );
		$this->assertSame( 4, $overview['summary']['unregistered_crawler_requests'] );
		$this->assertSame( 1, $overview['summary']['endpoint_errors'] );
		$this->assertSame( 'llms', $overview['endpoints'][0]['endpoint_id'] );
		$this->assertSame( '/thin-content/', $overview['content'][0]['url'] );
		$this->assertCount( 30, $overview['trend'] );
		$this->assertSame( '2026-06-27', $overview['trend'][0]['day'] );
		$this->assertSame( '2026-07-26', $overview['trend'][29]['day'] );

		$sql = implode( "\n", $GLOBALS['wpdb']->prepared_queries );
		$this->assertStringContainsString( "recognized = 1", $sql );
		$this->assertStringNotContainsString( 'category IN', $sql );
		$this->assertStringContainsString( "identity_status = 'claimed'", $sql );
		$this->assertStringContainsString( "identity_status = 'internal'", $sql );
		$this->assertStringNotContainsString( 'UPDATE ', $sql );
		$this->assertStringContainsString( '2026-06-27 00:00:00', $sql );
	}

	public function test_overview_groups_and_sanitizes_unknown_client_evidence(): void {
		$unknown = ( new CrawlerAnalyticsRepository() )->get_overview()['unknown_clients'];

		$this->assertCount( 2, $unknown );
		$this->assertSame( 'unregistered-bot', $unknown[0]['identity_status'] );
		$this->assertSame( 'Unregistered crawler candidate', $unknown[0]['label'] );
		$this->assertSame( 'ScannerBot/1.0', $unknown[0]['user_agent'] );
		$this->assertSame( 'a1b2c3d4e5f60708', $unknown[0]['requester_key'] );
		$this->assertSame( '203.0.113.0', $unknown[0]['ip_address'] );
		$this->assertSame( 'cloudflare', $unknown[0]['ip_source'] );
		$this->assertSame( 'anonymized', $unknown[0]['ip_storage'] );
		$this->assertSame( 'GET', $unknown[0]['request_method'] );
		$this->assertSame( 'json', $unknown[0]['accept_type'] );
		$this->assertSame( 3, $unknown[0]['endpoint_count'] );
		$this->assertSame( 7, $unknown[0]['endpoint_requests'] );
		$this->assertSame( 9, $unknown[0]['request_count'] );
		$this->assertSame( '2026-07-25 09:00:00', $unknown[0]['first_seen'] );
		$this->assertSame( '2026-07-26 11:30:00', $unknown[0]['last_seen'] );

		$this->assertSame( 'no-user-agent', $unknown[1]['identity_status'] );
		$this->assertSame( '', $unknown[1]['user_agent'] );
		$this->assertSame( '', $unknown[1]['request_method'] );
		$this->assertSame( 'full', $unknown[1]['ip_storage'] );

		$sql = implode( "\n", $GLOBALS['wpdb']->prepared_queries );
		$this->assertStringContainsString( "AND recognized = 0", $sql );
		$this->assertStringContainsString( "COALESCE(identity_status, 'legacy') <> 'claimed'", $sql );
		$this->assertStringContainsString( "COALESCE(identity_status, 'legacy') <> 'internal'", $sql );
		$this->assertStringContainsString( "category <> 'authenticated'", $sql );
		$this->assertStringContainsString( 'COUNT(DISTINCT CASE', $sql );
		$this->assertStringContainsString( 'LIMIT 50', $sql );
	}

	public function test_new_overview_cache_version_does_not_reuse_the_old_shape(): void {
		$GLOBALS['cybermaps_mock_transients']['cybermaps_analytics_overview_v1'] = array(
			'summary' => array( 'php_endpoint_requests' => 999 ),
		);

		$overview = ( new CrawlerAnalyticsRepository() )->get_overview();

		$this->assertSame( 8, $overview['summary']['php_endpoint_requests'] );
		$this->assertArrayHasKey( 'unknown_clients', $overview );
		$this->assertArrayHasKey( 'cybermaps_analytics_overview_v2', $GLOBALS['cybermaps_mock_transients'] );
	}

	public function test_recent_activity_balances_request_kinds_and_orders_them_together(): void {
		$activity = ( new CrawlerAnalyticsRepository() )->get_recent_activity();

		$this->assertCount( 2, $activity );
		$this->assertSame( 'page', $activity[0]['request_kind'] );
		$this->assertSame( 'endpoint', $activity[1]['request_kind'] );
		$this->assertSame( 'Unrecognized request', $activity[1]['bot'] );

		$sql = implode( "\n", $GLOBALS['wpdb']->prepared_queries );
		$this->assertStringContainsString( "recognized = 1 OR identity_status = 'unregistered-bot'", $sql );
	}

	public function test_dashboard_activity_uses_one_cached_five_row_projection(): void {
		$repository = new CrawlerAnalyticsRepository();
		$activity   = $repository->get_widget_activity();
		$query_count = count( $GLOBALS['wpdb']->prepared_queries );

		$this->assertCount( 2, $activity );
		$sql = $GLOBALS['wpdb']->prepared_queries[ $query_count - 1 ];
		$this->assertStringContainsString(
			'SELECT time, bot, url, request_kind, response_status',
			$sql
		);
		$this->assertStringNotContainsString( 'SELECT *', $sql );
		$this->assertStringContainsString( 'ORDER BY time DESC, id DESC', $sql );
		$this->assertStringContainsString( 'LIMIT 5', $sql );
		$this->assertSame( $activity, $repository->get_widget_activity() );
		$this->assertCount( $query_count, $GLOBALS['wpdb']->prepared_queries );
		$this->assertArrayHasKey(
			'cybermaps_recent_logs_widget',
			$GLOBALS['cybermaps_mock_transients']
		);
	}

	public function test_cutoff_uses_the_wordpress_site_local_clock(): void {
		$this->assertSame(
			'2026-07-19 12:00:00',
			CrawlerAnalyticsRepository::cutoff_mysql( 7 )
		);
	}
}

final class AnalyticsRepositoryWpdbStub {
	public string $prefix = 'wp_';

	/** @var string[] */
	public array $prepared_queries = array();

	public function prepare( string $query, ...$args ): string {
		foreach ( $args as $arg ) {
			preg_match( '/%[dis]/', $query, $placeholder );
			$replacement = '%i' === ( $placeholder[0] ?? '' )
				? (string) $arg
				: ( is_int( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'" );
			$query = (string) preg_replace( '/%[dis]/', $replacement, $query, 1 );
		}
		$this->prepared_queries[] = $query;
		return $query;
	}

	/**
	 * @return array<string,string|int>
	 */
	public function get_row( string $query, string $output = ARRAY_A ): array {
		unset( $query, $output );
		return array(
			'php_endpoint_requests'          => '8',
			'recognized_endpoint_requests'   => '3',
			'recognized_page_requests'       => '5',
			'distinct_signatures'            => '4',
			'claimed_requests'               => '8',
			'claimed_endpoint_requests'      => '3',
			'claimed_page_requests'          => '5',
			'internal_requests'              => '2',
			'unknown_requests'               => '6',
			'unregistered_crawler_requests' => '4',
			'endpoint_errors'                => '1',
		);
	}

	/**
	 * @return array<int,array<string,string|int>>
	 */
	public function get_results( string $query, string $output = ARRAY_A ): array {
		unset( $output );

		if (
			str_contains( $query, 'SELECT time, bot, url, request_kind, response_status' )
			&& str_contains( $query, 'LIMIT 5' )
		) {
			return array(
				array(
					'time'            => '2026-07-26 12:00:00',
					'bot'             => 'OAI-SearchBot',
					'url'             => '/latest/',
					'request_kind'    => 'page',
					'response_status' => '200',
				),
				array(
					'time'            => '2026-07-26 11:00:00',
					'bot'             => 'Unrecognized request',
					'url'             => '/llms.txt',
					'request_kind'    => 'endpoint',
					'response_status' => '200',
				),
			);
		}

		if ( str_contains( $query, 'GROUP BY endpoint_id' ) ) {
			return array(
				array(
					'endpoint_id'          => 'llms',
					'url'                  => '/llms.txt',
					'php_requests'         => '8',
					'recognized_requests'  => '3',
					'recognized_signatures' => '2',
					'errors'               => '1',
					'last_observed'        => '2026-07-26 11:00:00',
				),
			);
		}

		if ( str_contains( $query, 'GROUP BY url' ) ) {
			return array(
				array(
					'url'                   => '/thin-content/',
					'requests'              => '5',
					'recognized_signatures' => '2',
					'last_observed'         => '2026-07-26 12:00:00',
				),
			);
		}

		if ( str_contains( $query, 'GROUP BY bot, category' ) ) {
			return array(
				array(
					'bot'               => 'OAI-SearchBot',
					'category'          => 'ai-search',
					'requests'          => '4',
					'endpoint_requests' => '1',
					'page_requests'     => '3',
					'last_observed'     => '2026-07-26 12:00:00',
				),
			);
		}

		if ( str_contains( $query, 'GROUP BY category' ) ) {
			return array(
				array(
					'category'          => 'ai-search',
					'requests'          => '4',
					'endpoint_requests' => '1',
					'page_requests'     => '3',
				),
			);
		}

		if ( str_contains( $query, 'GROUP BY DATE(time)' ) ) {
			return array(
				array(
					'day'                           => '2026-07-26',
					'php_endpoint_requests'         => '2',
					'recognized_endpoint_requests'  => '1',
					'recognized_page_requests'      => '3',
				),
			);
		}

		if ( str_contains( $query, 'LEFT(user_agent, 512)' ) ) {
			return array(
				array(
					'identity_status'   => 'unregistered-bot',
					'label'             => 'Unregistered <strong>crawler</strong> candidate',
					'user_agent'        => '<b>ScannerBot/1.0</b>',
					'requester_key'     => 'A1B2C3D4E5F60708',
					'ip_address'        => '203.0.113.0',
					'ip_source'         => 'Cloudflare',
					'ip_storage'        => 'Anonymized',
					'request_method'    => 'get',
					'accept_type'       => 'JSON',
					'endpoint_count'    => '3',
					'endpoint_requests' => '7',
					'request_count'     => '9',
					'first_seen'        => '2026-07-25 09:00:00',
					'last_seen'         => '2026-07-26 11:30:00',
				),
				array(
					'identity_status'   => 'no-user-agent',
					'label'             => 'Client without User-Agent',
					'user_agent'        => '',
					'requester_key'     => '1111222233334444',
					'ip_address'        => '2001:db8::42',
					'ip_source'         => 'direct',
					'ip_storage'        => 'full',
					'request_method'    => 'GE T',
					'accept_type'       => 'none',
					'endpoint_count'    => '1',
					'endpoint_requests' => '1',
					'request_count'     => '2',
					'first_seen'        => '2026-07-26 10:00:00',
					'last_seen'         => '2026-07-26 10:05:00',
				),
			);
		}

		if ( str_contains( $query, "request_kind = 'endpoint'" ) ) {
			return array(
				array(
					'id'              => '10',
					'time'            => '2026-07-26 11:00:00',
					'bot'             => 'Unrecognized request',
					'category'        => 'unrecognized',
					'request_kind'    => 'endpoint',
					'recognized'      => '0',
					'url'             => '/llms.txt',
					'response_status' => '200',
				),
			);
		}

		return array(
			array(
				'id'              => '11',
				'time'            => '2026-07-26 12:00:00',
				'bot'             => 'OAI-SearchBot',
				'category'        => 'ai-search',
				'request_kind'    => 'page',
				'recognized'      => '1',
				'url'             => '/thin-content/',
				'response_status' => '200',
			),
		);
	}
}
