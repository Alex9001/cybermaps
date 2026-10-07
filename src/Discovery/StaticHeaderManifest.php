<?php
/**
 * Static publication header deployment contract.
 *
 * @package Cybermaps\Discovery
 */

declare(strict_types=1);

namespace Cybermaps\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Observes header expectations for enabled static publication candidates.
 * Snapshot validators are diagnostics, never deployable header overrides.
 * Origin/server response semantics remain authoritative. This class never
 * writes a web-server or CDN configuration.
 */
final class StaticHeaderManifest {

	/**
	 * Return enabled candidate policies and observational filesystem snapshots.
	 *
	 * @param array<string, mixed>|null $settings General settings.
	 * @return array<string, mixed>
	 */
	public function get_manifest( ?array $settings = null ): array {
		$settings = is_array( $settings ) ? $settings : \Cybermaps\Core\ConfigurationStore::settings();
		$targets  = \Cybermaps\Core\EndpointRegistry::get_instance()->get_static_targets( 'all', $settings );
		$policies = array();

		foreach ( $targets as $target ) {
			$path = (string) ( $target['path'] ?? '' );
			if ( '' === $path || isset( $policies[ $path ] ) ) {
				continue;
			}
			$policies[ $path ] = $this->policy_for_target( $target );
		}

		ksort( $policies, SORT_STRING );

		return array(
			'version'          => 1,
			'header_authority' => 'origin',
			'policies'         => $policies,
			'snippets'         => $this->snippets( $policies ),
		);
	}

	/**
	 * Validate observed headers against one static deployment policy.
	 *
	 * @param array<string, mixed>  $policy Header policy.
	 * @param array<string, string> $headers Lowercase observed headers.
	 * @return array{status:string,message:string,missing:array<int,string>,protocol_missing:array<int,string>}
	 */
	public static function validate_headers( array $policy, array $headers, string $body = '' ): array {
		$missing = self::required_header_mismatches( $policy, $headers );
		self::append_expected_header_mismatch( $missing, $policy, $headers, 'repr_digest', 'repr-digest', 'Repr-Digest' );
		self::append_expected_header_mismatch( $missing, $policy, $headers, 'etag', 'etag', 'ETag' );
		self::append_expected_header_mismatch( $missing, $policy, $headers, 'last_modified', 'last-modified', 'Last-Modified' );
		self::append_content_digest_mismatch( $missing, $policy, $headers, $body );
		self::append_expected_header_mismatch( $missing, $policy, $headers, 'content_usage', 'content-usage', 'Content-Usage' );

		if ( array() === $missing ) {
			return array(
				'status'           => 'healthy',
				'message'          => __( 'Static header policy conforms to the deployed response.', 'cybermaps' ),
				'missing'          => array(),
				'protocol_missing' => array(),
			);
		}

		return array(
			'status'           => 'error',
			'message'          => sprintf(
				/* translators: %s: missing or mismatched HTTP header names. */
				__( 'Static-server header policy is incomplete: %s.', 'cybermaps' ),
				implode( ', ', $missing )
			),
			'missing'          => $missing,
			'protocol_missing' => self::protocol_header_errors( $missing, $headers, $policy ),
		);
	}

	/** Missing optional digests are advisory; supplied incorrect digests are errors. */
	private static function protocol_header_errors( array $missing, array $headers, array $policy ): array {
		$required = array( 'Content-Type' );
		if ( '/.well-known/ai-catalog.json' === ( $policy['path'] ?? '' ) ) {
			$required[] = 'Access-Control-Allow-Origin';
		}
		foreach ( array( 'Repr-Digest', 'Content-Digest' ) as $name ) {
			if ( '' !== trim( (string) ( $headers[ strtolower( $name ) ] ?? '' ) ) ) {
				$required[] = $name;
			}
		}
		return array_values( array_intersect( $missing, $required ) );
	}

	/**
	 * Validate headers required for every static publication.
	 *
	 * @param array<string, mixed>  $policy Header policy.
	 * @param array<string, string> $headers Lowercase observed headers.
	 * @return array<int, string>
	 */
	private static function required_header_mismatches( array $policy, array $headers ): array {
		return array_merge(
			self::content_header_mismatches( $policy, $headers ),
			self::cors_header_mismatches( $headers )
		);
	}

