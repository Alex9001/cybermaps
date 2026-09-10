<?php
declare(strict_types=1);

namespace Cybermaps\MCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Bounded asynchronous task adapter for audit and static reconciliation. */
interface TaskServiceInterface {
	public function supports( string $type ): bool;

	/** @param array<string, mixed> $arguments
	 *  @return array<string, mixed>
	 */
	public function start( string $type, array $arguments, CallerContext $caller ): array;

	/** @return array<string, mixed>|null */
	public function status( string $handle, CallerContext $caller ): ?array;

	public function cancel( string $handle, CallerContext $caller ): bool;
}
