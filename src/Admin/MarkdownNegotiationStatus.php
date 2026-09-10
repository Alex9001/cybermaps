<?php
declare(strict_types=1);

namespace Cybermaps\Admin;

use Cybermaps\Core\URLManager;
use Cybermaps\Discovery\MarkdownNegotiation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public two-variant probe for Markdown content negotiation.
 */
final class MarkdownNegotiationStatus {
	private const MAX_RESPONSE_BYTES = 4 * 1024 * 1024;

	/**
	 * @param array<string,mixed> $settings Settings snapshot.
	 * @return array<string,mixed>
	 */
	public function get_status( array $settings ): array {
		$url = URLManager::get_home_url( '/' );
		if ( ! MarkdownNegotiation::is_enabled( $settings ) ) {
			return self::result( 'disabled', 'disabled', __( 'Native Markdown negotiation is disabled.', 'cybermaps' ), $url );
		}

		$markdown = $this->probe( $url, 'text/markdown, text/html;q=0.8' );
		$html     = $this->probe( $url, 'text/html' );
		return self::evaluate( $markdown, $html, $url );
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function evaluate( mixed $markdown, mixed $html, string $url ): array {
		if ( is_wp_error( $markdown ) || is_wp_error( $html ) ) {
			return self::result( 'unverified', 'unknown', __( 'The public variants could not both be reached; negotiation is not proven broken.', 'cybermaps' ), $url );
		}
		if ( ! self::successful( $markdown ) || ! self::successful( $html ) ) {
			return self::result( 'error', 'unknown', __( 'The public HTML and Markdown probes did not both return a successful response.', 'cybermaps' ), $url );
		}

		$markdown_type = strtolower( self::header_value( $markdown, 'content-type' ) );
		$html_type     = strtolower( self::header_value( $html, 'content-type' ) );
		if ( ! str_starts_with( $markdown_type, 'text/markdown' ) ) {
			return self::result( 'error', 'unknown', __( 'The Markdown request did not return text/markdown.', 'cybermaps' ), $url );
		}
		if ( ! str_starts_with( $html_type, 'text/html' ) ) {
			return self::result( 'error', 'cache', __( 'The default HTML request returned the wrong representation; an intermediary cache may be mixing variants.', 'cybermaps' ), $url );
		}

		$vary_markdown = self::header_value( $markdown, 'vary' );
		$vary_html     = self::header_value( $html, 'vary' );
		if ( ! self::varies_on_accept( $vary_markdown ) || ! self::varies_on_accept( $vary_html ) ) {
			return self::result( 'error', 'cache', __( 'Both public variants must declare Vary: Accept so shared caches keep them separate.', 'cybermaps' ), $url );
		}

		$tokens = self::header_value( $markdown, 'x-markdown-tokens' );
		if ( ! ctype_digit( $tokens ) || (int) $tokens < 1 || '' === wp_remote_retrieve_body( $markdown ) ) {
			return self::result( 'error', 'unknown', __( 'The Markdown response is empty or lacks a valid X-Markdown-Tokens estimate.', 'cybermaps' ), $url );
		}

		$source           = strtolower( self::header_value( $markdown, 'x-cybermaps-markdown-source' ) );
		$provider         = 'origin' === $source ? 'origin' : 'edge';
		$message          = 'origin' === $provider
			? __( 'Cybermaps served a native origin Markdown representation and the HTML variant remained distinct.', 'cybermaps' )
			: __( 'A CDN or edge service supplied Markdown and kept the HTML variant distinct.', 'cybermaps' );
		$result           = self::result( 'healthy', $provider, $message, $url );
		$result['tokens'] = (int) $tokens;
		return $result;
	}

	private function probe( string $url, string $accept ): mixed {
		return wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 2,
				'redirection'         => 2,
				'limit_response_size' => self::MAX_RESPONSE_BYTES,
				'headers'             => array(
					'Accept'                 => $accept,
					'Cache-Control'          => 'no-cache, no-store',
					'Pragma'                 => 'no-cache',
					'X-Cybermaps-Diagnostic' => '1',
				),
			)
		);
	}

	private static function successful( mixed $response ): bool {
		$status = wp_remote_retrieve_response_code( $response );
		return 200 <= $status && 300 > $status;
	}

	private static function varies_on_accept( string $header ): bool {
		return in_array( 'accept', array_map( 'strtolower', array_map( 'trim', explode( ',', $header ) ) ), true );
	}

	private static function header_value( mixed $response, string $name ): string {
		$value = wp_remote_retrieve_header( $response, $name );
		if ( is_array( $value ) ) {
			$parts = array_filter( $value, static fn( mixed $part ): bool => is_scalar( $part ) );
			return implode( ', ', array_map( 'strval', $parts ) );
		}
		return is_scalar( $value ) ? (string) $value : '';
	}

	/** @return array<string,mixed> */
	private static function result( string $status, string $provider, string $message, string $url ): array {
		return array(
			'status'   => $status,
			'provider' => $provider,
			'message'  => $message,
			'url'      => $url,
			'tokens'   => 0,
		);
	}
}
