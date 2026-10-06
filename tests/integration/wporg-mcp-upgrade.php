<?php
/** Seed a real 7.5.4 installation before replacing it with the candidate. */
if ( '7.5.4' !== CYBERMAPS_VERSION ) throw new RuntimeException( 'Expected the reviewed 7.5.4 fixture.' );
$settings = get_option( 'cybermaps_settings', array() );
$settings['mcp_mode'] = 'operations';
$settings['enable_discovery_hub'] = '1';
update_option( 'cybermaps_settings', $settings );
\Cybermaps\MCP\OAuth\WpdbOAuthRepository::create_tables();
\Cybermaps\MCP\TaskRepository::create_tables();
( new \Cybermaps\MCP\TaskRepository() )->create( str_repeat( 'a', 64 ), 'audit', 1, 'retired-test-client', array() );
wp_schedule_single_event( time() + 3600, 'cybermaps_mcp_run_task', array( str_repeat( 'a', 64 ) ) );
update_option( 'cybermaps_upgrade_fixture', 'preserved' );

( new \Cybermaps\MCP\OAuth\WpdbOAuthRepository() )->save_token( array(
    'token_hash' => hash( 'sha256', 'retired-release-fixture' ), 'token_type' => 'access',
    'family_id' => str_repeat( 'b', 64 ), 'client_id' => 'retired-test-client', 'user_id' => 1,
    'scopes' => array( 'cybermaps:operations' ), 'audience' => rest_url( 'cybermaps/v1/mcp' ),
    'expires_at' => time() + 3600, 'created_at' => time(),
) );
