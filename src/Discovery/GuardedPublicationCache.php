<?php
/**
 * Private storage for file-backed publications whose contents follow post visibility.
 *
 * @package Cybermaps
 */

declare(strict_types=1);

namespace Cybermaps\Discovery;

use Cybermaps\Core\EndpointRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Public URLs never point directly at a generation of these files. */
final class GuardedPublicationCache {
	public const PREFIX        = '@publication/';
	public const MIGRATION_KEY = 'cybermaps_guarded_publications_cutover';

	/** Retire previously owned origin files independently of the background scheduler. */
	public static function retire_public_files(): void {
		if ( is_multisite() || 'complete' === get_option( self::MIGRATION_KEY ) ) {
			return;
		}
		$bridge = StaticBridge::get_instance();
		if ( add_option( self::MIGRATION_KEY, 'started', '', false ) && 'all' === StaticBridge::get_mode() ) {
			// Fence a worker that began before the installation was upgraded.
			$bridge->invalidate();
		}
		$result = $bridge->purge_all( '', '', 'legacy_content' );
		if ( 'complete' === $result['status'] ) {
			update_option( self::MIGRATION_KEY, 'complete', false );
			if ( 'all' === StaticBridge::get_mode() ) {
				$bridge->request_sync();
			}
		}
	}

	/** Prioritize earlier public content copies during a visibility change on a migrating site. */
	public static function retire_post_files( int $post_id ): void {
		if ( is_multisite() || 'complete' === get_option( self::MIGRATION_KEY ) ) {
			return;
		}
		$bridge = StaticBridge::get_instance();
		$files  = array( 'discovery/chunks/' . $post_id . '.json' );
		foreach ( EndpointRegistry::get_instance()->get_static_targets( 'all', null, true ) as $target ) {
			if ( 'all' === $target['bucket'] ) {
				$files[] = $target['filename'];
			}
		}
		foreach ( $bridge->runner_get_localized_languages() as $language ) {
			foreach ( array( 'llms.txt', 'llms-full.txt', 'llms-tldr.txt' ) as $filename ) {
				$files[] = $language . '/' . $filename;
			}
		}
		$bridge->purge_all( '', '', 'failed_publication', $files );
		self::retire_public_files();
	}

	/** Identify content-derived publications, including previously configured XML slugs. */
	public static function covers( string $filename ): bool {
		if ( str_starts_with( $filename, self::PREFIX ) ) {
			return false;
		}
		if ( str_ends_with( $filename, '.xml' ) || 1 === preg_match( '#^(?:discovery/chunks/[1-9][0-9]*\.json|[a-z0-9_-]{2,16}/llms(?:-full|-tldr)?\.txt)$#D', $filename ) ) {
			return true;
		}
		$entry = EndpointRegistry::get_instance()->match_path( '/' . $filename );
		foreach ( $entry['definition']['static_targets'] ?? array() as $target ) {
			if ( 'all' === $target['bucket'] && '/' . $filename === $target['path'] ) {
				return true;
			}
		}
		return false;
	}

	/** Bound file reads and allow room for the response, hashing and WordPress shutdown. */
	public static function can_read_body( int $size ): bool {
		if ( $size < 0 || $size > LLMS::FULL_OUTPUT_MAX_BYTES ) {
			return false;
		}
		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		return $limit <= 0 || $size <= intdiv( max( 0, $limit - memory_get_usage( true ) - 16 * 1024 * 1024 ), 2 );
	}

	/** Pin both content families so a failed static sequence update cannot make old bytes eligible. */
	public static function privacy_generation(): string {
		$discovery = \Cybermaps\Core\CacheManager::get_generation( 'discovery', true );
		$sitemap   = \Cybermaps\Core\CacheManager::get_generation( 'sitemap', true );
		return $discovery . '-' . $sitemap;
	}

	/** The ownership key pins the generation that produced the bytes. */
	public static function key( string $filename, int $generation, string $privacy ): string {
		return self::PREFIX . $generation . '-' . $privacy . '/' . $filename;
	}

	/** Retrieve the logical filename from an internal ownership key. */
	public static function filename( string $key ): string {
		if ( preg_match( '#^@publication/[0-9]+-[0-9]+-[0-9]+/(.+)$#D', $key, $matches ) ) {
			return $matches[1];
		}
		return $key;
	}

	/**
	 * Establish a mode-0700 local directory outside the origin and WordPress trees.
	 * A host with no private writable temp storage falls back to WordPress.
	 *
	 * @param string $public_root Physical publication root.
	 * @param bool   $create Whether this operation may create a missing directory.
	 */
	public static function private_root( string $public_root, bool $create ): string {
		$temp = realpath( sys_get_temp_dir() );
		if ( false === $temp ) {
			return '';
		}
		$root = $temp . '/cybermaps-publications-' . wp_hash( ABSPATH . '|' . get_current_blog_id() );
		if ( ! self::outside_public_roots( $root, $public_root ) || is_link( $root ) ) {
			return '';
		}
		if ( ! file_exists( $root ) && ! $create ) {
			return $root;
		}
		global $wp_filesystem;
		if ( ! is_object( $wp_filesystem ) && ! WP_Filesystem() ) {
			return '';
		}
		if ( isset( $wp_filesystem->method ) && 'direct' !== $wp_filesystem->method ) {
			return '';
		}
		if ( $create && ! $wp_filesystem->is_dir( $root ) ) {
			$wp_filesystem->mkdir( $root, 0700 );
		}
		clearstatcache( true, $root );
		$permissions = $wp_filesystem->getchmod( $root );
		return '700' === substr( (string) $permissions, -3 ) && realpath( $root ) === $root ? $root : '';
	}

	/** Refuse a temporary directory exposed below any known public root. */
	private static function outside_public_roots( string $root, string $public_root ): bool {
		$paths = array( ABSPATH, $public_root, defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '', (string) getenv( 'DOCUMENT_ROOT' ) );
		foreach ( array_filter( $paths ) as $path ) {
			$public = realpath( $path );
			if ( false === $public || str_starts_with( $root . '/', rtrim( $public, '/\\' ) . '/' ) ) {
				return false;
			}
		}
		return true;
	}
}
