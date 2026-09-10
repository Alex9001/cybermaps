<?php
declare(strict_types=1);

namespace Cybermaps\Discovery\LLMSTLDR;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rough token estimation for TL;DR output budgeting.
 */
class TokenBudget {
	/**
	 * Estimate token count from text (chars / 4 heuristic).
	 *
	 * @param string $text Content.
	 * @return int
	 */
	public static function estimate_tokens( string $text ): int {
		return self::estimate_bytes( strlen( $text ) );
	}

	/**
	 * Estimate tokens from an encoded byte count without constructing a
	 * temporary concatenated publication.
	 */
	public static function estimate_bytes( int $bytes ): int {
		if ( $bytes <= 0 ) {
			return 0;
		}
		return (int) max( 1, (int) ceil( $bytes / 4 ) );
	}
}
