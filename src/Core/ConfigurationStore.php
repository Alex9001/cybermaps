<?php
declare(strict_types=1);

namespace Cybermaps\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shape-safe readers for Cybermaps' four runtime configuration roots.
 *
 * Sanitizers remain authoritative on write. These readers keep malformed or
 * legacy database values from leaking PHP warnings into public endpoints.
 */
final class ConfigurationStore {
	private static ?array $settings_memo  = null;
	private static ?array $discovery_memo = null;
	private static ?array $robots_memo    = null;
	private static ?array $identity_memo  = null;

	public static function register_hooks(): void {
		\add_action( 'updated_option', array( self::class, 'on_option_change' ), 10, 1 );
		\add_action( 'added_option', array( self::class, 'on_option_change' ), 10, 1 );
		\add_action( 'deleted_option', array( self::class, 'on_option_change' ), 10, 1 );
		\add_action( 'switch_blog', array( self::class, 'reset_memo' ), 10, 0 );
	}

	public static function on_option_change( string $option ): void {
		if (
			\in_array(
				$option,
				array(
					'cybermaps_settings',
					'cybermaps_discovery_center',
					'cybermaps_robots_manager',
					'cybermaps_identity_data',
				),
				true
			)
		) {
			self::reset_memo();
		}
	}

	public static function reset_memo(): void {
		self::$settings_memo  = null;
		self::$discovery_memo = null;
		self::$robots_memo    = null;
		self::$identity_memo  = null;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function settings(): array {
		if ( defined( 'CYBERMAPS_PHPUNIT' ) && CYBERMAPS_PHPUNIT ) {
			return self::array_option( 'cybermaps_settings' );
		}
		if ( null !== self::$settings_memo ) {
			return self::$settings_memo;
		}
		self::$settings_memo = self::array_option( 'cybermaps_settings' );
		return self::$settings_memo;
	}

	/**
	 * Read publication settings from stored bytes, refreshing their owning memo.
	 *
	 * Callers capture their generation before this observation and check it again
	 * before publishing. WordPress option caches are never consulted or written.
	 * Native pre-option, default-option and option filters retain their ordering.
	 */
	public static function publication_settings(): array {
		$stored              = self::publication_option( 'cybermaps_settings', array() );
		self::$settings_memo = is_array( $stored ) ? $stored : array();
		return self::$settings_memo;
	}

	/** Read current sitemap priority policy and refresh its owning request memo. */
	public static function publication_discovery(): array {
		$stored = self::publication_option( 'cybermaps_discovery_center', '' );
		if ( is_array( $stored ) ) {
			self::$discovery_memo = $stored;
		} else {
			$decoded              = is_string( $stored ) ? json_decode( $stored, true ) : null;
			self::$discovery_memo = is_array( $decoded ) ? $decoded : array();
		}
		return self::$discovery_memo;
	}

	/** Mirror native get_option hooks while bypassing every option-cache layer. */
	private static function publication_option( string $option, mixed $default_value ): mixed {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Preserve WordPress Core's native option filter contract.
		$pre = apply_filters( "pre_option_{$option}", false, $option, $default_value );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Preserve WordPress Core's native option filter contract.
		$pre = apply_filters( 'pre_option', $pre, $option, $default_value );
		return false !== $pre ? $pre : self::publication_stored_option( $option, $default_value );
	}

	/** Read exact bytes with native absent/present filters; SQL errors fail closed. */
	private static function publication_stored_option( string $option, mixed $default_value ): mixed {
		global $wpdb;
		if ( ! RawOptionStore::supported( $wpdb ) ) {
			if ( defined( 'CYBERMAPS_PHPUNIT' ) && CYBERMAPS_PHPUNIT ) {
				return get_option( $option, $default_value );
			}
			throw new BuildUnavailableException( esc_html__( 'Cybermaps could not read publication settings safely. Please retry shortly.', 'cybermaps' ) );
		}
		$wpdb->last_error = '';
		$raw              = RawOptionStore::read( $wpdb, $option );
		if ( false === $raw ) {
			throw new BuildUnavailableException( esc_html__( 'Cybermaps could not read publication settings safely. Please retry shortly.', 'cybermaps' ) );
		}
		if ( null === $raw ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Preserve WordPress Core's native option filter contract, including passed_default=true.
			return apply_filters( "default_option_{$option}", $default_value, $option, true );
		}
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Preserve WordPress Core's native option filter contract.
		return apply_filters( "option_{$option}", maybe_unserialize( $raw ), $option );
	}

	/**
	 * Discovery Center is stored as JSON but historical builds could leave an
	 * already-decoded array behind.
	 *
	 * @return array<string, mixed>
	 */
	public static function discovery(): array {
		if ( defined( 'CYBERMAPS_PHPUNIT' ) && CYBERMAPS_PHPUNIT ) {
			$stored = get_option( 'cybermaps_discovery_center', '' );
			if ( is_array( $stored ) ) {
				return $stored;
			}
			if ( ! is_string( $stored ) || '' === trim( $stored ) ) {
				return array();
			}
			$decoded = json_decode( $stored, true );
			return is_array( $decoded ) ? $decoded : array();
		}
		if ( null !== self::$discovery_memo ) {
			return self::$discovery_memo;
		}

		$stored = get_option( 'cybermaps_discovery_center', '' );
		if ( is_array( $stored ) ) {
			self::$discovery_memo = $stored;
			return self::$discovery_memo;
		}
		if ( ! is_string( $stored ) || '' === trim( $stored ) ) {
			self::$discovery_memo = array();
			return self::$discovery_memo;
		}

		$decoded              = json_decode( $stored, true );
		self::$discovery_memo = is_array( $decoded ) ? $decoded : array();
		return self::$discovery_memo;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function robots(): array {
		if ( defined( 'CYBERMAPS_PHPUNIT' ) && CYBERMAPS_PHPUNIT ) {
			$manager = self::array_option( 'cybermaps_robots_manager' );
			if ( isset( $manager['overrides'] ) && is_array( $manager['overrides'] ) ) {
				$manager['overrides'] = CrawlerRegistry::normalize_overrides( $manager['overrides'] );
			}
			return $manager;
		}
		if ( null !== self::$robots_memo ) {
			return self::$robots_memo;
		}

		$manager = self::array_option( 'cybermaps_robots_manager' );
		if ( isset( $manager['overrides'] ) && is_array( $manager['overrides'] ) ) {
			$manager['overrides'] = CrawlerRegistry::normalize_overrides( $manager['overrides'] );
		}

		self::$robots_memo = $manager;
		return self::$robots_memo;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function identity(): array {
		if ( defined( 'CYBERMAPS_PHPUNIT' ) && CYBERMAPS_PHPUNIT ) {
			return self::array_option( 'cybermaps_identity_data' );
		}
		if ( null !== self::$identity_memo ) {
			return self::$identity_memo;
		}
		self::$identity_memo = self::array_option( 'cybermaps_identity_data' );
		return self::$identity_memo;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function array_option( string $option ): array {
		$stored = get_option( $option, array() );
		return is_array( $stored ) ? $stored : array();
	}
}
