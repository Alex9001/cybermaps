<?php
/**
 * User-scoped ephemeral Cloudflare OAuth transaction storage.
 *
 * @package Cybermaps\Admin
 */

declare(strict_types=1);

namespace Cybermaps\Admin;

use Cybermaps\Core\DatabaseSessionLock;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Keeps PKCE material bounded to one short-lived administrator transaction. */
final class CloudflareOAuthTransactionStore {
	public const POINTER_PREFIX = 'cybermaps_cf_oauth_pointer_';
	private const KEY_PREFIX    = 'cybermaps_cf_oauth_';
	private const TTL           = 300;
	private ?\Closure $guard;

	public function __construct( private int $user_id = 0, ?callable $guard = null, private ?DatabaseSessionLock $lock = null ) {
		$this->guard = null !== $guard ? \Closure::fromCallable( $guard ) : null;
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
		$before = CloudflareOptionStore::read( $this->pointer_key() );
		$this->require_restartable();
		$host   = '' !== $environment_host ? $environment_host : CloudflareRuleManager::public_host();
		$record = self::transaction_record( $transaction, $verifier, $operation, $mode, $host );
		self::require_complete_record( $record );

		$this->guard_write();
		$this->persist( $this->record_key( $record['transaction_id'] ), $record, self::TTL );
		$this->guard_write();
		// Only a locked start changes the pointer; terminal writes never replace it.
		( new CloudflareOptionStore( $this->lock ) )->write( $this->pointer_key(), $before, $record['transaction_id'] );
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

	public function authorize_direct( string $state, string $code, string $transaction_id ): bool {
		$transaction = $this->current();
		if ( null === $transaction || ( $transaction['transaction_id'] ?? '' ) !== $transaction_id || 'custom' !== ( $transaction['mode'] ?? '' ) || 'pending' !== ( $transaction['status'] ?? '' ) ) {
			return false;
		}
		$expected = is_scalar( $transaction['state'] ?? null ) ? (string) $transaction['state'] : '';
		if ( '' === $expected || ! hash_equals( $expected, $state ) || '' === $code || strlen( $code ) > 4096 ) {
			return false;
		}
		$transaction['status'] = 'authorized';
		$transaction['code']   = $code;
		$this->write_record( $transaction_id, $transaction );
		return true;
	}

	/** @return array<string,mixed>|null */
	public function current(): ?array {
		$id = CloudflareOptionStore::read( $this->pointer_key() );
		if ( ! is_string( $id ) || '' === $id ) {
			return null;
		}
		$record = get_transient( $this->record_key( $id ) );
		return is_array( $record ) && ( $record['transaction_id'] ?? '' ) === $id ? $record : null;
	}

	/** Reject a second start while the current authorization is still usable. */
	public function require_restartable(): void {
		$record = $this->current();
		if ( in_array( $record['status'] ?? '', array( 'pending', 'authorized' ), true ) ) {
			throw new \RuntimeException( esc_html__( 'Cloudflare authorization is already in progress. Finish it or wait for it to expire before starting again.', 'cybermaps' ) );
		}
	}

	public function fail( string $message, string $transaction_id ): void {
		$this->write_record(
			$transaction_id,
			array(
				'status'     => 'failed',
				'message'    => substr( sanitize_text_field( $message ), 0, 500 ),
				'created_at' => time(),
			)
		);
	}

	/** @param array<string,mixed> $result */
	public function finish( array $result, string $transaction_id ): void {
		$this->write_record(
			$transaction_id,
			array(
				'status'     => 'finished',
				'result'     => $result,
				'created_at' => time(),
			)
		);
	}

	public function clear( string $transaction_id ): void {
		$this->guard_write();
		delete_transient( $this->record_key( $transaction_id ) );
	}

	/**
	 * Record IDs isolate late writes even if the database connection changes.
	 * The Transients API itself does not provide an atomic database-session fence.
	 *
	 * @param array<string,mixed> $record Transaction state without credentials on completion.
	 */
	private function write_record( string $transaction_id, array $record ): void {
		$this->guard_write();
		if ( CloudflareOptionStore::read( $this->pointer_key() ) !== $transaction_id ) {
			throw new \RuntimeException( esc_html__( 'Cloudflare authorization was replaced or expired. Start again.', 'cybermaps' ) );
		}
		$record['transaction_id'] = $transaction_id;
		$this->persist( $this->record_key( $transaction_id ), $record, self::TTL );
	}

	/** @param array<string,mixed>|string $value Transaction record or non-secret pointer. */
	private function persist( string $key, array|string $value, int $ttl ): void {
		if ( ! set_transient( $key, $value, $ttl ) ) {
			throw new \RuntimeException( esc_html__( 'Cloudflare authorization state could not be saved. Start again.', 'cybermaps' ) );
		}
	}

	private function guard_write(): void {
		if ( ! $this->lock?->maintain() ) {
			throw new \RuntimeException( esc_html__( 'The Cloudflare operation lost its database lock. Start again.', 'cybermaps' ) );
		}
		if ( null !== $this->guard ) {
			( $this->guard )();
		}
	}

	private function record_key( string $transaction_id ): string {
		if ( '' === $transaction_id ) {
			throw new \InvalidArgumentException( esc_html__( 'A Cloudflare transaction ID is required.', 'cybermaps' ) );
		}
		return $this->key() . '_' . hash( 'sha256', $transaction_id );
	}

	private function pointer_key(): string {
		$this->key();
		return self::POINTER_PREFIX . $this->user_id;
	}

	private function key(): string {
		if ( $this->user_id < 1 ) {
			throw new \RuntimeException( esc_html__( 'A signed-in administrator is required for Cloudflare authorization.', 'cybermaps' ) );
		}
		return self::KEY_PREFIX . $this->user_id;
	}
}
