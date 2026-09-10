<?php
/**
 * Cybermaps runtime and optimization status collection.
 *
 * @package Cybermaps\Admin
 */

declare(strict_types=1);

namespace Cybermaps\Admin;

use Cybermaps\Core\CacheManager;
use Cybermaps\Core\ConfigurationStore;
use Cybermaps\Discovery\StaticBridge;
use Cybermaps\Integration\EdgeCache\LiteSpeedAdapter;
use Cybermaps\Integration\EdgeCache\VarnishAdapter;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Collects bounded, non-secret evidence for the System Status UI. */
final class SystemStatusCollector {

	/** @return array<string,mixed> */
	public static function collect(): array {
		$sections = array(
			'runtime'     => array(
				'label' => __( 'Runtime', 'cybermaps' ),
				'items' => self::runtime_items(),
			),
			'delivery'    => array(
				'label' => __( 'Delivery stack', 'cybermaps' ),
				'items' => self::delivery_items(),
			),
			'caching'     => array(
				'label' => __( 'Caching and acceleration', 'cybermaps' ),
				'items' => self::cache_items(),
			),
			'publication' => array(
				'label' => __( 'Cybermaps publication', 'cybermaps' ),
				'items' => self::publication_items(),
			),
		);

		return array(
			'generated_at' => time(),
			'summary'      => self::summarize( $sections ),
			'sections'     => $sections,
		);
	}

	/** Return a support-safe report without URLs, paths, addresses, or secrets. */
	public static function diagnostic_report(): array {
		$status = self::collect();
		$report = array(
			'cybermaps_version' => defined( 'CYBERMAPS_VERSION' ) ? CYBERMAPS_VERSION : '',
			'generated_at'      => gmdate( 'c', (int) $status['generated_at'] ),
			'summary'           => $status['summary'],
			'sections'          => array(),
		);

		foreach ( $status['sections'] as $section_id => $section ) {
			$report['sections'][ $section_id ] = array_map(
				static fn( array $item ): array => array(
					'id'           => $item['id'],
					'label'        => $item['label'],
					'availability' => $item['availability'],
					'usage'        => $item['usage'],
					'state'        => $item['state'],
					'detail'       => $item['detail'],
				),
				(array) $section['items']
			);
		}

		return $report;
	}

	/** @return array<int,array<string,string>> */
	private static function runtime_items(): array {
		global $wpdb;
		$db_version = is_object( $wpdb ) && method_exists( $wpdb, 'db_version' ) ? (string) $wpdb->db_version() : __( 'Unknown', 'cybermaps' );
		$memory     = (string) ini_get( 'memory_limit' );

		return array(
			self::item( 'wordpress', 'WordPress', __( 'Available', 'cybermaps' ), __( 'Required runtime', 'cybermaps' ), 'active', get_bloginfo( 'version' ) ),
			self::item( 'php', 'PHP', __( 'Available', 'cybermaps' ), __( 'Required runtime', 'cybermaps' ), 'active', PHP_VERSION ),
			self::item( 'database', __( 'Database', 'cybermaps' ), __( 'Available', 'cybermaps' ), __( 'WordPress-managed', 'cybermaps' ), 'active', $db_version ),
			self::item( 'memory', __( 'PHP memory limit', 'cybermaps' ), __( 'Available', 'cybermaps' ), __( 'Shared with WordPress', 'cybermaps' ), 'active', '' !== $memory ? $memory : __( 'Unknown', 'cybermaps' ) ),
			self::item(
				'multisite',
				__( 'Multisite', 'cybermaps' ),
				is_multisite() ? __( 'Enabled', 'cybermaps' ) : __( 'Not enabled', 'cybermaps' ),
				is_multisite() ? __( 'Dynamic-only publication safety', 'cybermaps' ) : __( 'Not applicable', 'cybermaps' ),
				is_multisite() ? 'active' : 'not_applicable',
				is_multisite() ? __( 'Static root files remain disabled so sites cannot compete for shared filenames.', 'cybermaps' ) : __( 'Single-site installation.', 'cybermaps' )
			),
		);
	}