	/**
	 * Validate Content-Type and Cache-Control in publication order.
	 *
	 * @param array<string, mixed>  $policy Header policy.
	 * @param array<string, string> $headers Lowercase observed headers.
	 * @return array<int, string>
	 */
	private static function content_header_mismatches( array $policy, array $headers ): array {
		$missing = array();
		$mime    = strtolower( trim( explode( ';', (string) ( $headers['content-type'] ?? '' ), 2 )[0] ) );
		$want    = strtolower( (string) ( $policy['mime'] ?? '' ) );
		if ( '' === $mime || $mime !== $want ) {
			$missing[] = 'Content-Type';
		}
		if ( ! str_contains( strtolower( (string) ( $headers['cache-control'] ?? '' ) ), 'max-age=' . (int) ( $policy['browser_ttl'] ?? PublicationCachePolicy::DEFAULT_BROWSER_TTL ) ) ) {
			$missing[] = 'Cache-Control';
		}

		return $missing;
	}

	/**
	 * Validate CORS response headers in publication order.
	 *
	 * @param array<string, string> $headers Lowercase observed headers.
	 * @return array<int, string>
	 */
	private static function cors_header_mismatches( array $headers ): array {
		$missing = array();
		if ( '*' !== trim( (string) ( $headers['access-control-allow-origin'] ?? '' ) ) ) {
			$missing[] = 'Access-Control-Allow-Origin';
		}
		if ( ! str_contains( (string) ( $headers['access-control-expose-headers'] ?? '' ), 'Repr-Digest' ) ) {
			$missing[] = 'Access-Control-Expose-Headers';
		}

		return $missing;
	}

	/**
	 * Append one optional exact-value header mismatch.
	 *
	 * @param array<int, string>    $missing Mismatched header labels.
	 * @param array<string, mixed>  $policy Header policy.
	 * @param array<string, string> $headers Lowercase observed headers.
	 */
	private static function append_expected_header_mismatch(
		array &$missing,
		array $policy,
		array $headers,
		string $policy_key,
		string $header_key,
		string $label
	): void {
		$expected = (string) ( $policy[ $policy_key ] ?? '' );
		if ( '' !== $expected && ! hash_equals( $expected, trim( (string) ( $headers[ $header_key ] ?? '' ) ) ) ) {
			$missing[] = $label;
		}
	}

	/**
	 * Append the opt-in identity Content-Digest mismatch.
	 *
	 * @param array<int, string>    $missing Mismatched header labels.
	 * @param array<string, mixed>  $policy Header policy.
	 * @param array<string, string> $headers Lowercase observed headers.
	 */
	private static function append_content_digest_mismatch( array &$missing, array $policy, array $headers, string $body ): void {
		$expected_content_digest = (string) ( $policy['content_digest'] ?? '' );
		if ( '' === $expected_content_digest ) {
			return;
		}

		$observed_content_digest = trim( (string) ( $headers['content-digest'] ?? '' ) );
		// Content-Digest is opt-in and identity-only. When a deployment has
		// declared it, validate the received bytes as well as the header so a
		// proxy-side encoding change cannot appear conformant.
		$body_content_digest = '' !== $body ? PublicationCachePolicy::content_digest( $body, 'identity' ) : '';
		if (
			! hash_equals( $expected_content_digest, $observed_content_digest )
			|| '' === $body_content_digest
			|| ! hash_equals( $expected_content_digest, $body_content_digest )
		) {
			$missing[] = 'Content-Digest';
		}
	}

	/**
	 * @param array<string, mixed> $target Static target metadata.
	 * @return array<string, mixed>
	 */
	private function policy_for_target( array $target ): array {
		$path          = (string) ( $target['path'] ?? '' );
		$content_usage = ( new Robots() )->get_content_usage_header_for_path( $path );
		$deployed      = $this->deployed_metadata( (string) ( $target['filename'] ?? ltrim( $path, '/' ) ) );
		$cache_policy  = PublicationCachePolicy::for_publication( 'static', (string) ( $target['id'] ?? 'publication' ) );

		return array(
			'path'               => $path,
			'endpoint_id'        => (string) ( $target['id'] ?? '' ),
			'bucket'             => (string) ( $target['bucket'] ?? '' ),
			'enabled'            => ! empty( $target['enabled'] ),
			'snapshot_only'      => true,
			'existence_snapshot' => '' !== $deployed['repr_digest'],
			'mime'               => (string) ( $target['type'] ?? 'application/octet-stream' ),
			'browser_ttl'        => (int) $cache_policy['browser_ttl'],
			'shared_ttl'         => (int) $cache_policy['shared_ttl'],
			'cache_control'      => PublicationCachePolicy::cache_control( $cache_policy ),
			'cors_origin'        => '*',
			'expose_headers'     => implode( ', ', PublicationCachePolicy::exposed_headers() ),
			'repr_digest'        => (string) $deployed['repr_digest'],
			// Content-Digest requires current identity response bytes. Observed
			// digests never authorize literal header configuration.
			'content_digest'     => '',
			'etag'               => (string) $deployed['etag'],
			'last_modified'      => (string) $deployed['last_modified'],
			'tags'               => PublicationCachePolicy::tags( $cache_policy ),
			'content_usage'      => $content_usage,
		);
	}

