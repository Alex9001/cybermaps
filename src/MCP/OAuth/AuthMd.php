<?php
declare(strict_types=1);

namespace Cybermaps\MCP\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Generates the mode-gated public OAuth agent-registration description. */
final class AuthMd {
	public const PATH = '/auth.md';

	/** @param array<string,mixed>|null $settings General settings snapshot. */
	public function is_available( ?array $settings = null ): bool {
		$settings = $settings ?? \Cybermaps\Core\ConfigurationStore::settings();
		return AgentRegistrationMode::is_user_claimed( $settings )
			&& 'off' !== \Cybermaps\MCP\WordPressIntegration::mode( $settings );
	}

	/** @param array<string,mixed>|null $settings General settings snapshot. */
	public function get_content( ?array $settings = null ): string {
		if ( ! $this->is_available( $settings ) ) {
			return '';
		}

		return implode(
			"\n",
			array(
				'# Cybermaps Agent Authorization',
				'',
				'Registration mode: user-claimed OAuth 2.0 Device Authorization Grant (RFC 8628).',
				'',
				'## Client registration',
				'',
				'- Use a publicly routable HTTPS Client ID Metadata Document URL as `client_id`.',
				'- The document must identify the same `client_id`, declare HTTPS redirect URIs, and request supported Cybermaps scopes.',
				'- Cybermaps retrieves the document with SSRF-safe WordPress HTTP validation. There is no open Dynamic Client Registration endpoint.',
				'',
				'## Device flow',
				'',
				'- Device authorization endpoint: ' . OAuthRouteController::device_authorization_endpoint_url(),
				'- User verification page: ' . OAuthRouteController::agent_authorization_url(),
				'- Token endpoint: ' . rest_url( 'cybermaps/v1/oauth/token' ),
				'- Grant type: `' . OAuthService::DEVICE_GRANT_TYPE . '`.',
				'- A logged-in WordPress user must explicitly approve requested scopes before any credential is issued.',
				'',
				'Cybermaps does not create WordPress accounts through this flow and does not provide OpenID Connect, ID tokens, or JWKS.',
				'',
			)
		);
	}

	/** Serve the canonical mode-gated registration document. */
	public function handle(): void {
		if ( self::PATH !== \Cybermaps\Core\URLManager::get_request_path() || ! $this->is_available() ) {
			return;
		}
		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, follow' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The handler generates a fixed Markdown protocol document with escaped URL construction.
		echo $this->get_content();
		exit;
	}
}
