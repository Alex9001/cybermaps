<?php
/**
 * IndexNow queue table schema.
 *
 * @package Cybermaps\Discovery
 */

declare(strict_types=1);

namespace Cybermaps\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the site-local durable IndexNow queue table.
 */
final class IndexNowQueueSchema {
	public const VERSION        = 1;
	public const TABLE_SUFFIX   = 'cybermaps_indexnow_queue';
	public const VERSION_OPTION = 'cybermaps_indexnow_queue_schema';

	/**
	 * Return the current site's queue table name.
	 */
	public static function table_name(): string {
		global $wpdb;
		$prefix = \is_object( $wpdb ) && isset( $wpdb->prefix ) && \is_string( $wpdb->prefix )
			? $wpdb->prefix
			: 'wp_';

		return $prefix . self::TABLE_SUFFIX;
	}

	/**
	 * Create or update the queue table.
	 */
	public static function create_table(): bool {
		global $wpdb;
		if ( ! self::has_wpdb() ) {
			return false;
		}

		$table           = self::table_name();
		$charset_collate = \method_exists( $wpdb, 'get_charset_collate' ) ? (string) $wpdb->get_charset_collate() : '';
		$sql             = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			url_hash char(64) NOT NULL,
			url varchar(2048) NOT NULL,
			state varchar(20) NOT NULL DEFAULT 'queued',
			attempts smallint(5) unsigned NOT NULL DEFAULT 0,
			next_attempt_at bigint(20) unsigned NOT NULL DEFAULT 0,
			claim_token char(64) NOT NULL DEFAULT '',
			lease_expires_at bigint(20) unsigned NOT NULL DEFAULT 0,
			queued_again tinyint(1) unsigned NOT NULL DEFAULT 0,
			last_status smallint(5) unsigned NOT NULL DEFAULT 0,
			last_error varchar(255) NOT NULL DEFAULT '',
			created_at bigint(20) unsigned NOT NULL DEFAULT 0,
			updated_at bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY url_hash (url_hash),
			KEY state_next (state,next_attempt_at),
			KEY claim_token (claim_token),
			KEY lease_recovery (state,lease_expires_at)
		) {$charset_collate};";

		if ( ! \function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		if ( \function_exists( 'dbDelta' ) ) {
			dbDelta( $sql );
		}

		\update_option( self::VERSION_OPTION, self::VERSION, false );
		self::invalidate_option_cache( self::VERSION_OPTION );

		return self::table_exists();
	}

	/**
	 * Drop the queue table. Intended for uninstall/root lifecycle integration.
	 */
	public static function drop_table(): bool {
		global $wpdb;
		if ( ! self::has_wpdb() || ! \method_exists( $wpdb, 'query' ) ) {
			return false;
		}

		$result = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Remove only the plugin-owned queue table during requested uninstall cleanup.
			$wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table_name() ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- Requested cleanup of the plugin-owned queue table.
		);
		\delete_option( self::VERSION_OPTION );
		\delete_option( IndexNowQueue::OPTION );
		\delete_option( IndexNowQueueRepository::LAST_RESULT_OPTION );
		self::invalidate_option_cache( self::VERSION_OPTION );
		self::invalidate_option_cache( IndexNowQueue::OPTION );
		self::invalidate_option_cache( IndexNowQueueRepository::LAST_RESULT_OPTION );

		return false !== $result;
	}

	/**
	 * Remove queue-owned option diagnostics without dropping data rows.
	 */
	public static function cleanup_options(): void {
		\delete_option( IndexNowQueue::OPTION );
		\delete_option( IndexNowQueueRepository::LAST_RESULT_OPTION );
		self::invalidate_option_cache( IndexNowQueue::OPTION );
		self::invalidate_option_cache( IndexNowQueueRepository::LAST_RESULT_OPTION );
	}

	/**
	 * Check whether the queue table is present.
	 */
	public static function table_exists(): bool {
		global $wpdb;
		if ( ! self::has_wpdb() || ! \method_exists( $wpdb, 'get_var' ) ) {
			return false;
		}

		$table = self::table_name();
		$like  = \method_exists( $wpdb, 'esc_like' ) ? $wpdb->esc_like( $table ) : $table;
		$found = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $like )
		);

		return \is_string( $found ) && \hash_equals( $table, $found );
	}

	private static function has_wpdb(): bool {
		global $wpdb;
		return \is_object( $wpdb )
			&& isset( $wpdb->prefix )
			&& \is_string( $wpdb->prefix )
			&& \method_exists( $wpdb, 'prepare' );
	}

	private static function invalidate_option_cache( string $option_name ): void {
		if ( ! \function_exists( 'wp_cache_delete' ) ) {
			return;
		}

		\wp_cache_delete( $option_name, 'options' );
		\wp_cache_delete( 'notoptions', 'options' );
		\wp_cache_delete( 'alloptions', 'options' );
	}
}
