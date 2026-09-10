<?php
/**
 * CLI Command for Cybermaps
 *
 * @package Cybermaps\CLI
 */

declare(strict_types=1);

namespace Cybermaps\CLI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Command {

	/**
	 * Flushes the WordPress rewrite rules for the sitemap.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cybermaps flush_rules
	 *
	 * @when after_wp_load
	 */
	public function flush_rules( $args, $assoc_args ) {
		unset( $args, $assoc_args );
		\WP_CLI::line( __( 'Flushing rewrite rules...', 'cybermaps' ) );
		\Cybermaps\Sitemap\Orchestrator::add_rewrite_rules();
		flush_rewrite_rules();
		\WP_CLI::success( __( 'Rewrite rules flushed successfully.', 'cybermaps' ) );
	}

	/**
	 * Clears the background pre-cache for all sitemaps.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cybermaps clear_cache
	 *
	 * @when after_wp_load
	 */
	public function clear_cache( $args, $assoc_args ) {
		unset( $args, $assoc_args );
		\WP_CLI::line( __( 'Clearing Cybermaps Core transient cache...', 'cybermaps' ) );
		$count = \Cybermaps\Core\CacheManager::clear_all();

		\WP_CLI::success(
			sprintf(
				/* translators: %d: number of transient cache keys selected for deletion. */
				__( 'Cache cleared. Core transient keys selected for deletion: %d.', 'cybermaps' ),
				$count
			)
		);
	}

	/**
	 * Regenerates all sitemaps and static discovery files.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cybermaps regenerate
	 *
	 * @when after_wp_load
	 */
	public function regenerate( $args, $assoc_args ) {
		unset( $args, $assoc_args );
		\WP_CLI::line( __( 'Regenerating sitemaps and static files...', 'cybermaps' ) );
		$orchestrator = new \Cybermaps\Sitemap\Orchestrator();
		$orchestrator->clear_sitemap_cache();
		\Cybermaps\Core\CacheManager::clear_family( 'discovery' );
		\Cybermaps\Core\CacheManager::clear_family( 'chunks' );
		$report = \Cybermaps\Discovery\StaticBridge::get_instance()->request_sync( true );
		if ( ! \is_array( $report ) ) {
			\WP_CLI::error( __( 'Sitemap cache was cleared, but no static synchronization report was returned.', 'cybermaps' ) );
		}

		\WP_CLI::line( self::format_sync_counts( (array) ( $report['counts'] ?? array() ) ) );

		$status  = (string) ( $report['status'] ?? 'failed' );
		$message = self::get_regeneration_non_error_message( $report );
		if ( null !== $message ) {
			\WP_CLI::success( $message );
			return;
		}

		\WP_CLI::error(
			sprintf(
				/* translators: %s: static synchronization status. */
				__( 'Sitemap cache was cleared, but static synchronization finished with status: %s.', 'cybermaps' ),
				$status
			)
		);
	}

	/** @param array<string,mixed> $counts */
	private static function format_sync_counts( array $counts ): string {
		return sprintf(
			/* translators: 1: desired files, 2: written files, 3: unchanged files, 4: deleted files, 5: conflicts, 6: failed files, 7: skipped files, 8: retained files. */
			__( 'Static sync: desired=%1$d written=%2$d unchanged=%3$d deleted=%4$d conflicts=%5$d failed=%6$d skipped=%7$d retained=%8$d', 'cybermaps' ),
			(int) ( $counts['desired'] ?? 0 ),
			(int) ( $counts['written'] ?? 0 ),
			(int) ( $counts['unchanged'] ?? 0 ),
			(int) ( $counts['deleted'] ?? 0 ),
			(int) ( $counts['conflicted'] ?? 0 ),
			(int) ( $counts['failed'] ?? 0 ),
			(int) ( $counts['skipped'] ?? 0 ),
			(int) ( $counts['retained'] ?? 0 )
		);
	}

	/**
	 * Return the CLI completion message for a non-error regeneration result.
	 *
	 * A pending report is intentionally not marked complete by StaticBridge. It
	 * means the bounded foreground slice finished and the remaining work was
	 * queued, so the CLI should report that continuation instead of exiting as
	 * though publication failed.
	 *
	 * @param array<string, mixed> $report Structured StaticBridge report.
	 */
	public static function get_regeneration_non_error_message( array $report ): ?string {
		$status = (string) ( $report['status'] ?? 'failed' );
		if ( 'complete' === $status ) {
			return 'off' === (string) ( $report['mode'] ?? '' )
				? __( 'Sitemap cache cleared; dynamic mode is active and owned static files were reconciled.', 'cybermaps' )
				: __( 'Sitemap cache cleared and the static publication inventory synced completely.', 'cybermaps' );
		}

		if ( 'pending' === $status ) {
			return __( 'Sitemap cache cleared; static publication is in progress and the remaining work was queued for background continuation.', 'cybermaps' );
		}

		if ( 'skipped' === $status && ! empty( $report['success'] ) ) {
			return __( 'Sitemap cache cleared; static publication was intentionally skipped.', 'cybermaps' );
		}

		return null;
	}

	/**
	 * Displays the current configuration settings.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cybermaps status
	 *
	 * @when after_wp_load
	 */
	public function status( $args, $assoc_args ) {
		unset( $args, $assoc_args );
		$settings = \Cybermaps\Core\ConfigurationStore::settings();
		\WP_CLI::line( __( 'CYBERMAPS Configuration Status:', 'cybermaps' ) );
		foreach ( $settings as $key => $value ) {
			if ( 'api_secret' === $key ) {
				\WP_CLI::line( '- api_secret: ' . __( '[REDACTED]', 'cybermaps' ) );
				continue;
			}
			\WP_CLI::line( '- ' . $key . ': ' . self::format_status_value( $value ) );
		}
	}

	/**
	 * Render settings without confusing list indexes for their actual values.
	 */
	public static function format_status_value( mixed $value ): string {
		if ( is_array( $value ) || is_object( $value ) ) {
			$encoded = wp_json_encode( $value, JSON_UNESCAPED_SLASHES );
			return is_string( $encoded ) ? $encoded : '[unavailable]';
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( null === $value ) {
			return 'null';
		}

		return (string) $value;
	}
}
