<?php
declare(strict_types=1);
namespace Cybermaps\Admin\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SettingsAssets {
	public static function enqueue_scripts( $hook ) {
		if ( 'toplevel_page_cybermaps-settings' !== $hook ) {
			return;
		}

		self::enqueue_command_center_style();
		if ( 'setup' === self::requested_workspace() ) {
			self::enqueue_setup_assets();
			return;
		}

		self::enqueue_tab_assets( self::active_tab() );
	}

	private static function requested_workspace(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin workspace selection.
		return isset( $_GET['view'] ) && is_scalar( $_GET['view'] )
			? sanitize_key( wp_unslash( (string) $_GET['view'] ) )
			: '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	private static function enqueue_command_center_style(): void {
		wp_enqueue_style( 'cybermaps-command-center', CYBERMAPS_PLUGIN_URL . 'assets/css/admin-command-center.css', array(), CYBERMAPS_VERSION );
		wp_style_add_data( 'cybermaps-command-center', 'rtl', true );
	}

	private static function enqueue_setup_assets(): void {
		wp_enqueue_media();
		wp_enqueue_style( 'cybermaps-setup-wizard', CYBERMAPS_PLUGIN_URL . 'assets/css/setup-wizard.css', array( 'cybermaps-command-center' ), CYBERMAPS_VERSION );
		wp_enqueue_script( 'cybermaps-setup-wizard', CYBERMAPS_PLUGIN_URL . 'assets/js/setup-wizard.js', array( 'wp-element', 'wp-components', 'wp-i18n', 'wp-api-fetch' ), CYBERMAPS_VERSION, true );
		wp_localize_script(
			'cybermaps-setup-wizard',
			'cybermapsSetupWizard',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( \Cybermaps\Admin\SetupWizard\SetupWizardController::nonce_action() ),
				'strings' => array(
					'connectionError' => __( 'Cybermaps could not contact Guided Setup. Try again.', 'cybermaps' ),
					'leaveWarning'    => __( 'You have unfinished Guided Setup answers. Leave without applying them?', 'cybermaps' ),
				),
			)
		);
		wp_set_script_translations( 'cybermaps-setup-wizard', 'cybermaps', CYBERMAPS_PLUGIN_DIR . 'languages' );
	}

	private static function active_tab(): string {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin workspace selection.
		$active_tab = isset( $_GET['tab'] ) && is_scalar( $_GET['tab'] )
			? sanitize_key( wp_unslash( (string) $_GET['tab'] ) )
			: 'dashboard';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $active_tab, array( 'dashboard', 'sitemaps', 'shortcode', 'ai', 'schema', 'review', 'advanced' ), true ) ) {
			return 'dashboard';
		}
		return $active_tab;
	}

	private static function enqueue_tab_assets( string $active_tab ): void {
		if ( 'review' === $active_tab ) {
			wp_enqueue_media();
		}

		$command_center_dependencies = array( 'wp-element', 'wp-components', 'wp-api-fetch', 'wp-i18n', 'wp-a11y', 'jquery' );
		if ( function_exists( 'wp_get_toggletip' ) && wp_script_is( 'wp-tooltip', 'registered' ) ) {
			$command_center_dependencies[] = 'wp-tooltip';
		}
		wp_enqueue_script( 'cybermaps-command-center', CYBERMAPS_PLUGIN_URL . 'assets/js/admin-command-center.js', $command_center_dependencies, CYBERMAPS_VERSION, true );
		wp_set_script_translations( 'cybermaps-command-center', 'cybermaps', CYBERMAPS_PLUGIN_DIR . 'languages' );

		if ( 'advanced' === $active_tab ) {
			self::localize_exchange_data();
			self::enqueue_edge_optimization_assets();
		}

		if ( 'shortcode' === $active_tab ) {
			self::enqueue_shortcode_assets();
		}

		if ( 'sitemaps' !== $active_tab ) {
			return;
		}

		wp_enqueue_script( 'cybermaps-discovery-matrix', CYBERMAPS_PLUGIN_URL . 'assets/js/discovery-matrix-svg.js', array(), CYBERMAPS_VERSION, true );
		wp_enqueue_script( 'cybermaps-media-auditor', CYBERMAPS_PLUGIN_URL . 'assets/js/media-auditor.js', array( 'jquery' ), CYBERMAPS_VERSION, true );
		self::localize_media_auditor();
	}

	private static function enqueue_edge_optimization_assets(): void {
		wp_enqueue_style( 'cybermaps-system-status', CYBERMAPS_PLUGIN_URL . 'assets/css/system-status.css', array( 'cybermaps-command-center' ), CYBERMAPS_VERSION );
		wp_enqueue_script( 'cybermaps-edge-optimization', CYBERMAPS_PLUGIN_URL . 'assets/js/edge-optimization.js', array(), CYBERMAPS_VERSION, true );
		wp_localize_script(
			'cybermaps-edge-optimization',
			'cybermapsEdgeOptimization',
			array(
				'ajaxUrl'                => admin_url( 'admin-ajax.php' ),
				'nonce'                  => wp_create_nonce( \Cybermaps\Admin\EdgeOptimizationController::nonce_action() ),
				'oauthPollAction'        => 'cybermaps_edge_oauth_poll',
				'cloudflareDetected'     => \Cybermaps\Admin\CloudflareRuleManager::request_is_cloudflare(),
				'cloudflareDetectionUrl' => \Cybermaps\Core\URLManager::get_home_url( '/' ),
				'cloudflareHost'         => \Cybermaps\Admin\CloudflareRuleManager::public_host(),
				'publicResources'        => \Cybermaps\Admin\CloudflareRuleManager::browser_verification_resources(),
				'pollInterval'           => 2000,
				'pollSchedule'           => array( 2000, 3000, 5000, 8000, 10000 ),
				'strings'                => array(
					'working'               => __( 'Working…', 'cybermaps' ),
					'waiting'               => __( 'Cloudflare opened in a new tab. Select the account, review the permissions, and authorize Cybermaps.', 'cybermaps' ),
					'pending'               => __( 'Waiting for Cloudflare authorization…', 'cybermaps' ),
					'complete'              => __( 'Operation complete. Open Debugging to see the latest evidence.', 'cybermaps' ),
					'failed'                => __( 'The optimization request did not complete.', 'cybermaps' ),
					'verifying'             => __( 'Cloudflare rules were updated. Verifying each public resource from this browser…', 'cybermaps' ),
					'network'               => __( 'The browser could not reach this resource.', 'cybermaps' ),
					'cloudflareDetected'    => __( 'Cloudflare proxy traffic was detected. Cloudflare rule tools are available.', 'cybermaps' ),
					'cloudflareConfirmed'   => __( 'Manual Cloudflare confirmation accepted for this page. Authorization and public verification are still required.', 'cybermaps' ),
					'cloudflareMissing'     => __( 'Cloudflare proxy traffic was not detected. These controls remain disabled unless you explicitly confirm that this hostname is orange-cloud proxied.', 'cybermaps' ),
					'cloudflareChecking'    => __( 'Checking the configured public hostname for Cloudflare proxy traffic…', 'cybermaps' ),
					'cloudflareProbeFailed' => __( 'Cybermaps could not check the public hostname from this browser. Confirm the orange-cloud proxy manually only if you have verified it in Cloudflare DNS.', 'cybermaps' ),
					'copied'                => __( 'Snippet copied.', 'cybermaps' ),
					'copyFailed'            => __( 'The snippet could not be copied automatically.', 'cybermaps' ),
				),
			)
		);
	}

	private static function localize_exchange_data(): void {
		wp_localize_script(
			'cybermaps-command-center',
			'cybermaps_discovery',
			array(
				'exchange_nonce'            => wp_create_nonce( 'cybermaps_exchange_action' ),
				'exchange_export_url'       => add_query_arg(
					'_wpnonce',
					wp_create_nonce( 'cybermaps_export_config' ),
					admin_url( 'admin-post.php?action=cybermaps_export_config' )
				),
				'exchange_max_import_bytes' => \Cybermaps\Admin\MigrationHub::get_max_import_bytes(),
			)
		);
	}

	private static function enqueue_shortcode_assets(): void {
		wp_enqueue_script( 'cybermaps-shortcode-builder', CYBERMAPS_PLUGIN_URL . 'assets/js/shortcode-builder.js', array( 'wp-i18n' ), CYBERMAPS_VERSION, true );
		wp_set_script_translations( 'cybermaps-shortcode-builder', 'cybermaps', CYBERMAPS_PLUGIN_DIR . 'languages' );
	}

	private static function localize_media_auditor(): void {
		$media_settings  = \Cybermaps\Core\ConfigurationStore::settings();
		$saved_intensity = isset( $media_settings['media_discovery_intensity'] )
			&& in_array( $media_settings['media_discovery_intensity'], array( 'none', 'standard', 'advanced' ), true )
				? (string) $media_settings['media_discovery_intensity']
				: 'none';
		wp_localize_script(
			'cybermaps-media-auditor',
			'cybermaps_auditor',
			array(
				'nonce'           => wp_create_nonce( 'cybermaps_media_sync' ),
				'savedIntensity'  => $saved_intensity,
				'saveFirstNotice' => __( 'Save Changes after selecting a media discovery mode, then start the rescan.', 'cybermaps' ),
				'rescanLabel'     => __( 'Rescan Media Now', 'cybermaps' ),
				'rescanningLabel' => __( 'Rescanning…', 'cybermaps' ),
				'cursorError'     => __( 'The media rescan cursor did not advance.', 'cybermaps' ),
				'batchError'      => __( 'The media rescan exceeded its expected batch count.', 'cybermaps' ),
				'failedPrefix'    => __( 'Media rescan failed: ', 'cybermaps' ),
				'completeNotice'  => __( 'Media rescan complete.', 'cybermaps' ),
				'invalidResponse' => __( 'The server did not return a usable response.', 'cybermaps' ),
				'connectionError' => __( 'Connection error.', 'cybermaps' ),
			)
		);
	}

	public static function add_admin_menu() {
		add_menu_page(
			'CYBERMAPS',
			'CYBERMAPS',
			'manage_options',
			'cybermaps-settings',
			array( \Cybermaps\Admin\SettingsPage::class, 'render_admin_page' ),
			'dashicons-networking',
			30
		);
	}
}
