<?php
/**
 * One-time retirement of the pre-8.0 MCP credentials and jobs.
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
	public const DONE_OPTION   = 'cybermaps_mcp_retired';
	public const NOTICE_OPTION = 'cybermaps_mcp_migration_notice';

	/** Retire old remote authority before a site can opt in to the adapter. */
	public static function run(): void {
		if ( false !== get_option( self::DONE_OPTION, false ) ) {
			return;
		}
		$lock = new OptionLeaseLock( 'cybermaps_mcp_retirement_lock', 300, 30, true );
		if ( ! $lock->acquire() ) {
			return;
		}
		try {
			if ( false === get_option( self::DONE_OPTION, false ) ) {
				self::retire();
			}
		} finally {
			$lock->release();
		}
	}

	/** Cleanup is idempotent; a failed drop keeps the integration unavailable for retry. */
	private static function retire(): void {
		$settings = ConfigurationStore::settings();
		if ( 'off' !== ( $settings['mcp_mode'] ?? 'off' ) ) {
			update_option( self::NOTICE_OPTION, '1', false );
		}
		$settings['mcp_mode'] = 'off';
		unset( $settings['agent_registration_mode'] );
		update_option( 'cybermaps_settings', $settings );
		$stored = get_option( 'cybermaps_settings', array() );
		if ( ! is_array( $stored ) || 'off' !== ( $stored['mcp_mode'] ?? '' ) || isset( $stored['agent_registration_mode'] ) ) {
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
		update_option( self::DONE_OPTION, '1', false );
		$bridge = \Cybermaps\Discovery\StaticBridge::get_instance();
		$bridge->cancel_and_purge( 'discovery' );
		$bridge->request_sync( false, true );
	}
}
