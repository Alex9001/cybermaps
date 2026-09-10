<?php
declare(strict_types=1);
namespace Cybermaps\Admin\Settings\Tabs;

use Cybermaps\Admin\Settings\SettingsTab;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HTML Sitemap tab — shortcode builder and preview.
 */
class Shortcode implements SettingsTab {

	public function slug(): string {
		return 'shortcode';
	}

	public function label(): string {
		return __( 'HTML Sitemap', 'cybermaps' );
	}
	public function settings_page(): string {
		return 'cybermaps-shortcode';
	}


	public function register_settings(): void {}

	public function render(): void {
		\Cybermaps\Admin\ShortcodeBuilder::render();
	}
}
