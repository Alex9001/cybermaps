<?php
declare(strict_types=1);

namespace Cybermaps\Sitemap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Shared ownership policy for Cybermaps search-engine sitemap publications. */
final class PublicationPolicy {
	/**
	 * Existing sites retain sitemap publication until the administrator opts out.
	 *
	 * @param array<string,mixed>|null $settings Optional settings snapshot.
	 */
	public static function enabled( ?array $settings = null ): bool {
		$settings ??= \Cybermaps\Core\ConfigurationStore::settings();
		return ! \array_key_exists( 'enable_xml_sitemaps', $settings )
			|| \in_array( $settings['enable_xml_sitemaps'], array( '1', 1, true ), true );
	}

	/**
	 * Core routes can be replaced only when the configured XML route can resolve.
	 *
	 * @param array<string,mixed>|null $settings Optional settings snapshot.
	 */
	public static function replaces_core( ?array $settings = null ): bool {
		$settings ??= \Cybermaps\Core\ConfigurationStore::settings();
		$permalinks = \get_option( 'permalink_structure', '' );
		$redirect   = ! \array_key_exists( 'redirect_wp_sitemap', $settings )
			|| \in_array( $settings['redirect_wp_sitemap'], array( '1', 1, true ), true );
		return self::enabled( $settings )
			&& $redirect && \is_string( $permalinks ) && '' !== $permalinks;
	}

	/** Preserve WordPress and other plugins' decisions when we do not own Core routes. */
	public static function filter_core_enabled( bool $enabled ): bool {
		return self::replaces_core() ? false : $enabled;
	}

	/**
	 * Return an advertised index URL only while Cybermaps manages sitemaps.
	 *
	 * @param array<string,mixed>|null $settings Optional settings snapshot.
	 */
	public static function index_url( ?array $settings = null ): string {
		$settings ??= \Cybermaps\Core\ConfigurationStore::settings();
		if ( ! self::enabled( $settings ) ) {
			return '';
		}
		$routes = PublicationRouteSlugs::resolve( $settings );
		return \Cybermaps\Core\URLManager::get_home_url( '/' . $routes[ PublicationRouteSlugs::SITEMAP_KEY ] . '.xml' );
	}
}
