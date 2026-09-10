<?php
declare(strict_types=1);

namespace Cybermaps\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical parser for bounded term exclusion IDs and slugs.
 */
final class TermExclusionList {
	/**
	 * @param mixed $value Candidate list as CSV string or array.
	 * @return string[] Normalized unique tokens in first-seen order.
	 */
	public static function parse(
		mixed $value,
		int $limit = \Cybermaps\Discovery\PublicationConstraints::EXCLUSION_ITEMS_MAX,
		int $max_bytes = \Cybermaps\Discovery\PublicationConstraints::EXCLUSION_JOINED_MAX_BYTES
	): array {
		$values = is_array( $value )
			? $value
			: explode( ',', is_scalar( $value ) ? (string) $value : '' );
		$terms  = array();
		$bytes  = 0;

		foreach ( $values as $value_item ) {
			if ( count( $terms ) >= max( 0, $limit ) ) {
				break;
			}
			$term = self::normalized_term( $value_item );
			if ( '' === $term || in_array( $term, $terms, true ) ) {
				continue;
			}

			$increment = strlen( $term ) + ( empty( $terms ) ? 0 : 2 );
			if ( $bytes + $increment > max( 0, $max_bytes ) ) {
				break;
			}

			$terms[] = $term;
			$bytes  += $increment;
		}

		return $terms;
	}

	private static function normalized_term( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$raw = trim( (string) $value );
		if ( '' === $raw ) {
			return '';
		}
		$term = ctype_digit( $raw ) ? (string) absint( $raw ) : sanitize_title( $raw );
		return substr( $term, 0, \Cybermaps\Discovery\PublicationConstraints::EXCLUSION_SLUG_MAX_LENGTH );
	}

	/**
	 * @param mixed $value Candidate list as CSV string or array.
	 */
	public static function to_csv(
		mixed $value,
		int $limit = \Cybermaps\Discovery\PublicationConstraints::EXCLUSION_ITEMS_MAX,
		int $max_bytes = \Cybermaps\Discovery\PublicationConstraints::EXCLUSION_JOINED_MAX_BYTES
	): string {
		return implode( ', ', self::parse( $value, $limit, $max_bytes ) );
	}

	/**
	 * @param object|int|string|null $term WordPress term object or identifier.
	 * @param string[]               $excluded Parsed exclusion tokens.
	 */
	public static function term_matches( mixed $term, array $excluded ): bool {
		if ( empty( $excluded ) ) {
			return false;
		}

		$term_id = '';
		$slug    = '';
		if ( is_object( $term ) ) {
			if ( isset( $term->term_id ) ) {
				$term_id = (string) absint( (int) $term->term_id );
			}
			if ( isset( $term->slug ) ) {
				$slug = sanitize_title( (string) $term->slug );
			}
		} elseif ( is_numeric( $term ) ) {
			$term_id = (string) absint( (int) $term );
		}

		return ( '' !== $term_id && in_array( $term_id, $excluded, true ) )
			|| ( '' !== $slug && in_array( $slug, $excluded, true ) );
	}

	/**
	 * @param int|string $term_id Numeric term ID.
	 * @param string     $slug    Raw slug.
	 * @param string[]   $excluded Parsed exclusion tokens.
	 */
	public static function identifiers_match( int|string $term_id, string $slug, array $excluded ): bool {
		$id_token = (string) absint( (int) $term_id );
		$slug     = sanitize_title( $slug );

		return ( '' !== $id_token && in_array( $id_token, $excluded, true ) )
			|| ( '' !== $slug && in_array( $slug, $excluded, true ) );
	}
}
