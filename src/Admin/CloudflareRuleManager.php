<?php
/**
 * Idempotent Cloudflare rule management for public discovery resources.
 *
 * @package Cybermaps\Admin
 */

declare(strict_types=1);

namespace Cybermaps\Admin;

use Cybermaps\Core\EndpointRegistry;
use Cybermaps\Core\DatabaseSessionLock;
use Cybermaps\Core\URLManager;
use Cybermaps\Discovery\StaticHeaderManifest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Owns only rules carrying Cybermaps' stable reference identifiers. */
final class CloudflareRuleManager {
	private const STATE_OPTION     = 'cybermaps_cloudflare_rule_state';
	private const RESPONSE_PHASE   = 'http_response_headers_transform';
	private const REQUEST_PHASE    = 'http_request_transform';
	private const CACHE_PHASE      = 'http_request_cache_settings';
	private const VERIFY_MAX_BYTES = 4 * 1024 * 1024;
	private const OWNED_REFS       = array(
		'cybermaps_discovery_api_catalog_v1',
		'cybermaps_discovery_markdown_v1',
		'cybermaps_discovery_json_v1',
		'cybermaps_discovery_jsonld_v1',
		'cybermaps_discovery_mcp_card_v1',
		'cybermaps_discovery_oauth_v1',
		'cybermaps_discovery_origin_bypass_v1',
		'cybermaps_discovery_cache_safety_v1',
	);

	public function __construct( private CloudflareRulesClient $client, private ?\Closure $operation_guard = null, private ?DatabaseSessionLock $lock = null ) {}

	private function guard_operation(): void {
		if ( null !== $this->lock && ! $this->lock->maintain() ) {
			throw new \RuntimeException( esc_html__( 'The Cloudflare operation lost its database lock. Start again.', 'cybermaps' ) );
		}
		if ( null !== $this->operation_guard ) {
			( $this->operation_guard )();
		}
	}

	/** @return array<string,mixed> */
	public function install_header_rules(): array {
		$this->guard_operation();
		$context   = self::public_context();
		$preflight = self::verify_public( false );
		self::require_valid_bodies( $preflight );
		$zone   = $this->client->zone_for_host( $context['host'] );
		$rules  = self::header_rules( $context['host'] );
		$state  = $this->sync_recorded_phase( $zone, self::RESPONSE_PHASE, 'header_rules', __( 'Cybermaps discovery response headers', 'cybermaps' ), $rules );
		$synced = $state['header_rules'];

		return array(
			'zone'                => $zone['name'],
			'rules'               => $synced,
			'active_header_rules' => count( array_filter( $synced, static fn( array $rule ): bool => true === $rule['enabled'] ) ),
			'preflight'           => $preflight,
			'state'               => $state,
		);
	}

