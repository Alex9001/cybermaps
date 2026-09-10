<?php
declare(strict_types=1);

namespace Cybermaps\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backend-neutral cache for derived Cybermaps values.
 *
 * Durable family generations are the correctness authority. WordPress object
 * caches, transients, and optional APCu are replaceable acceleration layers.
 */
final class CacheManager {
	private const INVENTORY_OPTION      = 'cybermaps_core_transient_inventory';
	private const GENERATION_PREFIX     = 'cybermaps_cache_generation_';
	private const CACHE_SCHEMA          = 2;
	private const MAX_INVENTORY_SIZE    = 2000;
	private const PART_BYTES            = 262144;
	private const SEGMENT_THRESHOLD     = 524288;
	private const DEFAULT_LEASE_SECONDS = 60;

	/** @var string[] */
	private const FAMILIES = array(
		'admin',
		'analytics',
		'chunks',
		'discovery',
		'legacy',
		'schema',
		'settings',
		'sitemap',
		'translations',
	);

	/** @var array<string,string> */
	private const FIXED_KEYS = array(
		'cybermaps_recent_logs_widget'       => 'analytics',
		'cybermaps_recent_logs_full'         => 'analytics',
		'cybermaps_log_cat_stats'            => 'analytics',
		'cybermaps_log_url_stats'            => 'analytics',
		'cybermaps_logs_kpis'                => 'analytics',
		'cybermaps_logs_top_endpoints'       => 'analytics',
		'cybermaps_logs_velocity'            => 'analytics',
		'cybermaps_analytics_overview_v1'    => 'analytics',
		'cybermaps_analytics_activity_v1'    => 'analytics',
		'cybermaps_analytics_overview_v2'    => 'analytics',
		'cybermaps_analytics_activity_v2'    => 'analytics',
		'cybermaps_endpoint_observations_v1' => 'analytics',
		'cybermaps_adp_manifest_v3_sync'     => 'discovery',
		'cybermaps_ai_manifest_v1'           => 'discovery',
		'cybermaps_tldr_cache'               => 'discovery',
		'cybermaps_ai_publication_inventory' => 'discovery',
		'cybermaps_rss_sitemap'              => 'sitemap',
		'cybermaps_archive_month_inventory'  => 'sitemap',
		'cybermaps_llms_cache'               => 'discovery',
		'cybermaps_llms_full_cache'          => 'discovery',
		'cybermaps_discovery_health'         => 'discovery',
		'cybermaps_logs_dirty'               => 'analytics',
		'cybermaps_logs_schema_repair'       => 'schema',
		'cybermaps_default_settings'         => 'settings',
		'cybermaps_kg_cache'                 => 'legacy',
		'cybermaps_health_stats'             => 'legacy',
		'cybermaps_moat_stats'               => 'legacy',
		'cybermaps_knowledge_saturation'     => 'legacy',
		'cybermaps_llms_yaml_cache'          => 'legacy',
		'cybermaps_llms_full_yaml_cache'     => 'legacy',
		'cybermaps_last_modified_fallback'   => 'legacy',
	);

	/** @var array<string,int> */
	private static array $generations = array();

	/** @var array<string,bool> */
	private static array $invalidated = array();

	/** @var array<string,bool> */
	private static array $apcu_request_keys = array();

	/** @var array<int,string> */
	private static array $site_namespaces = array();

	/**
	 * Compatibility writer for callers that still read the original transient.
	 * New derived-value code should use put(), get(), or remember().
	 *
	 * @param mixed $value Cached value.
	 */
	public static function set( string $key, $value, int $expiration, string $family ): bool {
		$family     = self::normalize_family( $family );
		$generation = self::get_generation( $family );
		$stored     = self::set_if_current( $key, $value, $expiration, $family, $generation );
		$legacy     = self::write_legacy_if_current( $key, $value, $expiration, $family, $generation );

		if ( $legacy && ! isset( self::FIXED_KEYS[ $key ] ) ) {
			self::remember_legacy_key( $key, $family, $expiration );
		}

		return $stored || $legacy;
	}

