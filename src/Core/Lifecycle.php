<?php
declare(strict_types=1);

namespace Cybermaps\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns activation, deactivation, and multisite provisioning.
 */
final class Lifecycle {
	public const RUNTIME_COUNTER_CLEANUP_HOOK = 'cybermaps_cleanup_runtime_counters_event';

	/**
	 * Activate Cybermaps.
	 *
	 * @param bool $network_wide Whether WordPress is activating the plugin network-wide.
	 */
	public static function activate( bool $network_wide = false ): void {
		if ( \is_multisite() && $network_wide ) {
			foreach ( self::get_site_ids() as $site_id ) {
				\switch_to_blog( $site_id );

				try {
					// Multisite is dynamic-only and every site shares the
					// installation's rewrite target. Provision each site and
					// perform only a soft in-memory rewrite flush; repeatedly
					// rewriting server configuration adds no valid capability.
					self::activate_site( false, false );
				} finally {
					\restore_current_blog();
				}
			}
		} else {
			self::activate_site();
		}

		// The translation registry is shared across the WordPress installation.
		\Cybermaps\Admin\NetworkSetup::maybe_upgrade( true );
	}

	/**
	 * Provision one site without invoking the network activation path.
	 *
	 * @param object $new_site Newly initialized WP_Site object.
	 * @param array  $args     Site initialization arguments.
	 */
	public static function initialize_site( $new_site, array $args = array() ): void {
		if ( ! \is_multisite() ) {
			return;
		}

		$network_id = self::get_new_site_network_id( $new_site, $args );
		if ( ! self::is_network_active( $network_id ) ) {
			return;
		}

		$site_id = 0;
		if ( \is_object( $new_site ) && isset( $new_site->blog_id ) ) {
			$site_id = (int) $new_site->blog_id;
		} elseif ( \is_object( $new_site ) && isset( $new_site->id ) ) {
			$site_id = (int) $new_site->id;
		}

		if ( $site_id < 1 ) {
			return;
		}

		\switch_to_blog( $site_id );

		try {
			// Static output is installation-wide; a soft rewrite flush is enough here.
			self::activate_site( false, false );
		} finally {
			\restore_current_blog();
		}
	}

	/**
	 * Include Core-owned site tables in WordPress multisite teardown.
	 *
	 * @param string[] $tables  Tables WordPress already plans to drop.
	 * @param int      $site_id Site being uninitialized.
	 * @return string[]
	 */
	public static function include_site_tables_for_deletion( array $tables, int $site_id ): array {
		if ( $site_id < 1 ) {
			return $tables;
		}

		global $wpdb;
		$prefix = \method_exists( $wpdb, 'get_blog_prefix' )
			? (string) $wpdb->get_blog_prefix( $site_id )
			: (string) $wpdb->prefix;

		foreach (
			array(
				'cybermaps_logs',
				'cybermaps_runtime_counters',
				'cybermaps_indexnow_queue',
				'cybermaps_audit_findings',
				'cybermaps_audit_resources',
				'cybermaps_audit_runs',
				'cybermaps_mcp_oauth_clients',
				'cybermaps_mcp_oauth_codes',
				'cybermaps_mcp_oauth_devices',
				'cybermaps_mcp_oauth_grants',
				'cybermaps_mcp_oauth_tokens',
				'cybermaps_mcp_tasks',
			) as $suffix
		) {
			$tables[] = $prefix . $suffix;
		}

		return \array_values( \array_unique( $tables ) );
	}

