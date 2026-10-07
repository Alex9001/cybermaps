<?php
declare(strict_types=1);

namespace Cybermaps\Admin;

use Cybermaps\Core\DatabaseSessionLock;
use Cybermaps\Core\RawOptionStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Authoritative owned-option state, fenced within each SQL mutation. */
final class CloudflareOptionStore {
	public function __construct( private ?DatabaseSessionLock $lock ) {}

	/** @return string|null Exact stored bytes, or no row; failures never mean absence. */
	public static function read( string $option ): ?string {
		global $wpdb;
		$raw = RawOptionStore::read( $wpdb, $option );
		if ( false === $raw ) {
			throw new \RuntimeException( esc_html__( 'Cloudflare operation state could not be read safely. Please retry.', 'cybermaps' ) );
		}
		return $raw;
	}

	/** Replace the observed bytes only while this exact connection owns the lock. */
	public function write( string $option, ?string $before, ?string $next ): void {
		global $wpdb;
		$fence = $this->require_fence();
		try {
			$result = $this->mutate( $wpdb, $option, $before, $next, $fence );
			// A zero affected-row count can be an exact no-op. Verify it without
			// accepting a lost connection, SQL error or a conflicting successor.
			$this->require_fence();
			if ( false === $result || self::read( $option ) !== $next ) {
				throw new \RuntimeException( esc_html__( 'Cloudflare operation state could not be saved. The operation is incomplete; verify the provider before retrying.', 'cybermaps' ) );
			}
			$this->require_fence();
		} finally {
			RawOptionStore::invalidate( $option );
		}
	}

	/** @param array{name:string,connection_id:int} $fence Captured advisory-lock owner. */
	private function mutate( mixed $wpdb, string $option, ?string $before, ?string $next, array $fence ): int|false {
		if ( null === $next ) {
			return null === $before ? 0 : RawOptionStore::remove( $wpdb, $option, $before, $fence );
		}
		return null === $before
			? RawOptionStore::insert( $wpdb, $option, $next, $fence )
			: RawOptionStore::replace( $wpdb, $option, $before, $next, $fence );
	}

	/** @return array{name:string,connection_id:int} */
	private function require_fence(): array {
		$fence = $this->lock?->get_fence();
		if ( null === $fence ) {
			throw new \RuntimeException( esc_html__( 'The Cloudflare operation lost its database lock. Start again.', 'cybermaps' ) );
		}
		return $fence;
	}
}
