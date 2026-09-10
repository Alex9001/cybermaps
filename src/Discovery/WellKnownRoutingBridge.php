<?php
/**
 * Native well-known routing verification and legacy cleanup.
 *
 * @package Cybermaps\Discovery
 */

declare(strict_types=1);

namespace Cybermaps\Discovery;

use Cybermaps\Core\EndpointRegistry;
use Cybermaps\Core\NativeRoutingRegistrar;
use Cybermaps\Core\URLManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Verifies native routing and removes only the obsolete bridge block Cybermaps owns.
 */
final class WellKnownRoutingBridge {
	public const RECONCILE_HOOK = 'cybermaps_reconcile_well_known_routing';
	public const VERIFY_HOOK    = 'cybermaps_verify_well_known_routing';
	public const STATUS_OPTION  = 'cybermaps_well_known_routing_status';

	private const HASH_OPTION  = 'cybermaps_well_known_routing_hash';
	private const BEGIN_MARKER = '# BEGIN Cybermaps well-known routing';
	private const END_MARKER   = '# END Cybermaps well-known routing';
	private const MANAGED_FILE = '.well-known/.htaccess';

	private static bool $hooks_registered = false;

	/** Register bounded reconciliation and verification jobs. */
	public static function register_hooks(): void {
		if ( self::$hooks_registered ) {
			return;
		}
		self::$hooks_registered = true;
		add_action( self::RECONCILE_HOOK, array( new self(), 'reconcile' ) );
		add_action( self::VERIFY_HOOK, array( new self(), 'verify' ) );
		if ( ! wp_next_scheduled( self::VERIFY_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::VERIFY_HOOK );
		}
		if ( (int) get_option( 'cybermaps_well_known_routing_schema', 0 ) < 2 ) {
			self::request_reconciliation();
		}
	}

	/** Reconcile immediately or queue one nonblocking run. */
	public static function request_reconciliation( bool $immediate = false ): void {
		if ( $immediate ) {
			( new self() )->reconcile();
			return;
		}
		if ( ! wp_next_scheduled( self::RECONCILE_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::RECONCILE_HOOK );
		}
	}

	/**
	 * Activate WordPress global rules and retire the old nested file safely.
	 *
	 * @return array<string,mixed>
	 */
	public function reconcile( bool $schedule_verification = true ): array {
		$refreshed = NativeRoutingRegistrar::get_instance()->refresh_if_needed();
		$cleanup   = $this->remove();
		$status    = array(
			'status'          => 'native_rewrite',
			'delivery'        => 'wordpress_global_rewrite',
			'message'         => __( 'WordPress owns exact internal routes and global Apache/LiteSpeed rules for Cybermaps well-known publications.', 'cybermaps' ),
			'rules_refreshed' => $refreshed,
			'legacy_cleanup'  => (string) ( $cleanup['status'] ?? 'unknown' ),
			'updated_at'      => time(),
		);
		update_option( 'cybermaps_well_known_routing_schema', 2, false );
		update_option( self::STATUS_OPTION, $status, false );
		if ( $schedule_verification && ! wp_next_scheduled( self::VERIFY_HOOK ) ) {
			wp_schedule_single_event( time() + 10, self::VERIFY_HOOK );
		}
		return $status;
	}

	/**
	 * Remove the legacy marker block only when its recorded ownership hash matches.
	 *
	 * @return array<string,mixed>
	 */
	public function remove(): array {
		$path = $this->managed_path();
		if ( '' === $path || ! file_exists( $path ) ) {
			delete_option( self::HASH_OPTION );
			return array( 'status' => 'not_present' );
		}
		$filesystem = $this->filesystem();
		if ( null === $filesystem ) {
			return array( 'status' => 'filesystem_unavailable' );
		}
		$content = $filesystem->get_contents( $path );
		$content = is_string( $content ) ? $content : '';
		$block   = $this->owned_block( $content );
		if ( null === $block ) {
			delete_option( self::HASH_OPTION );
			return array( 'status' => 'not_present' );
		}
		$expected = (string) get_option( self::HASH_OPTION, '' );
		if ( '' === $expected || ! hash_equals( $expected, hash( 'sha256', $block ) ) ) {
			return array( 'status' => 'conflict' );
		}
		$remaining = str_replace( $block, '', $content );
		$chmod     = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644;
		$success   = '' === trim( $remaining )
			? $filesystem->delete( $path, false, 'f' )
			: $filesystem->put_contents( $path, $remaining, $chmod );
		if ( ! $success ) {
			return array( 'status' => 'failed' );
		}
		delete_option( self::HASH_OPTION );
		return array( 'status' => 'removed' );
	}

