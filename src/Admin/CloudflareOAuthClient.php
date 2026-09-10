<?php
/**
 * Ephemeral Cloudflare OAuth and relay client.
 *
 * @package Cybermaps\Admin
 */

declare(strict_types=1);

namespace Cybermaps\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Performs PKCE authorization support without persisting OAuth credentials. */
final class CloudflareOAuthClient {
	private const DEFAULT_RELAY_BASE = 'https://connect.cybermaps.dev';
	// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Documented Cloudflare OAuth service endpoint/origin validation; no remote assets are loaded.
	private const AUTH_ENDPOINT = 'https://dash.cloudflare.com/oauth2/auth';
	// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Documented Cloudflare OAuth service endpoint/origin validation; no remote assets are loaded.
	private const TOKEN_ENDPOINT = 'https://dash.cloudflare.com/oauth2/token';
	// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Documented Cloudflare OAuth service endpoint/origin validation; no remote assets are loaded.
	private const REVOKE_ENDPOINT    = 'https://dash.cloudflare.com/oauth2/revoke';
	private const REQUIRED_SCOPES    = 'zone.read zone-transform-rules.write cache-settings.write';
	private const MAX_RESPONSE_BYTES = 1048576;

	public function __construct( private ?\Closure $transport = null ) {}

	public static function generate_verifier(): string {
		return self::base64url( random_bytes( 64 ) );
	}

	public static function challenge_for( #[\SensitiveParameter] string $verifier ): string {
		if ( strlen( $verifier ) < 43 || strlen( $verifier ) > 128 || 1 !== preg_match( '/\A[A-Za-z0-9._~-]+\z/', $verifier ) ) {
			throw new \InvalidArgumentException( esc_html__( 'The Cloudflare PKCE verifier is invalid.', 'cybermaps' ) );
		}
		return self::base64url( hash( 'sha256', $verifier, true ) );
	}

	public static function is_valid_client_id( string $client_id ): bool {
		return strlen( $client_id ) >= 8 && strlen( $client_id ) <= 256 && 1 === preg_match( '/\A[A-Za-z0-9._-]+\z/', $client_id );
	}

	/** @return array<string,mixed> */
	public function create_direct_transaction( string $challenge, string $client_id, string $redirect_uri ): array {
		if ( ! self::is_valid_client_id( $client_id ) || ! hash_equals( EdgeOptimizationController::oauth_callback_url(), $redirect_uri ) ) {
			throw new \InvalidArgumentException( esc_html__( 'The custom Cloudflare OAuth configuration is invalid.', 'cybermaps' ) );
		}
		if ( strlen( $challenge ) < 43 || strlen( $challenge ) > 128 || 1 !== preg_match( '/\A[A-Za-z0-9_-]+\z/', $challenge ) ) {
			throw new \InvalidArgumentException( esc_html__( 'The Cloudflare PKCE challenge is invalid.', 'cybermaps' ) );
		}
		$data = $this->direct_transaction_data( $challenge, $client_id, $redirect_uri );
		$this->require_transaction_fields( $data );
		$this->require_authorization_url( $data, $challenge );
		return $data;
	}

