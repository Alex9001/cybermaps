<?php
declare(strict_types=1);

namespace Cybermaps\MCP\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** OAuth 2.1 authorization-code, PKCE, opaque-token, and metadata service. */
final class OAuthService {
	private const AUTHORIZATION_CODE_TTL = 300;
	private const ACCESS_TOKEN_TTL       = 900;
	private const REFRESH_TOKEN_TTL      = 2592000;
	private const DEVICE_CODE_TTL        = 600;
	private const DEVICE_POLL_INTERVAL   = 5;
	private const DEVICE_MAX_INTERVAL    = 60;
	public const DEVICE_GRANT_TYPE       = 'urn:ietf:params:oauth:grant-type:device_code';

	/** @var callable():int */
	private $clock;
	private string $device_code_pepper;

	public function __construct(
		private readonly OAuthRepository $repository,
		private readonly UserAuthorizer $users,
		private readonly ClientRegistrationValidator $clients,
		private readonly string $issuer,
		private readonly string $protected_resource,
		private readonly string $authorization_endpoint,
		private readonly string $token_endpoint,
		private readonly string $revocation_endpoint,
		?callable $clock = null,
		string $device_code_pepper = ''
	) {
		$this->clock              = $clock ?? static fn(): int => time();
		$this->device_code_pepper = '' !== $device_code_pepper
			? $device_code_pepper
			: ( \function_exists( 'wp_salt' ) ? \wp_salt( 'auth' ) : hash( 'sha256', $issuer ) );
	}

	/** @param string[] $scopes */
	public function register_administrator_client( string $client_id, string $client_name, array $redirect_uris, array $scopes, string $metadata_uri = '' ): void {
		$this->repository->save_client( $this->clients->validate_administrator_client( $client_id, $client_name, $redirect_uris, $scopes, $metadata_uri ) );
	}

	public function register_preapproved_metadata_client( string $client_id, string $metadata_uri ): void {
		$this->repository->save_client( $this->clients->validate_preapproved_metadata_document( $client_id, $metadata_uri ) );
	}

	/**
	 * Begin an RFC 8628 authorization using an HTTPS Client ID Metadata Document.
	 *
	 * @param array<string,mixed> $request Device authorization request.
	 * @return array<string,mixed>
	 */
	public function begin_device_authorization( array $request, string $verification_uri ): array {
		$client   = $this->user_claimed_client( (string) ( $request['client_id'] ?? '' ) );
		$scopes   = $this->requested_authorization_scopes( $client, (string) ( $request['scope'] ?? '' ) );
		$audience = $this->requested_audience( $request );
		$now      = $this->now();
		$this->repository->delete_expired_device_authorizations( $now );

		$device_code = $this->random_token();
		$user_code   = $this->random_user_code();
		$this->repository->save_device_authorization(
			array(
				'device_code_hash' => $this->hash_secret( $device_code ),
				'user_code_hash'   => $this->hash_user_code( $user_code ),
				'client_id'        => (string) $client['client_id'],
				'user_id'          => 0,
				'scopes'           => $scopes,
				'audience'         => $audience,
				'status'           => 'pending',
				'expires_at'       => $now + self::DEVICE_CODE_TTL,
				'interval'         => self::DEVICE_POLL_INTERVAL,
				'last_poll_at'     => null,
				'created_at'       => $now,
			)
		);

		return array(
			'device_code'               => $device_code,
			'user_code'                 => $user_code,
			'verification_uri'          => $verification_uri,
			'verification_uri_complete' => add_query_arg( 'user_code', rawurlencode( $user_code ), $verification_uri ),
			'expires_in'                => self::DEVICE_CODE_TTL,
			'interval'                  => self::DEVICE_POLL_INTERVAL,
		);
	}