	/** @return array<string,mixed> */
	public function install_origin_bypass_rule(): array {
		$context = self::public_context();
		$zone    = $this->client->zone_for_host( $context['host'] );
		$rules   = array( self::origin_bypass_rule( $context['host'] ) );
		$state   = $this->sync_recorded_phase( $zone, self::REQUEST_PHASE, 'origin_rule', __( 'Cybermaps discovery origin isolation', 'cybermaps' ), $rules );
		$synced  = array( $state['origin_rule'] );

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
		$state   = $this->sync_recorded_phase( $zone, self::CACHE_PHASE, 'cache_rule', __( 'Cybermaps discovery cache safety', 'cybermaps' ), $rules );
		$synced  = array( $state['cache_rule'] );

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

	public function record_credential_disposition( string $method, string $disposition ): void {
		if ( ! in_array( $method, array( 'oauth', 'api_token' ), true ) || ! in_array( $disposition, array( 'revoked', 'discarded', 'revoke_failed' ), true ) ) {
			return;
		}
		$this->store_state(
			array(
				'credential_method'      => $method,
				'credential_disposition' => $disposition,
				'credential_updated_at'  => time(),
			)
		);
	}

	/** @return array<string,mixed> */
	public function remove_rules(): array {
		$context = self::public_context();
		$zone    = $this->client->zone_for_host( $context['host'] );
		$this->store_state(
			array(
				'fingerprint'        => '',
				'phase_fingerprints' => array(),
			)
		);
		$removed  = array();
		$retained = array();
		foreach ( array( self::RESPONSE_PHASE, self::REQUEST_PHASE, self::CACHE_PHASE ) as $phase ) {
			$ruleset = $this->client->phase_ruleset( $zone['id'], $phase );
			if ( null === $ruleset ) {
				continue;
			}
			$removed  = array_merge( $removed, $this->remove_owned_from_ruleset( $zone['id'], $ruleset ) );
			$retained = array_merge( $retained, array_intersect( array_keys( self::rules_by_ref( (array) ( $ruleset['rules'] ?? array() ) ) ), self::OWNED_REFS ) );
		}
		$this->guard_operation();
		$before = CloudflareOptionStore::read( self::STATE_OPTION );
		( new CloudflareOptionStore( $this->lock ) )->write( self::STATE_OPTION, $before, null );
		return array(
			'zone'            => $zone['name'],
			'removed'         => array_values( $removed ),
			'retained_legacy' => array_values( array_unique( $retained ) ),
		);
	}

	/** @return array<string,mixed> */
	public static function state(): array {
		return self::state_observation()['state'];
	}

	/** @return array{available:bool,state:array<string,mixed>} */
	public static function state_observation(): array {
		try {
			$raw   = CloudflareOptionStore::read( self::STATE_OPTION );
			$state = null === $raw ? array() : maybe_unserialize( $raw );
			return array(
				'available' => is_array( $state ),
				'state'     => is_array( $state ) ? $state : array(),
			);
		} catch ( \RuntimeException $error ) {
			return array(
				'available' => false,
				'state'     => array(),
			);
		}
	}

	public static function request_is_cloudflare(): bool {
		$ray  = isset( $_SERVER['HTTP_CF_RAY'] ) && is_scalar( $_SERVER['HTTP_CF_RAY'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_CF_RAY'] ) )
			: '';
		$host = isset( $_SERVER['HTTP_HOST'] ) && is_scalar( $_SERVER['HTTP_HOST'] )
			? wp_parse_url( 'http://' . sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_HOST'] ) ), PHP_URL_HOST )
			: '';
		return '' !== $ray && strlen( $ray ) <= 256 && is_string( $host ) && hash_equals( self::public_host(), strtolower( trim( $host, '.' ) ) );
	}

	public static function public_host(): string {
		return self::public_context()['host'];
	}

	public static function expected_fingerprint(): string {
		try {
			return self::rules_fingerprint( array_merge( ...array_values( self::desired_phases() ) ) );
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
				'path'          => $path,
				'url'           => self::public_url_for_path( $path ),
				'profile'       => $profile,
				'expected_type' => self::expected_content_type( $profile ),
				'header_policy' => 'origin-authoritative',
				'repair_scope'  => 'missing-static-mime-only',
			);
		}
		return $resources;
	}

	/** @param array<string,mixed> $preflight */
	private static function require_valid_bodies( array $preflight ): void {
		if ( (int) ( $preflight['failed'] ?? 0 ) > 0 ) {
			throw new \RuntimeException( esc_html__( 'Cloudflare rules were not changed because one or more enabled origin resources did not return a valid body.', 'cybermaps' ) );
		}
	}

	/** @param array<int,array<string,mixed>> $rules @return array<int,array<string,string>> */
	private function sync_phase( string $zone_id, string $phase, string $name, array $rules ): array {
		$ruleset = $this->client->phase_ruleset( $zone_id, $phase );
		if ( null === $ruleset ) {
			$this->guard_operation();
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
			if ( ! $this->rollback( $zone_id, $ruleset_id, $journal ) ) {
				throw new \RuntimeException( esc_html__( 'The Cloudflare operation failed and some changes could not be restored. Review the rules in Cloudflare before retrying.', 'cybermaps' ) );
			}
			throw $error;
		}
		return self::rule_records( $results );
	}

	/** @param array<string,array<string,mixed>> $existing @param array<int,array<string,mixed>> $journal @return array<string,mixed> */
	private function upsert_rule( string $zone_id, string $ruleset_id, array $rule, array $existing, array &$journal ): array {
		$ref      = (string) ( $rule['ref'] ?? '' );
		$existing = self::adopt_legacy_rule( $rule, $existing );
		if ( ! isset( $existing[ $ref ] ) ) {
			$this->guard_operation();
			$created   = $this->client->create_rule( $zone_id, $ruleset_id, $rule );
			$journal[] = array(
				'operation' => 'create',
				'id'        => (string) ( $created['id'] ?? '' ),
			);
			return $created;
		}
		$old = $existing[ $ref ];
		if ( ! self::owns_rule( array_merge( $old, array( 'ref' => $ref ) ) ) ) {
			throw new \RuntimeException( sprintf( /* translators: %s: Cloudflare rule reference. */ esc_html__( 'Cloudflare rule reference %s is already used by an unrelated rule.', 'cybermaps' ), esc_html( $ref ) ) );
		}
		$this->guard_operation();
		$id        = sanitize_text_field( (string) ( $old['id'] ?? '' ) );
		$journal[] = array(
			'operation' => 'update',
			'id'        => $id,
			'old'       => self::mutable_rule( $old ),
		);
		return $this->client->update_rule( $zone_id, $ruleset_id, $id, $rule );
	}

	/** @param array<int,array<string,mixed>> $journal */
	private function rollback( string $zone_id, string $ruleset_id, array $journal ): bool {
		$restored = true;
		foreach ( array_reverse( $journal ) as $entry ) {
			try {
				if ( 'create' === ( $entry['operation'] ?? '' ) && '' !== (string) ( $entry['id'] ?? '' ) ) {
					$this->guard_operation();
					$this->client->delete_rule( $zone_id, $ruleset_id, (string) $entry['id'] );
				} elseif ( 'update' === ( $entry['operation'] ?? '' ) && is_array( $entry['old'] ?? null ) ) {
					$this->guard_operation();
					$this->client->update_rule( $zone_id, $ruleset_id, (string) $entry['id'], $entry['old'] );
				}
			} catch ( \Throwable $ignored ) {
				$restored = false;
			}
		}
		return $restored;
	}

	/** @param array<string,mixed> $ruleset @return array<int,string> */
	private function remove_owned_from_ruleset( string $zone_id, array $ruleset ): array {
		$ruleset_id = (string) ( $ruleset['id'] ?? '' );
		$removed    = array();
		foreach ( (array) ( $ruleset['rules'] ?? array() ) as $rule ) {
			$ref = is_array( $rule ) ? (string) ( $rule['ref'] ?? '' ) : '';
			$id  = is_array( $rule ) ? (string) ( $rule['id'] ?? '' ) : '';
			if ( '' === $id || ! is_array( $rule ) || ! self::owns_rule( $rule ) ) {
				continue;
			}
			$this->guard_operation();
			$this->client->delete_rule( $zone_id, $ruleset_id, $id );
			$removed[] = $ref;
		}
		return $removed;
	}

	private static function owned_ref( string $base, string $host ): string {
		$identity = strtolower( $host ) . self::canonical_public_path( '/' );
		return $base . '_' . substr( hash( 'sha256', $identity ), 0, 24 );
	}

	/** @param array<string,mixed> $rule */
	private static function owns_rule( array $rule ): bool {
		$host = self::public_host();
		$refs = array_map( static fn( string $base ): string => self::owned_ref( $base, $host ), self::OWNED_REFS );
		return in_array( $rule['ref'] ?? null, $refs, true )
			&& is_string( $rule['description'] ?? null ) && str_starts_with( $rule['description'], 'Cybermaps:' )
			&& is_string( $rule['expression'] ?? null )
			&& str_starts_with( $rule['expression'], '(http.host eq "' . self::expression_value( $host ) . '" and ' );
	}

	/** @param array<string,mixed> $desired @param array<string,array<string,mixed>> $existing @return array<string,array<string,mixed>> */
	private static function adopt_legacy_rule( array $desired, array $existing ): array {
		$ref = (string) ( $desired['ref'] ?? '' );
		foreach ( self::OWNED_REFS as $base ) {
			if ( ! isset( $existing[ $base ] ) || self::owned_ref( $base, self::public_host() ) !== $ref ) {
				continue;
			}
			$legacy        = $existing[ $base ];
			$legacy['ref'] = $ref;
			if ( ! self::owns_rule( $legacy ) ) {
				continue;
			}
			if ( isset( $existing[ $ref ] ) || self::mutable_rule( $legacy ) !== self::mutable_rule( $desired ) ) {
				throw new \RuntimeException( esc_html__( 'An older Cloudflare rule for this hostname cannot be safely adopted. Review its scope in Cloudflare before retrying.', 'cybermaps' ) );
			}
			$existing[ $ref ] = $existing[ $base ];
		}
		return $existing;
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
			// Keep disabled profiles in the plan to replace older unsafe owned rules.
			$rules[] = self::header_rule( $host, $profile, $paths );
		}
		return $rules;
	}

	/** @param array<int,string> $paths @return array<string,mixed> */
	private static function header_rule( string $host, string $profile, array $paths ): array {
		$headers = self::profile_headers( $profile );
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
			'expression'        => self::missing_mime_expression( $host, $paths ),
			'description'       => 'Cybermaps: ' . str_replace( '_', ' ', $profile ) . ' missing static MIME fallback',
			'enabled'           => array() !== $paths,
			'ref'               => self::owned_ref( 'cybermaps_discovery_' . $profile . '_v1', $host ),
		);
	}

	/** @return array<string,string> */
	private static function profile_headers( string $profile ): array {
		return array(
			'Content-Type'                => self::expected_content_type( $profile ) . '; charset=utf-8',
			'X-Cybermaps-Cloudflare-Rule' => 'v2-missing-mime',
		);
	}

	/** Only add missing MIME; origin representation and cache policy are authoritative.
	 *
	 * @param array<int,string> $paths Eligible static publication paths.
	 */
	private static function missing_mime_expression( string $host, array $paths ): string {
		$path = array() === $paths ? 'false' : self::path_set_expression( $paths );
		return '(http.host eq "' . self::expression_value( $host ) . '" and http.request.method in {"GET" "HEAD"} and ' . $path
			. ' and http.request.uri.query eq "" and not http.request.headers.truncated'
			. ' and not any(lower(http.request.headers.names[*])[*] in {"authorization" "cookie"})'
			. ' and http.response.code eq 200'
			. ' and not any(lower(http.response.headers.names[*])[*] in {"content-type" "set-cookie" "vary" "cache-control" "cdn-cache-control" "cloudflare-cdn-cache-control" "pragma" "expires"}))';
	}

	/** @return array<string,mixed> */
	private static function cache_safety_rule( string $host ): array {
		$paths       = array_merge( self::dynamic_alias_paths(), self::oauth_paths() );
		$path        = array() === $paths ? 'false' : self::path_set_expression( $paths );
		$root        = self::canonical_public_path( '/' );
		$negotiation = 'any(lower(http.request.headers["accept"][*])[*] contains "text/markdown")';
		if ( '/' !== $root ) {
			$negotiation = '(' . $negotiation . ' and (starts_with(http.request.uri.path, "' . self::expression_value( $root ) . '") or http.request.uri.path eq "' . self::expression_value( rtrim( $root, '/' ) ) . '"))';
		}
		return array(
			'action'            => 'set_cache_settings',
			'action_parameters' => array( 'cache' => false ),
			'expression'        => '(http.host eq "' . self::expression_value( $host ) . '" and (' . $negotiation . ' or ' . $path . '))',
			'description'       => 'Cybermaps: negotiated and dynamic discovery cache safety',
			'enabled'           => true,
			'ref'               => self::owned_ref( 'cybermaps_discovery_cache_safety_v1', $host ),
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
			'expression'        => '(http.host eq "' . self::expression_value( $host ) . '" and http.request.method in {"GET" "HEAD"} and http.request.uri.path eq "' . self::expression_value( self::canonical_public_path( '/ai-discovery' ) ) . '" and http.request.uri.query eq "")',
			'description'       => 'Cybermaps: isolate the canonical discovery index from shared origin caches',
			'enabled'           => true,
			'ref'               => self::owned_ref( 'cybermaps_discovery_origin_bypass_v1', $host ),
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
		$args     = array(
			'limit_response_size' => self::VERIFY_MAX_BYTES + 1,
			'timeout'             => 8,
			'redirection'         => 3,
			'headers'             => array(
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

		$body_ok = 200 === $code && strlen( $body ) <= self::VERIFY_MAX_BYTES && self::valid_body( $body, $profile );

		$head_ok = ! $check_headers || self::valid_headers( $response, $profile );
		return array(
			'path'    => $path,
			'ok'      => $body_ok && $head_ok,
			'status'  => $code,
			'body'    => $body_ok,
			'headers' => $head_ok,
			'message' => $body_ok && $head_ok ? esc_html__( 'The public body and MIME type are valid; origin cache policy is preserved.', 'cybermaps' ) : esc_html__( 'The public body or MIME type needs attention. An incorrect origin MIME type must be repaired at the origin.', 'cybermaps' ),
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
		$type = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		return trim( explode( ';', $type, 2 )[0] ) === self::expected_content_type( $profile );
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
		return self::sort_rule_fields( array_intersect_key( $rule, array_flip( array( 'action', 'action_parameters', 'expression', 'description', 'enabled', 'ref' ) ) ) );
	}

	/** @param array<string|int,mixed> $value @return array<string|int,mixed> */
	private static function sort_rule_fields( array $value ): array {
		ksort( $value );
		return array_map( static fn( mixed $field ): mixed => is_array( $field ) ? self::sort_rule_fields( $field ) : $field, $value );
	}

	/** @param array<int,mixed> $rules @return array<int,array<string,string>> */
	private static function rule_records( array $rules ): array {
		$records = array();
		foreach ( $rules as $rule ) {
			if ( is_array( $rule ) ) {
				$records[] = array(
					'id'      => sanitize_text_field( (string) ( $rule['id'] ?? '' ) ),
					'ref'     => sanitize_key( (string) ( $rule['ref'] ?? '' ) ),
					'enabled' => true === ( $rule['enabled'] ?? false ),
				);
			}
		}
		return $records;
	}

	/** @return array<string,array<int,array<string,mixed>>> */
	private static function desired_phases(): array {
		$host = self::public_host();
		return array(
			'header_rules' => self::header_rules( $host ),
			'origin_rule'  => array( self::origin_bypass_rule( $host ) ),
			'cache_rule'   => array( self::cache_safety_rule( $host ) ),
		);
	}

	/** @param array<int,array<string,mixed>> $rules */
	private static function rules_fingerprint( array $rules ): string {
		$encoded = wp_json_encode( $rules, JSON_UNESCAPED_SLASHES );
		return is_string( $encoded ) ? hash( 'sha256', $encoded ) : '';
	}

	/** @param array{id:string,name:string} $zone @param array<int,array<string,mixed>> $rules @return array<string,mixed> */
	private function sync_recorded_phase( array $zone, string $phase, string $key, string $name, array $rules ): array {
		$this->guard_operation();
		$observation = self::state_observation();
		if ( ! $observation['available'] ) {
			throw new \RuntimeException( esc_html__( 'Saved Cloudflare operation history is unavailable. No rule changes were started; retry when database access is restored.', 'cybermaps' ) );
		}
		$state        = $observation['state'];
		$fingerprints = ( $state['zone_id'] ?? '' ) === $zone['id'] && is_array( $state['phase_fingerprints'] ?? null ) ? $state['phase_fingerprints'] : array();
		// A failed or partially rolled-back operation must never retain a current claim.
		unset( $fingerprints[ $key ] );
		$this->store_state(
			array(
				'fingerprint'        => '',
				'phase_fingerprints' => $fingerprints,
			)
		);
		$synced = $this->sync_phase( $zone['id'], $phase, $name, $rules );
		$this->guard_operation();
		$fingerprints[ $key ] = self::rules_fingerprint( $rules );
		return $this->store_state(
			array(
				'zone_id'            => $zone['id'],
				'zone_name'          => $zone['name'],
				$key                 => 'header_rules' === $key ? $synced : reset( $synced ),
				'phase_fingerprints' => $fingerprints,
				'fingerprint'        => self::coherent_fingerprint( $fingerprints ),
				'updated_at'         => time(),
			)
		);
	}

	/** @param array<string,mixed> $fingerprints */
	private static function coherent_fingerprint( array $fingerprints ): string {
		$desired = self::desired_phases();
		foreach ( $desired as $key => $rules ) {
			$expected = self::rules_fingerprint( $rules );
			if ( '' === $expected || ( $fingerprints[ $key ] ?? null ) !== $expected ) {
				return '';
			}
		}
		return self::rules_fingerprint( array_merge( ...array_values( $desired ) ) );
	}

	/** @param array<string,mixed> $changes @return array<string,mixed> */
	private function store_state( array $changes ): array {
		$this->guard_operation();
		$before = CloudflareOptionStore::read( self::STATE_OPTION );
		$stored = null === $before ? array() : maybe_unserialize( $before );
		$state  = array_merge( is_array( $stored ) ? $stored : array(), $changes, array( 'schema' => 4 ) );
		( new CloudflareOptionStore( $this->lock ) )->write( self::STATE_OPTION, $before, (string) maybe_serialize( $state ) );
		return $state;
	}
}
