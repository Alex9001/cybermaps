<?php
declare(strict_types=1);

namespace Cybermaps\MCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Persistence primitive used to enforce single-use confirmation tokens. */
interface ConfirmationStateStoreInterface {
	public function put( string $key, int $expires_at ): bool;
	public function take( string $key, int $now ): bool;
}
