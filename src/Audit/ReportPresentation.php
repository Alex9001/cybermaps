<?php
declare(strict_types=1);

namespace Cybermaps\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared branding and visual themes for client-facing reports.
 */
final class ReportPresentation {
	/**
	 * @return array<string,string>
	 */
	public static function themes(): array {
		return array(
			'swiss'      => __( 'Swiss', 'cybermaps' ),
			'minimal'    => __( 'Minimal', 'cybermaps' ),
			'mono'       => __( 'Monochrome', 'cybermaps' ),
			'midnight'   => __( 'Midnight', 'cybermaps' ),
			'cyberbrand' => __( 'Cyberbrand', 'cybermaps' ),
		);
	}

	public static function theme(): string {
		$settings = \Cybermaps\Core\ConfigurationStore::settings();
		$stored   = $settings['report_theme'] ?? 'swiss';
		$theme    = is_scalar( $stored ) ? sanitize_key( (string) $stored ) : 'swiss';
		return array_key_exists( $theme, self::themes() ) ? $theme : 'swiss';
	}

	/**
	 * @return array{site_name:string,site_url:string,agency_name:string,agency_url:string,agency_logo:string}
	 */
	public static function identity(): array {
		$settings    = \Cybermaps\Core\ConfigurationStore::settings();
		$site_name   = is_scalar( $settings['site_name_override'] ?? null )
			? trim( (string) $settings['site_name_override'] )
			: '';
		$agency_name = is_scalar( $settings['agency_name'] ?? null )
			? trim( (string) $settings['agency_name'] )
			: '';

		return array(
			'site_name'   => '' !== $site_name ? $site_name : (string) get_bloginfo( 'name' ),
			'site_url'    => (string) \Cybermaps\Core\URLManager::get_home_url( '/' ),
			'agency_name' => $agency_name,
			'agency_url'  => \Cybermaps\Core\URLManager::sanitize_http_url( $settings['agency_url'] ?? '' ),
			'agency_logo' => \Cybermaps\Core\URLManager::sanitize_http_url( $settings['agency_logo'] ?? '' ),
		);
	}

	/**
	 * Return a safe HTML language tag for standalone report documents.
	 */
	public static function language(): string {
		$language = str_replace( '_', '-', (string) get_bloginfo( 'language' ) );
		$language = preg_replace( '/[^A-Za-z0-9-]/', '', $language ) ?? '';
		return '' !== $language ? $language : 'en-US';
	}

	/** Return registered report styles without consuming the caller's output or style queue. */
	public static function stylesheet_markup(): string {
		$url = CYBERMAPS_PLUGIN_URL . 'assets/css/report.css';
		wp_enqueue_style( 'cybermaps-report', $url, array(), CYBERMAPS_VERSION );

		// WP_Styles can accumulate markup instead of printing it. Keep that state
		// local so repeated standalone exports do not consume global done/queue state.
		$styles            = clone wp_styles();
		$styles->done      = array();
		$styles->to_do     = array();
		$styles->groups    = array();
		$styles->queue     = array();
		$styles->do_concat = true;
		// An empty list means every URL is concatenable in WP_Styles. A NUL
		// cannot prefix a stylesheet URL, so all links accumulate in print_html.
		$styles->default_dirs   = array( "\0" );
		$styles->concat         = '';
		$styles->concat_version = '';
		$styles->print_html     = '';
		$styles->print_code     = '';
		$styles->do_items( array( 'cybermaps-report' ) );

		return $styles->print_html;
	}

	/** Return the fixed body class that selects one of the five report themes. */
	public static function theme_class(): string {
		return 'cm-report-theme-' . self::theme();
	}
}
