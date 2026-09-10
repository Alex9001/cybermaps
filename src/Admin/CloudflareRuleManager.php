<?php
/**
 * Idempotent Cloudflare rule management for public discovery resources.
 *
 * @package Cybermaps\Admin
 */

declare(strict_types=1);

namespace Cybermaps\Admin;

use Cybermaps\Core\EndpointRegistry;
use Cybermaps\Core\URLManager;
use Cybermaps\Discovery\StaticHeaderManifest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Owns only rules carrying Cybermaps' stable reference identifiers. */
final class CloudflareRuleManager {
	private const STATE_OPTION   = 'cybermaps_cloudflare_rule_state';
	private const RESPONSE_PHASE = 'http_response_headers_transform';
	private const REQUEST_PHASE  = 'http_request_transform';
	private const CACHE_PHASE    = 'http_request_cache_settings';
	private const OWNED_REFS     = array(
		'cybermaps_discovery_api_catalog_v1',
		'cybermaps_discovery_markdown_v1',
		'cybermaps_discovery_json_v1',
		'cybermaps_discovery_jsonld_v1',
		'cybermaps_discovery_mcp_card_v1',
		'cybermaps_discovery_oauth_v1',
		'cybermaps_discovery_origin_bypass_v1',
		'cybermaps_discovery_cache_safety_v1',
	);

	public function __construct( private CloudflareRulesClient $client ) {}

	/** @return array<string,mixed> */
	public function install_header_rules(): array {
		$context   = self::public_context();
		$preflight = self::verify_public( false );
		self::require_valid_bodies( $preflight );
		$zone  = $this->client->zone_for_host( $context['host'] );
		$rules = self::header_rules( $context['host'] );
		if ( array() === $rules ) {
			throw new \RuntimeException( esc_html__( 'No enabled static discovery resources are eligible for Cloudflare header repair.', 'cybermaps' ) );
		}
		$synced = $this->sync_phase( $zone['id'], self::RESPONSE_PHASE, __( 'Cybermaps discovery response headers', 'cybermaps' ), $rules );
		$state  = self::store_state(
			array(
				'zone_id'      => $zone['id'],
				'zone_name'    => $zone['name'],
				'header_rules' => $synced,
				'fingerprint'  => self::state_has_rules( self::state(), array( 'origin_rule', 'cache_rule' ) ) ? self::expected_fingerprint() : '',
				'updated_at'   => time(),
			)
		);

		return array(
			'zone'      => $zone['name'],
			'rules'     => $synced,
			'preflight' => $preflight,
			'state'     => $state,
		);
	}

	/** @return array<string,mixed> */
	public function install_origin_bypass_rule(): array {
		$context = self::public_context();
		$zone    = $this->client->zone_for_host( $context['host'] );
		$rules   = array( self::origin_bypass_rule( $context['host'] ) );
		$synced  = $this->sync_phase( $zone['id'], self::REQUEST_PHASE, __( 'Cybermaps discovery origin isolation', 'cybermaps' ), $rules );
		$state   = self::store_state(
			array(
				'zone_id'     => $zone['id'],
				'zone_name'   => $zone['name'],
				'origin_rule' => reset( $synced ),
				'fingerprint' => self::state_has_rules( self::state(), array( 'header_rules', 'cache_rule' ) ) ? self::expected_fingerprint() : '',
				'updated_at'  => time(),
			)
		);

		return array(
			'zone'  => $zone['name'],
			'rules' => $synced,
			'state' => $state,
		);
	}

	/** @return array<string,mixed> */
	public function install_cache_rule(): array {
		$context = self::public_context();
		$zone    = $this->client->zone_for_host( $context['host'] );
		$rules   = array( self::cache_safety_rule( $context['host'] ) );
		$synced  = $this->sync_phase( $zone['id'], self::CACHE_PHASE, __( 'Cybermaps discovery cache safety', 'cybermaps' ), $rules );
		$state   = self::store_state(
			array(
				'zone_id'     => $zone['id'],
				'zone_name'   => $zone['name'],
				'cache_rule'  => reset( $synced ),
				'fingerprint' => self::state_has_rules( self::state(), array( 'header_rules', 'origin_rule' ) ) ? self::expected_fingerprint() : '',
				'updated_at'  => time(),
			)
		);

		return array(
			'zone'  => $zone['name'],
			'rules' => $synced,
			'state' => $state,
		);
	}

