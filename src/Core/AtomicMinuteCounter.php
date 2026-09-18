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
