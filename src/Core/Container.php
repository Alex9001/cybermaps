<?php
declare(strict_types=1);

namespace Cybermaps\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Container {
	/**
	 * Services and factories.
	 */
	private array $services = array();

	/**
	 * Cached instances.
	 */
	private array $instances = array();

	/**
	 * Register a service or factory.
	 *
	 * @param string $id      Service ID.
	 * @param mixed  $service Service instance or factory closure.
	 */
	public function set( string $id, $service ) {
		$this->services[ $id ] = $service;
	}

	/**
	 * Get a service instance.
	 *
	 * @param string $id Service ID.
	 * @return mixed
	 * @throws \InvalidArgumentException When the service ID is not registered.
	 */
	public function get( string $id ) {
		if ( isset( $this->instances[ $id ] ) ) {
			return $this->instances[ $id ];
		}

		if ( ! isset( $this->services[ $id ] ) ) {
			throw new \InvalidArgumentException(
				sprintf(
					/* translators: %s: internal service identifier. */
					__( 'Cybermaps service "%s" is not registered in the container.', 'cybermaps' ), // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception-only diagnostic; no browser output occurs here.
					$id // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The exact internal service ID is diagnostic data, not HTML output.
				)
			);
		}

		$service = $this->services[ $id ];

		if ( $service instanceof \Closure ) {
			$this->instances[ $id ] = $service( $this );
		} else {
			$this->instances[ $id ] = $service;
		}

		return $this->instances[ $id ];
	}
}
