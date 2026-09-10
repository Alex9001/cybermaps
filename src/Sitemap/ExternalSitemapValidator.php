<?php
declare(strict_types=1);

namespace Cybermaps\Sitemap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates external sitemap URLs before index inclusion or option storage.
 *
 * Validation is deliberately structural. Generating a sitemap must not block
 * on third-party HTTP requests, and a temporary remote outage must not silently
 * remove a configured child sitemap from the index.
 */
class ExternalSitemapValidator {

	/**
	 * Parse textarea lines and keep unique, same-publication HTTP(S) XML URLs.
	 *
	 * @param string $raw Newline-separated URLs.
	 * @return string Sanitized textarea value.
	 */
	public static function filter_textarea( string $raw ): string {
		return implode( "\n", self::filter_urls( $raw, true, 100 ) );
	}

	/**
	 * Parse additional page URLs for the miscellaneous sitemap. Sitemap URL
	 * ownership is origin- and path-scoped; allowing arbitrary third-party URLs
	 * here would create an invalid sitemap publication.
	 *
	 * @return string Sanitized textarea value.
	 */
	public static function filter_page_textarea( string $raw ): string {
		return implode( "\n", self::filter_urls( $raw, false, 1000 ) );
	}

	/**
	 * @return string[] Unique sanitized URLs.
	 */
	private static function filter_urls( string $raw, bool $require_xml, int $limit ): array {
		$lines = (array) preg_split( '/\r\n|\r|\n/', $raw );
		$valid = array();

		foreach ( $lines as $line ) {
			$url = \Cybermaps\Core\URLManager::sanitize_http_url( $line );
			if ( '' === $url || ! wp_http_validate_url( $url ) || ! self::is_in_publication_scope( $url ) ) {
				continue;
			}
			if ( $require_xml ) {
				$path = wp_parse_url( $url, PHP_URL_PATH );
				if ( ! is_string( $path ) || ! str_ends_with( strtolower( $path ), '.xml' ) ) {
					continue;
				}
			}

			$valid[ $url ] = $url;
			if ( count( $valid ) >= $limit ) {
				break;
			}
		}

		return array_values( $valid );
	}

	/**
	 * Determine whether a URL belongs to a publication origin and its WordPress
	 * home-path scope. A headless frontend is already reflected by URLManager.
	 */
	public static function is_in_publication_scope( string $url, string $scope_url = '' ): bool {
		$url       = \Cybermaps\Core\URLManager::sanitize_http_url( $url );
		$scope_url = '' !== $scope_url
			? \Cybermaps\Core\URLManager::sanitize_http_url( $scope_url )
			: \Cybermaps\Core\URLManager::get_home_url( '/' );
		if ( '' === $url || '' === $scope_url ) {
			return false;
		}

		$candidate = \wp_parse_url( $url );
		$scope     = \wp_parse_url( $scope_url );
		if ( ! \is_array( $candidate ) || ! \is_array( $scope ) ) {
			return false;
		}

		if ( ! self::has_matching_origin( $candidate, $scope ) ) {
			return false;
		}
		return self::is_in_path_scope( $candidate, $scope );
	}

	private static function has_matching_origin( array $candidate, array $scope ): bool {
		$scheme = self::origin_part( $candidate, 'scheme' );
		$host   = self::origin_part( $candidate, 'host' );
		return '' !== $scheme && '' !== $host && self::origin_part( $scope, 'scheme' ) === $scheme && self::origin_part( $scope, 'host' ) === $host && self::port( $candidate ) === self::port( $scope );
	}

	private static function origin_part( array $parts, string $key ): string {
		return \strtolower( (string) ( $parts[ $key ] ?? '' ) );
	}

	private static function port( array $parts ): int {
		return isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === self::origin_part( $parts, 'scheme' ) ? 443 : 80 );
	}

	private static function is_in_path_scope( array $candidate, array $scope ): bool {
		$path       = '/' . \ltrim( (string) ( $candidate['path'] ?? '/' ), '/' );
		$scope_path = '/' . \ltrim( (string) ( $scope['path'] ?? '/' ), '/' );
		$scope_path = '/' === $scope_path ? '/' : \rtrim( $scope_path, '/' );
		return '/' === $scope_path || $path === $scope_path || \str_starts_with( $path, $scope_path . '/' );
	}
}
