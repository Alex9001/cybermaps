<?php
declare(strict_types=1);
namespace Cybermaps\Sitemap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolve the three configurable sitemap publication slugs as one route set.
 *
 * These values cannot be validated independently: each publication needs a
 * unique filename, and the News/RSS routes must not shadow a generated child of
 * the primary sitemap. Runtime callers use the same resolver as the settings
 * sanitizer so malformed legacy values are safe before the option is resaved.
 */
final class PublicationRouteSlugs {
	public const SITEMAP_KEY = 'sitemap_url_base';
	public const NEWS_KEY    = 'news_sitemap_url_base';
	public const RSS_KEY     = 'rss_sitemap_url_base';

	private const MAX_SLUG_LENGTH = 180;

	/**
	 * Resolve a complete, collision-safe route set.
	 *
	 * Priority is primary sitemap, then News, then RSS. Valid custom values are
	 * preserved unless a higher-priority route already owns the same filename.
	 *
	 * @param array<string, mixed> $settings Candidate settings.
	 * @return array{sitemap_url_base:string,news_sitemap_url_base:string,rss_sitemap_url_base:string}
	 */
	public static function resolve( array $settings ): array {
		$sitemap = self::normalize( $settings[ self::SITEMAP_KEY ] ?? '' );
		if ( ! self::is_primary_available( $sitemap ) ) {
			$sitemap = 'sitemap';
		}

		$news = self::normalize( $settings[ self::NEWS_KEY ] ?? '' );
		if ( ! self::is_auxiliary_available( $news, 'news', $sitemap, array( $sitemap ) ) ) {
			$news = self::first_available(
				array( $sitemap . '-news', 'sitemap-news', 'cybermaps-news' ),
				'news',
				$sitemap,
				array( $sitemap )
			);
		}

		$rss = self::normalize( $settings[ self::RSS_KEY ] ?? '' );
		if ( ! self::is_auxiliary_available( $rss, 'rss', $sitemap, array( $sitemap, $news ) ) ) {
			$rss = self::first_available(
				array( 'sitemap-rss', $sitemap . '-rss', 'cybermaps-rss' ),
				'rss',
				$sitemap,
				array( $sitemap, $news )
			);
		}

		return array(
			self::SITEMAP_KEY => $sitemap,
			self::NEWS_KEY    => $news,
			self::RSS_KEY     => $rss,
		);
	}

	/**
	 * Resolve the current site's stored settings.
	 *
	 * @return array{sitemap_url_base:string,news_sitemap_url_base:string,rss_sitemap_url_base:string}
	 */
	public static function current(): array {
		return self::resolve( \Cybermaps\Core\ConfigurationStore::settings() );
	}

	/**
	 * Convert an optional filename/base value into the route grammar.
	 *
	 * @param mixed $value Candidate setting.
	 */
	public static function normalize( $value ): string {
		if ( ! \is_scalar( $value ) ) {
			return '';
		}

		$value = \trim( (string) $value );
		$value = (string) \preg_replace( '/(?:\.xml)+$/i', '', $value );
		$slug  = \sanitize_title( $value );

		return \strlen( $slug ) <= self::MAX_SLUG_LENGTH
			&& 1 === \preg_match( '/^[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?$/', $slug )
			? $slug
			: '';
	}

	/**
	 * Check a complete static filename against the generated child route family.
	 *
	 * The sitemap base must be the resolved route slug used by the publisher.
	 */
	public static function is_generated_child_filename( string $filename, string $sitemap ): bool {
		if ( ! \str_ends_with( $filename, '.xml' ) ) {
			return false;
		}

		$sitemap = self::normalize( $sitemap );
		return '' !== $sitemap
			&& self::is_generated_child( \substr( $filename, 0, -4 ), $sitemap );
	}

	/**
	 * Determine whether a primary route can coexist with fixed handlers.
	 */
	private static function is_primary_available( string $slug ): bool {
		if ( '' === $slug || self::is_reserved( $slug ) ) {
			return false;
		}

		// This legacy News path is inspected before the primary sitemap handler.
		// Using it for the index would redirect/404 the index itself.
		return 'sitemap-news' !== $slug;
	}

	/**
	 * Determine whether a News/RSS route is reachable and unique.
	 *
	 * @param string[] $used Higher-priority route slugs.
	 */
	private static function is_auxiliary_available(
		string $slug,
		string $role,
		string $sitemap,
		array $used
	): bool {
		if (
			'' === $slug
			|| self::is_reserved( $slug )
			|| \in_array( $slug, $used, true )
			|| self::is_generated_child( $slug, $sitemap )
		) {
			return false;
		}

		// The compatibility redirect handler owns these two legacy paths. News
		// may canonically own sitemap-news; no auxiliary route may own sitemap.
		if ( 'sitemap' === $slug ) {
			return false;
		}

		return 'rss' !== $role || 'sitemap-news' !== $slug;
	}

	/**
	 * Pick a deterministic safe fallback.
	 *
	 * @param string[] $candidates Ordered fallback candidates.
	 * @param string[] $used       Higher-priority route slugs.
	 */
	private static function first_available(
		array $candidates,
		string $role,
		string $sitemap,
		array $used
	): string {
		foreach ( $candidates as $candidate ) {
			$candidate = self::normalize( $candidate );
			if ( self::is_auxiliary_available( $candidate, $role, $sitemap, $used ) ) {
				return $candidate;
			}
		}

		// The fixed candidates above are sufficient for known routes. Keep a
		// deterministic final guard for future reserved-route additions.
		$candidate = $sitemap . '-' . $role . '-route';
		while ( ! self::is_auxiliary_available( $candidate, $role, $sitemap, $used ) ) {
			$candidate .= '-route';
		}

		return $candidate;
	}

	/**
	 * Check fixed WordPress/Cybermaps routes that configurable files must not own.
	 */
	private static function is_reserved( string $slug ): bool {
		if ( \in_array( $slug, array( 'ai-sitemap', 'sitemap-network' ), true ) ) {
			return true;
		}

		// Core's index and child routes are handled by the compatibility redirect
		// before Cybermaps' configurable sitemap/RSS handlers.
		return 1 === \preg_match( '/^wp-sitemap(?:-[a-z0-9-]+)?$/', $slug );
	}

	/**
	 * Check filenames owned by the generated sitemap-child route family.
	 */
	private static function is_generated_child( string $slug, string $sitemap ): bool {
		$prefix = \preg_quote( $sitemap, '/' );
		return $slug === $sitemap . '-misc'
			|| 1 === \preg_match( '/^' . $prefix . '-(?:authors|archives)-[1-9][0-9]*$/', $slug )
			|| 1 === \preg_match(
				'/^' . $prefix . '-(?:posts|taxonomies)-[a-z0-9_-]+-[1-9][0-9]*$/',
				$slug
			);
	}
}
