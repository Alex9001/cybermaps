<?php
declare(strict_types=1);
namespace Cybermaps\Admin\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Each tab in the Settings page implements this contract.
 */
interface SettingsTab {

	/**
	 * Unique slug for the tab, used in CSS IDs and routing.
	 */
	public function slug(): string;

	/**
	 * Human-readable tab label.
	 */
	public function label(): string;

	/**
	 * WordPress Settings API page slug for this tab (e.g. cybermaps-sitemaps).
	 */
	public function settings_page(): string;

	/**
	 * Register this tab's settings fields with the Settings API.
	 */
	public function register_settings(): void;

	/**
	 * Render the tab's HTML content.
	 */
	public function render(): void;
}
