<?php
declare(strict_types=1);

namespace Cybermaps\Admin;

use Cybermaps\Core\AtomicMinuteCounter;
use Cybermaps\Core\CacheManager;
use Cybermaps\Core\ClientIPResolver;
use Cybermaps\Core\EndpointRegistry;
use Cybermaps\Core\URLManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Records PHP-observed endpoints and crawler-identity evidence.
 *
 * Static web-server and CDN requests never execute this class and are
 * intentionally outside the reported coverage.
 */
final class CrawlerAnalyticsRecorder {

	public const HEALTH_ERROR_OPTION              = 'cybermaps_analytics_db_error';
	private const CACHE_INVALIDATION_GUARD        = 'cybermaps_logs_dirty';
	private const ENDPOINT_OBSERVATIONS_CACHE_KEY = 'cybermaps_endpoint_observations_v1';
	private const REQUESTER_ROWS_PER_MINUTE_MAX   = 120;

	private bool $recorded            = false;
	private bool $shutdown_registered = false;
	private CrawlerRequestClassifier $classifier;

	public function __construct( ?EndpointRegistry $registry = null ) {
		$this->classifier = new CrawlerRequestClassifier(
			$registry ?? EndpointRegistry::get_instance()
		);
	}

	/**
	 * Register final-status recording before a template handler can call exit.
	 */
	public function begin_frontend_observation(): void {
		if ( $this->shutdown_registered ) {
			return;
		}

		$this->shutdown_registered = true;
		\register_shutdown_function( array( $this, 'record_frontend_response' ) );
	}

	/**
	 * Record the final HTTP status after the frontend handler has completed.
	 */
	public function record_frontend_response(): void {
		$status = \http_response_code();
		$this->record_current_request( '', \is_int( $status ) ? $status : 200 );
	}

	/**
	 * Observe a completed REST response without modifying it.
	 *
	 * @param mixed $response REST response or error.
	 * @param mixed $server   REST server.
	 * @param mixed $request  REST request.
	 * @return mixed Unmodified response.
	 */
	public function observe_rest_response( $response, $server, $request ) {
		unset( $server );

		$raw_route = \is_object( $request ) && \method_exists( $request, 'get_route' )
			? $request->get_route()
			: '';
		$route     = \is_scalar( $raw_route ) ? (string) $raw_route : '';
		if ( ! $this->classifier->is_cybermaps_rest_route( $route ) ) {
			return $response;
		}

		$raw_status = \is_object( $response ) && \method_exists( $response, 'get_status' )
			? $response->get_status()
			: 200;
		$status     = \is_scalar( $raw_status ) && \is_numeric( $raw_status )
			? (int) $raw_status
			: 200;
		$this->record_current_request( $route, $status );

		return $response;
	}