	/** @return array<string,string> */
	private function direct_transaction_data( string $challenge, string $client_id, string $redirect_uri ): array {
		$transaction_id = bin2hex( random_bytes( 16 ) );

		$data = array(
			'transaction_id' => $transaction_id,
			'consume_secret' => bin2hex( random_bytes( 32 ) ),
			'state'          => bin2hex( random_bytes( 32 ) ),
			'client_id'      => $client_id,
			'redirect_uri'   => $redirect_uri,
		);

		$data['authorization_url'] = self::AUTH_ENDPOINT . '?' . http_build_query(
			array(
				'response_type'         => 'code',
				'client_id'             => $client_id,
				'redirect_uri'          => $redirect_uri,
				'scope'                 => self::REQUIRED_SCOPES,
				'state'                 => $data['state'],
				'code_challenge'        => $challenge,
				'code_challenge_method' => 'S256',
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
		return $data;
	}

	/** @return array<string,mixed> */
	public function create_transaction( string $challenge ): array {
		if ( strlen( $challenge ) < 43 || strlen( $challenge ) > 128 || 1 !== preg_match( '/\A[A-Za-z0-9_-]+\z/', $challenge ) ) {
			throw new \InvalidArgumentException( esc_html__( 'The Cloudflare PKCE challenge is invalid.', 'cybermaps' ) );
		}
		$response = $this->post_json(
			$this->relay_base() . '/v1/cloudflare/transactions',
			array(
				'protocol_version'      => 1,
				'code_challenge'        => $challenge,
				'code_challenge_method' => 'S256',
			)
		);
		$data     = $this->json_response( $response, __( 'Cybermaps Connect returned an invalid transaction.', 'cybermaps' ) );
		$this->require_transaction_fields( $data );
		$this->require_authorization_url( $data, $challenge );
		return $data;
	}

	/** @return array<string,mixed> */
	public function consume_transaction( string $transaction_id, #[\SensitiveParameter] string $secret ): array {
		if ( 1 !== preg_match( '/\A[a-f0-9-]{20,64}\z/i', $transaction_id ) || strlen( $secret ) < 32 || strlen( $secret ) > 256 ) {
			throw new \InvalidArgumentException( esc_html__( 'The Cloudflare authorization transaction is invalid.', 'cybermaps' ) );
		}
		$response = $this->post_json(
			$this->relay_base() . '/v1/cloudflare/transactions/' . rawurlencode( $transaction_id ) . '/consume',
			array( 'protocol_version' => 1 ),
			array( 'Authorization' => 'Bearer ' . $secret )
		);
		$data     = $this->json_response( $response, __( 'Cybermaps Connect returned an invalid authorization result.', 'cybermaps' ) );
		$status   = sanitize_key( (string) ( $data['status'] ?? '' ) );
		if ( ! in_array( $status, array( 'pending', 'authorized', 'denied', 'expired' ), true ) ) {
			throw new \RuntimeException( esc_html__( 'Cybermaps Connect returned an unknown authorization state.', 'cybermaps' ) );
		}
		return $data;
	}

	public function exchange_code( string $code, #[\SensitiveParameter] string $verifier, string $client_id, string $redirect_uri ): string {
		if ( '' === $code || strlen( $code ) > 4096 || ! $this->valid_client_id( $client_id ) || ! $this->valid_redirect_uri( $redirect_uri ) ) {
			throw new \InvalidArgumentException( esc_html__( 'Cloudflare returned an invalid authorization code exchange.', 'cybermaps' ) );
		}
		self::challenge_for( $verifier );
		$response = $this->post_form(
			self::TOKEN_ENDPOINT,
			array(
				'grant_type'    => 'authorization_code',
				'client_id'     => $client_id,
				'code'          => $code,
				'redirect_uri'  => $redirect_uri,
				'code_verifier' => $verifier,
			)
		);
		$data     = $this->json_response( $response, __( 'Cloudflare did not return a usable access token.', 'cybermaps' ) );
		$token    = is_scalar( $data['access_token'] ?? null ) ? trim( (string) $data['access_token'] ) : '';
		$type     = strtolower( is_scalar( $data['token_type'] ?? null ) ? (string) $data['token_type'] : 'bearer' );
		if ( strlen( $token ) < 20 || strlen( $token ) > 4096 || 'bearer' !== $type ) {
			throw new \RuntimeException( esc_html__( 'Cloudflare did not return a usable bearer token.', 'cybermaps' ) );
		}
		return $token;
	}

	public function revoke_access_token( #[\SensitiveParameter] string $token, string $client_id ): void {
		if ( strlen( $token ) < 20 || strlen( $token ) > 4096 || ! $this->valid_client_id( $client_id ) ) {
			throw new \InvalidArgumentException( esc_html__( 'The temporary Cloudflare token could not be revoked because its metadata was invalid.', 'cybermaps' ) );
		}
		$response = $this->post_form(
			self::REVOKE_ENDPOINT,
			array(
				'token'           => $token,
				'token_type_hint' => 'access_token',
				'client_id'       => $client_id,
			)
		);
		$this->require_success( $response, __( 'Cloudflare token revocation could not be confirmed.', 'cybermaps' ) );
	}

	private function relay_base(): string {
		$base = defined( 'CYBERMAPS_CLOUDFLARE_OAUTH_RELAY_URL' )
			? (string) constant( 'CYBERMAPS_CLOUDFLARE_OAUTH_RELAY_URL' )
			: self::DEFAULT_RELAY_BASE;
		$base = rtrim( trim( $base ), '/' );
		if ( ! str_starts_with( $base, 'https://' ) ) {
			throw new \RuntimeException( esc_html__( 'Cybermaps Connect must use HTTPS.', 'cybermaps' ) );
		}
		return $base;
	}

	/** @param array<string,mixed> $data */
	private function require_transaction_fields( array $data ): void {
		$limits = array(
			'transaction_id' => array( 20, 64 ),
			'consume_secret' => array( 32, 256 ),
			'state'          => array( 32, 512 ),
			'client_id'      => array( 8, 256 ),
			'redirect_uri'   => array( 20, 2048 ),
		);
		foreach ( $limits as $key => $range ) {
			$value = is_scalar( $data[ $key ] ?? null ) ? (string) $data[ $key ] : '';
			if ( strlen( $value ) < $range[0] || strlen( $value ) > $range[1] ) {
				throw new \RuntimeException( esc_html__( 'Cybermaps Connect returned incomplete transaction metadata.', 'cybermaps' ) );
			}
		}
		if ( ! $this->valid_client_id( (string) $data['client_id'] ) || ! $this->valid_redirect_uri( (string) $data['redirect_uri'] ) ) {
			throw new \RuntimeException( esc_html__( 'Cybermaps Connect returned unsafe OAuth client metadata.', 'cybermaps' ) );
		}
	}

	/** @param array<string,mixed> $data */
	private function require_authorization_url( array $data, string $challenge ): void {
		$url = is_scalar( $data['authorization_url'] ?? null ) ? (string) $data['authorization_url'] : '';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Validating a fixed external OAuth origin before redirect.
		$parts = parse_url( $url );
		if ( ! $this->is_cloudflare_authorization_url( $parts ) ) {
			throw new \RuntimeException( esc_html__( 'Cybermaps Connect returned an unsafe Cloudflare authorization URL.', 'cybermaps' ) );
		}
		$query = array();
		parse_str( (string) ( $parts['query'] ?? '' ), $query );
		if ( ! $this->authorization_query_matches( $data, $query, $challenge ) ) {
			throw new \RuntimeException( esc_html__( 'Cybermaps Connect returned mismatched OAuth transaction metadata.', 'cybermaps' ) );
		}
	}

	/** @param array<string,mixed>|false $parts */
	private function is_cloudflare_authorization_url( array|false $parts ): bool {
		return is_array( $parts )
			&& 'https' === ( $parts['scheme'] ?? '' )
			// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Documented Cloudflare OAuth service endpoint/origin validation; no remote assets are loaded.
			&& 'dash.cloudflare.com' === ( $parts['host'] ?? '' )
			&& '/oauth2/auth' === ( $parts['path'] ?? '' );
	}

	/** @param array<string,mixed> $data @param array<string,mixed> $query */
	private function authorization_query_matches( array $data, array $query, string $challenge ): bool {
		return isset( $query['state'], $query['client_id'], $query['redirect_uri'], $query['code_challenge'] )
			&& hash_equals( (string) $data['state'], (string) $query['state'] )
			&& hash_equals( (string) $data['client_id'], (string) $query['client_id'] )
			&& hash_equals( (string) $data['redirect_uri'], (string) $query['redirect_uri'] )
			&& hash_equals( $challenge, (string) $query['code_challenge'] )
			&& hash_equals( self::REQUIRED_SCOPES, (string) ( $query['scope'] ?? '' ) )
			&& 'S256' === (string) ( $query['code_challenge_method'] ?? '' )
			&& 'code' === (string) ( $query['response_type'] ?? '' );
	}

	private function valid_client_id( string $client_id ): bool {
		return self::is_valid_client_id( $client_id );
	}

	private function valid_redirect_uri( string $redirect_uri ): bool {
		return hash_equals( $this->relay_base() . '/cloudflare/callback', $redirect_uri )
			|| hash_equals( EdgeOptimizationController::oauth_callback_url(), $redirect_uri );
	}

	/** @param array<string,mixed> $body @param array<string,string> $headers @return array<string,mixed> */
	private function post_json( string $url, array $body, array $headers = array() ): array|\WP_Error {
		$encoded = wp_json_encode( $body, JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $encoded ) ) {
			throw new \RuntimeException( esc_html__( 'Cybermaps could not encode the OAuth request.', 'cybermaps' ) );
		}
		return $this->post(
			$url,
			array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			) + $headers,
			$encoded
		);
	}

