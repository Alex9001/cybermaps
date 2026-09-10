<?php
/**
 * REST response compatibility and cache-safety guard.
 *
 * @package Cybermaps\Integration
 */

declare(strict_types=1);

namespace Cybermaps\Integration;

use Cybermaps\Admin\CrawlerRequestClassifier;
use Cybermaps\Core\EndpointRegistry;
use Cybermaps\Core\URLManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps Cybermaps machine responses away from HTML optimizers and prevents
 * private Cybermaps REST responses from being cached by page-cache integrations.
 */
final class RestResponseGuard {

	/**
	 * REST endpoints whose responses require authentication and must never be
	 * stored in a shared cache.
	 *
	 * @var string[]
	 */
	private const PRIVATE_ENDPOINTS = array(
		'rest_urls',
		'rest_status',
		'rest_audit_latest',
		'rest_audit_run',
		'rest_purge',
	);

	private bool $accept_header_adjusted = false;

	private bool $had_accept_header = false;

	private string $original_accept_header = '';

	private readonly CrawlerRequestClassifier $classifier;

	public function __construct(
		private readonly EndpointRegistry $registry
	) {
		$this->classifier = new CrawlerRequestClassifier( $registry );
	}

	/**
	 * Register early compatibility hooks.
	 */
	public function register_hooks(): void {
		add_filter( 'mai_performance_enhancer_settings', array( $this, 'filter_mai_settings' ) );
		add_filter( 'rest_post_dispatch', array( $this, 'enforce_private_headers' ), PHP_INT_MAX, 3 );

		$this->prepare_current_request();
	}

	/**
	 * Mark a Cybermaps REST request as JSON before themes initialize.
	 *
	 * Some HTML output optimizers decide whether to buffer a request during
	 * after_setup_theme, before WordPress has dispatched the REST route. A REST
	 * client is not required to send an application/json Accept header, so give
	 * those integrations an accurate temporary signal and restore the original
	 * request header once theme setup is complete.
	 */
	public function prepare_current_request(): void {
		$endpoint_id = $this->current_endpoint_id();
		if ( ! $this->is_current_machine_request() ) {
			return;
		}

		if ( in_array( $endpoint_id, self::PRIVATE_ENDPOINTS, true ) ) {
			$this->mark_private_request();
		}

		if ( $this->current_request_is_json() ) {
			return;
		}

		$this->had_accept_header      = isset( $_SERVER['HTTP_ACCEPT'] )
			&& is_scalar( $_SERVER['HTTP_ACCEPT'] );
		$this->original_accept_header = $this->had_accept_header
			? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_ACCEPT'] ) )
			: '';
		$_SERVER['HTTP_ACCEPT']       = '' === trim( $this->original_accept_header )
			? 'application/json'
			: $this->original_accept_header . ', application/json';
		$this->accept_header_adjusted = true;

		add_action( 'after_setup_theme', array( $this, 'restore_accept_header' ), PHP_INT_MAX );
	}

	/**
	 * Restore the actual client-supplied Accept header.
	 */
	public function restore_accept_header(): void {
		if ( ! $this->accept_header_adjusted ) {
			return;
		}

		if ( $this->had_accept_header ) {
			$_SERVER['HTTP_ACCEPT'] = $this->original_accept_header;
		} else {
			unset( $_SERVER['HTTP_ACCEPT'] );
		}

		$this->accept_header_adjusted = false;
	}

	/**
	 * Defensive compatibility with Mai Performance Enhancer versions that do
	 * not skip machine responses before constructing their output buffer or
	 * respect the endpoint's own protocol cache policy.
	 *
	 * @param mixed $settings Mai settings.
	 * @return mixed
	 */
	public function filter_mai_settings( $settings ) {
		if (
			! is_array( $settings )
			|| ! $this->is_current_machine_request()
		) {
			return $settings;
		}

		$settings['cache_headers'] = false;
		return $settings;
	}