	/**
	 * Store a generation-fenced value without a legacy transient duplicate.
	 *
	 * @param mixed $value Cached value.
	 */
	public static function put( string $key, $value, int $expiration, string $family ): bool {
		$family     = self::normalize_family( $family );
		$generation = self::get_generation( $family );
		unset( self::$invalidated[ self::runtime_family_key( $family ) ] );

		return self::set_if_current( $key, $value, $expiration, $family, $generation );
	}

	/**
	 * Publish only while the generation captured before the build is current.
	 *
	 * @param mixed $value Cached value.
	 */
	public static function set_if_current(
		string $key,
		$value,
		int $expiration,
		string $family,
		int $generation
	): bool {
		$family = self::normalize_family( $family );
		if ( self::get_generation( $family, true ) !== $generation ) {
			return false;
		}

		$backend_key = self::backend_key( $key, $family, $generation );
		$wrapped     = array(
			'cybermaps_cache' => self::CACHE_SCHEMA,
			'value'           => $value,
		);
		$stored      = self::store_backend( $backend_key, $wrapped, $expiration, $family );
		if ( $stored ) {
			self::apcu_store( $backend_key, $wrapped, $expiration, $family );
			unset( self::$invalidated[ self::runtime_family_key( $family ) ] );
		}

		return $stored;
	}

	/**
	 * Generation-fenced writer that also refreshes a fixed legacy transient.
	 *
	 * This is limited to fixed compatibility keys whose exact deletion remains
	 * guaranteed by clear_family().
	 *
	 * @param mixed $value Cached value.
	 */
	public static function set_compatible_if_current(
		string $key,
		$value,
		int $expiration,
		string $family,
		int $generation
	): bool {
		if ( ! isset( self::FIXED_KEYS[ $key ] ) || self::FIXED_KEYS[ $key ] !== $family ) {
			return self::set_if_current( $key, $value, $expiration, $family, $generation );
		}
		if ( ! self::set_if_current( $key, $value, $expiration, $family, $generation ) ) {
			self::delete_matching_legacy_value( $key, $value );
			return false;
		}
		return self::write_legacy_if_current( $key, $value, $expiration, $family, $generation );
	}

	/**
	 * Read a generation-scoped value and distinguish false/null from a miss.
	 *
	 * @param bool|null $found Set to true only for a complete valid payload.
	 * @return mixed
	 */
	public static function get( string $key, string $family, ?bool &$found = null ) {
		$family      = self::normalize_family( $family );
		$backend_key = self::backend_key( $key, $family, self::get_generation( $family ) );
		$found       = false;
		$wrapped     = isset( self::$apcu_request_keys[ $backend_key ] )
			? self::apcu_get( $backend_key, $family, $apcu_found )
			: false;
		$apcu_found  = isset( $apcu_found ) && $apcu_found;

		if ( ! $apcu_found ) {
			$wrapped = self::load_backend( $backend_key, $family, $backend_found );
			if ( ! $backend_found ) {
				if ( isset( self::FIXED_KEYS[ $key ] ) && self::FIXED_KEYS[ $key ] === $family ) {
					$legacy = \get_transient( $key );
					if ( false !== $legacy ) {
						$found = true;
						return $legacy;
					}
				}
				return false;
			}
		}

		if (
			! \is_array( $wrapped )
			|| self::CACHE_SCHEMA !== (int) ( $wrapped['cybermaps_cache'] ?? 0 )
			|| ! \array_key_exists( 'value', $wrapped )
		) {
			return false;
		}

		$found = true;
		if ( ! $apcu_found ) {
			self::apcu_store( $backend_key, $wrapped, HOUR_IN_SECONDS, $family );
		}
		return $wrapped['value'];
	}

