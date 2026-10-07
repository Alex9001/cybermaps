<?php
/**
 * Shared analysis of stored WordPress content.
 *
 * @package Cybermaps
 */

declare(strict_types=1);

namespace Cybermaps\Content;

use Cybermaps\Core\CacheManager;
use Cybermaps\Core\URLManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extracts readable text, structural Markdown, headings, and links through one contract.
 */
final class ContentAnalyzer {
	private const ANALYSIS_VERSION           = 4;
	public const MAX_DERIVED_BYTES           = 4194304;
	private const MAX_SOURCE_BYTES           = 33554431;
	private const MAX_PERSISTED_RESULT_BYTES = 524288;
	private const MAX_REQUEST_CACHE_BYTES    = 4194304;
	private const CACHE_TTL                  = 86400;
	private const CACHE_KEY_PREFIX           = 'content_analysis_v4_';
	private const MAX_LINKS                  = 100000;
	private const MAX_HEADINGS               = 10000;
	private const MAX_URL_SEGMENTS           = 4096;

	/** @var array<string, array<string, mixed>> */
	private static array $request_cache = array();

	private static int $request_cache_bytes = 0;

	public function __construct(
		private readonly bool $persistent_cache = true,
		private readonly int $maximum_derived_bytes = self::MAX_DERIVED_BYTES
	) {}

	/**
	 * Analyze a post's stored content.
	 *
	 * @param object|int $post Post object or ID.
	 * @return array<string, mixed>
	 */
	public function analyze_post( object|int $post ): array {
		if ( is_int( $post ) ) {
			$post = get_post( $post );
		}

		if ( ! is_object( $post ) ) {
			return $this->empty_result();
		}

		$content  = isset( $post->post_content ) ? (string) $post->post_content : '';
		$post_id  = isset( $post->ID ) ? (int) $post->ID : 0;
		$base_url = $post_id > 0 ? (string) get_permalink( $post_id ) : '';

		return $this->analyze_content( $content, $base_url );
	}

	/**
	 * Analyze an arbitrary stored-content string.
	 *
	 * @param string $content  Stored content.
	 * @param string $base_url URL used to resolve relative links.
	 * @return array<string, mixed>
	 */
	public function analyze_content( string $content, string $base_url = '' ): array {
		$maximum  = min( self::MAX_SOURCE_BYTES, max( 1, $this->maximum_derived_bytes ) );
		$complete = strlen( $content ) <= $maximum;
		if ( strlen( $base_url ) > $maximum || ! $this->has_memory_headroom( min( strlen( $content ), $maximum ) + strlen( $base_url ) ) ) {
			$result                 = $this->empty_result();
			$result['complete']     = false;
			$result['source_bytes'] = strlen( $content );
			return $result;
		}
		$source = $complete ? $content : $this->source_slice( $content, $maximum );
		$key    = self::CACHE_KEY_PREFIX . hash( 'sha256', self::ANALYSIS_VERSION . ':' . $maximum . "\0" . $base_url . "\0" . $source . "\0" . ( $complete ? '1' : '0' ) );

		if ( isset( self::$request_cache[ $key ] ) ) {
			return self::$request_cache[ $key ];
		}

		if ( $this->persistent_cache ) {
			$cached = CacheManager::get( $key, 'discovery' );
			if ( is_array( $cached ) && self::ANALYSIS_VERSION === (int) ( $cached['version'] ?? 0 ) ) {
				$this->remember( $key, $cached );
				return $cached;
			}
		}

		$structure = $this->extract_structure( $source, $base_url );
		$result    = array(
			'version'      => self::ANALYSIS_VERSION,
			'text'         => $this->normalize_text( $source ),
			'markdown'     => $structure['markdown'],
			'headings'     => $structure['headings'],
			'links'        => $structure['links'],
			'complete'     => $complete && $structure['complete'],
			'source_bytes' => strlen( $content ),
		);

		if ( $result['complete'] && $this->persistent_cache && $this->primary_result_bytes( $result ) <= self::MAX_PERSISTED_RESULT_BYTES ) {
			$encoded = wp_json_encode( $result );
			if ( is_string( $encoded ) && strlen( $encoded ) <= self::MAX_PERSISTED_RESULT_BYTES ) {
				CacheManager::put( $key, $result, self::CACHE_TTL, 'discovery' );
			}
		}

		$this->remember( $key, $result );

		return $result;
	}

