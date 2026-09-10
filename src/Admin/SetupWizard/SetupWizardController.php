<?php
declare(strict_types=1);

namespace Cybermaps\Admin\SetupWizard;

use Cybermaps\Admin\ConfigurationReviewGuard;
use Cybermaps\Admin\MigrationHub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Protected admin endpoints for Guided Setup. */
final class SetupWizardController {
	private const NONCE_ACTION      = 'cybermaps_setup_wizard';
	private const MAX_PAYLOAD_BYTES = 65536;

	public static function register_hooks(): void {
		add_action( 'wp_ajax_cybermaps_setup_wizard_bootstrap', array( self::class, 'bootstrap' ) );
		add_action( 'wp_ajax_cybermaps_setup_wizard_search_pages', array( self::class, 'search_pages' ) );
		add_action( 'wp_ajax_cybermaps_setup_wizard_preview', array( self::class, 'preview' ) );
		add_action( 'wp_ajax_cybermaps_setup_wizard_apply', array( self::class, 'apply' ) );
	}

	public static function nonce_action(): string {
		return self::NONCE_ACTION;
	}

	public static function bootstrap(): void {
		self::authorize();
		wp_send_json_success( SetupWizardContext::build()->client_data() );
	}

	public static function search_pages(): void {
		self::authorize();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- authorize() verifies the action nonce before this payload is read.
		$query = isset( $_POST['query'] ) && is_scalar( $_POST['query'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['query'] ) ) : '';
		$page  = isset( $_POST['page'] ) && is_scalar( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		wp_send_json_success(
			array(
				'items' => SetupWizardContext::build()->search_catalog_parents( $query, $page ),
			)
		);
	}

	public static function preview(): void {
		self::authorize();
		try {
			$context = SetupWizardContext::build();
			$plan    = SetupWizardPlanFactory::build( self::payload(), $context );
			$preview = MigrationHub::get_instance()->preview( (string) $plan['content'], 'merge' );
			wp_send_json_success(
				array(
					'configuration'    => $plan['content'],
					'preview'          => $preview,
					'rationales'       => $plan['rationales'],
					'reset_sections'   => $plan['reset_sections'],
					'environment_hash' => $plan['environment_hash'],
				)
			);
		} catch ( \InvalidArgumentException $error ) {
			wp_send_json_error( array( 'message' => $error->getMessage() ), 400 );
		} catch ( \RuntimeException $error ) {
			wp_send_json_error( array( 'message' => $error->getMessage() ), 500 );
		} catch ( \Throwable $error ) {
			wp_send_json_error( array( 'message' => __( 'Cybermaps could not preview Guided Setup. No settings were changed.', 'cybermaps' ) ), 500 );
		}
	}

	public static function apply(): void {
		self::authorize();
		try {
			$context            = SetupWizardContext::build();
			$plan               = SetupWizardPlanFactory::build( self::payload(), $context );
			$posted_environment = self::posted_scalar( 'environment_hash' );
			$posted_content     = self::posted_configuration();
			$content_hash       = self::posted_scalar( 'content_hash' );
			$configuration_hash = self::posted_scalar( 'configuration_hash' );
			if (
				'' === $posted_environment
				|| ! hash_equals( (string) $plan['environment_hash'], $posted_environment )
				|| ! hash_equals( (string) $plan['content'], $posted_content )
			) {
				throw new \InvalidArgumentException( __( 'The Guided Setup plan changed after preview. Preview it again before applying.', 'cybermaps' ) );
			}
			if ( '' === $content_hash || '' === $configuration_hash ) {
				throw new \InvalidArgumentException( __( 'Preview Guided Setup before applying it.', 'cybermaps' ) );
			}

			$hub     = MigrationHub::get_instance();
			$preview = $hub->preview( (string) $plan['content'], 'merge' );
			if ( ConfigurationReviewGuard::has_high_impact_changes( $preview ) && ! self::posted_flag( 'acknowledge_high_impact' ) ) {
				throw new \InvalidArgumentException( __( 'Acknowledge the high-impact changes before applying Guided Setup.', 'cybermaps' ) );
			}
			if ( ! empty( $plan['reset_sections'] ) && ! self::posted_flag( 'acknowledge_reset' ) ) {
				throw new \InvalidArgumentException( __( 'Acknowledge the guided-field resets before applying Guided Setup.', 'cybermaps' ) );
			}

			$result = $hub->import_previewed(
				(string) $plan['content'],
				'merge',
				$content_hash,
				$configuration_hash
			);
			wp_send_json_success(
				array(
					'message' => __( 'Guided Setup applied successfully.', 'cybermaps' ),
					'result'  => $result,
				)
			);
		} catch ( \InvalidArgumentException $error ) {
			wp_send_json_error( array( 'message' => $error->getMessage() ), 400 );
		} catch ( \RuntimeException $error ) {
			wp_send_json_error( array( 'message' => $error->getMessage() ), 500 );
		} catch ( \Throwable $error ) {
			wp_send_json_error( array( 'message' => __( 'Guided Setup could not be applied. Review your current settings and try again.', 'cybermaps' ) ), 500 );
		}
	}

	private static function authorize(): void {
		self::require_post_request();
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'cybermaps' ) ), 403 );
		}
	}

	/** @return array<string,mixed> */
	private static function payload(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- authorize() verifies the nonce; raw JSON is decoded and structurally validated below.
		$raw = isset( $_POST['payload'] ) && is_string( $_POST['payload'] ) ? wp_unslash( $_POST['payload'] ) : '';
		if ( '' === trim( $raw ) || strlen( $raw ) > self::MAX_PAYLOAD_BYTES ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain JSON data; HTML escaping belongs at the presentation boundary.
			throw new \InvalidArgumentException( __( 'The Guided Setup request is missing or exceeds its safe size limit.', 'cybermaps' ) );
		}
		try {
			$payload = json_decode( $raw, true, 64, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain JSON data; HTML escaping belongs at the presentation boundary.
			throw new \InvalidArgumentException( __( 'The Guided Setup request is invalid JSON.', 'cybermaps' ) );
		}
		if ( ! is_array( $payload ) || ( ! empty( $payload ) && array_is_list( $payload ) ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain JSON data; HTML escaping belongs at the presentation boundary.
			throw new \InvalidArgumentException( __( 'The Guided Setup request must be an object.', 'cybermaps' ) );
		}

		return $payload;
	}

	private static function posted_configuration(): string {
		// This is a previously previewed JSON document, not a display field.
		// Sanitizing JSON with sanitize_text_field() can alter formatting and
		// invalidate the exact content hash checked by MigrationHub.
		$value = self::posted_raw( 'configuration' );
		if ( '' === $value || strlen( $value ) > MigrationHub::get_max_import_bytes() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages are plain JSON data; HTML escaping belongs at the presentation boundary.
			throw new \InvalidArgumentException( __( 'The reviewed Guided Setup configuration is missing or too large.', 'cybermaps' ) );
		}
		return $value;
	}

	private static function posted_raw( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- authorize() verifies the nonce; exact JSON is size- and hash-validated before import.
		return isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
	}

	private static function posted_scalar( string $key ): string {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- authorize() verifies the action nonce before this payload is read.
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	private static function posted_flag( string $key ): bool {
		return '1' === self::posted_scalar( $key );
	}

	private static function require_post_request(): void {
		$request_method = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		if ( 'POST' !== $request_method ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request method.', 'cybermaps' ) ), 405 );
		}
	}
}
