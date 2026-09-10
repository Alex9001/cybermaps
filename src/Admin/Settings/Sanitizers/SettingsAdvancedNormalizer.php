<?php
declare(strict_types=1);
namespace Cybermaps\Admin\Settings\Sanitizers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SettingsAdvancedNormalizer {
	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $sanitized
	 * @param array<string, mixed> $old_options
	 */
	public static function normalize( array $input, array $sanitized, array $old_options, string $active_tab ): array {
		$sanitized = self::normalize_identity( $input, $sanitized );
		$sanitized = self::normalize_audit_settings( $input, $sanitized, $active_tab );
		$sanitized = self::normalize_network_settings( $input, $sanitized, $active_tab );
		$sanitized = self::normalize_performance( $input, $sanitized, $active_tab );
		$sanitized = self::normalize_cloudflare_oauth( $input, $sanitized );
		$sanitized = self::normalize_credentials( $input, $sanitized, $old_options );
		return self::normalize_maintenance( $input, $sanitized, $active_tab );
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $sanitized
	 * @return array<string, mixed>
	 */
	private static function normalize_cloudflare_oauth( array $input, array $sanitized ): array {
		if ( array_key_exists( 'cloudflare_oauth_mode', $input ) ) {
			$mode = sanitize_key( SettingsSanitizer::scalar_string( $input['cloudflare_oauth_mode'] ) );

			$sanitized['cloudflare_oauth_mode'] = 'custom' === $mode ? 'custom' : 'managed';
		}
		if ( array_key_exists( 'cloudflare_oauth_client_id', $input ) ) {
			$client_id = sanitize_text_field( SettingsSanitizer::scalar_string( $input['cloudflare_oauth_client_id'] ) );

			$sanitized['cloudflare_oauth_client_id'] = \Cybermaps\Admin\CloudflareOAuthClient::is_valid_client_id( $client_id ) ? $client_id : '';
		}
		return $sanitized;
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $sanitized
	 * @return array<string, mixed>
	 */
	private static function normalize_performance( array $input, array $sanitized, string $active_tab ): array {
		foreach ( array( 'enable_litespeed_cache_integration', 'enable_apcu_l1_cache' ) as $key ) {
			if ( SettingsSanitizer::should_process_checkbox( $input, $key, 'advanced', $active_tab ) ) {
				$sanitized[ $key ] = ! empty( $input[ $key ] ) ? '1' : '0';
			}
		}
		return $sanitized;
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $sanitized
	 * @return array<string, mixed>
	 */
	private static function normalize_identity( array $input, array $sanitized ): array {
		if ( array_key_exists( 'agency_name', $input ) ) {
			$sanitized['agency_name'] = sanitize_text_field( SettingsSanitizer::scalar_string( $input['agency_name'] ) );
		}
		foreach ( array( 'agency_url', 'agency_logo' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$sanitized[ $key ] = \Cybermaps\Core\URLManager::sanitize_http_url( $input[ $key ] );
			}
		}
		if ( array_key_exists( 'site_name_override', $input ) ) {
			$sanitized['site_name_override'] = sanitize_text_field( SettingsSanitizer::scalar_string( $input['site_name_override'] ) );
		}
		if ( array_key_exists( 'report_theme', $input ) ) {
			$theme                     = sanitize_key( SettingsSanitizer::scalar_string( $input['report_theme'] ) );
			$sanitized['report_theme'] = in_array( $theme, array( 'swiss', 'minimal', 'mono', 'midnight', 'cyberbrand' ), true ) ? $theme : 'swiss';
		}
		return $sanitized;
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $sanitized
	 * @return array<string, mixed>
	 */
	private static function normalize_audit_settings( array $input, array $sanitized, string $active_tab ): array {
		$bounds = array(
			'audit_post_min_words'    => array( 1, 10000, 300 ),
			'audit_post_max_age_days' => array( 0, 36500, 365 ),
			'audit_page_min_words'    => array( 1, 10000, 150 ),
			'audit_page_max_age_days' => array( 0, 36500, 0 ),
		);
		foreach ( $bounds as $key => $bound ) {
			if ( array_key_exists( $key, $input ) ) {
				$raw               = is_scalar( $input[ $key ] ) ? trim( (string) $input[ $key ] ) : '';
				$value             = '' === $raw ? $bound[2] : absint( $raw );
				$sanitized[ $key ] = max( $bound[0], min( $bound[1], $value ) );
			}
		}
		foreach ( array( 'audit_post_require_media', 'audit_page_require_media' ) as $key ) {
			if ( SettingsSanitizer::should_process_checkbox( $input, $key, 'review', $active_tab ) ) {
				$sanitized[ $key ] = ! empty( $input[ $key ] ) ? '1' : '0';
			}
		}
		return $sanitized;
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $sanitized
	 * @return array<string, mixed>
	 */
	private static function normalize_network_settings( array $input, array $sanitized, string $active_tab ): array {
		foreach ( array( 'frontend_base_url', 'cdn_base_url' ) as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$sanitized[ $key ] = \Cybermaps\Core\URLManager::normalize_configured_base_url( $input[ $key ] );
			}
		}
		if ( SettingsSanitizer::should_process_checkbox( $input, 'cdn_enabled', 'advanced', $active_tab ) ) {
			$sanitized['cdn_enabled'] = ! empty( $input['cdn_enabled'] ) ? '1' : '0';
		}
		if ( array_key_exists( 'trusted_proxy_header', $input ) ) {
			$header                            = str_replace( '-', '_', sanitize_key( SettingsSanitizer::scalar_string( $input['trusted_proxy_header'] ) ) );
			$sanitized['trusted_proxy_header'] = in_array( $header, array( 'off', 'forwarded', 'x_forwarded_for', 'x_real_ip' ), true ) ? $header : 'off';
		}
		if ( array_key_exists( 'trusted_proxy_cidrs', $input ) ) {
			$sanitized['trusted_proxy_cidrs'] = self::normalize_trusted_proxy_cidrs( $input['trusted_proxy_cidrs'] );
		}
		return $sanitized;
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $sanitized
	 * @param array<string, mixed> $old_options
	 * @return array<string, mixed>
	 */
	private static function normalize_credentials( array $input, array $sanitized, array $old_options ): array {
		$old_secret = isset( $old_options['api_secret'] ) && is_scalar( $old_options['api_secret'] ) ? sanitize_text_field( (string) $old_options['api_secret'] ) : '';
		if ( array_key_exists( 'api_secret', $input ) ) {
			$new_secret = is_scalar( $input['api_secret'] ) ? sanitize_text_field( (string) $input['api_secret'] ) : '';
			if ( '' !== $new_secret ) {
				$sanitized['api_secret'] = $new_secret;
			}
		}
		if ( ! isset( $sanitized['api_secret'] ) || ! is_string( $sanitized['api_secret'] ) || '' === $sanitized['api_secret'] ) {
			$sanitized['api_secret'] = '' !== $old_secret ? $old_secret : wp_generate_password( 32, false );
		}
		return $sanitized;
	}

	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $sanitized
	 * @return array<string, mixed>
	 */
	private static function normalize_maintenance( array $input, array $sanitized, string $active_tab ): array {
		if ( SettingsSanitizer::should_process_checkbox( $input, 'delete_data_on_uninstall', 'advanced', $active_tab ) ) {
			$sanitized['delete_data_on_uninstall'] = ! empty( $input['delete_data_on_uninstall'] ) ? '1' : '0';
		}
		if ( SettingsSanitizer::should_process_checkbox( $input, 'enable_shortcode', 'shortcode', $active_tab ) ) {
			$sanitized['enable_shortcode'] = ! empty( $input['enable_shortcode'] ) ? '1' : '0';
		}
		if ( array_key_exists( 'log_retention_days', $input ) ) {
			$retention                       = SettingsSanitizer::value_or_default( $input['log_retention_days'], 30 );
			$sanitized['log_retention_days'] = max( 1, min( 365, absint( $retention ) ) );
		}
		return $sanitized;
	}

	private static function normalize_trusted_proxy_cidrs( mixed $value ): string {
		$candidates = array();
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				if ( is_scalar( $item ) ) {
					$parts      = preg_split( '/[\r\n,]+/', (string) $item );
					$candidates = array_merge( $candidates, is_array( $parts ) ? $parts : array() );
				}
			}
		} elseif ( is_scalar( $value ) ) {
			$parts      = preg_split( '/[\r\n,]+/', (string) $value );
			$candidates = is_array( $parts ) ? $parts : array();
		}
		$valid = array();
		foreach ( $candidates as $candidate ) {
			$cidr = trim( (string) $candidate );
			if ( '' !== $cidr && self::is_valid_cidr( $cidr ) && ! in_array( $cidr, $valid, true ) ) {
				$valid[] = $cidr;
			}
			if ( count( $valid ) >= 64 ) {
				break;
			}
		}
		return implode( "\n", array_slice( $valid, 0, 64 ) );
	}

	private static function is_valid_cidr( string $cidr ): bool {
		$parts = explode( '/', $cidr, 2 );
		if ( 2 !== count( $parts ) || '' === trim( $parts[0] ) || '' === trim( $parts[1] ) || ! ctype_digit( trim( $parts[1] ) ) ) {
			return false;
		}
		$network = trim( $parts[0] );
		$prefix  = (int) trim( $parts[1] );
		if ( false !== filter_var( $network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return $prefix <= 32;
		}
		if ( false !== filter_var( $network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return $prefix <= 128;
		}
		return false;
	}
}