	/**
	 * Record the current request at most once.
	 */
	public function record_current_request( string $rest_route = '', int $status = 200 ): bool {
		$settings = \Cybermaps\Core\ConfigurationStore::settings();

		if ( $this->recorded || ! $this->is_recording_enabled( $settings ) || $this->is_diagnostic_request() ) {
			return false;
		}

		$path           = URLManager::get_request_path();
		$accept         = isset( $_SERVER['HTTP_ACCEPT'] ) && is_scalar( $_SERVER['HTTP_ACCEPT'] )
			? (string) wp_unslash( (string) $_SERVER['HTTP_ACCEPT'] )
			: '';
		$classification = $this->classifier->classify( $path, $rest_route, $accept );
		$user_agent     = isset( $_SERVER['HTTP_USER_AGENT'] )
			&& \is_scalar( $_SERVER['HTTP_USER_AGENT'] )
			? \substr( \sanitize_text_field( \wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ), 0, 512 )
			: '';
		$logged_in      = \function_exists( 'is_user_logged_in' ) && \is_user_logged_in();
		$identity       = RequestIdentityClassifier::classify( $user_agent, $logged_in );

		// Keep normal browser and ambiguous client activity out of page analytics.
		// Explicit UA matches and conservative unregistered-bot candidates remain
		// useful on content, while every otherwise-eligible registered endpoint
		// observation is kept. Diagnostic requests were excluded above.
		if (
			'endpoint' !== $classification['request_kind']
			&& ! RequestIdentityClassifier::should_record_page( $identity )
		) {
			return false;
		}

		$status  = self::normalize_status( $status );
		$context = self::storage_context( $settings, $logged_in, $user_agent );
		if ( ! $logged_in && $this->exceeds_recording_backpressure( $context['requester_key'], $classification, $path ) ) {
			return false;
		}

		global $wpdb;
		$table_name = $wpdb->prefix . 'cybermaps_logs';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching
		$result = $wpdb->insert(
			$table_name,
			array(
				'time'                => \current_time( 'mysql' ),
				'bot'                 => $identity['bot'],
				'category'            => $identity['category'],
				'url'                 => \substr( $path, 0, 255 ),
				'request_kind'        => $classification['request_kind'],
				'endpoint_id'         => $classification['endpoint_id'],
				'response_status'     => $status,
				'delivery'            => 'php',
				'recognized'          => $identity['recognized'],
				'ip_address'          => $context['stored_ip'],
				'user_agent'          => $context['stored_user_agent'],
				'crawler_id'          => $identity['crawler_id'],
				'identity_status'     => $identity['identity_status'],
				'verification_method' => $identity['verification_method'],
				'client_type'         => $identity['client_type'],
				'requester_key'       => $context['requester_key'],
				'wp_user_id'          => $context['wp_user_id'],
				'request_method'      => $context['request_method'],
				'accept_type'         => $context['accept_type'],
				'ip_source'           => $context['ip_source'],
				'ip_storage'          => $context['ip_storage'],
			)
		);
		// phpcs:enable

		if ( false === $result ) {
			self::persist_health_error();
			return false;
		}

		self::clear_health_error();
		self::invalidate_analytics_cache();
		$this->recorded = true;
		return true;
	}

	/**
	 * Clamp persisted response status to the HTTP status range.
	 */
	private static function normalize_status( int $status ): int {
		return $status >= 100 && $status <= 599 ? $status : 200;
	}

	/**
	 * Build privacy-sensitive storage fields without retaining extra IP copies.
	 *
	 * @param array<string,mixed> $settings General settings.
	 * @return array{resolved_ip:string,stored_ip:string,ip_storage:string,ip_source:string,wp_user_id:int,request_method:string,accept_type:string,requester_key:string,stored_user_agent:string}
	 */
	private static function storage_context( array $settings, bool $logged_in, string $user_agent ): array {
		$ip_context  = self::ip_storage_context( $settings, $logged_in );
		$resolved_ip = $ip_context['resolved_ip'];

		return $ip_context + array(
			'wp_user_id'        => $logged_in && \function_exists( 'get_current_user_id' ) ? (int) \get_current_user_id() : 0,
			'request_method'    => self::get_request_method(),
			'accept_type'       => self::get_accept_type(),
			'requester_key'     => '' !== $resolved_ip ? self::get_requester_key( $resolved_ip ) : '',
			'stored_user_agent' => $logged_in ? '' : $user_agent,
		);
	}

	/**
	 * Resolve and normalize IP persistence according to the privacy setting.
	 *
	 * @param array<string,mixed> $settings General settings.
	 * @return array{resolved_ip:string,stored_ip:string,ip_storage:string,ip_source:string}
	 */
	private static function ip_storage_context( array $settings, bool $logged_in ): array {
		$resolution    = ClientIPResolver::resolve();
		$resolved_ip   = $logged_in ? '' : (string) $resolution['ip'];
		$anonymize_ips = ! isset( $settings['anonymize_analytics_ips'] ) || ! empty( $settings['anonymize_analytics_ips'] );
		$stored_ip     = '';
		$ip_storage    = 'none';
		if ( '' !== $resolved_ip ) {
			$stored_ip  = $anonymize_ips ? self::anonymize_ip( $resolved_ip ) : $resolved_ip;
			$ip_storage = $anonymize_ips ? 'anonymized' : 'full';
		}

		return array(
			'resolved_ip' => $resolved_ip,
			'stored_ip'   => $stored_ip,
			'ip_storage'  => $ip_storage,
			'ip_source'   => '' !== $resolved_ip ? (string) $resolution['source'] : 'none',
		);
	}

