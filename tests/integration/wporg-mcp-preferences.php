<?php
declare(strict_types=1);
/** Exercise the 8.0 preference conversion on real WordPress option storage. */
$settings = get_option( 'cybermaps_settings', array() );
$settings['mcp_mode'] = 'read_only';
$settings['agent_registration_mode'] = 'off';
unset( $settings['enable_mcp_adapter'] );
update_option( 'cybermaps_settings', $settings );
update_option( 'cybermaps_mcp_retired', '1', false );
update_option( 'cybermaps_mcp_migration_notice', '1', false );
if ( ! \Cybermaps\Core\MCPMigration::run() ) throw new RuntimeException( '8.0 preference cleanup failed.' );
$cleaned = get_option( 'cybermaps_settings' );
if ( array_key_exists( 'mcp_mode', $cleaned ) || array_key_exists( 'agent_registration_mode', $cleaned ) ) throw new RuntimeException( 'Retired configuration remains.' );
if ( '1' !== ( $cleaned['enable_mcp_adapter'] ?? '' ) ) throw new RuntimeException( 'Explicit 8.0 adapter consent was lost.' );
if ( false !== get_option( 'cybermaps_mcp_migration_notice', false ) ) throw new RuntimeException( 'Obsolete migration notice remains.' );
$cleaned['enable_mcp_adapter'] = '0';
update_option( 'cybermaps_settings', $cleaned );
echo "8.0 preference cleanup: retired keys and notice deleted; explicit adapter consent converted.\n";