	/** @return array<string,mixed> */
	public function device_authorization_details( string $user_code ): array {
		$record = $this->pending_device_record( $user_code );
		$client = $this->required_client( (string) $record['client_id'] );
		return array(
			'client'     => array(
				'client_id'   => (string) $client['client_id'],
				'client_name' => (string) $client['client_name'],
			),
			'scopes'     => (array) $record['scopes'],
			'expires_in' => max( 0, (int) $record['expires_at'] - $this->now() ),
		);
	}

	public function decide_device_authorization( string $user_code, int $user_id, bool $approved ): void {
		$record = $this->pending_device_record( $user_code );
		if ( $approved ) {
			$this->users->assert_scopes_allowed( $user_id, (array) $record['scopes'] );
		}
		$now    = $this->now();
		$status = $approved ? 'approved' : 'denied';
		if ( ! $this->repository->decide_device_authorization( $this->hash_user_code( $this->normalize_user_code( $user_code ) ), $user_id, $status, $now ) ) {
			throw new OAuthException( 'invalid_request', 'The device authorization is no longer pending.' );
		}
		if ( $approved ) {
			$this->repository->save_grant( (string) $record['client_id'], $user_id, (array) $record['scopes'], $now );
		}
	}

	/**
	 * Validate an authorization request before rendering a consent screen.
	 *
	 * @param array<string, mixed> $request
	 * @return array{client:array<string,mixed>,redirect_uri:string,state:string,scopes:string[]}
	 */
	public function authorization_request_details( array $request ): array {
		if ( 'code' !== (string) ( $request['response_type'] ?? '' ) ) {
			throw new OAuthException( 'unsupported_response_type', 'Only the authorization-code response type is supported.' );
		}
		$client       = $this->required_client( (string) ( $request['client_id'] ?? '' ) );
		$redirect_uri = (string) ( $request['redirect_uri'] ?? '' );
		$this->clients->assert_registered_redirect_uri( $client, $redirect_uri );
		$challenge = (string) ( $request['code_challenge'] ?? '' );
		if ( 'S256' !== (string) ( $request['code_challenge_method'] ?? '' ) || ! preg_match( '/^[A-Za-z0-9_-]{43,128}$/D', $challenge ) ) {
			throw new OAuthException( 'invalid_request', 'PKCE S256 is required.' );
		}
		$this->requested_audience( $request );

		return array(
			'client'       => $client,
			'redirect_uri' => $redirect_uri,
			'state'        => (string) ( $request['state'] ?? '' ),
			'scopes'       => $this->requested_authorization_scopes( $client, (string) ( $request['scope'] ?? '' ) ),
		);
	}

	/**
	 * Issue an authorization code after WordPress login and explicit consent.
	 *
	 * @param array<string, mixed> $request
	 * @param string[]             $consented_scopes
	 * @return array{code:string,redirect_uri:string,state:string}
	 */
	public function authorize( array $request, int $user_id, array $consented_scopes ): array {
		$details      = $this->authorization_request_details( $request );
		$client       = $details['client'];
		$redirect_uri = $details['redirect_uri'];
		$challenge    = (string) $request['code_challenge'];
		$audience     = $this->requested_audience( $request );
		$scopes       = $this->approved_scopes( $details['scopes'], $consented_scopes );
		$this->users->assert_scopes_allowed( $user_id, $scopes );

		$now  = time();
		$code = $this->random_token();
		$this->repository->save_grant( (string) $client['client_id'], $user_id, $scopes, $now );
		$this->repository->save_authorization_code(
			array(
				'code_hash'      => $this->hash_secret( $code ),
				'client_id'      => (string) $client['client_id'],
				'user_id'        => $user_id,
				'redirect_uri'   => $redirect_uri,
				'code_challenge' => $challenge,
				'scopes'         => $scopes,
				'audience'       => $audience,
				'expires_at'     => $now + self::AUTHORIZATION_CODE_TTL,
				'created_at'     => $now,
			)
		);

		return array(
			'code'         => $code,
			'redirect_uri' => $redirect_uri,
			'state'        => $details['state'],
		);
	}

