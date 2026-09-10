<?php
/**
 * Opt-in, ownership-safe discovery routing for Apache-compatible servers.
 *
 * @package Cybermaps\Discovery
 */

declare(strict_types=1);

namespace Cybermaps\Discovery;

use Cybermaps\Core\EndpointRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Owns one root-level block, never the WordPress or cache-plugin blocks. */
final class ManagedHtaccess {
	public const OPTION = 'cybermaps_managed_htaccess';
	private const LOCK  = 'cybermaps_managed_htaccess_lock';
	private const BEGIN = '# BEGIN Cybermaps managed delivery';
	private const END   = '# END Cybermaps managed delivery';

	/** @return array<string,mixed> */
	public static function state(): array {
		$state = get_option( self::OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/** Explain unsupported environments without guessing that a write will work. */
	public static function limitation(): string {
		if ( is_multisite() ) {
			return __( 'Automatic root configuration is unavailable on multisite because sites share server configuration.', 'cybermaps' );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Server capability hint only; never emitted or used as a filesystem path.
		$server = strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ?? '' ) ) );
		if ( ! str_contains( $server, 'apache' ) && ! str_contains( $server, 'litespeed' ) ) {
			return __( 'Apache or LiteSpeed was not detected. nginx does not read .htaccess; this action will not edit nginx or CDN configuration.', 'cybermaps' );
		}
		if ( untrailingslashit( \Cybermaps\Core\URLManager::get_home_url() ) !== untrailingslashit( home_url() ) ) {
			return __( 'Automatic root configuration requires the public frontend to use this WordPress home URL.', 'cybermaps' );
		}
		return '';
	}

	/** Apply or remove only an unchanged Cybermaps-owned block. */
	public function change( bool $install ): void {
		if ( $install && '' !== self::limitation() ) {
			throw new \RuntimeException( esc_html( self::limitation() ) );
		}
		$token = wp_generate_uuid4();
		if ( ! add_option( self::LOCK, $token, '', false ) ) {
			throw new \RuntimeException( esc_html__( 'Another Cybermaps server-configuration operation holds the lock. No file was changed.', 'cybermaps' ) );
		}
		register_shutdown_function(
			static function () use ( $token ): void {
				if ( get_option( self::LOCK ) === $token ) {
					delete_option( self::LOCK );
				}
			}
		);
		try {
			$this->write_change( $install );
		} finally {
			delete_option( self::LOCK );
		}
	}

	/** Remove on deactivation without making a filesystem failure fatal. */
	public function deactivate(): void {
		if ( empty( self::state()['block'] ) ) {
			return;
		}
		try {
			$this->change( false );
		} catch ( \Throwable $error ) {
			update_option( self::OPTION, array_merge( self::state(), array( 'message' => $error->getMessage() ) ), false );
		}
	}

	/** @return array{0:object,1:string} */
	private function filesystem(): array {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		global $wp_filesystem;
		if ( ! WP_Filesystem() || ! is_object( $wp_filesystem ) || 'direct' !== $wp_filesystem->method ) {
			throw new \RuntimeException( esc_html__( 'Automatic configuration requires direct WordPress filesystem access. No credentials are stored.', 'cybermaps' ) );
		}
		$path = trailingslashit( get_home_path() ) . '.htaccess';
		if ( is_link( $path ) || ! $wp_filesystem->exists( dirname( $path ) . '/index.php' ) ) {
			throw new \RuntimeException( esc_html__( 'The WordPress front-controller root could not be safely identified.', 'cybermaps' ) );
		}
		return array( $wp_filesystem, $path );
	}

	/** @param object $filesystem WordPress filesystem implementation. */
	private function read( object $filesystem, string $path ): string {
		if ( ! $filesystem->exists( $path ) ) {
			return '';
		}
		if ( $filesystem->size( $path ) > 1024 * 1024 ) {
			throw new \RuntimeException( esc_html__( 'The existing .htaccess exceeds the safe configuration size limit.', 'cybermaps' ) );
		}
		$content = $filesystem->get_contents( $path );
		if ( ! is_string( $content ) ) {
			throw new \RuntimeException( esc_html__( 'Cybermaps could not read .htaccess. No configuration was replaced.', 'cybermaps' ) );
		}
		return $content;
	}

