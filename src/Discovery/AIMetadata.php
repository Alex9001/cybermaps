<?php
declare(strict_types=1);

namespace Cybermaps\Discovery;

use Cybermaps\Content\ContentAnalyzer;
use Cybermaps\Content\VisibleTextExtractor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds transparent stored-content metadata without model inference.
 */
final class AIMetadata {
	public const TRANSITION_META_KEY = '_cybermaps_ai_next_transition';
	/**
	 * Calculate AI metadata without mutating a public read request.
	 *
	 * A valid save-time cache is reused when available. Call refresh() from a
	 * write lifecycle when a newly calculated value should be persisted.
	 *
	 * @return array<string, string> Literal/heuristic metadata.
	 */
	public static function calculate( int $post_id ): array {
		return self::resolve( $post_id, false );
	}

	/**
	 * Calculate and persist metadata during a post-write lifecycle.
	 *
	 * @return array<string, string> Literal/heuristic metadata.
	 */
	public static function refresh( int $post_id ): array {
		$metadata = self::resolve( $post_id, true );
		self::refresh_transition_index( $post_id );

		return $metadata;
	}

	/**
	 * Resolve the time-relative freshness label at a specific Unix timestamp.
	 *
	 * Static publications use this to detect label transitions without mutating
	 * the save-time metadata cache.
	 */
	public static function freshness_at( int $post_id, int $timestamp ): string {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}