	/** @return array<string,mixed> */
	public function install_all(): array {
		$headers = $this->install_header_rules();
		$result  = array(
			'status'  => 'complete',
			'zone'    => (string) ( $headers['zone'] ?? '' ),
			'headers' => $headers,
			'origin'  => null,
			'cache'   => null,
			'errors'  => array(),
		);
		try {
			$result['origin'] = $this->install_origin_bypass_rule();
		} catch ( \Throwable $error ) {
			$result['status']   = 'partial';
			$result['errors'][] = substr( sanitize_text_field( $error->getMessage() ), 0, 500 );
		}
		try {
			$result['cache'] = $this->install_cache_rule();
		} catch ( \Throwable $error ) {
			$result['status']   = 'partial';
			$result['errors'][] = substr( sanitize_text_field( $error->getMessage() ), 0, 500 );
		}
		return $result;
	}

	public static function record_credential_disposition( string $method, string $disposition ): void {
		if ( ! in_array( $method, array( 'oauth', 'api_token' ), true ) || ! in_array( $disposition, array( 'revoked', 'discarded', 'revoke_failed' ), true ) ) {
			return;
		}
		$state                           = self::state();
		$state['credential_method']      = $method;
		$state['credential_disposition'] = $disposition;
		$state['credential_updated_at']  = time();
		update_option( self::STATE_OPTION, $state, false );
	}

	/** @return array<string,mixed> */
	public function remove_rules(): array {
		$context = self::public_context();
		$zone    = $this->client->zone_for_host( $context['host'] );
		$removed = array();
		foreach ( array( self::RESPONSE_PHASE, self::REQUEST_PHASE, self::CACHE_PHASE ) as $phase ) {
			$ruleset = $this->client->phase_ruleset( $zone['id'], $phase );
			if ( null === $ruleset ) {
				continue;
			}
			$removed = array_merge( $removed, $this->remove_owned_from_ruleset( $zone['id'], $ruleset ) );
		}
		delete_option( self::STATE_OPTION );
		return array(
			'zone'    => $zone['name'],
			'removed' => array_values( $removed ),
		);
	}