	/** @return array<int,array<string,string>> */
	private static function delivery_items(): array {
		$server     = self::server_software();
		$cloudflare = self::cloudflare_item();
		$varnish    = self::varnish_item();

		return array(
			self::item(
				'web_server',
				__( 'Origin web server', 'cybermaps' ),
				'' !== $server ? __( 'Detected', 'cybermaps' ) : __( 'Unknown', 'cybermaps' ),
				__( 'Automatic publication compatibility', 'cybermaps' ),
				'' !== $server ? 'active' : 'unknown',
				'' !== $server ? $server : __( 'The server did not expose a software identifier.', 'cybermaps' )
			),
			$cloudflare,
			$varnish,
		);
	}

	/** @return array<int,array<string,string>> */
	private static function cache_items(): array {
		$profile             = CacheManager::capability_profile();
		$external            = ! empty( $profile['external'] );
		$litespeed_available = LiteSpeedAdapter::is_available();
		$litespeed_enabled   = LiteSpeedAdapter::is_enabled();
		$apcu_available      = CacheManager::is_apcu_available();
		$apcu_enabled        = CacheManager::is_apcu_enabled();

		return array(
			self::item(
				'object_cache',
				__( 'Persistent object cache', 'cybermaps' ),
				$external ? __( 'Detected', 'cybermaps' ) : __( 'Unavailable', 'cybermaps' ),
				$external ? __( 'Used automatically', 'cybermaps' ) : __( 'Using database transients', 'cybermaps' ),
				$external ? 'active' : 'unavailable',
				$external ? self::object_cache_label() : __( 'Install a conforming Redis or Memcached object-cache drop-in to accelerate derived publications.', 'cybermaps' )
			),
			self::integration_item( 'litespeed', __( 'LiteSpeed Cache', 'cybermaps' ), $litespeed_available, $litespeed_enabled, __( 'Cybermaps emits tags, performs targeted purges, and protects negotiated responses through official hooks.', 'cybermaps' ) ),
			self::integration_item( 'apcu', 'APCu L1', $apcu_available, $apcu_enabled, __( 'Cybermaps caches bounded derived values in disposable worker-local memory.', 'cybermaps' ) ),
			self::opcache_item(),
		);
	}

	/** @return array<int,array<string,string>> */
	private static function publication_items(): array {
		$settings = ConfigurationStore::settings();
		$mode     = StaticBridge::get_mode( $settings );
		$report   = get_option( 'cybermaps_last_static_sync_report', array() );
		$report   = is_array( $report ) ? $report : array();
		$sync     = sanitize_key( (string) ( $report['status'] ?? 'unknown' ) );
		$state    = 'complete' === $sync ? 'active' : ( 'off' === $mode ? 'not_applicable' : 'attention' );
		$detail   = 'off' === $mode
			? __( 'Physical publication is disabled; supported requests use WordPress routing.', 'cybermaps' )
			: sprintf(
				/* translators: 1: static mode, 2: latest synchronization status. */
				__( 'Mode: %1$s. Latest reconciliation: %2$s.', 'cybermaps' ),
				$mode,
				$sync
			);

		return array(
			self::item( 'static_engine', __( 'Static File Engine', 'cybermaps' ), 'off' === $mode ? __( 'Disabled', 'cybermaps' ) : __( 'Enabled', 'cybermaps' ), 'off' === $mode ? __( 'Dynamic routing', 'cybermaps' ) : __( 'Serving owned fallback files', 'cybermaps' ), $state, $detail ),
			self::cloudflare_rules_item(),
			self::cloudflare_credentials_item(),
			self::item(
				'cache_fencing',
				__( 'Cache invalidation', 'cybermaps' ),
				__( 'Available', 'cybermaps' ),
				__( 'Active', 'cybermaps' ),
				'active',
				__( 'Cybermaps advances site-local cache generations and never flushes unrelated cache entries.', 'cybermaps' )
			),
		);
	}