	/**
	 * Probe canonical well-known paths with their protocol media types.
	 *
	 * @return array<string,mixed>
	 */
	public function verify(): array {
		$checks = array();
		foreach ( NativeRoutingRegistrar::get_instance()->enabled_well_known_paths() as $path ) {
			$checks[ $path ] = $this->probe( $path );
		}
		$conformant = array() !== $checks && ! in_array( false, array_column( $checks, 'conformant' ), true );
		$status     = array(
			'status'     => $conformant ? 'publicly_verified' : 'verification_failed',
			'delivery'   => 'wordpress_global_rewrite',
			'message'    => $conformant
				? __( 'Canonical well-known publications reached WordPress with conforming responses.', 'cybermaps' )
				: __( 'At least one canonical well-known publication did not return its registered response.', 'cybermaps' ),
			'checks'     => $checks,
			'updated_at' => time(),
		);
		update_option( self::STATUS_OPTION, $status, false );
		return $status;
	}

	/** @return array<string,mixed> */
	public static function get_status(): array {
		$status = get_option( self::STATUS_OPTION, array() );
		return is_array( $status ) ? $status : array();
	}

	/** @return array{conformant:bool,status:int,type:string} */
	private function probe( string $path ): array {
		$url      = URLManager::get_home_url( ltrim( $path, '/' ) );
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => 8,
				'redirection' => 0,
				'headers'     => array( 'X-Cybermaps-Diagnostic' => '1' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'conformant' => false,
				'status'     => 0,
				'type'       => '',
			);
		}
		$code     = (int) wp_remote_retrieve_response_code( $response );
		$type     = strtolower( (string) wp_remote_retrieve_header( $response, 'content-type' ) );
		$expected = $this->expected_type( $path );
		return array(
			'conformant' => $code >= 200 && $code < 300 && ( '' === $expected || str_starts_with( $type, strtolower( $expected ) ) ),
			'status'     => $code,
			'type'       => $type,
		);
	}

	private function expected_type( string $path ): string {
		foreach ( EndpointRegistry::get_instance()->get_path_publications() as $endpoint ) {
			$paths = array_merge( array( (string) ( $endpoint['path'] ?? '' ) ), (array) ( $endpoint['aliases'] ?? array() ) );
			if ( in_array( $path, $paths, true ) ) {
				return (string) ( $endpoint['type'] ?? '' );
			}
		}
		return 'application/json';
	}

	private function managed_path(): string {
		return (string) StaticBridge::get_instance()->get_file_path( self::MANAGED_FILE );
	}

	private function owned_block( string $content ): ?string {
		$start = strpos( $content, self::BEGIN_MARKER );
		$end   = strpos( $content, self::END_MARKER );
		if ( false === $start || false === $end || $end < $start ) {
			return null;
		}
		$end += strlen( self::END_MARKER );
		if ( isset( $content[ $end ] ) && "\r" === $content[ $end ] ) {
			++$end;
		}
		if ( isset( $content[ $end ] ) && "\n" === $content[ $end ] ) {
			++$end;
		}
		return substr( $content, $start, $end - $start );
	}

	private function filesystem(): ?object {
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! WP_Filesystem() || ! is_object( $wp_filesystem ) ) {
			return null;
		}
		return $wp_filesystem;
	}
}