	/** @param array<string, mixed> $request @return array<string, mixed> */
	public function issue_tokens( array $request ): array {
		$grant_type = (string) ( $request['grant_type'] ?? '' );
		if ( 'authorization_code' === $grant_type ) {
			return $this->exchange_authorization_code( $request );
		}
		if ( 'refresh_token' === $grant_type ) {
			return $this->exchange_refresh_token( $request );
		}
		if ( self::DEVICE_GRANT_TYPE === $grant_type ) {
			return $this->exchange_device_code( $request );
		}
		throw new OAuthException( 'unsupported_grant_type', 'The grant type is not supported.' );
	}

	/**
	 * Verify a bearer header. Query-string access tokens are intentionally not accepted.
	 *
	 * @param string[] $required_scopes
	 * @return array<string, mixed>
	 */
	public function validate_bearer_header( ?string $authorization_header, string $audience, array $required_scopes = array() ): array {
		if ( ! preg_match( '/^Bearer ([A-Za-z0-9_-]{32,512})$/D', (string) $authorization_header, $matches ) ) {
			throw new OAuthException( 'invalid_token', 'A Bearer authorization header is required.', 401 );
		}
		$token = $this->repository->find_token( $this->hash_secret( $matches[1] ) );
		if ( null === $token || 'access' !== $token['token_type'] || null !== $token['revoked_at'] || (int) $token['expires_at'] <= time() ) {
			throw new OAuthException( 'invalid_token', 'The access token is invalid or expired.', 401 );
		}
		if ( ! hash_equals( (string) $token['audience'], $audience ) ) {
			throw new OAuthException( 'invalid_token', 'The access token was issued for another resource.', 401 );
		}
		$required_scopes = empty( $required_scopes ) ? array() : $this->clients->normalize_scopes( $required_scopes );
		if ( array_diff( $required_scopes, (array) $token['scopes'] ) ) {
			throw new OAuthException( 'insufficient_scope', 'The access token does not grant the required scope.', 403 );
		}
		$this->users->assert_scopes_allowed( (int) $token['user_id'], $required_scopes );
		return $token;
	}

	/** OAuth revocation returns successfully for unknown or other-client tokens. */
	public function revoke( string $client_id, string $token_value ): void {
		$token_hash = $this->hash_secret( $token_value );
		$token      = $this->repository->find_token( $token_hash );
		if ( null === $token || ! hash_equals( (string) $token['client_id'], $client_id ) ) {
			return;
		}
		if ( 'refresh' === $token['token_type'] ) {
			$this->repository->revoke_family( (string) $token['family_id'], time() );
			return;
		}
		$this->repository->revoke_token( $token_hash, time() );
	}