	/**
	 * Reassert non-cacheable headers after all REST callbacks have run.
	 *
	 * This also covers permission errors, which must not be cached as the
	 * response for a later authenticated request.
	 *
	 * @param mixed $response REST response.
	 * @param mixed $server   REST server.
	 * @param mixed $request  REST request.
	 * @return mixed
	 */
	public function enforce_private_headers( $response, $server, $request ) {
		unset( $server );

		if (
			! is_object( $request )
			|| ! method_exists( $request, 'get_route' )
			|| ! is_object( $response )
			|| ! method_exists( $response, 'header' )
		) {
			return $response;
		}
		$raw_route = $request->get_route();
		if ( ! is_scalar( $raw_route ) || ! $this->is_private_rest_path( (string) $raw_route ) ) {
			return $response;
		}

		$response->header( 'Cache-Control', 'no-cache, no-store, must-revalidate, private' );
		$response->header( 'Pragma', 'no-cache' );
		$response->header( 'Expires', '0' );

		return $response;
	}

	/**
	 * Resolve a request URI or rest_route query to a registered endpoint ID.
	 */
	public function endpoint_id_from_request( string $request_uri, string $rest_route = '' ): string {
		$rest_route = '/' . ltrim( rawurldecode( trim( $rest_route ) ), '/' );
		if ( '/' !== $rest_route ) {
			$matched = $this->registry->match_rest_path( $rest_route );
			return null === $matched ? '' : (string) $matched['id'];
		}

		$path = wp_parse_url( $request_uri, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			return '';
		}

		$rest_prefix = function_exists( 'rest_get_url_prefix' )
			? rest_get_url_prefix()
			: 'wp-json';
		$prefix      = '/' . trim( $rest_prefix, '/' ) . '/';
		$offset      = strpos( rawurldecode( $path ), $prefix );
		if ( false === $offset ) {
			return '';
		}

		$route   = '/' . ltrim( substr( rawurldecode( $path ), $offset + strlen( $prefix ) ), '/' );
		$matched = $this->registry->match_rest_path( $route );
		return null === $matched ? '' : (string) $matched['id'];
	}

	/**
	 * Determine whether a WordPress REST path maps to a private endpoint.
	 */
	public function is_private_rest_path( string $rest_path ): bool {
		$matched = $this->registry->match_rest_path( $rest_path );
		return null !== $matched
			&& in_array( (string) $matched['id'], self::PRIVATE_ENDPOINTS, true );
	}

	/**
	 * Determine whether the current request targets any Cybermaps machine
	 * publication, sitemap, robots, IndexNow-key, chunk, or REST route.
	 */
	public function is_current_machine_request(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only routing inspection.
		$rest_route = isset( $_GET['rest_route'] ) && is_string( $_GET['rest_route'] )
			? sanitize_text_field( wp_unslash( $_GET['rest_route'] ) )
			: '';
		// phpcs:enable

		if ( '' !== $this->current_endpoint_id() ) {
			return true;
		}

		$result = $this->classifier->classify(
			(string) URLManager::get_request_path(),
			$rest_route
		);
		return 'endpoint' === (string) ( $result['request_kind'] ?? '' );
	}

	/**
	 * Resolve the current request.
	 */
	private function current_endpoint_id(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only routing inspection.
		$rest_route = isset( $_GET['rest_route'] ) && is_string( $_GET['rest_route'] )
			? sanitize_text_field( wp_unslash( $_GET['rest_route'] ) )
			: '';
		// phpcs:enable
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) && is_scalar( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) )
			: '';

		return $this->endpoint_id_from_request( $request_uri, $rest_route );
	}

	/**
	 * Ask WordPress about JSON negotiation only for valid scalar headers.
	 *
	 * Core's wp_is_json_request() passes these server values to a string-typed
	 * media-type parser. Malformed array input must not be allowed to turn a
	 * public machine request into a PHP TypeError.
	 */
	private function current_request_is_json(): bool {
		foreach ( array( 'HTTP_ACCEPT', 'CONTENT_TYPE' ) as $header ) {
			if ( isset( $_SERVER[ $header ] ) && ! is_scalar( $_SERVER[ $header ] ) ) {
				return false;
			}
		}

		return wp_is_json_request();
	}

	/**
	 * Set the conventional page-cache bypass constant as early as possible.
	 */
	private function mark_private_request(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- This is the conventional page-cache bypass constant consumed by third-party cache plugins.
		}
	}
}
