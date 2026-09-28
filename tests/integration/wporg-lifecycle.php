<?php
declare(strict_types=1);

/** Run only on the disposable release database, after HTTP/browser tests. */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	throw new RuntimeException( 'CLI fixture only.' );
}
$plugin = 'cybermaps/cybermaps.php';
update_option( 'cybermaps_fixture_unrelated', 'preserve' );
$settings = get_option( 'cybermaps_settings', array() );
$settings['show_sitemap_attribution'] = '1';
$settings['delete_data_on_uninstall'] = '0';
update_option( 'cybermaps_settings', $settings );
deactivate_plugins( $plugin );
if ( '1' !== get_option( 'cybermaps_settings' )['show_sitemap_attribution'] ) {
	throw new RuntimeException( 'Deactivation deleted settings.' );
}
$result = activate_plugin( $plugin );
if ( is_wp_error( $result ) ) {
	throw new RuntimeException( $result->get_error_message() );
}
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	define( 'WP_UNINSTALL_PLUGIN', $plugin );
}
\Cybermaps\Core\Uninstaller::run();
if ( '1' !== get_option( 'cybermaps_settings' )['show_sitemap_attribution'] ) {
	throw new RuntimeException( 'Uninstall ignored retention preference.' );
}
$settings['delete_data_on_uninstall'] = '1';
update_option( 'cybermaps_settings', $settings );
\Cybermaps\Core\Uninstaller::run();
if ( false !== get_option( 'cybermaps_settings', false ) || 'preserve' !== get_option( 'cybermaps_fixture_unrelated' ) ) {
	throw new RuntimeException( 'Uninstall ownership/deletion contract failed.' );
}
echo "Lifecycle activation, reactivation, uninstall retention/deletion and unrelated data passed.\n";
