<?php
declare(strict_types=1);

namespace Cybermaps\MCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Resolves OAuth and WordPress authorization for one REST request. */
interface CallerContextResolverInterface {
	public function resolve( object $request ): CallerContext;
}
