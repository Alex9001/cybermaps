<?php
declare(strict_types=1);

namespace Cybermaps\MCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Callable adapter for the owning OAuth service. */
final class CallbackCallerContextResolver implements CallerContextResolverInterface {
	private \Closure $callback;

	public function __construct( callable $callback ) {
		$this->callback = \Closure::fromCallable( $callback );
	}

	public function resolve( object $request ): CallerContext {
		$context = ( $this->callback )( $request );
		return $context instanceof CallerContext ? $context : new CallerContext( '', '' );
	}
}
