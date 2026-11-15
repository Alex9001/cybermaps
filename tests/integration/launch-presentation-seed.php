<?php
declare(strict_types=1);

// Disposable, local screenshot fixture; never included in the plugin package.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI || '1' !== getenv( 'CYBERMAPS_PRESENTATION_FIXTURE' ) ) {
	throw new RuntimeException( 'This fixture requires a disposable WP-CLI installation.' );
}
if ( 'Willow Studio — Demo' !== get_option( 'blogname' ) ) {
	throw new RuntimeException( 'Unexpected installation; refusing to seed content.' );
}
wp_set_current_user( 1 );
update_option( 'blogdescription', 'A fictional design studio used to demonstrate CYBERMAPS.' );
update_option( 'show_avatars', 0 );
update_option( 'default_comment_status', 'closed' );
update_user_meta( 1, 'show_welcome_panel', 0 );
wp_update_user( array( 'ID' => 1, 'display_name' => 'Demo Editor', 'admin_color' => 'fresh' ) );

$pages = array();
foreach ( array( 'Welcome', 'About our studio', 'Services', 'Contact' ) as $title ) {
	$id = wp_insert_post(
		array(
			'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title,
			'post_content' => '<h2>Made for people</h2><p>Willow Studio is a fictional design studio. This demonstration content shows how a small business can organize its website, describe services and publish useful guides.</p><p>Every screen shows actual CYBERMAPS output from this local demonstration site.</p>',
		), true
	);
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( $id->get_error_message() );
	}
	$pages[ $title ] = $id;
}
foreach ( array( 'Website design', 'Brand identity', 'Content planning' ) as $title ) {
	wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_parent' => $pages['Services'], 'post_content' => '<h2>A considered approach</h2><p>This is a fictional service page for the CYBERMAPS demonstration site. We start with a clear brief, review the content together and prepare a practical plan.</p>' ) );
}
foreach ( array( 'Planning a useful website', 'Writing clear page titles', 'Organizing a services page', 'Making navigation easier', 'Choosing meaningful links', 'Reviewing older content', 'Preparing a content brief', 'Explaining your business', 'Keeping an accessible website', 'Publishing a helpful guide', 'Understanding your sitemap', 'Checking content delivery' ) as $index => $title ) {
	wp_insert_post( array( 'post_type' => 'post', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => '<h2>Start with the reader</h2><p>This fictional guide is part of a local CYBERMAPS demonstration. It illustrates an ordinary publishing workflow, from planning content to checking delivery. Short articles deliberately produce real content report findings.</p><p><a href="' . esc_url( get_permalink( $pages['Services'] ) ) . '">Explore our services</a> or <a href="' . esc_url( get_permalink( $pages['Contact'] ) ) . '">contact the studio</a>.</p>', 'post_excerpt' => 0 === $index % 3 ? '' : 'Practical ideas from the fictional Willow Studio demonstration site.' ) );
}
update_option( 'show_on_front', 'page' );
update_option( 'page_on_front', $pages['Welcome'] );
$menu = wp_create_nav_menu( 'Demo navigation' );
if ( ! is_wp_error( $menu ) ) {
	foreach ( $pages as $id ) {
		wp_update_nav_menu_item( $menu, 0, array( 'menu-item-object' => 'page', 'menu-item-object-id' => $id, 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish' ) );
	}
}
$settings = Cybermaps\Admin\Settings\Sanitizers\SettingsSanitizer::sanitize( array(
	'enable_discovery_hub' => '1', 'enable_llms_full' => '1', 'enable_llms_tldr' => '1',
	'enable_rag_chunks' => '1', 'enable_mcp_adapter' => '0', 'enable_analytics' => '1',
	'enable_indexnow' => '0', 'enable_websub' => '0', 'static_engine_mode' => 'off',
	'llms_title_override' => 'Willow Studio',
	'llms_mission_statement' => 'A fictional design studio demonstrating clear content and machine-readable publishing.',
) );
update_option( 'cybermaps_settings', $settings );
$identity = ( new Cybermaps\Admin\IdentityHub() )->sanitize_import( array(
	'type' => 'Organization', 'name' => 'Willow Studio',
	'description' => 'A fictional design studio used in a local CYBERMAPS demonstration.',
	'email' => 'hello@example.com',
	'catalogs' => array( array( 'mode' => 'auto', 'name' => 'Studio services', 'item_type' => 'Service', 'parent_id' => $pages['Services'], 'items' => array() ) ),
) );
update_option( 'cybermaps_identity_data', $identity );
flush_rewrite_rules( false );
$service = new Cybermaps\Audit\ContentAuditService();
$baseline_run_id = $service->run();
$run_id = $service->run();
unset( $settings['api_secret'] );
echo wp_json_encode( array( 'fixture' => 'fictional-willow-studio', 'version' => CYBERMAPS_VERSION, 'pages' => $pages, 'report_run' => $run_id, 'baseline_run' => $baseline_run_id, 'settings' => $settings, 'admin_roles' => get_userdata( 1 )->roles ) ) . "\n";
