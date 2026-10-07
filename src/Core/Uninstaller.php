<?php
declare(strict_types=1);

namespace Cybermaps\Core;

use Cybermaps\Discovery\StaticOwnershipStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deletes data owned by Core when the site's uninstall opt-in is enabled.
 */
final class Uninstaller {
	/**
	 * Core-owned options stored in each site's options table.
	 *
	 * @var string[]
	 */
	private const OPTIONS = array(
		'cybermaps_settings',
		'cybermaps_data_version',
		'cybermaps_upgrade_state',
		'cybermaps_upgrade_lock',
		'cybermaps_discovery_center',
		'cybermaps_robots_manager',
		'cybermaps_identity_data',
		'cybermaps_cloudflare_rule_state',
		'cybermaps_managed_htaccess',
		'cybermaps_managed_htaccess_lock',
		'cybermaps_fatal_errors',
		'cybermaps_health_history',
		DiagnosticLogger::STATE_OPTION,
		DiagnosticLogger::ENTRIES_OPTION,
		'cybermaps_indexnow_key',
		\Cybermaps\Discovery\IndexNowQueue::OPTION,
		\Cybermaps\Discovery\IndexNowQueueSchema::VERSION_OPTION,
		\Cybermaps\Discovery\IndexNowQueueRepository::LAST_RESULT_OPTION,
		'cybermaps_static_hashes',
		'cybermaps_static_hashes_schema',
		'cybermaps_static_write_errors',
		'cybermaps_static_write_errors_dropped',
		'cybermaps_last_static_sync',
		'cybermaps_last_static_sync_attempt',
		'cybermaps_last_static_sync_report',
		'cybermaps_last_time_sensitive_static_refresh',
		'cybermaps_static_schedule_error',
		'cybermaps_static_operation_lock',
		'cybermaps_static_write_intent',
		'cybermaps_static_generation',
		'cybermaps_static_ownership_revision',
		'cybermaps_static_suspended',
		'cybermaps_static_cleanup_after_cancel',
		'cybermaps_static_sync_state',
		'cybermaps_static_failed_retry',
		'cybermaps_static_sync_epoch',
		'cybermaps_logs_schema_version',
		'cybermaps_audit_schema_version',
		'cybermaps_mcp_retired',
		'cybermaps_mcp_retirement_lock',
		'cybermaps_mcp_migration_notice',
		RuntimeCounterStore::READY_OPTION,
		'cybermaps_edge_cache_delivery_status',
		'cybermaps_edge_cache_pending_static',
		'cybermaps_content_audit_run_lock',
		'cybermaps_analytics_db_error',
		'cybermaps_core_transient_inventory',
		'cybermaps_llms_yaml_cache',
		'cybermaps_llms_full_yaml_cache',
		'cybermaps_media_audit_generation',
		'cybermaps_sitemap_occupancy_generation',
		'cybermaps_sitemap_occupancy_token',
		'cybermaps_sitemap_occupancy_manifest',
		'cybermaps_sitemap_occupancy_work',
		'cybermaps_sitemap_occupancy_state',
		'cybermaps_sitemap_occupancy_lock',
	);

	/**
	 * Core-owned post metadata.
	 *
	 * @var string[]
	 */
	private const POST_META_KEYS = array(
		'_cybermaps_ai_meta',
		'_cybermaps_ai_meta_ts',
		'_cybermaps_ai_next_transition',
		'_cybermaps_exclude_ai',
		'_cybermaps_exclude_search',
		'_cybermaps_exclude_sitemap',
		'_cybermaps_intent_override',
		'_cybermaps_media_audit',
		'_cybermaps_media_audit_generation',
		'_cybermaps_media_audit_mode',
		'_cybermaps_sitemap_changefreq',
		'_cybermaps_sitemap_priority',
		'_cybermaps_translation_sync_disabled',
	);

	/**
	 * Core-owned user metadata shared across the installation.
	 *
	 * @var string[]
	 */
	private const USER_META_KEYS = array(
		'cybermaps_dismiss_logs_well_known_notice',
	);

