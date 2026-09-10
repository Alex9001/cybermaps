<?php
declare(strict_types=1);

namespace Cybermaps\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bounded RFC 9110 media-range negotiation for Markdown representations.
 */
final class AcceptNegotiator {
	private const MAX_HEADER_BYTES = 8192;
	private const MAX_RANGES       = 32;

	/**
	 * Return whether an explicit Markdown range is at least as preferred as HTML.
	 */
	public static function prefers_markdown( mixed $header ): bool {
		if ( ! is_scalar( $header ) ) {
			return false;
		}

		$value = trim( (string) $header );
		if ( '' === $value || self::MAX_HEADER_BYTES < strlen( $value ) || 1 === preg_match( '/[\r\n]/', $value ) ) {
			return false;
		}

		$ranges = self::parse_ranges( $value );
		if ( ! self::has_explicit_markdown( $ranges ) ) {
			return false;
		}

		$markdown = self::quality_for( $ranges, 'text', 'markdown' );
		$html     = self::quality_for( $ranges, 'text', 'html' );
		return 0.0 < $markdown && $markdown >= $html;
	}

	/**
	 * @return array<int,array{type:string,subtype:string,quality:float}>
	 */
	private static function parse_ranges( string $header ): array {
		$ranges = array();
		foreach ( array_slice( explode( ',', $header ), 0, self::MAX_RANGES ) as $candidate ) {
			$range = self::parse_range( trim( $candidate ) );
			if ( null !== $range ) {
				$ranges[] = $range;
			}
		}
		return $ranges;
	}

	/**
	 * @return array{type:string,subtype:string,quality:float}|null
	 */
	private static function parse_range( string $candidate ): ?array {
		if ( '' === $candidate ) {
			return null;
		}

		$parts = array_map( 'trim', explode( ';', $candidate ) );
		$media = strtolower( (string) array_shift( $parts ) );
		if ( 1 !== preg_match( "/^([!#$%&'*+.^_`|~0-9a-z-]+|\\*)\\/([!#$%&'*+.^_`|~0-9a-z-]+|\\*)$/", $media, $matches ) ) {
			return null;
		}
		if ( '*' === $matches[1] && '*' !== $matches[2] ) {
			return null;
		}

		$quality = self::quality_parameter( $parts );
		if ( null === $quality ) {
			return null;
		}

		return array(
			'type'    => $matches[1],
			'subtype' => $matches[2],
			'quality' => $quality,
		);
	}

	/**
	 * @param string[] $parameters Media-range parameters.
	 */
	private static function quality_parameter( array $parameters ): ?float {
		$quality = 1.0;
		foreach ( $parameters as $parameter ) {
			$pair = array_map( 'trim', explode( '=', $parameter, 2 ) );
			if ( 2 !== count( $pair ) || 'q' !== strtolower( $pair[0] ) ) {
				continue;
			}
			if ( 1 !== preg_match( '/^(?:0(?:\.\d{0,3})?|1(?:\.0{0,3})?)$/', $pair[1] ) ) {
				return null;
			}
			$quality = (float) $pair[1];
		}
		return $quality;
	}

	/**
	 * @param array<int,array{type:string,subtype:string,quality:float}> $ranges Parsed ranges.
	 */
	private static function has_explicit_markdown( array $ranges ): bool {
		foreach ( $ranges as $range ) {
			if ( 'text' === $range['type'] && 'markdown' === $range['subtype'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<int,array{type:string,subtype:string,quality:float}> $ranges Parsed ranges.
	 */
	private static function quality_for( array $ranges, string $type, string $subtype ): float {
		$best_specificity = -1;
		$quality          = 0.0;
		foreach ( $ranges as $range ) {
			$specificity = self::matching_specificity( $range, $type, $subtype );
			if ( $specificity < $best_specificity ) {
				continue;
			}
			if ( $specificity > $best_specificity ) {
				$best_specificity = $specificity;
				$quality          = $range['quality'];
				continue;
			}
			$quality = max( $quality, $range['quality'] );
		}
		return $quality;
	}

	/**
	 * @param array{type:string,subtype:string,quality:float} $range Parsed range.
	 */
	private static function matching_specificity( array $range, string $type, string $subtype ): int {
		if ( $type === $range['type'] && $subtype === $range['subtype'] ) {
			return 2;
		}
		if ( $type === $range['type'] && '*' === $range['subtype'] ) {
			return 1;
		}
		return '*' === $range['type'] && '*' === $range['subtype'] ? 0 : -1;
	}
}