	/**
	 * Build one value behind a bounded backend-neutral fill lease.
	 *
	 * @param callable():mixed          $producer  Value producer.
	 * @param callable(mixed):bool|null $cacheable Optional admission predicate.
	 * @return mixed
	 */
	public static function remember(
		string $key,
		int $expiration,
		string $family,
		callable $producer,
		?callable $cacheable = null,
		int $lease_seconds = self::DEFAULT_LEASE_SECONDS,
		bool $skip_cache = false
	) {
		$family = self::normalize_family( $family );
		if ( ! $skip_cache ) {
			$cached = self::get( $key, $family, $found );
			if ( $found ) {
				return $cached;
			}
		}

		$name = 'cybermaps_cache_fill_' . substr( hash( 'sha256', self::site_id() . '|' . $family . '|' . $key ), 0, 40 );
		return CacheFill::run(
			$name,
			$lease_seconds,
			static function () use ( $key, $expiration, $family, $producer, $cacheable, $skip_cache ) {
				// Another owner may have filled the cache before we acquired the lock.
				if ( ! $skip_cache ) {
					$cached = self::get( $key, $family, $found );
					if ( $found ) {
						return $cached;
					}
				}
				$generation = self::get_generation( $family, true );
				$value      = $producer();
				CacheFill::heartbeat( true );
				if ( ! $skip_cache && ( null === $cacheable || $cacheable( $value ) ) ) {
					self::set_if_current( $key, $value, $expiration, $family, $generation );
				}
				return $value;
			}
		);
	}

	/**
	 * Atomically claim a short-lived operational guard.
	 *
	 * Unlike fill leases this key is intentionally independent of a cache
	 * generation, so an invalidation performed by the claimant cannot make the
	 * guard immediately claimable again.
	 */
	public static function claim( string $key, int $expiration, string $scope = 'runtime' ): bool {
		$scope = self::normalize_family( $scope );
		$guard = 'guard:' . \substr(
			\hash( 'sha256', self::site_id() . '|' . $scope . '|' . $key ),
			0,
			40
		);
		return self::acquire_lease( $guard, $scope, max( 1, $expiration ) );
	}

	/** Return the durable generation for one family. */
	public static function get_generation( string $family, bool $refresh = false ): int {
		$family      = self::normalize_family( $family );
		$runtime_key = self::runtime_family_key( $family );
		if ( ! $refresh && isset( self::$generations[ $runtime_key ] ) ) {
			return self::$generations[ $runtime_key ];
		}

		$generation = max( 0, AtomicOptionSequence::current( self::generation_option( $family ) ) );

		self::$generations[ $runtime_key ] = $generation;
		return $generation;
	}

	/**
	 * Invalidate one family once in the request and notify edge adapters.
	 */
	public static function clear_family( string $family ): int {
		$family      = self::normalize_family( $family );
		$runtime_key = self::runtime_family_key( $family );
		$generation  = null;
		if ( ! isset( self::$invalidated[ $runtime_key ] ) ) {
			$next_generation = AtomicOptionSequence::increment( self::generation_option( $family ) );
			if ( $next_generation < 1 ) {
				self::$generations[ $runtime_key ] = self::get_generation( $family, true );
				\do_action( 'cybermaps_cache_family_invalidation_failed', $family );
			} else {
				self::$generations[ $runtime_key ] = $next_generation;
				self::$invalidated[ $runtime_key ] = true;
				$generation                        = $next_generation;
			}
		}

		$count = 0;

		foreach ( self::FIXED_KEYS as $key => $key_family ) {
			if ( $family === $key_family ) {
				\delete_transient( $key );
				++$count;
			}
		}

		$inventory = self::get_legacy_inventory( $family );
		foreach ( $inventory as $key => $record ) {
			if ( (string) ( $record['family'] ?? '' ) !== $family ) {
				continue;
			}
			\delete_transient( $key );
			unset( $inventory[ $key ] );
			++$count;
		}
		self::store_legacy_inventory( $inventory, $family );

		if ( null !== $generation ) {
			/** Fires after a site-local cache family generation advances. */
			\do_action( 'cybermaps_cache_family_invalidated', $family, $generation );
		}
		return $count;
	}

