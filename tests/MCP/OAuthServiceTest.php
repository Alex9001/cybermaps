<?php
declare(strict_types=1);

namespace Cybermaps\Tests\MCP;

use Cybermaps\MCP\OAuth\ClientRegistrationValidator;
use Cybermaps\MCP\OAuth\OAuthException;
use Cybermaps\MCP\OAuth\OAuthRepository;
use Cybermaps\MCP\OAuth\OAuthRouteController;
use Cybermaps\MCP\OAuth\OAuthService;
use Cybermaps\MCP\OAuth\UserAuthorizer;
use PHPUnit\Framework\TestCase;

final class OAuthServiceTest extends TestCase {
	private OAuthService $service;
	private InMemoryOAuthRepository $repository;
	private mixed $previous_wpdb = null;

	protected function setUp(): void {
		parent::setUp();
		global $wpdb;
		$this->previous_wpdb = $wpdb ?? null;
		$wpdb                = new OAuthOneTimeStateWpdb();
		$GLOBALS['cybermaps_mock_transients'] = array();
		$this->repository = new InMemoryOAuthRepository();
		$this->service = new OAuthService(
			$this->repository,
			new AllowingOAuthUserAuthorizer(),
			new ClientRegistrationValidator(),
			'https://example.com',
			'https://example.com/wp-json/cybermaps/v1/mcp',
			'https://example.com/wp-json/cybermaps/v1/oauth/authorize',
			'https://example.com/wp-json/cybermaps/v1/oauth/token',
			'https://example.com/wp-json/cybermaps/v1/oauth/revoke'
		);
		$this->service->register_administrator_client(
			'client-one',
			'Example MCP client',
			array( 'https://client.example/callback' ),
			array( 'cybermaps:read', 'cybermaps:audit' )
		);
	}

	protected function tearDown(): void {
		global $wpdb;
		$wpdb = $this->previous_wpdb;
		parent::tearDown();
	}

	public function test_authorization_code_pkce_exchange_and_bearer_validation(): void {
		$verifier = str_repeat( 'a', 43 );
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
		$authorization = $this->service->authorize(
			array(
				'response_type'         => 'code',
				'client_id'             => 'client-one',
				'redirect_uri'          => 'https://client.example/callback',
				'code_challenge'        => $challenge,
				'code_challenge_method' => 'S256',
				'scope'                 => 'cybermaps:read',
				'state'                 => 'csrf-client-state',
			),
			21,
			array( 'cybermaps:read' )
		);

		$this->assertSame( 'csrf-client-state', $authorization['state'] );
		$tokens = $this->service->issue_tokens(
			array(
				'grant_type'    => 'authorization_code',
				'client_id'     => 'client-one',
				'code'          => $authorization['code'],
				'redirect_uri'  => 'https://client.example/callback',
				'code_verifier' => $verifier,
			)
		);

		$this->assertSame( 900, $tokens['expires_in'] );
		$this->assertSame( 'cybermaps:read', $tokens['scope'] );
		$this->assertSame(
			21,
			$this->service->validate_bearer_header(
				'Bearer ' . $tokens['access_token'],
				'https://example.com/wp-json/cybermaps/v1/mcp',
				array( 'cybermaps:read' )
			)['user_id']
		);

		$this->expectException( OAuthException::class );
		$this->service->issue_tokens(
			array(
				'grant_type'    => 'authorization_code',
				'client_id'     => 'client-one',
				'code'          => $authorization['code'],
				'redirect_uri'  => 'https://client.example/callback',
				'code_verifier' => $verifier,
			)
		);
	}

	public function test_stale_atomic_consent_row_cannot_be_replayed(): void {
		$controller = new OAuthRouteController( $this->service, static fn(): int => 21 );
		$verifier = str_repeat( 'd', 43 );
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
		$started = $controller->begin_authorization(
			array(
				'response_type'         => 'code',
				'client_id'             => 'client-one',
				'redirect_uri'          => 'https://client.example/callback',
				'code_challenge'        => $challenge,
				'code_challenge_method' => 'S256',
				'scope'                 => 'cybermaps:read',
			)
		)->get_data();
		$GLOBALS['cybermaps_mock_transients'] = array();

		$first = $controller->complete_authorization(
			array(
				'consent_id' => $started['consent_id'],
				'nonce'      => $started['nonce'],
				'approved'   => '1',
				'scopes'     => array( 'cybermaps:read' ),
			)
		)->get_data();
		$second = $controller->complete_authorization(
			array(
				'consent_id' => $started['consent_id'],
				'nonce'      => $started['nonce'],
				'approved'   => '1',
				'scopes'     => array( 'cybermaps:read' ),
			)
		)->get_data();

		$this->assertArrayHasKey( 'redirect_uri', $first );
		$this->assertSame( 'invalid_request', $second['error'] );
	}

