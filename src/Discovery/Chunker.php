<?php
declare(strict_types=1);
namespace Cybermaps\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Chunker {
	public const DEFAULT_WINDOW_SIZE = 800;
	public const MIN_WINDOW_SIZE     = 100;
	public const MAX_WINDOW_SIZE     = 12000;

	private readonly bool $multibyte_enabled;

	public function __construct(
		private readonly bool $cache_enabled = true,
		?bool $multibyte_enabled = null
	) {
		$functions_available     = \function_exists( 'mb_strlen' )
			&& \function_exists( 'mb_substr' );
		$this->multibyte_enabled = null === $multibyte_enabled
			? $functions_available
			: $multibyte_enabled && $functions_available;
	}

	/**
	 * Get chunks for a specific post.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public function get_chunks( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}

		$settings  = \Cybermaps\Core\ConfigurationStore::settings();
		$config    = self::normalize_configuration( $settings );
		$ai_meta   = \Cybermaps\Discovery\AIMetadata::calculate( (int) $post_id );
		$freshness = (string) ( $ai_meta['freshness'] ?? '' );
		$intent    = \Cybermaps\Discovery\IntentEngine::calculate( $post_id, 'post', $post->post_type );

		// Freshness is time-relative. Including its current label prevents the
		// 24-hour payload cache from replaying a prior label after a transition.
		$config_hash      = substr(
			hash(
				'sha256',
				$config['window_size'] . ':' . $config['overlap'] . ':' . $freshness . ':' . $intent
			),
			0,
			16
		);
		$cache_key        = 'cybermaps_chunks_' . $post_id . '_' . md5( (string) $post->post_modified_gmt ) . '_' . $config_hash;
		$cache_generation = \Cybermaps\Core\CacheManager::get_generation( 'chunks' );
		if ( $this->cache_enabled ) {
			$cached = \Cybermaps\Core\CacheManager::get( $cache_key, 'chunks', $cache_found );
			if ( $cache_found && is_array( $cached ) ) {
				return $cached;
			}
		}

		$text        = $this->sanitize_content( $post->post_content );
		$chunks_text = $this->split_text( $text, $config['window_size'], $config['overlap'] );

		$chunks = array();
		foreach ( $chunks_text as $index => $chunk_text ) {
			$chunks[] = array(
				'index' => $index,
				'text'  => $chunk_text,
			);
		}

		$result = array(
			'post_id'  => (int) $post_id,
			'metadata' => array(
				'freshness'     => (string) ( $ai_meta['freshness'] ?? '' ),
				'length_band'   => (string) ( $ai_meta['length_band'] ?? '' ),
				'intent'        => $intent,
				'last_modified' => get_the_modified_date( 'c', $post_id ),
			),
			'chunks'   => $chunks,
		);

		// Cache for 24 hours — invalidated automatically when post is modified (cache key includes post_modified_gmt hash)
		if ( $this->cache_enabled ) {
			\Cybermaps\Core\CacheManager::set_if_current(
				$cache_key,
				$result,
				DAY_IN_SECONDS,
				'chunks',
				$cache_generation
			);
		}

		return $result;
	}

	/**
	 * Normalize administrator-provided chunk configuration to safe boundaries.
	 *
	 * Overlap is capped at half the chunk size so every step contributes a
	 * meaningful amount of new content and output cannot grow unexpectedly.
	 *
	 * @param array<string, mixed> $settings Settings or a settings-like payload.
	 * @return array{window_size:int,overlap:int}
	 */
	public static function normalize_configuration( array $settings ): array {
		$stored_window = $settings['rag_chunk_size'] ?? null;
		$window_size   = is_scalar( $stored_window ) && is_numeric( $stored_window )
			? (int) $stored_window
			: self::DEFAULT_WINDOW_SIZE;
		$window_size   = max( self::MIN_WINDOW_SIZE, min( self::MAX_WINDOW_SIZE, $window_size ) );

		$stored_overlap = $settings['rag_chunk_overlap'] ?? null;
		$overlap        = is_scalar( $stored_overlap ) && is_numeric( $stored_overlap )
			? (int) $stored_overlap
			: 100;
		$overlap        = max( 0, min( intdiv( $window_size, 2 ), $overlap ) );

		return array(
			'window_size' => $window_size,
			'overlap'     => $overlap,
		);
	}