	/** Clear Core families without flushing unrelated WordPress caches. */
	public static function clear_all(): int {
		self::$invalidated = array();

		$count = 0;
		foreach ( self::FAMILIES as $family ) {
			$count += self::clear_family( $family );
		}
		\delete_option( self::INVENTORY_OPTION );
		return $count;
	}

	/** @return array<string,mixed> */
	public static function capability_profile(): array {
		$external = self::uses_external_cache();
		return array(
			'backend'            => $external ? 'wordpress-object-cache' : 'wordpress-transients',
			'external'           => $external,
			'site_local_groups'  => true,
			'generation_fenced'  => true,
			'atomic_add'         => \function_exists( 'wp_cache_add' ),
			'increment'          => \function_exists( 'wp_cache_incr' ),
			'flush_group'        => self::supports( 'flush_group' ),
			'flush_runtime'      => self::supports( 'flush_runtime' ),
			'get_multiple'       => self::supports( 'get_multiple' ),
			'set_multiple'       => self::supports( 'set_multiple' ),
			'delete_multiple'    => self::supports( 'delete_multiple' ),
			'apcu_available'     => self::is_apcu_available(),
			'apcu_l1'            => self::apcu_available(),
			'compression'        => \function_exists( 'gzcompress' ) && \function_exists( 'gzuncompress' ),
			'part_bytes'         => self::PART_BYTES,
			'inventory_database' => ! $external,
		);
	}

	/** Reset request-local memoization for tests and long-running workers. */
	public static function reset_runtime(): void {
		self::$generations       = array();
		self::$invalidated       = array();
		self::$apcu_request_keys = array();
		self::$site_namespaces   = array();
	}