	private function write_change( bool $install ): void {
		list( $filesystem, $path ) = $this->filesystem();
		$original                  = $this->read( $filesystem, $path );
		$state                     = self::state();
		if ( ! empty( $state['block'] ) && ( $state['path'] ?? '' ) !== $path ) {
			throw new \RuntimeException( esc_html__( 'The WordPress root changed since installation. The previous configuration was preserved for manual review.', 'cybermaps' ) );
		}
		$remainder   = $this->without_owned_block( $original, $state );
		$block       = $install ? $this->block() : '';
		$replacement = $block . $remainder;
		if ( ! hash_equals( hash( 'sha256', $original ), hash( 'sha256', $this->read( $filesystem, $path ) ) ) ) {
			throw new \RuntimeException( esc_html__( '.htaccess changed during the operation. Retry after other configuration changes finish.', 'cybermaps' ) );
		}
		// Retain recovery data before touching the file; never expose its contents in UI.
		$pending = array(
			'path'     => $path,
			'block'    => $block,
			'previous' => $original,
			'message'  => __( 'Configuration write pending.', 'cybermaps' ),
		);
		update_option( self::OPTION, $pending, false );
		if ( get_option( self::OPTION ) !== $pending ) {
			throw new \RuntimeException( esc_html__( 'Cybermaps could not save configuration recovery data. No file was changed.', 'cybermaps' ) );
		}
		$mode = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644;
		if ( ! $filesystem->put_contents( $path, $replacement, $mode ) ) {
			if ( $filesystem->put_contents( $path, $original, $mode ) ) {
				update_option( self::OPTION, $state, false );
			}
			throw new \RuntimeException( esc_html__( '.htaccess could not be written. Cybermaps attempted to restore the previous content.', 'cybermaps' ) );
		}
		update_option(
			self::OPTION,
			array(
				'path'    => $path,
				'block'   => $block,
				'message' => $install ? __( 'Rules written; public delivery has not yet been verified.', 'cybermaps' ) : __( 'Cybermaps managed block removed. Other rules were preserved.', 'cybermaps' ),
			),
			false
		);
	}

	/** @param array<string,mixed> $state Saved ownership record. */
	private function without_owned_block( string $content, array $state ): string {
		if ( ! str_contains( $content, self::BEGIN ) && ! str_contains( $content, self::END ) ) {
			return $content;
		}
		$block = (string) ( $state['block'] ?? '' );
		if ( '' === $block || 1 !== substr_count( $content, self::BEGIN ) || 1 !== substr_count( $content, self::END ) || ! str_contains( $content, $block ) ) {
			throw new \RuntimeException( esc_html__( 'The Cybermaps .htaccess block has external edits or ambiguous markers. It was preserved; automatic overwrite and removal were refused.', 'cybermaps' ) );
		}
		return str_replace( $block, '', $content );
	}

	/** Use rewrite-only syntax shared by Apache, LiteSpeed, and OpenLiteSpeed. */
	private function block(): string {
		$target = trailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ) . 'index.php';
		if ( ! preg_match( '~^/[a-zA-Z0-9/_.-]+$~D', $target ) ) {
			throw new \RuntimeException( esc_html__( 'The WordPress home path cannot be represented safely in automatic rewrite rules.', 'cybermaps' ) );
		}
		$lines = array( self::BEGIN, '<IfModule mod_rewrite.c>', 'RewriteEngine On' );
		foreach ( $this->paths() as $path ) {
			$lines[] = 'RewriteRule ^' . preg_quote( ltrim( $path, '/' ), '#' ) . '$ ' . $target . ' [E=Cache-Control:no-cache,L]';
		}
		return implode( "\n", array_merge( $lines, array( '</IfModule>', self::END, '' ) ) );
	}

	/** @return string[] Fixed registry paths only, not content URLs or wildcards. */
	private function paths(): array {
		$paths = array();
		foreach ( EndpointRegistry::get_instance()->get_path_publications() as $endpoint ) {
			foreach ( array_merge( array( $endpoint['path'] ?? '' ), (array) ( $endpoint['aliases'] ?? array() ) ) as $path ) {
				if ( is_string( $path ) && preg_match( '~^/[a-zA-Z0-9/_.-]+$~D', $path ) ) {
					$paths[] = $path;
				}
			}
		}
		sort( $paths, SORT_STRING );
		return array_values( array_unique( $paths ) );
	}
}