	/**
	 * Split text into overlapping segments using a sliding window.
	 *
	 * @param string $text        Text to split.
	 * @param int    $window_size Maximum segment length.
	 * @param int    $overlap     Overlap length.
	 * @return array
	 */
	public function split_text( $text, $window_size, $overlap ) {
		$text        = is_scalar( $text ) ? (string) $text : '';
		$window_size = (int) $window_size;
		$overlap     = (int) $overlap;
		$chunks      = array();
		$length      = $this->text_length( $text );

		if ( $length <= $window_size ) {
			return array( $text );
		}

		// Sanity check to prevent infinite loops
		if ( $window_size <= $overlap ) {
			$overlap = round( $window_size / 2 );
		}
		if ( $window_size <= 0 ) {
			return array( $text );
		}

		$start = 0;
		while ( $start < $length ) {
			$chunks[] = $this->text_slice( $text, $start, $window_size );

			$next_start = $start + ( $window_size - $overlap );
			if ( $next_start <= $start ) {
				// Prevent stall
				$start += $window_size;
			} else {
				$start = $next_start;
			}
		}

		return $chunks;
	}

	/**
	 * Count UTF-8 characters when mbstring exists, or bytes in the fallback.
	 */
	private function text_length( string $text ): int {
		return $this->multibyte_enabled
			? (int) \mb_strlen( $text, 'UTF-8' )
			: \strlen( $text );
	}

	/**
	 * Slice text without requiring mbstring.
	 *
	 * The extension-free path treats the configured window as bytes and moves
	 * boundaries away from UTF-8 continuation bytes. ASCII behavior is
	 * identical, while non-ASCII chunks remain valid UTF-8 and bounded.
	 */
	private function text_slice( string $text, int $start, int $length ): string {
		if ( $this->multibyte_enabled ) {
			return (string) \mb_substr( $text, $start, $length, 'UTF-8' );
		}

		$byte_length = \strlen( $text );
		$start       = $this->next_utf8_boundary( $text, max( 0, min( $byte_length, $start ) ) );
		$end         = min( $byte_length, $start + max( 0, $length ) );
		while ( $end > $start && $end < $byte_length && $this->is_utf8_continuation( $text[ $end ] ) ) {
			--$end;
		}
		if ( $end === $start && $end < $byte_length ) {
			$end = $this->next_utf8_boundary( $text, min( $byte_length, $start + 1 ) );
		}

		return \substr( $text, $start, max( 0, $end - $start ) );
	}

	private function next_utf8_boundary( string $text, int $offset ): int {
		$length = \strlen( $text );
		while ( $offset < $length && $this->is_utf8_continuation( $text[ $offset ] ) ) {
			++$offset;
		}
		return $offset;
	}

	private function is_utf8_continuation( string $byte ): bool {
		return 0x80 === ( \ord( $byte ) & 0xC0 );
	}

	/**
	 * Strip HTML while preserving heading text as Markdown.
	 *
	 * @param string $content HTML content.
	 * @return string
	 */
	private function sanitize_content( $content ) {
		// Convert headings for structural context
		$content = $this->extract_headers_to_markdown( $content );
		$content = wp_strip_all_tags( $content );
		return trim( $content );
	}

	/**
	 * Extract headers and convert them to markdown-style tags.
	 *
	 * @param string $content HTML content.
	 * @return string
	 */
	public function extract_headers_to_markdown( $content ) {
		// Comments are not visible page content. Remove them before either the
		// HTML Processor or regex fallback sees heading-like markup inside them.
		$content = preg_replace( '/<!--.*?-->/s', '', $content );

		if ( ! class_exists( 'WP_HTML_Processor' ) ) {
			// Fallback to regex if WP_HTML_Processor is not available (WP < 6.4)
			return preg_replace_callback(
				'/<h([1-6])[^>]*?>(.*?)<\/h\1>/is',
				function ( $matches ) {
					$level  = (int) $matches[1];
					$hashes = str_repeat( '#', $level );
					return "\n\n$hashes " . wp_strip_all_tags( $matches[2] ) . "\n\n";
				},
				$content
			);
		}

		$processor = \WP_HTML_Processor::create_fragment( $content );
		while ( $processor->next_tag( 'H1, H2, H3, H4, H5, H6' ) ) {
			// Mark valid headers to allow in-place replacement while ignoring comments/scripts
			$processor->set_attribute( 'data-cybermaps-marker', $processor->get_tag() );
		}
		$content = $processor->get_updated_html();

		// Replace marked headers with markdown
		return preg_replace_callback(
			'/<h([1-6])(?:\s+[^>]*?)?\s+data-cybermaps-marker="H\1"[^>]*?>(.*?)<\/h\1>/is',
			function ( $matches ) {
				$level  = (int) $matches[1];
				$hashes = str_repeat( '#', $level );
				return "\n\n$hashes " . wp_strip_all_tags( $matches[2] ) . "\n\n";
			},
			$content
		);
	}
}