	private static function store_backend( string $key, array $wrapped, int $expiration, string $family ): bool {
		$serialized = \serialize( $wrapped ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Cache values may include trusted WordPress value objects and are checksummed when segmented.
		if (
			\strlen( $serialized ) <= self::SEGMENT_THRESHOLD
			&& self::backend_set( $key, $wrapped, $expiration, $family )
		) {
			return true;
		}

		$compressed = false;
		$payload    = $serialized;
		if ( \function_exists( 'gzcompress' ) ) {
			$candidate = \gzcompress( $serialized, 6 );
			if ( false !== $candidate && \strlen( $candidate ) < \strlen( $serialized ) ) {
				$payload    = $candidate;
				$compressed = true;
			}
		}

		$parts = \str_split( $payload, self::PART_BYTES );
		foreach ( $parts as $index => $part ) {
			if ( ! self::backend_set( $key . ':p:' . $index, $part, $expiration, $family ) ) {
				self::delete_parts( $key, $family, $index );
				return false;
			}
		}

		$manifest = array(
			'cybermaps_cache' => self::CACHE_SCHEMA,
			'format'          => 'parts',
			'parts'           => \count( $parts ),
			'bytes'           => \strlen( $payload ),
			'compressed'      => $compressed,
			'checksum'        => \hash( 'sha256', $payload ),
		);
		if ( ! self::backend_set( $key, $manifest, $expiration, $family ) ) {
			self::delete_parts( $key, $family, \count( $parts ) );
			return false;
		}

		return true;
	}

	/** @return mixed */
	private static function load_backend( string $key, string $family, ?bool &$found ) {
		$value = self::backend_get( $key, $family, $found );
		if ( ! $found ) {
			return false;
		}
		if ( ! \is_array( $value ) || 'parts' !== (string) ( $value['format'] ?? '' ) ) {
			return $value;
		}

		return self::load_segmented_backend( $key, $family, $value, $found );
	}

	/** @param array<string,mixed> $manifest @return mixed */
	private static function load_segmented_backend( string $key, string $family, array $manifest, ?bool &$found ) {
		$count   = max( 0, min( 256, (int) ( $manifest['parts'] ?? 0 ) ) );
		$payload = self::segmented_payload( $key, $family, $count );
		if ( null === $payload || ! self::valid_segmented_payload( $payload, $manifest ) ) {
			$found = false;
			return false;
		}
		$payload = self::uncompressed_payload( $payload, ! empty( $manifest['compressed'] ) );
		$decoded = is_string( $payload ) ? @\unserialize( $payload, array( 'allowed_classes' => false ) ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize,WordPress.PHP.NoSilencedErrors.Discouraged -- Checksummed plugin-owned cache payload; a decode warning is treated as a cache miss.
		if ( ! \is_array( $decoded ) ) {
			$found = false;
			return false;
		}
		return $decoded;
	}

	private static function segmented_payload( string $key, string $family, int $count ): ?string {
		if ( 0 === $count ) {
			return null;
		}
		$payload = '';
		for ( $index = 0; $index < $count; ++$index ) {
			$part = self::backend_get( $key . ':p:' . $index, $family, $part_found );
			if ( ! $part_found || ! \is_string( $part ) ) {
				return null;
			}
			$payload .= $part;
		}
		return $payload;
	}

	private static function valid_segmented_payload( string $payload, array $manifest ): bool {
		return \strlen( $payload ) === (int) ( $manifest['bytes'] ?? -1 ) && \hash_equals( (string) ( $manifest['checksum'] ?? '' ), \hash( 'sha256', $payload ) );
	}

	private static function uncompressed_payload( string $payload, bool $compressed ): string|false {
		return $compressed && \function_exists( 'gzuncompress' ) ? \gzuncompress( $payload ) : $payload;
	}

	/** @param mixed $value */
	private static function backend_set( string $key, $value, int $expiration, string $family ): bool {
		if ( self::uses_external_cache() && \function_exists( 'wp_cache_set' ) ) {
			return (bool) \wp_cache_set( $key, $value, self::group( $family ), max( 0, $expiration ) );
		}
		return \set_transient( $key, $value, max( 0, $expiration ) );
	}

	/** @return mixed */
	private static function backend_get( string $key, string $family, ?bool &$found ) {
		if ( self::uses_external_cache() && \function_exists( 'wp_cache_get' ) ) {
			$cache_found = false;
			$value       = \wp_cache_get( $key, self::group( $family ), false, $cache_found );
			$found       = (bool) $cache_found;
			return $value;
		}
		$value = \get_transient( $key );
		$found = false !== $value;
		return $value;
	}

	/**
	 * @param array<string,mixed>|null $lease Acquired lease metadata.
	 */
	private static function acquire_lease(
		string $key,
		string $family,
		int $seconds,
		?array &$lease = null
	): bool {
		$token = \hash( 'sha256', $key . '|' . \microtime( true ) . '|' . \wp_generate_password( 32, false, false ) );
		if ( self::uses_external_cache() && \function_exists( 'wp_cache_add' ) ) {
			$acquired = (bool) \wp_cache_add( $key, $token, self::group( $family ) . '_locks', $seconds );
			if ( $acquired ) {
				$lease = array( 'backend' => 'object-cache' );
			}
			return $acquired;
		}

		$option  = 'cybermaps_cache_lease_' . \substr( \hash( 'sha256', self::site_id() . '|' . $key ), 0, 40 );
		$current = \get_option( $option, 0 );
		$expires = \is_array( $current )
			? (int) ( $current['expires'] ?? 0 )
			: (int) $current;
		if ( $expires > 0 && $expires <= \time() ) {
			\delete_option( $option );
		}
		$value    = array(
			'token'   => $token,
			'expires' => \time() + $seconds,
		);
		$acquired = \add_option( $option, $value, '', false );
		if ( $acquired ) {
			$lease = array(
				'backend' => 'option',
				'option'  => $option,
				'token'   => $token,
				'value'   => $value,
			);
		}
		return $acquired;
	}

	private static function delete_parts( string $key, string $family, int $count ): void {
		for ( $index = 0; $index < $count; ++$index ) {
			if ( self::uses_external_cache() && \function_exists( 'wp_cache_delete' ) ) {
				\wp_cache_delete( $key . ':p:' . $index, self::group( $family ) );
			} else {
				\delete_transient( $key . ':p:' . $index );
			}
		}
	}

	private static function remember_legacy_key( string $key, string $family, int $expiration ): void {
		if ( self::uses_external_cache() ) {
			self::remember_external_legacy_key( $key, $family, $expiration );
			return;
		}
		self::remember_option_legacy_key( $key, $family, $expiration );
	}

	private static function remember_external_legacy_key( string $key, string $family, int $expiration ): void {
		$inventory_key     = 'legacy_inventory_' . $family;
		$inventory         = \function_exists( 'wp_cache_get' ) ? \wp_cache_get( $inventory_key, 'cybermaps_cache_inventory' ) : array();
		$inventory         = \is_array( $inventory ) ? $inventory : array();
		$inventory[ $key ] = array(
			'family'  => $family,
			'expires' => \time() + max( 0, $expiration ),
		);
		if ( \count( $inventory ) > self::MAX_INVENTORY_SIZE ) {
			$inventory = \array_slice( $inventory, -self::MAX_INVENTORY_SIZE, null, true );
		}
		if ( \function_exists( 'wp_cache_set' ) ) {
			\wp_cache_set( $inventory_key, $inventory, 'cybermaps_cache_inventory', max( DAY_IN_SECONDS, $expiration ) );
		}
	}

	private static function remember_option_legacy_key( string $key, string $family, int $expiration ): void {
		$inventory = self::get_legacy_inventory( $family );
		$now       = \time();
		foreach ( $inventory as $owned_key => $record ) {
			if ( (int) ( $record['expires'] ?? 0 ) > 0 && (int) $record['expires'] <= $now ) {
				unset( $inventory[ $owned_key ] );
			}
		}
		$inventory[ $key ] = array(
			'family'  => $family,
			'expires' => $expiration > 0 ? $now + $expiration : 0,
		);
		if ( \count( $inventory ) > self::MAX_INVENTORY_SIZE ) {
			\uasort(
				$inventory,
				static fn ( array $a, array $b ): int =>
					(int) ( $a['expires'] ?? 0 ) <=> (int) ( $b['expires'] ?? 0 )
			);
			$retained = \array_slice( $inventory, -self::MAX_INVENTORY_SIZE, null, true );
			foreach ( \array_keys( \array_diff_key( $inventory, $retained ) ) as $evicted_key ) {
				\delete_transient( $evicted_key );
			}
			$inventory = $retained;
		}
		self::store_legacy_inventory( $inventory, $family );
	}

	/** @return array<string,array{family:string,expires:int}> */
	private static function get_legacy_inventory( string $family ): array {
		if ( self::uses_external_cache() && \function_exists( 'wp_cache_get' ) ) {
			$value = \wp_cache_get( 'legacy_inventory_' . $family, 'cybermaps_cache_inventory' );
			return \is_array( $value ) ? $value : array();
		}
		$value = \get_option( self::INVENTORY_OPTION, array() );
		return \is_array( $value ) ? $value : array();
	}

	/** @param array<string,array{family:string,expires:int}> $inventory */
	private static function store_legacy_inventory( array $inventory, string $family ): void {
		if ( self::uses_external_cache() ) {
			if ( \function_exists( 'wp_cache_set' ) ) {
				\wp_cache_set( 'legacy_inventory_' . $family, $inventory, 'cybermaps_cache_inventory', DAY_IN_SECONDS );
			}
			return;
		}
		if ( empty( $inventory ) ) {
			\delete_option( self::INVENTORY_OPTION );
			return;
		}
		\update_option( self::INVENTORY_OPTION, $inventory, false );
	}

	private static function apcu_store( string $key, array $wrapped, int $expiration, string $family ): void {
		if (
			self::apcu_available()
			&& \in_array( $family, array( 'chunks', 'discovery', 'sitemap' ), true )
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Size admission only; the value remains a trusted derived cache object.
			&& \strlen( \serialize( $wrapped ) ) <= 1048576
		) {
			\apcu_store( self::apcu_key( $key ), $wrapped, max( 1, $expiration ) );
			self::$apcu_request_keys[ $key ] = true;
		}
	}

	/** @return mixed */
	private static function apcu_get( string $key, string $family, ?bool &$found ) {
		$found = false;
		if (
			! self::apcu_available()
			|| ! \in_array( $family, array( 'chunks', 'discovery', 'sitemap' ), true )
		) {
			return false;
		}
		return \apcu_fetch( self::apcu_key( $key ), $found );
	}

	public static function is_apcu_available(): bool {
		if ( ! \function_exists( 'apcu_fetch' ) || ! \function_exists( 'apcu_store' ) ) {
			return false;
		}
		return ! \function_exists( 'apcu_enabled' ) || \apcu_enabled();
	}

	public static function is_apcu_enabled(): bool {
		$settings = ConfigurationStore::settings();
		return ! isset( $settings['enable_apcu_l1_cache'] ) || ! empty( $settings['enable_apcu_l1_cache'] );
	}

	private static function apcu_available(): bool {
		return self::is_apcu_enabled() && self::is_apcu_available();
	}

	private static function apcu_key( string $key ): string {
		return 'cybermaps:' . self::site_id() . ':' . $key;
	}

	private static function backend_key( string $key, string $family, int $generation ): string {
		return 'v' . self::CACHE_SCHEMA . ':g' . $generation . ':'
			. \substr( \hash( 'sha256', self::site_id() . '|' . $family . '|' . $key ), 0, 40 );
	}

	private static function generation_option( string $family ): string {
		return self::GENERATION_PREFIX . $family;
	}

	private static function group( string $family ): string {
		return 'cybermaps_' . $family;
	}

	private static function normalize_family( string $family ): string {
		$family = \sanitize_key( $family );
		return '' !== $family ? $family : 'discovery';
	}

	private static function site_id(): string {
		$blog_id = \function_exists( 'get_current_blog_id' ) ? max( 1, (int) \get_current_blog_id() ) : 1;
		if ( isset( self::$site_namespaces[ $blog_id ] ) ) {
			return self::$site_namespaces[ $blog_id ];
		}

		$identity = (string) \get_option( 'siteurl', '' );
		if ( '' === $identity && \defined( 'ABSPATH' ) ) {
			$identity = (string) ABSPATH;
		}
		$salt = \defined( 'WP_CACHE_KEY_SALT' ) ? (string) WP_CACHE_KEY_SALT : '';

		self::$site_namespaces[ $blog_id ] = $blog_id . ':' . \substr(
			\hash( 'sha256', $salt . '|' . \strtolower( \rtrim( $identity, '/' ) ) ),
			0,
			20
		);

		return self::$site_namespaces[ $blog_id ];
	}

	private static function runtime_family_key( string $family ): string {
		return self::site_id() . ':' . $family;
	}

	private static function uses_external_cache(): bool {
		return \function_exists( 'wp_using_ext_object_cache' ) && \wp_using_ext_object_cache();
	}

	private static function supports( string $feature ): bool {
		return \function_exists( 'wp_cache_supports' ) && \wp_cache_supports( $feature );
	}

	/**
	 * Write a compatibility transient only while its captured generation remains current.
	 *
	 * @param mixed $value Cached value.
	 */
	private static function write_legacy_if_current(
		string $key,
		$value,
		int $expiration,
		string $family,
		int $generation
	): bool {
		if ( self::get_generation( $family, true ) !== $generation ) {
			self::delete_matching_legacy_value( $key, $value );
			return false;
		}

		$stored = \set_transient( $key, $value, $expiration );
		if ( self::get_generation( $family, true ) !== $generation ) {
			self::delete_matching_legacy_value( $key, $value );
			return false;
		}
		return $stored;
	}

	/** @param mixed $value Expected legacy value. */
	private static function delete_matching_legacy_value( string $key, $value ): void {
		if ( \get_transient( $key ) === $value ) {
			\delete_transient( $key );
		}
	}
}
