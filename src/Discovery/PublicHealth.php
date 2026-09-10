<?php
/**
 * Bounded public discovery health response.
 *
 * @package Cybermaps\Discovery
 */

declare(strict_types=1);

namespace Cybermaps\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes only a coarse service state and already-public release metadata.
 */
final class PublicHealth {
	public const MEDIA_TYPE = 'application/health+json';
	public const REST_ROUTE = '/health';

	/**
	 * Serve the public health representation.
	 */
	public function handle(): void {
		$path = (string) \Cybermaps\Core\URLManager::get_request_path();
		if ( ! self::matches_path( $path ) || ! Integrity::is_hub_enabled() ) {
			return;
		}

		$output = \wp_json_encode( $this->get_health_data(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$output = \is_string( $output ) ? $output : '{}';

		Integrity::send_headers( $output, MINUTE_IN_SECONDS );
		\header( 'Content-Type: ' . self::MEDIA_TYPE );
		\header( 'Access-Control-Allow-Origin: *' );
		if ( ! \Cybermaps\Core\ReadOnlyRequest::is_head() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Deliberate protocol JSON response.
			echo $output;
		}
		exit;
	}

	/**
	 * Return a deliberately small payload with no component or environment data.
	 *
	 * @return array{status:string,serviceId:string,version:string}
	 */
	public function get_health_data(): array {
		return array(
			'status'    => 'pass',
			'serviceId' => 'cybermaps-discovery',
			'version'   => CYBERMAPS_VERSION,
		);
	}

	/**
	 * Resolve the registered URL, with a route-derived fallback for integration.
	 */
	public static function get_url(): string {
		$endpoints  = \Cybermaps\Core\EndpointRegistry::get_instance();
		$registered = $endpoints->get_url( 'public_health' );
		if ( '' !== $registered ) {
			return $registered;
		}

		$rest_root = $endpoints->get_url( 'rest_root' );
		return \str_ends_with( $rest_root, '/discovery' )
			? \substr( $rest_root, 0, -\strlen( '/discovery' ) ) . self::REST_ROUTE
			: '';
	}

	/**
	 * Match the canonical REST route path.
	 */
	private static function matches_path( string $request_path ): bool {
		$path = \wp_parse_url( self::get_url(), PHP_URL_PATH );

		return \is_string( $path ) && '' !== $path && $path === $request_path;
	}
}
