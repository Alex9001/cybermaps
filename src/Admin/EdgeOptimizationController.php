<?php
/**
 * Admin-only delivery optimization actions.
 *
 * @package Cybermaps\Admin
 */

declare(strict_types=1);

namespace Cybermaps\Admin;

use Cybermaps\Core\CacheManager;
use Cybermaps\Core\ConfigurationStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Coordinates diagnostics, ephemeral Cloudflare OAuth, and fallback token actions. */
final class EdgeOptimizationController {
	private const NONCE_ACTION = 'cybermaps_edge_optimization';
	private const LOCK_TTL     = 90;
	private const VERIFY_CACHE = 'cybermaps_edge_public_verification';
	private string $lock_token = '';

	public function register_hooks(): void {
		( new LocalDeliveryController() )->register_hooks();
		add_action( 'wp_ajax_cybermaps_edge_status', array( $this, 'ajax_status' ) );
		add_action( 'wp_ajax_cybermaps_edge_verify', array( $this, 'ajax_verify' ) );
		add_action( 'wp_ajax_cybermaps_edge_oauth_poll', array( $this, 'ajax_oauth_poll' ) );
		add_action( 'wp_ajax_cybermaps_edge_install_headers', array( $this, 'ajax_install_headers' ) );
		add_action( 'wp_ajax_cybermaps_edge_install_cache', array( $this, 'ajax_install_cache' ) );
		add_action( 'wp_ajax_cybermaps_edge_remove_rules', array( $this, 'ajax_remove_rules' ) );
		add_action( 'wp_ajax_cybermaps_edge_clear_caches', array( $this, 'ajax_clear_caches' ) );
		add_action( 'wp_ajax_cybermaps_edge_rebuild_static', array( $this, 'ajax_rebuild_static' ) );
		add_action( 'admin_post_cybermaps_cloudflare_oauth_start', array( $this, 'start_cloudflare_oauth' ) );
		add_action( 'admin_post_cybermaps_cloudflare_oauth_callback', array( $this, 'cloudflare_oauth_callback' ) );
	}

	public static function nonce_action(): string {
		return self::NONCE_ACTION;
	}

	public static function oauth_start_url( string $operation ): string {
		$operation = in_array( $operation, array( 'install', 'remove' ), true ) ? $operation : 'install';
		$url       = add_query_arg(
			array(
				'action'    => 'cybermaps_cloudflare_oauth_start',
				'operation' => $operation,
			),
			admin_url( 'admin-post.php' )
		);
		return wp_nonce_url( $url, self::NONCE_ACTION . ':' . $operation );
	}

	public static function oauth_callback_url(): string {
		return admin_url( 'admin-post.php?action=cybermaps_cloudflare_oauth_callback' );
	}

	public function ajax_status(): void {
		$this->authorize();
		wp_send_json_success( array( 'status' => SystemStatusCollector::collect() ) );
	}

	public function ajax_verify(): void {
		$this->authorize();
		$verification = get_transient( self::VERIFY_CACHE );
		if ( ! is_array( $verification ) ) {
			$verification = CloudflareRuleManager::verify_public();
			set_transient( self::VERIFY_CACHE, $verification, MINUTE_IN_SECONDS );
		}
		wp_send_json_success( array( 'verification' => $verification ) );
	}

