<?php
/**
 * Canonical public sitemap route matcher.
 *
 * @package Cybermaps\Sitemap
 */

declare(strict_types=1);

namespace Cybermaps\Sitemap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shares the exact public sitemap filename grammar with analytics and request
 * throttling so operational surfaces cannot drift from generated routes.
 */
final class SitemapRouteMatcher {
	/**
	 * Match a canonical sitemap publication path.
	 *
	 * @param array<string, mixed>|null $settings Optional settings snapshot.
	 * @return array{route:string,provider_id:string,page:int}|null
	 */
	public static function match_path( string $path, ?array $settings = null ): ?array {
		$parsed = \wp_parse_url( $path, PHP_URL_PATH );
		$path   = \is_string( $parsed ) ? $parsed : $path;
		$path   = '/' . \ltrim( $path, '/' );
		if ( '/' !== $path ) {
			$path = \rtrim( $path, '/' );
		}

		$routes    = PublicationRouteSlugs::resolve(
			$settings ?? \Cybermaps\Core\ConfigurationStore::settings()
		);
		$base      = $routes['sitemap_url_base'];
		$news_base = $routes['news_sitemap_url_base'];
		$rss_base  = $routes['rss_sitemap_url_base'];

		$fixed = self::fixed_routes( $base, $news_base, $rss_base );
		if ( isset( $fixed[ $path ] ) ) {
			return $fixed[ $path ];
		}

		return self::dynamic_route( $path, $base );
	}

	private static function fixed_routes( string $base, string $news_base, string $rss_base ): array {
		return array(
			'/' . $base . '.xml'      => self::result( 'index' ),
			'/sitemap-network.xml'    => self::result( 'network' ),
			'/' . $news_base . '.xml' => self::result( 'news', ProviderIdentity::NEWS, 1 ),
			'/' . $rss_base . '.xml'  => self::result( 'rss' ),
			'/' . $base . '-misc.xml' => self::result( 'child', ProviderIdentity::MISC, 1 ),
		);
	}

	private static function dynamic_route( string $path, string $base ): ?array {
		$quoted_base = \preg_quote( $base, '#' );
		$patterns    = array(
			'system'    => '#^/' . $quoted_base . '-(authors|archives)-([1-9][0-9]*)\.xml$#',
			'post_type' => '#^/' . $quoted_base . '-posts-([a-z0-9_-]+)-([1-9][0-9]*)\.xml$#',
			'taxonomy'  => '#^/' . $quoted_base . '-taxonomies-([a-z0-9_-]+)-([1-9][0-9]*)\.xml$#',
		);
		foreach ( $patterns as $kind => $pattern ) {
			if ( 1 === \preg_match( $pattern, $path, $matches ) ) {
				return self::result( 'child', self::provider_identity( $kind, (string) $matches[1] ), (int) $matches[2] );
			}
		}
		return null;
	}

	private static function provider_identity( string $kind, string $name ): string {
		return 'system' === $kind ? ProviderIdentity::system( $name ) : ( 'post_type' === $kind ? ProviderIdentity::post_type( $name ) : ProviderIdentity::taxonomy( $name ) );
	}

	/**
	 * @return array{route:string,provider_id:string,page:int}
	 */
	private static function result( string $route, string $provider_id = '', int $page = 1 ): array {
		return array(
			'route'       => $route,
			'provider_id' => $provider_id,
			'page'        => $page,
		);
	}
}
