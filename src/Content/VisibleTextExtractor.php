<?php
declare(strict_types=1);

namespace Cybermaps\Content;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extracts literal, human-visible text without executing shortcodes or blocks.
 */
final class VisibleTextExtractor {
	public const SUMMARY_SOURCE_MAX_BYTES = 256 * 1024;

	private ContentAnalyzer $analyzer;

	public function __construct( ?ContentAnalyzer $analyzer = null ) {
		$this->analyzer = $analyzer ?? new ContentAnalyzer();
	}

	/**
	 * Normalize stored post content into plain text.
	 */
	public function from_post( object|int $post ): string {
		return (string) $this->analyzer->analyze_post( $post )['text'];
	}

	/**
	 * Preserve bounded-analysis evidence for callers making full-content claims.
	 *
	 * @return array{text:string,complete:bool,source_bytes:int}
	 */
	public function analyze_post( object|int $post ): array {
		$result = $this->analyzer->analyze_post( $post );
		return array(
			'text'         => (string) $result['text'],
			'complete'     => true === $result['complete'],
			'source_bytes' => (int) $result['source_bytes'],
		);
	}

	/**
	 * Normalize arbitrary stored content without rendering dynamic WordPress data.
	 */
	public function normalize( string $content ): string {
		return (string) $this->analyzer->analyze_content( $content )['text'];
	}

	/**
	 * Return an explicit excerpt or a literal leading slice of visible content.
	 */
	public function summary( object|int $post, int $words = 40 ): string {
		$object = is_object( $post ) ? $post : get_post( $post );
		if ( ! is_object( $object ) ) {
			return '';
		}

		$excerpt_source = isset( $object->post_excerpt ) && is_scalar( $object->post_excerpt )
			? (string) $object->post_excerpt
			: '';
		$excerpt        = $this->normalize( $this->leading_bytes( $excerpt_source ) );
		$content_source = isset( $object->post_content ) && is_scalar( $object->post_content )
			? (string) $object->post_content
			: '';
		$text           = '' !== $excerpt
			? $excerpt
			: $this->normalize( $this->leading_bytes( $content_source ) );

		return $this->trim_words( $text, $words );
	}

	/**
	 * Count Unicode word-like sequences.
	 */
	public function word_count( string $text ): int {
		if ( '' === trim( $text ) ) {
			return 0;
		}

		$count = preg_match_all( "/[\\p{L}\\p{N}]+(?:[’'_-][\\p{L}\\p{N}]+)*/u", $text );
		return false === $count ? 0 : (int) $count;
	}

	private function trim_words( string $text, int $limit ): string {
		$limit   = max( 1, $limit );
		$matched = preg_match_all( "/[\\p{L}\\p{N}]+(?:[’'_-][\\p{L}\\p{N}]+)*|[^\\p{L}\\p{N}\\s]+/u", $text, $matches );
		if ( false === $matched || 0 === $matched ) {
			return '';
		}

		$tokens = (array) ( $matches[0] ?? array() );
		$words  = 0;
		$output = array();
		foreach ( $tokens as $token ) {
			if ( 1 === preg_match( '/[\p{L}\p{N}]/u', (string) $token ) ) {
				if ( $words >= $limit ) {
					break;
				}
				++$words;
			}
			$output[] = (string) $token;
		}

		$joined = implode( ' ', $output );
		$joined = preg_replace( '/\s+([.,;:!?%)\]}])/u', '$1', $joined ) ?? $joined;
		$joined = preg_replace( '/([(\[{])\s+/u', '$1', $joined ) ?? $joined;

		return trim( $joined ) . ( $words >= $limit && count( $tokens ) > count( $output ) ? '…' : '' );
	}

	/**
	 * A compact leading extract never needs an unbounded stored-content input.
	 * Cut on a valid UTF-8 boundary before applying regex normalization so one
	 * pathological post cannot multiply request memory just to produce 40–60
	 * words.
	 */
	private function leading_bytes( string $text ): string {
		if ( strlen( $text ) <= self::SUMMARY_SOURCE_MAX_BYTES ) {
			return $text;
		}

		$maximum = self::SUMMARY_SOURCE_MAX_BYTES;
		while ( $maximum > self::SUMMARY_SOURCE_MAX_BYTES - 3 && 0x80 === ( ord( $text[ $maximum ] ) & 0xC0 ) ) {
			--$maximum;
		}
		$prefix = substr( $text, 0, $maximum );
		return 1 === preg_match( '//u', $prefix ) ? $prefix : '';
	}
}
