<?php
declare(strict_types=1);

namespace Cybermaps\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Monotonic, production-atomic sequence stored in a non-autoloaded option.
 */
final class AtomicOptionSequence {
	/**
	 * Read a sequence without trusting this request's option-cache snapshot.
	 */
	public static function current( string $option_name ): int {
		if ( '' === $option_name ) {
			return 0;
		}

		global $wpdb;
		if (
			\is_object( $wpdb )
			&& isset( $wpdb->options )
			&& \is_string( $wpdb->options )
			&& \method_exists( $wpdb, 'prepare' )
			&& \method_exists( $wpdb, 'get_var' )
		) {
			$value = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1',
					$wpdb->options,
					$option_name
				)
			);
			if ( false === $value || ( isset( $wpdb->last_error ) && '' !== (string) $wpdb->last_error ) ) {
				return -1;
			}

			return null === $value ? 0 : self::parse_value( $value );
		}

		return self::parse_value( \get_option( $option_name, 0 ) );
	}

	/**
	 * Increment an internal sequence and return the observed value.
	 *
	 * WordPress always provides wpdb in production. The Settings API fallback is
	 * retained for isolated tooling and test stubs that do not bootstrap wpdb.
	 */
	public static function increment( string $option_name ): int {
		if ( '' === $option_name ) {
			return 0;
		}

		global $wpdb;
		if (
			\is_object( $wpdb )
			&& isset( $wpdb->options )
			&& \is_string( $wpdb->options )
			&& \method_exists( $wpdb, 'prepare' )
			&& \method_exists( $wpdb, 'query' )
			&& \method_exists( $wpdb, 'get_var' )
		) {
			// Do not depend on version-specific add_option() insert/upsert semantics
			// for the insert half of an atomic increment: a stale caller must never
			// replace an already-advanced value with 1. Keep both the missing-row and
			// existing-row paths in one database expression.
			$changed = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE option_value = IF(option_value REGEXP '^(0|[1-9][0-9]*)$', CAST(option_value AS UNSIGNED) + 1, option_value)",
					$wpdb->options,
					$option_name,
					'1',
					'off'
				)
			);
			if ( false === $changed ) {
				return 0;
			}

			self::invalidate_option_cache( $option_name );
			$value = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1',
					$wpdb->options,
					$option_name
				)
			);

			// A concurrent increment may make the observed value newer than the one
			// allocated by this request. That remains a valid monotonic fence.
			if ( false === $value || ( isset( $wpdb->last_error ) && '' !== (string) $wpdb->last_error ) ) {
				return 0;
			}
			$parsed = null === $value ? -1 : self::parse_value( $value );
			return $parsed > 0 ? $parsed : 0;
		}

		// Isolated docs tooling and unit stubs do not always bootstrap wpdb.
		// This path is deliberately not presented as production-atomic.
		if ( \add_option( $option_name, 1, '', false ) ) {
			return 1;
		}
		$current = self::parse_value( \get_option( $option_name, 0 ) );
		if ( $current < 0 ) {
			return 0;
		}
		$next = $current + 1;
		\update_option( $option_name, $next, false );
		$stored = self::parse_value( \get_option( $option_name, 0 ) );

		return $stored >= $next ? $stored : 0;
	}

	/**
	 * Advance a sequence to at least the supplied value without allowing an older
	 * request to move it backwards.
	 *
	 * @return int The observed value, or -1 when persistence could not be proven.
	 */
	public static function advance_to( string $option_name, int $minimum ): int {
		if ( '' === $option_name || $minimum < 0 ) {
			return -1;
		}

		global $wpdb;
		if (
			\is_object( $wpdb )
			&& isset( $wpdb->options )
			&& \is_string( $wpdb->options )
			&& \method_exists( $wpdb, 'prepare' )
			&& \method_exists( $wpdb, 'query' )
			&& \method_exists( $wpdb, 'get_var' )
		) {
			$changed = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE option_value = IF(option_value REGEXP '^(0|[1-9][0-9]*)$', GREATEST(CAST(option_value AS UNSIGNED), CAST(%s AS UNSIGNED)), option_value)",
					$wpdb->options,
					$option_name,
					(string) $minimum,
					'off',
					(string) $minimum
				)
			);
			if ( false === $changed ) {
				return -1;
			}

			self::invalidate_option_cache( $option_name );
			return self::current( $option_name );
		}

		$current = self::current( $option_name );
		if ( $current < 0 ) {
			return -1;
		}
		if ( $current >= $minimum ) {
			return $current;
		}
		\update_option( $option_name, $minimum, false );
		$stored = self::current( $option_name );

		return $stored >= $minimum ? $stored : -1;
	}

	private static function parse_value( mixed $value ): int {
		if ( \is_int( $value ) ) {
			return $value >= 0 ? $value : -1;
		}
		if ( ! \is_string( $value ) || 1 !== \preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ) {
			return -1;
		}
		$parsed = \filter_var( $value, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 0 ) ) );
		return false === $parsed ? -1 : (int) $parsed;
	}

	private static function invalidate_option_cache( string $option_name ): void {
		if ( ! \function_exists( 'wp_cache_delete' ) ) {
			return;
		}

		\wp_cache_delete( $option_name, 'options' );
		\wp_cache_delete( 'notoptions', 'options' );
		// A pre-existing installation may have created this option as autoloaded.
		\wp_cache_delete( 'alloptions', 'options' );
	}
}
