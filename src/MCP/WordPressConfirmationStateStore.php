<?php
declare(strict_types=1);

namespace Cybermaps\MCP;

use Cybermaps\Core\AtomicOneTimeStateStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Atomic WordPress-backed confirmation state for normal WordPress requests. */
final class WordPressConfirmationStateStore implements ConfirmationStateStoreInterface {
	private const SCOPE = 'mcp_confirm';

	public function __construct( private readonly ?AtomicOneTimeStateStore $store = null ) {
	}

	public function put( string $key, int $expires_at ): bool {
		$store = $this->store ?? new AtomicOneTimeStateStore();
		return $store->put( self::SCOPE, $key, $expires_at, $expires_at );
	}

	public function take( string $key, int $now ): bool {
		$store      = $this->store ?? new AtomicOneTimeStateStore();
		$expires_at = $store->take( self::SCOPE, $key, $now );
		return is_int( $expires_at ) && $expires_at >= $now;
	}
}