	/**
	 * @param array<string, array<string, mixed>> $policies Static path policies.
	 * @return array<string, string>
	 */
	private function snippets( array $policies ): array {
		$guidance = implode(
			"\n",
			array(
				'# Cybermaps header guidance only; no response-header overrides are generated.',
				'# Preserve origin Content-Type, Cache-Control, CORS and response status for dynamic responses and errors.',
				'# Configure MIME types in the existing static-file handler for actual regular files only.',
				'# Keep native current-file ETag and Last-Modified handling; never copy snapshot validators into configuration.',
				'# Repr-Digest and Content-Digest require current response bytes; delegate to the origin if the server cannot calculate them.',
				'# Apply any local public caching/CORS policy only to successful responses from verified owned static files.',
				'# When that distinction cannot be enforced, keep the endpoint dynamic and use the routing recipes separately.',
			)
		);
		$cdn      = array();
		foreach ( array_keys( $policies ) as $path ) {
			$cdn[] = self::cdn_policy_snippet( $path );
		}

		return array(
			'apache'    => $guidance,
			'nginx'     => $guidance,
			'litespeed' => $guidance,
			'varnish'   => \Cybermaps\Integration\EdgeCache\VarnishAdapter::advisory_vcl(),
			'routing'   => $this->routing_snippets(),
			'cdn'       => implode( "\n", $cdn ),
		);
	}

	/** Describe an origin-authoritative CDN posture without header mutations. */
	private static function cdn_policy_snippet( string $path ): string {
		return (string) wp_json_encode(
			array(
				'path'   => $path,
				'action' => array(
					'response_headers' => 'preserve_origin',
					'cache_control'    => 'respect_origin',
					'validators'       => 'origin_or_current_file',
				),
				'note'   => 'Advisory only. Preserve private/no-store and error responses. Filesystem snapshot metadata is diagnostic and must not be deployed as literal headers.',
			)
		);
	}

	/**
	 * Observe current bytes and modification time at a candidate static path.
	 * This neither proves ownership nor authorizes header deployment. The values
	 * are diagnostic snapshots and may change immediately after inspection.
	 */
	private function deployed_metadata( string $filename ): array {
		if ( '' === $filename ) {
			return array(
				'repr_digest'   => '',
				'etag'          => '',
				'last_modified' => '',
			);
		}
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( ! function_exists( 'WP_Filesystem' ) || ! WP_Filesystem() ) {
			return array(
				'repr_digest'   => '',
				'etag'          => '',
				'last_modified' => '',
			);
		}

		global $wp_filesystem;
		if ( ! is_object( $wp_filesystem ) ) {
			return array(
				'repr_digest'   => '',
				'etag'          => '',
				'last_modified' => '',
			);
		}
		$path = StaticBridge::get_instance()->get_file_path( $filename );
		if ( '' === $path || ! $wp_filesystem->exists( $path ) || ( method_exists( $wp_filesystem, 'is_file' ) && ! $wp_filesystem->is_file( $path ) ) ) {
			return array(
				'repr_digest'   => '',
				'etag'          => '',
				'last_modified' => '',
			);
		}
		$body = $wp_filesystem->get_contents( $path );
		if ( ! is_string( $body ) ) {
			return array(
				'repr_digest'   => '',
				'etag'          => '',
				'last_modified' => '',
			);
		}

		$mtime = method_exists( $wp_filesystem, 'mtime' ) ? (int) $wp_filesystem->mtime( $path ) : 0;
		return array(
			'repr_digest'   => PublicationCachePolicy::repr_digest( $body ),
			'etag'          => PublicationCachePolicy::etag( $body ),
			'last_modified' => $mtime > 0 ? gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT' : '',
		);
	}

