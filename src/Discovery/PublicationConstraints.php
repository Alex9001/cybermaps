<?php
declare(strict_types=1);

namespace Cybermaps\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared resource bounds for administrator input and public AI publications.
 */
final class PublicationConstraints {
	public const AI_SITEMAP_LIMIT_MIN     = 1;
	public const AI_SITEMAP_LIMIT_MAX     = 2000;
	public const AI_SITEMAP_LIMIT_DEFAULT = 100;

	public const FEED_LIMIT_MIN     = 1;
	public const FEED_LIMIT_MAX     = 100;
	public const FEED_LIMIT_DEFAULT = 10;

	public const LLMS_LINK_LIMIT_MIN     = 20;
	public const LLMS_LINK_LIMIT_MAX     = 500;
	public const LLMS_LINK_LIMIT_DEFAULT = 100;

	public const CUSTOM_LINKS_MAX               = 100;
	public const ACTION_MAPPINGS_MAX            = 100;
	public const BRIEFING_PINNED_IDS_MAX        = 100;
	public const SUMMARY_CANDIDATE_SCAN_MAX     = 1000;
	public const BRIEFING_CANDIDATE_SCAN_MAX    = 250;
	public const BRIEFING_TOKEN_BUDGET_MIN      = 1000;
	public const BRIEFING_TOKEN_BUDGET_MAX      = 200000;
	public const BRIEFING_TOKEN_BUDGET_DEFAULT  = 80000;
	public const BRIEFING_OUTPUT_MAX_BYTES      = self::BRIEFING_TOKEN_BUDGET_MAX * 4;
	public const MISSION_MAX_LENGTH             = 2048;
	public const PUBLISHER_GUIDANCE_MAX_LENGTH  = 16384;
	public const SITE_GUIDE_ADDITION_MAX_LENGTH = 8192;
	public const AI_SNIPPET_MAX_LENGTH          = 1024;
	public const SEARCH_QUERY_MAX_LENGTH        = 200;
	public const SEARCH_TITLE_MAX_LENGTH        = 512;
	public const PUBLICATION_NAME_MAX_LENGTH    = 256;
	public const PUBLICATION_TYPE_ITEMS_MAX     = 100;
	public const TAXONOMY_FILTER_ITEMS_MAX      = 100;
	public const TAXONOMY_NAME_MAX_LENGTH       = 32;
	public const TAXONOMY_FILTER_MAX_LENGTH     =
		( self::TAXONOMY_FILTER_ITEMS_MAX * self::TAXONOMY_NAME_MAX_LENGTH )
		+ ( ( self::TAXONOMY_FILTER_ITEMS_MAX - 1 ) * 2 );
	public const TOPICS_MAX                     = 25;
	public const TOPIC_LABEL_MAX_LENGTH         = 64;
	public const TOPICS_TOTAL_MAX_LENGTH        = 2048;
	public const EXCLUSION_ITEMS_MAX            = 1000;
	public const EXCLUSION_JOINED_MAX_BYTES     = 16384;
	public const EXCLUSION_SLUG_MAX_LENGTH      = 191;
	public const CONTENT_GROUP_MAP_MAX          = 200;

	/**
	 * Schema.org action types supported by the action manager.
	 *
	 * @var string[]
	 */
	public const ACTION_TYPES = array(
		'ContactAction',
		'BuyAction',
		'ReserveAction',
		'SubscribeAction',
		'SearchAction',
	);

	/**
	 * License assertions offered by the settings interface.
	 *
	 * @var string[]
	 */
	public const CONTENT_LICENSES = array(
		'',
		'CC-BY-4.0',
		'CC-BY-SA-4.0',
		'CC-BY-NC-4.0',
		'CC0-1.0',
		'MIT',
		'GPL-3.0',
		'Apache-2.0',
		'Proprietary',
	);

	/**
	 * Clamp the per-post-type AI sitemap selection limit.
	 *
	 * @param mixed $value Candidate value.
	 */
	public static function ai_sitemap_limit( $value ): int {
		return max(
			self::AI_SITEMAP_LIMIT_MIN,
			min( self::AI_SITEMAP_LIMIT_MAX, absint( $value ) )
		);
	}