	public function test_refresh_replay_revokes_the_entire_token_family(): void {
		$tokens = $this->authorize_and_exchange();
		$rotated = $this->service->issue_tokens(
			array(
				'grant_type'    => 'refresh_token',
				'client_id'     => 'client-one',
				'refresh_token' => $tokens['refresh_token'],
			)
		);

		try {
			$this->service->issue_tokens(
				array(
					'grant_type'    => 'refresh_token',
					'client_id'     => 'client-one',
					'refresh_token' => $tokens['refresh_token'],
				)
			);
			self::fail( 'Refresh-token replay must fail.' );
		} catch ( OAuthException $exception ) {
			$this->assertSame( 'invalid_grant', $exception->to_error_response()['error'] );
		}

		$this->expectException( OAuthException::class );
		$this->service->validate_bearer_header(
			'Bearer ' . $rotated['access_token'],
			'https://example.com/wp-json/cybermaps/v1/mcp'
		);
	}

	public function test_metadata_and_redirects_are_strict(): void {
		$this->assertSame(
			array( 'S256' ),
			$this->service->authorization_server_metadata()['code_challenge_methods_supported']
		);
		$this->assertSame(
			array( 'header' ),
			$this->service->protected_resource_metadata()['bearer_methods_supported']
		);

		$this->expectException( OAuthException::class );
		$this->service->authorize(
			array(
				'response_type'         => 'code',
				'client_id'             => 'client-one',
				'redirect_uri'          => 'https://client.example/callback/',
				'code_challenge'        => str_repeat( 'a', 43 ),
				'code_challenge_method' => 'S256',
			),
			21,
			array( 'cybermaps:read' )
		);
	}

	public function test_root_well_known_metadata_urls_are_canonical(): void {
		$this->assertSame(
			'https://example.com/.well-known/oauth-authorization-server',
			OAuthRouteController::authorization_server_metadata_url()
		);
		$this->assertSame(
			'https://example.com/.well-known/oauth-protected-resource',
			OAuthRouteController::protected_resource_metadata_url()
		);
		$this->assertSame(
			'https://example.com/wp-json/cybermaps/v1/mcp',
			$this->service->protected_resource_metadata()['resource']
		);
	}

	public function test_authenticated_route_uses_one_time_nonce_bound_consent(): void {
		$controller = new OAuthRouteController( $this->service, static fn(): int => 21 );
		$verifier = str_repeat( 'c', 43 );
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
		$started = $controller->begin_authorization(
			array(
				'response_type'         => 'code',
				'client_id'             => 'client-one',
				'redirect_uri'          => 'https://client.example/callback',
				'code_challenge'        => $challenge,
				'code_challenge_method' => 'S256',
				'scope'                 => 'cybermaps:read',
				'state'                 => 'bound-state',
			)
		)->get_data();

		$completed = $controller->complete_authorization(
			array(
				'consent_id' => $started['consent_id'],
				'nonce'      => $started['nonce'],
				'approved'   => '1',
				'scopes'     => array( 'cybermaps:read' ),
			)
		)->get_data();

		$this->assertSame( 'https://client.example/callback', $completed['redirect_uri'] );
		$this->assertSame( 'bound-state', $completed['parameters']['state'] );
		$this->assertNotSame( '', $completed['parameters']['code'] );
		$this->assertSame(
			'invalid_request',
			$controller->complete_authorization(
				array(
					'consent_id' => $started['consent_id'],
					'nonce'      => $started['nonce'],
					'approved'   => '1',
				)
			)->get_data()['error']
		);
	}

