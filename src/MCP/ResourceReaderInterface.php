<?php
declare(strict_types=1);

namespace Cybermaps\MCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Reads a publication already allowlisted by EndpointRegistry. */
interface ResourceReaderInterface {
	/**
	 * @param array<string, mixed> $definition Registry definition.
	 * @return array{body: string, mime_type: string}|null
	 */
	public function read( string $id, array $definition ): ?array;
}
