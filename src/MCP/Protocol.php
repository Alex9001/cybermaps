<?php
declare(strict_types=1);

namespace Cybermaps\MCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** MCP protocol constants supported by Cybermaps. */
final class Protocol {
	public const VERSION = '2026-07-28';
	public const ROUTE   = '/mcp';
	public const MODES   = array( 'off', 'discovery', 'read_only', 'operations' );
}
