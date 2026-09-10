<?php
declare(strict_types=1);

namespace Cybermaps\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared HTTP method policy for public read-only publications.
 */
final class ReadOnlyRequest {
	/**
	 * Resolve the current request method.
	 */
	public static function method(): string {
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) ) {
			return 'GET';
		}
		if ( ! is_scalar( $_SERVER['REQUEST_METHOD'] ) ) {
			return '';
		}

		return strtoupper(
			sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) )
		);
	}

	public static function is_allowed( string $method ): bool {
		return in_array( strtoupper( $method ), array( 'GET', 'HEAD' ), true );
	}

	public static function is_head(): bool {
		return 'HEAD' === self::method();
	}

	/**
	 * End an unsupported request with an explicit method contract.
	 */
	public static function enforce(): void {
		if ( self::is_allowed( self::method() ) ) {
			return;
		}

		status_header( 405 );
		nocache_headers();
		header( 'Allow: GET, HEAD' );
		exit;
	}
}
