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
	/** Return the same explicit failure for GET and HEAD without partial output. */
	public static function serve_size_limit_error( PublicationSizeLimitException $error ): never {
		$payload = \Cybermaps\Core\ProtocolOutput::json(
			array(
				'type'        => 'about:blank',
				'title'       => __( 'Publication exceeds the safe output limit.', 'cybermaps' ),
				'status'      => 507,
				'detail'      => $error->getMessage(),
				'publication' => $error->get_publication(),
				'max_bytes'   => $error->get_maximum_bytes(),
			)
		);
		\status_header( 507 );
		\nocache_headers();
		\header( 'Cache-Control: no-store, max-age=0' );
		\header( 'X-Cybermaps-Error: publication_too_large' );
		\header( 'Content-Type: application/problem+json; charset=utf-8' );
		if ( ! \Cybermaps\Core\ReadOnlyRequest::is_head() ) {
			\Cybermaps\Core\ProtocolOutput::emit( $payload, 'json' );
		}
		exit;
	}

	/** Return an uncached retryable failure without a partial protocol body. */
	public static function serve_unavailable( \Cybermaps\Core\BuildUnavailableException $error ): never {
		\status_header( 503 );
		\nocache_headers();
		\header( 'Cache-Control: no-store, max-age=0' );
		\header( 'Retry-After: ' . \Cybermaps\Core\BuildUnavailableException::RETRY_AFTER );
		\header( 'Content-Type: text/plain; charset=utf-8' );
		if ( ! \Cybermaps\Core\ReadOnlyRequest::is_head() ) {
			echo esc_html( $error->getMessage() ) . "\n";
		}
		exit;
	}

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
