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

	/**
	 * Fixed palettes only; settings never enter the CSS declaration.
	 */
	public static function theme_css(): string {
		$palettes = array(
			'swiss'      => array(
				'bg'          => '#eef2f6',
				'surface'     => '#ffffff',
				'primary'     => '#172033',
				'primary_2'   => '#26344a',
				'accent'      => '#2271b1',
				'accent_soft' => '#edf6fc',
				'text'        => '#334155',
				'muted'       => '#64748b',
				'border'      => '#dbe2ea',
				'success'     => '#047857',
				'warning'     => '#b45309',
				'danger'      => '#b91c1c',
			),
			'minimal'    => array(
				'bg'          => '#ffffff',
				'surface'     => '#ffffff',
				'primary'     => '#18181b',
				'primary_2'   => '#27272a',
				'accent'      => '#18181b',
				'accent_soft' => '#f4f4f5',
				'text'        => '#3f3f46',
				'muted'       => '#71717a',
				'border'      => '#e4e4e7',
				'success'     => '#166534',
				'warning'     => '#92400e',
				'danger'      => '#991b1b',
			),
			'mono'       => array(
				'bg'          => '#f4f4f4',
				'surface'     => '#ffffff',
				'primary'     => '#000000',
				'primary_2'   => '#202020',
				'accent'      => '#505050',
				'accent_soft' => '#f0f0f0',
				'text'        => '#303030',
				'muted'       => '#707070',
				'border'      => '#d8d8d8',
				'success'     => '#235f23',
				'warning'     => '#75530b',
				'danger'      => '#8a2424',
			),
			'midnight'   => array(
				'bg'          => '#0f172a',
				'surface'     => '#172033',
				'primary'     => '#08101f',
				'primary_2'   => '#111c31',
				'accent'      => '#38bdf8',
				'accent_soft' => '#1e293b',
				'text'        => '#dbeafe',
				'muted'       => '#94a3b8',
				'border'      => '#334155',
				'success'     => '#34d399',
				'warning'     => '#fbbf24',
				'danger'      => '#f87171',
			),
			'cyberbrand' => array(
				'bg'          => '#0a0a0a',
				'surface'     => '#141414',
				'primary'     => '#000000',
				'primary_2'   => '#111111',
				'accent'      => '#ffc734',
				'accent_soft' => '#211d12',
				'text'        => '#e5e7eb',
				'muted'       => '#9ca3af',
				'border'      => '#303030',
				'success'     => '#4ade80',
				'warning'     => '#fbbf24',
				'danger'      => '#f87171',
			),
		);
		$palette  = $palettes[ self::theme() ] ?? $palettes['swiss'];
		$css      = ':root{';
		foreach ( $palette as $name => $value ) {
			$css .= '--cmr-' . str_replace( '_', '-', $name ) . ':' . $value . ';';
		}
		return $css . '}';
	}
}
