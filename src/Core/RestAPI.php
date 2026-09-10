<?php
declare(strict_types=1);
namespace Cybermaps\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RestAPI {
	private EndpointRegistry $endpoints;

	public function __construct( ?EndpointRegistry $endpoints = null ) {
		$this->endpoints = $endpoints ?? EndpointRegistry::get_instance();
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_post_dispatch', array( $this, 'add_public_cache_validators' ), 10, 3 );
	}

	/**
	 * Add validators only to successful public Cybermaps discovery responses.
	 *
	 * @param mixed $response REST response after callback dispatch.
	 * @param mixed $server   REST server instance.
	 * @param mixed $request  REST request instance.
	 * @return mixed
	 */
	public function add_public_cache_validators( $response, $server, $request ) {
		unset( $server );
		if ( ! $this->is_public_cache_response( $response, $request ) ) {
			return $response;
		}

		$body = wp_json_encode( $response->get_data() );
		if ( ! is_string( $body ) ) {
			return $response;
		}
		$etag = '"' . md5( $body ) . '"';
		$response->header( 'ETag', $etag );
		$response->header( 'Cache-Control', 'public, max-age=300, must-revalidate' );
		$response->header( 'Vary', 'Accept' );
		$response->header( 'X-Cybermaps-Version', CYBERMAPS_VERSION );
		$response->header( 'Content-Digest', 'sha-256=:' . base64_encode( hash( 'sha256', $body, true ) ) . ':' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- RFC Content-Digest requires Base64.

		if ( \Cybermaps\Discovery\Integrity::is_not_modified( $etag, null ) ) {
			if ( method_exists( $response, 'set_status' ) ) {
				$response->set_status( 304 );
			}
			if ( method_exists( $response, 'set_data' ) ) {
				$response->set_data( null );
			}
		}

		return $response;
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes() {
		$this->register_route(
			'rest_root',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_discovery' ),
				'permission_callback' => array( $this, 'check_discovery_hub_permission' ),
			)
		);

		$this->register_route(
			'public_health',
			array(
				'methods'             => array( 'GET', 'HEAD' ),
				'callback'            => array( $this, 'get_public_health' ),
				'permission_callback' => array( $this, 'check_discovery_hub_permission' ),
			)
		);

		$this->register_route(
			'rest_mcp_server_card',
			array(
				'methods'             => array( 'GET', 'HEAD' ),
				'callback'            => array( $this, 'get_mcp_server_card' ),
				'permission_callback' => array( $this, 'check_mcp_server_card_permission' ),
			)
		);

		$this->register_route(
			'rest_llms_tldr',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_llms_tldr' ),
				'permission_callback' => array( $this, 'check_llms_tldr_permission' ),
			)
		);

		$this->register_route(
			'rest_search',
			array(
				'methods'             => 'GET',
				'callback'            => array( new \Cybermaps\Discovery\Search(), 'handle_search' ),
				'permission_callback' => array( $this, 'check_discovery_hub_permission' ),
				'args'                => array(
					'q'     => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => array( \Cybermaps\Discovery\Search::class, 'normalize_query' ),
						'validate_callback' => array( \Cybermaps\Discovery\Search::class, 'is_valid_query' ),
						'description'       => __( 'Search query string.', 'cybermaps' ),
					),
					'limit' => array(
						'type'              => 'integer',
						'default'           => 20,
						'minimum'           => 1,
						'maximum'           => 100,
						'sanitize_callback' => 'absint',
						'description'       => __( 'Maximum number of results to return.', 'cybermaps' ),
					),
				),
			)
		);

