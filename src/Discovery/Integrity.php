<?php
declare(strict_types=1);
namespace Cybermaps\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared HTTP integrity and conditional-request helper.
 */
class Integrity {
	/**
	 * Whether the AI Publication Hub is enabled in settings.
	 */
	public static function is_hub_enabled(): bool {
		$settings = \Cybermaps\Core\ConfigurationStore::settings();
		return ! empty( $settings['enable_discovery_hub'] );
	}

	/**
	 * Send Cybermaps publication integrity headers and handle conditional discovery.
	 *
	 * @param string   $content The content to hash and send.
	 * @param int      $ttl Cache-Control max-age in seconds.
	 * @param int|null $last_modified_ts Optional timestamp only when it represents
	 *                                   every input to this exact body.
	 * @return void
	 */
	public static function send_headers( string $content, int $ttl = 3600, ?int $last_modified_ts = null ): void {
		// WordPress marks virtual discovery URLs as 404 before handlers run; force success.
		status_header( 200 );
		$policy = PublicationCachePolicy::for_request_path(
			(string) \Cybermaps\Core\URLManager::get_request_path()
		);
		// Retain the historical per-handler TTL argument as an internal override.
		$policy['browser_ttl'] = max( 0, $ttl );
		$policy['shared_ttl']  = max( 0, $ttl );
		$not_modified          = self::send_representation_headers( $content, $policy, $last_modified_ts );

		// X-Robots-Tag for AI training control.
		$settings = \Cybermaps\Core\ConfigurationStore::settings();
		if ( self::should_forbid_ai_training( $settings ) ) {
			header( 'X-Robots-Tag: noai, noimageai' );
		}

		// Content-Security-Policy for discovery data endpoints
		header( "Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'" );

		if ( $not_modified ) {
			status_header( 304 );
			exit;
		}
	}

