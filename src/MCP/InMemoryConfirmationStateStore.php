<?php
declare(strict_types=1);

namespace Cybermaps\MCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Request-process store intended for tests and non-persistent adapters. */
final class InMemoryConfirmationStateStore implements ConfirmationStateStoreInterface {
	/** @var array<string, int> */
	private array $states = array();

	public function put( string $key, int $expires_at ): bool {
		$this->states[ $key ] = $expires_at;
		return true;
	}

	public function take( string $key, int $now ): bool {
		$expires_at = $this->states[ $key ] ?? 0;
		unset( $this->states[ $key ] );
		return $expires_at >= $now;
	}
}
