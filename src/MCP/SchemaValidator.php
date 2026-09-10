<?php
declare(strict_types=1);

namespace Cybermaps\MCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Small, fail-closed validator for the bounded schemas declared by Core tools. */
final class SchemaValidator {
	/**
	 * @param array<string, mixed> $value  Tool arguments.
	 * @param array<string, mixed> $schema JSON Schema 2020-12 subset.
	 * @return array<int, string>
	 */
	public function validate( array $value, array $schema ): array {
		$properties = is_array( $schema['properties'] ?? null ) ? $schema['properties'] : array();
		return array_merge(
			$this->required_errors( $value, $schema ),
			$this->unknown_errors( $value, $schema, $properties ),
			$this->property_errors( $value, $properties )
		);
	}

	/** @return array<int,string> */
	private function required_errors( array $value, array $schema ): array {
		$errors = array();
		foreach ( is_array( $schema['required'] ?? null ) ? $schema['required'] : array() as $name ) {
			if ( is_string( $name ) && ! array_key_exists( $name, $value ) ) {
				$errors[] = sprintf( 'Missing required argument: %s.', $name );
			}
		}
		return $errors;
	}

	/** @param array<string,mixed> $properties @return array<int,string> */
	private function unknown_errors( array $value, array $schema, array $properties ): array {
		if ( false === ( $schema['additionalProperties'] ?? true ) ) {
			$errors = array();
			foreach ( array_keys( $value ) as $name ) {
				if ( ! array_key_exists( $name, $properties ) ) {
					$errors[] = sprintf( 'Unknown argument: %s.', $name );
				}
			}
			return $errors;
		}
		return array();
	}

	/** @param array<string,mixed> $properties @return array<int,string> */
	private function property_errors( array $value, array $properties ): array {
		$errors = array();
		foreach ( $properties as $name => $property ) {
			if ( ! is_string( $name ) || ! is_array( $property ) || ! array_key_exists( $name, $value ) ) {
				continue;
			}
			$errors = array_merge( $errors, $this->validate_property( $name, $value[ $name ], $property ) );
		}
		return $errors;
	}

	/**
	 * @param array<string, mixed> $schema Property schema.
	 * @return array<int, string>
	 */
	private function validate_property( string $name, mixed $value, array $schema ): array {
		$type = (string) ( $schema['type'] ?? '' );
		return match ( $type ) {
			'string' => $this->validate_string( $name, $value, $schema ),
			'integer' => $this->validate_integer( $name, $value, $schema ),
			'array' => $this->validate_array( $name, $value, $schema ),
			default => array( sprintf( '%s uses an unsupported schema type.', $name ) ),
		};
	}

	private function validate_string( string $name, mixed $value, array $schema ): array {
		if ( ! is_string( $value ) ) {
			return array( sprintf( '%s must be a string.', $name ) ); }
		$length = strlen( $value );
		return $length < (int) ( $schema['minLength'] ?? 0 ) || $length > (int) ( $schema['maxLength'] ?? PHP_INT_MAX ) ? array( sprintf( '%s has an invalid length.', $name ) ) : array();
	}

	private function validate_integer( string $name, mixed $value, array $schema ): array {
		if ( ! is_int( $value ) ) {
			return array( sprintf( '%s must be an integer.', $name ) ); }
		return $value < (int) ( $schema['minimum'] ?? PHP_INT_MIN ) || $value > (int) ( $schema['maximum'] ?? PHP_INT_MAX ) ? array( sprintf( '%s is outside the allowed range.', $name ) ) : array();
	}

	private function validate_array( string $name, mixed $value, array $schema ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			return array( sprintf( '%s must be an array.', $name ) ); }
		$count = count( $value );
		if ( $count < (int) ( $schema['minItems'] ?? 0 ) || $count > (int) ( $schema['maxItems'] ?? PHP_INT_MAX ) ) {
			return array( sprintf( '%s has an invalid item count.', $name ) ); }
		$errors = array();
		$items  = is_array( $schema['items'] ?? null ) ? $schema['items'] : array();
		foreach ( $value as $index => $item ) {
			$errors = array_merge( $errors, $this->validate_property( $name . '[' . $index . ']', $item, $items ) ); }
		return $errors;
	}
}
