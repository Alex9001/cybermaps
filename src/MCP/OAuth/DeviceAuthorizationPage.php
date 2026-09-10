<?php
declare(strict_types=1);

namespace Cybermaps\MCP\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Logged-in WordPress approval page for RFC 8628 user codes. */
final class DeviceAuthorizationPage {
	public const PATH = '/cybermaps-agent-auth';

	/** @var callable():int */
	private $current_user_id;

	/** @param callable():int $current_user_id Testable current-user reader. */
	public function __construct( private readonly OAuthService $service, callable $current_user_id, private readonly bool $enabled ) {
		$this->current_user_id = $current_user_id;
	}

	public function maybe_serve( mixed $wp = null ): void {
		unset( $wp );
		if ( ! $this->enabled || self::PATH !== $this->relative_request_path() ) {
			return;
		}
		$user_id = max( 0, (int) call_user_func( $this->current_user_id ) );
		if ( $user_id < 1 ) {
			wp_safe_redirect( wp_login_url( home_url( self::PATH ) ) );
			exit;
		}

		$user_code = $this->request_value( 'user_code', 9 );
		$message   = '';
		$error     = '';
		try {
			if ( 'POST' === $this->request_method() ) {
				$message   = $this->process_decision( $user_code, $user_id );
				$user_code = '';
			}
			$details = '' === $user_code ? null : $this->service->device_authorization_details( $user_code );
		} catch ( OAuthException $exception ) {
			$details = null;
			$error   = $exception->getMessage();
		}
		$this->render( $user_code, $details, $message, $error );
		exit;
	}

	private function process_decision( string $user_code, int $user_id ): string {
		$nonce = $this->request_value( 'cybermaps_agent_auth_nonce', 255 );
		if ( ! wp_verify_nonce( $nonce, $this->nonce_action( $user_code ) ) ) {
			throw new OAuthException( 'invalid_request', 'The approval request nonce is invalid.', 403 );
		}
		$decision = $this->request_value( 'decision', 7 );
		if ( ! in_array( $decision, array( 'approve', 'deny' ), true ) ) {
			throw new OAuthException( 'invalid_request', 'Choose Approve or Deny.' );
		}
		$this->service->decide_device_authorization( $user_code, $user_id, 'approve' === $decision );
		return 'approve' === $decision
			? __( 'The agent was approved. You can return to the requesting application.', 'cybermaps' )
			: __( 'The agent request was denied.', 'cybermaps' );
	}

	/** @param array<string,mixed>|null $details */
	private function render( string $user_code, ?array $details, string $message, string $error ): void {
		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset', 'UTF-8' ) );
		echo '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html__( 'Authorize Cybermaps Agent', 'cybermaps' ) . '</title></head><body>';
		echo '<main style="max-width:42rem;margin:4rem auto;padding:0 1.5rem;font:16px/1.5 sans-serif"><h1>' . esc_html__( 'Authorize Cybermaps Agent', 'cybermaps' ) . '</h1>';
		if ( '' !== $message ) {
			echo '<p role="status">' . esc_html( $message ) . '</p>';
		}
		if ( '' !== $error ) {
			echo '<p role="alert">' . esc_html( $error ) . '</p>';
		}
		if ( is_array( $details ) ) {
			$this->render_decision_form( $user_code, $details );
		} else {
			$this->render_lookup_form( $user_code );
		}
		echo '</main></body></html>';
	}

	private function render_lookup_form( string $user_code ): void {
		echo '<form method="get" action="' . esc_url( home_url( self::PATH ) ) . '"><label for="cybermaps-agent-user-code">' . esc_html__( 'User code', 'cybermaps' ) . '</label> ';
		echo '<input id="cybermaps-agent-user-code" name="user_code" value="' . esc_attr( $user_code ) . '" maxlength="9" pattern="[A-Z2-9]{4}-[A-Z2-9]{4}" required> ';
		echo '<button type="submit">' . esc_html__( 'Continue', 'cybermaps' ) . '</button></form>';
	}

	/** @param array<string,mixed> $details */
	private function render_decision_form( string $user_code, array $details ): void {
		$client = is_array( $details['client'] ?? null ) ? $details['client'] : array();
		echo '<p><strong>' . esc_html( (string) ( $client['client_name'] ?? '' ) ) . '</strong></p><ul>';
		foreach ( (array) ( $details['scopes'] ?? array() ) as $scope ) {
			echo '<li><code>' . esc_html( (string) $scope ) . '</code></li>';
		}
		echo '</ul><form method="post" action="' . esc_url( home_url( self::PATH ) ) . '">';
		echo '<input type="hidden" name="user_code" value="' . esc_attr( $user_code ) . '">';
		wp_nonce_field( $this->nonce_action( $user_code ), 'cybermaps_agent_auth_nonce' );
		echo '<button type="submit" name="decision" value="approve">' . esc_html__( 'Approve', 'cybermaps' ) . '</button> ';
		echo '<button type="submit" name="decision" value="deny">' . esc_html__( 'Deny', 'cybermaps' ) . '</button></form>';
	}

	private function relative_request_path(): string {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) && is_scalar( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '';
		$path        = wp_parse_url( $request_uri, PHP_URL_PATH );
		$base        = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$path        = is_string( $path ) ? $path : '';
		$base        = is_string( $base ) ? rtrim( $base, '/' ) : '';
		return '' === $base ? $path : ( str_starts_with( $path, $base . '/' ) ? substr( $path, strlen( $base ) ) : '' );
	}

	private function request_method(): string {
		return isset( $_SERVER['REQUEST_METHOD'] ) && is_scalar( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) )
			: 'GET';
	}

	private function request_value( string $key, int $maximum_length ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- POST nonce verification is performed before a decision; GET only looks up a short-lived public user code.
		$value = $_REQUEST[ $key ] ?? '';
		$value = is_scalar( $value ) ? sanitize_text_field( wp_unslash( (string) $value ) ) : '';
		return strlen( $value ) <= $maximum_length ? trim( $value ) : '';
	}

	private function nonce_action( string $user_code ): string {
		return 'cybermaps_agent_auth_' . hash( 'sha256', strtoupper( trim( $user_code ) ) );
	}
}