		return self::get_freshness( $post, max( 0, $timestamp ) );
	}

	/**
	 * Return the next timestamp at which this post's freshness label can change.
	 */
	public static function next_freshness_transition( int $post_id, ?int $after = null ): ?int {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}

		return self::get_post_transition( $post, max( 0, $after ?? time() ) );
	}

	/**
	 * Refresh the compact timestamp used by the static publication scheduler.
	 */
	public static function refresh_transition_index( int $post_id, ?int $after = null ): ?int {
		$transition = self::next_freshness_transition( $post_id, $after );
		if ( null === $transition ) {
			update_post_meta( $post_id, self::TRANSITION_META_KEY, 0 );
			return null;
		}

		update_post_meta( $post_id, self::TRANSITION_META_KEY, $transition );
		return $transition;
	}

	/**
	 * Calculate a transition from a post already loaded by the caller.
	 */
	private static function get_post_transition( \WP_Post $post, int $after ): ?int {
		$created  = self::parse_gmt_date( (string) ( $post->post_date_gmt ?? '' ) );
		$modified = self::parse_gmt_date( (string) ( $post->post_modified_gmt ?? '' ) );
		if ( $created < 1 && $modified < 1 ) {
			return null;
		}

		$candidates = array();
		self::append_new_transition( $candidates, $created, $after );
		$recent_starts = $created > 0 ? $created + ( 90 * DAY_IN_SECONDS ) : 0;
		$recent_ends   = $modified + ( 30 * DAY_IN_SECONDS );
		if ( $modified > 0 && $recent_starts <= $recent_ends ) {
			if ( $recent_starts > $after ) {
				$candidates[] = $recent_starts;
			} elseif ( $recent_ends + 1 > $after ) {
				$candidates[] = $recent_ends + 1;
			}
		}

		return empty( $candidates ) ? null : min( $candidates );
	}

	/**
	 * @param int[] $candidates Candidate transition timestamps.
	 */
	private static function append_new_transition( array &$candidates, int $created, int $after ): void {
		if ( $created < 1 ) {
			return;
		}
		$new_ends = $created + ( 7 * DAY_IN_SECONDS ) + 1;
		if ( $new_ends > $after ) {
			$candidates[] = $new_ends;
		}
	}

	/**
	 * Resolve metadata from the valid cache or stored post content.
	 *
	 * @return array<string, string>
	 */
	private static function resolve( int $post_id, bool $persist ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return array();
		}

		$settings        = \Cybermaps\Core\ConfigurationStore::settings();
		$enable_snippets = self::snippets_enabled( $settings );
		$snippet_config  = $enable_snippets ? '1' : '0';
		$cached          = get_post_meta( $post_id, '_cybermaps_ai_meta', true );
		$raw_last_calc   = get_post_meta( $post_id, '_cybermaps_ai_meta_ts', true );
		$last_calc       = self::last_calculated_timestamp( $raw_last_calc );
		$modified        = self::modified_timestamp( $post );

		if ( '2' === get_post_meta( $post_id, '_cybermaps_ai_meta_version', true ) && self::cache_is_current( $cached, $last_calc, $modified, $snippet_config ) ) {
			// Freshness is relative to the current time, not just to the last
			// content write. Recompute it on every read so a valid save-time
			// content cache cannot leave a post labeled "new" indefinitely.
			$cached['freshness'] = self::get_freshness( $post );
			return $cached;
		}

		$source = (string) ( $post->post_content ?? '' );
		PublicationSizeLimitException::require_capacity( strlen( $source ), 'ai-metadata', 33554431 );
		$visible = ( new ContentAnalyzer( false ) )->visible_text( $source );
		$meta    = array(
			'content_type'     => self::get_content_type( $post ),
			'length_band'      => self::get_length_band( $visible ),
			'freshness'        => self::get_freshness( $post ),
			'snippet'          => $enable_snippets ? self::generate_metadata_excerpt( $visible ) : '',
			'_snippet_enabled' => $snippet_config,
		);

		if ( $persist ) {
			update_post_meta( $post_id, '_cybermaps_ai_meta', $meta );
			update_post_meta( $post_id, '_cybermaps_ai_meta_ts', time() );
			update_post_meta( $post_id, '_cybermaps_ai_meta_version', '2' );
		}

		return $meta;
	}

	private static function last_calculated_timestamp( mixed $value ): int {
		return is_scalar( $value ) && is_numeric( $value )
			? max( 0, (int) $value )
			: 0;
	}

	private static function modified_timestamp( \WP_Post $post ): int {
		$text      = trim( (string) ( $post->post_modified_gmt ?? '' ) );
		$timestamp = '' === $text ? false : strtotime( $text . ' UTC' );
		return false === $timestamp ? 0 : $timestamp;
	}

	/**
	 * @param array<string,mixed> $settings Current settings.
	 */
	private static function snippets_enabled( array $settings ): bool {
		return ! isset( $settings['enable_content_hints'] )
			|| (
				is_scalar( $settings['enable_content_hints'] )
				&& '1' === (string) $settings['enable_content_hints']
			);
	}

	private static function cache_is_current(
		mixed $cached,
		int $last_calc,
		int $modified,
		string $snippet_config
	): bool {
		return is_array( $cached )
			&& $last_calc >= $modified
			&& self::is_valid_cache( $cached, $snippet_config );
	}

	/**
	 * Reject malformed or legacy-unbounded metadata before any public consumer
	 * string-casts it.
	 *
	 * @param array<string,mixed> $cached Saved metadata.
	 */
	private static function is_valid_cache( array $cached, string $snippet_config ): bool {
		if (
			! isset(
				$cached['content_type'],
				$cached['snippet'],
				$cached['length_band'],
				$cached['freshness'],
				$cached['_snippet_enabled']
			)
			|| ! is_scalar( $cached['content_type'] )
			|| ! is_scalar( $cached['snippet'] )
			|| ! is_scalar( $cached['length_band'] )
			|| ! is_scalar( $cached['freshness'] )
			|| ! is_scalar( $cached['_snippet_enabled'] )
		) {
			return false;
		}

		$snippet = (string) $cached['snippet'];
		return in_array(
			(string) $cached['content_type'],
			array( 'Article', 'StaticContent', 'Commerce', 'Multimedia' ),
			true
		)
			&& in_array( (string) $cached['length_band'], array( 'short', 'medium', 'long' ), true )
			&& in_array( (string) $cached['freshness'], array( 'new', 'recently_updated', 'established' ), true )
			&& $snippet_config === (string) $cached['_snippet_enabled']
			&& ( '1' === $snippet_config || '' === $snippet )
			&& PublicationConstraints::bounded_text(
				$snippet,
				PublicationConstraints::AI_SNIPPET_MAX_LENGTH
			) === $snippet;
	}

	/**
	 * Generate a regex- and excerpt-based metadata string.
	 */
	private static function generate_metadata_excerpt( string $visible ): string {
		$content = (string) preg_replace( '/\s+/', ' ', $visible );

		// Retain only the bounded distinct candidates needed by the public hint.
		$entities = self::distinct_candidates( '/\\b(?<!\\. )[A-Z][a-z]{3,}\\b/', $content, 8 );
		$stats    = self::distinct_candidates( '/\\b\\d+(?:\\.\\d+)?%?|\\$\\d+(?:\\.\\d+)?\\b/', $content, 5 );

		// Locate the final nonempty paragraph without allocating every paragraph.
		$visible        = rtrim( $visible );
		$last_newline   = strrpos( $visible, "\n" );
		$last_paragraph = self::excerpt_source( $visible, false === $last_newline ? 0 : $last_newline + 1 );
		$conclusion     = wp_trim_words( $last_paragraph, 30 );

		$snippet = '';
		if ( ! empty( $entities ) ) {
			$snippet .= 'Capitalized terms: ' . implode( ', ', $entities ) . '. ';
		}
		if ( ! empty( $stats ) ) {
			$snippet .= 'Numeric text: ' . implode( ', ', $stats ) . '. ';
		}
		if ( '' !== $conclusion ) {
			$snippet .= 'Closing excerpt: ' . $conclusion;
		}

		if ( strlen( $snippet ) < 50 ) {
			$snippet = wp_trim_words( self::excerpt_source( $content ), 40 );
		}

		return PublicationConstraints::bounded_text(
			trim( $snippet ),
			PublicationConstraints::AI_SNIPPET_MAX_LENGTH
		);
	}

	/** @return string[] */
	private static function distinct_candidates( string $pattern, string $content, int $limit ): array {
		$candidates = array();
		$offset     = 0;
		$count      = 0;
		while ( $count < $limit && 1 === preg_match( $pattern, $content, $match, PREG_OFFSET_CAPTURE, $offset ) ) {
			$offset = $match[0][1] + strlen( $match[0][0] );
			$token  = PublicationConstraints::bounded_text( $match[0][0], PublicationConstraints::AI_SNIPPET_MAX_LENGTH );
			if ( ! in_array( $token, $candidates, true ) ) {
				$candidates[] = $token;
				++$count;
			}
		}
		return $candidates;
	}

	private static function excerpt_source( string $content, int $offset = 0 ): string {
		return function_exists( 'mb_strcut' )
			? (string) mb_strcut( $content, $offset, VisibleTextExtractor::SUMMARY_SOURCE_MAX_BYTES, 'UTF-8' )
			: substr( $content, $offset, VisibleTextExtractor::SUMMARY_SOURCE_MAX_BYTES );
	}

	/**
	 * Determine the stored content-type hint.
	 */
	private static function get_content_type( \WP_Post $post ): string {
		$type     = (string) ( $post->post_type ?? 'post' );
		$articles = array( 'post', 'news', 'tutorial', 'guide' );
		$commerce = array( 'product', 'listing' );

		if ( in_array( $type, $articles, true ) ) {
			return 'Article';
		}
		if ( 'page' === $type ) {
			return 'StaticContent';
		}
		if ( in_array( $type, $commerce, true ) ) {
			return 'Commerce';
		}
		if ( 'attachment' === $type ) {
			return 'Multimedia';
		}

		return 'Article';
	}

	/**
	 * Determine a literal length band using Unicode-aware stored-text tokens.
	 */
	private static function get_length_band( string $content ): string {
		$word_count = ( new VisibleTextExtractor() )->word_count( $content );

		if ( $word_count > 1000 ) {
			return 'long';
		}
		if ( $word_count > 300 ) {
			return 'medium';
		}
		return 'short';
	}

	/**
	 * Determine a date-based freshness label.
	 */
	private static function get_freshness( \WP_Post $post, ?int $timestamp = null ): string {
		$created  = self::parse_gmt_date( (string) ( $post->post_date_gmt ?? '' ) );
		$modified = self::parse_gmt_date( (string) ( $post->post_modified_gmt ?? '' ) );
		$now      = max( 0, $timestamp ?? time() );

		$age_days     = ( $now - $created ) / DAY_IN_SECONDS;
		$mod_age_days = ( $now - $modified ) / DAY_IN_SECONDS;

		if ( $age_days <= 7 ) {
			return 'new';
		}
		if ( $mod_age_days <= 30 && $age_days >= 90 ) {
			return 'recently_updated';
		}

		return 'established';
	}

	/**
	 * Parse a WordPress GMT date without depending on the server timezone.
	 */
	private static function parse_gmt_date( string $date ): int {
		$date      = trim( $date );
		$timestamp = '' === $date ? false : strtotime( $date . ' UTC' );
		return false === $timestamp ? 0 : $timestamp;
	}
}
