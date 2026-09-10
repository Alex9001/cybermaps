<?php
/**
 * Narrow Cloudflare Rulesets API client.
 *
 * @package Cybermaps\Admin
 */

declare(strict_types=1);

namespace Cybermaps\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Uses an ephemeral bearer credential without persisting it. */
final class CloudflareRulesClient {
	private const API_BASE = 'https://api.cloudflare.com/client/v4';

	public function __construct( #[\SensitiveParameter] private string $token ) {}

	/** @return array{id:string,name:string} */
	public function zone_for_host( string $host ): array {
		$zones = array();
		$page  = 1;
		do {
			$response = $this->request(
				'GET',
				'/zones',
				null,
				array(
					'page'     => $page,
					'per_page' => 50,
					'status'   => 'active',
				)
			);
			$zones    = array_merge( $zones, is_array( $response['result'] ?? null ) ? $response['result'] : array() );
			$pages    = max( 1, (int) ( $response['result_info']['total_pages'] ?? 1 ) );
			++$page;
		} while ( $page <= $pages && $page <= 20 );

		return self::select_zone( $host, $zones );
	}

	/** @param array<int,mixed> $zones @return array{id:string,name:string} */
	public static function select_zone( string $host, array $zones ): array {
		$host       = strtolower( trim( $host, ". \t\n\r\0\x0B" ) );
		$candidates = array();
		foreach ( $zones as $zone ) {
			if ( ! is_array( $zone ) ) {
				continue;
			}
			$name = strtolower( trim( (string) ( $zone['name'] ?? '' ), '.' ) );
			$id   = sanitize_text_field( (string) ( $zone['id'] ?? '' ) );
			if ( '' === $id || '' === $name || ( $host !== $name && ! str_ends_with( $host, '.' . $name ) ) ) {
				continue;
			}
			$candidates[] = array(
				'id'   => $id,
				'name' => $name,
			);
		}
		if ( array() === $candidates ) {
			throw new \RuntimeException( esc_html__( 'The Cloudflare authorization cannot access an active zone matching the public hostname.', 'cybermaps' ) );
		}
		usort( $candidates, static fn( array $left, array $right ): int => strlen( $right['name'] ) <=> strlen( $left['name'] ) );
		if ( isset( $candidates[1] ) && strlen( $candidates[0]['name'] ) === strlen( $candidates[1]['name'] ) ) {
			throw new \RuntimeException( esc_html__( 'More than one authorized Cloudflare zone matches this hostname. Authorize only the intended account and try again.', 'cybermaps' ) );
		}
		return $candidates[0];
	}

	/** @return array<string,mixed>|null */
	public function phase_ruleset( string $zone_id, string $phase ): ?array {
		$response = $this->request( 'GET', '/zones/' . rawurlencode( $zone_id ) . '/rulesets/phases/' . rawurlencode( $phase ) . '/entrypoint', null, array(), true );
		return ! empty( $response['not_found'] ) ? null : (array) ( $response['result'] ?? array() );
	}

	/** @param array<int,array<string,mixed>> $rules @return array<string,mixed> */
	public function create_phase_ruleset( string $zone_id, string $phase, string $name, array $rules ): array {
		$response = $this->request(
			'POST',
			'/zones/' . rawurlencode( $zone_id ) . '/rulesets',
			array(
				'name'        => $name,
				'description' => 'Cybermaps managed rules. Remove through Cybermaps or by matching the cybermaps_* reference.',
				'kind'        => 'zone',
				'phase'       => $phase,
				'rules'       => array_values( $rules ),
			)
		);
		return (array) ( $response['result'] ?? array() );
	}

	/** @param array<string,mixed> $rule @return array<string,mixed> */
	public function create_rule( string $zone_id, string $ruleset_id, array $rule ): array {
		$response = $this->request( 'POST', self::rules_path( $zone_id, $ruleset_id ), $rule );
		return (array) ( $response['result'] ?? array() );
	}

	/** @param array<string,mixed> $rule @return array<string,mixed> */
	public function update_rule( string $zone_id, string $ruleset_id, string $rule_id, array $rule ): array {
		$response = $this->request( 'PATCH', self::rules_path( $zone_id, $ruleset_id ) . '/' . rawurlencode( $rule_id ), $rule );
		return (array) ( $response['result'] ?? array() );
	}

	public function delete_rule( string $zone_id, string $ruleset_id, string $rule_id ): void {
		$this->request( 'DELETE', self::rules_path( $zone_id, $ruleset_id ) . '/' . rawurlencode( $rule_id ) );
	}

	private static function rules_path( string $zone_id, string $ruleset_id ): string {
		return '/zones/' . rawurlencode( $zone_id ) . '/rulesets/' . rawurlencode( $ruleset_id ) . '/rules';
	}

	/** @param array<string,mixed>|null $body @param array<string,mixed> $query @return array<string,mixed> */
	private function request( string $method, string $path, ?array $body = null, array $query = array(), bool $allow_not_found = false ): array {
		$url = self::API_BASE . $path;
		if ( array() !== $query ) {
			$url = add_query_arg( $query, $url );
		}
		$args = array(
			'method'              => $method,
			'timeout'             => 12,
			'redirection'         => 0,
			'reject_unsafe_urls'  => true,
			'limit_response_size' => 1048576,
			'headers'             => array(
				'Authorization' => 'Bearer ' . $this->token,
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
			),
		);
		if ( null !== $body ) {
			$encoded = wp_json_encode( $body, JSON_UNESCAPED_SLASHES );
			if ( ! is_string( $encoded ) ) {
				throw new \RuntimeException( esc_html__( 'Cybermaps could not encode the Cloudflare rule request.', 'cybermaps' ) );
			}
			$args['body'] = $encoded;
		}

		$response = wp_safe_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( esc_html__( 'Cloudflare could not be reached securely. Check outbound HTTPS connectivity and try again.', 'cybermaps' ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $allow_not_found && 404 === $code ) {
			return array( 'not_found' => true );
		}
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $decoded ) || empty( $decoded['success'] ) ) {
			throw new \RuntimeException( esc_html( self::api_error( is_array( $decoded ) ? $decoded : array(), $code ) ) );
		}
		return $decoded;
	}

	/** @param array<string,mixed> $decoded */
	private static function api_error( array $decoded, int $code ): string {
		$messages = array();
		foreach ( array_slice( (array) ( $decoded['errors'] ?? array() ), 0, 3 ) as $error ) {
			if ( is_array( $error ) && is_scalar( $error['message'] ?? null ) ) {
				$messages[] = sanitize_text_field( (string) $error['message'] );
			}
		}
		$detail = array() !== $messages ? implode( '; ', $messages ) : esc_html__( 'Cloudflare rejected the request.', 'cybermaps' );
		return sprintf( /* translators: 1: HTTP status code, 2: provider error. */ esc_html__( 'Cloudflare API error %1$d: %2$s', 'cybermaps' ), absint( $code ), esc_html( substr( $detail, 0, 400 ) ) );
	}
}
