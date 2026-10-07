<?php
/**
 * Opt-in Varnish PURGE transport.
 *
 * @package Cybermaps\Integration\EdgeCache
 */

declare(strict_types=1);

namespace Cybermaps\Integration\EdgeCache;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends exact-path PURGE requests only after a site owner explicitly opts in.
 *
 * There is deliberately no public WordPress PURGE endpoint. The matching VCL
 * must constrain client addresses and validate the configured secret itself.
 */
final class VarnishAdapter implements AdapterInterface {
	public const URL_CONSTANT   = 'CYBERMAPS_VARNISH_PURGE_URL';
	public const TOKEN_CONSTANT = 'CYBERMAPS_VARNISH_PURGE_TOKEN';
	private const MAX_URLS      = 50;

	/**
	 * Inspect configuration without sending a purge or asserting event-independent enablement.
	 * Filters receive a documented diagnostic context, never a fabricated invalidation.
	 *
	 * @return array{enabled_for_probe:bool,configured:bool,valid:bool}
	 */
	public function configuration_status(): array {
		$event    = array(
			'reason'     => 'configuration_status',
			'diagnostic' => true,
			'urls'       => array(),
		);
		$endpoint = $this->endpoint( $event );
		$token    = $this->token( $event );
		return array(
			'enabled_for_probe' => $this->is_enabled( $event ),
			'configured'        => '' !== $endpoint || '' !== $token,
			'valid'             => '' !== $endpoint && '' !== $token && $this->is_allowed_endpoint( $endpoint, $event ),
		);
	}

	/**
	 * @param array<string,mixed> $event Invalidation event.
	 * @return array<string,mixed>
	 */
	public function purge( array $event ): array {
		if ( ! $this->is_enabled( $event ) ) {
			return $this->result( 'disabled' );
		}
		$urls     = isset( $event['urls'] ) && is_array( $event['urls'] ) ? $event['urls'] : array();
		$targets  = Coordinator::normalize_purge_urls( $urls, $event );
		$coverage = $this->coverage( $event, $urls, $targets );
		if ( empty( $targets ) ) {
			return $this->delivery_summary( array(), $coverage );
		}

		$endpoint = $this->endpoint( $event );
		$token    = $this->token( $event );
		if ( '' === $endpoint || '' === $token || ! $this->is_allowed_endpoint( $endpoint, $event ) ) {
			return $this->result( 'invalid_configuration' );
		}
		if ( ! function_exists( 'wp_remote_request' ) ) {
			return $this->result( 'transport_unavailable' );
		}

		$results = array();
		foreach ( $targets as $url ) {
			$target = $this->target_for_public_url( $endpoint, $url );
			if ( '' === $target ) {
				$results[] = array(
					'url'    => $url,
					'status' => 'invalid_url',
				);
				continue;
			}

			$results[] = $this->purge_url( $target, $url, $token, $event );
		}

		return $this->delivery_summary( $results, $coverage );
	}

	/** @param array<string,mixed> $event @param string[] $urls @param string[] $targets */
	private function coverage( array $event, array $urls, array $targets ): array {
		$requested = max( count( $urls ), (int) filter_var( $event['requested_url_count'] ?? 0, FILTER_VALIDATE_INT ) );
		$truncated = max( 0, count( $urls ) - self::MAX_URLS, (int) filter_var( $event['truncated_url_count'] ?? 0, FILTER_VALIDATE_INT ) );
		$complete  = ! array_key_exists( 'url_scope_complete', $event ) || true === $event['url_scope_complete'];
		return array(
			'requested_url_count' => $requested,
			'truncated_url_count' => $truncated,
			'url_scope_complete'  => $complete && 0 === $truncated && count( $targets ) === $requested,
		);
	}

	/** @param array<int,array<string,mixed>> $results @param array<string,mixed> $coverage */
	private function delivery_summary( array $results, array $coverage ): array {
		$failed = count(
			array_filter(
				$results,
				static fn( array $result ): bool => ! is_int( $result['status'] ) || $result['status'] < 200 || $result['status'] >= 300
			)
		);

		return array_merge(
			$coverage,
			array(
				'adapter' => 'varnish',
				'status'  => $this->delivery_status( count( $results ), $failed, $coverage ),
				'count'   => count( $results ),
				'failed'  => $failed,
				'results' => array_slice( $results, 0, 20 ),
			)
		);
	}

	/** @param array<string,mixed> $coverage */
	private function delivery_status( int $count, int $failed, array $coverage ): string {
		if ( $count < 1 ) {
			return 'unsupported_scope';
		}
		if ( $failed > 0 ) {
			return 'partial';
		}
		return ! empty( $coverage['url_scope_complete'] ) ? 'sent' : 'incomplete_scope';
	}