	private static function cloudflare_item(): array {
		$detected = CloudflareRuleManager::request_is_cloudflare();
		$state    = CloudflareRuleManager::state();
		$has_rule = ! empty( $state['header_rules'] ) || ! empty( $state['cache_rule'] );
		if ( $has_rule ) {
			return self::item( 'cloudflare', 'Cloudflare', $detected ? __( 'Detected', 'cybermaps' ) : __( 'Configured', 'cybermaps' ), __( 'Cybermaps-managed rules recorded', 'cybermaps' ), 'active', __( 'Use public verification to confirm that the active zone applies the recorded rules.', 'cybermaps' ) );
		}
		if ( $detected ) {
			return self::item( 'cloudflare', 'Cloudflare', __( 'Detected', 'cybermaps' ), __( 'Available but unused', 'cybermaps' ), 'available', __( 'Cloudflare can repair static discovery media types and protect negotiated responses.', 'cybermaps' ), self::advanced_url( 'cybermaps-edge-optimization' ) );
		}

		return self::item( 'cloudflare', 'Cloudflare', __( 'Not detected', 'cybermaps' ), __( 'Not configured', 'cybermaps' ), 'not_applicable', __( 'Cloudflare automation is optional and does not affect Core publication.', 'cybermaps' ) );
	}

	private static function cloudflare_rules_item(): array {
		$state       = CloudflareRuleManager::state();
		$installed   = ! empty( $state['header_rules'] ) || ! empty( $state['cache_rule'] );
		$fingerprint = CloudflareRuleManager::expected_fingerprint();
		$drifted     = $installed && ( '' === $fingerprint || ! hash_equals( (string) ( $state['fingerprint'] ?? '' ), $fingerprint ) );

		if ( $drifted ) {
			return self::item( 'cloudflare_rules', __( 'Cloudflare discovery rules', 'cybermaps' ), __( 'Installed state found', 'cybermaps' ), __( 'Repair required', 'cybermaps' ), 'attention', __( 'The hostname, endpoint inventory, or expected headers changed after the last installation.', 'cybermaps' ), self::advanced_url( 'cybermaps-edge-optimization' ) );
		}
		if ( $installed ) {
			return self::item( 'cloudflare_rules', __( 'Cloudflare discovery rules', 'cybermaps' ), __( 'Configured', 'cybermaps' ), __( 'Active configuration recorded', 'cybermaps' ), 'active', __( 'Run public delivery checks to verify the observed edge response.', 'cybermaps' ) );
		}

		return self::item( 'cloudflare_rules', __( 'Cloudflare discovery rules', 'cybermaps' ), __( 'Optional', 'cybermaps' ), __( 'Not installed', 'cybermaps' ), 'not_applicable', __( 'Core remains functional without Cloudflare. One-click OAuth can install both rule families without saving a credential.', 'cybermaps' ) );
	}

	private static function cloudflare_credentials_item(): array {
		$state       = CloudflareRuleManager::state();
		$method      = sanitize_key( (string) ( $state['credential_method'] ?? '' ) );
		$disposition = sanitize_key( (string) ( $state['credential_disposition'] ?? '' ) );
		if ( 'revoke_failed' === $disposition ) {
			return self::item( 'cloudflare_credentials', __( 'Cloudflare credentials', 'cybermaps' ), __( 'Not stored', 'cybermaps' ), __( 'Revocation unconfirmed', 'cybermaps' ), 'attention', __( 'Cybermaps discarded the temporary credential, but Cloudflare did not confirm revocation. Revoke Cybermaps under Cloudflare OAuth authorizations.', 'cybermaps' ), self::advanced_url( 'cybermaps-edge-optimization' ) );
		}
		if ( in_array( $disposition, array( 'revoked', 'discarded' ), true ) ) {
			$detail = 'oauth' === $method
				? __( 'The OAuth access token was used once, revoked, and removed from WordPress memory.', 'cybermaps' )
				: __( 'The fallback API token was used for one request, discarded, and never saved.', 'cybermaps' );
			return self::item( 'cloudflare_credentials', __( 'Cloudflare credentials', 'cybermaps' ), __( 'Not stored', 'cybermaps' ), __( 'Disposed after use', 'cybermaps' ), 'active', $detail );
		}
		return self::item( 'cloudflare_credentials', __( 'Cloudflare credentials', 'cybermaps' ), __( 'Not stored', 'cybermaps' ), __( 'No authorization performed', 'cybermaps' ), 'not_applicable', __( 'Cybermaps does not maintain a persistent Cloudflare connection.', 'cybermaps' ) );
	}

