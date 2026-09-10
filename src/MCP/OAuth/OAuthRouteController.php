<?php
declare(strict_types=1);

namespace Cybermaps\MCP\OAuth;

use Cybermaps\Core\AtomicOneTimeStateStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST routes and browser-consent contract for the isolated OAuth server.
 *
 * The embedding UI renders consent, then redirects only with the exact URI and
 * parameters returned by the completion callback.
 */
final class OAuthRouteController {
	private const NAMESPACE                         = 'cybermaps/v1';
	private const CONSENT_SCOPE                     = 'mcp_oauth_consent';
	private const CONSENT_TTL                       = 600;
	public const AUTHORIZATION_SERVER_METADATA_PATH = '/.well-known/oauth-authorization-server';
	public const PROTECTED_RESOURCE_METADATA_PATH   = '/.well-known/oauth-protected-resource';

	/** @var callable():int */
	private $current_user_id;
	/** @var callable():bool */
	private $device_mode;

	/**
	 * @param callable():int|null $current_user_id Testable current-user reader.
	 */
	public function __construct( private readonly OAuthService $service, ?callable $current_user_id = null, private readonly ?AtomicOneTimeStateStore $consent_store = null, ?callable $device_mode = null ) {
		$this->current_user_id = $current_user_id ?? static fn(): int => (int) \get_current_user_id();
		$this->device_mode     = $device_mode ?? static fn(): bool => AgentRegistrationMode::is_user_claimed();
	}

	public function register_hooks(): void {
		\add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		\add_action( 'parse_request', array( $this, 'serve_well_known_metadata' ), 0 );
		\add_action( 'parse_request', array( $this, 'serve_agent_authorization_page' ), 1 );
	}

	/** Serve standards-discoverable OAuth metadata without relying on wp-json routes. */
	public function serve_well_known_metadata( mixed $wp = null ): void {
		unset( $wp );
		( new OAuthMetadataPublication( $this->service, $this->device_mode ) )->handle();
	}

	public static function authorization_server_metadata_url(): string {
		return home_url( self::AUTHORIZATION_SERVER_METADATA_PATH );
	}

	public static function protected_resource_metadata_url(): string {
		return home_url( self::PROTECTED_RESOURCE_METADATA_PATH );
	}

	public static function device_authorization_endpoint_url(): string {
		return rest_url( self::NAMESPACE . '/oauth/device-authorization' );
	}

	public static function agent_authorization_url(): string {
		return home_url( DeviceAuthorizationPage::PATH );
	}

	public function register_routes(): void {
		$this->register_route( '/oauth/authorization-server', 'GET', 'authorization_server_metadata', '__return_true' );
		$this->register_route( '/oauth/protected-resource', 'GET', 'protected_resource_metadata', '__return_true' );
		$this->register_route( '/oauth/authorize', 'GET', 'begin_authorization', '__return_true' );
		$this->register_route( '/oauth/authorize', 'POST', 'complete_authorization', '__return_true' );
		$this->register_route( '/oauth/token', 'POST', 'token', '__return_true' );
		$this->register_route( '/oauth/revoke', 'POST', 'revoke', '__return_true' );
		$this->register_route( '/oauth/device-authorization', 'POST', 'device_authorization', '__return_true' );
		$this->register_route( '/oauth/clients', 'POST', 'register_client', array( $this, 'check_administrator_permission' ) );
	}

	/** @return \WP_REST_Response */
	public function authorization_server_metadata(): \WP_REST_Response {
		return $this->response( $this->authorization_metadata() );
	}

	/** @return \WP_REST_Response */
	public function protected_resource_metadata(): \WP_REST_Response {
		return $this->response( ( new OAuthMetadataPublication( $this->service, $this->device_mode ) )->protected_resource_metadata() );
	}

	/** @return \WP_REST_Response */
	public function begin_authorization( $request ): \WP_REST_Response {
		$user_id = $this->authenticated_user_id();
		if ( $user_id < 1 ) {
			return $this->error_response( new OAuthException( 'login_required', 'WordPress login is required.', 401 ) );
		}

		try {
			$authorization_request = $this->authorization_request( $request );
			$details               = $this->service->authorization_request_details( $authorization_request );
			$consent_id            = bin2hex( random_bytes( 24 ) );
			if ( ! $this->consent_store()->put(
				self::CONSENT_SCOPE,
				$consent_id,
				array(
					'user_id' => $user_id,
					'request' => $authorization_request,
				),
				time() + self::CONSENT_TTL
			) ) {
				throw new OAuthException( 'server_error', 'The authorization consent request could not be stored.', 500 );
			}

			return $this->response(
				array(
					'consent_id' => $consent_id,
					'nonce'      => \wp_create_nonce( $this->consent_nonce_action( $consent_id ) ),
					'expires_in' => self::CONSENT_TTL,
					'client'     => array(
						'client_id'   => $details['client']['client_id'],
						'client_name' => $details['client']['client_name'],
					),
					'scopes'     => $details['scopes'],
				)
			);
		} catch ( OAuthException $exception ) {
			return $this->error_response( $exception );
		}
	}

