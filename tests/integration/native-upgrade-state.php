<?php
declare(strict_types=1);

/** Run only through WP-CLI on the isolated native-upgrade.py installation. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'CYBERMAPS_NATIVE_UPGRADE_FIXTURE' ) || true !== CYBERMAPS_NATIVE_UPGRADE_FIXTURE ) {
	throw new RuntimeException( 'Disposable native upgrade fixture required.' );
}

function cybermaps_native_upgrade_require( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/** Populate a real persisted report, including its resource and finding rows. */
function cybermaps_native_upgrade_report( int $post_id ): void {
	\Cybermaps\Audit\AuditRunRepository::create_tables();
	$repository = new \Cybermaps\Audit\AuditRunRepository();
	$run = $repository->begin( \Cybermaps\Audit\AuditPolicy::from_settings() );
	$repository->add_resource( $run, array(
		'resource_key' => 'post:' . $post_id,
		'object_type' => 'post', 'object_id' => $post_id, 'post_type' => 'page',
		'title' => 'Native update preservation fixture', 'url' => get_permalink( $post_id ),
		'modified_gmt' => '2026-01-01 00:00:00', 'word_count' => 10, 'age_days' => 1,
		'has_media' => false, 'indexable' => true, 'indexability' => array( 'indexable' => true ),
		'measurement' => array( 'fixture' => true ), 'content_hash' => hash( 'sha256', 'native-update-fixture' ),
	), array( array(
		'key' => 'thin_content', 'severity' => 'info', 'summary' => 'Disposable fixture finding',
		'evidence' => array( 'word_count' => 10 ), 'recommendation' => 'Fixture only.',
	) ) );
	$repository->complete( $run, 1, 1, hash( 'sha256', 'native-update-report' ) );
}

function cybermaps_native_upgrade_seed(): void {
	$settings = (array) get_option( 'cybermaps_settings', array() );
	$settings = array_merge( $settings, array(
		'enable_xml_sitemaps' => '1', 'redirect_wp_sitemap' => '0', 'show_sitemap_attribution' => '0',
		'enable_discovery_hub' => '1', 'enable_mcp_adapter' => '0', 'enable_analytics' => '0',
		'static_engine_mode' => 'well_known', 'report_theme' => 'midnight', 'delete_data_on_uninstall' => '0',
	) );
	update_option( 'cybermaps_settings', $settings );
	update_option( 'cybermaps_discovery_center', \Cybermaps\Admin\Settings\Sanitizers\DiscoveryCenterSanitizer::sanitize_import( array( 'archetype' => 'knowledgebase' ) ) );
	update_option( 'cybermaps_robots_manager', \Cybermaps\Admin\Settings\Sanitizers\RobotsManagerSanitizer::sanitize_import( array( 'manual_directives' => '# Native update fixture' ) ) );
	update_option( 'cybermaps_identity_data', array( 'type' => 'Organization', 'name' => 'Native update fixture ' . get_current_blog_id() ) );
	update_option( 'native_upgrade_unrelated_option', 'preserve-' . get_current_blog_id() );
	\Cybermaps\Core\ConfigurationStore::reset_memo();
	$post_id = wp_insert_post( array(
		'post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'upgrade-preservation',
		'post_title' => 'Upgrade preservation', 'post_content' => 'A disposable page for the native WordPress update test.',
	), true );
	cybermaps_native_upgrade_require( ! is_wp_error( $post_id ), 'Could not seed published content.' );
	cybermaps_native_upgrade_report( (int) $post_id );
	global $wp_rewrite;
	$wp_rewrite->set_permalink_structure( '/%postname%/' );
	flush_rewrite_rules( false );
	if ( ! is_multisite() ) {
		$bridge = \Cybermaps\Discovery\StaticBridge::get_instance();
		$report = $bridge->sync_all();
		cybermaps_native_upgrade_require( ! empty( $report['success'] ) && 'complete' === ( $report['status'] ?? '' ), 'Seed static publication did not complete.' );
	}
	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	WP_Filesystem();
	global $wp_filesystem;
	cybermaps_native_upgrade_require( $wp_filesystem->put_contents( ABSPATH . 'native-upgrade-foreign.txt', 'Foreign file: retain across update.' ), 'Could not seed foreign file.' );
}