	/** Normalize literal text without allocating structural link/heading collections. */
	public function visible_text( string $content ): string {
		return $this->normalize_text( $content );
	}

	/**
	 * Resolve one stored link to a safe public HTTP URL.
	 */
	public function resolve_link_url( string $url, string $base_url ): string {
		return $this->resolve_url( $url, $base_url );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function empty_result(): array {
		return array(
			'version'      => self::ANALYSIS_VERSION,
			'text'         => '',
			'markdown'     => '',
			'headings'     => array(),
			'links'        => array(),
			'complete'     => true,
			'source_bytes' => 0,
		);
	}

	/**
	 * @param string               $key    Cache key.
	 * @param array<string, mixed> $result Analysis result.
	 */
	private function remember( string $key, array $result ): void {
		if ( $this->primary_result_bytes( $result ) > self::MAX_REQUEST_CACHE_BYTES ) {
			return;
		}
		$size = strlen( (string) wp_json_encode( $result ) );
		if ( $size > self::MAX_REQUEST_CACHE_BYTES ) {
			return;
		}

		while ( self::$request_cache && self::$request_cache_bytes + $size > self::MAX_REQUEST_CACHE_BYTES ) {
			$oldest_key = array_key_first( self::$request_cache );
			if ( null === $oldest_key ) {
				break;
			}
			self::$request_cache_bytes -= strlen( (string) wp_json_encode( self::$request_cache[ $oldest_key ] ) );
			unset( self::$request_cache[ $oldest_key ] );
		}

		self::$request_cache[ $key ] = $result;
		self::$request_cache_bytes  += $size;
	}

	/** @param array<string,mixed> $result Analysis result. */
	private function primary_result_bytes( array $result ): int {
		if ( count( (array) ( $result['links'] ?? array() ) ) > 10000 || count( (array) ( $result['headings'] ?? array() ) ) > 5000 ) {
			return self::MAX_REQUEST_CACHE_BYTES + 1;
		}
		return strlen( (string) ( $result['text'] ?? '' ) )
			+ strlen( (string) ( $result['markdown'] ?? '' ) );
	}

	/**
	 * Extract structure with the WordPress HTML API when it is available.
	 *
	 * @param string $content  Stored content.
	 * @param string $base_url Base URL.
	 * @return array{markdown:string,headings:array<int,array{level:int,text:string}>,links:array<int,array{url:string,text:string}>,complete:bool}
	 */
	private function extract_structure( string $content, string $base_url ): array {
		if ( function_exists( 'strip_shortcodes' ) ) {
			$content = strip_shortcodes( $content );
		}
		if ( class_exists( '\\WP_HTML_Tag_Processor' ) ) {
			return $this->extract_with_html_api( $content, $base_url );
		}

		return $this->extract_fallback( $content, $base_url );
	}

	/**
	 * @param string $content  Stored content.
	 * @param string $base_url Base URL.
	 * @return array{markdown:string,headings:array<int,array{level:int,text:string}>,links:array<int,array{url:string,text:string}>,complete:bool}
	 */
	private function extract_with_html_api( string $content, string $base_url ): array {
		$processor               = new \WP_HTML_Tag_Processor( $content );
		$state                   = $this->initial_html_state();
		$state['retained_bytes'] = strlen( $content );

		while ( $processor->next_token() ) {
			if ( '#tag' === $processor->get_token_type() ) {
				$this->process_html_tag( $processor, $state, $base_url );
			} elseif ( '#text' === $processor->get_token_type() ) {
				$this->process_html_text( $processor, $state );
			}
			if ( ! $state['complete'] ) {
				break;
			}
		}

		return array(
			'markdown' => $this->normalize_markdown( $state['markdown'] ),
			'headings' => $state['headings'],
			'links'    => $state['links'],
			'complete' => '' === $state['ignored_tag'] && $state['complete'],
		);
	}

	/** @return array<string,mixed> */
	private function initial_html_state(): array {
		return array(
			'markdown'       => '',
			'headings'       => array(),
			'links'          => array(),
			'ignored_tag'    => '',
			'ignored_depth'  => 0,
			'heading_level'  => 0,
			'heading_text'   => '',
			'active_link'    => null,
			'complete'       => true,
			'retained_bytes' => 0,
		);
	}

	/** @param array<string,mixed> $state Parser state. */
	private function process_html_tag( \WP_HTML_Tag_Processor $processor, array &$state, string $base_url ): void {
		$tag     = strtoupper( (string) $processor->get_token_name() );
		$closing = $processor->is_tag_closer();

		if ( '' !== $state['ignored_tag'] ) {
			$this->advance_ignored_depth( $state, $tag, $closing );
			return;
		}
		if ( ! $closing && in_array( $tag, array( 'SCRIPT', 'STYLE' ), true ) ) {
			return;
		}
		if ( ! $closing && in_array( $tag, array( 'NOSCRIPT', 'TEMPLATE' ), true ) ) {
			$state['ignored_tag']   = $tag;
			$state['ignored_depth'] = 1;
			return;
		}

		$heading_level = $this->heading_level( $tag );
		if ( $heading_level > 0 ) {
			$this->process_heading_tag( $state, $heading_level, $closing );
			return;
		}
		if ( 'A' === $tag ) {
			$this->process_anchor_tag( $processor, $state, $base_url, $closing );
			return;
		}
		$this->append_tag_boundary( $state, $tag, $closing );
	}

	/** @param array<string,mixed> $state Parser state. */
	private function advance_ignored_depth( array &$state, string $tag, bool $closing ): void {
		if ( $tag !== $state['ignored_tag'] ) {
			return;
		}
		$state['ignored_depth'] += $closing ? -1 : 1;
		if ( 0 === $state['ignored_depth'] ) {
			$state['ignored_tag'] = '';
		}
	}

	private function heading_level( string $tag ): int {
		return 1 === preg_match( '/^H([1-6])$/', $tag, $matches ) ? (int) $matches[1] : 0;
	}

	/** @param array<string,mixed> $state Parser state. */
	private function process_heading_tag( array &$state, int $level, bool $closing ): void {
		if ( ! $closing ) {
			$state['heading_level'] = $level;
			$state['heading_text']  = '';
			$this->append_markdown( $state, "\n\n" . str_repeat( '#', $level ) . ' ' );
			return;
		}

		$heading = trim( preg_replace( '/\s+/u', ' ', $state['heading_text'] ) ?? $state['heading_text'] );
		if ( '' !== $heading && count( $state['headings'] ) < self::MAX_HEADINGS && $this->claim_bytes( strlen( $heading ) + 128, $state['retained_bytes'], $state['complete'] ) ) {
			$state['headings'][] = array(
				'level' => $level,
				'text'  => $heading,
			);
		} elseif ( '' !== $heading ) {
			$state['complete'] = false;
		}
		$state['heading_level'] = 0;
		$state['heading_text']  = '';
		$this->append_markdown( $state, "\n\n" );
	}

	/** @param array<string,mixed> $state Parser state. */
	private function process_anchor_tag(
		\WP_HTML_Tag_Processor $processor,
		array &$state,
		string $base_url,
		bool $closing
	): void {
		if ( ! $closing ) {
			$url = $this->bounded_structure_url( (string) $processor->get_attribute( 'href' ), $base_url, $state['retained_bytes'], $state['complete'] );
			if ( '' !== $url && ! $this->claim_bytes( strlen( $url ), $state['retained_bytes'], $state['complete'] ) ) {
				return;
			}
			$state['active_link'] = '' !== $url ? array(
				'url'  => $url,
				'text' => '',
			) : null;
			if ( null !== $state['active_link'] ) {
				$this->append_markdown( $state, '[' );
			}
			return;
		}
		if ( null === $state['active_link'] ) {
			return;
		}

		$link = $state['active_link'];
		if ( count( $state['links'] ) >= self::MAX_LINKS || ! $this->claim_bytes( strlen( $link['url'] ) * 4 + strlen( $link['text'] ) + 132, $state['retained_bytes'], $state['complete'] ) ) {
			$state['complete'] = false;
			return;
		}
		$state['markdown'] .= '](' . $this->markdown_destination( (string) $link['url'] ) . ')';
		if ( $state['complete'] ) {
			$state['links'][] = array(
				'url'  => $link['url'],
				'text' => trim( preg_replace( '/\s+/u', ' ', $link['text'] ) ?? $link['text'] ),
			);
		} else {
			$state['complete'] = false;
		}
		$state['active_link'] = null;
	}

	/** @param array<string,mixed> $state Parser state. */
	private function append_tag_boundary( array &$state, string $tag, bool $closing ): void {
		$block_tags = array( 'ADDRESS', 'ARTICLE', 'ASIDE', 'BLOCKQUOTE', 'DIV', 'DL', 'FIGCAPTION', 'FIGURE', 'FOOTER', 'HEADER', 'MAIN', 'NAV', 'OL', 'P', 'PRE', 'SECTION', 'TABLE', 'UL' );
		if ( 'BR' === $tag ) {
			$this->append_markdown( $state, "\n" );
		} elseif ( 'LI' === $tag && ! $closing ) {
			$this->append_markdown( $state, "\n- " );
		} elseif ( in_array( $tag, $block_tags, true ) ) {
			$this->append_markdown( $state, "\n\n" );
		}
	}

	/** @param array<string,mixed> $state Parser state. */
	private function process_html_text( \WP_HTML_Tag_Processor $processor, array &$state ): void {
		if ( '' !== $state['ignored_tag'] ) {
			return;
		}
		// WordPress has already decoded character references in text tokens.
		$text = (string) $processor->get_modifiable_text();
		$text = str_replace( array( "\r", "\n", "\t" ), ' ', $text );
		$text = preg_replace( '/\s+/u', ' ', $text ) ?? $text;
		if ( '' === $text ) {
			return;
		}

		if ( ! $this->claim_bytes( strlen( $text ) * 4, $state['retained_bytes'], $state['complete'] ) ) {
			return;
		}
		$state['markdown'] .= $this->escape_markdown_text( $text );
		if ( $state['heading_level'] > 0 ) {
			$state['heading_text'] .= $text;
		}
		if ( null !== $state['active_link'] ) {
			$state['active_link']['text'] .= $text;
		}
	}

	/**
	 * Conservative fallback for isolated test environments without the HTML API.
	 *
	 * @param string $content  Stored content.
	 * @param string $base_url Base URL.
	 * @return array{markdown:string,headings:array<int,array{level:int,text:string}>,links:array<int,array{url:string,text:string}>,complete:bool}
	 */
	private function extract_fallback( string $content, string $base_url ): array {
		$complete        = true;
		$content         = $this->remove_hidden_content( $content, $complete );
		$hidden_complete = $complete;
		$complete        = true;
		$retained        = strlen( $content );
		$markdown        = $this->fallback_markdown( $content, $base_url, $retained, $complete );
		$headings        = $this->fallback_headings( $content, $complete, $retained );
		$links           = $this->fallback_links( $content, $base_url, $complete, $retained );

		return array(
			'markdown' => $this->normalize_markdown( $markdown ),
			'headings' => $headings,
			'links'    => $links,
			'complete' => $hidden_complete && $complete,
		);
	}

	/** @return array<int,array{level:int,text:string}> */
	private function fallback_headings( string $content, bool &$complete, int &$retained ): array {
		$headings = array();
		foreach ( $this->fallback_matches( '/<h([1-6])\b[^>]*>(.*?)<\/h\1>/is', $content, $complete ) as $heading_match ) {
			$text = trim( html_entity_decode( wp_strip_all_tags( $heading_match[2] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			if ( '' === $text ) {
				continue;
			}
			if ( count( $headings ) >= self::MAX_HEADINGS || ! $this->claim_bytes( strlen( $text ) + 128, $retained, $complete ) ) {
				$complete = false;
				break;
			}
			$headings[] = array(
				'level' => (int) $heading_match[1],
				'text'  => $text,
			);
		}
		return $headings;
	}

	/** @return array<int,array{url:string,text:string}> */
	private function fallback_links( string $content, string $base_url, bool &$complete, int &$retained ): array {
		$links = array();
		foreach ( $this->fallback_matches( '/<a\b[^>]*href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)<\/a>/is', $content, $complete ) as $link_match ) {
			$url = $this->bounded_structure_url( html_entity_decode( $link_match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $base_url, $retained, $complete );
			if ( '' === $url ) {
				continue;
			}
			if ( count( $links ) >= self::MAX_LINKS || ! $this->claim_bytes( strlen( $url ) + strlen( $link_match[3] ) + 128, $retained, $complete ) ) {
				$complete = false;
				break;
			}
			$links[] = array(
				'url'  => $url,
				'text' => trim( html_entity_decode( wp_strip_all_tags( $link_match[3] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ),
			);
		}
		return $links;
	}

	/**
	 * Scan one match at a time without retaining the complete source match set.
	 *
	 * @return \Generator<int,array<int,string>>
	 */
	private function fallback_matches( string $pattern, string $content, bool &$complete ): \Generator {
		$offset = 0;
		while ( $complete ) {
			$matched = preg_match( $pattern, $content, $matches, PREG_OFFSET_CAPTURE, $offset );
			if ( 1 !== $matched ) {
				if ( false === $matched ) {
					$complete = false;
				}
				return;
			}
			$offset = $matches[0][1] + strlen( $matches[0][0] );
			yield array_column( $matches, 0 );
		}
	}

	private function fallback_markdown( string $content, string $base_url, int &$retained, bool &$complete ): string {
		if ( ! $this->claim_bytes( strlen( $content ), $retained, $complete ) ) {
			return '';
		}
		$markdown = preg_replace_callback(
			'/<h([1-6])\b[^>]*>(.*?)<\/h\1>/is',
			function ( array $parts ) use ( &$retained, &$complete ): string {
				if ( ! $this->claim_bytes( strlen( $parts[2] ) + 12, $retained, $complete ) ) {
					return '';
				}
				return "\n\n" . str_repeat( '#', (int) $parts[1] ) . ' ' . trim( wp_strip_all_tags( $parts[2] ) ) . "\n\n";
			},
			$content
		) ?? $content;
		$markdown = preg_replace_callback(
			'/<a\b[^>]*href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)<\/a>/is',
			function ( array $parts ) use ( $base_url, &$retained, &$complete ): string {
				$text = trim( wp_strip_all_tags( $parts[3] ) );
				$url  = $this->bounded_structure_url( html_entity_decode( $parts[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $base_url, $retained, $complete );
				if ( ! $this->claim_bytes( strlen( $url ) * 3 + strlen( $text ) * 2 + 6, $retained, $complete ) ) {
					return '';
				}
				return '' !== $url ? '[' . $this->escape_markdown_text( $text ) . '](' . $this->markdown_destination( $url ) . ')' : $text;
			},
			$markdown
		) ?? $markdown;
		$markdown = preg_replace( '/<li\b[^>]*>/i', "\n- ", $markdown ) ?? $markdown;
		$markdown = preg_replace( '/<br\s*\/?>/i', "\n", $markdown ) ?? $markdown;
		$markdown = preg_replace( '/<\/(p|div|section|article|blockquote|ul|ol)>/i', "\n\n", $markdown ) ?? $markdown;
		$markdown = wp_strip_all_tags( $markdown );

		return html_entity_decode( $markdown, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/** Remove whole hidden trees, including nested and unclosed templates. */
	private function remove_hidden_content( string $content, bool &$complete ): string {
		$content = preg_replace( '/<!--.*?(?:-->|$)/s', ' ', $content ) ?? $content;
		$content = preg_replace( '#<(script|style)\\b[^>]*>.*?(?:</\\1\\s*>|$)#is', ' ', $content ) ?? $content;
		$pattern = '~<(/?)(template|noscript)\\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>~i';
		$output  = '';
		$cursor  = 0;
		$state   = array(
			'ignored_tag'   => '',
			'ignored_depth' => 0,
		);
		$offset  = 0;
		while ( 1 === preg_match( $pattern, $content, $match, PREG_OFFSET_CAPTURE, $offset ) ) {
			$offset = $match[0][1] + strlen( $match[0][0] );
			$tag    = strtoupper( $match[2][0] );
			$closer = '/' === $match[1][0];
			if ( '' !== $state['ignored_tag'] ) {
				$this->advance_ignored_depth( $state, $tag, $closer );
				if ( '' === $state['ignored_tag'] ) {
					$cursor = $offset;
				}
			} elseif ( ! $closer ) {
				$output                .= substr( $content, $cursor, $match[0][1] - $cursor ) . ' ';
				$state['ignored_tag']   = $tag;
				$state['ignored_depth'] = 1;
			}
		}
		if ( '' !== $state['ignored_tag'] ) {
			$complete = false;
			return $output;
		}
		return $output . substr( $content, $cursor );
	}

	private function normalize_text( string $content ): string {
		$complete = true;
		$content  = $this->remove_hidden_content( $content, $complete );
		if ( function_exists( 'strip_shortcodes' ) ) {
			$content = strip_shortcodes( $content );
		}
		$content = preg_replace(
			'#</?(?:address|article|aside|blockquote|br|dd|div|dl|dt|figcaption|figure|footer|h[1-6]|header|hr|li|main|nav|ol|p|pre|section|table|td|th|tr|ul)\b[^>]*>#i',
			"\n",
			$content
		) ?? $content;
		$content = wp_strip_all_tags( $content, false );
		$content = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$content = preg_replace( '/[^\S\r\n]+/u', ' ', $content ) ?? $content;
		$content = preg_replace( '/ *\R */u', "\n", $content ) ?? $content;
		$content = preg_replace( "/\n{3,}/", "\n\n", $content ) ?? $content;

		return trim( $content );
	}

	/** Reserve retained fields before concatenating strings or adding records. */
	private function claim_bytes( int $bytes, int &$retained, bool &$complete ): bool {
		if ( ! $complete || $bytes > $this->maximum_derived_bytes - $retained || ! $this->has_memory_headroom( $bytes ) ) {
			$complete = false;
			return false;
		}
		$retained += $bytes;
		return true;
	}

	private function has_memory_headroom( int $bytes ): bool {
		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		return $limit <= 0 || $bytes <= intdiv( max( 0, $limit - memory_get_usage( true ) - 16 * 1024 * 1024 ), 8 );
	}

	/** @param array<string,mixed> $state Parser state. */
	private function append_markdown( array &$state, string $text ): void {
		if ( $this->claim_bytes( strlen( $text ), $state['retained_bytes'], $state['complete'] ) ) {
			$state['markdown'] .= $text;
		}
	}

	private function bounded_structure_url( string $url, string $base_url, int $retained, bool &$complete ): string {
		$maximum = max( 0, $this->maximum_derived_bytes - $retained );
		if ( ! $complete || strlen( $url ) + strlen( $base_url ) > $maximum || ! $this->has_memory_headroom( strlen( $url ) + strlen( $base_url ) ) ) {
			$complete = false;
			return '';
		}
		$segments = substr_count( $url, '/' ) + substr_count( $base_url, '/' );
		if ( $segments > self::MAX_URL_SEGMENTS || ! $this->has_memory_headroom( strlen( $url ) + strlen( $base_url ) + $segments * 256 ) ) {
			$complete = false;
			return '';
		}
		$resolved = $this->resolve_url( $url, $base_url );
		if ( strlen( $resolved ) > $maximum || ! $this->has_memory_headroom( strlen( $resolved ) * 4 ) ) {
			$complete = false;
			return '';
		}
		return $resolved;
	}

	private function source_slice( string $content, int $maximum ): string {
		while ( $maximum > 0 && 0x80 === ( ord( $content[ $maximum ] ) & 0xC0 ) ) {
			--$maximum;
		}
		return substr( $content, 0, $maximum );
	}

	private function normalize_markdown( string $markdown ): string {
		$markdown = str_replace( array( "\r\n", "\r" ), "\n", $markdown );
		$markdown = preg_replace( '/[\t ]+/u', ' ', $markdown ) ?? $markdown;
		$markdown = preg_replace( '/ *\n */u', "\n", $markdown ) ?? $markdown;
		$markdown = preg_replace( '/\n{3,}/u', "\n\n", $markdown ) ?? $markdown;

		return trim( $markdown );
	}

	private function escape_markdown_text( string $text ): string {
		return str_replace( array( '\\', '[', ']', '`' ), array( '\\\\', '\\[', '\\]', '\\`' ), $text );
	}

	private function markdown_destination( string $url ): string {
		return str_replace( array( '(', ')' ), array( '%28', '%29' ), $url );
	}

	private function resolve_url( string $url, string $base_url ): string {
		$url = trim( $url );
		if ( '' === $url || $this->has_unsupported_scheme( $url ) ) {
			return '';
		}
		if ( str_starts_with( $url, '#' ) || str_starts_with( $url, '?' ) ) {
			return $this->local_reference_url( $url, $base_url );
		}

		$absolute = $this->absolute_url( $url, $base_url );
		if ( null !== $absolute ) {
			return $absolute;
		}
		if ( '' === $base_url ) {
			return '';
		}
		return $this->relative_url( $url, $base_url );
	}

	private function has_unsupported_scheme( string $url ): bool {
		return 1 === preg_match( '#^[a-z][a-z0-9+.-]*:#i', $url )
			&& 1 !== preg_match( '#^https?://#i', $url );
	}

	private function local_reference_url( string $url, string $base_url ): string {
		if ( '' === $base_url ) {
			return '';
		}
		$base_url = (string) preg_replace( '/#.*$/', '', $base_url );
		if ( str_starts_with( $url, '?' ) ) {
			$base_url = (string) preg_replace( '/\?.*$/', '', $base_url );
		}

		return (string) URLManager::rewrite_url( esc_url_raw( $base_url . $url, array( 'http', 'https' ) ) );
	}

	private function absolute_url( string $url, string $base_url ): ?string {
		if ( preg_match( '#^https?://#i', $url ) ) {
			return (string) URLManager::rewrite_url( esc_url_raw( $url, array( 'http', 'https' ) ) );
		}
		if ( ! str_starts_with( $url, '//' ) ) {
			return null;
		}

		$scheme = (string) wp_parse_url( $base_url, PHP_URL_SCHEME );
		$scheme = '' !== $scheme ? $scheme : 'https';
		return (string) URLManager::rewrite_url( esc_url_raw( $scheme . ':' . $url, array( 'http', 'https' ) ) );
	}

	private function relative_url( string $url, string $base_url ): string {
		$parts = wp_parse_url( $base_url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		$origin = ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'];
		if ( isset( $parts['port'] ) ) {
			$origin .= ':' . (int) $parts['port'];
		}
		if ( str_starts_with( $url, '/' ) ) {
			return (string) URLManager::rewrite_url( esc_url_raw( $origin . $url, array( 'http', 'https' ) ) );
		}

		$base_path      = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		$directory      = str_ends_with( $base_path, '/' ) ? $base_path : rtrim( dirname( $base_path ), '/' ) . '/';
		$path_end       = strcspn( $url, '?#' );
		$url_path       = substr( $url, 0, $path_end );
		$suffix         = substr( $url, $path_end );
		$path           = rtrim( $directory, '/' ) . '/' . $url_path;
		$trailing_slash = str_ends_with( $url_path, '/' );
		$segments       = $this->resolve_path_segments( $path );

		$resolved = $origin . '/' . implode( '/', $segments );
		if ( $trailing_slash ) {
			$resolved = rtrim( $resolved, '/' ) . '/';
		}

		return (string) URLManager::rewrite_url( esc_url_raw( $resolved . $suffix, array( 'http', 'https' ) ) );
	}

	/** @return string[] */
	private function resolve_path_segments( string $path ): array {
		$segments = array();
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				array_pop( $segments );
				continue;
			}
			$segments[] = $segment;
		}
		return $segments;
	}
}
