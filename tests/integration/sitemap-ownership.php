<?php
declare(strict_types=1);

use Cybermaps\Core\ConfigurationStore;
use Cybermaps\Discovery\StaticBridge;
use Cybermaps\Sitemap\Orchestrator;
use Cybermaps\Sitemap\PublicationPolicy;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'CYBERMAPS_SITEMAP_FIXTURE' ) ) {
	throw new RuntimeException( 'This fixture requires an explicitly disposable WP-CLI runtime.' );
}

function cybermaps_ownership_assert( bool $condition, string $name ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'Sitemap ownership: ' . $name );
	}
}

$original = get_option( 'cybermaps_settings', array() );
$permalinks = get_option( 'permalink_structure' );
$public = get_option( 'blog_public' );
$cases = array();
$blocker = static fn(): bool => false;
global $wp_rewrite;
$files = array( 'ownership-map.xml', 'ownership-map-posts-post-1.xml', 'ownership-news.xml', 'ownership-rss.xml', 'ai-sitemap.xml' );
$root = sys_get_temp_dir() . '/cybermaps-sitemap-ownership-' . bin2hex( random_bytes( 6 ) );
$root_filter = static fn(): string => $root;

try {
	update_option( 'blog_public', '1' );
	update_option( 'permalink_structure', '/%postname%/' );
	$enabled = array_replace( $original, array( 'enable_xml_sitemaps' => '1', 'redirect_wp_sitemap' => '1' ) );
	update_option( 'cybermaps_settings', $enabled );
	cybermaps_ownership_assert( false === ( new WP_Sitemaps() )->sitemaps_enabled(), 'active replacement' );
	$cases['active_replacement'] = true;

	$disabled = array_replace( $enabled, array( 'enable_xml_sitemaps' => '0' ) );
	update_option( 'cybermaps_settings', $disabled );
	cybermaps_ownership_assert( true === ( new WP_Sitemaps() )->sitemaps_enabled() && '' === PublicationPolicy::index_url(), 'disabled handover' );
	$cases['disabled_handover'] = true;
	add_filter( 'wp_sitemaps_enabled', $blocker, 9 );
	cybermaps_ownership_assert( false === ( new WP_Sitemaps() )->sitemaps_enabled(), 'third-party false preserved' );
	remove_filter( 'wp_sitemaps_enabled', $blocker, 9 );
	$cases['foreign_filter'] = true;
	update_option( 'blog_public', '0' );
	cybermaps_ownership_assert( false === ( new WP_Sitemaps() )->sitemaps_enabled(), 'private site preserved' );
	update_option( 'blog_public', '1' );
	$cases['private_site'] = true;

	add_rewrite_rule( '^ownership-foreign.xml$', 'index.php?foreign_sitemap=1', 'top' );
	$wp_rewrite->extra_rules_top['^ownership-obsolete.xml$'] = 'index.php?cybermaps_sitemap=index';
	Orchestrator::refresh_rewrite_rules();
	cybermaps_ownership_assert( ! isset( $wp_rewrite->extra_rules_top['^ownership-obsolete.xml$'] ) && isset( $wp_rewrite->extra_rules_top['^ownership-foreign.xml$'] ), 'rewrite handover' );
	unset( $wp_rewrite->extra_rules_top['^ownership-foreign.xml$'] );
	$cases['rewrite_handover'] = true;

	update_option( 'cybermaps_settings', array_replace( $enabled, array( 'redirect_wp_sitemap' => '0' ) ) );
	cybermaps_ownership_assert( ( new WP_Sitemaps() )->sitemaps_enabled() && PublicationPolicy::enabled(), 'redirect opt-out' );
	$cases['redirect_opt_out'] = true;
	update_option( 'cybermaps_settings', $enabled );
	update_option( 'permalink_structure', '' );
	cybermaps_ownership_assert( ( new WP_Sitemaps() )->sitemaps_enabled(), 'plain permalinks' );
	$cases['plain_permalinks'] = true;

	if ( is_multisite() ) {
		$sites = get_sites( array( 'number' => 2, 'site__not_in' => array( get_current_blog_id() ), 'fields' => 'ids' ) );
		cybermaps_ownership_assert( count( $sites ) > 0, 'multisite fixture needs another site' );
		switch_to_blog( (int) $sites[0] );
		$child_original = get_option( 'cybermaps_settings', array() );
		try {
			update_option( 'cybermaps_settings', array_replace( $child_original, array( 'enable_xml_sitemaps' => '0' ) ) );
			cybermaps_ownership_assert( ! PublicationPolicy::enabled() && 'off' === StaticBridge::get_mode(), 'child opt-out' );
		} finally {
			update_option( 'cybermaps_settings', $child_original );
			restore_current_blog();
		}
		cybermaps_ownership_assert( PublicationPolicy::enabled(), 'main site unchanged' );
		$cases['site_isolation'] = true;
	} else {
		add_filter( 'cybermaps_static_publication_root', $root_filter );
		wp_mkdir_p( $root );
		WP_Filesystem();
		global $wp_filesystem;
		$settings = array_replace( $enabled, array( 'static_engine_mode' => 'all', 'enable_discovery_hub' => '1', 'sitemap_url_base' => 'ownership-map', 'news_sitemap_url_base' => 'ownership-news', 'rss_sitemap_url_base' => 'ownership-rss' ) );
		update_option( 'cybermaps_settings', $settings );
		$bridge = StaticBridge::get_instance();
		foreach ( $files as $file ) {
			cybermaps_ownership_assert( $bridge->write_file( $file, '<owned/>' ), 'seed ' . $file );
		}
		$wp_filesystem->put_contents( $root . '/ownership-news.xml', '<foreign/>' );
		update_option( 'cybermaps_settings', array_replace( $settings, array( 'enable_xml_sitemaps' => '0' ) ) );
		foreach ( array_slice( $files, 0, 2 ) as $file ) {
			cybermaps_ownership_assert( ! $wp_filesystem->exists( $root . '/' . $file ) && ! $bridge->write_file( $file, '<obsolete/>' ), 'purge and block ' . $file );
		}
		cybermaps_ownership_assert( ! $wp_filesystem->exists( $root . '/ownership-rss.xml' ) && '<foreign/>' === $wp_filesystem->get_contents( $root . '/ownership-news.xml' ) && '<owned/>' === $wp_filesystem->get_contents( $root . '/ai-sitemap.xml' ), 'preserve foreign and AI files' );
		$cases['static_handover'] = true;
	}
} finally {
	remove_filter( 'wp_sitemaps_enabled', $blocker, 9 );
	if ( isset( $wp_filesystem ) && $wp_filesystem->is_dir( $root ) ) {
		$wp_filesystem->delete( $root, true );
	}
	remove_filter( 'cybermaps_static_publication_root', $root_filter );
	update_option( 'cybermaps_settings', $original );
	update_option( 'permalink_structure', $permalinks );
	update_option( 'blog_public', $public );
	ConfigurationStore::reset_memo();
	Orchestrator::refresh_rewrite_rules();
}

echo wp_json_encode( array( 'schema_version' => 1, 'sitemap_ownership_passed' => true, 'multisite' => is_multisite(), 'cases' => $cases ), JSON_PRETTY_PRINT ) . "\n";
