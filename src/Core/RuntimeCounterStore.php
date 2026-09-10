<?php
declare(strict_types=1);

namespace Cybermaps\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optional compact database backend for short-lived atomic counters.
 *
 * Lifecycle code may install the table and set READY_OPTION. Until then callers
 * receive null and retain their compatibility fallback.
 */
final class RuntimeCounterStore {
	public const READY_OPTION = 'cybermaps_runtime_counter_table_ready';

	/** Increment a bucket atomically, or return null when the table is unavailable. */
	public static function increment( string $bucket, int $ttl_seconds ): ?int {
		if ( ! \get_option( self::READY_OPTION, false ) ) {
			return null;
		}

		global $wpdb;
		if ( ! \is_object( $wpdb ) || ! \method_exists( $wpdb, 'query' ) || ! \method_exists( $wpdb, 'get_var' ) ) {
			return null;
		}

		$table   = self::table_name();
		$now     = \time();
		$expires = $now + max( 1, $ttl_seconds );
		$bucket  = \substr( $bucket, 0, 191 );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Runtime counters require an atomic plugin-table upsert.
		$result = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (bucket_key, counter_value, expires_at)
				VALUES (%s, 1, %d)
				ON DUPLICATE KEY UPDATE
					counter_value = IF(expires_at <= %d, 1, counter_value + 1),
					expires_at = IF(expires_at <= %d, %d, expires_at)',
				$table,
				$bucket,
				$expires,
				$now,
				$now,
				$expires
			)
		);
		if ( false === $result ) {
			return null;
		}
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT counter_value FROM %i WHERE bucket_key = %s',
				$table,
				$bucket
			)
		);
		// phpcs:enable

		return null === $count || false === $count ? null : max( 1, (int) $count );
	}

	/** Delete a bounded batch of expired buckets. */
	public static function cleanup( int $limit = 1000 ): int {
		if ( ! \get_option( self::READY_OPTION, false ) ) {
			return 0;
		}
		global $wpdb;
		if ( ! \is_object( $wpdb ) || ! \method_exists( $wpdb, 'query' ) ) {
			return 0;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded cleanup of the plugin-owned runtime table.
		$result = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE expires_at <= %d LIMIT %d',
				self::table_name(),
				\time(),
				max( 1, min( 10000, $limit ) )
			)
		);
		// phpcs:enable
		return false === $result ? 0 : max( 0, (int) $result );
	}

	/** Return dbDelta-compatible table SQL. */
	public static function schema_sql(): string {
		global $wpdb;
		$charset = \is_object( $wpdb ) && \method_exists( $wpdb, 'get_charset_collate' )
			? (string) $wpdb->get_charset_collate()
			: '';

		return 'CREATE TABLE ' . self::table_name() . " (
			bucket_key varchar(191) NOT NULL,
			counter_value bigint(20) unsigned NOT NULL DEFAULT 0,
			expires_at bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (bucket_key),
			KEY expires_at (expires_at)
		) {$charset};";
	}

	public static function table_name(): string {
		global $wpdb;
		$prefix = \is_object( $wpdb ) && isset( $wpdb->prefix ) ? (string) $wpdb->prefix : '';
		return $prefix . 'cybermaps_runtime_counters';
	}
}