	/**
	 * Bound one anonymous requester only when an atomic external cache is present.
	 *
	 * @param array{request_kind:string,endpoint_id:string} $classification Request classification.
	 */
	private function exceeds_recording_backpressure( string $requester_key, array $classification, string $path ): bool {
		$scope    = 'endpoint' === (string) $classification['request_kind']
			? (string) $classification['endpoint_id']
			: \substr( \md5( $path ), 0, 12 );
		$material = '' !== $requester_key
			? $requester_key
			: AtomicMinuteCounter::UNRESOLVED_CLIENT_BUCKET;
		$key      = AtomicMinuteCounter::requester_bucket( 'cm_log_pressure', $scope, $material );
		$count    = AtomicMinuteCounter::increment( $key );

		return $count > self::REQUESTER_ROWS_PER_MINUTE_MAX;
	}

	/**
	 * Coalesce append-only cache invalidation to one pass per minute.
	 *
	 * Analytics read models already expire after one to five minutes. Clearing
	 * every aggregate transient for every observed request defeats those caches
	 * on active sites and adds avoidable option/cache churn to the hot path.
	 */
	private static function invalidate_analytics_cache(): void {
		if ( ! CacheManager::claim( self::CACHE_INVALIDATION_GUARD, MINUTE_IN_SECONDS, 'analytics_guard' ) ) {
			return;
		}

		CacheManager::clear_family( 'analytics' );
	}

	/**
	 * Return a concise persisted database-health message, if present.
	 */
	public static function get_health_error(): string {
		$error = \get_option( self::HEALTH_ERROR_OPTION, array() );
		return \is_array( $error ) && isset( $error['message'] )
			? (string) $error['message']
			: '';
	}

	/**
	 * Persist a safe diagnostic without exposing SQL or database internals.
	 */
	public static function persist_health_error(): void {
		\update_option(
			self::HEALTH_ERROR_OPTION,
			array(
				'message' => \__( 'Crawler analytics could not read from or write to its database table. Run the Cybermaps database upgrade and check the site database logs.', 'cybermaps' ),
				'time'    => \time(),
			),
			false
		);
	}

	/**
	 * Clear a recovered database-health error.
	 */
	public static function clear_health_error(): void {
		if ( false !== \get_option( self::HEALTH_ERROR_OPTION, false ) ) {
			\delete_option( self::HEALTH_ERROR_OPTION );
		}
	}