	public function test_device_grant_stays_pending_slows_down_and_issues_only_after_approval(): void {
		$now       = 1700000000;
		$client_id = 'https://client.example/oauth-client.json';
		$this->repository->save_client( $this->metadata_client( $client_id ) );
		$service = $this->device_service( $now );
		$started = $service->begin_device_authorization(
			array(
				'client_id' => $client_id,
				'scope'     => 'cybermaps:read',
			),
			'https://example.com/cybermaps-agent-auth'
		);

		$this->assertMatchesRegularExpression( '/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $started['user_code'] );
		$this->assertSame( 600, $started['expires_in'] );
		$this->assertFalse( $this->repository->contains_plain_device_value( $started['device_code'], $started['user_code'] ) );
		$this->assertOAuthError(
			'authorization_pending',
			static fn() => $service->issue_tokens(
				array(
					'grant_type'  => OAuthService::DEVICE_GRANT_TYPE,
					'client_id'   => $client_id,
					'device_code' => $started['device_code'],
				)
			)
		);
		$this->assertOAuthError(
			'slow_down',
			static fn() => $service->issue_tokens(
				array(
					'grant_type'  => OAuthService::DEVICE_GRANT_TYPE,
					'client_id'   => $client_id,
					'device_code' => $started['device_code'],
				)
			)
		);

		$service->decide_device_authorization( $started['user_code'], 21, true );
		$tokens = $service->issue_tokens(
			array(
				'grant_type'  => OAuthService::DEVICE_GRANT_TYPE,
				'client_id'   => $client_id,
				'device_code' => $started['device_code'],
			)
		);
		$this->assertSame( 'cybermaps:read', $tokens['scope'] );
		$this->assertOAuthError(
			'invalid_grant',
			static fn() => $service->issue_tokens(
				array(
					'grant_type'  => OAuthService::DEVICE_GRANT_TYPE,
					'client_id'   => $client_id,
					'device_code' => $started['device_code'],
				)
			)
		);
	}

	public function test_device_denial_and_expiry_return_rfc_8628_errors(): void {
		$now       = 1700000000;
		$client_id = 'https://client.example/oauth-client.json';
		$this->repository->save_client( $this->metadata_client( $client_id ) );
		$service = $this->device_service( $now );
		$denied  = $service->begin_device_authorization( array( 'client_id' => $client_id ), 'https://example.com/cybermaps-agent-auth' );
		$service->decide_device_authorization( $denied['user_code'], 21, false );
		$this->assertOAuthError(
			'access_denied',
			static fn() => $service->issue_tokens( array( 'grant_type' => OAuthService::DEVICE_GRANT_TYPE, 'client_id' => $client_id, 'device_code' => $denied['device_code'] ) )
		);

		$expired = $service->begin_device_authorization( array( 'client_id' => $client_id ), 'https://example.com/cybermaps-agent-auth' );
		$now    += 601;
		$this->assertOAuthError(
			'expired_token',
			static fn() => $service->issue_tokens( array( 'grant_type' => OAuthService::DEVICE_GRANT_TYPE, 'client_id' => $client_id, 'device_code' => $expired['device_code'] ) )
		);
	}

	public function test_mode_aware_metadata_advertises_device_grant_only_when_enabled(): void {
		$enabled  = new OAuthRouteController( $this->service, null, null, static fn(): bool => true );
		$disabled = new OAuthRouteController( $this->service, null, null, static fn(): bool => false );
		$metadata = $enabled->authorization_server_metadata()->get_data();

		$this->assertSame( OAuthRouteController::device_authorization_endpoint_url(), $metadata['device_authorization_endpoint'] );
		$this->assertContains( OAuthService::DEVICE_GRANT_TYPE, $metadata['grant_types_supported'] );
		$this->assertArrayNotHasKey( 'device_authorization_endpoint', $disabled->authorization_server_metadata()->get_data() );
	}

	/** @return array<string, mixed> */
	private function authorize_and_exchange(): array {
		$verifier = str_repeat( 'b', 43 );
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
		$authorization = $this->service->authorize(
			array(
				'response_type'         => 'code',
				'client_id'             => 'client-one',
				'redirect_uri'          => 'https://client.example/callback',
				'code_challenge'        => $challenge,
				'code_challenge_method' => 'S256',
			),
			21,
			array( 'cybermaps:read', 'cybermaps:audit' )
		);
		return $this->service->issue_tokens(
			array(
				'grant_type'    => 'authorization_code',
				'client_id'     => 'client-one',
				'code'          => $authorization['code'],
				'redirect_uri'  => 'https://client.example/callback',
				'code_verifier' => $verifier,
			)
		);
	}

	private function device_service( int &$now ): OAuthService {
		return new OAuthService(
			$this->repository,
			new AllowingOAuthUserAuthorizer(),
			new ClientRegistrationValidator(),
			'https://example.com',
			'https://example.com/wp-json/cybermaps/v1/mcp',
			'https://example.com/wp-json/cybermaps/v1/oauth/authorize',
			'https://example.com/wp-json/cybermaps/v1/oauth/token',
			'https://example.com/wp-json/cybermaps/v1/oauth/revoke',
			static function () use ( &$now ): int {
				return $now;
			},
			'test-device-pepper'
		);
	}

	/** @return array<string,mixed> */
	private function metadata_client( string $client_id ): array {
		return array(
			'client_id'     => $client_id,
			'client_name'   => 'Claimed agent',
			'redirect_uris' => array( 'https://client.example/callback' ),
			'scopes'        => array( 'cybermaps:read' ),
			'metadata_uri'  => $client_id,
		);
	}

	private function assertOAuthError( string $expected, callable $callback ): void {
		try {
			$callback();
			self::fail( 'Expected OAuth error ' . $expected . '.' );
		} catch ( OAuthException $exception ) {
			$this->assertSame( $expected, $exception->to_error_response()['error'] );
		}
	}
}

final class OAuthOneTimeStateWpdb {
	public string $options = 'wp_options';
	public string $last_error = '';
	/** @var array<string,string> */
	public array $rows = array();

