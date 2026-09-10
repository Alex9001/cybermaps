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