	/**
	 * Clamp the JSON Feed item limit.
	 *
	 * @param mixed $value Candidate value.
	 */
	public static function feed_limit( $value ): int {
		return max(
			self::FEED_LIMIT_MIN,
			min( self::FEED_LIMIT_MAX, absint( $value ) )
		);
	}

	/**
	 * Clamp the number of resource links published in concise llms.txt output.
	 *
	 * @param mixed $value Candidate value.
	 */
	public static function llms_link_limit( $value ): int {
		return max(
			self::LLMS_LINK_LIMIT_MIN,
			min( self::LLMS_LINK_LIMIT_MAX, absint( $value ) )
		);
	}

	/**
	 * Return the canonical case-sensitive Schema.org action type.
	 *
	 * Older Cybermaps sanitization lowercased saved action types. Accepting those
	 * spellings here repairs their public representation without discarding the
	 * administrator's existing mapping.
	 *
	 * @param mixed $value Candidate type.
	 */
	public static function action_type( $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$candidate = strtolower( trim( (string) $value ) );
		foreach ( self::ACTION_TYPES as $type ) {
			if ( strtolower( $type ) === $candidate ) {
				return $type;
			}
		}

		return '';
	}

	/**
	 * Return one supported operator-authored license assertion.
	 */
	public static function content_license( mixed $value ): string {
		$license = is_scalar( $value )
			? sanitize_text_field( (string) $value )
			: '';

		return in_array( $license, self::CONTENT_LICENSES, true ) ? $license : '';
	}

	/**
	 * Bound publisher-authored text by Unicode characters and encoded bytes.
	 */
	public static function bounded_text( mixed $value, int $maximum ): string {
		if ( ! is_scalar( $value ) || $maximum < 1 ) {
			return '';
		}

		$text = (string) $value;
		if ( function_exists( 'mb_substr' ) ) {
			$text = mb_substr( $text, 0, $maximum, 'UTF-8' );
		} else {
			$text = substr( $text, 0, $maximum );
			while ( '' !== $text && 1 !== preg_match( '//u', $text ) ) {
				$text = substr( $text, 0, -1 );
			}
		}

		if ( strlen( $text ) > $maximum ) {
			if ( function_exists( 'mb_strcut' ) ) {
				$text = mb_strcut( $text, 0, $maximum, 'UTF-8' );
			} else {
				$text = substr( $text, 0, $maximum );
				while ( '' !== $text && 1 !== preg_match( '//u', $text ) ) {
					$text = substr( $text, 0, -1 );
				}
			}
		}

		return $text;
	}

	/**
	 * Normalize bounded, non-empty, unique topic labels.
	 *
	 * @return string[]
	 */
	public static function topics( mixed $value ): array {
		$values = is_array( $value ) ? $value : explode( ',', is_scalar( $value ) ? (string) $value : '' );
		$topics = array();
		$seen   = array();
		$bytes  = 0;
		foreach ( $values as $candidate ) {
			if ( count( $topics ) >= self::TOPICS_MAX ) {
				break;
			}
			$normalized = self::normalize_topic( $candidate );
			if ( null === $normalized ) {
				continue;
			}
			$topic    = $normalized['topic'];
			$identity = $normalized['identity'];
			if ( isset( $seen[ $identity ] ) ) {
				continue;
			}

			$separator_bytes = empty( $topics ) ? 0 : 2;
			if ( $bytes + $separator_bytes + strlen( $topic ) > self::TOPICS_TOTAL_MAX_LENGTH ) {
				break;
			}
			$topics[]          = $topic;
			$seen[ $identity ] = true;
			$bytes            += $separator_bytes + strlen( $topic );
		}

		return $topics;
	}

	/**
	 * @return array{topic:string,identity:string}|null
	 */
	private static function normalize_topic( mixed $candidate ): ?array {
		$topic = self::bounded_text(
			sanitize_text_field( is_scalar( $candidate ) ? (string) $candidate : '' ),
			self::TOPIC_LABEL_MAX_LENGTH
		);
		$topic = trim( $topic );
		if ( '' === $topic ) {
			return null;
		}
		return array(
			'topic'    => $topic,
			'identity' => function_exists( 'mb_strtolower' )
				? mb_strtolower( $topic, 'UTF-8' )
				: strtolower( $topic ),
		);
	}
}