	/**
	 * Core-owned scheduled hooks.
	 *
	 * @var string[]
	 */
	private const SCHEDULED_HOOKS = array(
		'cybermaps_cleanup_logs_event',
		Lifecycle::RUNTIME_COUNTER_CLEANUP_HOOK,
		RuntimeCounterStore::CLEANUP_CONTINUATION_HOOK,
		'cybermaps_continue_logs_cleanup_event',
		'cybermaps_bg_sync_static_files',
		\Cybermaps\Discovery\StaticBridge::PURGE_CONTINUATION_HOOK,
		'cybermaps_refresh_time_sensitive_static_files',
		'cybermaps_daily_health_snapshot',
		'cybermaps_weekly_health_snapshot',
		'cybermaps_weekly_health_check',
		'cybermaps_retry_upgrade',
		'cybermaps_retry_translation_schema_upgrade',
		'cybermaps_bg_build_sitemap_occupancy',
		'cybermaps_edge_cache_retry',
		\Cybermaps\Discovery\IndexNowQueue::CRON_HOOK,
		'cybermaps_mcp_run_task',
		'cybermaps_mcp_cleanup_tasks',
	);

	/**
	 * Execute uninstall cleanup.
	 */
	public static function run(): void {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			return;
		}

		if ( ! \is_multisite() ) {
			if ( self::cleanup_current_site() ) {
				self::cleanup_shared_data();
				self::delete_network_settings();
			}

			return;
		}

		$site_ids         = self::get_site_ids();
		$cleaned_site_ids = array();

		foreach ( $site_ids as $site_id ) {
			\switch_to_blog( $site_id );

			try {
				if ( self::cleanup_current_site() ) {
					$cleaned_site_ids[] = $site_id;
				}
			} finally {
				\restore_current_blog();
			}
		}

		if ( ! empty( $site_ids ) && \count( $cleaned_site_ids ) === \count( $site_ids ) ) {
			self::cleanup_shared_data();
			self::delete_network_settings();
			return;
		}

