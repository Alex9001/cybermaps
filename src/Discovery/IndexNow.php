<?php
/**
 * IndexNow Service
 *
 * @package Cybermaps\Discovery
 */

declare(strict_types=1);

namespace Cybermaps\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class IndexNow {

	/**
	 * Settings.
	 *
	 * @var array
	 */
	private $settings;

	/**
	 * Durable delivery queue.
	 */
	private IndexNowQueue $queue;

	/**
	 * IndexNow constructor.
	 */
	public function __construct() {
		$this->settings = \Cybermaps\Core\ConfigurationStore::settings();
		$this->queue    = new IndexNowQueue();
	}

	/**
	 * Register hooks.
	 */
	public function register_hooks() {
		add_action( 'init', array( $this, 'handle_key_request' ) );
		add_action( IndexNowQueue::CRON_HOOK, array( $this, 'process_queue' ) );
	}

	/**
	 * Submit one URL after post-commit eligibility reconciliation.
	 */
	public function notify_url( string $url ): void {
		if ( empty( $this->settings['enable_indexnow'] ) || '' === $url ) {
			return;
		}

		$canonical = $this->canonical_public_url( $url );
		if ( '' === $canonical ) {
			return;
		}

		$this->queue->enqueue( array( $canonical ) );
	}

	/**
	 * Accept a bounded caller-supplied batch for reliable queue delivery.
	 *
	 * @param string[] $urls Public URLs.
	 * @return array<string, mixed>
	 */
	public function submit_urls( array $urls, bool $process_now = false ): array {
		$eligible = array();
		$rejected = 0;
		foreach ( $urls as $url ) {
			$canonical = is_string( $url ) ? $this->canonical_public_url( $url ) : '';
			if ( '' === $canonical ) {
				++$rejected;
				continue;
			}
			$eligible[] = $canonical;
		}

		$enqueue = $this->queue->enqueue( array_values( array_unique( $eligible ) ) );
		$report  = $process_now ? $this->process_queue() : array();

		return array(
			'accepted'  => $enqueue['accepted'],
			'duplicate' => $enqueue['duplicate'],
			'rejected'  => $rejected + $enqueue['rejected'],
			'report'    => $report,
			'health'    => $this->queue->health(),
		);
	}

	/**
	 * Process one due IndexNow batch. Safe for the queue cron hook and bounded
	 * operation executors.
	 *
	 * @return array<string, mixed>
	 */
	public function process_queue(): array {
		if ( empty( $this->settings['enable_indexnow'] ) ) {
			return array(
				'status' => 'disabled',
				'count'  => 0,
			);
		}

		$claim = $this->queue->claim_due();
		if ( false === ( $claim['schema_available'] ?? true ) ) {
			return array(
				'status' => 'schema_unavailable',
				'count'  => 0,
			);
		}
		$urls  = $claim['urls'];
		$token = $claim['token'];
		if ( array() === $urls ) {
			$this->queue->rearm();
			return array(
				'status' => 'empty',
				'count'  => 0,
			);
		}

		$response = $this->send_urls( $urls, true );
		if ( is_wp_error( $response ) ) {
			$this->queue->retry_or_fail( $urls, $token, 0, $this->retry_delay( 0, '' ), __( 'The IndexNow request did not complete.', 'cybermaps' ), true );
			return array(
				'status' => 'retry_scheduled',
				'count'  => count( $urls ),
				'code'   => 0,
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			$this->queue->acknowledge( $urls, $token, $code );
			return array(
				'status' => 'accepted',
				'count'  => count( $urls ),
				'code'   => $code,
			);
		}

		$retryable = 429 === $code || $code >= 500;
		$this->queue->retry_or_fail(
			$urls,
			$token,
			$code,
			$this->retry_delay( $code, $this->response_header( $response, 'retry-after' ) ),
			sprintf(
				/* translators: %d: HTTP response status code. */
				__( 'IndexNow returned HTTP %d.', 'cybermaps' ),
				$code
			),
			$retryable
		);

		return array(
			'status' => $retryable ? 'retry_scheduled' : 'discarded',
			'count'  => count( $urls ),
			'code'   => $code,
		);
	}

	/**
	 * Return the queue state without leaking the IndexNow verification key.
	 *
	 * @return array<string, mixed>
	 */
	public function get_queue_health(): array {
		return $this->queue->health();
	}

	/**
	 * Send an already validated same-origin batch.
	 *
	 * @param string[] $urls Canonical public URLs.
	 */
	private function send_urls( array $urls, bool $blocking ): mixed {
		$key              = $this->get_indexnow_key();
		$publication_home = \Cybermaps\Core\URLManager::get_home_url();
		$host             = wp_parse_url( $publication_home, PHP_URL_HOST );
		if (
			! is_string( $host )
			|| '' === $host
			|| array() === $urls
		) {
			return new \WP_Error();
		}

		$payload = wp_json_encode(
			array(
				'host'        => $host,
				'key'         => $key,
				'keyLocation' => \Cybermaps\Core\URLManager::get_home_url( "/{$key}.txt" ),
				'urlList'     => array_values( array_slice( $urls, 0, 10000 ) ),
			)
		);

		return wp_safe_remote_post(
			'https://api.indexnow.org/indexnow',
			array(
				'headers'     => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'        => $payload,
				'data_format' => 'body',
				'blocking'    => $blocking,
				'timeout'     => $blocking ? 5 : 1,
			)
		);
	}

	/**
	 * Validate the configured public origin and return a stable URL key.
	 */
	private function canonical_public_url( string $url ): string {
		$publication_home = \Cybermaps\Core\URLManager::get_home_url();
		$url              = \Cybermaps\Core\URLManager::sanitize_http_url( $url );
		if ( '' === $url || ! self::same_origin( $publication_home, $url ) ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( '' === $scheme || '' === $host ) {
			return '';
		}

		$canonical = $scheme . '://' . $host;
		if ( isset( $parts['port'] ) && ! ( 'https' === $scheme && 443 === (int) $parts['port'] ) && ! ( 'http' === $scheme && 80 === (int) $parts['port'] ) ) {
			$canonical .= ':' . (int) $parts['port'];
		}
		$canonical .= isset( $parts['path'] ) && '' !== $parts['path'] ? (string) $parts['path'] : '/';
		if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
			$canonical .= '?' . (string) $parts['query'];
		}

		return $canonical;
	}

	/**
	 * Derive a bounded retry delay from Retry-After or exponential backoff.
	 */
	private function retry_delay( int $status_code, string $retry_after ): int {
		if ( ( 429 === $status_code || $status_code >= 500 ) && '' !== $retry_after ) {
			if ( ctype_digit( trim( $retry_after ) ) ) {
				return max( 60, min( 3600, (int) $retry_after ) );
			}
			$timestamp = strtotime( $retry_after );
			if ( false !== $timestamp ) {
				return max( 60, min( 3600, $timestamp - time() ) );
			}
		}

		return 60;
	}

	private function response_header( mixed $response, string $name ): string {
		$value = wp_remote_retrieve_header( $response, $name );
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Handle request for IndexNow key file.
	 */
	public function handle_key_request() {
		if ( empty( $this->settings['enable_indexnow'] ) ) {
			return;
		}

		$key  = $this->get_indexnow_key();
		$path = \Cybermaps\Core\URLManager::get_request_path();

		if ( $key && hash_equals( "/{$key}.txt", (string) $path ) ) {
			$request_method = isset( $_SERVER['REQUEST_METHOD'] )
				&& is_scalar( $_SERVER['REQUEST_METHOD'] )
				? strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) )
				: 'GET';
			if ( ! in_array( $request_method, array( 'GET', 'HEAD' ), true ) ) {
				status_header( 405 );
				header( 'Allow: GET, HEAD' );
				exit;
			}

			status_header( 200 );
			header( 'Content-Type: text/plain; charset=utf-8', true );
			header( 'X-Cybermaps-Version: ' . CYBERMAPS_VERSION );
			header( 'Cache-Control: public, max-age=86400' );
			if ( 'HEAD' !== $request_method ) {
				echo esc_html( $key );
			}
			exit;
		}
	}

