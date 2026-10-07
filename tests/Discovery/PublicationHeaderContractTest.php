<?php
/** Header and body assertions across actual terminating PHP response paths. */
declare(strict_types=1);
namespace Cybermaps\Tests\Discovery;

use PHPUnit\Framework\TestCase;

final class PublicationHeaderContractTest extends TestCase {
	private function response( string $case, string $method ): array {
		$command = array( PHP_BINARY, dirname( __DIR__ ) . '/fixtures/publication-header-contract.php', $case, $method );
		$process = proc_open( $command, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$body = stream_get_contents( $pipes[1] ); fclose( $pipes[1] );
		$metadata = stream_get_contents( $pipes[2] ); fclose( $pipes[2] );
		self::assertSame( 0, proc_close( $process ), $metadata );
		self::assertStringStartsWith( "\nFIXTURE:", $metadata );
		return json_decode( substr( $metadata, strlen( "\nFIXTURE:" ) ), true, 512, JSON_THROW_ON_ERROR ) + array( 'body' => $body );
	}

	public function test_diagnostic_no_store_survives_final_headers_and_conditional_requests(): void {
		foreach ( array( 'diagnostic', 'diagnostic-conditional' ) as $case ) {
			foreach ( array( 'GET', 'HEAD' ) as $method ) {
				$response = $this->response( $case, $method );
				self::assertSame( 200, $response['status'] );
				self::assertSame( 'no-store, no-cache, must-revalidate, max-age=0', $response['headers']['cache-control'] );
				self::assertSame( str_repeat( 'a', 32 ), $response['headers']['x-cybermaps-diagnostic-response'] );
				self::assertSame( 'HEAD' === $method, '' === $response['body'] );
			}
		}
	}

	public function test_nonmatching_diagnostic_retains_normal_policy_and_conditional_status(): void {
		$response = $this->response( 'diagnostic-mismatch', 'GET' );
		self::assertSame( 200, $response['status'] );
		self::assertSame( 'public, max-age=3600, must-revalidate', $response['headers']['cache-control'] );
		self::assertArrayNotHasKey( 'x-cybermaps-diagnostic-response', $response['headers'] );
		foreach ( array( 'GET', 'HEAD' ) as $method ) {
			$response = $this->response( 'conditional', $method );
			self::assertSame( 304, $response['status'] );
			self::assertSame( '', $response['body'] );
		}
	}

	public function test_head_error_paths_emit_no_php_body(): void {
		foreach ( array( 'throttle' => 429, 'router' => 500, 'router-size' => 507 ) as $case => $status ) {
			$get = $this->response( $case, 'GET' );
			$head = $this->response( $case, 'HEAD' );
			self::assertSame( $status, $get['status'] );
			self::assertSame( $status, $head['status'] );
			self::assertNotSame( '', $get['body'] );
			self::assertSame( '', $head['body'] );
		}
	}

	public function test_started_publication_still_rejects_a_later_generation_change(): void {
		$get = $this->response( 'stale-generation', 'GET' );
		$head = $this->response( 'stale-generation', 'HEAD' );
		self::assertSame( 503, $get['status'] );
		self::assertSame( 503, $head['status'] );
		self::assertStringContainsString( 'Please retry shortly', $get['body'] );
		self::assertStringNotContainsString( '<url>', $get['body'] );
		self::assertSame( '', $head['body'] );
	}
}