	/** @return array<string,mixed> */
	public static function state(): array {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	public static function request_is_cloudflare(): bool {
		$ray  = isset( $_SERVER['HTTP_CF_RAY'] ) && is_scalar( $_SERVER['HTTP_CF_RAY'] )
			? trim( (string) $_SERVER['HTTP_CF_RAY'] )
			: '';
		$host = isset( $_SERVER['HTTP_HOST'] ) && is_scalar( $_SERVER['HTTP_HOST'] )
			? wp_parse_url( 'http://' . trim( (string) $_SERVER['HTTP_HOST'] ), PHP_URL_HOST )
			: '';
		return '' !== $ray && strlen( $ray ) <= 256 && is_string( $host ) && hash_equals( self::public_host(), strtolower( trim( $host, '.' ) ) );
	}

	public static function public_host(): string {
		return self::public_context()['host'];
	}

	public static function expected_fingerprint(): string {
		try {
			$context = self::public_context();
			$rules   = array_merge( self::header_rules( $context['host'] ), array( self::origin_bypass_rule( $context['host'] ), self::cache_safety_rule( $context['host'] ) ) );
			$encoded = wp_json_encode( $rules, JSON_UNESCAPED_SLASHES );
			return is_string( $encoded ) ? hash( 'sha256', $encoded ) : '';
		} catch ( \Throwable $error ) {
			return '';
		}
	}

	/** @return array<string,mixed> */
	public static function verify_public( bool $check_headers = true ): array {
		$policies = array_slice( self::relevant_policies(), 0, 20 );
		$rows     = array();
		foreach ( $policies as $policy ) {
			$rows[] = self::verify_policy( $policy, $check_headers );
		}
		$failed = count( array_filter( $rows, static fn( array $row ): bool => empty( $row['ok'] ) ) );
		return array(
			'total'      => count( $rows ),
			'passed'     => count( $rows ) - $failed,
			'failed'     => $failed,
			'headers'    => $check_headers,
			'checked_at' => time(),
			'results'    => $rows,
		);
	}

	/** @return array<int,array<string,mixed>> */
	public static function browser_verification_resources(): array {
		$resources = array();
		foreach ( array_slice( self::relevant_policies(), 0, 20 ) as $policy ) {
			$path = (string) $policy['path'];

			$profile = self::profile_for_policy( $policy );

			$resources[] = array(
				'path'             => $path,
				'url'              => self::public_url_for_path( $path ),
				'profile'          => $profile,
				'expected_type'    => self::expected_content_type( $profile ),
				'expected_cache'   => 'oauth' === $profile ? 'no-store' : 'max-age=300',
				'requires_linkset' => 'api_catalog' === $profile,
			);
		}
		return $resources;
	}

	/** @param array<string,mixed> $preflight */
	private static function require_valid_bodies( array $preflight ): void {
		if ( (int) ( $preflight['total'] ?? 0 ) < 1 || (int) ( $preflight['failed'] ?? 0 ) > 0 ) {
			throw new \RuntimeException( esc_html__( 'Cloudflare rules were not changed because one or more enabled origin resources did not return a valid body.', 'cybermaps' ) );
		}
	}

	/** @param array<int,array<string,mixed>> $rules @return array<int,array<string,string>> */
	private function sync_phase( string $zone_id, string $phase, string $name, array $rules ): array {
		$ruleset = $this->client->phase_ruleset( $zone_id, $phase );
		if ( null === $ruleset ) {
			$created = $this->client->create_phase_ruleset( $zone_id, $phase, $name, $rules );
			return self::rule_records( (array) ( $created['rules'] ?? array() ) );
		}

		return $this->upsert_rules( $zone_id, $ruleset, $rules );
	}

	/** @param array<string,mixed> $ruleset @param array<int,array<string,mixed>> $desired @return array<int,array<string,string>> */
	private function upsert_rules( string $zone_id, array $ruleset, array $desired ): array {
		$ruleset_id = sanitize_text_field( (string) ( $ruleset['id'] ?? '' ) );
		if ( '' === $ruleset_id ) {
			throw new \RuntimeException( esc_html__( 'Cloudflare returned a ruleset without an identifier.', 'cybermaps' ) );
		}
		$existing = self::rules_by_ref( (array) ( $ruleset['rules'] ?? array() ) );
		$journal  = array();
		$results  = array();
		try {
			foreach ( $desired as $rule ) {
				$results[] = $this->upsert_rule( $zone_id, $ruleset_id, $rule, $existing, $journal );
			}
		} catch ( \Throwable $error ) {
			$this->rollback( $zone_id, $ruleset_id, $journal );
			throw $error;
		}
		return self::rule_records( $results );
	}

	/** @param array<string,array<string,mixed>> $existing @param array<int,array<string,mixed>> $journal @return array<string,mixed> */
	private function upsert_rule( string $zone_id, string $ruleset_id, array $rule, array $existing, array &$journal ): array {
		$ref = (string) ( $rule['ref'] ?? '' );
		if ( ! isset( $existing[ $ref ] ) ) {
			$created   = $this->client->create_rule( $zone_id, $ruleset_id, $rule );
			$journal[] = array(
				'operation' => 'create',
				'id'        => (string) ( $created['id'] ?? '' ),
			);
			return $created;
		}
		$old = $existing[ $ref ];
		if ( ! str_starts_with( (string) ( $old['description'] ?? '' ), 'Cybermaps:' ) ) {
			throw new \RuntimeException( sprintf( /* translators: %s: Cloudflare rule reference. */ esc_html__( 'Cloudflare rule reference %s is already used by an unrelated rule.', 'cybermaps' ), esc_html( $ref ) ) );
		}
		$id        = sanitize_text_field( (string) ( $old['id'] ?? '' ) );
		$journal[] = array(
			'operation' => 'update',
			'id'        => $id,
			'old'       => self::mutable_rule( $old ),
		);
		return $this->client->update_rule( $zone_id, $ruleset_id, $id, $rule );
	}

	/** @param array<int,array<string,mixed>> $journal */
	private function rollback( string $zone_id, string $ruleset_id, array $journal ): void {
		foreach ( array_reverse( $journal ) as $entry ) {
			try {
				if ( 'create' === ( $entry['operation'] ?? '' ) && '' !== (string) ( $entry['id'] ?? '' ) ) {
					$this->client->delete_rule( $zone_id, $ruleset_id, (string) $entry['id'] );
				} elseif ( 'update' === ( $entry['operation'] ?? '' ) && is_array( $entry['old'] ?? null ) ) {
					$this->client->update_rule( $zone_id, $ruleset_id, (string) $entry['id'], $entry['old'] );
				}
			} catch ( \Throwable $ignored ) {
				// Best effort only; the original provider error remains authoritative.
				continue;
			}
		}
	}

	/** @param array<string,mixed> $ruleset @return array<int,string> */
	private function remove_owned_from_ruleset( string $zone_id, array $ruleset ): array {
		$ruleset_id = (string) ( $ruleset['id'] ?? '' );
		$removed    = array();
		foreach ( (array) ( $ruleset['rules'] ?? array() ) as $rule ) {
			$ref = is_array( $rule ) ? (string) ( $rule['ref'] ?? '' ) : '';
			$id  = is_array( $rule ) ? (string) ( $rule['id'] ?? '' ) : '';
			if ( '' === $id || ! in_array( $ref, self::OWNED_REFS, true ) || ! str_starts_with( (string) ( $rule['description'] ?? '' ), 'Cybermaps:' ) ) {
				continue;
			}
			$this->client->delete_rule( $zone_id, $ruleset_id, $id );
			$removed[] = $ref;
		}
		return $removed;
	}

	/** @return array<int,array<string,mixed>> */
	private static function header_rules( string $host ): array {
		$groups = array(
			'api_catalog' => array(),
			'markdown'    => array(),
			'json'        => array(),
			'jsonld'      => array(),
			'mcp_card'    => array(),
			'oauth'       => array(),
		);
		foreach ( self::relevant_policies() as $policy ) {
			$groups[ self::profile_for_policy( $policy ) ][] = (string) $policy['path'];
		}
		$rules = array();
		foreach ( $groups as $profile => $paths ) {
			if ( array() !== $paths ) {
				$rules[] = self::header_rule( $host, $profile, $paths );
			}
		}
		return $rules;
	}

	/** @param array<int,string> $paths @return array<string,mixed> */
	private static function header_rule( string $host, string $profile, array $paths ): array {
		$headers = self::profile_headers( $profile, $paths );
		$mapped  = array();
		foreach ( $headers as $name => $value ) {
			$mapped[ $name ] = array(
				'operation' => 'set',
				'value'     => $value,
			);
		}

		return array(
			'action'            => 'rewrite',
			'action_parameters' => array( 'headers' => $mapped ),
			'expression'        => self::path_expression( $host, $paths ),
			'description'       => 'Cybermaps: ' . str_replace( '_', ' ', $profile ) . ' discovery headers',
			'enabled'           => true,
			'ref'               => 'cybermaps_discovery_' . $profile . '_v1',
		);
	}

	/** @param array<int,string> $paths @return array<string,string> */
	private static function profile_headers( string $profile, array $paths ): array {
		$headers                 = array(
			'Access-Control-Allow-Origin'   => '*',
			'Access-Control-Expose-Headers' => 'Content-Type, Cache-Control, Link, ETag, Last-Modified, Repr-Digest, Content-Digest, CF-Ray, X-Cybermaps-Cloudflare-Rule',
			'X-Content-Type-Options'        => 'nosniff',
			'X-Cybermaps-Cloudflare-Rule'   => 'v1',
			'Cache-Control'                 => 'oauth' === $profile ? 'no-store' : 'public, max-age=300, stale-while-revalidate=60',
		);
		$headers['Content-Type'] = match ( $profile ) {
			'api_catalog' => 'application/linkset+json; profile="https://www.rfc-editor.org/info/rfc9727"',
			'markdown'    => 'text/markdown; charset=utf-8',
			'jsonld'      => 'application/ld+json; charset=utf-8',
			'mcp_card'    => 'application/mcp-server-card+json; charset=utf-8',
			default       => 'application/json; charset=utf-8',
		};
		if ( 'api_catalog' === $profile ) {
			$url             = self::public_url_for_path( (string) reset( $paths ) );
			$headers['Link'] = '<' . $url . '>; rel="api-catalog"; type="application/linkset+json"; profile="https://www.rfc-editor.org/info/rfc9727"';
		}
		return $headers;
	}

	/** @return array<string,mixed> */
	private static function cache_safety_rule( string $host ): array {
		$paths = array_merge( self::dynamic_alias_paths(), self::oauth_paths() );
		$path  = array() === $paths ? 'false' : self::path_set_expression( $paths );
		return array(
			'action'            => 'set_cache_settings',
			'action_parameters' => array( 'cache' => false ),
			'expression'        => '(http.host eq "' . self::expression_value( $host ) . '" and (any(http.request.headers["accept"][*] contains "text/markdown") or ' . $path . '))',
			'description'       => 'Cybermaps: negotiated and dynamic discovery cache safety',
			'enabled'           => true,
			'ref'               => 'cybermaps_discovery_cache_safety_v1',
		);
	}

	/** @return array<string,mixed> */
	private static function origin_bypass_rule( string $host ): array {
		$namespace = strtolower( (string) CYBERMAPS_VERSION ) . '-' . substr( hash( 'sha256', $host ), 0, 16 );
		return array(
			'action'            => 'rewrite',
			'action_parameters' => array(
				'uri' => array(
					'query' => array( 'value' => 'cybermaps_origin=' . rawurlencode( $namespace ) ),
				),
			),
			'expression'        => '(http.host eq "' . self::expression_value( $host ) . '" and http.request.method in {"GET" "HEAD"} and http.request.uri.path eq "/ai-discovery" and http.request.uri.query eq "")',
			'description'       => 'Cybermaps: isolate the canonical discovery index from shared origin caches',
			'enabled'           => true,
			'ref'               => 'cybermaps_discovery_origin_bypass_v1',
		);
	}

	/** @return array<int,array<string,mixed>> */
	private static function relevant_policies(): array {
		$manifest = ( new StaticHeaderManifest() )->get_manifest();
		$policies = array();
		foreach ( (array) ( $manifest['policies'] ?? array() ) as $policy ) {
			$path = is_array( $policy ) ? (string) ( $policy['path'] ?? '' ) : '';
			$mime = is_array( $policy ) ? strtolower( (string) ( $policy['mime'] ?? '' ) ) : '';
			if ( ! is_array( $policy ) || empty( $policy['enabled'] ) || ! self::is_discovery_path( $path ) || ! self::is_supported_mime( $mime ) ) {
				continue;
			}
			$path = self::canonical_public_path( $path );
			if ( '' === $path ) {
				continue;
			}

			$policy['path']    = $path;
			$policies[ $path ] = $policy;
		}
		ksort( $policies, SORT_STRING );
		return array_values( $policies );
	}

	private static function is_discovery_path( string $path ): bool {
		return str_starts_with( $path, '/.well-known/' )
			|| in_array( $path, array( '/auth.md', '/ai.json', '/ai-usage.json', '/ai-actions.json', '/ai-discovery' ), true );
	}

	private static function is_supported_mime( string $mime ): bool {
		return str_contains( $mime, 'json' ) || str_contains( $mime, 'markdown' );
	}

	/** @param array<string,mixed> $policy */
	private static function profile_for_policy( array $policy ): string {
		$path = strtolower( (string) ( $policy['path'] ?? '' ) );
		$mime = strtolower( (string) ( $policy['mime'] ?? '' ) );
		if ( str_contains( $mime, 'linkset+json' ) || str_ends_with( $path, '/api-catalog' ) ) {
			return 'api_catalog';
		}
		if ( str_contains( $mime, 'markdown' ) || str_ends_with( $path, '.md' ) ) {
			return 'markdown';
		}
		if ( str_contains( $path, '/oauth-' ) || str_contains( $path, '/openid-' ) ) {
			return 'oauth';
		}
		return match ( $mime ) {
			'application/ld+json'              => 'jsonld',
			'application/mcp-server-card+json' => 'mcp_card',
			default                            => 'json',
		};
	}

	/** @return array<int,string> */
	private static function dynamic_alias_paths(): array {
		$paths    = array();
		$registry = EndpointRegistry::get_instance();
		foreach ( array( 'api_catalog', 'discovery_index' ) as $id ) {
			$definition = $registry->get( $id );
			if ( is_array( $definition ) ) {
				foreach ( array_filter( (array) ( $definition['aliases'] ?? array() ), 'is_string' ) as $alias ) {
					$path = self::canonical_public_path( $alias );
					if ( '' !== $path ) {
						$paths[] = $path;
					}
				}
			}
		}
		return array_values( array_unique( $paths ) );
	}

	/** @return array<int,string> */
	private static function oauth_paths(): array {
		$paths = array();
		foreach ( self::relevant_policies() as $policy ) {
			if ( 'oauth' === self::profile_for_policy( $policy ) ) {
				$paths[] = (string) $policy['path'];
			}
		}
		return array_values( array_unique( $paths ) );
	}

	/** @param array<int,string> $paths */
	private static function path_expression( string $host, array $paths ): string {
		return '(http.host eq "' . self::expression_value( $host ) . '" and http.request.method in {"GET" "HEAD"} and ' . self::path_set_expression( $paths ) . ')';
	}

	/** @param array<int,string> $paths */
	private static function path_set_expression( array $paths ): string {
		$values = array_map( static fn( string $path ): string => '"' . self::expression_value( $path ) . '"', array_values( array_unique( $paths ) ) );
		return 'http.request.uri.path in {' . implode( ' ', $values ) . '}';
	}

	private static function expression_value( string $value ): string {
		return str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $value );
	}

