<?php
declare(strict_types=1);
namespace Cybermaps\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class URLManager {
	/**
	 * Request-local normalization cache for repeatedly rendered sitemap rows.
	 *
	 * @var array<string, string|null>
	 */
	private static array $normalized_base_urls = array();

	/**
	 * @var array<string, array<string, mixed>|null>
	 */
	private static array $normalized_base_parts = array();

	public static function get_home_url( $path = '' ) {
		$base = self::get_public_base_url();
		$path = is_scalar( $path ) ? (string) $path : '';

		if ( '' === $path ) {
			return $base;
		}

		if ( self::is_well_known_path( $path ) ) {
			return self::get_origin_url( $path, $base );
		}

		return $base . '/' . ltrim( $path, '/' );
	}

	/**
	 * Permit wp_safe_redirect() to reach an explicitly configured headless
	 * frontend.
	 *
	 * Cybermaps' legacy sitemap redirects target the same public base used in
	 * sitemap and discovery URLs. WordPress otherwise replaces a cross-origin
	 * target with its local fallback, making those redirects incorrect on a
	 * decoupled installation.
	 *
	 * @param mixed $hosts WordPress's current allowed redirect hosts.
	 * @return string[]
	 */
	public static function allow_configured_public_host( $hosts ): array {
		$hosts    = is_array( $hosts ) ? $hosts : array();
		$settings = self::get_settings();
		$frontend = self::normalize_configured_base_url( $settings['frontend_base_url'] ?? '' );
		$host     = '' !== $frontend ? wp_parse_url( $frontend, PHP_URL_HOST ) : null;

		if ( is_string( $host ) && '' !== $host && ! in_array( $host, $hosts, true ) ) {
			$hosts[] = $host;
		}

		return array_values(
			array_unique(
				array_filter(
					$hosts,
					static fn ( $candidate ): bool => is_string( $candidate ) && '' !== $candidate
				)
			)
		);
	}

	/**
	 * Build an origin-root URL for a well-known publication.
	 *
	 * RFC 8615 well-known paths are rooted at the top of the origin, even when
	 * WordPress or a configured headless frontend lives below a path prefix.
	 */
	public static function get_origin_url( string $path = '', ?string $base_url = null ): string {
		$base = self::normalize_base_url(
			null === $base_url ? self::get_public_base_url() : $base_url
		);
		if ( null === $base ) {
			$base = self::normalize_base_url( (string) home_url() );
		}
		if ( null === $base ) {
			return '';
		}

		$parts = self::get_normalized_base_parts( $base );
		if ( null === $parts ) {
			return '';
		}

		$origin = (string) $parts['scheme'] . '://' . (string) $parts['host'];
		if ( isset( $parts['port'] ) ) {
			$origin .= ':' . (int) $parts['port'];
		}

		return '' === $path ? $origin : $origin . '/' . ltrim( $path, '/' );
	}

	/**
	 * Get the URL for a specific RAG chunk file.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function get_chunk_url( $post_id ) {
		return self::get_home_url( "/discovery/chunks/{$post_id}.json" );
	}

	/**
	 * Append a URI-template query expression without corrupting query-form URLs.
	 *
	 * WordPress REST URLs use a clean `/wp-json/` path when rewrite permalinks
	 * are available, but use `?rest_route=...` on plain-permalink sites. Public
	 * machine documents must therefore choose `&` when the URL already contains
	 * a query string. A fragment, when supplied by an extension, remains last.
	 */
	public static function append_query_template( string $url, string $template ): string {
		$url      = trim( $url );
		$template = ltrim( trim( $template ), '?&' );
		if ( '' === $url || '' === $template ) {
			return $url;
		}

		$fragment = '';
		$position = strpos( $url, '#' );
		if ( false !== $position ) {
			$fragment = substr( $url, $position );
			$url      = substr( $url, 0, $position );
		}

		if ( str_ends_with( $url, '?' ) || str_ends_with( $url, '&' ) ) {
			$separator = '';
		} else {
			$separator = str_contains( $url, '?' ) ? '&' : '?';
		}

		return $url . $separator . $template . $fragment;
	}

	/**
	 * Rewrite a same-site media URL to the configured sitemap CDN.
	 *
	 * Media collected from content may already use either the WordPress URL or
	 * the configured public frontend URL, so both bases are eligible. External
	 * hosts and same-origin paths outside a subdirectory installation are left
	 * untouched.
	 *
	 * @param mixed $url Candidate media URL.
	 * @return mixed
	 */
	public static function rewrite_media_url( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return $url;
		}

		$settings = self::get_settings();
		if ( empty( $settings['cdn_enabled'] ) || empty( $settings['cdn_base_url'] ) ) {
			return $url;
		}
		$url_parts = wp_parse_url( $url );
		if ( ! is_array( $url_parts ) ) {
			return $url;
		}

		$cdn_base = (string) $settings['cdn_base_url'];
		$sources  = array( (string) home_url() );
		if ( ! empty( $settings['frontend_base_url'] ) ) {
			$sources[] = (string) $settings['frontend_base_url'];
		}

