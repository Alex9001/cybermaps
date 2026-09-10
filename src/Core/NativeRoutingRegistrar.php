<?php
/**
 * Provider-neutral WordPress publication routing.
 *
 * @package Cybermaps\Core
 */

declare(strict_types=1);

namespace Cybermaps\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers exact global rules before filesystem short-circuits can intercept them.
 */
final class NativeRoutingRegistrar {
	public const QUERY_VAR     = 'cybermaps_publication';
	public const SCHEMA_OPTION = 'cybermaps_native_routing_schema';

	private static ?self $instance = null;
	private bool $hooks_registered = false;

	public static function get_instance(): self {
		self::$instance ??= new self();
		return self::$instance;
	}

	/** Register routing at the beginning of WordPress rewrite collection. */
	public function register_hooks(): void {
		if ( $this->hooks_registered ) {
			return;
		}
		$this->hooks_registered = true;
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		add_action( 'init', array( $this, 'register_rules' ), 1 );
		add_action( 'wp_loaded', array( $this, 'refresh_if_needed' ), 99 );
	}

	/** @param string[] $vars
	 *  @return string[]
	 */
	public function add_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return array_values( array_unique( $vars ) );
	}

	/** Register internal rules for every fixed publication and global well-known rules. */
	public function register_rules(): void {
		foreach ( $this->rules() as $path => $id ) {
			$regex = $this->regex( $path );
			$query = 'index.php?' . self::QUERY_VAR . '=' . rawurlencode( $id );
			add_rewrite_rule( $regex, $query, 'top' );
			if ( str_starts_with( $path, '/.well-known/' ) ) {
				$this->add_external_rule( $regex, $query );
			}
		}
	}

	/** Flush once when the deterministic rule inventory changes. */
	public function refresh_if_needed(): bool {
		$schema = $this->schema_hash();
		if ( hash_equals( $schema, (string) get_option( self::SCHEMA_OPTION, '' ) ) ) {
			return false;
		}
		$this->register_rules();
		flush_rewrite_rules( true );
		update_option( self::SCHEMA_OPTION, $schema, false );
		return true;
	}

	/** Install rules during activation before WordPress writes its global config. */
	public function activate( bool $hard_flush = true ): void {
		$this->register_rules();
		flush_rewrite_rules( $hard_flush );
		update_option( self::SCHEMA_OPTION, $this->schema_hash(), false );
	}

	/** Remove the in-memory rules before the deactivation flush. */
	public function deactivate( bool $hard_flush = true ): void {
		global $wp_rewrite;
		foreach ( array_keys( $this->rules() ) as $path ) {
			$regex = $this->regex( $path );
			if ( is_object( $wp_rewrite ) ) {
				unset( $wp_rewrite->extra_rules_top[ $regex ], $wp_rewrite->extra_rules[ $regex ], $wp_rewrite->non_wp_rules[ $regex ] );
			}
		}
		delete_option( self::SCHEMA_OPTION );
		flush_rewrite_rules( $hard_flush );
	}

	/**
	 * Exact well-known paths that must reach WordPress before directory/file checks.
	 *
	 * @return string[]
	 */
	public function well_known_paths(): array {
		return array_values(
			array_filter(
				array_keys( $this->rules() ),
				static fn( string $path ): bool => str_starts_with( $path, '/.well-known/' )
			)
		);
	}

	/** @return string[] Enabled canonical/alias paths suitable for public probes. */
	public function enabled_well_known_paths(): array {
		$registry = EndpointRegistry::get_instance();
		$settings = ConfigurationStore::settings();
		$paths    = array();
		foreach ( $this->rules() as $path => $id ) {
			if ( ! str_starts_with( $path, '/.well-known/' ) ) {
				continue;
			}
			if ( 'oauth_metadata' === $id ) {
				$mcp = (string) ( $settings['mcp_mode'] ?? 'off' );
				if ( empty( $settings['enable_discovery_hub'] ) || 'off' === $mcp ) {
					continue;
				}
			} elseif ( ! $registry->is_enabled( $id, $settings ) ) {
				continue;
			}
			$paths[] = $path;
		}
		return $paths;
	}

	/** @return array<string,string> */
	private function rules(): array {
		$rules = array();
		foreach ( EndpointRegistry::get_instance()->get_path_publications() as $id => $endpoint ) {
			$paths = array_merge( array( (string) ( $endpoint['path'] ?? '' ) ), (array) ( $endpoint['aliases'] ?? array() ) );
			foreach ( $paths as $path ) {
				$path = $this->normalize_path( (string) $path );
				if ( '' !== $path ) {
					$rules[ $path ] = (string) $id;
				}
			}
		}
		foreach ( array( '/.well-known/oauth-authorization-server', '/.well-known/oauth-protected-resource' ) as $path ) {
			$rules[ $path ] = 'oauth_metadata';
		}
		ksort( $rules );
		return $rules;
	}

	private function add_external_rule( string $regex, string $query ): void {
		global $wp_rewrite;
		if ( is_object( $wp_rewrite ) && method_exists( $wp_rewrite, 'add_external_rule' ) ) {
			$wp_rewrite->add_external_rule( $regex, $query );
		}
	}

	private function normalize_path( string $path ): string {
		$path = '/' . ltrim( trim( $path ), '/' );
		return '/' === $path || str_contains( $path, '{' ) ? '' : $path;
	}

	private function regex( string $path ): string {
		return '^' . preg_quote( ltrim( $path, '/' ), '#' ) . '$';
	}

	private function schema_hash(): string {
		return hash( 'sha256', '2|' . wp_json_encode( $this->rules() ) );
	}
}