	/**
	 * Remove shared translation-registry rows for a deleted multisite site.
	 *
	 * @param object $old_site Site object supplied by wp_uninitialize_site.
	 */
	public static function cleanup_uninitialized_site( $old_site ): void {
		$site_id = \is_object( $old_site )
			? (int) ( $old_site->blog_id ?? $old_site->id ?? 0 )
			: 0;
		if ( $site_id < 1 ) {
			return;
		}

		global $wpdb;
		$table_name = $wpdb->base_prefix . 'cybermaps_translations';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE site_id = %d',
				$table_name,
				$site_id
			)
		);
		if ( false !== $deleted && $deleted > 0 ) {
			self::clear_translation_caches( $site_id );
		}
	}

	/**
	 * Clear site-local caches and sitemap responses backed by the shared
	 * translation registry.
	 *
	 * A relationship can connect sites across networks, while WordPress stores
	 * each site's transients in its own options table. Site deletion is rare, so
	 * invalidating the bounded, inventoried translation family on every
	 * remaining site is safer than leaving another site's hreflang cache stale.
	 */
	private static function clear_translation_caches( int $removed_site_id ): void {
		$current_site_id = \function_exists( 'get_current_blog_id' )
			? (int) \get_current_blog_id()
			: 0;
		$site_ids        = \get_sites(
			array(
				'fields'     => 'ids',
				'number'     => 0,
				'network_id' => 0,
			)
		);
		$site_ids        = \array_values(
			\array_unique(
				\array_filter(
					\array_map( 'intval', \is_array( $site_ids ) ? $site_ids : array() ),
					static fn ( int $site_id ): bool => $site_id > 0
						&& $site_id !== $removed_site_id
				)
			)
		);

		foreach ( $site_ids as $site_id ) {
			if ( $site_id === $current_site_id ) {
				self::clear_site_translation_caches();
				continue;
			}

			\switch_to_blog( $site_id );
			try {
				self::clear_site_translation_caches();
			} finally {
				\restore_current_blog();
			}
		}
	}

	/**
	 * Clear cache families affected by a removed translation relationship.
	 *
	 * CacheManager's generation fence is authoritative when its option store is
	 * available. Site teardown still removes the bounded, explicitly owned
	 * transient inventory when a minimal or failing database adapter cannot
	 * advance that fence.
	 */
	private static function clear_site_translation_caches(): void {
		foreach ( array( 'translations', 'sitemap' ) as $family ) {
			if ( CacheManager::clear_family( $family ) > 0 ) {
				continue;
			}

			$inventory = \get_option( 'cybermaps_core_transient_inventory', array() );
			if ( ! \is_array( $inventory ) ) {
				continue;
			}

			$changed = false;
			foreach ( $inventory as $key => $record ) {
				if ( ! \is_string( $key ) || ! \is_array( $record ) || (string) ( $record['family'] ?? '' ) !== $family ) {
					continue;
				}
				\delete_transient( $key );
				unset( $inventory[ $key ] );
				$changed = true;
			}
			if ( $changed ) {
				\update_option( 'cybermaps_core_transient_inventory', $inventory, false );
			}
		}
	}

	/**
	 * Deactivate Cybermaps.
	 *
	 * @param bool $network_wide Whether WordPress is deactivating the plugin network-wide.
	 */
	public static function deactivate( bool $network_wide = false ): void {
		if ( \is_multisite() && $network_wide ) {
			foreach ( self::get_site_ids() as $site_id ) {
				\switch_to_blog( $site_id );

				try {
					// Multisite never publishes Core files. Clear each site's
					// schedules and rewrite cache without repeatedly rewriting
					// installation-wide server configuration.
					self::deactivate_site( false );
				} finally {
					\restore_current_blog();
				}
			}

			return;
		}

		self::deactivate_site();
	}

	/**
	 * Activate the current site.
	 *
	 * @param bool $restore_static_output Whether to schedule regeneration of static output.
	 * @param bool $hard_flush            Whether WordPress should rewrite server configuration.
	 */
	private static function activate_site( bool $restore_static_output = true, bool $hard_flush = true ): void {
		$runtime_support_ready = Upgrade::provision_runtime_support();
		if ( $runtime_support_ready ) {
			Upgrade::stamp_fresh_install();
		}
		\Cybermaps\Discovery\StaticBridge::get_instance()->resume();
		\Cybermaps\Admin\Logs::create_table();
		\Cybermaps\Audit\AuditRunRepository::create_tables();
		\Cybermaps\MCP\OAuth\WpdbOAuthRepository::create_tables();
		\Cybermaps\MCP\TaskRepository::create_tables();
		\Cybermaps\Sitemap\Orchestrator::add_rewrite_rules();
		NativeRoutingRegistrar::get_instance()->activate( $hard_flush );
		\Cybermaps\Discovery\WellKnownRoutingBridge::request_reconciliation( true );
		( new \Cybermaps\Sitemap\Orchestrator() )->invalidate_occupancy();

		if ( ! \wp_next_scheduled( 'cybermaps_cleanup_logs_event' ) ) {
			\wp_schedule_event( \time(), 'daily', 'cybermaps_cleanup_logs_event' );
		}
		if ( ! \wp_next_scheduled( self::RUNTIME_COUNTER_CLEANUP_HOOK ) ) {
			\wp_schedule_event( \time(), 'hourly', self::RUNTIME_COUNTER_CLEANUP_HOOK );
		}

		// Deactivation removes generated output, so restore it after reactivation.
		if (
			$restore_static_output
			&& ( ! \is_multisite() || \is_main_site() )
			&& 'off' !== \Cybermaps\Discovery\StaticBridge::get_mode()
		) {
			\Cybermaps\Discovery\StaticBridge::get_instance()->request_sync();
		}
	}

	/**
	 * Deactivate the current site without deleting persistent data.
	 *
	 * @param bool $hard_flush Whether WordPress should rewrite server configuration.
	 */
	private static function deactivate_site( bool $hard_flush = true ): void {
		( new \Cybermaps\Discovery\ManagedHtaccess() )->deactivate();
		if ( ! \is_multisite() || \is_main_site() ) {
			\Cybermaps\Discovery\StaticBridge::get_instance()->cancel_and_purge( 'all', true );
			( new \Cybermaps\Discovery\WellKnownRoutingBridge() )->remove();
		}

		NativeRoutingRegistrar::get_instance()->deactivate( $hard_flush );
		foreach (
			array(
				'cybermaps_cleanup_logs_event',
				self::RUNTIME_COUNTER_CLEANUP_HOOK,
				'cybermaps_continue_logs_cleanup_event',
				'cybermaps_bg_sync_static_files',
				'cybermaps_refresh_time_sensitive_static_files',
				'cybermaps_daily_health_snapshot',
				'cybermaps_weekly_health_snapshot',
				'cybermaps_weekly_health_check',
				'cybermaps_edge_cache_retry',
				\Cybermaps\Discovery\WellKnownRoutingBridge::RECONCILE_HOOK,
				\Cybermaps\Discovery\WellKnownRoutingBridge::VERIFY_HOOK,
				\Cybermaps\Discovery\IndexNowQueue::CRON_HOOK,
				\Cybermaps\MCP\WordPressTaskService::RUN_HOOK,
				\Cybermaps\MCP\WordPressTaskService::CLEANUP_HOOK,
				\Cybermaps\Sitemap\PageOccupancyBuilder::HOOK,
				Upgrade::RETRY_HOOK,
				\Cybermaps\Admin\NetworkSetup::RETRY_HOOK,
			) as $hook
		) {
			\wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Determine whether the plugin is active across a specific network.
	 *
	 * @param int $network_id Network ID. Zero falls back to the current network.
	 */
	private static function is_network_active( int $network_id = 0 ): bool {
		if ( $network_id < 1 && \function_exists( 'get_current_network_id' ) ) {
			$network_id = (int) \get_current_network_id();
		}

		$active_plugins = $network_id > 0 && \function_exists( 'get_network_option' )
			? \get_network_option( $network_id, 'active_sitewide_plugins', array() )
			: \get_site_option( 'active_sitewide_plugins', array() );
		$basename       = defined( 'CYBERMAPS_PLUGIN_BASENAME' )
			? (string) CYBERMAPS_PLUGIN_BASENAME
			: 'cybermaps/cybermaps.php';

		return \is_array( $active_plugins ) && \array_key_exists( $basename, $active_plugins );
	}

	/**
	 * Resolve the network that owns a newly initialized site.
	 *
	 * WP_Site exposes the network as site_id on established WordPress versions;
	 * network_id is accepted as well for forward compatibility and test doubles.
	 *
	 * @param object $new_site Newly initialized WP_Site object.
	 * @param array  $args     Site initialization arguments.
	 */
	private static function get_new_site_network_id( $new_site, array $args ): int {
		if ( \is_object( $new_site ) && isset( $new_site->network_id ) ) {
			return (int) $new_site->network_id;
		}
		if ( \is_object( $new_site ) && isset( $new_site->site_id ) ) {
			return (int) $new_site->site_id;
		}
		if ( isset( $args['network_id'] ) ) {
			return (int) $args['network_id'];
		}

		return \function_exists( 'get_current_network_id' )
			? (int) \get_current_network_id()
			: 0;
	}

	/**
	 * Return every site ID in the installation.
	 *
	 * @return int[]
	 */
	private static function get_site_ids(): array {
		$args = array(
			'fields' => 'ids',
			'number' => 0,
		);

		if ( \function_exists( 'get_current_network_id' ) ) {
			$args['network_id'] = \get_current_network_id();
		}

		$site_ids = \get_sites( $args );

		return \array_values(
			\array_filter(
				\array_map( 'intval', \is_array( $site_ids ) ? $site_ids : array() ),
				static fn ( int $site_id ): bool => $site_id > 0
			)
		);
	}
}
