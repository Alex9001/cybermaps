<?php
declare(strict_types=1);

namespace Cybermaps\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves registry-backed publication handlers for the router.
 */
final class PublicationHandlerResolver {
	private ADP $adp;

	private \Cybermaps\Core\EndpointRegistry $registry;

	/**
	 * Request-local handler instances keyed by endpoint ID.
	 *
	 * @var array<string, object>
	 */
	private array $handlers = array();

	public function __construct(
		ADP $adp,
		?\Cybermaps\Core\EndpointRegistry $registry = null
	) {
		$this->adp      = $adp;
		$this->registry = $registry ?? \Cybermaps\Core\EndpointRegistry::get_instance();
	}

	/**
	 * @param array<string, mixed> $definition Registry definition.
	 */
	public function resolve( string $handler_class, string $endpoint_id, array $definition ): ?object {
		$cache_key = '' !== $endpoint_id ? $endpoint_id : $handler_class;
		if ( isset( $this->handlers[ $cache_key ] ) ) {
			return $this->handlers[ $cache_key ];
		}

		if ( ADP::class === $handler_class || AIManifest::class === $handler_class ) {
			$handler = $this->adp;
		} else {
			$handler = null;
			if ( \class_exists( $handler_class ) ) {
				$reflection  = new \ReflectionClass( $handler_class );
				$constructor = $reflection->getConstructor();
				if ( null === $constructor || 0 === $constructor->getNumberOfRequiredParameters() ) {
					$handler = $reflection->newInstance();
				}
			}
		}

		/**
		 * Let an extension supply a handler that needs constructor dependencies.
		 *
		 * @param object|null         $handler       Default handler instance.
		 * @param string              $endpoint_id   Registry endpoint ID.
		 * @param array<string,mixed> $definition    Registry definition.
		 */
		$handler = \apply_filters(
			'cybermaps_publication_handler_instance',
			$handler,
			$endpoint_id,
			$definition
		);

		if ( ! \is_object( $handler ) ) {
			return null;
		}

		$this->handlers[ $cache_key ] = $handler;
		return $handler;
	}
}
