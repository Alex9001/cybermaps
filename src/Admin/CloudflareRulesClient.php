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

	public function __construct( #[\SensitiveParameter] private string $token, private ?\Closure $transport = null ) {}

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
			$pages    = self::complete_zone_page_count( $response, $page, $pages ?? null );
			$zones    = array_merge( $zones, $response['result'] );
			++$page;
		} while ( $page <= $pages );

		return self::select_zone( $host, $zones );
	}

	/** Refuse a partial inventory before any zone can become a mutation target.
	 *
	 * @param array<string,mixed> $response Provider zone-list response.
	 */
	private static function complete_zone_page_count( array $response, int $page, ?int $expected ): int {
		$pages = $response['result_info']['total_pages'] ?? null;
		if ( ! is_int( $pages ) || $pages < 1 || $pages > 20
			|| ( $response['result_info']['page'] ?? null ) !== $page
			|| ( null !== $expected && $expected !== $pages )
			|| ! is_array( $response['result'] ?? null )
		) {
			throw new \RuntimeException( esc_html__( 'The authorized Cloudflare zone inventory is incomplete or exceeds the safe lookup limit. Authorize only the intended zone and try again.', 'cybermaps' ) );
		}
		return $pages;
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
		$result   = $response['result'] ?? null;
		if ( ! is_array( $result ) || ! is_string( $result['id'] ?? null ) || '' === $result['id']
			|| ( $result['phase'] ?? null ) !== $phase || ! is_array( $result['rules'] ?? null )
			|| array() === $rules || count( $rules ) !== count( $result['rules'] )
		) {
			throw new \RuntimeException( esc_html__( 'Cloudflare did not return a complete created ruleset. Review the rules in Cloudflare before retrying.', 'cybermaps' ) );
		}
		$matched = array();
		foreach ( $rules as $rule ) {
			$matched[] = self::mutation_rule( $response, $rule );
		}
		if ( count( $matched ) !== count( array_unique( array_column( $matched, 'id' ) ) ) ) {
			throw new \RuntimeException( esc_html__( 'Cloudflare returned duplicate rule identifiers. Review the rules in Cloudflare before retrying.', 'cybermaps' ) );
		}
		$result['rules'] = $matched;
		return $result;
	}

	/** @param array<string,mixed> $rule @return array<string,mixed> */
	public function create_rule( string $zone_id, string $ruleset_id, array $rule ): array {
		$response = $this->request( 'POST', self::rules_path( $zone_id, $ruleset_id ), $rule );
		return self::mutation_rule( $response, $rule );
	}

	/** @param array<string,mixed> $rule @return array<string,mixed> */
	public function update_rule( string $zone_id, string $ruleset_id, string $rule_id, array $rule ): array {
		$response = $this->request( 'PATCH', self::rules_path( $zone_id, $ruleset_id ) . '/' . rawurlencode( $rule_id ), $rule );
		return self::mutation_rule( $response, $rule, $rule_id );
	}

	/** @param array<string,mixed> $response @param array<string,mixed> $desired @return array<string,mixed> */
	private static function mutation_rule( array $response, array $desired, string $id = '' ): array {
		$ref   = $desired['ref'] ?? '';
		$rules = $response['result']['rules'] ?? null;
		if ( '' === $ref || ! is_array( $rules ) ) {
			throw new \RuntimeException( esc_html__( 'Cloudflare returned an invalid rule mutation result. Review the rules in Cloudflare before retrying.', 'cybermaps' ) );
		}
		$matches = array_values( array_filter( $rules, static fn( mixed $rule ): bool => is_array( $rule ) && ( $rule['ref'] ?? null ) === $ref ) );
		if ( 1 !== count( $matches ) || ! is_string( $matches[0]['id'] ?? null ) || '' === $matches[0]['id'] ) {
			throw new \RuntimeException( esc_html__( 'Cloudflare did not identify the changed rule. Review the rules in Cloudflare before retrying.', 'cybermaps' ) );
		}
		if ( '' !== $id && $id !== $matches[0]['id'] ) {
			throw new \RuntimeException( esc_html__( 'Cloudflare returned an unexpected rule identifier. Review the rules in Cloudflare before retrying.', 'cybermaps' ) );
		}
		self::require_matching_fields( $matches[0], $desired );
		return $matches[0];
	}

	/** @param array<string,mixed> $actual @param array<string,mixed> $desired */
	private static function require_matching_fields( array $actual, array $desired ): void {
		foreach ( $desired as $field => $value ) {
			if ( self::ordered_fields( $value ) !== self::ordered_fields( $actual[ $field ] ?? null ) ) {
				throw new \RuntimeException( esc_html__( 'Cloudflare returned a rule that does not match the requested configuration. Review the rules in Cloudflare before retrying.', 'cybermaps' ) );
			}
		}
	}

	/** Compare provider JSON objects without depending on property order. */
	private static function ordered_fields( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value, SORT_STRING );
		}
		return array_map( array( self::class, 'ordered_fields' ), $value );
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

		$response = null !== $this->transport ? ( $this->transport )( $url, $args ) : wp_safe_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( esc_html__( 'Cloudflare could not be reached securely. Check outbound HTTPS connectivity and try again.', 'cybermaps' ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $allow_not_found && 404 === $code ) {
			return array( 'not_found' => true );
		}
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $decoded ) || true !== ( $decoded['success'] ?? null ) ) {
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
