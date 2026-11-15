<?php
declare(strict_types=1);

/** Controlled phase only. This file is absent from the real-updater fixture. */
if ( ! defined( 'CYBERMAPS_NATIVE_UPGRADE_FIXTURE' ) || true !== CYBERMAPS_NATIVE_UPGRADE_FIXTURE || 'controlled' !== CYBERMAPS_NATIVE_UPGRADE_PHASE ) {
	throw new RuntimeException( 'Controlled native upgrade fixture required.' );
}

function cybermaps_native_upgrade_controlled_offer( $updates ) {
	if ( ! is_object( $updates ) ) {
		$updates = new stdClass();
	}
	$plugin = 'cybermaps/cybermaps.php';
	$header = get_file_data( WP_PLUGIN_DIR . '/' . $plugin, array( 'Version' => 'Version' ) );
	if ( version_compare( $header['Version'], CYBERMAPS_NATIVE_UPGRADE_VERSION, '>=' ) ) {
		return $updates;
	}
	$updates->checked[ $plugin ] = $header['Version'];
	$updates->response[ $plugin ] = (object) array(
		'id' => 'w.org/plugins/cybermaps', 'slug' => 'cybermaps', 'plugin' => $plugin,
		'new_version' => CYBERMAPS_NATIVE_UPGRADE_VERSION, 'url' => 'https://wordpress.org/plugins/cybermaps/',
		'package' => 'https://downloads.wordpress.org/plugin/cybermaps.' . CYBERMAPS_NATIVE_UPGRADE_VERSION . '.zip',
		'requires' => '7.1', 'requires_php' => '8.2',
	);
	unset( $updates->no_update[ $plugin ] );
	return $updates;
}
add_filter( 'pre_set_site_transient_update_plugins', 'cybermaps_native_upgrade_controlled_offer' );

function cybermaps_native_upgrade_controlled_download( $reply, $package ) {
	$expected = 'https://downloads.wordpress.org/plugin/cybermaps.' . CYBERMAPS_NATIVE_UPGRADE_VERSION . '.zip';
	if ( $package !== $expected ) {
		return $reply;
	}
	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	WP_Filesystem();
	global $wp_filesystem;
	$temporary = wp_tempnam( 'cybermaps-native-upgrade.zip' );
	if ( ! is_string( $temporary ) || ! $wp_filesystem->copy( '/candidate/cybermaps.zip', $temporary, true ) ) {
		return new WP_Error( 'native_upgrade_fixture_copy', 'Could not stage the frozen candidate.' );
	}
	return $temporary;
}
add_filter( 'upgrader_pre_download', 'cybermaps_native_upgrade_controlled_download', 10, 2 );
