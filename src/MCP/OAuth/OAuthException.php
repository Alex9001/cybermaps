<?php
declare(strict_types=1);

namespace Cybermaps\MCP\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A protocol-safe OAuth failure.
 */
final class OAuthException extends \RuntimeException {
	public function __construct( private readonly string $oauth_error, string $message, private readonly int $status_code = 400 ) {
		parent::__construct( $message );
	}

	/**
	 * @return array<string, string>
	 */
	public function to_error_response(): array {
		return array(
			'error'             => $this->oauth_error,
			'error_description' => $this->getMessage(),
		);
	}

	public function status_code(): int {
		return $this->status_code;
	}
}
