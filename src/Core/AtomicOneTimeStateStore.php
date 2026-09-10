<?php
/**
 * Atomic one-time state storage.
 *
 * @package Cybermaps\Core
 */

declare(strict_types=1);

namespace Cybermaps\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * DB-authoritative, single-consume state records stored in non-autoloaded options.
 */
final class AtomicOneTimeStateStore {
	private const PREFIX = 'cybermaps_one_time_';
	private const SCHEMA = 1;

	/**
	 * Store one bounded state value until an absolute expiry timestamp.
	 */
	public function put( string $scope, string $key, mixed $value, int $expires_at ): bool {
		$option_name = $this->option_name( $scope, $key );
		if ( '' === $option_name || $expires_at <= time() ) {
			return false;
		}

		$record = array(
			'v'          => self::SCHEMA,
			'scope'      => $this->normalize_scope( $scope ),
			'key_hash'   => hash( 'sha256', $key ),
			'value'      => $value,
			'expires_at' => $expires_at,
		);

		global $wpdb;
		if ( ! $this->wpdb_available() || ! \method_exists( $wpdb, 'query' ) ) {
			return false;
		}

		$stored = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s) ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = VALUES(autoload)',
				$wpdb->options,
				$option_name,
				$this->serialize_value( $record ),
				'off'
			)
		);
		if ( false === $stored ) {
			return false;
		}

		$this->invalidate_option_cache( $option_name );
		return true;
	}

	/**
	 * Consume a state value exactly once.
	 */
	public function take( string $scope, string $key, int $now ): mixed {
		$option_name = $this->option_name( $scope, $key );
		if ( '' === $option_name ) {
			return null;
		}

		$observed = $this->read_raw( $option_name );
		if ( null === $observed['raw'] ) {
			return null;
		}
		$record = $observed['value'];
		if ( ! \is_array( $record ) || ! $this->valid_record( $record, $scope, $key ) ) {
			return null;
		}
		if ( (int) $record['expires_at'] < $now ) {
			$this->delete_raw( $option_name, $observed['raw'], false );
			return null;
		}

		return $this->delete_raw( $option_name, $observed['raw'], true ) ? $record['value'] : null;
	}

	/**
	 * Delete expired state for one key without exposing the value.
	 */
	public function delete( string $scope, string $key ): bool {
		$option_name = $this->option_name( $scope, $key );
		if ( '' === $option_name ) {
			return false;
		}

		global $wpdb;
		if ( ! $this->wpdb_available() || ! \method_exists( $wpdb, 'query' ) ) {
			return false;
		}

		$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name = %s',
				$wpdb->options,
				$option_name
			)
		);
		if ( false === $deleted ) {
			return false;
		}

		$this->invalidate_option_cache( $option_name );
		return (int) $deleted > 0;
	}

	private function option_name( string $scope, string $key ): string {
		$scope = $this->normalize_scope( $scope );
		if ( '' === $scope || '' === $key ) {
			return '';
		}

		return self::PREFIX . $scope . '_' . hash( 'sha256', $key );
	}

	private function normalize_scope( string $scope ): string {
		$scope = strtolower( preg_replace( '/[^a-zA-Z0-9_]+/', '_', $scope ) ?? '' );
		return trim( $scope, '_' );
	}

	/**
	 * @return array{raw:string|null,value:mixed}
	 */
	private function read_raw( string $option_name ): array {
		global $wpdb;
		if ( ! $this->wpdb_available() || ! \method_exists( $wpdb, 'get_var' ) ) {
			return array(
				'raw'   => null,
				'value' => null,
			);
		}

		$raw = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1',
				$wpdb->options,
				$option_name
			)
		);
		if ( ! \is_string( $raw ) ) {
			return array(
				'raw'   => null,
				'value' => null,
			);
		}

		return array(
			'raw'   => $raw,
			'value' => \function_exists( 'maybe_unserialize' ) ? \maybe_unserialize( $raw ) : \unserialize( $raw, array( 'allowed_classes' => false ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		);
	}

	private function delete_raw( string $option_name, string $raw_value, bool $require_match ): bool {
		global $wpdb;
		if ( ! $this->wpdb_available() || ! \method_exists( $wpdb, 'query' ) ) {
			return false;
		}

		$sql     = $require_match
			? 'DELETE FROM %i WHERE option_name = %s AND BINARY option_value = BINARY %s'
			: 'DELETE FROM %i WHERE option_name = %s';
		$args    = $require_match
			? array( $wpdb->options, $option_name, $raw_value )
			: array( $wpdb->options, $option_name );
		$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL is selected from two literal DELETE templates; identifiers and values use placeholders.
			$wpdb->prepare( $sql, ...$args ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL is selected from fixed statements; values remain placeholders.
		);
		if ( false === $deleted ) {
			return false;
		}

		$this->invalidate_option_cache( $option_name );
		return (int) $deleted > 0;
	}

	/**
	 * @param array<string,mixed> $record
	 */
	private function valid_record( array $record, string $scope, string $key ): bool {
		return self::SCHEMA === (int) ( $record['v'] ?? 0 )
			&& $this->normalize_scope( $scope ) === (string) ( $record['scope'] ?? '' )
			&& hash_equals( hash( 'sha256', $key ), (string) ( $record['key_hash'] ?? '' ) )
			&& isset( $record['expires_at'] )
			&& (int) $record['expires_at'] > 0;
	}

	private function serialize_value( mixed $value ): string {
		return \function_exists( 'maybe_serialize' )
			? (string) \maybe_serialize( $value )
			: \serialize( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
	}

	private function wpdb_available(): bool {
		global $wpdb;
		return \is_object( $wpdb )
			&& isset( $wpdb->options )
			&& \is_string( $wpdb->options )
			&& \method_exists( $wpdb, 'prepare' );
	}

	private function invalidate_option_cache( string $option_name ): void {
		if ( ! \function_exists( 'wp_cache_delete' ) ) {
			return;
		}

		\wp_cache_delete( $option_name, 'options' );
		\wp_cache_delete( 'notoptions', 'options' );
		\wp_cache_delete( 'alloptions', 'options' );
	}
}
