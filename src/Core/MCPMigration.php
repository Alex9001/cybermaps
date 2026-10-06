<?php
/**
 * Delete obsolete MCP data and convert the 8.0 adapter preference.
 *
 * @package Cybermaps\Core
 */

declare(strict_types=1);

namespace Cybermaps\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Independent per-site migration, including sites whose main upgrade is already settled. */
final class MCPMigration {
	public const DONE_OPTION      = 'cybermaps_mcp_retired';
	private const CLEANUP_VERSION = '2';

	/** Retire old remote authority before a site can opt in to the adapter. */
	public static function run(): bool {
		if ( self::is_complete() ) {
			return true;
		}
		$lock = new OptionLeaseLock( 'cybermaps_mcp_retirement_lock', 300, 30, true );
		if ( ! $lock->acquire() ) {
			return false;
		}
		try {
			if ( ! self::is_complete() ) {
				self::retire();
			}
		} finally {
			$lock->release();
		}
		return self::is_complete();
	}

	/** Whether obsolete MCP data has been removed on this site. */
	public static function is_complete(): bool {
		return self::CLEANUP_VERSION === (string) get_option( self::DONE_OPTION, '' );
	}

	/** Cleanup is idempotent; a failed drop keeps the integration unavailable for retry. */
	private static function retire(): void {
		if ( ! self::remove_settings() ) {
			return;
		}
		foreach ( array( 'cybermaps_mcp_run_task', 'cybermaps_mcp_cleanup_tasks' ) as $hook ) {
			if ( false === wp_unschedule_hook( $hook ) ) {
				return;
			}
		}
		if ( ! Uninstaller::remove_legacy_mcp_tables() ) {
			return;
		}
		delete_option( 'cybermaps_mcp_oauth_schema_version' );
		delete_option( 'cybermaps_mcp_migration_notice' );
		update_option( self::DONE_OPTION, self::CLEANUP_VERSION, false );
		$bridge = \Cybermaps\Discovery\StaticBridge::get_instance();
		$bridge->cancel_and_purge( 'discovery' );
		$bridge->request_sync( false, true );
	}

	/** Delete retired fields; preserve only an adapter opt-in already made on 8.0. */
	private static function remove_settings(): bool {
		$settings = ConfigurationStore::settings();
		if ( '1' === (string) get_option( self::DONE_OPTION, '' ) && 'read_only' === ( $settings['mcp_mode'] ?? '' ) ) {
			$settings['enable_mcp_adapter'] = '1';
		}
		unset( $settings['mcp_mode'], $settings['agent_registration_mode'] );
		update_option( 'cybermaps_settings', $settings );
		$stored = get_option( 'cybermaps_settings', array() );
		return is_array( $stored ) && ! array_key_exists( 'mcp_mode', $stored ) && ! array_key_exists( 'agent_registration_mode', $stored );
	}
}
