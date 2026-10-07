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
				self::retire( $lock );
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
	private static function retire( OptionLeaseLock $lock ): void {
		if ( ! self::remove_settings( $lock ) || ! $lock->maintain() ) {
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

	/** Remove only fields from the exact authoritative settings observation. */
	private static function remove_settings( OptionLeaseLock $lock ): bool {
		global $wpdb;
		try {
			$raw = RawOptionStore::read( $wpdb, 'cybermaps_settings' );
			if ( false === $raw ) {
				return false;
			}
			if ( null === $raw ) {
				return self::settings_still_match( $lock, null );
			}
			$settings = maybe_unserialize( $raw );
			$version  = RawOptionStore::read( $wpdb, self::DONE_OPTION );
			if ( ! is_array( $settings ) || false === $version ) {
				return false;
			}
			$next = self::cleaned_settings( $settings, $version );
			return $next === $settings
				? self::settings_still_match( $lock, $raw )
				: self::replace_settings( $lock, $raw, $settings, $next );
		} finally {
			// A conflict must also evict this request's stale pre-migration memo.
			RawOptionStore::invalidate( 'cybermaps_settings' );
		}
	}

	/** Preserve explicit canonical consent, including an opt-out, over retired fields. */
	private static function cleaned_settings( array $settings, ?string $version ): array {
		if ( '1' === $version && 'read_only' === ( $settings['mcp_mode'] ?? '' ) && ! array_key_exists( 'enable_mcp_adapter', $settings ) ) {
			$settings['enable_mcp_adapter'] = '1';
		}
		unset( $settings['mcp_mode'], $settings['agent_registration_mode'] );
		return $settings;
	}

	/** Commit under both exact option bytes and the existing migration session fence. */
	private static function replace_settings( OptionLeaseLock $lock, string $raw, array $settings, array $next ): bool {
		global $wpdb;
		$fence = $lock->get_database_fence();
		if ( null === $fence || ! $lock->maintain() ) {
			return false;
		}
		$next_raw = (string) maybe_serialize( $next );
		if ( 1 !== RawOptionStore::replace( $wpdb, 'cybermaps_settings', $raw, $next_raw, $fence ) ) {
			return false;
		}
		RawOptionStore::invalidate( 'cybermaps_settings' );
		if ( ! self::settings_still_match( $lock, $next_raw ) ) {
			return false;
		}
		\Cybermaps\Admin\ConfigurationMutationStore::notify(
			'cybermaps_settings',
			array(
				'exists' => true,
				'value'  => $settings,
			),
			array( 'value' => $next )
		);
		return self::settings_still_match( $lock, $next_raw );
	}

	private static function settings_still_match( OptionLeaseLock $lock, ?string $expected ): bool {
		global $wpdb;
		return RawOptionStore::read( $wpdb, 'cybermaps_settings' ) === $expected && $lock->maintain();
	}
}