		$this->register_route(
			'rest_urls',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_urls' ),
				'permission_callback' => array( $this, 'check_secret_permission' ),
			)
		);

		$this->register_route(
			'rest_status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_status' ),
				'permission_callback' => array( $this, 'check_secret_permission' ),
			)
		);

		$this->register_route(
			'rest_audit_latest',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_latest_audit' ),
				'permission_callback' => array( $this, 'check_secret_permission' ),
			)
		);

		$this->register_route(
			'rest_audit_run',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_audit_run' ),
				'permission_callback' => array( $this, 'check_secret_permission' ),
				'args'                => array(
					'run_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'minimum'           => 1,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		$this->register_route(
			'rest_purge',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'purge_static_files' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	/**
	 * Register a route only when its implementation is represented in the
	 * shared endpoint registry.
	 *
	 * @param string               $endpoint_id Endpoint registry ID.
	 * @param array<string, mixed> $args        WordPress REST route arguments.
	 */
	private function register_route( string $endpoint_id, array $args ): void {
		$route = $this->endpoints->get_rest_route( $endpoint_id );
		if ( null === $route ) {
			return;
		}

		register_rest_route( $route['namespace'], $route['route'], $args );
	}

	/**
	 * Purge all physically generated discovery files.
	 *
	 * @return \WP_REST_Response
	 */
	public function purge_static_files( $request ) {
		unset( $request );
		$bridge = \Cybermaps\Discovery\StaticBridge::get_instance();
		$result = $bridge->purge_all();
		return $this->private_response( $result );
	}

	/**
	 * Require the AI Publication Hub to be enabled for public discovery REST routes.
	 *
	 * @return true|\WP_Error
	 */
	public function check_discovery_hub_permission() {
		if ( ! \Cybermaps\Discovery\Integrity::is_hub_enabled() ) {
			return new \WP_Error(
				'discovery_hub_disabled',
				__( 'AI Publication Hub is disabled.', 'cybermaps' ),
				array( 'status' => 404 )
			);
		}
		return true;
	}

	/**
	 * Require an active MCP mode without requiring an OAuth access token.
	 *
	 * @return true|\WP_Error
	 */
	public function check_mcp_server_card_permission() {
		if ( ! \Cybermaps\Discovery\MCPServerCard::is_available() ) {
			return new \WP_Error(
				'mcp_server_card_disabled',
				__( 'The MCP Server Card is unavailable while MCP is disabled.', 'cybermaps' ),
				array( 'status' => 404 )
			);
		}
		return true;
	}

	/**
	 * Check secret permission based on X-Cybermaps-Secret header.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return bool
	 */
	public function check_secret_permission( $request ) {
		$settings = ConfigurationStore::settings();
		$secret   = isset( $settings['api_secret'] ) && is_scalar( $settings['api_secret'] )
			? (string) $settings['api_secret']
			: '';
		if ( '' === $secret || ! is_object( $request ) || ! method_exists( $request, 'get_header' ) ) {
			return false;
		}

		$header = $request->get_header( 'X-Cybermaps-Secret' );
		if ( ! is_scalar( $header ) || '' === (string) $header ) {
			return false;
		}

		return hash_equals( $secret, (string) $header );
	}

	/**
	 * Get all active discovery manifests.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_discovery() {
		$base     = \Cybermaps\Sitemap\Orchestrator::get_sitemap_base();
		$settings = ConfigurationStore::settings();
		$data     = array(
			'adp'                => $this->endpoints->get_url( 'adp_discovery' ),
			'cybermaps_manifest' => $this->endpoints->get_url( 'manifest' ),
			'llms'               => $this->endpoints->get_url( 'llms' ),
			'feed'               => $this->endpoints->get_url( 'feed' ),
			'updates'            => $this->endpoints->get_url( 'updates' ),
			'knowledge_graph'    => $this->endpoints->get_url( 'knowledge_graph' ),
			'ai_sitemap'         => $this->endpoints->get_url( 'ai_sitemap' ),
			'api_catalog'        => $this->endpoints->get_url( 'api_catalog' ),
			'ai_catalog'         => $this->endpoints->get_url( 'ai_catalog' ),
			'openapi'            => $this->endpoints->get_url( 'openapi' ),
			'health'             => $this->endpoints->get_url( 'public_health' ),
			'sitemap_index'      => \Cybermaps\Core\URLManager::get_home_url( '/' . $base . '.xml' ),
		);
		if ( $this->endpoints->is_enabled( 'llms_tldr', $settings ) ) {
			$data['llms_tldr'] = $this->endpoints->get_url( 'llms_tldr' );
		}
		if ( $this->endpoints->is_enabled( 'llms_full', $settings ) ) {
			$data['llms_full'] = $this->endpoints->get_url( 'llms_full' );
		}
		if ( $this->endpoints->is_enabled( 'rest_mcp', $settings ) ) {
			$data['mcp']             = $this->endpoints->get_url( 'rest_mcp' );
			$data['mcp_server_card'] = $this->endpoints->get_url( 'rest_mcp_server_card' );
		}
		if ( $this->endpoints->is_enabled( 'auth_md', $settings ) ) {
			$data['auth'] = $this->endpoints->get_url( 'auth_md' );
		}
		$publisher_guidance = \Cybermaps\Discovery\PublisherGuidance::get( $settings );
		if ( '' !== $publisher_guidance ) {
			$data['publisher_guidance'] = $publisher_guidance;
		}
		return rest_ensure_response( $data );
	}

	/**
	 * Return stable, non-sensitive public discovery health.
	 */
	public function get_public_health(): \WP_REST_Response {
		return $this->public_metadata_response(
			( new \Cybermaps\Discovery\PublicHealth() )->get_health_data(),
			\Cybermaps\Discovery\PublicHealth::MEDIA_TYPE
		);
	}

	/**
	 * Return the current experimental MCP Server Card.
	 */
	public function get_mcp_server_card(): \WP_REST_Response {
		return $this->public_metadata_response(
			( new \Cybermaps\Discovery\MCPServerCard() )->get_card_data(),
			\Cybermaps\Discovery\MCPServerCard::MEDIA_TYPE
		);
	}

	/**
	 * Build a public metadata response with explicit cross-origin discovery.
	 *
	 * @param array<string,mixed> $data       Response body.
	 * @param string              $media_type Response media type.
	 */
	private function public_metadata_response( array $data, string $media_type ): \WP_REST_Response {
		$response = rest_ensure_response( $data );
		$response->header( 'Content-Type', $media_type );
		$response->header( 'Access-Control-Allow-Origin', '*' );
		return $response;
	}

	public function get_llms_tldr() {
		$tldr = new \Cybermaps\Discovery\LLMSTLDR();
		try {
			return rest_ensure_response( array( 'content' => $tldr->get_content() ) );
		} catch ( BuildUnavailableException $error ) {
			return new \WP_REST_Response(
				array(
					'code'    => 'cybermaps_publication_unavailable',
					'message' => $error->getMessage(),
				),
				503,
				array(
					'Retry-After'   => BuildUnavailableException::RETRY_AFTER,
					'Cache-Control' => 'no-store, max-age=0',
				)
			);
		}
	}

	/**
	 * Require both the AI Publication Hub and the experimental briefing opt-in.
	 */
	public function check_llms_tldr_permission() {
		$hub_permission = $this->check_discovery_hub_permission();
		if ( true !== $hub_permission ) {
			return $hub_permission;
		}
		if (
			! $this->endpoints->is_enabled(
				'llms_tldr',
				ConfigurationStore::settings()
			)
		) {
			return new \WP_Error(
				'budgeted_briefing_disabled',
				__( 'The experimental budgeted site briefing is disabled.', 'cybermaps' ),
				array( 'status' => 404 )
			);
		}
		return true;
	}

	/**
	 * Get discovery and sitemap entry URLs (authenticated via X-Cybermaps-Secret).
	 *
	 * @param \WP_REST_Request $request The REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_urls( $request ) {
		unset( $request );

		$base    = \Cybermaps\Sitemap\Orchestrator::get_sitemap_base();
		$index   = new \Cybermaps\Discovery\DiscoveryIndex();
		$payload = array(
			'version'           => CYBERMAPS_VERSION,
			'generated'         => gmdate( 'c' ),
			'discovery_enabled' => \Cybermaps\Discovery\Integrity::is_hub_enabled(),
			'discovery'         => $this->get_discovery()->get_data(),
			'index'             => $index->get_index(),
			'rest_api'          => rest_url( EndpointRegistry::REST_NAMESPACE ),
			'sitemap_xml'       => \Cybermaps\Core\URLManager::get_home_url( '/' . $base . '.xml' ),
		);

		return $this->private_response( $payload );
	}

	/**
	 * Get bounded, local publication state without making network requests.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_status() {
		$settings       = ConfigurationStore::settings();
		$static_mode    = \Cybermaps\Discovery\StaticBridge::get_mode( $settings );
		$stored_report  = get_option( 'cybermaps_last_static_sync_report', array() );
		$report         = self::array_value( $stored_report );
		$last_result    = self::status_key( $report['status'] ?? '' );
		$report_mode    = self::status_key( $report['mode'] ?? '' );
		$generation     = self::positive_number( get_option( 'cybermaps_static_generation', 0 ) );
		$report_current = self::report_is_current( $report, $generation, $static_mode, $report_mode );
		$publication    = self::publication_state( $static_mode, $last_result, $report_current );
		$sync_state     = $publication['sync_state'];
		$status         = $publication['status'];
		$counts         = self::sync_counts( $report );

		$last_success     = self::string_value( get_option( 'cybermaps_last_static_sync', '' ) );
		$last_attempt     = self::string_value( get_option( 'cybermaps_last_static_sync_attempt', '' ) );
		$report_succeeded = self::report_succeeded( $report['success'] ?? false );
		$base             = \Cybermaps\Sitemap\Orchestrator::get_sitemap_base();
		return $this->private_response(
			array(
				'version'            => CYBERMAPS_VERSION,
				'status'             => $status,
				'generated'          => gmdate( 'c' ),
				'sitemap'            => array(
					'index_url'     => \Cybermaps\Core\URLManager::get_home_url( '/' . $base . '.xml' ),
					'cache_enabled' => self::enabled_setting( $settings, 'enable_caching' ),
					'cache_store'   => self::cache_store_name(),
				),
				'discovery'          => array(
					'enabled' => self::enabled_setting( $settings, 'enable_discovery_hub' ),
				),
				'cache'              => \Cybermaps\Core\CacheManager::capability_profile(),
				'edge_invalidation'  => $this->edge_invalidation_status(),
				'static_publication' => array(
					'mode'         => $static_mode,
					'sync_state'   => $sync_state,
					'last_success' => $last_success,
					'last_attempt' => $last_attempt,
					'success'      => self::publication_succeeded( $static_mode, $report_current, $last_result, $report_succeeded ),
					'counts'       => $counts,
				),
			)
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function edge_invalidation_status(): array {
		$rows      = ( new \Cybermaps\Integration\EdgeCache\Coordinator() )->get_status();
		$sanitized = array();

		foreach ( array_slice( is_array( $rows ) ? $rows : array(), 0, 20 ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$sanitized[] = $this->sanitize_edge_status_row( $row );
		}

		return $sanitized;
	}

	/**
	 * @param mixed $adapters Stored adapter diagnostics.
	 * @return array<int,array<string,int|string|bool>>
	 */
	private function sanitize_edge_adapter_status( mixed $adapters ): array {
		$sanitized = array();
		foreach ( array_slice( is_array( $adapters ) ? $adapters : array(), 0, 4 ) as $adapter ) {
			$row = $this->sanitize_edge_adapter( $adapter );
			if ( null !== $row ) {
				$sanitized[] = $row;
			}
		}

		return $sanitized;
	}

	private function sanitize_edge_adapter( mixed $adapter ): ?array {
		if ( ! is_array( $adapter ) ) {
			return null;
		}
		$row = array();
		foreach ( array( 'adapter', 'status' ) as $key ) {
			if ( is_scalar( $adapter[ $key ] ?? null ) ) {
				$row[ $key ] = sanitize_key( (string) $adapter[ $key ] );
			}
		}
		if ( array_key_exists( 'supported', $adapter ) ) {
			$row['supported'] = ! empty( $adapter['supported'] );
		}
		foreach ( array( 'purged', 'code' ) as $key ) {
			if ( is_scalar( $adapter[ $key ] ?? null ) && is_numeric( $adapter[ $key ] ) ) {
				$row[ $key ] = max( 0, (int) $adapter[ $key ] );
			}
		}
		return $row;
	}

	private function is_public_cache_response( mixed $response, mixed $request ): bool {
		if ( ! $response instanceof \WP_REST_Response || ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
			return false;
		}
		$route = $request->get_route();
		$match = is_scalar( $route ) ? $this->endpoints->match_rest_path( (string) $route ) : null;
		$id    = null === $match ? '' : (string) ( $match['id'] ?? '' );
		return in_array( $id, array( 'rest_root', 'public_health', 'rest_mcp_server_card', 'rest_llms_tldr', 'rest_search' ), true ) && ( ! method_exists( $response, 'get_status' ) || 200 === (int) $response->get_status() );
	}

	private static function status_key( mixed $value ): string {
		return is_scalar( $value ) ? sanitize_key( (string) $value ) : '';
	}

	private static function array_value( mixed $value ): array {
		return is_array( $value ) ? $value : array();
	}

	private static function string_value( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	private static function cache_store_name(): string {
		return function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ? 'external-object-cache' : 'wordpress-transients';
	}

	private static function positive_number( mixed $value ): int {
		return is_scalar( $value ) && is_numeric( $value ) ? max( 0, (int) $value ) : 0;
	}

	private static function report_is_current( array $report, int $generation, string $mode, string $report_mode ): bool {
		return array_key_exists( 'generation', $report ) && self::positive_number( $report['generation'] ?? -1 ) === $generation && $mode === $report_mode;
	}

	/** @return array{sync_state:string,status:string} */
	private static function publication_state( string $mode, string $last_result, bool $current ): array {
		if ( 'off' === $mode ) {
			return array(
				'sync_state' => 'not_required',
				'status'     => 'operational',
			);
		}
		if ( '' === $last_result ) {
			return array(
				'sync_state' => 'not_run',
				'status'     => 'unverified',
			);
		}
		if ( ! $current ) {
			return array(
				'sync_state' => 'stale',
				'status'     => 'attention',
			);
		}
		return array(
			'sync_state' => $last_result,
			'status'     => match ( $last_result ) {
				'complete'        => 'operational',
				'partial', 'busy' => 'attention',
				'failed'          => 'degraded',
				default           => 'unverified',
			},
		);
	}

	private static function sync_counts( array $report ): array {
		$stored = isset( $report['counts'] ) && is_array( $report['counts'] ) ? $report['counts'] : array();
		$counts = array();
		foreach ( array( 'desired', 'written', 'unchanged', 'conflicted', 'failed', 'skipped', 'deleted', 'retained' ) as $key ) {
			$counts[ $key ] = self::positive_number( $stored[ $key ] ?? 0 );
		}
		return $counts;
	}

	private static function report_succeeded( mixed $value ): bool {
		return true === $value || ( is_scalar( $value ) && '1' === (string) $value );
	}

	private static function enabled_setting( array $settings, string $key ): bool {
		return is_scalar( $settings[ $key ] ?? null ) && '1' === (string) $settings[ $key ];
	}

	private static function publication_succeeded( string $mode, bool $current, string $result, bool $succeeded ): bool {
		return 'off' === $mode || ( $current && 'complete' === $result && $succeeded );
	}

	/** @return array<string,mixed> */
	private function sanitize_edge_status_row( array $row ): array {
		return array(
			'id'         => is_scalar( $row['id'] ?? null ) ? sanitize_text_field( (string) $row['id'] ) : '',
			'time'       => self::positive_number( $row['time'] ?? 0 ),
			'family'     => self::status_key( $row['family'] ?? '' ),
			'generation' => self::positive_number( $row['generation'] ?? 0 ),
			'tag_count'  => self::positive_number( $row['tag_count'] ?? 0 ),
			'url_count'  => self::positive_number( $row['url_count'] ?? 0 ),
			'status'     => self::status_key( $row['status'] ?? '' ),
			'adapters'   => $this->sanitize_edge_adapter_status( $row['adapters'] ?? array() ),
		);
	}

	/**
	 * Return the latest completed content report run.
	 */
	public function get_latest_audit() {
		try {
			$run = ( new \Cybermaps\Audit\AuditReadAPI() )->latest_json_run();
		} catch ( \OverflowException ) {
			return $this->audit_too_large_response();
		} catch ( \Throwable ) {
			return $this->audit_database_error_response();
		}
		if ( null === $run ) {
			return $this->private_error_response(
				'cybermaps_audit_not_found',
				__( 'No completed content report run exists.', 'cybermaps' ),
				404
			);
		}
		return $this->private_response( $run );
	}

	/**
	 * Return one saved content report run by query parameter.
	 *
	 * @param \WP_REST_Request $request REST request.
	 */
	public function get_audit_run( $request ) {
		$run_id = absint( $request->get_param( 'run_id' ) );
		try {
			$run = ( new \Cybermaps\Audit\AuditReadAPI() )->get_json_run( $run_id );
		} catch ( \OverflowException ) {
			return $this->audit_too_large_response();
		} catch ( \Throwable ) {
			return $this->audit_database_error_response();
		}
		if ( null === $run ) {
			return $this->private_error_response(
				'cybermaps_audit_not_found',
				__( 'The requested content report run was not found.', 'cybermaps' ),
				404
			);
		}
		return $this->private_response( $run );
	}

	/**
	 * Return a bounded error when a complete immutable snapshot cannot be
	 * represented safely in one synchronous JSON response.
	 */
	private function audit_too_large_response(): \WP_REST_Response {
		return $this->private_error_response(
			'cybermaps_audit_too_large',
			__( 'The saved content report is too large for a synchronous JSON response. Use the bounded CSV export from Reports.', 'cybermaps' ),
			413
		);
	}

	/**
	 * Convert storage failures into a private, non-cacheable REST error without
	 * exposing database details or misreporting the report as missing.
	 */
	private function audit_database_error_response(): \WP_REST_Response {
		return $this->private_error_response(
			'cybermaps_audit_database_error',
			__( 'The saved content report could not be loaded from the database.', 'cybermaps' ),
			500
		);
	}

	/**
	 * Return a private, non-cacheable REST response.
	 *
	 * @param mixed $data   Response payload.
	 * @param int   $status HTTP status.
	 */
	private function private_response( $data, int $status = 200 ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-cache, no-store, must-revalidate, private' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'Expires', '0' );
		return $response;
	}

	/**
	 * Return a private REST error using WordPress's standard error envelope.
	 */
	private function private_error_response( string $code, string $message, int $status ): \WP_REST_Response {
		return $this->private_response(
			array(
				'code'    => $code,
				'message' => $message,
				'data'    => array( 'status' => $status ),
			),
			$status
		);
	}
}