	/** @return array<string,mixed> */
	private function result( string $status ): array {
		return array(
			'adapter' => 'varnish',
			'status'  => $status,
			'count'   => 0,
		);
	}

	/** @param array<string,mixed> $event @return array<string,mixed> */
	private function purge_url( string $target, string $url, string $token, array $event ): array {
		$response = wp_remote_request(
			$target,
			array(
				'method'      => 'PURGE',
				'timeout'     => 2,
				'redirection' => 0,
				'headers'     => array(
					'Host'                 => $this->public_host( $url ),
					'X-Cybermaps-Purge'    => $token,
					'X-Cybermaps-Purge-ID' => (string) ( $event['id'] ?? '' ),
				),
			)
		);
		return array(
			'url'    => $url,
			'status' => is_wp_error( $response ) ? 'transport_error' : (int) wp_remote_retrieve_response_code( $response ),
		);
	}

	/**
	 * Return a safe VCL advisory. It is never written or applied by Core.
	 */
	public static function advisory_vcl(): string {
		return implode(
			"\n",
			array(
				'# Cybermaps Varnish PURGE advisory. Install only after replacing the ACL and secret.',
				'acl cybermaps_purge_acl { "127.0.0.1"; }',
				'sub vcl_recv {',
				'  if (req.method == "PURGE") {',
				'    if (client.ip !~ cybermaps_purge_acl || req.http.X-Cybermaps-Purge != "REPLACE_WITH_SECRET") {',
				'      return (synth(405));',
				'    }',
				'    return (purge);',
				'  }',
				'}',
			)
		);
	}

	/**
	 * @param array<string,mixed> $event Invalidation event.
	 */
	private function is_enabled( array $event ): bool {
		return (bool) apply_filters( 'cybermaps_varnish_purge_enabled', false, $event );
	}

	/**
	 * @param array<string,mixed> $event Invalidation event.
	 */
	private function endpoint( array $event ): string {
		$default = defined( self::URL_CONSTANT ) ? (string) constant( self::URL_CONSTANT ) : '';
		$value   = apply_filters( 'cybermaps_varnish_purge_url', $default, $event );
		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * @param array<string,mixed> $event Invalidation event.
	 */
	private function token( array $event ): string {
		$default = defined( self::TOKEN_CONSTANT ) ? (string) constant( self::TOKEN_CONSTANT ) : '';
		$value   = apply_filters( 'cybermaps_varnish_purge_token', $default, $event );
		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * @param array<string,mixed> $event Invalidation event.
	 */
	private function is_allowed_endpoint( string $endpoint, array $event ): bool {
		$parts = wp_parse_url( $endpoint );
		if (
			! is_array( $parts )
			|| ! isset( $parts['scheme'], $parts['host'] )
			|| ! in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true )
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
			|| isset( $parts['query'] )
			|| isset( $parts['fragment'] )
		) {
			return false;
		}

		$host    = strtolower( (string) $parts['host'] );
		$origin  = wp_parse_url( home_url( '/' ) );
		$same    = is_array( $origin ) && isset( $origin['host'] ) && 0 === strcasecmp( $host, (string) $origin['host'] );
		$allowed = apply_filters( 'cybermaps_varnish_purge_hosts', array(), $event );
		$allowed = is_array( $allowed ) ? $allowed : array();
		$allowed = array_map( static fn( mixed $value ): string => strtolower( trim( is_scalar( $value ) ? (string) $value : '' ) ), $allowed );

		return $same || in_array( $host, $allowed, true );
	}

	private function target_for_public_url( string $endpoint, string $public_url ): string {
		$endpoint_parts = wp_parse_url( $endpoint );
		$public_parts   = wp_parse_url( $public_url );
		if ( ! is_array( $endpoint_parts ) || ! is_array( $public_parts ) ) {
			return '';
		}
		$public_path = isset( $public_parts['path'] ) ? (string) $public_parts['path'] : '/';
		if ( '' === $public_path || ! str_starts_with( $public_path, '/' ) ) {
			return '';
		}

		$target = (string) $endpoint_parts['scheme'] . '://' . (string) $endpoint_parts['host'];
		if ( isset( $endpoint_parts['port'] ) ) {
			$target .= ':' . (int) $endpoint_parts['port'];
		}
		$target .= $public_path;
		if ( isset( $public_parts['query'] ) ) {
			$target .= '?' . (string) $public_parts['query'];
		}
		return $target;
	}

	private function public_host( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		return (string) $parts['host'] . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
	}
}
