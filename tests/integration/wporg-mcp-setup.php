<?php
if ( '0.7.0' !== WP_MCP_VERSION ) throw new RuntimeException( 'Unexpected adapter version.' );
if ( '1' !== get_option( \Cybermaps\Core\MCPMigration::DONE_OPTION ) ) throw new RuntimeException( 'MCP retirement did not complete.' );
$settings = get_option( 'cybermaps_settings', array() );
$settings['enable_discovery_hub'] = '1';
$settings['mcp_mode'] = 'read_only';
update_option( 'cybermaps_settings', $settings );
$id = wp_create_user( 'mcp-reader', wp_generate_password( 32 ), 'mcp-reader@example.com' );
if ( is_wp_error( $id ) ) throw new RuntimeException( 'Cannot create disposable reader.' );
$user = new WP_User( $id );
$user->set_role( 'subscriber' );
$credentials = array();
foreach ( array( 'mcp-reader' => $id, 'admin' => get_user_by( 'login', 'admin' )->ID ) as $name => $user_id ) {
    $password = WP_Application_Passwords::create_new_application_password( $user_id, array( 'name' => 'Disposable MCP test' ) );
    if ( is_wp_error( $password ) ) throw new RuntimeException( 'Cannot create disposable application password.' );
    $credentials[$name] = $password[0];
}
foreach ( array( 'publish' => 'CybermapsMCPPublic', 'private' => 'CybermapsMCPPrivate', 'draft' => 'CybermapsMCPDraft' ) as $status => $title ) {
    wp_insert_post( array( 'post_title' => $title, 'post_content' => 'MCP eligibility fixture.', 'post_status' => $status, 'post_type' => 'post' ) );
}
echo wp_json_encode( $credentials );

$excluded = wp_insert_post( array( 'post_title' => 'CybermapsMCPExcluded', 'post_status' => 'publish', 'post_type' => 'post' ) );
update_post_meta( $excluded, '_cybermaps_exclude_ai', '1' );