	/**
	 * Return per-endpoint PHP observation coverage for status screens.
	 *
	 * The legacy `recognized_*` keys remain part of this internal return shape
	 * for status-screen compatibility. They mean a crawler-registry User-Agent
	 * signature matched; they do not mean the provider was verified.
	 *
	 * @return array<string,array{php_requests:int,recognized_requests:int,last_php:string,last_recognized:string}>
	 */
	public static function get_endpoint_observations(): array {
		$generation = CacheManager::get_generation( 'analytics' );
		$cached     = CacheManager::get( self::ENDPOINT_OBSERVATIONS_CACHE_KEY, 'analytics', $cache_found );
		if ( $cache_found && \is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) {
			return array();
		}
		$table = $wpdb->prefix . 'cybermaps_logs';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.NoCaching -- Analytics requires an exact aggregate over the plugin-owned event table; the result is cached immediately below.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT endpoint_id,
				COUNT(*) AS php_requests,
				SUM(recognized = 1) AS recognized_requests,
				MAX(time) AS last_php,
				MAX(CASE WHEN recognized = 1 THEN time ELSE NULL END) AS last_recognized
			FROM %i
			WHERE request_kind = 'endpoint'
			GROUP BY endpoint_id",
				$table
			),
			ARRAY_A
		);
		// phpcs:enable

		$results = array();
		foreach ( (array) $rows as $row ) {
			$id = sanitize_key( (string) ( $row['endpoint_id'] ?? '' ) );
			if ( '' === $id ) {
				continue;
			}
			$results[ $id ] = array(
				'php_requests'        => (int) ( $row['php_requests'] ?? 0 ),
				'recognized_requests' => (int) ( $row['recognized_requests'] ?? 0 ),
				'last_php'            => (string) ( $row['last_php'] ?? '' ),
				'last_recognized'     => (string) ( $row['last_recognized'] ?? '' ),
			);
		}

		CacheManager::set_compatible_if_current(
			self::ENDPOINT_OBSERVATIONS_CACHE_KEY,
			$results,
			MINUTE_IN_SECONDS,
			'analytics',
			$generation
		);
		return $results;
	}

	/**
	 * Reduce an IP address to a network identifier before local storage.
	 */
	public static function anonymize_ip( string $ip_address ): string {
		$ip_address = \trim( $ip_address );
		if ( false !== \filter_var( $ip_address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$octets    = \explode( '.', $ip_address );
			$octets[3] = '0';
			return \implode( '.', $octets );
		}

		if ( false !== \filter_var( $ip_address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$binary = \inet_pton( $ip_address );
			if ( false === $binary ) {
				return '';
			}

			$network = \substr( $binary, 0, 8 ) . \str_repeat( "\0", 8 );
			$result  = \inet_ntop( $network );
			return false === $result ? '' : $result;
		}

		return '';
	}

	/**
	 * Build a site-specific pseudonymous key without retaining another IP copy.
	 */
	public static function get_requester_key( string $ip_address ): string {
		$ip_address = \trim( $ip_address );
		if ( false === \filter_var( $ip_address, FILTER_VALIDATE_IP ) ) {
			return '';
		}

		return \substr( \wp_hash( $ip_address, 'auth', 'sha256' ), 0, 16 );
	}

	/**
	 * Normalize the current HTTP method to a bounded token.
	 */
	private static function get_request_method(): string {
		$method = isset( $_SERVER['REQUEST_METHOD'] )
			&& \is_scalar( $_SERVER['REQUEST_METHOD'] )
			? \strtoupper( \sanitize_text_field( \wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) )
			: '';

		return 1 === \preg_match( '/^[A-Z]{1,10}$/', $method ) ? $method : '';
	}

	/**
	 * Reduce the Accept header to the media family useful for diagnostics.
	 */
	private static function get_accept_type(): string {
		$accept = isset( $_SERVER['HTTP_ACCEPT'] )
			&& \is_scalar( $_SERVER['HTTP_ACCEPT'] )
			? \strtolower( \sanitize_text_field( \wp_unslash( (string) $_SERVER['HTTP_ACCEPT'] ) ) )
			: '';

		if ( '' === $accept ) {
			return 'none';
		}
		if ( \str_contains( $accept, 'text/markdown' ) ) {
			return 'markdown';
		}
		if ( \str_contains( $accept, 'application/json' ) || \str_contains( $accept, '+json' ) ) {
			return 'json';
		}
		if (
			\str_contains( $accept, 'application/xml' )
			|| \str_contains( $accept, 'text/xml' )
			|| \str_contains( $accept, '+xml' )
		) {
			return 'xml';
		}
		if ( \str_contains( $accept, 'text/html' ) ) {
			return 'html';
		}
		if ( \str_contains( $accept, 'text/plain' ) ) {
			return 'text';
		}
		if ( \str_contains( $accept, '*/*' ) ) {
			return 'any';
		}

		return 'other';
	}

	/**
	 * Apply the documented analytics exclusions.
	 */
	private function is_recording_enabled( array $settings ): bool {
		if ( empty( $settings['enable_analytics'] ) ) {
			return false;
		}

		if ( \function_exists( 'is_admin' ) && \is_admin() ) {
			return false;
		}

		return true;
	}

	private function is_diagnostic_request(): bool {
		if (
			! isset( $_SERVER['HTTP_X_CYBERMAPS_DIAGNOSTIC'] )
			|| ! \is_scalar( $_SERVER['HTTP_X_CYBERMAPS_DIAGNOSTIC'] )
		) {
			return false;
		}

		return '1' === \sanitize_text_field(
			\wp_unslash( (string) $_SERVER['HTTP_X_CYBERMAPS_DIAGNOSTIC'] )
		);
	}
}
