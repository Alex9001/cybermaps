<?php
declare(strict_types=1);
namespace Cybermaps\Tests\Discovery;

/** Actual PHP header/body behavior of both terminating Markdown handlers. */
final class MarkdownPrivacyBoundaryTest extends \PHPUnit\Framework\TestCase {
	public function test_known_privacy_changes_reject_get_head_and_conditional_requests_before_output(): void {
		$socket = stream_socket_server( 'tcp://127.0.0.1:0', $code, $message );
		self::assertIsResource( $socket, $message );
		$address = stream_socket_get_name( $socket, false );
		fclose( $socket );
		$log = tempnam( sys_get_temp_dir(), 'markdown-privacy-' );
		$process = proc_open( array( PHP_BINARY, '-S', $address, __DIR__ . '/fixtures/markdown-privacy.php' ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', $log, 'a' ), 2 => array( 'file', $log, 'a' ) ), $pipes );
		self::assertIsResource( $process );
		fclose( $pipes[0] );
		try {
			$this->wait_for_server( $address );
			foreach ( array( '/guides/setup/index.md', '/guides/setup/' ) as $path ) {
				foreach ( array( 'eligibility', 'policy', 'encoding' ) as $stage ) {
					foreach ( array( 'GET', 'HEAD' ) as $method ) {
						foreach ( array( false, true ) as $conditional ) {
							$headers = 'Accept: text/markdown' . ( $conditional ? "\r\nIf-None-Match: *" : '' );
							$context = stream_context_create( array( 'http' => array( 'method' => $method, 'header' => $headers, 'ignore_errors' => true, 'timeout' => 3 ) ) );
							$body = file_get_contents( 'http://' . $address . $path . '?stage=' . $stage, false, $context );
							self::assertIsString( $body );
							self::assertStringContainsString( ' 503 ', $http_response_header[0], $path . '/' . $stage . '/' . $method );
							$response_headers = strtolower( implode( "\n", $http_response_header ) );
							self::assertStringContainsString( 'cache-control: no-store, max-age=0', $response_headers );
							self::assertStringContainsString( 'retry-after: 5', $response_headers );
							self::assertSame( 'HEAD' === $method, '' === $body );
							self::assertStringNotContainsString( 'REVOKED_MARKDOWN_BODY', $body );
							foreach ( array( 'etag:', 'repr-digest:', 'content-digest:', 'link:', 'x-markdown-tokens:' ) as $representation_header ) {
								self::assertStringNotContainsString( $representation_header, $response_headers );
							}
						}
					}
				}
			}
			foreach ( array( array( 'GET', 'text/html', 'unavailable', 200 ), array( 'POST', 'text/markdown', 'unavailable', 200 ), array( 'GET', 'text/html', 'healthy-html', 200 ), array( 'GET', 'text/markdown', 'unavailable', 503 ) ) as [$method, $accept, $stage, $status] ) {
				$context = stream_context_create( array( 'http' => array( 'method' => $method, 'header' => 'Accept: ' . $accept, 'ignore_errors' => true, 'timeout' => 3 ) ) );
				$body = file_get_contents( 'http://' . $address . '/guides/setup/?stage=' . $stage, false, $context );
				self::assertStringContainsString( ' ' . $status . ' ', $http_response_header[0] );
				if ( 200 === $status ) { self::assertSame( 'Ordinary HTML fallthrough', $body ); }
				if ( 'healthy-html' === $stage ) { self::assertStringContainsString( 'vary: accept', strtolower( implode( "\n", $http_response_header ) ) ); }
			}
			foreach ( array( '/llms.txt', '/llms-full.txt', '/discovery/chunks/7.json', '/updates.json' ) as $path ) {
				$body = file_get_contents( 'http://' . $address . $path . '?stage=healthy-protocol' );
				self::assertStringContainsString( ' 200 ', $http_response_header[0] );
				self::assertStringContainsString( '/discovery/chunks/7.json' === $path ? 'REVOKED_MARKDOWN_BODY' : 'Guide', $body );
				foreach ( array( 'policy', 'encoding' ) as $stage ) {
					foreach ( array( 'GET', 'HEAD' ) as $method ) {
						foreach ( array( false, true ) as $conditional ) {
							$context = stream_context_create( array( 'http' => array( 'method' => $method, 'header' => $conditional ? 'If-None-Match: *' : '', 'ignore_errors' => true, 'timeout' => 3 ) ) );
							$body = file_get_contents( 'http://' . $address . $path . '?stage=' . $stage, false, $context );
							self::assertStringContainsString( ' 503 ', $http_response_header[0], $path . '/' . $stage . '/' . $method );
							$response_headers = strtolower( implode( "\n", $http_response_header ) );
							self::assertStringContainsString( 'cache-control: no-store, max-age=0', $response_headers );
							self::assertStringContainsString( 'retry-after: 5', $response_headers );
							self::assertSame( 'HEAD' === $method, '' === $body );
							self::assertStringNotContainsString( 'Guide', $body );
							self::assertStringNotContainsString( 'REVOKED_MARKDOWN_BODY', $body );
							foreach ( array( 'etag:', 'repr-digest:', 'content-digest:' ) as $name ) { self::assertStringNotContainsString( $name, $response_headers ); }
						}
					}
				}
			}
		} finally {
			proc_terminate( $process );
			proc_close( $process );
			unlink( $log );
		}
	}

	private function wait_for_server( string $address ): void {
		for ( $attempt = 0; $attempt < 50; ++$attempt ) {
			$connection = @stream_socket_client( 'tcp://' . $address, $code, $message, 0.1 );
			if ( is_resource( $connection ) ) { fclose( $connection ); return; }
			usleep( 10000 );
		}
		self::fail( 'The bounded local Markdown fixture did not start.' );
	}
}
