<?php
declare(strict_types=1);
namespace Cybermaps\Discovery;

use Cybermaps\Content\ContentAnalyzer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Chunker {
	public const MAX_SOURCE_BYTES    = 2 * 1024 * 1024;
	public const MAX_OUTPUT_BYTES    = 4 * 1024 * 1024;
	public const MAX_CHUNKS          = 4096;
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

		PublicationSizeLimitException::require_capacity( strlen( (string) ( $post->post_content ?? '' ) ), 'chunks.json', self::MAX_SOURCE_BYTES );

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
				'bounded-v4:' . $config['window_size'] . ':' . $config['overlap'] . ':' . $freshness . ':' . $intent
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

		$analysis = $this->complete_analysis( $post );
		$text     = (string) $analysis['markdown'];
		$chunks   = array();
		$bytes    = 0;
		foreach ( $this->iterate_segments( $text, $config['window_size'], $config['overlap'] ) as $index => $chunk_text ) {
			$bytes += strlen( (string) wp_json_encode( $chunk_text ) ) + 32;
			PublicationSizeLimitException::require_capacity( $bytes, 'chunks.json', self::MAX_OUTPUT_BYTES );
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

		PublicationSizeLimitException::require_value_capacity( $result, 'chunks.json', self::MAX_OUTPUT_BYTES );
		PublicationSizeLimitException::require_capacity( strlen( (string) wp_json_encode( $result ) ), 'chunks.json', self::MAX_OUTPUT_BYTES );

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

	/** Reject oversized sources and incomplete structure before metadata/chunk work. */
	private function complete_analysis( object $post ): array {
		PublicationSizeLimitException::require_capacity( strlen( (string) ( $post->post_content ?? '' ) ), 'chunks.json', self::MAX_SOURCE_BYTES, 64 );
		$analysis = ( new ContentAnalyzer( $this->cache_enabled ) )->analyze_post( $post );
		if ( empty( $analysis['complete'] ) ) {
			PublicationSizeLimitException::require_capacity( self::MAX_OUTPUT_BYTES + 1, 'chunks.json', self::MAX_OUTPUT_BYTES );
		}
		return $analysis;
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
		if ( $window_size <= 0 ) {
			return array( $text );
		}
		$overlap = $window_size <= $overlap ? (int) round( $window_size / 2 ) : max( 0, $overlap );
		return iterator_to_array( $this->iterate_segments( $text, $window_size, $overlap ), false );
	}

	/** Generate each segment once instead of retaining a second complete list. */
	private function iterate_segments( string $text, int $window_size, int $overlap ): \Generator {
		$length = $this->text_length( $text );
		$step   = max( 1, $window_size - $overlap );
		$count  = $length <= $window_size ? 1 : (int) ceil( $length / $step );
		if ( $count > self::MAX_CHUNKS ) {
			PublicationSizeLimitException::require_capacity( self::MAX_OUTPUT_BYTES + 1, 'chunks.json', self::MAX_OUTPUT_BYTES );
		}
		PublicationSizeLimitException::require_capacity( strlen( $text ) * 2 + $count * 128, 'chunks.json', self::MAX_OUTPUT_BYTES );
		if ( $length <= $window_size ) {
			yield $text;
			return;
		}

		$start = 0;
		$bytes = strlen( $text );
		while ( $start < $bytes ) {
			if ( $this->multibyte_enabled ) {
				$end  = $this->advance_characters( $text, $start, $window_size );
				$next = $this->advance_characters( $text, $start, $step );
			} else {
				$end  = $this->previous_utf8_boundary( $text, min( $bytes, $start + $window_size ), $start );
				$next = 0 === $overlap ? $end : $this->next_utf8_boundary( $text, min( $bytes, $start + $step ) );
			}
			yield substr( $text, $start, $end - $start );
			$start = max( $start + 1, $next );
		}
	}

	/** Move through UTF-8 once per window; avoid rescanning every prior character. */
	private function advance_characters( string $text, int $offset, int $count ): int {
		$length = strlen( $text );
		for ( $index = 0; $index < $count && $offset < $length; ++$index ) {
			$offset = $this->next_utf8_boundary( $text, $offset + 1 );
		}
		return $offset;
	}

	private function previous_utf8_boundary( string $text, int $end, int $start ): int {
		$length = strlen( $text );
		while ( $end > $start && $end < $length && $this->is_utf8_continuation( $text[ $end ] ) ) {
			--$end;
		}
		return $end > $start ? $end : $this->next_utf8_boundary( $text, min( $length, $start + 1 ) );
	}

	/**
	 * Count UTF-8 characters when mbstring exists, or bytes in the fallback.
	 */
	private function text_length( string $text ): int {
		return $this->multibyte_enabled
			? (int) \mb_strlen( $text, 'UTF-8' )
			: \strlen( $text );
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
	 * Extract headers and convert them to markdown-style tags.
	 *
	 * @param string $content HTML content.
	 * @return string
	 */
	public function extract_headers_to_markdown( $content ) {
		return (string) $this->complete_analysis( (object) array( 'post_content' => (string) $content ) )['markdown'];
	}
}