/** Capture bounded table schema and real row bytes rather than success flags. */
function cybermaps_native_upgrade_tables(): array {
	global $wpdb;
	$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix . 'cybermaps_' ) . '%' ) );
	cybermaps_native_upgrade_require( count( $tables ) >= 3, 'Cybermaps persisted tables were not created.' );
	$result = array();
	foreach ( $tables as $table ) {
		$columns = $wpdb->get_results( $wpdb->prepare( 'SHOW COLUMNS FROM %i', $table ), ARRAY_A );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i LIMIT 1001', $table ), ARRAY_A );
		cybermaps_native_upgrade_require( '' === $wpdb->last_error && count( $rows ) <= 1000, 'Unbounded or failed fixture table read.' );
		$encoded = array_map( static fn( array $row ): string => (string) wp_json_encode( $row ), $rows );
		sort( $encoded );
		$result[ $table ] = array( 'columns' => $columns, 'row_count' => count( $rows ), 'rows_sha256' => hash( 'sha256', implode( "\n", $encoded ) ) );
	}
	ksort( $result );
	return $result;
}

function cybermaps_native_upgrade_site_snapshot(): array {
	$options = array();
	foreach ( array( 'cybermaps_settings', 'cybermaps_discovery_center', 'cybermaps_robots_manager', 'cybermaps_identity_data', 'native_upgrade_unrelated_option' ) as $name ) {
		$options[ $name ] = get_option( $name );
	}
	$cron = array();
	foreach ( _get_cron_array() as $timestamp => $hooks ) {
		foreach ( $hooks as $hook => $events ) {
			if ( str_starts_with( $hook, 'cybermaps' ) ) {
				$cron[ $timestamp ][ $hook ] = $events;
			}
		}
	}
	$owned = array();
	$store = new \Cybermaps\Discovery\StaticOwnershipStore();
	foreach ( $store->read_flat_hashes() as $path => $recorded_hash ) {
		$file = \Cybermaps\Discovery\StaticBridge::get_instance()->get_file_path( $path );
		cybermaps_native_upgrade_require( is_file( $file ) && hash_file( 'md5', $file ) === $recorded_hash, 'Owned static file does not match its recorded hash.' );
		$owned[ $path ] = array( 'recorded_md5' => $recorded_hash, 'sha256' => hash_file( 'sha256', $file ) );
	}
	ksort( $owned );
	$rewrites = get_option( 'rewrite_rules' );
	cybermaps_native_upgrade_require( is_array( $rewrites ) && isset( $rewrites['^native-upgrade-foreign/?$'] ), 'Foreign rewrite rule is missing.' );
	cybermaps_native_upgrade_require( is_multisite() || count( $owned ) >= 1, 'Single-site ownership fixture is empty.' );
	cybermaps_native_upgrade_require( ! is_multisite() || 'off' === \Cybermaps\Discovery\StaticBridge::get_mode(), 'Multisite enabled shared-root static publication.' );
	$xml = ( new \Cybermaps\Sitemap\Orchestrator() )->generate_xml( 'index', 1 );
	$document = new DOMDocument();
	cybermaps_native_upgrade_require( $document->loadXML( $xml, LIBXML_NONET ) && 'sitemapindex' === $document->documentElement->localName, 'Updated sitemap index is not valid XML.' );
	$children = $document->getElementsByTagName( 'sitemap' )->length;
	cybermaps_native_upgrade_require( $children >= 1, 'Updated sitemap index lost its children.' );
	return array(
		'options' => $options, 'cron' => $cron, 'rewrites' => $rewrites,
		'tables' => cybermaps_native_upgrade_tables(), 'owned_files' => $owned,
		'foreign_file_sha256' => hash_file( 'sha256', ABSPATH . 'native-upgrade-foreign.txt' ),
		'sitemap_index_sha256' => hash( 'sha256', $xml ), 'sitemap_index_children' => $children,
		'static_mode' => \Cybermaps\Discovery\StaticBridge::get_mode(),
	);
}

