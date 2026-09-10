<?php
declare(strict_types=1);

namespace Cybermaps\MCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** JSON-RPC dispatch exception with a stable protocol error code. */
final class ProtocolException extends \RuntimeException {
	public function __construct( public readonly int $rpc_code, string $message ) {
		parent::__construct( $message );
	}
}
