<?php
/**
 * Plugin Name: CYBERMAPS: XML Sitemaps & llms.txt
 * Plugin URI: https://cybermaps.dev
 * Description: XML sitemaps, literal Markdown and llms.txt publishing, diagnostics, analytics, and reports for WordPress.
 * Version: 7.4.2
 * Requires at least: 7.1
 * Requires PHP: 8.2
 * Author: Aleksandr Oreshkin
 * Author URI: https://profiles.wordpress.org/oreshkin/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: cybermaps
 * Domain Path: /languages
 *
 * Copyright (C) 2026 Aleksandr Oreshkin
 *
 * Cybermaps is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the
 * Free Software Foundation, either version 2 of the License, or (at your
 * option) any later version.
 *
 * Cybermaps is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with Cybermaps. If not, see https://www.gnu.org/licenses/gpl-2.0.html.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants
define( 'CYBERMAPS_VERSION', '7.4.2' );
define( 'CYBERMAPS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CYBERMAPS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CYBERMAPS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Register Autoloader
require_once CYBERMAPS_PLUGIN_DIR . 'src/Autoloader.php';
\Cybermaps\Autoloader::register();

// Initialize classes
function cybermaps_init(): void {
	// Cybermaps owns sitemap publication only after the plugin has initialized.
	add_filter( 'wp_sitemaps_enabled', '__return_false' );

	$container = new \Cybermaps\Core\Container();
	$plugin    = new \Cybermaps\Core\Plugin( $container );
	$plugin->run();
}
add_action( 'plugins_loaded', 'cybermaps_init' );

register_activation_hook( __FILE__, array( \Cybermaps\Core\Lifecycle::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Cybermaps\Core\Lifecycle::class, 'deactivate' ) );

// Provision only the newly created site when Core is active across its network.
add_action( 'wp_initialize_site', array( \Cybermaps\Core\Lifecycle::class, 'initialize_site' ), 10, 2 );
add_filter( 'wpmu_drop_tables', array( \Cybermaps\Core\Lifecycle::class, 'include_site_tables_for_deletion' ), 10, 2 );
add_action( 'wp_uninitialize_site', array( \Cybermaps\Core\Lifecycle::class, 'cleanup_uninitialized_site' ), 20, 1 );
