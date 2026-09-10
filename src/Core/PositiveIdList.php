<?php
declare(strict_types=1);

namespace Cybermaps\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical parser for bounded, comma-delimited positive WordPress IDs.
 */
final class PositiveIdList {
	/**
	 * @param mixed $value Candidate IDs as a CSV string or array.
	 * @return int[]
	 */
	public static function parse( mixed $value, int $limit = PHP_INT_MAX, int $max_bytes = PHP_INT_MAX ): array {
		$items = is_array( $value )
			? $value
			: explode( ',', is_scalar( $value ) ? (string) $value : '' );
		$ids   = array();
		$bytes = 0;

		foreach ( $items as $item ) {
			if ( count( $ids ) >= max( 0, $limit ) || ! is_scalar( $item ) ) {
				if ( count( $ids ) >= max( 0, $limit ) ) {
					break;
				}
				continue;
			}

			$raw = trim( (string) $item );
			if ( '' === $raw || ! ctype_digit( $raw ) ) {
				continue;
			}

			$id = absint( $raw );
			if ( $id <= 0 || in_array( $id, $ids, true ) ) {
				continue;
			}

			$encoded   = (string) $id;
			$increment = strlen( $encoded ) + ( empty( $ids ) ? 0 : 2 );
			if ( $bytes + $increment > max( 0, $max_bytes ) ) {
				break;
			}

			$ids[]  = $id;
			$bytes += $increment;
		}

		return $ids;
	}

	/**
	 * @param mixed $value Candidate IDs as a CSV string or array.
	 */
	public static function to_csv( mixed $value, int $limit = PHP_INT_MAX, int $max_bytes = PHP_INT_MAX ): string {
		return implode( ', ', self::parse( $value, $limit, $max_bytes ) );
	}
}