	private static function varnish_item(): array {
		$constant = defined( VarnishAdapter::URL_CONSTANT ) && '' !== trim( (string) constant( VarnishAdapter::URL_CONSTANT ) );
		$filtered = function_exists( 'has_filter' ) && false !== has_filter( 'cybermaps_varnish_purge_enabled' );
		$active   = $constant || $filtered;

		return self::item(
			'varnish',
			'Varnish',
			$active ? __( 'Configured', 'cybermaps' ) : __( 'Not detected', 'cybermaps' ),
			$active ? __( 'Exact-URL PURGE integration available', 'cybermaps' ) : __( 'Not configured', 'cybermaps' ),
			$active ? 'active' : 'not_applicable',
			$active ? __( 'Cybermaps sends bounded exact-path invalidations through the opt-in adapter.', 'cybermaps' ) : __( 'Varnish remains opt-in because its PURGE ACL and secret belong to the deployment.', 'cybermaps' )
		);
	}

	private static function opcache_item(): array {
		$available = function_exists( 'opcache_get_status' );
		$enabled   = $available && '1' === (string) ini_get( 'opcache.enable' );
		return self::item(
			'opcache',
			'OPcache',
			$available ? __( 'Available', 'cybermaps' ) : __( 'Unavailable', 'cybermaps' ),
			$enabled ? __( 'Benefits PHP automatically', 'cybermaps' ) : __( 'Not active', 'cybermaps' ),
			$enabled ? 'active' : ( $available ? 'available' : 'unavailable' ),
			$enabled ? __( 'Compiled PHP bytecode is cached by the runtime; no Cybermaps-specific integration is required.', 'cybermaps' ) : __( 'Enable OPcache at the PHP service level when appropriate.', 'cybermaps' )
		);
	}

	private static function integration_item( string $id, string $label, bool $available, bool $enabled, string $active_detail ): array {
		if ( ! $available ) {
			return self::item( $id, $label, __( 'Unavailable', 'cybermaps' ), __( 'Not used', 'cybermaps' ), 'unavailable', __( 'The required runtime or plugin integration was not detected.', 'cybermaps' ) );
		}
		if ( ! $enabled ) {
			return self::item( $id, $label, __( 'Available', 'cybermaps' ), __( 'Disabled in Cybermaps', 'cybermaps' ), 'available', __( 'This optimization is available but its Cybermaps opt-out is active.', 'cybermaps' ), self::advanced_url( 'cybermaps-edge-optimization' ) );
		}

		return self::item( $id, $label, __( 'Available', 'cybermaps' ), __( 'Used by Cybermaps', 'cybermaps' ), 'active', $active_detail );
	}

	private static function object_cache_label(): string {
		if ( defined( 'WP_REDIS_VERSION' ) || class_exists( '\RedisCachePro\Plugin' ) ) {
			return __( 'Redis-backed WordPress object cache detected.', 'cybermaps' );
		}
		if ( class_exists( '\Memcached' ) ) {
			return __( 'Persistent WordPress object cache detected; Memcached is available.', 'cybermaps' );
		}

		return __( 'A persistent WordPress object-cache drop-in is active.', 'cybermaps' );
	}

	private static function server_software(): string {
		$server = isset( $_SERVER['SERVER_SOFTWARE'] ) && is_scalar( $_SERVER['SERVER_SOFTWARE'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['SERVER_SOFTWARE'] ) )
			: '';
		return substr( $server, 0, 120 );
	}

	/** @param array<string,array<string,mixed>> $sections @return array<string,int> */
	private static function summarize( array $sections ): array {
		$summary = array(
			'active'    => 0,
			'available' => 0,
			'attention' => 0,
		);
		foreach ( $sections as $section ) {
			foreach ( (array) ( $section['items'] ?? array() ) as $item ) {
				$state = (string) ( $item['state'] ?? '' );
				if ( isset( $summary[ $state ] ) ) {
					++$summary[ $state ];
				}
			}
		}
		return $summary;
	}

	/** @return array<string,string> */
	private static function item( string $id, string $label, string $availability, string $usage, string $state, string $detail, string $action_url = '' ): array {
		return array(
			'id'           => $id,
			'label'        => $label,
			'availability' => $availability,
			'usage'        => $usage,
			'state'        => $state,
			'detail'       => $detail,
			'action_url'   => $action_url,
		);
	}

	private static function advanced_url( string $anchor ): string {
		return add_query_arg(
			array(
				'page' => 'cybermaps-settings',
				'tab'  => 'advanced',
			),
			admin_url( 'admin.php' )
		) . '#' . $anchor;
	}
}
