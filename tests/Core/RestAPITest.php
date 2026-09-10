<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Core;

use Cybermaps\Core\RestAPI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RestAPITest extends \WP_UnitTestCase {
	private mixed $original_wpdb = null;

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;

		$this->original_wpdb = $wpdb ?? null;
		$GLOBALS['cybermaps_mock_is_multisite'] = false;
		$GLOBALS['cybermaps_mock_options'] = array(
			'cybermaps_settings' => array(
				'static_engine_mode'   => 'all',
				'enable_discovery_hub' => '1',
				'enable_caching'       => '1',
			),
			'cybermaps_last_static_sync'         => '2026-07-28T10:00:00+00:00',
			'cybermaps_last_static_sync_attempt' => '2026-07-29T10:00:00+00:00',
			'cybermaps_static_generation'        => 4,
		);
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb = $this->original_wpdb;
		unset( $_SERVER['HTTP_IF_NONE_MATCH'] );
		parent::tearDown();
	}

	public function test_public_discovery_response_gets_cache_validators(): void {
		$request = new class() {
			public function get_route(): string {
				return '/cybermaps/v1/discovery';
			}
		};
		$response = rest_ensure_response( array( 'ok' => true ) );

		$result  = ( new RestAPI() )->add_public_cache_validators( $response, null, $request );
		$headers = $result->get_headers();

		$this->assertSame( 200, $result->get_status() );
		$this->assertMatchesRegularExpression( '/^"[a-f0-9]{32}"$/', $headers['ETag'] );
		$this->assertSame( 'public, max-age=300, must-revalidate', $headers['Cache-Control'] );
		$this->assertSame( 'Accept', $headers['Vary'] );
		$this->assertSame( '6.0.0', $headers['X-Cybermaps-Version'] );
		$this->assertStringStartsWith( 'sha-256=:', $headers['Content-Digest'] );
	}

	public function test_public_health_and_mcp_card_responses_get_cache_validators(): void {
		$api = new RestAPI();
		foreach ( array( '/cybermaps/v1/health', '/cybermaps/v1/mcp/server-card' ) as $route ) {
			$request = new class( $route ) {
				public function __construct( private string $route ) {}

				public function get_route(): string {
					return $this->route;
				}
			};
			$response = $api->add_public_cache_validators( rest_ensure_response( array( 'ok' => true ) ), null, $request );

			$this->assertArrayHasKey( 'ETag', $response->get_headers() );
			$this->assertSame( 'public, max-age=300, must-revalidate', $response->get_headers()['Cache-Control'] );
		}
	}

	public function test_public_metadata_callbacks_set_cors_and_media_type(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['mcp_mode'] = 'discovery';
		$api = new RestAPI();

		$health = $api->get_public_health();
		$card   = $api->get_mcp_server_card();

		$this->assertSame( \Cybermaps\Discovery\PublicHealth::MEDIA_TYPE, $health->get_headers()['Content-Type'] );
		$this->assertSame( '*', $health->get_headers()['Access-Control-Allow-Origin'] );
		$this->assertSame( \Cybermaps\Discovery\MCPServerCard::MEDIA_TYPE, $card->get_headers()['Content-Type'] );
		$this->assertSame( '*', $card->get_headers()['Access-Control-Allow-Origin'] );
	}

	public function test_public_discovery_response_honors_if_none_match(): void {
		$request = new class() {
			public function get_route(): string {
				return '/cybermaps/v1/discovery';
			}
		};
		$api      = new RestAPI();
		$initial  = $api->add_public_cache_validators( rest_ensure_response( array( 'ok' => true ) ), null, $request );
		$_SERVER['HTTP_IF_NONE_MATCH'] = $initial->get_headers()['ETag'];

		$validated = $api->add_public_cache_validators( rest_ensure_response( array( 'ok' => true ) ), null, $request );

		$this->assertSame( 304, $validated->get_status() );
		$this->assertNull( $validated->get_data() );
	}

	public function test_private_rest_response_does_not_get_public_validators(): void {
		$request = new class() {
			public function get_route(): string {
				return '/cybermaps/v1/status';
			}
		};
		$response = new \WP_REST_Response( array( 'private' => true ) );
		$response->header( 'Cache-Control', 'no-store, private' );

		$result = ( new RestAPI() )->add_public_cache_validators( $response, null, $request );

		$this->assertSame( array( 'Cache-Control' => 'no-store, private' ), $result->get_headers() );
	}

	public function test_status_reports_failed_physical_publication_factually(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_last_static_sync_report'] = array(
			'status'     => 'failed',
			'success'    => false,
			'mode'       => 'all',
			'generation' => 4,
			'counts'     => array(
				'desired' => 12,
				'written' => 3,
				'failed'  => 2,
			),
		);

		$response = ( new RestAPI() )->get_status();
		$data     = $response->get_data();

		$this->assertSame( 'degraded', $data['status'] );
		$this->assertSame( 'all', $data['static_publication']['mode'] );
		$this->assertSame( 'failed', $data['static_publication']['sync_state'] );
		$this->assertFalse( $data['static_publication']['success'] );
		$this->assertSame( 12, $data['static_publication']['counts']['desired'] );
		$this->assertSame( 3, $data['static_publication']['counts']['written'] );
		$this->assertSame( 2, $data['static_publication']['counts']['failed'] );
		$this->assertSame( 0, $data['static_publication']['counts']['conflicted'] );
		$this->assertTrue( $data['sitemap']['cache_enabled'] );
		$this->assertTrue( $data['discovery']['enabled'] );
		$this->assertSame( 'wordpress-transients', $data['cache']['backend'] );
		$this->assertSame( array(), $data['edge_invalidation'] );
		$this->assertPrivateNoStoreHeaders( $response );
	}

	public function test_status_does_not_claim_a_physical_sync_when_mode_is_off(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['static_engine_mode'] = 'off';
		$GLOBALS['cybermaps_mock_options']['cybermaps_last_static_sync_report'] = array(
			'status'     => 'failed',
			'success'    => false,
			'mode'       => 'all',
			'generation' => 4,
		);

		$response = ( new RestAPI() )->get_status();
		$data     = $response->get_data();

		$this->assertSame( 'operational', $data['status'] );
		$this->assertSame( 'not_required', $data['static_publication']['sync_state'] );
		$this->assertSame( 'off', $data['static_publication']['mode'] );
		$this->assertTrue( $data['static_publication']['success'] );
		$this->assertPrivateNoStoreHeaders( $response );
	}

	public function test_status_marks_a_previous_generation_report_as_stale(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_last_static_sync_report'] = array(
			'status'     => 'complete',
			'success'    => true,
			'mode'       => 'well_known',
			'generation' => 3,
		);

		$data = ( new RestAPI() )->get_status()->get_data();

		$this->assertSame( 'attention', $data['status'] );
		$this->assertSame( 'stale', $data['static_publication']['sync_state'] );
		$this->assertFalse( $data['static_publication']['success'] );
	}

	public function test_status_is_unverified_before_first_required_sync(): void {
		unset( $GLOBALS['cybermaps_mock_options']['cybermaps_last_static_sync_report'] );

		$data = ( new RestAPI() )->get_status()->get_data();

		$this->assertSame( 'unverified', $data['status'] );
		$this->assertSame( 'not_run', $data['static_publication']['sync_state'] );
	}

	public function test_status_fails_closed_for_malformed_nested_runtime_state(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['enable_caching'] = array( '1' );
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['enable_discovery_hub'] = new \stdClass();
		$GLOBALS['cybermaps_mock_options']['cybermaps_static_generation'] = array( 4 );
		$GLOBALS['cybermaps_mock_options']['cybermaps_last_static_sync'] = array( 'bad' );
		$GLOBALS['cybermaps_mock_options']['cybermaps_last_static_sync_attempt'] = new \stdClass();
		$GLOBALS['cybermaps_mock_options']['cybermaps_last_static_sync_report'] = array(
			'status'     => array( 'complete' ),
			'mode'       => new \stdClass(),
			'generation' => array( 4 ),
			'success'    => array( true ),
			'counts'     => array(
				'desired' => array( 12 ),
				'written' => new \stdClass(),
			),
		);

		$data = ( new RestAPI() )->get_status()->get_data();

		$this->assertSame( 'unverified', $data['status'] );
		$this->assertSame( 'not_run', $data['static_publication']['sync_state'] );
		$this->assertFalse( $data['static_publication']['success'] );
		$this->assertSame( 0, $data['static_publication']['counts']['desired'] );
		$this->assertSame( 0, $data['static_publication']['counts']['written'] );
		$this->assertSame( '', $data['static_publication']['last_success'] );
		$this->assertSame( '', $data['static_publication']['last_attempt'] );
		$this->assertFalse( $data['sitemap']['cache_enabled'] );
		$this->assertFalse( $data['discovery']['enabled'] );
		$this->assertSame( 'wordpress-transients', $data['cache']['backend'] );
	}

	public function test_status_sanitizes_edge_invalidation_history(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_edge_cache_delivery_status'] = array(
			array(
				'id'         => 'evt-1',
				'time'       => 123,
				'family'     => 'discovery',
				'generation' => 9,
				'tag_count'  => 3,
				'url_count'  => 2,
				'status'     => 'ok',
				'urls'       => array( 'https://example.com/leak' ),
				'adapters'   => array(
					array(
						'adapter'   => 'varnish',
						'status'    => 'ok',
						'supported' => true,
						'purged'    => 2,
						'code'      => 200,
						'token'     => 'secret',
						'headers'   => array( 'Authorization' => 'Bearer token' ),
					),
				),
			),
		);

		$data    = ( new RestAPI() )->get_status()->get_data();
		$history = $data['edge_invalidation'];

		$this->assertCount( 1, $history );
		$this->assertArrayNotHasKey( 'urls', $history[0] );
		$this->assertSame(
			array(
				'adapter'   => 'varnish',
				'status'    => 'ok',
				'supported' => true,
				'purged'    => 2,
				'code'      => 200,
			),
			$history[0]['adapters'][0]
		);
	}

	public function test_latest_audit_success_is_private_and_non_cacheable(): void {
		global $wpdb;
		$wpdb = new RestAuditDatabaseStub( 7 );

		$response = ( new RestAPI() )->get_latest_audit();
		$data     = $response->get_data();

		$this->assertSame( 7, (int) $data['id'] );
		$this->assertSame( array(), $data['resources'] );
		$this->assertSame( array(), $data['findings'] );
		$this->assertSame( 0, $data['diff']['baseline_run_id'] );
		$this->assertPrivateNoStoreHeaders( $response );
	}

	public function test_latest_audit_not_found_error_is_private_and_non_cacheable(): void {
		global $wpdb;
		$wpdb = new RestAuditDatabaseStub( 0 );

		$response = ( new RestAPI() )->get_latest_audit();
		$data     = $response->get_data();

		$this->assertSame( 'cybermaps_audit_not_found', $data['code'] );
		$this->assertSame( 404, $data['data']['status'] );
		$this->assertPrivateNoStoreHeaders( $response );
	}

	public function test_latest_audit_rejects_an_oversized_json_snapshot_before_hydration(): void {
		global $wpdb;
		$wpdb = new RestAuditDatabaseStub( 7, 25000, 1 );

		$response = ( new RestAPI() )->get_latest_audit();
		$data     = $response->get_data();

		$this->assertSame( 'cybermaps_audit_too_large', $data['code'] );
		$this->assertSame( 413, $data['data']['status'] );
		$this->assertStringNotContainsString(
			'SELECT * FROM wp_cybermaps_audit_resources',
			implode( "\n", $wpdb->queries )
		);
		$this->assertPrivateNoStoreHeaders( $response );
	}

	public function test_audit_run_database_failure_is_a_private_server_error_not_not_found(): void {
		global $wpdb;
		$wpdb = new RestAuditDatabaseStub( 7 );
		$wpdb->fail_profile_read = true;
		$request = new class() {
			public function get_param( string $name ): int {
				unset( $name );
				return 7;
			}
		};

		$response = ( new RestAPI() )->get_audit_run( $request );
		$data     = $response->get_data();

		$this->assertSame( 'cybermaps_audit_database_error', $data['code'] );
		$this->assertSame( 500, $data['data']['status'] );
		$this->assertPrivateNoStoreHeaders( $response );
	}

	public function test_private_url_inventory_remains_available_when_public_hub_is_disabled(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['enable_discovery_hub'] = '0';

		$response = ( new RestAPI() )->get_urls( null );
		$data     = $response->get_data();

		$this->assertFalse( $data['discovery_enabled'] );
		$this->assertArrayHasKey( 'discovery', $data );
		$this->assertArrayHasKey( 'sitemap_xml', $data );
		$this->assertPrivateNoStoreHeaders( $response );
	}

	public function test_secret_permission_fails_closed_for_malformed_stored_or_request_values(): void {
		$api = new RestAPI();
		$request = new class( 'secret-value' ) {
			public function __construct( private mixed $header ) {}

			public function get_header( string $name ): mixed {
				unset( $name );
				return $this->header;
			}
		};

		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['api_secret'] = array( 'bad' );
		$this->assertFalse( $api->check_secret_permission( $request ) );

		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['api_secret'] = 'secret-value';
		$this->assertFalse( $api->check_secret_permission( new \stdClass() ) );
		$this->assertTrue( $api->check_secret_permission( $request ) );
	}

	private function assertPrivateNoStoreHeaders( \WP_REST_Response $response ): void {
		$property = new \ReflectionProperty( \WP_REST_Response::class, 'headers' );
		$headers  = $property->getValue( $response );

		$this->assertSame( 'no-cache, no-store, must-revalidate, private', $headers['Cache-Control'] );
		$this->assertSame( 'no-cache', $headers['Pragma'] );
		$this->assertSame( '0', $headers['Expires'] );
	}
}