	/** @return \WP_REST_Response */
	public function complete_authorization( $request ): \WP_REST_Response {
		$user_id    = $this->authenticated_user_id();
		$consent_id = $this->string_parameter( $request, 'consent_id', 96 );
		if ( $user_id < 1 || '' === $consent_id ) {
			return $this->error_response( new OAuthException( 'login_required', 'WordPress login is required.', 401 ) );
		}
		$nonce = $this->string_parameter( $request, 'nonce', 255 );
		if ( ! \wp_verify_nonce( $nonce, $this->consent_nonce_action( $consent_id ) ) ) {
			return $this->error_response( new OAuthException( 'invalid_request', 'The authorization consent nonce is invalid.', 403 ) );
		}
		$record = $this->consent_store()->take( self::CONSENT_SCOPE, $consent_id, time() );
		if ( ! is_array( $record ) || (int) ( $record['user_id'] ?? 0 ) !== $user_id || ! is_array( $record['request'] ?? null ) ) {
			return $this->error_response( new OAuthException( 'invalid_request', 'The authorization consent request expired.' ) );
		}

		try {
			$details = $this->service->authorization_request_details( $record['request'] );
			if ( '1' !== $this->string_parameter( $request, 'approved', 1 ) ) {
				return $this->response(
					$this->redirect_contract(
						$details['redirect_uri'],
						array(
							'error'             => 'access_denied',
							'error_description' => 'The resource owner denied the request.',
							'state'             => $details['state'],
						)
					)
				);
			}
			$authorization = $this->service->authorize( $record['request'], $user_id, $this->array_parameter( $request, 'scopes' ) );
			return $this->response(
				$this->redirect_contract(
					$authorization['redirect_uri'],
					array(
						'code'  => $authorization['code'],
						'state' => $authorization['state'],
					)
				)
			);
		} catch ( OAuthException $exception ) {
			return $this->error_response( $exception );
		}
	}

	/** @return \WP_REST_Response */
	public function token( $request ): \WP_REST_Response {
		$values = $this->request_values( $request );
		if ( OAuthService::DEVICE_GRANT_TYPE === (string) ( $values['grant_type'] ?? '' ) && ! $this->device_enabled() ) {
			return $this->error_response( new OAuthException( 'unauthorized_client', 'User-claimed agent authorization is disabled.', 403 ) );
		}
		try {
			return $this->response( $this->service->issue_tokens( $values ) );
		} catch ( OAuthException $exception ) {
			return $this->error_response( $exception );
		}
	}

	/** @return \WP_REST_Response */
	public function device_authorization( $request ): \WP_REST_Response {
		if ( ! $this->device_enabled() ) {
			return $this->error_response( new OAuthException( 'unauthorized_client', 'User-claimed agent authorization is disabled.', 403 ) );
		}
		try {
			return $this->response(
				$this->service->begin_device_authorization(
					array(
						'client_id' => $this->string_parameter( $request, 'client_id', 191 ),
						'scope'     => $this->string_parameter( $request, 'scope', 255 ),
						'resource'  => $this->string_parameter( $request, 'resource', 2048 ),
					),
					self::agent_authorization_url()
				)
			);
		} catch ( OAuthException $exception ) {
			return $this->error_response( $exception );
		}
	}

	public function serve_agent_authorization_page( mixed $wp = null ): void {
		( new DeviceAuthorizationPage( $this->service, $this->current_user_id, $this->device_enabled() ) )->maybe_serve( $wp );
	}

	/** @return \WP_REST_Response */
	public function revoke( $request ): \WP_REST_Response {
		try {
			$client_id = $this->string_parameter( $request, 'client_id', 191 );
			$token     = $this->string_parameter( $request, 'token', 512 );
			if ( '' === $client_id || '' === $token ) {
				throw new OAuthException( 'invalid_request', 'A client ID and token are required.' );
			}
			$this->service->revoke( $client_id, $token );
			return $this->response( array() );
		} catch ( OAuthException $exception ) {
			return $this->error_response( $exception );
		}
	}

