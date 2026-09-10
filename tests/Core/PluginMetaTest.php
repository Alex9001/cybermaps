<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Core;

use Cybermaps\Core\Plugin;
use Cybermaps\Core\Container;
use PHPUnit\Framework\TestCase;

final class PluginMetaTest extends TestCase {
	public function test_editor_meta_sanitizers_accept_only_supported_values(): void {
		$this->assertSame( '1', Plugin::sanitize_binary_meta( '1' ) );
		$this->assertSame( '0', Plugin::sanitize_binary_meta( 'true' ) );

		$this->assertSame( 'informational', Plugin::sanitize_intent_meta( 'Informational' ) );
		$this->assertSame( 'transactional', Plugin::sanitize_intent_meta( 'transactional' ) );
		$this->assertSame( '', Plugin::sanitize_intent_meta( 'purchase-ready' ) );

		$this->assertSame( 0.0, Plugin::sanitize_priority_meta( -1 ) );
		$this->assertSame( 0.7, Plugin::sanitize_priority_meta( 0.66 ) );
		$this->assertSame( 1.0, Plugin::sanitize_priority_meta( 9 ) );

		$this->assertSame( 'weekly', Plugin::sanitize_changefreq_meta( 'Weekly' ) );
		$this->assertSame( '', Plugin::sanitize_changefreq_meta( 'sometimes' ) );
	}

	public function test_admin_frame_policy_is_appended_without_replacing_an_existing_csp(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Core/Plugin.php' );

		$this->assertStringContainsString(
			'header( "Content-Security-Policy: frame-ancestors \'self\'", false );',
			$source
		);
	}

	public function test_admin_only_services_are_not_resolved_on_public_requests(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Core/Plugin.php' );
		$gate   = strpos( $source, 'if ( \\is_admin() ) {' );

		$this->assertNotFalse( $gate );
		foreach (
			array(
				"'auditor'",
				"'discovery_auditor'",
				"'sitemap_status'",
				"'ai_discovery_status'",
				"'discovery_analytics'",
			) as $service
		) {
			$this->assertNotFalse( strpos( $source, '$container->get( ' . $service, $gate ) );
		}

		$this->assertStringContainsString(
			'if ( is_multisite() && \\is_admin() ) {',
			$source
		);
	}

	public function test_settings_redirect_ignores_a_malformed_tab_value(): void {
		$_POST['cybermaps_active_tab'] = array( 'advanced' );

		try {
			$this->assertSame(
				'https://example.test/wp-admin/admin.php?page=cybermaps-settings',
				Plugin::preserve_settings_tab_query(
					'https://example.test/wp-admin/admin.php?page=cybermaps-settings'
				)
			);
		} finally {
			unset( $_POST['cybermaps_active_tab'] );
		}
	}

	public function test_plugin_boot_fails_closed_for_a_malformed_general_settings_option(): void {
		$original_hooks = $GLOBALS['wp_hooks'];
		$GLOBALS['wp_hooks'] = array();
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = new \stdClass();

		set_error_handler(
			static function ( int $severity, string $message ): bool {
				throw new \ErrorException( $message, 0, $severity );
			}
		);
		try {
			( new Plugin( new Container() ) )->run();
		} finally {
			restore_error_handler();
			$GLOBALS['wp_hooks'] = $original_hooks;
		}

		$this->addToAssertionCount( 1 );
	}

	public function test_editor_contract_registers_both_publication_exclusions(): void {
		$GLOBALS['cybermaps_mock_registered_post_meta'] = array();
		$GLOBALS['cybermaps_mock_post_type_supports']   = array(
			'portfolio' => array( 'editor' => true ),
		);
		$portfolio = (object) array(
			'name'         => 'portfolio',
			'public'       => true,
			'show_in_rest' => true,
		);
		$GLOBALS['cybermaps_mock_post_type_objects']['portfolio'] = $portfolio;

		Plugin::register_publication_meta( 'portfolio', $portfolio );

		$registered = $GLOBALS['cybermaps_mock_registered_post_meta']['portfolio'];
		$this->assertSame(
			array(
				'_cybermaps_exclude_sitemap',
				'_cybermaps_exclude_ai',
				'_cybermaps_intent_override',
				'_cybermaps_sitemap_priority',
				'_cybermaps_sitemap_changefreq',
			),
			array_keys( $registered )
		);
		$this->assertTrue( $GLOBALS['cybermaps_mock_post_type_supports']['portfolio']['custom-fields'] );
		$this->assertSame( 'number', $registered['_cybermaps_sitemap_priority']['type'] );
		$this->assertTrue( $registered['_cybermaps_exclude_ai']['show_in_rest'] );
	}

	public function test_editor_contract_ignores_non_public_and_attachment_types(): void {
		$GLOBALS['cybermaps_mock_registered_post_meta'] = array();
		$private = (object) array(
			'name'         => 'private_note',
			'public'       => false,
			'show_in_rest' => true,
		);
		$attachment = (object) array(
			'name'         => 'attachment',
			'public'       => true,
			'show_in_rest' => true,
		);

		Plugin::register_publication_meta( 'private_note', $private );
		Plugin::register_publication_meta( 'attachment', $attachment );

		$this->assertSame( array(), $GLOBALS['cybermaps_mock_registered_post_meta'] );
	}

