<?php
declare(strict_types=1);

namespace Cybermaps\Admin\Settings\Sanitizers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Bounded transport validation for the schemas owned by option sanitizers. */
final class SettingsSubmission {
	public const MAX_BYTES = 262144;

	/** @return array<string,mixed>|null */
	public static function decode( mixed $input, array $schema ): ?array {
		if ( is_string( $input ) ) {
			if ( strlen( $input ) > self::MAX_BYTES || '{' !== substr( ltrim( $input ), 0, 1 ) ) {
				return null;
			}
			$input = json_decode( $input, true, 8 );
		}
		if ( ! is_array( $input ) || array() === $input || array_is_list( $input ) ) {
			return null;
		}
		if ( ! self::valid_record( $input, $schema ) ) {
			return null;
		}
		// Nested-array fallback is checked before encoding, so depth, collection
		// counts and individual strings already bound this temporary allocation.
		$encoded = wp_json_encode( $input );
		return is_string( $encoded ) && strlen( $encoded ) <= self::MAX_BYTES ? $input : null;
	}

	/** Validate an explicit import record; an empty record means reset. */
	public static function import_record( array $input, array $schema ): ?array {
		return array() === $input ? array() : self::decode( $input, $schema );
	}

	private static function valid_record( array $input, array $schema ): bool {
		if ( count( $input ) > count( $schema ) || array_diff_key( $input, $schema ) ) {
			return false;
		}
		foreach ( $input as $key => $value ) {
			if ( ! self::valid_value( $value, $schema[ $key ] ) ) {
				return false;
			}
		}
		return true;
	}

	private static function valid_value( mixed $value, array $rule ): bool {
		return match ( $rule['type'] ) {
			'text' => is_string( $value ) && strlen( $value ) <= $rule['max'],
			'number' => ( '' === $value && ! empty( $rule['empty'] ) ) || self::valid_number( $value ),
			'id' => ( is_int( $value ) && $value >= 0 ) || ( is_string( $value ) && strlen( $value ) <= 19 && 1 === preg_match( '/^(?:0|[1-9][0-9]*)$/D', $value ) ),
			'flag' => in_array( $value, array( true, false, 0, 1, '', '0', '1', 'true', 'false', 'yes', 'no', 'on', 'off' ), true ),
			'checkbox' => in_array( $value, array( true, false, 0, 1, '', '0', '1' ), true ),
			'map' => self::valid_map( $value, $rule ),
			'list' => is_array( $value ) && array_is_list( $value ) && self::valid_map( $value, $rule + array( 'numeric_keys' => true ) ),
			'record' => is_array( $value ) && self::valid_record( $value, $rule['fields'] ),
			default => false,
		};
	}

	private static function valid_number( mixed $value ): bool {
		return ( is_int( $value ) || is_float( $value ) || is_string( $value ) )
			&& strlen( (string) $value ) <= 64
			&& is_numeric( $value )
			&& is_finite( (float) $value );
	}

	private static function valid_map( mixed $value, array $rule ): bool {
		if ( ! is_array( $value ) || count( $value ) > $rule['max'] ) {
			return false;
		}
		foreach ( $value as $key => $item ) {
			if ( ! self::valid_map_key( $key, $rule ) || ! self::valid_value( $item, $rule['value'] ) ) {
				return false;
			}
		}
		return true;
	}

	private static function valid_map_key( int|string $key, array $rule ): bool {
		if ( isset( $rule['keys'] ) ) {
			return in_array( $key, $rule['keys'], true );
		}
		if ( is_int( $key ) && empty( $rule['numeric_keys'] ) ) {
			return false;
		}
		return strlen( (string) $key ) <= ( $rule['key_max'] ?? 256 );
	}
}
