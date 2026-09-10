<?php
declare(strict_types=1);

namespace Cybermaps\MCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Callable adapter for registry-backed publication generators. */
final class CallbackResourceReader implements ResourceReaderInterface {
	private \Closure $callback;

	public function __construct( callable $callback ) {
		$this->callback = \Closure::fromCallable( $callback );
	}

	public function read( string $id, array $definition ): ?array {
		$value = ( $this->callback )( $id, $definition );
		if ( ! is_array( $value ) || ! is_string( $value['body'] ?? null ) ) {
			return null;
		}

		return array(
			'body'      => $value['body'],
			'mime_type' => is_string( $value['mime_type'] ?? null ) ? $value['mime_type'] : (string) ( $definition['type'] ?? 'text/plain' ),
		);
	}
}
