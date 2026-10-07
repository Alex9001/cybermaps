<?php
declare(strict_types=1);

namespace Cybermaps\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Internal exact-byte option primitives. No option filters or cache reads. */
final class RawOptionStore {
	public static function supported( mixed $database ): bool {
		return is_object( $database ) && isset( $database->options ) && is_string( $database->options )
			&& method_exists( $database, 'prepare' ) && method_exists( $database, 'get_var' ) && method_exists( $database, 'query' );
	}

	/** @return string|null|false Exact stored bytes, absent row, or failure. */
	public static function read( mixed $wpdb, string $option ): string|null|false {
		if ( ! self::supported( $wpdb ) ) {
			return false;
		}
		$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', $wpdb->options, $option ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( false === $raw || ! empty( $wpdb->last_error ) ) {
			return false;
		}
		return null === $raw ? self::null_scalar_value( $wpdb ) : ( is_string( $raw ) ? $raw : false );
	}

	/**
	 * Native wpdb::get_var() collapses an existing empty string to null. Its
	 * same-query result rows retain exact bytes; inspecting them avoids a second
	 * observation that could race the first. SQL NULL/malformed rows fail closed.
	 */
	private static function null_scalar_value( mixed $wpdb ): string|null|false {
		$row = $wpdb->last_result[0] ?? null;
		if ( null === $row ) {
			return null;
		}
		return is_object( $row ) && is_string( $row->option_value ?? null ) ? $row->option_value : false;
	}

	/** Replace only the observed bytes, including case and trailing spaces. */
	public static function replace( mixed $wpdb, string $option, string $expected, string $next, ?array $fence = null ): int|false {
		if ( ! self::supported( $wpdb ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s AND (%d = 0 OR (IS_USED_LOCK(%s) = %d AND CONNECTION_ID() = %d))',
				$wpdb->options,
				$next,
				$option,
				$expected,
				null === $fence ? 0 : 1,
				$fence['name'] ?? '',
				$fence['connection_id'] ?? 0,
				$fence['connection_id'] ?? 0
			)
		);
		return self::result( $wpdb, $result );
	}

	/** An absent-row write is insert-only; a competing insert is a conflict. */
	public static function insert( mixed $wpdb, string $option, string $next, ?array $fence = null ): int|false {
		if ( ! self::supported( $wpdb ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO %i (option_name, option_value, autoload) SELECT %s, %s, %s WHERE (%d = 0 OR (IS_USED_LOCK(%s) = %d AND CONNECTION_ID() = %d))',
				$wpdb->options,
				$option,
				$next,
				'off',
				null === $fence ? 0 : 1,
				$fence['name'] ?? '',
				$fence['connection_id'] ?? 0,
				$fence['connection_id'] ?? 0
			)
		);
		return self::result( $wpdb, $result );
	}

	/** Delete only bytes owned by the caller's observation. */
	public static function remove( mixed $wpdb, string $option, string $expected, ?array $fence = null ): int|false {
		if ( ! self::supported( $wpdb ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name = %s AND BINARY option_value = BINARY %s AND (%d = 0 OR (IS_USED_LOCK(%s) = %d AND CONNECTION_ID() = %d))',
				$wpdb->options,
				$option,
				$expected,
				null === $fence ? 0 : 1,
				$fence['name'] ?? '',
				$fence['connection_id'] ?? 0,
				$fence['connection_id'] ?? 0
			)
		);
		return self::result( $wpdb, $result );
	}

	private static function result( mixed $wpdb, mixed $result ): int|false {
		return false === $result || ! empty( $wpdb->last_error ) ? false : (int) $result;
	}

	/** Direct writes never populate caches from a potentially superseded value. */
	public static function invalidate( string $option ): void {
		wp_cache_delete( $option, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		ConfigurationStore::on_option_change( $option );
	}
}
