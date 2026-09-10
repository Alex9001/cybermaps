<?php
declare(strict_types=1);

namespace Cybermaps\Admin;

use Cybermaps\Core\EndpointRegistry;
use Cybermaps\Discovery\MarkdownAlternate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classifies PHP-observed requests for crawler analytics.
 *
 * The endpoint registry owns fixed discovery and REST routes. Sitemap, robots,
 * RAG chunk, and IndexNow paths are generated from runtime settings and are
 * deliberately handled here instead of relying on a stale list of prefixes.
 */
final class CrawlerRequestClassifier {

	public function __construct(
		private readonly EndpointRegistry $registry
	) {}

	/**
	 * Classify one request path.
	 *
	 * @return array{request_kind: 'endpoint'|'page', endpoint_id: string}
	 */
	public function classify( string $path, string $rest_route = '', string $accept = '' ): array {
		$path        = $this->normalize_path( $path );
		$rest_route  = $this->normalize_path( $rest_route );
		$endpoint_id = $this->match_registered_request( $path, $rest_route );
		if ( '' !== $endpoint_id ) {
			return $this->endpoint( $endpoint_id );
		}

		$endpoint_id = $this->match_discovery_path( $path );
		if ( '' !== $endpoint_id ) {
			return $this->endpoint( $endpoint_id );
		}

		$sitemap_id = $this->match_sitemap( $path );
		if ( '' !== $sitemap_id ) {
			return $this->endpoint( $sitemap_id );
		}

		$indexnow_key = (string) \get_option( 'cybermaps_indexnow_key', '' );
		if ( '' !== $indexnow_key && '/' . $indexnow_key . '.txt' === $path ) {
			return $this->endpoint( 'indexnow_key' );
		}
		if (
			\Cybermaps\Discovery\MarkdownNegotiation::is_enabled()
			&& \Cybermaps\Discovery\AcceptNegotiator::prefers_markdown( $accept )
		) {
			return $this->endpoint( 'markdown_negotiation' );
		}

		return array(
			'request_kind' => 'page',
			'endpoint_id'  => '',
		);
	}

	/**
	 * Match explicit REST input, fixed registry paths, and pretty REST URLs.
	 */
	private function match_registered_request( string $path, string $rest_route ): string {
		if ( '/' !== $rest_route ) {
			$endpoint_id = $this->match_rest_route( $rest_route );
			if ( '' !== $endpoint_id ) {
				return $endpoint_id;
			}
		}

		$path_match = $this->registry->match_path( $path );
		if ( null !== $path_match ) {
			return (string) $path_match['id'];
		}
		foreach ( $this->registry->all() as $endpoint_id => $definition ) {
			if ( 'rest' === (string) ( $definition['kind'] ?? '' ) && $this->matches_rest_url_path( $path, $definition ) ) {
				return (string) $endpoint_id;
			}
		}

		return '';
	}

	/**
	 * Match parameterized discovery routes outside the fixed endpoint registry.
	 */
	private function match_discovery_path( string $path ): string {
		if ( '/robots.txt' === $path ) {
			return 'robots';
		}
		if ( 1 === preg_match( '#^/discovery/chunks/[1-9][0-9]*\.json$#', $path ) ) {
			return 'rag_chunk';
		}
		if ( 1 === preg_match( '#^/[a-z0-9_-]{2,16}/(llms(?:-full|-tldr)?\.txt)$#', $path, $matches ) ) {
			return self::localized_llms_id( $matches[1] );
		}
		return MarkdownAlternate::is_candidate_request( $path ) ? 'markdown_alternate' : '';
	}

	/**
	 * Map a localized LLMS filename to its canonical endpoint ID.
	 */
	private static function localized_llms_id( string $filename ): string {
		return match ( $filename ) {
			'llms-full.txt' => 'llms_full',
			'llms-tldr.txt' => 'llms_tldr',
			default         => 'llms',
		};
	}

	/**
	 * Determine whether a REST route belongs to Cybermaps analytics coverage.
	 */
	public function is_cybermaps_rest_route( string $route ): bool {
		$route = $this->normalize_path( $route );
		if ( '' !== $this->match_rest_route( $route ) ) {
			return true;
		}

		return '/cybermaps/v1' === $route || \str_starts_with( $route, '/cybermaps/v1/' );
	}