	/**
	 * Return narrow, advisory routes for paths intentionally kept dynamic.
	 *
	 * @return array<string,string>
	 */
	private function routing_snippets(): array {
		$paths  = $this->dynamic_routing_paths();
		$nginx  = self::nginx_routing_snippet( $paths );
		$apache = self::apache_routing_snippet( $paths );

		return array(
			'nginx'                => $nginx,
			'apache'               => $apache,
			'litespeed'            => $apache,
			'apache_openlitespeed' => $apache,
			'litespeed_cache'      => self::litespeed_cache_snippet( $paths ),
			'varnish'              => self::varnish_routing_snippet( $paths ),
			'reverse_proxy_cdn'    => self::reverse_proxy_routing_snippet( $paths ),
		);
	}

	/** Return canonical protocol paths and aliases that must reach WordPress. */
	private function dynamic_routing_paths(): array {
		$registry = \Cybermaps\Core\EndpointRegistry::get_instance();
		$paths    = array();
		foreach ( array( 'discovery_index', 'api_catalog', 'ai_catalog', 'mcp_server_card' ) as $id ) {
			$definition = $registry->get( $id );
			if ( ! is_array( $definition ) || empty( $definition['path'] ) ) {
				continue;
			}
			$paths[] = (string) $definition['path'];
			$paths   = array_merge( $paths, array_filter( (array) ( $definition['aliases'] ?? array() ), 'is_string' ) );
		}

		return array_values( array_unique( $paths ) );
	}

	/** Build copy-ready nginx exact-route locations. */
	private static function nginx_routing_snippet( array $paths ): string {
		$lines = array( '# Cybermaps dynamic discovery routes. Add inside the server block before a generic /.well-known/ location.' );
		foreach ( $paths as $path ) {
			$lines[] = 'location = ' . $path . ' { rewrite ^ /index.php last; }';
		}

		return implode( "\n", $lines );
	}

	/** Build copy-ready Apache and OpenLiteSpeed rewrite rules. */
	private static function apache_routing_snippet( array $paths ): string {
		$lines = array(
			'# Cybermaps dynamic discovery routes. Add in the virtual host before generic .well-known handling.',
			'RewriteEngine On',
		);
		foreach ( $paths as $path ) {
			$lines[] = 'RewriteRule ^' . preg_quote( ltrim( $path, '/' ), '#' ) . '$ /index.php [END,QSA]';
		}

		return implode( "\n", $lines );
	}

	/** Build LiteSpeed Cache bypass rules for dynamic discovery and Markdown. */
	private static function litespeed_cache_snippet( array $paths ): string {
		$pattern = self::routing_path_pattern( $paths );
		return implode(
			"\n",
			array(
				'# LiteSpeed Cache bypass. Routing still requires the Apache/OpenLiteSpeed rules above.',
				'<IfModule LiteSpeed>',
				'  RewriteEngine On',
				'  RewriteCond %{REQUEST_URI} ^/(?:' . $pattern . ')/?$ [NC]',
				'  RewriteRule .* - [E=Cache-Control:no-cache]',
				'  RewriteCond %{HTTP:Accept} text/markdown [NC]',
				'  RewriteRule .* - [E=Cache-Control:no-cache]',
				'</IfModule>',
			)
		);
	}

	/** Build a Varnish pass rule for dynamic discovery and Markdown variants. */
	private static function varnish_routing_snippet( array $paths ): string {
		$pattern = self::routing_path_pattern( $paths );
		return implode(
			"\n",
			array(
				'# Merge this condition into the existing vcl_recv subroutine.',
				'if (req.url ~ "^/(?:' . $pattern . ')(?:\\?.*)?$" || req.http.Accept ~ "(?i)text/markdown") {',
				'  return (pass);',
				'}',
			)
		);
	}

	/** Build a vendor-neutral reverse-proxy/CDN routing policy. */
	private static function reverse_proxy_routing_snippet( array $paths ): string {
		$encoded = wp_json_encode(
			array(
				'match'  => array( 'exact_paths' => array_values( $paths ) ),
				'action' => array(
					'origin' => 'wordpress',
					'cache'  => 'bypass',
				),
				'note'   => 'Translate this vendor-neutral policy into the provider rule language; Cybermaps never changes edge configuration.',
			)
		);

		return is_string( $encoded ) ? $encoded : '';
	}

	/** Return a regex-safe route alternation without leading slashes. */
	private static function routing_path_pattern( array $paths ): string {
		return implode(
			'|',
			array_map(
				static fn( string $path ): string => preg_quote( ltrim( $path, '/' ), '#' ),
				$paths
			)
		);
	}
}