	/** @return \WP_REST_Response */
	public function register_client( $request ): \WP_REST_Response {
		if ( ! $this->verify_rest_nonce( $request ) ) {
			return $this->error_response( new OAuthException( 'invalid_request', 'A valid WordPress REST nonce is required.', 403 ) );
		}
		try {
			$client_id    = $this->string_parameter( $request, 'client_id', 191 );
			$metadata_uri = $this->string_parameter( $request, 'metadata_uri', 2048 );
			if ( '' !== $metadata_uri ) {
				$this->service->register_preapproved_metadata_client( $client_id, $metadata_uri );
			} else {
				$this->service->register_administrator_client(
					$client_id,
					$this->string_parameter( $request, 'client_name', 191 ),
					$this->array_parameter( $request, 'redirect_uris' ),
					$this->array_parameter( $request, 'scopes' )
				);
			}
			return $this->response(
				array(
					'client_id'  => $client_id,
					'registered' => true,
				),
				201
			);
		} catch ( OAuthException $exception ) {
			return $this->error_response( $exception );
		}
	}

	public function check_administrator_permission(): bool {
		return \current_user_can( 'manage_options' );
	}

	private function register_route( string $route, string $methods, string $callback, $permission_callback ): void {
		\register_rest_route(
			self::NAMESPACE,
			$route,
			array(
				'methods'             => $methods,
				'callback'            => array( $this, $callback ),
				'permission_callback' => $permission_callback,
			)
		);
	}

	private function consent_store(): AtomicOneTimeStateStore {
		return $this->consent_store ?? new AtomicOneTimeStateStore();
	}

	/** @return array<string, string> */
	private function authorization_request( $request ): array {
		$values = array();
		foreach ( array( 'response_type', 'client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'scope', 'state', 'resource' ) as $key ) {
			$values[ $key ] = $this->string_parameter( $request, $key, 2048 );
		}
		if ( '' === $values['resource'] ) {
			unset( $values['resource'] );
		}
		return $values;
	}

	/** @return array<string, mixed> */
	private function request_values( $request ): array {
		if ( is_array( $request ) ) {
			return $request;
		}
		if ( is_object( $request ) && method_exists( $request, 'get_params' ) ) {
			$params = $request->get_params();
			return is_array( $params ) ? $params : array();
		}
		return array();
	}

	private function string_parameter( $request, string $key, int $maximum_length ): string {
		$values = $this->request_values( $request );
		$value  = $values[ $key ] ?? '';
		$value  = is_scalar( $value ) ? trim( (string) $value ) : '';
		return strlen( $value ) <= $maximum_length ? $value : '';
	}

	/** @return string[] */
	private function array_parameter( $request, string $key ): array {
		$values = $this->request_values( $request );
		$value  = $values[ $key ] ?? array();
		if ( is_string( $value ) ) {
			$value = preg_split( '/\s+/', trim( $value ) );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_values( array_filter( $value, 'is_string' ) );
	}

	private function authenticated_user_id(): int {
		return max( 0, (int) call_user_func( $this->current_user_id ) );
	}

	/** @return array<string,mixed> */
	private function authorization_metadata(): array {
		return ( new OAuthMetadataPublication( $this->service, $this->device_mode ) )->authorization_server_metadata();
	}

	private function device_enabled(): bool {
		return (bool) call_user_func( $this->device_mode );
	}

	private function verify_rest_nonce( $request ): bool {
		$nonce = $this->string_parameter( $request, '_wpnonce', 255 );
		if ( '' === $nonce && is_object( $request ) && method_exists( $request, 'get_header' ) ) {
			$nonce = (string) $request->get_header( 'X-WP-Nonce' );
		}
		return '' !== $nonce && \wp_verify_nonce( $nonce, 'wp_rest' );
	}

	/** @param array<string, string> $parameters @return array<string, mixed> */
	private function redirect_contract( string $redirect_uri, array $parameters ): array {
		return array(
			'redirect_uri' => $redirect_uri,
			'parameters'   => $parameters,
		);
	}

	/** @return \WP_REST_Response */
	private function response( array $data, int $status = 200 ): \WP_REST_Response {
		$response = new \WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/** @return \WP_REST_Response */
	private function error_response( OAuthException $exception ): \WP_REST_Response {
		return $this->response( $exception->to_error_response(), $exception->status_code() );
	}

	private function consent_nonce_action( string $consent_id ): string {
		return 'cybermaps_mcp_oauth_consent_' . $consent_id;
	}
}
