<?php
declare(strict_types=1);

namespace Cybermaps\MCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Authenticated identity and authorization facts for one MCP request. */
final class CallerContext {
	/** @var array<string, true> */
	private array $scopes = array();

	private \Closure $capability_callback;

	/**
	 * @param array<int, string> $scopes OAuth scopes granted to the caller.
	 * @param callable           $capability_callback WordPress capability checker.
	 */
	public function __construct(
		public readonly string $user_id,
		public readonly string $client_id,
		array $scopes = array(),
		?callable $capability_callback = null
	) {
		foreach ( $scopes as $scope ) {
			if ( is_string( $scope ) && '' !== $scope ) {
				$this->scopes[ $scope ] = true;
			}
		}
		$this->capability_callback = \Closure::fromCallable( $capability_callback ?? static fn(): bool => false );
	}

	public function has_scope( string $scope ): bool {
		return '' === $scope || isset( $this->scopes[ $scope ] );
	}

	public function can( string $capability ): bool {
		return '' === $capability || true === ( $this->capability_callback )( $capability );
	}
}
