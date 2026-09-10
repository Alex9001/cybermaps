<?php
declare(strict_types=1);

namespace Cybermaps\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared method and CORS preflight handling for active discovery publications.
 */
final class PublicationRequestGuard {
	/**
	 * Handle OPTIONS and reject unsupported methods for an active publication route.
	 */
	public static function enforce_active_route(): void {
		$method = \Cybermaps\Core\ReadOnlyRequest::method();
		if ( 'OPTIONS' === $method ) {
			Integrity::handle_preflight();
			return;
		}

		if ( \Cybermaps\Core\ReadOnlyRequest::is_allowed( $method ) ) {
			return;
		}

		\status_header( 405 );
		\nocache_headers();
		\header( 'Allow: GET, HEAD, OPTIONS' );
		exit;
	}
}