	/** @return array{host:string,origin:string} */
	private static function public_context(): array {
		$url   = URLManager::get_home_url( '/' );
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) || ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) ) {
			throw new \RuntimeException( esc_html__( 'The configured public site URL is not a valid HTTP origin.', 'cybermaps' ) );
		}
		$host = strtolower( trim( (string) $parts['host'], '.' ) );
		if ( 1 !== preg_match( '/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/', $host ) ) {
			throw new \RuntimeException( esc_html__( 'The public hostname cannot be represented safely in a Cloudflare rule.', 'cybermaps' ) );
		}
		$origin = strtolower( (string) $parts['scheme'] ) . '://' . $host;
		if ( isset( $parts['port'] ) ) {
			$origin .= ':' . (int) $parts['port'];
		}
		return array(
			'host'   => $host,
			'origin' => $origin,
		);
	}

	private static function canonical_public_path( string $path ): string {
		$url   = URLManager::get_home_url( $path );
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['path'] ) ) {
			return '';
		}
		return '/' . ltrim( (string) $parts['path'], '/' );
	}

	private static function public_url_for_path( string $path ): string {
		return self::public_context()['origin'] . '/' . ltrim( $path, '/' );
	}

	/** @param array<string,mixed> $policy @return array<string,mixed> */
	private static function verify_policy( array $policy, bool $check_headers ): array {
		$path     = (string) $policy['path'];
		$url      = self::public_url_for_path( $path );
		$url      = add_query_arg( 'cybermaps_verify', wp_generate_uuid4(), $url );
		$args     = array(
			'timeout'     => 8,
			'redirection' => 3,
			'headers'     => array(
				'Cache-Control'          => 'no-cache',
				'X-Cybermaps-Diagnostic' => '1',
			),
		);
		$response = wp_safe_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			return array(
				'path'    => $path,
				'ok'      => false,
				'status'  => 0,
				'message' => esc_html__( 'The public request failed.', 'cybermaps' ),
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );

		$body = (string) wp_remote_retrieve_body( $response );

		$profile = self::profile_for_policy( $policy );

		$body_ok = 200 === $code && self::valid_body( $body, $profile );

		$head_ok = ! $check_headers || self::valid_headers( $response, $profile );
		return array(
			'path'    => $path,
			'ok'      => $body_ok && $head_ok,
			'status'  => $code,
			'body'    => $body_ok,
			'headers' => $head_ok,
			'message' => $body_ok && $head_ok ? esc_html__( 'Body and required headers are available.', 'cybermaps' ) : esc_html__( 'The body or expected edge headers need attention.', 'cybermaps' ),
		);
	}

	private static function valid_body( string $body, string $profile ): bool {
		if ( '' === trim( $body ) ) {
			return false;
		}
		if ( 'markdown' === $profile ) {
			return true;
		}
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return false;
		}
		return 'api_catalog' !== $profile || ( isset( $decoded['linkset'] ) && is_array( $decoded['linkset'] ) );
	}

	private static function expected_content_type( string $profile ): string {
		return match ( $profile ) {
			'api_catalog' => 'application/linkset+json',
			'markdown'    => 'text/markdown',
			'jsonld'      => 'application/ld+json',
			'mcp_card'    => 'application/mcp-server-card+json',
			default       => 'application/json',
		};
	}

	/** @param array<string,mixed>|\WP_Error $response */
	private static function valid_headers( $response, string $profile ): bool {
		$type     = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		$cors     = trim( (string) wp_remote_retrieve_header( $response, 'access-control-allow-origin' ) );
		$cache    = strtolower( (string) wp_remote_retrieve_header( $response, 'cache-control' ) );
		$want     = self::expected_content_type( $profile );
		$cache_ok = 'oauth' === $profile ? str_contains( $cache, 'no-store' ) : str_contains( $cache, 'max-age=300' );
		$link_ok  = 'api_catalog' !== $profile || str_contains( strtolower( (string) wp_remote_retrieve_header( $response, 'link' ) ), 'rel="api-catalog"' );
		$marker   = strtolower( trim( (string) wp_remote_retrieve_header( $response, 'x-cybermaps-cloudflare-rule' ) ) );
		return str_starts_with( $type, $want ) && '*' === $cors && $cache_ok && $link_ok && 'v1' === $marker;
	}

	/** @param array<int,mixed> $rules @return array<string,array<string,mixed>> */
	private static function rules_by_ref( array $rules ): array {
		$indexed = array();
		foreach ( $rules as $rule ) {
			if ( is_array( $rule ) && '' !== (string) ( $rule['ref'] ?? '' ) ) {
				$indexed[ (string) $rule['ref'] ] = $rule;
			}
		}
		return $indexed;
	}

	/** @param array<string,mixed> $rule @return array<string,mixed> */
	private static function mutable_rule( array $rule ): array {
		return array_intersect_key( $rule, array_flip( array( 'action', 'action_parameters', 'expression', 'description', 'enabled', 'ref' ) ) );
	}

	/** @param array<int,mixed> $rules @return array<int,array<string,string>> */
	private static function rule_records( array $rules ): array {
		$records = array();
		foreach ( $rules as $rule ) {
			if ( is_array( $rule ) ) {
				$records[] = array(
					'id'  => sanitize_text_field( (string) ( $rule['id'] ?? '' ) ),
					'ref' => sanitize_key( (string) ( $rule['ref'] ?? '' ) ),
				);
			}
		}
		return $records;
	}

	/** @param array<string,mixed> $state @param array<int,string> $keys */
	private static function state_has_rules( array $state, array $keys ): bool {
		foreach ( $keys as $key ) {
			if ( empty( $state[ $key ] ) ) {
				return false;
			}
		}
		return true;
	}

	/** @param array<string,mixed> $changes @return array<string,mixed> */
	private static function store_state( array $changes ): array {
		$state = array_merge( self::state(), $changes, array( 'schema' => 2 ) );
		update_option( self::STATE_OPTION, $state, false );
		return $state;
	}
}
