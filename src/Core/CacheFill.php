<?php
declare(strict_types=1);

namespace Cybermaps\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Durable mutual exclusion for expensive derived publications. */
final class CacheFill {
	/** @var array<string,OptionLeaseLock> */
	private static array $active         = array();
	private static float $last_heartbeat = 0.0;

	/**
	 * Run only as the owner of a site-scoped, generation-independent fill lock.
	 *
	 * Cache eviction or invalidation must never authorize a second producer.
	 * A database session fence also protects a stalled MySQL worker beyond TTL.
	 *
	 * @param callable():mixed $producer Value producer.
	 * @return mixed
	 */
	public static function run( string $name, int $seconds, callable $producer ): mixed {
		$fence = new DatabaseSessionLock( $name );
		$lease = new OptionLeaseLock( $name, max( 1, $seconds ), 1, $fence->is_supported() );
		if ( ! $lease->acquire() ) {
			throw new BuildUnavailableException( esc_html__( 'Cybermaps is already building this publication. Please retry shortly.', 'cybermaps' ) );
		}
		self::$active[ $name ] = $lease;
		try {
			$value = $producer();
			self::heartbeat( true );
			return $value;
		} finally {
			unset( self::$active[ $name ] );
			$lease->release();
		}
	}

	/** Renew and verify active owners between bounded units of publication work. */
	public static function heartbeat( bool $force = false ): void {
		$now = ( hrtime( true ) / 1e9 );
		if ( ! $force && $now - self::$last_heartbeat < 1.0 ) {
			return;
		}
		self::$last_heartbeat = $now;
		foreach ( self::$active as $lease ) {
			if ( ! $lease->maintain() ) {
				throw new BuildUnavailableException( esc_html__( 'Cybermaps stopped publication because its build lock was lost. Please retry shortly.', 'cybermaps' ) );
			}
		}
	}
}
