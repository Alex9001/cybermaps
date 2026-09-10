<?php
declare(strict_types=1);

namespace Cybermaps\MCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Issues and consumes caller-bound single-use mutation confirmations. */
interface ConfirmationServiceInterface {
	/** @param array<string, mixed> $arguments */
	public function issue( CallerContext $caller, string $tool, array $arguments ): string;

	/** @param array<string, mixed> $arguments */
	public function consume( string $token, CallerContext $caller, string $tool, array $arguments ): bool;
}