	public function start_cloudflare_oauth(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'cybermaps' ), '', array( 'response' => 403 ) );
		}
		$operation = $this->requested_operation();
		check_admin_referer( self::NONCE_ACTION . ':' . $operation );
		if ( ! $this->cloudflare_environment_confirmed() ) {
			wp_die( esc_html__( 'Cloudflare was not detected for this hostname. Confirm that its DNS record is proxied through Cloudflare before continuing.', 'cybermaps' ), esc_html__( 'Cloudflare not detected', 'cybermaps' ), array( 'response' => 400 ) );
		}
		$store = new CloudflareOAuthTransactionStore();
		try {
			$client      = new CloudflareOAuthClient();
			$verifier    = CloudflareOAuthClient::generate_verifier();
			$oauth       = $this->oauth_configuration();
			$transaction = $this->create_oauth_transaction( $client, $verifier, $oauth );
			$store->begin( $transaction, $verifier, $operation, $oauth['mode'], CloudflareRuleManager::public_host() );
			// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Client validates the exact HTTPS Cloudflare OAuth origin.
			wp_redirect( (string) $transaction['authorization_url'], 302, 'Cybermaps' );
			exit;
		} catch ( \Throwable $error ) {
			$message = $this->safe_error( $error, __( 'Cloudflare authorization could not be started.', 'cybermaps' ) );
			$store->fail( $message );
			wp_die( esc_html( $message ), esc_html__( 'Cloudflare authorization', 'cybermaps' ), array( 'response' => 502 ) );
		}
	}

	public function cloudflare_oauth_callback(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sign in as an administrator to finish Cloudflare authorization.', 'cybermaps' ), '', array( 'response' => 403 ) );
		}
		$store       = new CloudflareOAuthTransactionStore();
		$transaction = $store->current();
		$state       = $this->callback_value( 'state', 512 );
		if ( ! $this->valid_direct_callback( $transaction, $state ) ) {
			wp_die( esc_html__( 'Cloudflare authorization state is invalid or expired. Return to Cybermaps and start again.', 'cybermaps' ), '', array( 'response' => 400 ) );
		}
		$error = $this->callback_value( 'error_description', 500 );
		if ( '' === $error ) {
			$error = $this->callback_value( 'error', 100 );
		}
		if ( '' !== $error ) {
			$store->fail( sprintf( /* translators: %s: Cloudflare OAuth error. */ __( 'Cloudflare authorization was not completed: %s', 'cybermaps' ), $error ) );
			wp_safe_redirect( self::oauth_return_url() );
			exit;
		}
		if ( ! $store->authorize_direct( $state, $this->callback_value( 'code', 4096 ) ) ) {
			wp_die( esc_html__( 'Cloudflare did not return a usable authorization code. Return to Cybermaps and start again.', 'cybermaps' ), '', array( 'response' => 400 ) );
		}
		wp_safe_redirect( self::oauth_return_url() );
		exit;
	}

	private static function oauth_return_url(): string {
		return add_query_arg(
			array(
				'page'                        => 'cybermaps-settings',
				'tab'                         => 'advanced',
				'cybermaps_cloudflare_return' => '1',
			),
			admin_url( 'admin.php' )
		);
	}

	public function ajax_oauth_poll(): void {
		$this->authorize();
		$store       = new CloudflareOAuthTransactionStore();
		$transaction = $store->current();
		if ( null === $transaction ) {
			wp_send_json_success( array( 'status' => 'idle' ) );
		}
		$finished = $this->finished_transaction_result( $transaction );
		if ( null !== $finished ) {
			$store->clear();
			wp_send_json_success( $finished );
		}
		if ( 'failed' === ( $transaction['status'] ?? '' ) ) {
			$message = (string) ( $transaction['message'] ?? __( 'Cloudflare authorization did not start.', 'cybermaps' ) );
			$store->clear();
			wp_send_json_success(
				array(
					'status'  => 'failed',
					'message' => $message,
				)
			);
		}
		if ( ! $this->acquire_lock() ) {
			wp_send_json_success(
				array(
					'status'  => 'processing',
					'message' => __( 'Another Cybermaps edge operation is finishing.', 'cybermaps' ),
				)
			);
		}
		try {
			$response = $this->poll_oauth_transaction( $transaction, $store );
		} catch ( \Throwable $error ) {
			$message = $this->safe_error( $error, __( 'Cloudflare authorization did not complete.', 'cybermaps' ) );
			$store->fail( $message );
			$response = array(
				'status'  => 'failed',
				'message' => $message,
			);
		} finally {
			$this->release_lock();
		}
		wp_send_json_success( $response );
	}

	/** @param array<string,mixed> $transaction @return array<string,mixed>|null */
	private function finished_transaction_result( array $transaction ): ?array {
		if ( 'finished' !== ( $transaction['status'] ?? '' ) ) {
			return null;
		}
		if ( is_array( $transaction['result'] ?? null ) ) {
			return $transaction['result'];
		}
		return array(
			'status'  => 'failed',
			'message' => __( 'Cloudflare finished without a recoverable result.', 'cybermaps' ),
		);
	}

	public function ajax_install_headers(): void {
		$this->cloudflare_mutation( static fn( CloudflareRuleManager $manager ): array => $manager->install_header_rules() );
	}

	public function ajax_install_cache(): void {
		$this->cloudflare_mutation( static fn( CloudflareRuleManager $manager ): array => $manager->install_cache_rule() );
	}

	public function ajax_remove_rules(): void {
		$this->cloudflare_mutation( static fn( CloudflareRuleManager $manager ): array => $manager->remove_rules() );
	}

	public function ajax_clear_caches(): void {
		$this->authorize();
		$count = CacheManager::clear_all();
		wp_send_json_success(
			array(
				'message' => sprintf( /* translators: %d: removed compatibility cache entries. */ __( 'Cybermaps advanced every cache family and removed %d compatibility entries.', 'cybermaps' ), $count ),
			)
		);
	}

	public function ajax_rebuild_static(): void {
		$this->authorize();
		$report = \Cybermaps\Discovery\StaticBridge::get_instance()->sync_all();
		delete_transient( self::VERIFY_CACHE );
		wp_send_json_success(
			array(
				'message' => sprintf( /* translators: %s: static reconciliation status. */ __( 'Static publication reconciliation finished with status: %s.', 'cybermaps' ), sanitize_text_field( (string) ( $report['status'] ?? 'unknown' ) ) ),
				'report'  => $report,
			)
		);
	}

	/** @param array<string,mixed> $transaction @return array<string,mixed> */
	private function poll_oauth_transaction( array $transaction, CloudflareOAuthTransactionStore $store ): array {
		$client = new CloudflareOAuthClient();
		if ( 'custom' === ( $transaction['mode'] ?? '' ) ) {
			return $this->poll_direct_transaction( $client, $transaction, $store );
		}
		$result = $client->consume_transaction( (string) $transaction['transaction_id'], (string) $transaction['consume_secret'] );
		$status = (string) $result['status'];
		if ( 'pending' === $status ) {
			return array(
				'status'      => 'pending',
				'message'     => __( 'Waiting for Cloudflare authorization.', 'cybermaps' ),
				'retry_after' => max( 2, min( 10, absint( $result['retry_after'] ?? 2 ) ) ),
			);
		}
		if ( 'authorized' !== $status ) {
			$store->clear();
			return array(
				'status'  => 'failed',
				'message' => 'expired' === $status ? __( 'Cloudflare authorization expired. Start again.', 'cybermaps' ) : __( 'Cloudflare authorization was declined or unavailable.', 'cybermaps' ),
			);
		}
		if ( ! isset( $result['state'], $result['code'] ) || ! hash_equals( (string) $transaction['state'], (string) $result['state'] ) ) {
			throw new \RuntimeException( esc_html__( 'Cloudflare authorization state did not match the initiating administrator.', 'cybermaps' ) );
		}
		return $this->complete_oauth( $client, $transaction, (string) $result['code'], $store );
	}

	/** @param array<string,mixed> $transaction @return array<string,mixed> */
	private function poll_direct_transaction( CloudflareOAuthClient $client, array $transaction, CloudflareOAuthTransactionStore $store ): array {
		if ( 'authorized' !== ( $transaction['status'] ?? '' ) ) {
			return array(
				'status'      => 'pending',
				'message'     => __( 'Waiting for Cloudflare authorization.', 'cybermaps' ),
				'retry_after' => 2,
			);
		}
		$code = is_scalar( $transaction['code'] ?? null ) ? (string) $transaction['code'] : '';
		if ( '' === $code ) {
			throw new \RuntimeException( esc_html__( 'Cloudflare authorization did not include a code.', 'cybermaps' ) );
		}
		return $this->complete_oauth( $client, $transaction, $code, $store );
	}

	/** @return array{mode:string,client_id:string} */
	private function oauth_configuration(): array {
		$settings  = ConfigurationStore::settings();
		$mode      = 'custom' === ( $settings['cloudflare_oauth_mode'] ?? 'managed' ) ? 'custom' : 'managed';
		$client_id = is_scalar( $settings['cloudflare_oauth_client_id'] ?? null ) ? trim( (string) $settings['cloudflare_oauth_client_id'] ) : '';
		return array(
			'mode'      => $mode,
			'client_id' => $client_id,
		);
	}

	/** @param array{mode:string,client_id:string} $oauth @return array<string,mixed> */
	private function create_oauth_transaction( CloudflareOAuthClient $client, string $verifier, array $oauth ): array {
		$challenge = CloudflareOAuthClient::challenge_for( $verifier );
		if ( 'managed' === $oauth['mode'] ) {
			return $client->create_transaction( $challenge );
		}
		if ( ! CloudflareOAuthClient::is_valid_client_id( $oauth['client_id'] ) ) {
			throw new \RuntimeException( esc_html__( 'Save a valid Cloudflare OAuth client ID before using custom OAuth.', 'cybermaps' ) );
		}
		return $client->create_direct_transaction( $challenge, $oauth['client_id'], self::oauth_callback_url() );
	}

	/** @param array<string,mixed>|null $transaction */
	private function valid_direct_callback( ?array $transaction, string $state ): bool {
		return null !== $transaction
			&& 'custom' === ( $transaction['mode'] ?? '' )
			&& 'pending' === ( $transaction['status'] ?? '' )
			&& '' !== $state
			&& hash_equals( (string) ( $transaction['state'] ?? '' ), $state );
	}

	private function callback_value( string $key, int $limit ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- OAuth state is the callback CSRF protection and is verified before use.
		$value = isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ $key ] ) ) : '';
		return substr( $value, 0, $limit );
	}

	/** @param array<string,mixed> $transaction @return array<string,mixed> */
	private function complete_oauth( CloudflareOAuthClient $oauth, array $transaction, string $code, CloudflareOAuthTransactionStore $store ): array {
		$token      = '';
		$result     = null;
		$error      = null;
		$revocation = 'discarded';
		try {
			$this->require_current_transaction_host( $transaction );
			$token   = $oauth->exchange_code( $code, (string) $transaction['code_verifier'], (string) $transaction['client_id'], (string) $transaction['redirect_uri'] );
			$manager = new CloudflareRuleManager( new CloudflareRulesClient( $token ) );
			$result  = 'remove' === (string) $transaction['operation'] ? $manager->remove_rules() : $manager->install_all();
		} catch ( \Throwable $exception ) {
			$error = $this->safe_error( $exception, __( 'The Cloudflare rule operation did not complete.', 'cybermaps' ) );
		} finally {
			if ( '' !== $token ) {
				try {
					$oauth->revoke_access_token( $token, (string) $transaction['client_id'] );
					$revocation = 'revoked';
				} catch ( \Throwable $ignored ) {
					$revocation = 'revoke_failed';
				}
			}
			$token = '';
		}
		CloudflareRuleManager::record_credential_disposition( 'oauth', $revocation );
		delete_transient( self::VERIFY_CACHE );
		$response = $this->oauth_result( $result, $error, $revocation );
		$store->finish( $response );
		return $response;
	}

	/** @param array<string,mixed>|null $result @return array<string,mixed> */
	private function oauth_result( ?array $result, ?string $error, string $revocation ): array {
		if ( null !== $error ) {
			return array(
				'status'     => 'failed',
				'message'    => $error,
				'revocation' => $revocation,
			);
		}
		$status  = 'partial' === ( $result['status'] ?? '' ) ? 'partial' : 'complete';
		$message = 'partial' === $status
			? __( 'Cloudflare installed the discovery headers, but cache safety needs attention. Review Debugging for details.', 'cybermaps' )
			: __( 'Cloudflare authorization completed and Cybermaps rules were updated.', 'cybermaps' );
		if ( 'revoke_failed' === $revocation ) {
			$message .= ' ' . __( 'The credential was discarded, but Cloudflare revocation could not be confirmed.', 'cybermaps' );
		}
		return array(
			'status'     => $status,
			'message'    => $message,
			'result'     => $result,
			'revocation' => $revocation,
		);
	}

	/** @param callable(CloudflareRuleManager):array<string,mixed> $operation */
	private function cloudflare_mutation( callable $operation ): void {
		$this->authorize();
		if ( ! $this->cloudflare_environment_confirmed() ) {
			wp_send_json_error( array( 'message' => __( 'Cloudflare was not detected. Confirm that this hostname is orange-cloud proxied before using Cloudflare rule tools.', 'cybermaps' ) ), 400 );
		}
		$token = $this->request_token();
		if ( ! $this->acquire_lock() ) {
			wp_send_json_error( array( 'message' => __( 'Another Cybermaps edge operation is already running. Wait briefly and try again.', 'cybermaps' ) ), 409 );
		}
		$result = null;
		$error  = null;
		try {
			$manager = new CloudflareRuleManager( new CloudflareRulesClient( $token ) );
			$result  = $operation( $manager );
		} catch ( \Throwable $exception ) {
			$error = $this->safe_error( $exception, __( 'The Cloudflare operation did not complete.', 'cybermaps' ) );
		} finally {
			$token = '';
			CloudflareRuleManager::record_credential_disposition( 'api_token', 'discarded' );
			$this->release_lock();
		}
		if ( null !== $error ) {
			wp_send_json_error( array( 'message' => $error ), 400 );
		}
		delete_transient( self::VERIFY_CACHE );
		wp_send_json_success( array( 'result' => $result ) );
	}

	private function authorize(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		if ( 'POST' !== $method ) {
			wp_send_json_error( array( 'message' => __( 'POST is required.', 'cybermaps' ) ), 405 );
		}
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'cybermaps' ) ), 403 );
		}
	}

	private function requested_operation(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The operation selects the nonce action checked immediately by the caller.
		$operation = isset( $_GET['operation'] ) && is_scalar( $_GET['operation'] ) ? sanitize_key( wp_unslash( (string) $_GET['operation'] ) ) : '';
		return in_array( $operation, array( 'install', 'remove' ), true ) ? $operation : 'install';
	}

	private function cloudflare_environment_confirmed(): bool {
		if ( CloudflareRuleManager::request_is_cloudflare() ) {
			return true;
		}
		// phpcs:disable WordPress.Security.NonceVerification -- Callers verify their admin or AJAX nonce before this intent flag is read.
		$get       = isset( $_GET['cloudflare_confirmed'] ) && is_scalar( $_GET['cloudflare_confirmed'] ) ? sanitize_key( wp_unslash( (string) $_GET['cloudflare_confirmed'] ) ) : '';
		$post      = isset( $_POST['cloudflare_confirmed'] ) && is_scalar( $_POST['cloudflare_confirmed'] ) ? sanitize_key( wp_unslash( (string) $_POST['cloudflare_confirmed'] ) ) : '';
		$get_host  = isset( $_GET['cloudflare_host'] ) && is_scalar( $_GET['cloudflare_host'] ) ? strtolower( sanitize_text_field( wp_unslash( (string) $_GET['cloudflare_host'] ) ) ) : '';
		$post_host = isset( $_POST['cloudflare_host'] ) && is_scalar( $_POST['cloudflare_host'] ) ? strtolower( sanitize_text_field( wp_unslash( (string) $_POST['cloudflare_host'] ) ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification
		$host = '1' === $get ? $get_host : $post_host;
		return ( '1' === $get || '1' === $post ) && hash_equals( CloudflareRuleManager::public_host(), trim( $host, '.' ) );
	}

	/** @param array<string,mixed> $transaction */
	private function require_current_transaction_host( array $transaction ): void {
		$host = is_scalar( $transaction['environment_host'] ?? null ) ? strtolower( trim( (string) $transaction['environment_host'], '.' ) ) : '';
		if ( '' === $host || ! hash_equals( CloudflareRuleManager::public_host(), $host ) ) {
			throw new \RuntimeException( esc_html__( 'The configured public hostname changed during Cloudflare authorization. Start the operation again.', 'cybermaps' ) );
		}
	}

	private function request_token(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() verifies the action nonce before this helper runs.
		$token = isset( $_POST['token'] ) && is_scalar( $_POST['token'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['token'] ) ) : '';
		if ( strlen( $token ) < 20 || strlen( $token ) > 512 || 1 !== preg_match( '/\A[A-Za-z0-9._-]+\z/', $token ) ) {
			wp_send_json_error( array( 'message' => __( 'Enter a valid scoped Cloudflare API token. The token is used once and is not stored.', 'cybermaps' ) ), 400 );
		}
		return $token;
	}

	private function acquire_lock(): bool {
		$key              = $this->lock_key();
		$this->lock_token = bin2hex( random_bytes( 16 ) );
		$value            = array(
			'token' => $this->lock_token,
			'time'  => time(),
		);
		if ( add_option( $key, $value, '', false ) ) {
			return true;
		}
		$held = get_option( $key, array() );
		if ( time() - $this->lock_time( $held ) <= self::LOCK_TTL ) {
			$this->lock_token = '';
			return false;
		}
		delete_option( $key );
		return add_option( $key, $value, '', false );
	}

	private function release_lock(): void {
		$held = get_option( $this->lock_key(), array() );
		if ( '' !== $this->lock_token && is_array( $held ) && hash_equals( $this->lock_token, (string) ( $held['token'] ?? '' ) ) ) {
			delete_option( $this->lock_key() );
		}
		$this->lock_token = '';
	}

	private function lock_time( mixed $held ): int {
		return is_array( $held ) ? (int) ( $held['time'] ?? 0 ) : (int) $held;
	}

	private function lock_key(): string {
		return 'cybermaps_edge_operation_lock';
	}

	private function safe_error( \Throwable $error, string $fallback ): string {
		$message = substr( sanitize_text_field( $error->getMessage() ), 0, 500 );
		return '' !== $message ? $message : $fallback;
	}
}
