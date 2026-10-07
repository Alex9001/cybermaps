<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

/** Ordinary loopback requests test the native headers of terminating handlers. */
final class PublicationPrivacyUnavailableTest extends \PHPUnit\Framework\TestCase {
	public function test_router_and_updates_privacy_failures_are_uncached_and_head_safe(): void {
		$socket = stream_socket_server( 'tcp://127.0.0.1:0', $code, $message );
		self::assertIsResource( $socket, $message );
		$address = stream_socket_get_name( $socket, false );
		fclose( $socket );
		$log = tempnam( sys_get_temp_dir(), 'privacy-http-' );
		$process = proc_open(
			array( PHP_BINARY, '-S', $address, dirname( __DIR__ ) . '/fixtures/publication-privacy-unavailable.php' ),
			array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', $log, 'a' ), 2 => array( 'file', $log, 'a' ) ),
			$pipes
		);
		self::assertIsResource( $process );
		fclose( $pipes[0] );
		try {
			$this->wait_for_server( $address );
			foreach ( array( '/ai.json', '/updates.json' ) as $path ) {
				foreach ( array( 'GET', 'HEAD' ) as $method ) {
					$context = stream_context_create( array( 'http' => array( 'method' => $method, 'ignore_errors' => true, 'timeout' => 3 ) ) );
					$body = file_get_contents( 'http://' . $address . $path, false, $context );
					self::assertIsString( $body );
					self::assertStringContainsString( ' 503 ', $http_response_header[0] );
					$headers = strtolower( implode( "\n", $http_response_header ) );
					self::assertStringContainsString( 'cache-control: no-store, max-age=0', $headers );
					self::assertStringContainsString( 'retry-after: 5', $headers );
					self::assertStringContainsString( 'content-type: text/plain; charset=utf-8', $headers );
					self::assertSame( 'HEAD' === $method, '' === $body );
					self::assertStringNotContainsString( '"updates"', $body );
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
			if ( is_resource( $connection ) ) {
				fclose( $connection );
				return;
			}
			usleep( 10000 );
		}
		self::fail( 'The bounded local HTTP fixture did not start.' );
	}
}