/**
 * Minimal database surface needed by AuditReadAPI.
 */
final class RestAuditDatabaseStub {
	public string $prefix = 'wp_';
	public string $last_error = '';
	public bool $fail_profile_read = false;
	/** @var string[] */
	public array $queries = array();

	public function __construct(
		private readonly int $latest_id,
		private readonly int $resource_count = 0,
		private readonly int $finding_count = 0,
		private readonly int $baseline_finding_count = 0
	) {}

	public function get_var( string $query ): int {
		$this->queries[] = $query;
		return $this->latest_id;
	}

	public function prepare( string $query, mixed ...$args ): string {
		foreach ( $args as $arg ) {
			preg_match( '/%[dis]/', $query, $placeholder );
			$replacement = '%i' === ( $placeholder[0] ?? '' )
				? (string) $arg
				: ( is_int( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'" );
			$query = (string) preg_replace( '/%[dis]/', $replacement, $query, 1 );
		}
		return $query;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function get_row( string $query, string $output ): ?array {
		unset( $output );
		$this->queries[] = $query;
		if ( $this->latest_id < 1 || ! str_contains( $query, 'id = ' . $this->latest_id ) ) {
			return null;
		}
		if ( str_contains( $query, 'SELECT report.resource_count' ) ) {
			if ( $this->fail_profile_read ) {
				$this->last_error = 'Simulated report profile failure';
				return null;
			}
			return array(
				'resource_count'          => $this->resource_count,
				'finding_count'           => $this->finding_count,
				'baseline_run_id'         => 0,
				'baseline_finding_count'  => $this->baseline_finding_count,
			);
		}

		return array(
			'id'              => $this->latest_id,
			'status'          => 'complete',
			'policy_json'     => '{}',
			'baseline_run_id' => 0,
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_results( string $query, string $output ): array {
		unset( $output );
		$this->queries[] = $query;
		return array();
	}
}
