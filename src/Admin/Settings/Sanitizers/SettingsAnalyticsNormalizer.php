<?php
declare(strict_types=1);
namespace Cybermaps\Admin\Settings\Sanitizers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SettingsAnalyticsNormalizer {
	/**
	 * @param array<string, mixed> $input
	 * @param array<string, mixed> $sanitized
	 * @param array<string, mixed> $old_options
	 */
	public static function normalize( array $input, array $sanitized, array $old_options, string $active_tab ): array {
		if ( SettingsSanitizer::should_process_checkbox( $input, 'enable_analytics', 'analytics', $active_tab ) ) {
			$sanitized['enable_analytics'] = ! empty( $input['enable_analytics'] ) ? '1' : '0';
		} elseif ( ! isset( $sanitized['enable_analytics'] ) ) {
			$sanitized['enable_analytics'] = '0';
		}
		if ( SettingsSanitizer::should_process_checkbox( $input, 'anonymize_analytics_ips', 'analytics', $active_tab ) ) {
			$sanitized['anonymize_analytics_ips'] = ! empty( $input['anonymize_analytics_ips'] ) ? '1' : '0';
		} elseif ( ! isset( $sanitized['anonymize_analytics_ips'] ) ) {
			$sanitized['anonymize_analytics_ips'] = '1';
		}

		return $sanitized;
	}
}
