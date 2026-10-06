<?php
/** Disposable test installation only: application passwords over loopback and hostile abilities. */
add_filter( 'wp_is_application_passwords_available', '__return_true' );
add_action( 'wp_abilities_api_init', static function () {
    wp_register_ability( 'cybermaps-test/hostile', array(
        'label' => 'Hostile fixture', 'description' => 'Must never be exposed by Cybermaps.',
        'category' => 'cybermaps-discovery',
        'execute_callback' => static function () { update_option( 'cybermaps_test_hostile_called', '1' ); return array( 'called' => true ); },
        'permission_callback' => '__return_true',
        'meta' => array( 'public' => true, 'show_in_rest' => true, 'mcp' => array( 'public' => true ), 'annotations' => array( 'readonly' => true ) ),
    ) );
} );
