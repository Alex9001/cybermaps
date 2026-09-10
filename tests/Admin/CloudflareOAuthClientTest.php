<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\CloudflareOAuthClient;
use PHPUnit\Framework\TestCase;

final class CloudflareOAuthClientTest extends TestCase {
	public function test_pkce_verifier_and_challenge_are_url_safe(): void {
		$verifier  = CloudflareOAuthClient::generate_verifier();
		$challenge = CloudflareOAuthClient::challenge_for( $verifier );
		self::assertMatchesRegularExpression( '/\A[A-Za-z0-9._~-]{43,128}\z/', $verifier );
		self::assertMatchesRegularExpression( '/\A[A-Za-z0-9_-]{43}\z/', $challenge );
	}

	public function test_relay_transaction_is_validated_without_sending_the_verifier(): void {
		$challenge = str_repeat( 'a', 43 );
		$state     = '11111111-1111-4111-8111-111111111111.' . str_repeat( 'b', 43 );
		$callback  = 'https://connect.cybermaps.dev/cloudflare/callback';
		$auth_url  = add_query_arg(
			array(
				'client_id' => 'public-client-id', 'redirect_uri' => $callback, 'response_type' => 'code',
				'state' => $state, 'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'scope' => 'zone.read zone-transform-rules.write cache-settings.write',
			),
			'https://dash.cloudflare.com/oauth2/auth'
		);
		$calls  = array();
		$client = new CloudflareOAuthClient(
			static function ( string $url, array $args ) use ( &$calls, $state, $callback, $auth_url ): array {
				$calls[] = array( $url, $args );
				return array(
					'response' => array( 'code' => 201 ),
					'body' => wp_json_encode(
						array(
							'transaction_id' => '11111111-1111-4111-8111-111111111111',
							'consume_secret' => str_repeat( 's', 43 ),
							'state' => $state,
							'client_id' => 'public-client-id',
							'redirect_uri' => $callback,
							'authorization_url' => $auth_url,
						)
					),
				);
			}
		);
		$result = $client->create_transaction( $challenge );
		self::assertSame( $state, $result['state'] );
		self::assertStringNotContainsString( 'code_verifier', (string) $calls[0][1]['body'] );
	}

	public function test_code_exchange_and_revocation_use_direct_cloudflare_endpoints(): void {
		$calls  = array();
		$client = new CloudflareOAuthClient(
			static function ( string $url, array $args ) use ( &$calls ): array {
				$calls[] = array( $url, $args );
				$body    = str_ends_with( $url, '/token' ) ? wp_json_encode( array( 'access_token' => str_repeat( 't', 40 ), 'token_type' => 'Bearer' ) ) : '';
				return array( 'response' => array( 'code' => 200 ), 'body' => $body );
			}
		);
		$token = $client->exchange_code( 'authorization-code', str_repeat( 'v', 64 ), 'public-client-id', 'https://connect.cybermaps.dev/cloudflare/callback' );
		$client->revoke_access_token( $token, 'public-client-id' );
		self::assertSame( 'https://dash.cloudflare.com/oauth2/token', $calls[0][0] );
		self::assertSame( 'https://dash.cloudflare.com/oauth2/revoke', $calls[1][0] );
		self::assertArrayNotHasKey( 'refresh_token', $calls[0][1]['body'] );
	}
}
