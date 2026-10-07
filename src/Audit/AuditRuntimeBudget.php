<?php
declare(strict_types=1);

namespace Cybermaps\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Cooperative fallback when a cache drop-in cannot release request-local data. */
final class AuditRuntimeBudget {
	public const MAX_HYDRATIONS = 1000;
	private const MAX_SECONDS   = 20;
	private const MIN_HEADROOM  = 32 * 1024 * 1024;
	private int $hydrations     = 0;
	private float $started;

	public function __construct( private int $maximum = self::MAX_HYDRATIONS ) {
		$this->maximum = max( 0, min( self::MAX_HYDRATIONS, $maximum ) );
		$this->started = hrtime( true ) / 1e9;
	}

	public static function cleanup_supported(): bool {
		return function_exists( 'wp_cache_flush_runtime' )
			&& function_exists( 'wp_cache_supports' )
			&& wp_cache_supports( 'flush_runtime' );
	}

	/**
	 * Count candidate hydrations across both passes, not unique resources. Check
	 * before database batch hydration; this is not a hard query deadline or proof
	 * that one unusually large WordPress row or batch fits the remaining memory.
	 */
	public function claim( int $candidates = 0 ): void {
		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		if (
			$candidates < 0 || $candidates > $this->maximum - $this->hydrations
			|| hrtime( true ) / 1e9 - $this->started >= self::MAX_SECONDS
			|| ( $limit > 0 && $limit - memory_get_usage( true ) < self::MIN_HEADROOM )
		) {
			throw new \RuntimeException( esc_html__( 'Cybermaps stopped this content report at its safety budget because the object cache cannot release runtime data. No partial report was completed. Use an object-cache implementation with runtime cleanup before retrying a larger report.', 'cybermaps' ) );
		}
		$this->hydrations += $candidates;
	}
}
