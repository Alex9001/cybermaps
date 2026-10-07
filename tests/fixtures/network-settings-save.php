<?php
declare(strict_types=1);
/** Isolated actual-handler fixture; avoids process-global WordPress mock overrides. */
define( 'ABSPATH', __DIR__ . '/' );
$mode = $argv[1] ?? 'failure';
$stored = array( 'enable_master_index' => 'unchanged' === $mode ? '1' : '0' );
$calls = array();
function current_user_can( $capability ) { return 'manage_network_options' === $capability; }
function sanitize_text_field( $value ) { return $value; }
function wp_unslash( $value ) { return $value; }
function sanitize_key( $value ) { return $value; }
function check_admin_referer( $action ) { $GLOBALS['calls'][] = 'nonce:' . $action; return true; }
function update_site_option( $name, $value ) {
	$GLOBALS['calls'][] = 'write';
	if ( 'success' !== $GLOBALS['mode'] ) { return false; }
	$GLOBALS['stored'] = $value;
	return true;
}
function get_site_option( $name, $default = false ) { $GLOBALS['calls'][] = 'read'; return $GLOBALS['stored']; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function network_admin_url( $path ) { return 'https://example.test/wp-admin/network/' . $path; }
function wp_safe_redirect( $url ) { echo json_encode( array( 'stored' => $GLOBALS['stored'], 'calls' => $GLOBALS['calls'], 'redirect' => $url ) ), "\n"; }
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array( 'cybermaps_network_settings' => array( 'enable_master_index' => '1' ) );
require dirname( __DIR__, 2 ) . '/src/Admin/NetworkSettings.php';
( new Cybermaps\Admin\NetworkSettings() )->save_network_settings();