	/** @return array{query:string,args:array<int,mixed>} */
	public function prepare( string $query, mixed ...$args ): array {
		return array(
			'query' => $query,
			'args'  => $args,
		);
	}

	public function query( array|string $prepared ): int|false {
		$query = is_array( $prepared ) ? $prepared['query'] : $prepared;
		$args  = is_array( $prepared ) ? $prepared['args'] : array();
		if ( str_starts_with( $query, 'INSERT INTO' ) ) {
			$this->rows[ (string) $args[1] ] = (string) $args[2];
			return 1;
		}
		if ( str_starts_with( $query, 'DELETE FROM' ) ) {
			$option = (string) ( $args[1] ?? '' );
			if ( str_contains( $query, 'BINARY option_value' ) && ( $this->rows[ $option ] ?? null ) !== ( $args[2] ?? null ) ) {
				return 0;
			}
			if ( ! array_key_exists( $option, $this->rows ) ) {
				return 0;
			}
			unset( $this->rows[ $option ] );
			return 1;
		}
		return 0;
	}

	public function get_var( array|string $prepared ): ?string {
		$args = is_array( $prepared ) ? $prepared['args'] : array();
		return $this->rows[ (string) ( $args[1] ?? '' ) ] ?? null;
	}
}

final class AllowingOAuthUserAuthorizer implements UserAuthorizer {
	public function assert_scopes_allowed( int $user_id, array $scopes ): void {
		if ( $user_id < 1 || empty( $scopes ) ) {
			throw new OAuthException( 'access_denied', 'Test authorizer rejected the grant.' );
		}
	}
}

final class InMemoryOAuthRepository implements OAuthRepository {
	/** @var array<string, array<string, mixed>> */
	private array $clients = array();
	/** @var array<string, array<string, mixed>> */
	private array $codes = array();
	/** @var array<string, array<string, mixed>> */
	private array $tokens = array();
	/** @var array<string, array<string,mixed>> */
	private array $devices = array();

	public function save_client( array $client ): void {
		$this->clients[ (string) $client['client_id'] ] = $client;
	}

	public function find_client( string $client_id ): ?array {
		return $this->clients[ $client_id ] ?? null;
	}

	public function save_authorization_code( array $code ): void {
		$this->codes[ (string) $code['code_hash'] ] = $code + array( 'consumed_at' => null );
	}

	public function find_authorization_code( string $code_hash ): ?array {
		return $this->codes[ $code_hash ] ?? null;
	}

	public function consume_authorization_code( string $code_hash, int $now ): ?array {
		$code = $this->codes[ $code_hash ] ?? null;
		if ( ! is_array( $code ) || null !== $code['consumed_at'] || (int) $code['expires_at'] <= $now ) {
			return null;
		}
		$this->codes[ $code_hash ]['consumed_at'] = $now;
		return $code;
	}

