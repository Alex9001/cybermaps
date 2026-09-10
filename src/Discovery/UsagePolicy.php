<?php
/**
 * AI Usage Policy Handler
 *
 * @package Cybermaps\Discovery
 */

declare(strict_types=1);

namespace Cybermaps\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class UsagePolicy {

	/**
	 * Handle requests for /ai-usage.json.
	 */
	public function handle() {
		$path = \Cybermaps\Core\URLManager::get_request_path();
		if ( '/ai-usage.json' !== $path ) {
			return;
		}

		$settings = \Cybermaps\Core\ConfigurationStore::settings();
		if ( empty( $settings['enable_discovery_hub'] ) ) {
			return;
		}

		$data   = $this->get_policy_data();
		$output = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		Integrity::send_headers( $output );
		header( 'Content-Type: application/json; charset=utf-8' );
		if ( ! \Cybermaps\Core\ReadOnlyRequest::is_head() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $output;
		}
		exit;
	}

	/**
	 * Get the policy data for the manifest.
	 *
	 * @return array
	 */
	public function get_policy_data() {
		$settings = \Cybermaps\Core\ConfigurationStore::settings();
		$license  = PublicationConstraints::content_license(
			$settings['llms_content_license'] ?? ''
		);

		/*
		 * Runtime output must match the defaults shown in the settings UI even
		 * before that tab has been saved. Validate stored values again here so
		 * an old import or direct option write cannot publish an unsupported
		 * policy token.
		 */
		$rag        = $this->policy_value(
			$settings['ai_usage_rag'] ?? 'allow',
			array( 'allow', 'forbid', 'limited' ),
			'allow'
		);
		$training   = $this->policy_value(
			$settings['ai_usage_training'] ?? 'forbid',
			array( 'allow', 'forbid' ),
			'forbid'
		);
		$commercial = $this->policy_value(
			$settings['ai_usage_commercial'] ?? 'forbid',
			array( 'allow', 'forbid' ),
			'forbid'
		);
		$policy     = array(
			'rag_usage'           => $rag,
			'foundation_training' => $training,
			'commercial_use'      => $commercial,
		);

		$data = array(
			'version'   => '1.0',
			'publisher' => get_bloginfo( 'name' ),
			'policy'    => $policy,
		);
		if ( '' !== $license ) {
			$data['license'] = $license;
		}

		$licensing_email = is_scalar( $settings['ai_licensing_email'] ?? null )
			? sanitize_email( (string) $settings['ai_licensing_email'] )
			: '';
		if ( '' !== $licensing_email ) {
			$data['contacts'] = array(
				'licensing' => $licensing_email,
			);
		}

		return $data;
	}

	/**
	 * @param string[] $allowed Allowed values.
	 */
	private function policy_value( mixed $stored, array $allowed, string $fallback ): string {
		$value = is_scalar( $stored ) ? sanitize_key( (string) $stored ) : $fallback;
		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}
}