	/** @return array<string, mixed> */
	public function authorization_server_metadata(): array {
		return array(
			'issuer'                                => $this->issuer,
			'authorization_endpoint'                => $this->authorization_endpoint,
			'token_endpoint'                        => $this->token_endpoint,
			'revocation_endpoint'                   => $this->revocation_endpoint,
			'response_types_supported'              => array( 'code' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'token_endpoint_auth_methods_supported' => array( 'none' ),
			'scopes_supported'                      => array( 'cybermaps:read', 'cybermaps:audit', 'cybermaps:publish', 'cybermaps:purge', 'cybermaps:abilities:execute' ),
		);
	}

	/** @return array<string, mixed> */
	public function protected_resource_metadata(): array {
		return array(
			'resource'                 => $this->protected_resource,
			'authorization_servers'    => array( $this->issuer ),
			'bearer_methods_supported' => array( 'header' ),
			'scopes_supported'         => array( 'cybermaps:read', 'cybermaps:audit', 'cybermaps:publish', 'cybermaps:purge', 'cybermaps:abilities:execute' ),
		);
	}

	/** @param array<string, mixed> $request @return array<string, mixed> */
	private function exchange_authorization_code( array $request ): array {
		$client   = $this->required_client( (string) ( $request['client_id'] ?? '' ) );
		$code     = (string) ( $request['code'] ?? '' );
		$verifier = (string) ( $request['code_verifier'] ?? '' );
		if ( ! preg_match( '/^[A-Za-z0-9-._~]{43,128}$/D', $verifier ) ) {
			throw new OAuthException( 'invalid_grant', 'The PKCE code verifier is invalid.' );
		}
		$code_hash = $this->hash_secret( $code );
		$record    = $this->repository->find_authorization_code( $code_hash );
		if ( null === $record || ! hash_equals( (string) $record['client_id'], (string) $client['client_id'] ) || ! hash_equals( (string) $record['redirect_uri'], (string) ( $request['redirect_uri'] ?? '' ) ) ) {
			throw new OAuthException( 'invalid_grant', 'The authorization code is invalid, expired, or already used.' );
		}
		$record = $this->repository->consume_authorization_code( $code_hash, time() );
		if ( null === $record ) {
			throw new OAuthException( 'invalid_grant', 'The authorization code is invalid, expired, or already used.' );
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- RFC 7636 S256 base64url encoding.
		$calculated = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
		if ( ! hash_equals( (string) $record['code_challenge'], $calculated ) ) {
			throw new OAuthException( 'invalid_grant', 'The PKCE code verifier does not match.' );
		}
		$this->users->assert_scopes_allowed( (int) $record['user_id'], (array) $record['scopes'] );
		return $this->mint_token_pair( $record, (array) $record['scopes'], '' );
	}

	/** @param array<string, mixed> $request @return array<string, mixed> */
	private function exchange_refresh_token( array $request ): array {
		$client     = $this->required_client( (string) ( $request['client_id'] ?? '' ) );
		$token_hash = $this->hash_secret( (string) ( $request['refresh_token'] ?? '' ) );
		$existing   = $this->repository->find_token( $token_hash );
		if ( null === $existing || 'refresh' !== $existing['token_type'] || ! hash_equals( (string) $existing['client_id'], (string) $client['client_id'] ) ) {
			throw new OAuthException( 'invalid_grant', 'The refresh token is invalid.' );
		}
		$result = $this->repository->consume_refresh_token( $token_hash, time() );
		if ( 'replayed' === $result['status'] && is_array( $result['token'] ) ) {
			$this->repository->revoke_family( (string) $result['token']['family_id'], time() );
		}
		if ( 'active' !== $result['status'] || ! is_array( $result['token'] ) ) {
			throw new OAuthException( 'invalid_grant', 'The refresh token is invalid, expired, or already used.' );
		}
		$token  = $result['token'];
		$scopes = $this->requested_refresh_scopes( (array) $token['scopes'], (string) ( $request['scope'] ?? '' ) );
		$this->users->assert_scopes_allowed( (int) $token['user_id'], $scopes );
		return $this->mint_token_pair( $token, $scopes, (string) $token['family_id'] );
	}

	/** @param array<string,mixed> $request @return array<string,mixed> */
	private function exchange_device_code( array $request ): array {
		$client      = $this->required_client( (string) ( $request['client_id'] ?? '' ) );
		$device_code = (string) ( $request['device_code'] ?? '' );
		if ( ! preg_match( '/^[A-Za-z0-9_-]{32,128}$/D', $device_code ) ) {
			throw new OAuthException( 'invalid_grant', 'The device code is invalid.' );
		}
		$code_hash = $this->hash_secret( $device_code );
		$record    = $this->repository->find_device_authorization( $code_hash );
		if ( ! is_array( $record ) || ! hash_equals( (string) $record['client_id'], (string) $client['client_id'] ) ) {
			throw new OAuthException( 'invalid_grant', 'The device code is invalid.' );
		}

		$now = $this->now();
		if ( (int) $record['expires_at'] <= $now ) {
			$this->repository->delete_expired_device_authorizations( $now );
			throw new OAuthException( 'expired_token', 'The device code expired.' );
		}
		if ( 'pending' === $record['status'] ) {
			$this->throw_device_poll_error( $code_hash, $record, $now );
		}
		if ( 'denied' === $record['status'] ) {
			throw new OAuthException( 'access_denied', 'The resource owner denied the request.' );
		}
		if ( 'approved' !== $record['status'] ) {
			throw new OAuthException( 'invalid_grant', 'The device code was already used.' );
		}
		$record = $this->repository->consume_device_authorization( $code_hash, $now );
		if ( ! is_array( $record ) ) {
			throw new OAuthException( 'invalid_grant', 'The device code was already used.' );
		}
		$this->users->assert_scopes_allowed( (int) $record['user_id'], (array) $record['scopes'] );
		return $this->mint_token_pair( $record, (array) $record['scopes'], '' );
	}

	/** @param array<string,mixed> $record */
	private function throw_device_poll_error( string $code_hash, array $record, int $now ): never {
		$interval = max( self::DEVICE_POLL_INTERVAL, min( self::DEVICE_MAX_INTERVAL, (int) $record['interval'] ) );
		$last     = (int) ( $record['last_poll_at'] ?? 0 );
		$slow     = $last > 0 && $now < $last + $interval;
		$interval = $slow ? min( self::DEVICE_MAX_INTERVAL, $interval + 5 ) : $interval;
		$this->repository->update_device_poll( $code_hash, $now, $interval );
		throw new OAuthException(
			$slow ? 'slow_down' : 'authorization_pending',
			$slow ? 'The client is polling faster than the current interval.' : 'The resource owner has not completed authorization.'
		);
	}

	/** @param array<string, mixed> $record @param string[] $scopes @return array<string, mixed> */
	private function mint_token_pair( array $record, array $scopes, string $family_id ): array {
		$now       = time();
		$family_id = '' === $family_id ? bin2hex( random_bytes( 32 ) ) : $family_id;
		$access    = $this->random_token();
		$refresh   = $this->random_token();
		foreach (
			array(
				array(
					'token_hash' => $this->hash_secret( $access ),
					'token_type' => 'access',
					'expires_at' => $now + self::ACCESS_TOKEN_TTL,
				),
				array(
					'token_hash' => $this->hash_secret( $refresh ),
					'token_type' => 'refresh',
					'expires_at' => $now + self::REFRESH_TOKEN_TTL,
				),
			) as $token
		) {
			$this->repository->save_token(
				array_merge(
					$token,
					array(
						'family_id'  => $family_id,
						'client_id'  => (string) $record['client_id'],
						'user_id'    => (int) $record['user_id'],
						'scopes'     => $scopes,
						'audience'   => (string) $record['audience'],
						'created_at' => $now,
					)
				)
			);
		}

		return array(
			'access_token'  => $access,
			'token_type'    => 'Bearer',
			'expires_in'    => self::ACCESS_TOKEN_TTL,
			'refresh_token' => $refresh,
			'scope'         => implode( ' ', $scopes ),
			'resource'      => (string) $record['audience'],
		);
	}

	/** @return array<string, mixed> */
	private function required_client( string $client_id ): array {
		$client = $this->repository->find_client( $client_id );
		if ( null === $client ) {
			throw new OAuthException( 'invalid_client', 'The OAuth client is not registered.', 401 );
		}
		return $client;
	}

	/** @return array<string,mixed> */
	private function user_claimed_client( string $client_id ): array {
		$parts = \wp_parse_url( $client_id );
		if ( '' === $client_id || strlen( $client_id ) > 191 || ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) ) {
			throw new OAuthException( 'invalid_client', 'The client ID must be an HTTPS Client ID Metadata Document URL.', 401 );
		}
		$client = $this->repository->find_client( $client_id );
		if ( null === $client ) {
			$this->register_preapproved_metadata_client( $client_id, $client_id );
			$client = $this->required_client( $client_id );
		}
		if ( ! hash_equals( $client_id, (string) ( $client['metadata_uri'] ?? '' ) ) ) {
			throw new OAuthException( 'unauthorized_client', 'The client is not registered through its Client ID Metadata Document.', 401 );
		}
		return $client;
	}

	/** @return array<string,mixed> */
	private function pending_device_record( string $user_code ): array {
		$user_code = $this->normalize_user_code( $user_code );
		$record    = $this->repository->find_device_authorization_by_user_code( $this->hash_user_code( $user_code ) );
		if ( ! is_array( $record ) ) {
			throw new OAuthException( 'invalid_request', 'The user code is invalid.' );
		}
		if ( (int) $record['expires_at'] <= $this->now() ) {
			throw new OAuthException( 'expired_token', 'The user code expired.' );
		}
		if ( 'pending' !== $record['status'] ) {
			throw new OAuthException( 'invalid_request', 'The device authorization was already decided.' );
		}
		return $record;
	}

	/** @param array<string, mixed> $request */
	private function requested_audience( array $request ): string {
		$requested = (string) ( $request['resource'] ?? $this->protected_resource );
		if ( ! hash_equals( $this->protected_resource, $requested ) ) {
			throw new OAuthException( 'invalid_target', 'The requested resource is not served by Cybermaps.' );
		}
		return $requested;
	}

	/** @param array<string, mixed> $client @return string[] */
	private function requested_authorization_scopes( array $client, string $requested_scope ): array {
		$requested = '' === trim( $requested_scope ) ? (array) $client['scopes'] : preg_split( '/\s+/', trim( $requested_scope ) );
		$requested = $this->clients->normalize_scopes( is_array( $requested ) ? $requested : array() );
		if ( array_diff( $requested, (array) $client['scopes'] ) ) {
			throw new OAuthException( 'invalid_scope', 'The requested scope is not approved for this client.' );
		}
		return $requested;
	}

	/** @param string[] $requested @param string[] $consented @return string[] */
	private function approved_scopes( array $requested, array $consented ): array {
		$consented = $this->clients->normalize_scopes( $consented );
		if ( array_diff( $requested, $consented ) ) {
			throw new OAuthException( 'invalid_scope', 'The requested scope was not approved for this authorization.' );
		}
		return $requested;
	}

	/** @param string[] $existing @return string[] */
	private function requested_refresh_scopes( array $existing, string $requested_scope ): array {
		if ( '' === trim( $requested_scope ) ) {
			return $existing;
		}
		$requested = preg_split( '/\s+/', trim( $requested_scope ) );
		$requested = $this->clients->normalize_scopes( is_array( $requested ) ? $requested : array() );
		if ( array_diff( $requested, $existing ) ) {
			throw new OAuthException( 'invalid_scope', 'A refresh token cannot expand its scope.' );
		}
		return $requested;
	}

	private function random_token(): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Opaque token base64url encoding.
		return rtrim( strtr( base64_encode( random_bytes( 48 ) ), '+/', '-_' ), '=' );
	}

	private function random_user_code(): string {
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$code     = '';
		for ( $index = 0; $index < 8; ++$index ) {
			$code .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
		}
		return substr( $code, 0, 4 ) . '-' . substr( $code, 4 );
	}

	private function normalize_user_code( string $user_code ): string {
		$user_code = strtoupper( trim( $user_code ) );
		if ( ! preg_match( '/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4}$/D', $user_code ) ) {
			throw new OAuthException( 'invalid_request', 'The user code is invalid.' );
		}
		return $user_code;
	}

	private function hash_user_code( string $user_code ): string {
		return hash_hmac( 'sha256', $user_code, $this->device_code_pepper );
	}

	private function now(): int {
		return (int) call_user_func( $this->clock );
	}

	private function hash_secret( string $secret ): string {
		return hash( 'sha256', $secret );
	}
}
