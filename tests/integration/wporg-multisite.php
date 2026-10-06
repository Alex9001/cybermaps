<?php
declare(strict_types=1);
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! is_multisite() ) {
	throw new RuntimeException( 'Disposable multisite fixture required.' );
}
$site = wp_insert_site( array( 'domain' => 'cybermaps.test', 'path' => '/second/', 'title' => 'Second site' ) );
if ( is_wp_error( $site ) ) {
	throw new RuntimeException( $site->get_error_message() );
}
foreach ( array( get_current_blog_id(), (int) $site ) as $site_id ) {
	switch_to_blog( $site_id );
	try {
		\Cybermaps\Core\ConfigurationStore::reset_memo();
		update_option( 'cybermaps_settings', array( 'static_engine_mode' => 'all', 'show_sitemap_attribution' => '0' ) );
		if ( 'off' !== \Cybermaps\Discovery\StaticBridge::get_mode() ) {
			throw new RuntimeException( 'Multisite attempted shared-root static publication.' );
		}
		\Cybermaps\Core\MCPMigration::run();
		if ( \Cybermaps\MCP\WordPressIntegration::is_enabled() ) throw new RuntimeException( 'Network adapter bypassed per-site opt-in.' );
		$settings = get_option( 'cybermaps_settings' );
		$settings['enable_discovery_hub'] = '1';
		$settings['enable_mcp_adapter'] = '1';
		update_option( 'cybermaps_settings', $settings );
		\Cybermaps\Core\ConfigurationStore::reset_memo();
		if ( ! \Cybermaps\MCP\WordPressIntegration::is_enabled() ) throw new RuntimeException( 'Network adapter unavailable after site opt-in.' );
		$xml = ( new \Cybermaps\Sitemap\Orchestrator() )->generate_xml( 'index', 1 );
		if ( str_contains( $xml, 'sitemap-attribution.xsl' ) ) {
			throw new RuntimeException( 'Site received unconsented attribution.' );
		}
	} finally {
		restore_current_blog();
		\Cybermaps\Core\ConfigurationStore::reset_memo();
	}
}
echo "Two-site dynamic-only publication and default-off attribution passed.\n";
