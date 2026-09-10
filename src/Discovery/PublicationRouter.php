<?php
declare(strict_types=1);

namespace Cybermaps\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Routes fixed discovery paths from EndpointRegistry to their dynamic handler.
 */
class PublicationRouter {

	private \Cybermaps\Core\EndpointRegistry $registry;

	private PublicationHandlerResolver $handler_resolver;

	public function __construct(
		ADP $adp,
		?\Cybermaps\Core\EndpointRegistry $registry = null,
		?PublicationHandlerResolver $handler_resolver = null
	) {
		$this->registry         = $registry ?? \Cybermaps\Core\EndpointRegistry::get_instance();
		$this->handler_resolver = $handler_resolver ?? new PublicationHandlerResolver( $adp, $this->registry );
	}

	/**
	 * Match the current fixed path and invoke its protocol-specific handler.
	 */
	public function handle(): void {
		$match = $this->registry->match_path(
			(string) \Cybermaps\Core\URLManager::get_request_path()
		);
		if ( null === $match ) {
			return;
		}
		$endpoint_id = (string) ( $match['id'] ?? '' );
		if (
			! Integrity::is_hub_enabled()
			|| ! $this->registry->is_enabled( $endpoint_id )
		) {
			return;
		}
		PublicationRequestGuard::enforce_active_route();
		StaticBridge::get_instance()->request_repair_for_path(
			(string) \Cybermaps\Core\URLManager::get_request_path()
		);

		$definition    = (array) ( $match['definition'] ?? array() );
		$handler_class = isset( $definition['handler_class'] )
			? (string) $definition['handler_class']
			: '';
		if ( '' === $handler_class ) {
			return;
		}

		$handler = $this->handler_resolver->resolve( $handler_class, $endpoint_id, $definition );
		if ( null !== $handler && \is_callable( array( $handler, 'handle' ) ) ) {
			$handler->handle();
			return;
		}

		self::respond_handler_failure( $endpoint_id );
	}

	/**
	 * End an active publication route when its handler cannot be resolved.
	 */
	private static function respond_handler_failure( string $endpoint_id ): void {
		\update_option(
			'cybermaps_publication_handler_error',
			array(
				'endpoint_id' => \sanitize_key( $endpoint_id ),
				'time'        => \time(),
				'message'     => \__( 'A registered discovery publication could not be served.', 'cybermaps' ),
			),
			false
		);

		\status_header( 500 );
		\nocache_headers();
		\header( 'Cache-Control: no-store' );
		\header( 'Content-Type: text/plain; charset=utf-8' );
		\esc_html_e( 'This publication is temporarily unavailable.', 'cybermaps' );
		exit;
	}
}