	/** @param array<string,string> $body @return array<string,mixed> */
	private function post_form( string $url, array $body ): array|\WP_Error {
		return $this->post(
			$url,
			array(
				'Content-Type' => 'application/x-www-form-urlencoded',
				'Accept'       => 'application/json',
			),
			$body
		);
	}

	/** @param array<string,string> $headers @param array<string,string>|string $body @return array<string,mixed> */
	private function post( string $url, array $headers, array|string $body ): array|\WP_Error {
		$args     = array(
			'timeout'             => 12,
			'redirection'         => 0,
			'reject_unsafe_urls'  => true,
			'limit_response_size' => self::MAX_RESPONSE_BYTES,
			'headers'             => $headers,
			'body'                => $body,
		);
		$response = null !== $this->transport
			? ( $this->transport )( $url, $args )
			: wp_safe_remote_post( $url, $args );
		return is_array( $response ) || is_wp_error( $response ) ? $response : array();
	}

	/** @param array<string,mixed>|\WP_Error $response @return array<string,mixed> */
	private function json_response( array|\WP_Error $response, string $fallback ): array {
		$this->require_success( $response, $fallback );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) ) {
			throw new \RuntimeException( esc_html( $fallback ) );
		}
		return $decoded;
	}

	/** @param array<string,mixed>|\WP_Error $response */
	private function require_success( array|\WP_Error $response, string $fallback ): void {
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( esc_html__( 'The Cloudflare authorization service could not be reached securely.', 'cybermaps' ) );
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			throw new \RuntimeException( sprintf( /* translators: 1: HTTP status code, 2: operation description. */ esc_html__( 'Cloudflare authorization error %1$d: %2$s', 'cybermaps' ), absint( $code ), esc_html( $fallback ) ) );
		}
	}

	private static function base64url( string $bytes ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- RFC 7636 requires base64url encoding for the PKCE challenge.
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}
}
