<?php
declare(strict_types=1);

namespace Cybermaps\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Signals that a complete publication cannot be assembled within Core's hard
 * in-memory response bound.
 *
 * Callers must treat this as a failed publication. Returning or writing the
 * accumulated prefix would make a file described as complete misleading.
 */
final class PublicationSizeLimitException extends \RuntimeException {
	/** Reject a complete body before allocating its larger derived forms. */
	public static function require_capacity( int $bytes, string $publication, int $maximum_bytes, int $allocation_factor = 8 ): void {
		$limit     = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		$available = $limit > 0 ? max( 0, $limit - memory_get_usage( true ) - 16 * 1024 * 1024 ) : PHP_INT_MAX;
		$maximum   = min( $maximum_bytes, intdiv( $available, max( 1, $allocation_factor ) ) );
		if ( $bytes > $maximum ) {
			throw new self( esc_html( $publication ), (int) esc_html( (string) $maximum ) );
		}
	}

	/**
	 * Bound structured response data before JSON encoding can multiply it.
	 *
	 * @param mixed $value Response data.
	 */
	public static function require_value_capacity( mixed $value, string $publication, int $maximum_bytes ): void {
		$bytes = 0;
		self::count_value_bytes( $value, $bytes, $publication, $maximum_bytes, 0 );
	}

	private static function count_value_bytes( mixed $value, int &$bytes, string $publication, int $maximum_bytes, int $depth ): void {
		if ( $depth > 32 || is_object( $value ) ) {
			self::require_capacity( $maximum_bytes + 1, $publication, $maximum_bytes );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $child ) {
				$bytes += strlen( (string) $key ) + 8;
				self::require_capacity( $bytes, $publication, $maximum_bytes );
				self::count_value_bytes( $child, $bytes, $publication, $maximum_bytes, $depth + 1 );
			}
			return;
		}
		$bytes += is_scalar( $value ) ? strlen( (string) $value ) + 4 : 4;
		self::require_capacity( $bytes, $publication, $maximum_bytes );
	}

	private string $publication;
	private int $maximum_bytes;

	public function __construct( string $publication, int $maximum_bytes ) {
		$this->publication   = sanitize_file_name( basename( $publication ) );
		$this->maximum_bytes = max( 1, $maximum_bytes );

		parent::__construct(
			sprintf(
				/* translators: 1: publication filename, 2: maximum encoded bytes. */
				__(
					'Cybermaps did not publish %1$s because its complete body exceeded the %2$s-byte safety limit. No partial output was returned or written.',
					'cybermaps'
				),
				'' !== $this->publication ? $this->publication : 'LLMS',
				number_format( $this->maximum_bytes )
			)
		);
	}

	public function get_publication(): string {
		return $this->publication;
	}

	public function get_maximum_bytes(): int {
		return $this->maximum_bytes;
	}
}
