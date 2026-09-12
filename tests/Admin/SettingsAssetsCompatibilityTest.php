<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\Settings\SettingsAssets;
use PHPUnit\Framework\TestCase;

final class SettingsAssetsCompatibilityTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$_GET['tab']                                  = 'dashboard';
		$GLOBALS['cybermaps_mock_enqueued_scripts']   = array();
		$GLOBALS['cybermaps_mock_enqueued_styles']    = array();
		$GLOBALS['cybermaps_mock_registered_scripts'] = array();
	}

	protected function tearDown(): void {
		unset( $_GET['tab'], $_GET['view'], $GLOBALS['cybermaps_mock_registered_scripts'] );
		parent::tearDown();
	}

	public function test_wordpress_70_does_not_receive_an_unregistered_tooltip_dependency(): void {
		SettingsAssets::enqueue_scripts( 'toplevel_page_cybermaps-settings' );

		$dependencies = $GLOBALS['cybermaps_mock_enqueued_scripts']['cybermaps-command-center']['deps'];
		$this->assertNotContains( 'wp-tooltip', $dependencies );
		$this->assertContains( 'wp-a11y', $dependencies );
	}

	public function test_wordpress_71_keeps_the_registered_native_tooltip_dependency(): void {
		$GLOBALS['cybermaps_mock_registered_scripts']['wp-tooltip'] = true;

		SettingsAssets::enqueue_scripts( 'toplevel_page_cybermaps-settings' );

		$dependencies = $GLOBALS['cybermaps_mock_enqueued_scripts']['cybermaps-command-center']['deps'];
		$this->assertContains( 'wp-tooltip', $dependencies );
	}

	public function test_setup_assets_refresh_when_same_version_build_contents_change(): void {
		$_GET['view'] = 'setup';
		SettingsAssets::enqueue_scripts( 'toplevel_page_cybermaps-settings' );

		$script = $GLOBALS['cybermaps_mock_enqueued_scripts']['cybermaps-setup-wizard'];
		$style  = $GLOBALS['cybermaps_mock_enqueued_styles']['cybermaps-setup-wizard'];
		$this->assertSame( array( 'wp-element', 'wp-i18n' ), $script['deps'] );
		$this->assertSame( CYBERMAPS_VERSION . '-' . substr( hash_file( 'sha256', CYBERMAPS_PLUGIN_DIR . 'assets/js/setup-wizard.js' ), 0, 12 ), $script['version'] );
		$this->assertSame( CYBERMAPS_VERSION . '-' . substr( hash_file( 'sha256', CYBERMAPS_PLUGIN_DIR . 'assets/css/setup-wizard.css' ), 0, 12 ), $style['version'] );
		$this->assertNotSame( CYBERMAPS_VERSION, $script['version'] );
		$this->assertNotSame( CYBERMAPS_VERSION, $style['version'] );
	}
}
