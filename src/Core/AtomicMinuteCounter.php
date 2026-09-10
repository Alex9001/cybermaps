<?php
declare(strict_types=1);

namespace Cybermaps\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared atomic per-minute counter with production-safe backends.
 */
final class AtomicMinuteCounter {
	public const UNRESOLVED_CLIENT_BUCKET = 'unresolved_client';

	/**
	 * Increment one minute bucket and return the new count.
	 */
	public static function increment( string $cache_key, int $ttl_seconds = 70 ): int {
		$ttl_seconds  = max( 1, $ttl_seconds );
		$cached_count = self::increment_object_cache( $cache_key, $ttl_seconds );
		if ( null !== $cached_count ) {
			return $cached_count;
		}

		$table_count = RuntimeCounterStore::increment( $cache_key, $ttl_seconds );
		if ( null !== $table_count ) {
			return $table_count;
		}

		$table_count = self::increment_transient_option( $cache_key, $ttl_seconds );
		if ( null !== $table_count ) {
			return $table_count;
		}

		$current = max( 0, (int) \get_transient( $cache_key ) ) + 1;
		\set_transient( $cache_key, $current, $ttl_seconds );
		return $current;
	}

	private static function increment_object_cache( string $cache_key, int $ttl_seconds ): ?int {
		if ( ! \function_exists( 'wp_using_ext_object_cache' ) || ! \wp_using_ext_object_cache() || ! \function_exists( 'wp_cache_add' ) || ! \function_exists( 'wp_cache_incr' ) ) {
			return null;
		}
		$group = 'cybermaps_atomic_counter';
		if ( \wp_cache_add( $cache_key, 1, $group, $ttl_seconds ) ) {
			return 1;
		}
		$count = \wp_cache_incr( $cache_key, 1, $group );
		return false === $count ? ( \wp_cache_add( $cache_key, 1, $group, $ttl_seconds ) ? 1 : null ) : max( 1, (int) $count );
	}

	private static function increment_transient_option( string $cache_key, int $ttl_seconds ): ?int {
		global $wpdb;
		if ( ! ( \class_exists( '\\wpdb', false ) && $wpdb instanceof \wpdb && ! empty( $wpdb->options ) ) ) {
			return null;
		}
		$data_option    = '_transient_' . $cache_key;
		$timeout_option = '_transient_timeout_' . $cache_key;
		$now            = \time();
		$timeout        = (int) \get_option( $timeout_option, 0 );
		if ( $timeout <= $now ) {
			\delete_option( $data_option );
			\delete_option( $timeout_option );
		}
		if ( \add_option( $data_option, 1, '', false ) ) {
			\add_option( $timeout_option, $now + $ttl_seconds, '', false );
			return 1;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic increment cannot use the non-atomic Transients API.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i data
				INNER JOIN %i timeout
					ON timeout.option_name = %s
				SET data.option_value = CAST(data.option_value AS UNSIGNED) + 1
				WHERE data.option_name = %s
					AND CAST(timeout.option_value AS UNSIGNED) > %d',
				$wpdb->options,
				$wpdb->options,
				$timeout_option,
				$data_option,
				$now
			)
		);
		$count   = false === $updated || $updated < 1
			? null
			: $wpdb->get_var(
				$wpdb->prepare(
					'SELECT option_value FROM %i WHERE option_name = %s',
					$wpdb->options,
					$data_option
				)
			);
		// phpcs:enable
		if ( null !== $count && false !== $count ) {
			if ( \function_exists( 'wp_cache_delete' ) ) {
				\wp_cache_delete( $data_option, 'options' );
				\wp_cache_delete( $timeout_option, 'options' );
			}
			return max( 1, (int) $count );
		}
		return null;
	}

	/**
	 * Build a salted requester bucket for rate limiting and backpressure.
	 */
	public static function requester_bucket( string $counter_namespace, string $scope, string $requester_material ): string {
		$material = trim( $requester_material );
		if ( '' === $material ) {
			$material = self::UNRESOLVED_CLIENT_BUCKET;
		}

		$hash   = \function_exists( 'wp_hash' )
			? substr( \wp_hash( $counter_namespace . '|' . $scope . '|' . $material, 'nonce', 'sha256' ), 0, 24 )
			: substr( hash( 'sha256', $counter_namespace . '|' . $scope . '|' . $material ), 0, 24 );
		$minute = (int) floor( time() / 60 );

		return \sanitize_key( $counter_namespace ) . '_' . \sanitize_key( $scope ) . '_' . $hash . '_' . $minute;
	}
}