	public function test_editor_script_is_scoped_and_receives_the_publication_inventory(): void {
		$GLOBALS['cybermaps_mock_post_types'] = array( 'post', 'portfolio', 'attachment' );
		$GLOBALS['cybermaps_mock_post_type_objects']['portfolio'] = (object) array(
			'name'   => 'portfolio',
			'public' => true,
		);
		$GLOBALS['cybermaps_mock_current_screen'] = (object) array( 'post_type' => 'portfolio' );
		$GLOBALS['cybermaps_mock_enqueued_scripts'] = array();
		$GLOBALS['cybermaps_mock_localized_scripts'] = array();

		Plugin::enqueue_editor_assets();

		$this->assertArrayHasKey( 'cybermaps-sitemap-exclusion', $GLOBALS['cybermaps_mock_enqueued_scripts'] );
		$this->assertSame(
			array( 'post', 'portfolio' ),
			$GLOBALS['cybermaps_mock_localized_scripts']['cybermaps-sitemap-exclusion']['cybermapsEditor']['postTypes']
		);
		$this->assertFalse(
			$GLOBALS['cybermaps_mock_localized_scripts']['cybermaps-sitemap-exclusion']['cybermapsEditor']['legacyComponentSizing']
		);
	}

	public function test_editor_script_enables_legacy_component_sizing_only_before_wordpress_71(): void {
		$GLOBALS['cybermaps_mock_post_types']             = array( 'post' );
		$GLOBALS['cybermaps_mock_post_type_objects']['post'] = (object) array(
			'name'   => 'post',
			'public' => true,
		);
		$GLOBALS['cybermaps_mock_current_screen']         = (object) array( 'post_type' => 'post' );
		$GLOBALS['cybermaps_mock_enqueued_scripts']       = array();
		$GLOBALS['cybermaps_mock_localized_scripts']      = array();
		$GLOBALS['cybermaps_mock_wp_version']             = '7.0';

		try {
			Plugin::enqueue_editor_assets();
			$this->assertTrue(
				$GLOBALS['cybermaps_mock_localized_scripts']['cybermaps-sitemap-exclusion']['cybermapsEditor']['legacyComponentSizing']
			);
		} finally {
			unset( $GLOBALS['cybermaps_mock_wp_version'] );
		}
	}

	public function test_save_pipeline_skips_published_non_public_post_types(): void {
		$GLOBALS['cybermaps_mock_options'] = array(
			'cybermaps_settings' => array( 'media_discovery_intensity' => 'standard' ),
		);
		$GLOBALS['cybermaps_mock_post_meta'] = array();
		$GLOBALS['cybermaps_mock_post_type_objects']['internal_note'] = (object) array(
			'name'   => 'internal_note',
			'public' => false,
		);
		$post = (object) array(
			'ID'          => 44,
			'post_type'   => 'internal_note',
			'post_status' => 'publish',
		);

		( new Plugin( new Container() ) )->on_save_post( $post->ID, $post );

		$this->assertArrayNotHasKey( $post->ID, $GLOBALS['cybermaps_mock_post_meta'] );
	}

	public function test_save_pipeline_clears_stale_media_when_discovery_is_off(): void {
		$GLOBALS['cybermaps_mock_options'] = array(
			'cybermaps_settings' => array( 'media_discovery_intensity' => 'none' ),
		);
		$GLOBALS['cybermaps_mock_post_meta'] = array(
			45 => array(
				'_cybermaps_media_audit' => array(
					array( 'type' => 'video', 'url' => 'https://example.com/stale.mp4' ),
				),
			),
		);
		$GLOBALS['cybermaps_mock_post_type_objects']['post'] = (object) array(
			'name'   => 'post',
			'public' => true,
		);
		$post = (object) array(
			'ID'          => 45,
			'post_type'   => 'post',
			'post_status' => 'publish',
		);

		( new Plugin( new Container() ) )->on_save_post( $post->ID, $post );

		$this->assertArrayNotHasKey(
			'_cybermaps_media_audit',
			$GLOBALS['cybermaps_mock_post_meta'][45]
		);
	}

	/**
	 * @dataProvider malformed_media_modes
	 */
	public function test_save_pipeline_fails_closed_for_malformed_media_modes( mixed $mode ): void {
		$GLOBALS['cybermaps_mock_options'] = array(
			'cybermaps_settings' => array( 'media_discovery_intensity' => $mode ),
		);
		$GLOBALS['cybermaps_mock_post_meta'] = array(
			46 => array(
				'_cybermaps_media_audit'            => array(
					array( 'type' => 'video', 'url' => 'https://example.com/stale.mp4' ),
				),
				'_cybermaps_media_audit_mode'       => 'advanced',
				'_cybermaps_media_audit_generation' => 1,
			),
		);
		$GLOBALS['cybermaps_mock_post_type_objects']['post'] = (object) array(
			'name'   => 'post',
			'public' => true,
		);
		$post = (object) array(
			'ID'           => 46,
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_title'   => 'Malformed setting',
			'post_content' => '<img src="https://example.com/should-not-scan.jpg">',
		);

		( new Plugin( new Container() ) )->on_save_post( $post->ID, $post );

		$this->assertArrayNotHasKey(
			'_cybermaps_media_audit',
			$GLOBALS['cybermaps_mock_post_meta'][46]
		);
		$this->assertArrayNotHasKey(
			'_cybermaps_media_audit_generation',
			$GLOBALS['cybermaps_mock_post_meta'][46]
		);
	}

	public static function malformed_media_modes(): array {
		return array(
			'array'   => array( array( 'advanced' ) ),
			'object'  => array( (object) array( 'mode' => 'advanced' ) ),
			'unknown' => array( 'turbo' ),
		);
	}
}