	/**
	 * Match a WordPress REST request route.
	 */
	private function match_rest_route( string $route ): string {
		$match = $this->registry->match_rest_path( $route );
		if ( null !== $match ) {
			return (string) $match['id'];
		}

		if ( '/cybermaps/v1' === $route || \str_starts_with( $route, '/cybermaps/v1/' ) ) {
			return 'rest_unknown';
		}

		return '';
	}

	/**
	 * Match a pretty-permalink REST URL path.
	 *
	 * @param array<string, mixed> $definition REST endpoint definition.
	 */
	private function matches_rest_url_path( string $path, array $definition ): bool {
		$namespace = (string) ( $definition['namespace'] ?? '' );
		$route     = (string) ( $definition['route'] ?? '' );
		if ( '' === $namespace || '' === $route ) {
			return false;
		}

		/*
		 * This classifier runs during plugins_loaded so machine requests can be
		 * marked before themes and page-cache integrations initialize. rest_url()
		 * is not safe at that point: WordPress may not have constructed the
		 * rewrite object it consults. URLManager already removes the site's
		 * subdirectory prefix, so the pretty REST path can be compared directly
		 * from the safe, filterable REST prefix and registry metadata.
		 */
		$rest_prefix = \function_exists( 'rest_get_url_prefix' )
			? \rest_get_url_prefix()
			: 'wp-json';
		$url_path    = '/' . \trim( (string) $rest_prefix, '/' )
			. '/' . \trim( $namespace, '/' )
			. '/' . \ltrim( $route, '/' );

		return $path === $this->normalize_path( $url_path );
	}

	/**
	 * Match configured sitemap and compatibility routes.
	 */
	private function match_sitemap( string $path ): string {
		$settings  = \Cybermaps\Core\ConfigurationStore::settings();
		$routes    = \Cybermaps\Sitemap\PublicationRouteSlugs::resolve( $settings );
		$base      = $routes['sitemap_url_base'];
		$news_base = $routes['news_sitemap_url_base'];

		$canonical = \Cybermaps\Sitemap\SitemapRouteMatcher::match_path( $path, $settings );
		if ( null !== $canonical ) {
			return self::canonical_sitemap_id( (string) $canonical['route'] );
		}

		if ( 1 === \preg_match( '#^/wp-sitemap(?:-[a-z0-9-]+)?\.xml$#i', $path ) ) {
			return 'wordpress_sitemap';
		}
		if ( '/sitemap.xml' === $path && 'sitemap' !== $base ) {
			return 'legacy_sitemap';
		}
		if ( '/sitemap-news.xml' === $path && 'sitemap-news' !== $news_base ) {
			return 'legacy_news_sitemap';
		}

		return '';
	}

	/**
	 * Map canonical sitemap route names to analytics endpoint IDs.
	 */
	private static function canonical_sitemap_id( string $route ): string {
		return match ( $route ) {
			'index'   => 'sitemap_index',
			'network' => 'sitemap_network',
			'news'    => 'sitemap_news',
			'rss'     => 'sitemap_rss',
			'child'   => 'sitemap_child',
			default   => '',
		};
	}

	/**
	 * Normalize a path without broad prefix matching.
	 */
	private function normalize_path( string $path ): string {
		$parsed = \wp_parse_url( $path, PHP_URL_PATH );
		$path   = \is_string( $parsed ) ? $parsed : $path;
		$path   = '/' . \ltrim( $path, '/' );

		if ( '/' !== $path ) {
			$path = \rtrim( $path, '/' );
		}

		return '' !== $path ? $path : '/';
	}

	/**
	 * Build a typed endpoint result.
	 *
	 * @return array{request_kind: 'endpoint', endpoint_id: string}
	 */
	private function endpoint( string $endpoint_id ): array {
		return array(
			'request_kind' => 'endpoint',
			'endpoint_id'  => \substr( $endpoint_id, 0, 100 ),
		);
	}
}
