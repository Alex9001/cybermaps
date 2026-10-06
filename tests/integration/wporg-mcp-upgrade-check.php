<?php
declare(strict_types=1);
if ( ! \Cybermaps\Core\MCPMigration::is_complete() || array_key_exists( 'mcp_mode', get_option( 'cybermaps_settings' ) ) || array_key_exists( 'agent_registration_mode', get_option( 'cybermaps_settings' ) ) ) throw new RuntimeException( 'Legacy MCP was not safely retired.' );
if ( 'preserved' !== get_option( 'cybermaps_upgrade_fixture' ) ) throw new RuntimeException( 'Unrelated upgrade data was lost.' );
global $wpdb;
foreach ( array( 'oauth_clients', 'oauth_codes', 'oauth_tokens', 'oauth_grants', 'oauth_devices', 'tasks' ) as $suffix ) {
    $table = $wpdb->prefix . 'cybermaps_mcp_' . $suffix;
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) throw new RuntimeException( 'Retired table remains: ' . $suffix );
}
foreach ( _get_cron_array() as $events ) {
    if ( isset( $events['cybermaps_mcp_run_task'] ) || isset( $events['cybermaps_mcp_cleanup_tasks'] ) ) throw new RuntimeException( 'Retired MCP job remains.' );
}
echo "7.5.4 MCP operations migration: deleted retired configuration, credentials, tables and jobs, preserved unrelated settings.\n";