	/**
	 * Send the portable representation-level HTTP contract and return whether a
	 * conditional request matched. Callers that own an output buffer, such as
	 * XML sitemap rendering, can clean it before sending the 304 response.
	 *
	 * @param array<string,mixed> $policy Normalized publication cache policy.
	 */
	public static function send_representation_headers( string $content, array $policy, ?int $last_modified_ts = null ): bool {
		$etag = PublicationCachePolicy::etag( $content );
		header( 'X-Cybermaps-Version: ' . CYBERMAPS_VERSION );
		self::send_cors_headers();
		header( 'ETag: ' . $etag );
		if ( null !== $last_modified_ts && $last_modified_ts > 0 ) {
			header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $last_modified_ts ) . ' GMT' );
		} else {
			$last_modified_ts = null;
		}
		header( 'Cache-Control: ' . PublicationCachePolicy::cache_control( $policy ) );
		header( 'Repr-Digest: ' . PublicationCachePolicy::repr_digest( $content ) );

		// PHP cannot know whether a later server/CDN gzip or Brotli filter will
		// transform the bytes. Content-Digest is emitted only when an integration
		// proves the final coding is identity.
		$encoding = apply_filters( 'cybermaps_publication_final_content_encoding', '', $policy, $content );
		$encoding = is_string( $encoding ) ? $encoding : '';
		$digest   = PublicationCachePolicy::content_digest( $content, $encoding );
		if ( '' !== $digest ) {
			header( 'Content-Digest: ' . $digest );
		}

		$tags = PublicationCachePolicy::tags( $policy );
		if ( ! empty( $tags ) ) {
			header( 'Surrogate-Key: ' . implode( ' ', $tags ) );
			header( 'Cache-Tag: ' . implode( ',', $tags ) );
			\Cybermaps\Integration\EdgeCache\LiteSpeedAdapter::emit_tags( $tags );
		}

		return self::is_not_modified( $etag, $last_modified_ts );
	}

	/**
	 * Publish the cross-origin read contract shared by public machine endpoints.
	 */
	public static function send_cors_headers(): void {
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Expose-Headers: ' . implode( ', ', PublicationCachePolicy::exposed_headers() ) );
	}

	/**
	 * End a public CORS preflight before the GET/HEAD-only guard runs.
	 */
	public static function handle_preflight(): void {
		if ( 'OPTIONS' !== \Cybermaps\Core\ReadOnlyRequest::method() ) {
			return;
		}

		status_header( 204 );
		self::send_cors_headers();
		header( 'Allow: GET, HEAD, OPTIONS' );
		header( 'Access-Control-Allow-Methods: GET, HEAD, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Accept, If-Modified-Since, If-None-Match' );
		$policy = PublicationCachePolicy::for_request_path(
			(string) \Cybermaps\Core\URLManager::get_request_path()
		);
		header( 'Access-Control-Max-Age: ' . (int) $policy['preflight_ttl'] );
		header( 'Cache-Control: public, max-age=' . (int) $policy['preflight_ttl'] . ', must-revalidate' );
		header( 'Content-Length: 0' );
		exit;
	}

	/**
	 * Evaluate HTTP conditional headers using RFC precedence.
	 *
	 * If-None-Match takes precedence over If-Modified-Since. A present but
	 * nonmatching ETag must therefore produce a fresh response even when the
	 * date condition would otherwise match. Weak ETags use the weak comparison
	 * required for GET and HEAD cache validation.
	 *
	 * @param string               $etag Current quoted ETag.
	 * @param int|null             $last_modified_ts Authoritative Last-Modified
	 *                                                timestamp, when one exists.
	 * @param array<string, mixed>|null $server Optional request server values for tests.
	 */
	public static function is_not_modified( string $etag, ?int $last_modified_ts, ?array $server = null ): bool {
		$server = null === $server ? $_SERVER : $server;

		$if_none_match = isset( $server['HTTP_IF_NONE_MATCH'] )
			&& is_scalar( $server['HTTP_IF_NONE_MATCH'] )
			? trim( (string) wp_unslash( (string) $server['HTTP_IF_NONE_MATCH'] ) )
			: '';

		if ( '' !== $if_none_match ) {
			foreach ( explode( ',', $if_none_match ) as $candidate ) {
				$candidate = trim( $candidate );
				if (
					'*' === $candidate
					|| hash_equals( self::normalize_etag( $etag ), self::normalize_etag( $candidate ) )
				) {
					return true;
				}
			}

			// RFC conditional precedence: do not evaluate the date header when
			// If-None-Match was supplied but did not match.
			return false;
		}

		if ( null === $last_modified_ts || $last_modified_ts < 1 ) {
			return false;
		}

		$if_modified_since = isset( $server['HTTP_IF_MODIFIED_SINCE'] )
			&& is_scalar( $server['HTTP_IF_MODIFIED_SINCE'] )
			? trim( (string) wp_unslash( (string) $server['HTTP_IF_MODIFIED_SINCE'] ) )
			: '';
		if ( '' === $if_modified_since ) {
			return false;
		}

		$client_time = strtotime( $if_modified_since );
		return false !== $client_time && $client_time >= $last_modified_ts;
	}

	/**
	 * Strip the weak validator prefix for If-None-Match comparison.
	 */
	private static function normalize_etag( string $etag ): string {
		$etag = trim( $etag );
		if ( str_starts_with( $etag, 'W/' ) || str_starts_with( $etag, 'w/' ) ) {
			$etag = substr( $etag, 2 );
		}
		return trim( $etag );
	}

	/**
	 * Read the current canonical AI usage policy.
	 *
	 * @param array<string, mixed> $settings Cybermaps settings.
	 */
	private static function should_forbid_ai_training( array $settings ): bool {
		$stored = $settings['ai_usage_training'] ?? 'forbid';
		return ! is_scalar( $stored ) || 'allow' !== (string) $stored;
	}
}