	public function save_device_authorization( array $authorization ): void {
		$this->devices[ (string) $authorization['device_code_hash'] ] = $authorization;
	}

	public function find_device_authorization( string $device_code_hash ): ?array {
		return $this->devices[ $device_code_hash ] ?? null;
	}

	public function find_device_authorization_by_user_code( string $user_code_hash ): ?array {
		foreach ( $this->devices as $authorization ) {
			if ( hash_equals( (string) $authorization['user_code_hash'], $user_code_hash ) ) {
				return $authorization;
			}
		}
		return null;
	}

	public function update_device_poll( string $device_code_hash, int $polled_at, int $interval ): void {
		if ( isset( $this->devices[ $device_code_hash ] ) ) {
			$this->devices[ $device_code_hash ]['last_poll_at'] = $polled_at;
			$this->devices[ $device_code_hash ]['interval']     = $interval;
		}
	}

	public function decide_device_authorization( string $user_code_hash, int $user_id, string $status, int $now ): bool {
		foreach ( $this->devices as $hash => $authorization ) {
			if ( hash_equals( (string) $authorization['user_code_hash'], $user_code_hash ) && 'pending' === $authorization['status'] && (int) $authorization['expires_at'] > $now ) {
				$this->devices[ $hash ]['status']  = $status;
				$this->devices[ $hash ]['user_id'] = $user_id;
				return true;
			}
		}
		return false;
	}

	public function consume_device_authorization( string $device_code_hash, int $now ): ?array {
		$authorization = $this->devices[ $device_code_hash ] ?? null;
		if ( ! is_array( $authorization ) || 'approved' !== $authorization['status'] || (int) $authorization['expires_at'] <= $now ) {
			return null;
		}
		$this->devices[ $device_code_hash ]['status'] = 'consumed';
		return $authorization;
	}

	public function delete_expired_device_authorizations( int $now ): int {
		$deleted = 0;
		foreach ( $this->devices as $hash => $authorization ) {
			if ( (int) $authorization['expires_at'] <= $now || 'consumed' === $authorization['status'] ) {
				unset( $this->devices[ $hash ] );
				++$deleted;
			}
		}
		return $deleted;
	}

	public function contains_plain_device_value( string $device_code, string $user_code ): bool {
		return str_contains( serialize( $this->devices ), $device_code ) || str_contains( serialize( $this->devices ), $user_code );
	}

	public function save_grant( string $client_id, int $user_id, array $scopes, int $now ): void {
		unset( $client_id, $user_id, $scopes, $now );
	}

	public function save_token( array $token ): void {
		$this->tokens[ (string) $token['token_hash'] ] = $token + array( 'revoked_at' => null );
	}

	public function find_token( string $token_hash ): ?array {
		return $this->tokens[ $token_hash ] ?? null;
	}

	public function consume_refresh_token( string $token_hash, int $now ): array {
		$token = $this->tokens[ $token_hash ] ?? null;
		if ( ! is_array( $token ) || 'refresh' !== $token['token_type'] ) {
			return array( 'status' => 'unknown', 'token' => null );
		}
		if ( (int) $token['expires_at'] <= $now ) {
			return array( 'status' => 'expired', 'token' => $token );
		}
		if ( null !== $token['revoked_at'] ) {
			return array( 'status' => 'replayed', 'token' => $token );
		}
		$this->tokens[ $token_hash ]['revoked_at'] = $now;
		return array( 'status' => 'active', 'token' => $token );
	}

	public function revoke_token( string $token_hash, int $now ): void {
		if ( isset( $this->tokens[ $token_hash ] ) ) {
			$this->tokens[ $token_hash ]['revoked_at'] = $now;
		}
	}

	public function revoke_family( string $family_id, int $now ): void {
		foreach ( $this->tokens as $token_hash => $token ) {
			if ( $family_id === $token['family_id'] ) {
				$this->tokens[ $token_hash ]['revoked_at'] = $now;
			}
		}
	}

	public function revoke_client_tokens( string $client_id, int $now ): void {
		foreach ( $this->tokens as $token_hash => $token ) {
			if ( $client_id === $token['client_id'] ) {
				$this->tokens[ $token_hash ]['revoked_at'] = $now;
			}
		}
	}
}
