<?php
declare(strict_types=1);

namespace Cybermaps\MCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** HMAC-signed, caller-bound, five-minute single-use confirmation service. */
final class OneTimeConfirmationService implements ConfirmationServiceInterface {
	private \Closure $clock;
	private \Closure $nonce_factory;

	public function __construct(
		private readonly ConfirmationStateStoreInterface $store,
		private readonly string $secret,
		?callable $clock = null,
		?callable $nonce_factory = null
	) {
		$this->clock         = \Closure::fromCallable( $clock ?? 'time' );
		$this->nonce_factory = \Closure::fromCallable( $nonce_factory ?? static fn(): string => bin2hex( random_bytes( 16 ) ) );
	}

	public function issue( CallerContext $caller, string $tool, array $arguments ): string {
		$now     = (int) ( $this->clock )();
		$payload = array(
			'user'   => $caller->user_id,
			'client' => $caller->client_id,
			'tool'   => $tool,
			'args'   => self::arguments_hash( $arguments ),
			'exp'    => $now + 300,
			'nonce'  => (string) ( $this->nonce_factory )(),
		);
		$json    = wp_json_encode( $payload );
		$encoded = self::encode( is_string( $json ) ? $json : '{}' );
		$token   = $encoded . '.' . hash_hmac( 'sha256', $encoded, $this->secret );
		$this->store->put( hash( 'sha256', $token ), $payload['exp'] );
		return $token;
	}

	public function consume( string $token, CallerContext $caller, string $tool, array $arguments ): bool {
		$parts = explode( '.', $token, 2 );
		if ( 2 !== count( $parts ) || ! hash_equals( hash_hmac( 'sha256', $parts[0], $this->secret ), $parts[1] ) ) {
			return false;
		}
		$json    = self::decode( $parts[0] );
		$payload = false === $json ? null : json_decode( $json, true );
		$now     = (int) ( $this->clock )();
		if (
			! is_array( $payload )
			|| (string) ( $payload['user'] ?? '' ) !== $caller->user_id
			|| (string) ( $payload['client'] ?? '' ) !== $caller->client_id
			|| (string) ( $payload['tool'] ?? '' ) !== $tool
			|| (string) ( $payload['args'] ?? '' ) !== self::arguments_hash( $arguments )
			|| (int) ( $payload['exp'] ?? 0 ) < $now
		) {
			return false;
		}
		return $this->store->take( hash( 'sha256', $token ), $now );
	}

	/** @param array<string, mixed> $arguments */
	private static function arguments_hash( array $arguments ): string {
		ksort( $arguments, SORT_STRING );
		$json = wp_json_encode( $arguments );
		return hash( 'sha256', is_string( $json ) ? $json : '' );
	}

	private static function encode( string $value ): string {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Token encoding, not obfuscation.
	}

	private static function decode( string $value ): string|false {
		$padding = strlen( $value ) % 4;
		if ( 0 !== $padding ) {
			$value .= str_repeat( '=', 4 - $padding );
		}
		return base64_decode( strtr( $value, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Token decoding, not obfuscation.
	}
}
