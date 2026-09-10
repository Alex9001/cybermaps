<?php
/**
 * Shared public-publication HTTP cache policy.
 *
 * @package Cybermaps\Discovery
 */

declare(strict_types=1);

namespace Cybermaps\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds the portable cache, integrity, CORS, and edge-tag contract.
 *
 * The policy deliberately starts conservative. An edge can revalidate every
 * stale response without requiring a proprietary purge implementation. Hosts
 * that have a verified purge adapter may opt into a different policy through
 * the documented filter below.
 */
final class PublicationCachePolicy {
	public const DEFAULT_BROWSER_TTL = 3600;
	public const DEFAULT_SHARED_TTL  = 3600;
	public const PREFLIGHT_TTL       = 86400;
	public const MAX_TAGS            = 8;

	/**
	 * Return one normalized policy for a public representation.
	 *
	 * @param string               $family Publication family, for example discovery or sitemap.
	 * @param string               $endpoint Stable endpoint/provider identifier.
	 * @param array<string,mixed>|null $settings Optional settings snapshot.
	 * @return array<string,mixed>
	 */
	public static function for_publication( string $family = 'discovery', string $endpoint = '', ?array $settings = null ): array {
		$settings = \is_array( $settings ) ? $settings : \Cybermaps\Core\ConfigurationStore::settings();
		$family   = self::slug( $family, 'discovery' );
		$endpoint = self::slug( $endpoint, 'root' );

		$policy = array(
			'browser_ttl'            => self::DEFAULT_BROWSER_TTL,
			'shared_ttl'             => self::DEFAULT_SHARED_TTL,
			'must_revalidate'        => true,
			'stale_while_revalidate' => 0,
			'stale_if_error'         => 0,
			'preflight_ttl'          => self::PREFLIGHT_TTL,
			'family'                 => $family,
			'endpoint'               => $endpoint,
			'generation'             => max( 0, (int) \get_option( 'cybermaps_static_generation', 0 ) ),
		);

		/**
		 * Filters the normalized HTTP cache policy for a public publication.
		 *
		 * Stale directives require must_revalidate to be disabled because HTTP
		 * shared caches must otherwise revalidate before reusing stale content.
		 * This hook is intentionally the only policy override; it does not imply
		 * that an edge-purge transport is configured.
		 *
		 * @param array<string,mixed> $policy   Normalized candidate policy.
		 * @param string              $family   Publication family.
		 * @param string              $endpoint Endpoint/provider identifier.
		 * @param array<string,mixed> $settings Current Cybermaps settings.
		 */
		$policy = \apply_filters( 'cybermaps_publication_cache_policy', $policy, $family, $endpoint, $settings );
		$policy = \is_array( $policy ) ? $policy : array();

		$browser_ttl = self::bounded_int( $policy['browser_ttl'] ?? self::DEFAULT_BROWSER_TTL, self::DEFAULT_BROWSER_TTL, 0, 31536000 );
		$shared_ttl  = self::bounded_int( $policy['shared_ttl'] ?? self::DEFAULT_SHARED_TTL, self::DEFAULT_SHARED_TTL, 0, 31536000 );
		$must        = ! array_key_exists( 'must_revalidate', $policy ) || (bool) $policy['must_revalidate'];
		$stale_swr   = self::bounded_int( $policy['stale_while_revalidate'] ?? 0, 0, 0, 604800 );
		$stale_error = self::bounded_int( $policy['stale_if_error'] ?? 0, 0, 0, 604800 );

		if ( $must ) {
			$stale_swr   = 0;
			$stale_error = 0;
		}

		return array(
			'browser_ttl'            => $browser_ttl,
			'shared_ttl'             => $shared_ttl,
			'must_revalidate'        => $must,
			'stale_while_revalidate' => $stale_swr,
			'stale_if_error'         => $stale_error,
			'preflight_ttl'          => self::bounded_int( $policy['preflight_ttl'] ?? self::PREFLIGHT_TTL, self::PREFLIGHT_TTL, 0, 86400 ),
			'family'                 => $family,
			'endpoint'               => $endpoint,
			'generation'             => self::bounded_int( $policy['generation'] ?? max( 0, (int) \get_option( 'cybermaps_static_generation', 0 ) ), max( 0, (int) \get_option( 'cybermaps_static_generation', 0 ) ), 0, PHP_INT_MAX ),
		);
	}

	/**
	 * Infer a policy for the current fixed path without making handlers repeat
	 * their endpoint identity.
	 */
	public static function for_request_path( string $path ): array {
		$match = \Cybermaps\Core\EndpointRegistry::get_instance()->match_path( $path );
		if ( null !== $match ) {
			return self::for_publication( 'discovery', (string) ( $match['id'] ?? 'root' ) );
		}

		return self::for_publication( 'discovery', 'root' );
	}

