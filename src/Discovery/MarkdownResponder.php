<?php
declare(strict_types=1);

namespace Cybermaps\Discovery;

use Cybermaps\Discovery\LLMSTLDR\TokenBudget;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends bounded Markdown representations with one consistent HTTP contract.
 */
final class MarkdownResponder {
	/**
	 * Send a complete Markdown representation.
	 *
	 * @param string[] $links RFC 8288 Link field values.
	 */
	public function send( string $content, ?int $modified, array $links, bool $negotiated ): never {
		if ( $negotiated ) {
			self::add_vary_accept();
			header( 'X-Cybermaps-Markdown-Source: origin' );
		}
		header( 'Content-Type: text/markdown; charset=utf-8', true );
		header( 'X-Markdown-Tokens: ' . TokenBudget::estimate_tokens( $content ) );
		foreach ( $links as $link ) {
			header( 'Link: ' . $link, false );
		}

		Integrity::send_headers( $content, HOUR_IN_SECONDS, $modified );
		if ( ! \Cybermaps\Core\ReadOnlyRequest::is_head() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Literal Markdown protocol body.
			echo $content;
		}
		exit;
	}

	/**
	 * Merge Accept into any response Vary dimensions already registered.
	 */
	public static function add_vary_accept(): void {
		$values = array();
		foreach ( headers_list() as $header_line ) {
			if ( 0 === stripos( $header_line, 'Vary:' ) ) {
				$values[] = trim( substr( $header_line, 5 ) );
			}
		}
		header_remove( 'Vary' );
		header( 'Vary: ' . PublicationCachePolicy::merge_vary( implode( ',', $values ), array( 'Accept' ) ) );
	}

	/**
	 * Return an authoritative post-modified timestamp when available.
	 */
	public static function modified_timestamp( object $post ): ?int {
		$modified = (string) ( $post->post_modified_gmt ?? $post->post_date_gmt ?? '' );
		if ( '' === $modified || '0000-00-00 00:00:00' === $modified ) {
			return null;
		}
		$timestamp = strtotime( $modified . ' UTC' );
		return false === $timestamp ? null : $timestamp;
	}

	/**
	 * Emit a complete problem response for an oversized representation.
	 */
	public function send_size_limit_error( PublicationSizeLimitException $error, bool $negotiated ): never {
		$payload = wp_json_encode(
			array(
				'type'        => 'about:blank',
				'title'       => __( 'Markdown representation exceeds the safe output limit.', 'cybermaps' ),
				'status'      => 507,
				'detail'      => $error->getMessage(),
				'publication' => $error->get_publication(),
				'max_bytes'   => $error->get_maximum_bytes(),
			),
			JSON_UNESCAPED_SLASHES
		);
		if ( ! is_string( $payload ) ) {
			$payload = '{"title":"Markdown representation exceeds the safe output limit.","status":507}';
		}

		if ( $negotiated ) {
			self::add_vary_accept();
			header( 'X-Cybermaps-Markdown-Source: origin' );
		}
		status_header( 507 );
		nocache_headers();
		header( 'X-Cybermaps-Version: ' . CYBERMAPS_VERSION );
		header( 'X-Cybermaps-Error: publication_too_large' );
		header( "Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'" );
		header( 'Content-Type: application/problem+json; charset=utf-8' );
		if ( ! \Cybermaps\Core\ReadOnlyRequest::is_head() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- RFC 9457 JSON protocol body.
			echo $payload;
		}
		exit;
	}
}
