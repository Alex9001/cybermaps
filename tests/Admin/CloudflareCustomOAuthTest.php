<?php
/**
 * Tests for site-managed Cloudflare OAuth.
 *
 * @package Cybermaps\Tests\Admin
 */

declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\CloudflareOAuthClient;
use Cybermaps\Admin\CloudflareOAuthTransactionStore;
use Cybermaps\Admin\CloudflareRuleManager;
use Cybermaps\Admin\EdgeOptimizationController;
use PHPUnit\Framework\TestCase;

final class CloudflareCustomOAuthTest extends TestCase {
	public function test_direct_transaction_uses_exact_callback_scopes_and_pkce(): void {
		$client      = new CloudflareOAuthClient();
		$verifier    = CloudflareOAuthClient::generate_verifier();
		$challenge   = CloudflareOAuthClient::challenge_for( $verifier );
		$callback    = EdgeOptimizationController::oauth_callback_url();
		$transaction = $client->create_direct_transaction( $challenge, '544b1a755ac5e65da02473537d92f915', $callback );
		$query       = array();
		parse_str( (string) parse_url( (string) $transaction['authorization_url'], PHP_URL_QUERY ), $query );

		self::assertSame( 'https://dash.cloudflare.com/oauth2/auth', strtok( (string) $transaction['authorization_url'], '?' ) );
		self::assertSame( 'code', $query['response_type'] );
		self::assertSame( '544b1a755ac5e65da02473537d92f915', $query['client_id'] );
		self::assertSame( $callback, $query['redirect_uri'] );
		self::assertSame( 'zone.read zone-transform-rules.write cache-settings.write', $query['scope'] );
		self::assertSame( $transaction['state'], $query['state'] );
		self::assertSame( $challenge, $query['code_challenge'] );
		self::assertSame( 'S256', $query['code_challenge_method'] );
	}

	public function test_direct_callback_state_is_single_use(): void {
		$store       = new CloudflareOAuthTransactionStore( 92814 );
		$transaction = array(
			'transaction_id' => str_repeat( 'a', 32 ),
			'consume_secret' => str_repeat( 'b', 64 ),
			'state'          => str_repeat( 'c', 64 ),
			'client_id'      => str_repeat( 'd', 32 ),
			'redirect_uri'   => EdgeOptimizationController::oauth_callback_url(),
		);
		$store->begin( $transaction, str_repeat( 'e', 64 ), 'install', 'custom' );

		self::assertFalse( $store->authorize_direct( str_repeat( 'f', 64 ), 'wrong-state-code' ) );
		self::assertTrue( $store->authorize_direct( str_repeat( 'c', 64 ), 'authorized-code' ) );
		self::assertFalse( $store->authorize_direct( str_repeat( 'c', 64 ), 'second-code' ) );
		self::assertSame( 'authorized', $store->current()['status'] ?? null );
		self::assertSame( 'authorized-code', $store->current()['code'] ?? null );
		self::assertSame( CloudflareRuleManager::public_host(), $store->current()['environment_host'] ?? null );
		$store->clear();
	}

	public function test_client_id_validation_rejects_secrets_and_whitespace(): void {
		self::assertTrue( CloudflareOAuthClient::is_valid_client_id( '544b1a755ac5e65da02473537d92f915' ) );
		self::assertFalse( CloudflareOAuthClient::is_valid_client_id( 'short' ) );
		self::assertFalse( CloudflareOAuthClient::is_valid_client_id( 'client id with spaces' ) );
		self::assertFalse( CloudflareOAuthClient::is_valid_client_id( str_repeat( 'x', 257 ) ) );
	}

	public function test_finished_result_remains_available_for_a_resume_poll(): void {
		$store  = new CloudflareOAuthTransactionStore( 92815 );
		$result = array(
			'status'  => 'complete',
			'message' => 'Rules installed.',
		);
		$store->finish( $result );

		self::assertSame( 'finished', $store->current()['status'] ?? null );
		self::assertSame( $result, $store->current()['result'] ?? null );
		$store->clear();
	}

	public function test_request_detection_is_bound_to_the_public_host(): void {
		$ray  = $_SERVER['HTTP_CF_RAY'] ?? null;
		$host = $_SERVER['HTTP_HOST'] ?? null;
		try {
			$_SERVER['HTTP_CF_RAY'] = 'test-ray';
			$_SERVER['HTTP_HOST']   = CloudflareRuleManager::public_host();
			self::assertTrue( CloudflareRuleManager::request_is_cloudflare() );

			$_SERVER['HTTP_HOST'] = 'different.example';
			self::assertFalse( CloudflareRuleManager::request_is_cloudflare() );
		} finally {
			$this->restore_server_value( 'HTTP_CF_RAY', $ray );
			$this->restore_server_value( 'HTTP_HOST', $host );
		}
	}

	private function restore_server_value( string $key, mixed $value ): void {
		if ( null === $value ) {
			unset( $_SERVER[ $key ] );
			return;
		}
		$_SERVER[ $key ] = $value;
	}
}