		self::delete_translation_rows( $cleaned_site_ids );
	}

	/**
	 * Delete site-local data only when the current site opted in.
	 *
	 * @return bool Whether this site's data was deleted.
	 */
	private static function cleanup_current_site(): bool {
		// Runtime callbacks must not remain scheduled after the code is removed,
		// even when the user chose to retain persistent Cybermaps data.
		foreach ( self::SCHEDULED_HOOKS as $hook ) {
			\wp_clear_scheduled_hook( $hook );
		}

		$settings     = \get_option( 'cybermaps_settings', array() );
		$purge_result = array(
			'success'  => true,
			'status'   => 'complete',
			'retained' => array(),
		);
		if ( ! \is_multisite() || \is_main_site() ) {
			$purge_result = \Cybermaps\Discovery\StaticBridge::get_instance()
				->cancel_and_purge( 'all', true, true );
			\wp_clear_scheduled_hook( \Cybermaps\Discovery\StaticBridge::PURGE_CONTINUATION_HOOK );
		}

		if (
			! \is_array( $settings )
			|| '1' !== (string) ( $settings['delete_data_on_uninstall'] ?? '0' )
		) {
			return false;
		}

		return self::cleanup_owned_site_data( $purge_result );
	}

	/** Remove only obsolete MCP tables during the 8.0 migration. */
	public static function remove_legacy_mcp_tables(): bool {
		return self::remove_owned_tables( true );
	}

	/** Drop only the fixed owned-table inventory; never accepts names from a caller. */
	private static function remove_owned_tables( bool $legacy_mcp_only ): bool {
		global $wpdb;
		$tables_removed = true;
		$owned_tables   = array(
			$wpdb->prefix . 'cybermaps_logs',
			$wpdb->prefix . 'cybermaps_runtime_counters',
			$wpdb->prefix . 'cybermaps_indexnow_queue',
			$wpdb->prefix . 'cybermaps_static_ownership',
			$wpdb->prefix . 'cybermaps_audit_findings',
			$wpdb->prefix . 'cybermaps_audit_resources',
			$wpdb->prefix . 'cybermaps_audit_runs',
			$wpdb->prefix . 'cybermaps_mcp_oauth_clients',
			$wpdb->prefix . 'cybermaps_mcp_oauth_codes',
			$wpdb->prefix . 'cybermaps_mcp_oauth_devices',
			$wpdb->prefix . 'cybermaps_mcp_oauth_grants',
			$wpdb->prefix . 'cybermaps_mcp_oauth_tokens',
			$wpdb->prefix . 'cybermaps_mcp_tasks',
		);
		foreach ( $owned_tables as $owned_table ) {
			if ( $legacy_mcp_only && ! str_starts_with( $owned_table, $wpdb->prefix . 'cybermaps_mcp_' ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$dropped = $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $owned_table ) );
			if ( false === $dropped || ! self::table_was_removed( $owned_table ) ) {
				$tables_removed = false;
			}
		}

		return $tables_removed;
	}

	/**
	 * Remove opted-in site data independently from the best-effort static purge.
	 *
	 * @param array<string,mixed> $purge_result StaticBridge purge result.
	 */
	private static function cleanup_owned_site_data( array $purge_result ): bool {
		/*
		 * WordPress removes the plugin files after uninstall.php returns, so a
		 * retryable static-file error cannot safely postpone database cleanup.
		 * The operation lock is deleted below as an ownership fence; an in-flight
		 * writer will observe the lost token and stop before its next write.
		 */
		self::report_incomplete_static_purge( $purge_result );

		$tables_removed = self::remove_owned_tables( false );

		foreach ( self::POST_META_KEYS as $meta_key ) {
			\delete_post_meta_by_key( $meta_key );
		}
		$post_meta_removed = self::post_meta_was_removed();

		// Clear transient caches before deleting OPTIONS. The cache manager needs
		// cybermaps_core_transient_inventory to identify dynamic sitemap, chunk,
		// translation, and status keys without touching extension-owned caches.
		CacheManager::clear_all();

		foreach ( self::OPTIONS as $option_name ) {
			\delete_option( $option_name );
		}
		foreach ( StaticOwnershipStore::all_option_names() as $option_name ) {
			\delete_option( $option_name );
		}

		$pointers_removed = self::remove_oauth_pointers();
		$options_removed  = self::options_were_removed();
		return $tables_removed && $post_meta_removed && $options_removed && $pointers_removed;
	}

	/**
	 * Verify a DROP statement instead of allowing a failed local cleanup to
	 * authorize deletion of installation-shared data.
	 */
	private static function table_was_removed( string $table_name ): bool {
		global $wpdb;
		if ( ! \method_exists( $wpdb, 'get_results' ) ) {
			// Minimal database adapters cannot verify beyond the successful DROP.
			return true;
		}

		$pattern = \method_exists( $wpdb, 'esc_like' )
			? $wpdb->esc_like( $table_name )
			: \addcslashes( $table_name, '_%\\' );
		$rows    = self::read_cleanup_rows( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pattern ) );
		return is_array( $rows ) && empty( $rows );
	}

	/**
	 * Verify that no Core-owned post metadata survived the bulk deletes.
	 */
	private static function post_meta_was_removed(): bool {
		global $wpdb;
		if (
			empty( $wpdb->postmeta )
			|| ! \method_exists( $wpdb, 'get_results' )
		) {
			return true;
		}

		$placeholders = \implode( ',', \array_fill( 0, \count( self::POST_META_KEYS ), '%s' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The fixed Core-owned key list supplies exactly one string value per generated placeholder in this single statement.
		$query = $wpdb->prepare(
			"SELECT meta_key FROM %i
			WHERE meta_key IN ({$placeholders})
			LIMIT 1",
			...array_merge( array( $wpdb->postmeta ), self::POST_META_KEYS )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = self::read_cleanup_rows( $query );
		return is_array( $rows ) && empty( $rows );
	}

	/** Execute only fully prepared, bounded cleanup inventory queries. */
	private static function read_cleanup_rows( string $query ): array|false {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) {
			return false;
		}
		$wpdb->last_error = '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared cleanup queries inspect only the fixed owned table, post-meta and numeric OAuth-pointer inventories; callers bound results to at most100 rows.
		$rows = $wpdb->get_results(
			$query, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Every caller prepares its fixed cleanup template and typed values before entering this private reader.
			ARRAY_A
		);
		return is_array( $rows ) && count( $rows ) <= 100 && '' === (string) $wpdb->last_error ? $rows : false;
	}

	/** Delete only owned per-user ID pointers, including pointers for deleted users. */
	private static function remove_oauth_pointers(): bool {
		$deadline = microtime( true ) + 10;
		$after    = '';
		do {
			$rows = self::oauth_pointer_rows( $after, 100 );
			if ( false === $rows ) {
				return false;
			}
			foreach ( $rows as $row ) {
				$name = $row['option_name'] ?? '';
				if ( ! self::remove_oauth_pointer( $name ) ) {
					return false;
				}
				$after = $name;
			}
			$full_page = 100 === count( $rows );
		} while ( $full_page && microtime( true ) < $deadline );
		return array() === self::oauth_pointer_rows( '', 1 );
	}

	private static function remove_oauth_pointer( mixed $name ): bool {
		if ( ! is_string( $name ) || 1 !== preg_match( '/^cybermaps_cf_oauth_pointer_[0-9]+$/D', $name ) ) {
			return false;
		}
		delete_option( $name );
		$missing = new \stdClass();
		return get_option( $name, $missing ) === $missing;
	}

	private static function oauth_pointer_rows( string $after, int $limit ): array|false {
		global $wpdb;
		if ( empty( $wpdb->options ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return false;
		}
		return self::read_cleanup_rows(
			$wpdb->prepare(
				'SELECT option_name FROM %i WHERE option_name LIKE %s AND BINARY LEFT(option_name,%d) = BINARY %s AND LENGTH(option_name) > %d AND SUBSTRING(option_name,%d) NOT REGEXP %s AND option_name > %s ORDER BY option_name LIMIT %d',
				$wpdb->options,
				'cybermaps\\_cf\\_oauth\\_pointer\\_%',
				27,
				'cybermaps_cf_oauth_pointer_',
				27,
				28,
				'[^0-9]',
				$after,
				$limit
			)
		);
	}

	/**
	 * Verify exact option absence; delete_option() returns false both when an
	 * option is already absent and when a database write fails.
	 */
	private static function options_were_removed(): bool {
		$missing = new \stdClass();
		foreach ( self::OPTIONS as $option_name ) {
			if ( \get_option( $option_name, $missing ) !== $missing ) {
				return false;
			}
		}
		foreach ( StaticOwnershipStore::all_option_names() as $option_name ) {
			if ( \get_option( $option_name, $missing ) !== $missing ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Record why public generated files may remain without retaining private
	 * plugin data after an opted-in uninstall.
	 *
	 * @param array<string,mixed> $result StaticBridge purge result.
	 */
	private static function report_incomplete_static_purge( array $result ): void {
		if ( self::static_purge_allows_data_cleanup( $result ) ) {
			return;
		}

		if ( ! \function_exists( 'wp_trigger_error' ) ) {
			return;
		}

		$status   = \sanitize_key( (string) ( $result['status'] ?? 'error' ) );
		$retained = \array_slice(
			\array_map( 'strval', \array_keys( (array) ( $result['retained'] ?? array() ) ) ),
			0,
			10
		);
		$detail   = empty( $retained )
			? $status
			: $status . ': ' . \implode( ', ', $retained );
		$counts   = (array) ( $result['counts'] ?? array() );
		$detail  .= sprintf(
			/* translators: 1: deleted files, 2: retained files, 3: remaining inventory lower bound or unknown. */
			__( '; deleted %1$d, retained %2$d, pending at least %3$s', 'cybermaps' ),
			max( 0, (int) ( $counts['deleted'] ?? 0 ) ),
			max( 0, (int) ( $counts['retained'] ?? 0 ) ),
			isset( $result['pending_lower_bound'] ) ? (string) $result['pending_lower_bound'] : __( 'unknown', 'cybermaps' )
		);
		\wp_trigger_error(
			__METHOD__,
			\sprintf(
				/* translators: %s: static cleanup status and retained relative paths. */
				__( 'Cybermaps removed its opted-in database data, but ownership-safe static cleanup did not finish (%s). Some generated public files may remain.', 'cybermaps' ),
				$detail
			),
			E_USER_WARNING
		);
	}

	/**
	 * Decide whether static reconciliation left only intentional conflicts.
	 *
	 * @param array<string,mixed> $result StaticBridge purge result.
	 */
	private static function static_purge_allows_data_cleanup( array $result ): bool {
		if (
			empty( $result['success'] )
			|| ! \in_array( (string) ( $result['status'] ?? '' ), array( 'complete', 'partial' ), true )
		) {
			return false;
		}

		$intentional_retention = array(
			'content_changed',
			'invalid_inventory_hash',
			'invalid_inventory_path',
		);
		foreach ( (array) ( $result['retained'] ?? array() ) as $reason ) {
			if ( ! \in_array( (string) $reason, $intentional_retention, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Delete data shared by all sites only after every site opted in.
	 */
	private static function cleanup_shared_data(): void {
		foreach ( self::USER_META_KEYS as $meta_key ) {
			\delete_metadata( 'user', 0, $meta_key, '', true );
		}

		self::drop_translation_table();
	}

	/**
	 * Delete translation rows for sites whose local data was removed.
	 *
	 * @param int[] $site_ids Site IDs that opted into cleanup.
	 */
	private static function delete_translation_rows( array $site_ids ): void {
		if ( empty( $site_ids ) ) {
			return;
		}

		global $wpdb;
		$table_name = $wpdb->base_prefix . 'cybermaps_translations';
		$changed    = false;

		foreach ( $site_ids as $site_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$deleted = $wpdb->query(
				$wpdb->prepare(
					'DELETE FROM %i WHERE site_id = %d',
					$table_name,
					$site_id
				)
			);
			$changed = $changed || ( false !== $deleted && $deleted > 0 );
		}

		if ( ! $changed ) {
			return;
		}

		$current_site_id = \function_exists( 'get_current_blog_id' )
			? (int) \get_current_blog_id()
			: 0;
		foreach ( \array_diff( self::get_site_ids(), $site_ids ) as $remaining_site_id ) {
			$switched = false;
			if ( (int) $remaining_site_id !== $current_site_id ) {
				$switched = (bool) \switch_to_blog( (int) $remaining_site_id );
				if ( ! $switched ) {
					continue;
				}
			}

			try {
				CacheManager::clear_family( 'translations' );
				CacheManager::clear_family( 'sitemap' );
			} finally {
				if ( $switched ) {
					\restore_current_blog();
				}
			}
		}
	}

	/**
	 * Drop the shared translation table.
	 */
	private static function drop_translation_table(): void {
		global $wpdb;
		$table_name = $wpdb->base_prefix . 'cybermaps_translations';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table_name ) );
	}

	/**
	 * Delete Core network options from every network in the installation.
	 */
	private static function delete_network_settings(): void {
		$option_names = array(
			'cybermaps_network_settings',
			'cybermaps_translation_schema_upgrade_state',
			'cybermaps_translation_schema_version',
		);
		if ( ! \is_multisite() ) {
			foreach ( $option_names as $option_name ) {
				\delete_site_option( $option_name );
			}
			return;
		}
		if ( \function_exists( 'get_networks' ) && \function_exists( 'delete_network_option' ) ) {
			$network_ids = \get_networks(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);

			foreach ( \is_array( $network_ids ) ? $network_ids : array() as $network_id ) {
				foreach ( $option_names as $option_name ) {
					\delete_network_option( (int) $network_id, $option_name );
				}
			}

			return;
		}

		foreach ( $option_names as $option_name ) {
			\delete_site_option( $option_name );
		}
	}

	/**
	 * Return every site ID in the installation.
	 *
	 * @return int[]
	 */
	private static function get_site_ids(): array {
		$site_ids = \get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);

		return \array_values(
			\array_filter(
				\array_map( 'intval', \is_array( $site_ids ) ? $site_ids : array() ),
				static fn ( int $site_id ): bool => $site_id > 0
			)
		);
	}
}