	/**
	 * Build a standards-compliant Cache-Control field value.
	 *
	 * @param array<string,mixed> $policy Normalized policy.
	 */
	public static function cache_control( array $policy ): string {
		$parts            = array( 'public', 'max-age=' . max( 0, (int) ( $policy['browser_ttl'] ?? self::DEFAULT_BROWSER_TTL ) ) );
		$must             = ! empty( $policy['must_revalidate'] );
		$stale            = max( 0, (int) ( $policy['stale_while_revalidate'] ?? 0 ) )
			+ max( 0, (int) ( $policy['stale_if_error'] ?? 0 ) );
		$shared           = max( 0, (int) ( $policy['shared_ttl'] ?? self::DEFAULT_SHARED_TTL ) );
		$shared_ttl_delta = $shared - max( 0, (int) ( $policy['browser_ttl'] ?? self::DEFAULT_BROWSER_TTL ) );

		// s-maxage implies proxy-revalidate. Do not combine it with a requested
		// stale window because shared-cache implementations rightfully differ.
		if ( 0 === $stale && 0 !== $shared_ttl_delta ) {
			$parts[] = 's-maxage=' . $shared;
		}
		if ( $must ) {
			$parts[] = 'must-revalidate';
		}
		if ( ! $must && ! empty( $policy['stale_while_revalidate'] ) ) {
			$parts[] = 'stale-while-revalidate=' . (int) $policy['stale_while_revalidate'];
		}
		if ( ! $must && ! empty( $policy['stale_if_error'] ) ) {
			$parts[] = 'stale-if-error=' . (int) $policy['stale_if_error'];
		}

		return implode( ', ', $parts );
	}

	/**
	 * Return a representation digest. Repr-Digest is stable when a downstream
	 * server applies a content coding, unlike Content-Digest.
	 */
	public static function repr_digest( string $content ): string {
		return 'sha-256=:' . base64_encode( hash( 'sha256', $content, true ) ) . ':'; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- RFC 9530 structured field bytes require Base64.
	}

	/**
	 * Return Content-Digest only when the final message content is known to be
	 * identity encoded. PHP cannot make that claim before an edge gzip/Brotli
	 * filter runs, so callers normally publish Repr-Digest only.
	 */
	public static function content_digest( string $content, string $content_encoding = '' ): string {
		return 'identity' === strtolower( trim( $content_encoding ) )
			? self::repr_digest( $content )
			: '';
	}

	/**
	 * Return a deterministic strong validator for the canonical representation.
	 */
	public static function etag( string $content ): string {
		return '"' . md5( $content ) . '"';
	}

	/**
	 * Return bounded ASCII cache tags with no user or requester information.
	 *
	 * @param array<string,mixed> $policy Normalized policy.
	 * @return string[]
	 */
	public static function tags( array $policy ): array {
		$origin     = (string) \home_url( '/' );
		$site_hash  = substr( hash( 'sha256', strtolower( $origin ) ), 0, 16 );
		$family     = self::slug( (string) ( $policy['family'] ?? 'discovery' ), 'discovery' );
		$endpoint   = self::slug( (string) ( $policy['endpoint'] ?? 'root' ), 'root' );
		$generation = max( 0, (int) ( $policy['generation'] ?? 0 ) );
		$tags       = array(
			'cm-site-' . $site_hash,
			'cm-publications',
			'cm-family-' . $family,
			'cm-endpoint-' . $endpoint,
			'cm-generation-' . $generation,
		);

		/**
		 * Filters bounded deterministic tags for an edge representation.
		 *
		 * @param string[]            $tags   Candidate tags.
		 * @param array<string,mixed> $policy Cache policy.
		 */
		$tags = \apply_filters( 'cybermaps_publication_cache_tags', $tags, $policy );
		$tags = \is_array( $tags ) ? $tags : array();
		$tags = array_values(
			array_unique(
				array_filter(
					array_map(
						static fn( mixed $tag ): string => self::slug( is_scalar( $tag ) ? (string) $tag : '', '' ),
						$tags
					),
					static fn( string $tag ): bool => '' !== $tag && strlen( $tag ) <= 80
				)
			)
		);

		return array_slice( $tags, 0, self::MAX_TAGS );
	}

	/**
	 * Merge Vary tokens without overwriting a handler's content negotiation.
	 */
	public static function merge_vary( string $existing, array $tokens ): string {
		$all = array();
		foreach ( array_merge( explode( ',', $existing ), $tokens ) as $token ) {
			$token = trim( (string) $token );
			if ( '' === $token || '*' === $token || 1 !== preg_match( '/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $token ) ) {
				continue;
			}
			$all[ strtolower( $token ) ] = $token;
		}

		return implode( ', ', array_values( $all ) );
	}

	/**
	 * Return the public CORS exposed header list.
	 *
	 * @return string[]
	 */
	public static function exposed_headers(): array {
		return array(
			'Repr-Digest',
			'Content-Digest',
			'ETag',
			'Last-Modified',
			'X-Cybermaps-Version',
			'X-Update-Frequency',
			'Surrogate-Key',
			'Cache-Tag',
			'X-LiteSpeed-Tag',
			'X-Markdown-Tokens',
			'X-Cybermaps-Markdown-Source',
		);
	}

	private static function bounded_int( mixed $value, int $fallback, int $minimum, int $maximum ): int {
		if ( ! is_scalar( $value ) || ! is_numeric( $value ) ) {
			return $fallback;
		}

		return min( $maximum, max( $minimum, (int) $value ) );
	}

	private static function slug( string $value, string $fallback ): string {
		$value = strtolower( trim( $value ) );
		$value = preg_replace( '/[^a-z0-9_-]+/', '-', $value );
		$value = is_string( $value ) ? trim( $value, '-' ) : '';
		$value = substr( $value, 0, 48 );

		return '' === $value ? $fallback : $value;
	}
}