function cybermaps_native_upgrade_snapshot(): array {
	$sites = array();
	$ids = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 10 ) ) : array( get_current_blog_id() );
	foreach ( $ids as $id ) {
		if ( is_multisite() ) {
			switch_to_blog( (int) $id );
		}
		try {
			\Cybermaps\Core\ConfigurationStore::reset_memo();
			$sites[ (string) $id ] = cybermaps_native_upgrade_site_snapshot();
		} finally {
			if ( is_multisite() ) {
				restore_current_blog();
			}
			\Cybermaps\Core\ConfigurationStore::reset_memo();
		}
	}
	$header = get_file_data( WP_PLUGIN_DIR . '/cybermaps/cybermaps.php', array( 'Version' => 'Version', 'UpdateURI' => 'Update URI' ) );
	return array(
		'version' => $header['Version'], 'loaded_version' => CYBERMAPS_VERSION,
		'basename' => plugin_basename( WP_PLUGIN_DIR . '/cybermaps/cybermaps.php' ),
		'update_uri' => $header['UpdateURI'], 'active' => is_plugin_active( 'cybermaps/cybermaps.php' ),
		'network_active' => is_plugin_active_for_network( 'cybermaps/cybermaps.php' ),
		'multisite' => is_multisite(), 'sites' => $sites,
		'wordpress_version' => get_bloginfo( 'version' ), 'php_version' => PHP_VERSION,
		'controlled_offer_installed' => false !== has_filter( 'pre_set_site_transient_update_plugins', 'cybermaps_native_upgrade_controlled_offer' ),
		'controlled_download_installed' => false !== has_filter( 'upgrader_pre_download', 'cybermaps_native_upgrade_controlled_download' ),
	);
}

$action = $args[0] ?? '';
if ( 'seed' === $action ) {
	$ids = is_multisite() ? get_sites( array( 'fields' => 'ids', 'number' => 10 ) ) : array( get_current_blog_id() );
	foreach ( $ids as $id ) {
		if ( is_multisite() ) {
			switch_to_blog( (int) $id );
		}
		try {
			\Cybermaps\Core\ConfigurationStore::reset_memo();
			cybermaps_native_upgrade_seed();
		} finally {
			if ( is_multisite() ) {
				restore_current_blog();
			}
			\Cybermaps\Core\ConfigurationStore::reset_memo();
		}
	}
	echo wp_json_encode( cybermaps_native_upgrade_snapshot() );
} elseif ( 'snapshot' === $action ) {
	echo wp_json_encode( cybermaps_native_upgrade_snapshot() );
} elseif ( 'offer' === $action ) {
	delete_site_transient( 'update_plugins' );
	wp_update_plugins();
	$updates = get_site_transient( 'update_plugins' );
	$offer = $updates->response['cybermaps/cybermaps.php'] ?? null;
	echo wp_json_encode( array( 'offer' => $offer, 'checked' => $updates->checked['cybermaps/cybermaps.php'] ?? null,
		'controlled_offer_installed' => false !== has_filter( 'pre_set_site_transient_update_plugins', 'cybermaps_native_upgrade_controlled_offer' ),
		'controlled_download_installed' => false !== has_filter( 'upgrader_pre_download', 'cybermaps_native_upgrade_controlled_download' ),
	) );
} else {
	throw new RuntimeException( 'Unknown native upgrade fixture action.' );
}
