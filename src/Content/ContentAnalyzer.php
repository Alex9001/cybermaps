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
	private const ANALYSIS_VERSION           = 1;
	private const MAX_SOURCE_BYTES           = 33554431;
	private const MAX_PERSISTED_RESULT_BYTES = 524288;
	private const MAX_REQUEST_CACHE_BYTES    = 4194304;
	private const CACHE_TTL                  = 86400;
	private const CACHE_KEY_PREFIX           = 'content_analysis_v1_';
	private const MAX_LINKS                  = 100000;
	private const MAX_HEADINGS               = 10000;

	/** @var array<string, array<string, mixed>> */
	private static array $request_cache = array();

	private static int $request_cache_bytes = 0;

	public function __construct( private readonly bool $persistent_cache = true ) {}

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
		$complete = strlen( $content ) <= self::MAX_SOURCE_BYTES;
		$source   = $complete ? $content : substr( $content, 0, self::MAX_SOURCE_BYTES );
		$key      = self::CACHE_KEY_PREFIX . hash( 'sha256', self::ANALYSIS_VERSION . "\0" . $base_url . "\0" . $source . "\0" . ( $complete ? '1' : '0' ) );

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

		if ( $this->persistent_cache && $this->primary_result_bytes( $result ) <= self::MAX_PERSISTED_RESULT_BYTES ) {
			$encoded = wp_json_encode( $result );
			if ( is_string( $encoded ) && strlen( $encoded ) <= self::MAX_PERSISTED_RESULT_BYTES ) {
				CacheManager::put( $key, $result, self::CACHE_TTL, 'discovery' );
			}
		}

		$this->remember( $key, $result );

		return $result;
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
		$processor = new \WP_HTML_Tag_Processor( $content );
		$state     = $this->initial_html_state();

		while ( $processor->next_token() ) {
			if ( '#tag' === $processor->get_token_type() ) {
				$this->process_html_tag( $processor, $state, $base_url );
			} elseif ( '#text' === $processor->get_token_type() ) {
				$this->process_html_text( $processor, $state );
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
			'markdown'      => '',
			'headings'      => array(),
			'links'         => array(),
			'ignored_tag'   => '',
			'heading_level' => 0,
			'heading_text'  => '',
			'active_link'   => null,
			'complete'      => true,
		);
	}

	/** @param array<string,mixed> $state Parser state. */
	private function process_html_tag( \WP_HTML_Tag_Processor $processor, array &$state, string $base_url ): void {
		$tag     = strtoupper( (string) $processor->get_token_name() );
		$closing = $processor->is_tag_closer();

		if ( '' !== $state['ignored_tag'] ) {
			if ( $closing && $tag === $state['ignored_tag'] ) {
				$state['ignored_tag'] = '';
			}
			return;
		}
		if ( ! $closing && in_array( $tag, array( 'SCRIPT', 'STYLE' ), true ) ) {
			return;
		}
		if ( ! $closing && in_array( $tag, array( 'NOSCRIPT', 'TEMPLATE' ), true ) ) {
			$state['ignored_tag'] = $tag;
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

	private function heading_level( string $tag ): int {
		return 1 === preg_match( '/^H([1-6])$/', $tag, $matches ) ? (int) $matches[1] : 0;
	}

	/** @param array<string,mixed> $state Parser state. */
	private function process_heading_tag( array &$state, int $level, bool $closing ): void {
		if ( ! $closing ) {
			$state['heading_level'] = $level;
			$state['heading_text']  = '';
			$state['markdown']     .= "\n\n" . str_repeat( '#', $level ) . ' ';
			return;
		}

		$heading = trim( preg_replace( '/\s+/u', ' ', $state['heading_text'] ) ?? $state['heading_text'] );
		if ( '' !== $heading && count( $state['headings'] ) < self::MAX_HEADINGS ) {
			$state['headings'][] = array(
				'level' => $level,
				'text'  => $heading,
			);
		} elseif ( '' !== $heading ) {
			$state['complete'] = false;
		}
		$state['heading_level'] = 0;
		$state['heading_text']  = '';
		$state['markdown']     .= "\n\n";
	}

	/** @param array<string,mixed> $state Parser state. */
	private function process_anchor_tag(
		\WP_HTML_Tag_Processor $processor,
		array &$state,
		string $base_url,
		bool $closing
	): void {
		if ( ! $closing ) {
			$url                  = $this->resolve_url( (string) $processor->get_attribute( 'href' ), $base_url );
			$state['active_link'] = '' !== $url ? array(
				'url'  => $url,
				'text' => '',
			) : null;
			if ( null !== $state['active_link'] ) {
				$state['markdown'] .= '[';
			}
			return;
		}
		if ( null === $state['active_link'] ) {
			return;
		}

		$link               = $state['active_link'];
		$state['markdown'] .= '](' . $this->markdown_destination( (string) $link['url'] ) . ')';
		if ( count( $state['links'] ) < self::MAX_LINKS ) {
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
			$state['markdown'] .= "\n";
		} elseif ( 'LI' === $tag && ! $closing ) {
			$state['markdown'] .= "\n- ";
		} elseif ( in_array( $tag, $block_tags, true ) ) {
			$state['markdown'] .= "\n\n";
		}
	}

	/** @param array<string,mixed> $state Parser state. */
	private function process_html_text( \WP_HTML_Tag_Processor $processor, array &$state ): void {
		if ( '' !== $state['ignored_tag'] ) {
			return;
		}
		$text = html_entity_decode( (string) $processor->get_modifiable_text(), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = str_replace( array( "\r", "\n", "\t" ), ' ', $text );
		$text = preg_replace( '/\s+/u', ' ', $text ) ?? $text;
		if ( '' === $text ) {
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
		$content  = preg_replace( '#<(script|style|template|noscript)\b[^>]*>.*?(?:</\1>|$)#is', ' ', $content ) ?? $content;
		$content  = preg_replace( '/<!--.*?(?:-->|$)/s', ' ', $content ) ?? $content;
		$markdown = $this->fallback_markdown( $content, $base_url );

		return array(
			'markdown' => $this->normalize_markdown( $markdown ),
			'headings' => $this->fallback_headings( $content ),
			'links'    => $this->fallback_links( $content, $base_url ),
			'complete' => true,
		);
	}

	/** @return array<int,array{level:int,text:string}> */
	private function fallback_headings( string $content ): array {
		$headings = array();
		preg_match_all( '/<h([1-6])\b[^>]*>(.*?)<\/h\1>/is', $content, $matches, PREG_SET_ORDER );
		foreach ( $matches as $heading_match ) {
			$headings[] = array(
				'level' => (int) $heading_match[1],
				'text'  => trim( wp_strip_all_tags( html_entity_decode( $heading_match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ),
			);
		}
		return $headings;
	}

	/** @return array<int,array{url:string,text:string}> */
	private function fallback_links( string $content, string $base_url ): array {
		$links = array();
		preg_match_all( '/<a\b[^>]*href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)<\/a>/is', $content, $matches, PREG_SET_ORDER );
		foreach ( $matches as $link_match ) {
			$url = $this->resolve_url( html_entity_decode( $link_match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $base_url );
			if ( '' === $url ) {
				continue;
			}
			$links[] = array(
				'url'  => $url,
				'text' => trim( wp_strip_all_tags( html_entity_decode( $link_match[3], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ),
			);
		}
		return $links;
	}

	private function fallback_markdown( string $content, string $base_url ): string {
		$markdown = preg_replace_callback(
			'/<h([1-6])\b[^>]*>(.*?)<\/h\1>/is',
			static fn( array $parts ): string => "\n\n" . str_repeat( '#', (int) $parts[1] ) . ' ' . trim( wp_strip_all_tags( $parts[2] ) ) . "\n\n",
			$content
		) ?? $content;
		$markdown = preg_replace_callback(
			'/<a\b[^>]*href\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)<\/a>/is',
			function ( array $parts ) use ( $base_url ): string {
				$text = trim( wp_strip_all_tags( $parts[3] ) );
				$url  = $this->resolve_url( html_entity_decode( $parts[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $base_url );
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

	private function normalize_text( string $content ): string {
		$content = preg_replace( '#<(script|style|template|noscript)\b[^>]*>.*?(?:</\1>|$)#is', ' ', $content ) ?? $content;
		$content = preg_replace( '/<!--.*?(?:-->|$)/s', ' ', $content ) ?? $content;
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
		$path           = rtrim( $directory, '/' ) . '/' . $url;
		$url_path       = (string) ( preg_split( '/[?#]/', $url, 2 )[0] ?? '' );
		$trailing_slash = str_ends_with( $url_path, '/' );
		$segments       = $this->resolve_path_segments( $path );

		$resolved = $origin . '/' . implode( '/', $segments );
		if ( $trailing_slash ) {
			$resolved = rtrim( $resolved, '/' ) . '/';
		}

		return (string) URLManager::rewrite_url( esc_url_raw( $resolved, array( 'http', 'https' ) ) );
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
