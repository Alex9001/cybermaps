<?php
/**
 * User-scoped ephemeral Cloudflare OAuth transaction storage.
 *
 * @package Cybermaps\Admin
 */

declare(strict_types=1);

namespace Cybermaps\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Keeps PKCE material bounded to one short-lived administrator transaction. */
final class CloudflareOAuthTransactionStore {
	private const KEY_PREFIX = 'cybermaps_cf_oauth_';
	private const TTL        = 300;

	public function __construct( private int $user_id = 0 ) {
		if ( $this->user_id < 1 ) {
			$this->user_id = get_current_user_id();
		}
	}

	/** @param array<string,mixed> $transaction */
	public function begin( array $transaction, #[\SensitiveParameter] string $verifier, string $operation, string $mode = 'managed', string $environment_host = '' ): void {
		if ( ! in_array( $operation, array( 'install', 'remove' ), true ) ) {
			throw new \InvalidArgumentException( esc_html__( 'The Cloudflare operation is invalid.', 'cybermaps' ) );
		}
		if ( ! in_array( $mode, array( 'managed', 'custom' ), true ) ) {
			throw new \InvalidArgumentException( esc_html__( 'The Cloudflare OAuth mode is invalid.', 'cybermaps' ) );
		}
		$host   = '' !== $environment_host ? $environment_host : CloudflareRuleManager::public_host();
		$record = self::transaction_record( $transaction, $verifier, $operation, $mode, $host );
		self::require_complete_record( $record );
		set_transient( $this->key(), $record, self::TTL );
	}

	/** @param array<string,mixed> $transaction @return array<string,mixed> */
	private static function transaction_record( array $transaction, #[\SensitiveParameter] string $verifier, string $operation, string $mode, string $environment_host ): array {
		return array(
			'status'           => 'pending',
			'mode'             => $mode,
			'operation'        => $operation,
			'transaction_id'   => (string) ( $transaction['transaction_id'] ?? '' ),
			'consume_secret'   => (string) ( $transaction['consume_secret'] ?? '' ),
			'state'            => (string) ( $transaction['state'] ?? '' ),
			'client_id'        => (string) ( $transaction['client_id'] ?? '' ),
			'redirect_uri'     => (string) ( $transaction['redirect_uri'] ?? '' ),
			'code_verifier'    => $verifier,
			'environment_host' => strtolower( trim( $environment_host, '.' ) ),
			'created_at'       => time(),
		);
	}

	/** @param array<string,mixed> $record */
	private static function require_complete_record( array $record ): void {
		$required = array( 'transaction_id', 'consume_secret', 'state', 'client_id', 'redirect_uri', 'code_verifier', 'environment_host' );
		foreach ( $required as $key ) {
			if ( '' === $record[ $key ] ) {
				throw new \InvalidArgumentException( esc_html__( 'The Cloudflare transaction is incomplete.', 'cybermaps' ) );
			}
		}
	}

	public function authorize_direct( string $state, string $code ): bool {
		$transaction = $this->current();
		if ( null === $transaction || 'custom' !== ( $transaction['mode'] ?? '' ) || 'pending' !== ( $transaction['status'] ?? '' ) ) {
			return false;
		}
		$expected = is_scalar( $transaction['state'] ?? null ) ? (string) $transaction['state'] : '';
		if ( '' === $expected || ! hash_equals( $expected, $state ) || '' === $code || strlen( $code ) > 4096 ) {
			return false;
		}
		$transaction['status'] = 'authorized';
		$transaction['code']   = $code;
		set_transient( $this->key(), $transaction, self::TTL );
		return true;
	}

	/** @return array<string,mixed>|null */
	public function current(): ?array {
		$record = get_transient( $this->key() );
		return is_array( $record ) ? $record : null;
	}

	public function fail( string $message ): void {
		set_transient(
			$this->key(),
			array(
				'status'     => 'failed',
				'message'    => substr( sanitize_text_field( $message ), 0, 500 ),
				'created_at' => time(),
			),
			self::TTL
		);
	}

	/** @param array<string,mixed> $result */
	public function finish( array $result ): void {
		set_transient(
			$this->key(),
			array(
				'status'     => 'finished',
				'result'     => $result,
				'created_at' => time(),
			),
			self::TTL
		);
	}

	public function clear(): void {
		delete_transient( $this->key() );
	}

	private function key(): string {
		if ( $this->user_id < 1 ) {
			throw new \RuntimeException( esc_html__( 'A signed-in administrator is required for Cloudflare authorization.', 'cybermaps' ) );
		}
		return self::KEY_PREFIX . $this->user_id;
	}
}
