<?php
declare(strict_types=1);

namespace Cybermaps\MCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Callable task adapter owned by the existing audit/static services. */
final class CallbackTaskService implements TaskServiceInterface {
	/** @var array<string, \Closure> */
	private array $starters = array();
	private \Closure $status_callback;
	private \Closure $cancel_callback;

	/** @param array<string, callable> $starters */
	public function __construct( array $starters, callable $status_callback, callable $cancel_callback ) {
		foreach ( $starters as $type => $callback ) {
			if ( is_string( $type ) && is_callable( $callback ) ) {
				$this->starters[ $type ] = \Closure::fromCallable( $callback );
			}
		}
		$this->status_callback = \Closure::fromCallable( $status_callback );
		$this->cancel_callback = \Closure::fromCallable( $cancel_callback );
	}

	public function supports( string $type ): bool {
		return isset( $this->starters[ $type ] );
	}

	public function start( string $type, array $arguments, CallerContext $caller ): array {
		$result = isset( $this->starters[ $type ] ) ? ( $this->starters[ $type ] )( $arguments, $caller ) : array();
		return is_array( $result ) ? $result : array();
	}

	public function status( string $handle, CallerContext $caller ): ?array {
		$result = ( $this->status_callback )( $handle, $caller );
		return is_array( $result ) ? $result : null;
	}

	public function cancel( string $handle, CallerContext $caller ): bool {
		return true === ( $this->cancel_callback )( $handle, $caller );
	}
}
