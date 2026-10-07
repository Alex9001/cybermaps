<?php
declare(strict_types=1);

namespace Cybermaps\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Bounded read-only adapter for public request metadata. */
final class RequestInput {
	public static function query_text( string $key, int $max_bytes = 128 ): string {
		if ( 1 !== preg_match( '/^[a-zA-Z0-9_-]{1,64}$/D', $key ) ) {
			return '';
		}
		if ( ! isset( $_SERVER['REQUEST_URI'] ) || ! is_scalar( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}
		// Slashing can double accepted bytes; reject larger inputs before copying them.
		if ( is_string( $_SERVER['REQUEST_URI'] ) && isset( $_SERVER['REQUEST_URI'][16384] ) ) {
			return '';
		}
		// Validate literal bytes instead of normalizing percent escapes or URI markup.
		$uri = filter_var(
			wp_unslash( (string) $_SERVER['REQUEST_URI'] ),
			FILTER_CALLBACK,
			array( 'options' => static fn( string $value ): string => 1 === preg_match( '/\A[\x21-\x7E]{1,8192}\z/D', $value ) ? $value : '' )
		);
		if ( '' === $uri ) {
			return '';
		}
		return self::query_value( $uri, $key, $max_bytes );
	}

	private static function query_value( string $uri, string $key, int $max_bytes ): string {
		$query = wp_parse_url( $uri, PHP_URL_QUERY );
		if ( ! is_string( $query ) || strlen( $query ) > 4096 ) {
			return '';
		}
		$values = array();
		wp_parse_str( $query, $values );
		$value = $values[ $key ] ?? null;
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = (string) $value;
		if ( strlen( $value ) > max( 0, $max_bytes ) || 1 === preg_match( '/[\x00-\x1F\x7F]/', $value ) ) {
			return '';
		}
		return sanitize_text_field( $value );
	}

	public static function header( string $name, int $max_bytes = 4096 ): string {
		$key = match ( strtolower( $name ) ) {
			'accept' => 'HTTP_ACCEPT',
			'if-none-match' => 'HTTP_IF_NONE_MATCH',
			'if-modified-since' => 'HTTP_IF_MODIFIED_SINCE',
			default => '',
		};
		if ( '' === $key ) {
			return '';
		}
		if ( ! isset( $_SERVER[ $key ] ) || ! is_scalar( $_SERVER[ $key ] ) ) {
			return '';
		}
		if ( is_string( $_SERVER[ $key ] ) && isset( $_SERVER[ $key ][8192] ) ) {
			return '';
		}
		// Preserve accepted validator bytes; reject controls instead of stripping them.
		$value = filter_var(
			wp_unslash( (string) $_SERVER[ $key ] ),
			FILTER_CALLBACK,
			array( 'options' => static fn( string $value ): string => 1 === preg_match( '/\A[\x20-\x7E]{0,4096}\z/D', $value ) ? $value : '' )
		);
		return strlen( $value ) <= max( 0, $max_bytes ) ? $value : '';
	}
}