		$candidates = array();
		foreach ( array_unique( $sources ) as $source ) {
			$rewritten = self::replace_url_base( $url_parts, $source, $cdn_base );
			if ( null !== $rewritten ) {
				$candidates[] = array(
					'specificity' => self::base_path_length( $source ),
					'url'         => $rewritten,
				);
			}
		}
		$already_cdn = self::replace_url_base( $url_parts, $cdn_base, $cdn_base );
		if ( ! empty( $candidates ) ) {
			usort(
				$candidates,
				static fn( array $left, array $right ): int =>
					$right['specificity'] <=> $left['specificity']
			);
			if (
				null !== $already_cdn
				&& self::base_path_length( $cdn_base ) >= (int) $candidates[0]['specificity']
			) {
				return $url;
			}
			return (string) $candidates[0]['url'];
		}

		return $url;
	}

	/**
	 * Rewrite a WordPress public URL to the configured headless frontend.
	 *
	 * @param mixed $url Candidate public URL.
	 * @return mixed
	 */
	public static function rewrite_url( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return $url;
		}

		$settings = self::get_settings();
		if ( empty( $settings['frontend_base_url'] ) ) {
			return $url;
		}
		$url_parts = wp_parse_url( $url );
		if ( ! is_array( $url_parts ) ) {
			return $url;
		}

		$backend_base   = (string) home_url();
		$frontend_base  = (string) $settings['frontend_base_url'];
		$rewritten      = self::replace_url_base(
			$url_parts,
			$backend_base,
			$frontend_base
		);
		$already_public = self::replace_url_base(
			$url_parts,
			$frontend_base,
			$frontend_base
		);
		if (
			null !== $already_public
			&& (
				null === $rewritten
				|| self::base_path_length( $frontend_base ) >= self::base_path_length( $backend_base )
			)
		) {
			return $url;
		}

		return null === $rewritten ? $url : $rewritten;
	}

	/**
	 * Get the relative request path.
	 *
	 * @return string
	 */
	public static function get_request_path() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) && is_scalar( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) )
			: '';
		if ( empty( $uri ) ) {
			return '/';
		}

		$path      = wp_parse_url( $uri, PHP_URL_PATH );
		$site_path = wp_parse_url( home_url(), PHP_URL_PATH );
		$path      = is_string( $path ) ? $path : '/';
		$site_path = is_string( $site_path ) ? rtrim( $site_path, '/' ) : '';

		if ( ! empty( $site_path ) && '/' !== $site_path ) {
			if ( $path === $site_path || str_starts_with( $path, $site_path . '/' ) ) {
				$path = substr( $path, strlen( $site_path ) );
			}
		}

		if ( empty( $path ) ) {
			$path = '/';
		}
		if ( '/' !== $path[0] ) {
			$path = '/' . $path;
		}

		return $path;
	}

	/**
	 * Return the configured public base when it is a usable HTTP(S) base URL.
	 */
	private static function get_public_base_url(): string {
		$settings = self::get_settings();
		if ( ! empty( $settings['frontend_base_url'] ) ) {
			$frontend = self::normalize_base_url( (string) $settings['frontend_base_url'] );
			if ( null !== $frontend ) {
				return $frontend;
			}
		}

		$backend = self::normalize_base_url( (string) home_url() );
		return null === $backend ? '' : $backend;
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function get_settings(): array {
		return ConfigurationStore::settings();
	}

	/**
	 * Determine whether a relative route belongs to RFC 8615's origin root.
	 */
	private static function is_well_known_path( string $path ): bool {
		$route = wp_parse_url( $path, PHP_URL_PATH );
		if ( ! is_string( $route ) ) {
			return false;
		}

		return str_starts_with( '/' . ltrim( $route, '/' ), '/.well-known/' );
	}

	/**
	 * Validate one absolute publication URL.
	 *
	 * User information is never valid in a public sitemap or machine-readable
	 * action target. Only HTTP(S) URLs with a host are accepted.
	 *
	 * @param mixed $url Candidate URL.
	 */
	public static function sanitize_http_url( $url ): string {
		if ( ! is_scalar( $url ) ) {
			return '';
		}

		$url   = trim( (string) $url );
		$parts = wp_parse_url( $url );
		if (
			! is_array( $parts )
			|| empty( $parts['scheme'] )
			|| ! in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true )
			|| empty( $parts['host'] )
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
		) {
			return '';
		}

		return (string) esc_url_raw( $url, array( 'http', 'https' ) );
	}

	/**
	 * Normalize a configured frontend or CDN base for safe path appending.
	 *
	 * @param mixed $url Candidate base URL.
	 */
	public static function normalize_configured_base_url( $url ): string {
		if ( ! is_scalar( $url ) ) {
			return '';
		}

		$normalized = self::normalize_base_url( (string) $url );
		return null === $normalized ? '' : $normalized;
	}

	/**
	 * Validate and normalize a configured public base URL.
	 *
	 * A base URL cannot contain credentials, a query, or a fragment because
	 * appending publication paths to any of those components is ambiguous.
	 */
	private static function normalize_base_url( string $url ): ?string {
		$cache_key = $url;
		if ( array_key_exists( $cache_key, self::$normalized_base_urls ) ) {
			return self::$normalized_base_urls[ $cache_key ];
		}

		$parts = wp_parse_url( trim( $url ) );
		if (
			! is_array( $parts )
			|| empty( $parts['scheme'] )
			|| empty( $parts['host'] )
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
			|| isset( $parts['query'] )
			|| isset( $parts['fragment'] )
		) {
			self::$normalized_base_urls[ $cache_key ] = null;
			return self::$normalized_base_urls[ $cache_key ];
		}

		$scheme = strtolower( (string) $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			self::$normalized_base_urls[ $cache_key ] = null;
			return self::$normalized_base_urls[ $cache_key ];
		}

		$host = strtolower( (string) $parts['host'] );
		$base = $scheme . '://' . $host;
		if ( isset( $parts['port'] ) ) {
			$port = (int) $parts['port'];
			if ( $port < 1 || $port > 65535 ) {
				self::$normalized_base_urls[ $cache_key ] = null;
				return self::$normalized_base_urls[ $cache_key ];
			}
			$base .= ':' . $port;
		}

		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '';
		if ( '' !== $path && '/' !== $path[0] ) {
			self::$normalized_base_urls[ $cache_key ] = null;
			return self::$normalized_base_urls[ $cache_key ];
		}

		self::$normalized_base_urls[ $cache_key ] = $base . ( '/' === $path ? '' : rtrim( $path, '/' ) );
		return self::$normalized_base_urls[ $cache_key ];
	}

	/**
	 * Replace one same-origin, path-bounded URL base with another.
	 *
	 * Query strings and fragments belong to the resource URL, not either base,
	 * and are copied without interpretation.
	 */
	private static function replace_url_base(
		array $url_parts,
		string $source_base,
		string $replacement_base
	): ?string {
		$source      = self::normalize_base_url( $source_base );
		$replacement = self::normalize_base_url( $replacement_base );
		if (
			null === $source
			|| null === $replacement
			|| empty( $url_parts['scheme'] )
			|| empty( $url_parts['host'] )
			|| isset( $url_parts['user'] )
			|| isset( $url_parts['pass'] )
		) {
			return null;
		}

		$source_parts = self::get_normalized_base_parts( $source );
		if (
			null === $source_parts
			|| strtolower( (string) $url_parts['scheme'] ) !== strtolower( (string) $source_parts['scheme'] )
			|| strtolower( (string) $url_parts['host'] ) !== strtolower( (string) $source_parts['host'] )
			|| self::effective_port( $url_parts ) !== self::effective_port( $source_parts )
		) {
			return null;
		}

		$url_path    = isset( $url_parts['path'] ) ? (string) $url_parts['path'] : '';
		$source_path = isset( $source_parts['path'] )
			? rtrim( (string) $source_parts['path'], '/' )
			: '';
		if (
			'' !== $source_path
			&& $url_path !== $source_path
			&& ! str_starts_with( $url_path, $source_path . '/' )
		) {
			return null;
		}

		$suffix = '' === $source_path
			? $url_path
			: substr( $url_path, strlen( $source_path ) );
		$result = $replacement . $suffix;

		if ( array_key_exists( 'query', $url_parts ) ) {
			$result .= '?' . (string) $url_parts['query'];
		}
		if ( array_key_exists( 'fragment', $url_parts ) ) {
			$result .= '#' . (string) $url_parts['fragment'];
		}

		return $result;
	}

	/**
	 * Return an origin's effective port so explicit default ports still match.
	 *
	 * @param array<string, mixed> $parts Parsed URL components.
	 */
	private static function effective_port( array $parts ): int {
		if ( isset( $parts['port'] ) ) {
			return (int) $parts['port'];
		}

		return 'https' === strtolower( (string) ( $parts['scheme'] ?? '' ) ) ? 443 : 80;
	}

	/**
	 * Prefer the most specific matching source when backend and frontend paths
	 * overlap on the same origin.
	 */
	private static function base_path_length( string $base_url ): int {
		$base = self::normalize_base_url( $base_url );
		if ( null === $base ) {
			return -1;
		}

		$parts = self::get_normalized_base_parts( $base );
		$path  = null === $parts ? '' : (string) ( $parts['path'] ?? '' );
		return strlen( rtrim( $path, '/' ) );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function get_normalized_base_parts( string $base_url ): ?array {
		if ( array_key_exists( $base_url, self::$normalized_base_parts ) ) {
			return self::$normalized_base_parts[ $base_url ];
		}

		$parts                                    = wp_parse_url( $base_url );
		self::$normalized_base_parts[ $base_url ] = is_array( $parts ) ? $parts : null;
		return self::$normalized_base_parts[ $base_url ];
	}
}
