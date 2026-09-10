<?php
declare(strict_types=1);

namespace Cybermaps\MCP\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site-local persistence contract for opaque OAuth credentials.
 *
 * All secrets supplied to this interface are one-way hashes. Implementations
 * must provide atomic consumption for authorization codes and refresh tokens.
 */
interface OAuthRepository {
	/** @param array<string, mixed> $client */
	public function save_client( array $client ): void;

	/** @return array<string, mixed>|null */
	public function find_client( string $client_id ): ?array;

	/** @param array<string, mixed> $code */
	public function save_authorization_code( array $code ): void;

	/** @return array<string, mixed>|null */
	public function find_authorization_code( string $code_hash ): ?array;

	/** @return array<string, mixed>|null */
	public function consume_authorization_code( string $code_hash, int $now ): ?array;

	/** @param array<string, mixed> $authorization */
	public function save_device_authorization( array $authorization ): void;

	/** @return array<string, mixed>|null */
	public function find_device_authorization( string $device_code_hash ): ?array;

	/** @return array<string, mixed>|null */
	public function find_device_authorization_by_user_code( string $user_code_hash ): ?array;

	public function update_device_poll( string $device_code_hash, int $polled_at, int $interval ): void;

	public function decide_device_authorization( string $user_code_hash, int $user_id, string $status, int $now ): bool;

	/** @return array<string, mixed>|null */
	public function consume_device_authorization( string $device_code_hash, int $now ): ?array;

	public function delete_expired_device_authorizations( int $now ): int;

	/** @param string[] $scopes */
	public function save_grant( string $client_id, int $user_id, array $scopes, int $now ): void;

	/** @param array<string, mixed> $token */
	public function save_token( array $token ): void;

	/** @return array<string, mixed>|null */
	public function find_token( string $token_hash ): ?array;

	/**
	 * @return array{status:string,token:array<string,mixed>|null}
	 */
	public function consume_refresh_token( string $token_hash, int $now ): array;

	public function revoke_token( string $token_hash, int $now ): void;

	public function revoke_family( string $family_id, int $now ): void;

	public function revoke_client_tokens( string $client_id, int $now ): void;
}