	/**
	 * Get or generate IndexNow key.
	 *
	 * @return string
	 */
	private function get_indexnow_key() {
		$key = get_option( 'cybermaps_indexnow_key' );
		$key = is_scalar( $key ) ? trim( (string) $key ) : '';
		if ( 1 !== preg_match( '/^[A-Za-z0-9-]{8,128}$/', $key ) ) {
			$key = wp_generate_password( 32, false, false );
			update_option( 'cybermaps_indexnow_key', $key, false );
		}
		return $key;
	}

	/**
	 * Verify the public IndexNow key file on the configured publication host.
	 *
	 * @return array{success:bool,message:string,checked_at:int}
	 */
	public static function verify_public_key_remote( bool $force = false ): array {
		$cached = get_transient( 'cybermaps_indexnow_remote_verify' );
		if ( ! $force && is_array( $cached ) ) {
			return $cached;
		}

		$instance = new self();
		$key      = $instance->get_indexnow_key();
		$key_url  = \Cybermaps\Core\URLManager::get_home_url( '/' . $key . '.txt' );
		$response = wp_safe_remote_get(
			$key_url,
			array(
				'timeout' => 2,
			)
		);

		$success = false;
		$message = __( 'The IndexNow key URL did not return HTTP 200 with the expected body.', 'cybermaps' );
		if ( ! is_wp_error( $response ) ) {
			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = trim( (string) wp_remote_retrieve_body( $response ) );
			if ( 200 === $code && hash_equals( $key, $body ) ) {
				$success = true;
				$message = __( 'The public IndexNow key URL responded correctly.', 'cybermaps' );
			}
		}

		$result = array(
			'success'    => $success,
			'message'    => $message,
			'checked_at' => time(),
		);
		set_transient( 'cybermaps_indexnow_remote_verify', $result, 10 * MINUTE_IN_SECONDS );

		return $result;
	}

	/**
	 * IndexNow requires every submitted URL to belong to the declared host.
	 */
	private static function same_origin( string $left, string $right ): bool {
		$left_parts  = wp_parse_url( $left );
		$right_parts = wp_parse_url( $right );
		if (
			! is_array( $left_parts )
			|| ! is_array( $right_parts )
			|| empty( $left_parts['scheme'] )
			|| empty( $right_parts['scheme'] )
			|| empty( $left_parts['host'] )
			|| empty( $right_parts['host'] )
		) {
			return false;
		}

		$left_scheme  = strtolower( (string) $left_parts['scheme'] );
		$right_scheme = strtolower( (string) $right_parts['scheme'] );
		$left_port    = isset( $left_parts['port'] )
			? (int) $left_parts['port']
			: ( 'https' === $left_scheme ? 443 : 80 );
		$right_port   = isset( $right_parts['port'] )
			? (int) $right_parts['port']
			: ( 'https' === $right_scheme ? 443 : 80 );

		return $left_scheme === $right_scheme
			&& strtolower( (string) $left_parts['host'] ) === strtolower( (string) $right_parts['host'] )
			&& $left_port === $right_port;
	}
}
