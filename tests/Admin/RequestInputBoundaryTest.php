<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\EdgeOptimizationController;
use Cybermaps\Admin\Settings\Tabs\ContentReview;
use Cybermaps\Core\RequestInput;
use Cybermaps\Discovery\MarkdownNegotiation;
use PHPUnit\Framework\TestCase;

final class RequestInputBoundaryTest extends TestCase {
	public function test_literal_adapter_rejects_controls_and_limits_before_any_normalization(): void {
		$before = $_SERVER;
		try {
			foreach ( array( "\x00", "\r", "\n", "\t", "\x7F", ' ' ) as $invalid ) {
				$_SERVER['REQUEST_URI'] = '/cybermaps-' . $invalid . 'openapi.json?version=3.1.2';
				self::assertSame( '', RequestInput::query_text( 'version', 16 ) );
			}
			$_SERVER['REQUEST_URI'] = '/<literal>%2Fpath?version=3%2E1%2E2';
			self::assertSame( '3.1.2', RequestInput::query_text( 'version', 16 ) );
			$_SERVER['REQUEST_URI'] = '/' . str_repeat( 'p', 8192 - strlen( '/?version=3.1.2' ) ) . '?version=3.1.2';
			self::assertSame( 8192, strlen( $_SERVER['REQUEST_URI'] ) );
			self::assertSame( '3.1.2', RequestInput::query_text( 'version', 16 ) );
			$_SERVER['REQUEST_URI'] = 'p' . $_SERVER['REQUEST_URI'];
			self::assertSame( '', RequestInput::query_text( 'version', 16 ) );
			$_SERVER['REQUEST_URI'] = str_repeat( 'p', 16385 );
			self::assertSame( '', RequestInput::query_text( 'version', 16 ) );
			$_SERVER['REQUEST_URI'] = '/?version=' . str_repeat( 'x', 4096 - strlen( 'version=' ) );
			self::assertSame( str_repeat( 'x', 4088 ), RequestInput::query_text( 'version', 4088 ) );
			$_SERVER['REQUEST_URI'] .= 'x';
			self::assertSame( '', RequestInput::query_text( 'version', 4096 ) );
			foreach ( array( '"opaque%25<tag>"', 'W/"a%2Fb", "b"', '  "a"  ' ) as $literal ) {
				$_SERVER['HTTP_IF_NONE_MATCH'] = wp_slash( $literal );
				self::assertSame( $literal, RequestInput::header( 'if-none-match' ) );
			}
			$_SERVER['HTTP_IF_NONE_MATCH'] = str_repeat( 'x', 4096 );
			self::assertSame( $_SERVER['HTTP_IF_NONE_MATCH'], RequestInput::header( 'if-none-match' ) );
			self::assertSame( '', RequestInput::header( 'if-none-match', 4095 ) );
			$_SERVER['HTTP_IF_NONE_MATCH'] .= 'x';
			self::assertSame( '', RequestInput::header( 'if-none-match' ) );
			foreach ( array( "\x00", "\r", "\n", "\t", "\x7F" ) as $invalid ) {
				$_SERVER['HTTP_IF_NONE_MATCH'] = '"valid' . $invalid . 'tag"';
				self::assertSame( '', RequestInput::header( 'if-none-match' ) );
			}
			$_SERVER['REQUEST_URI'] = array( '/?version=3.1.2' );
			$_SERVER['HTTP_IF_NONE_MATCH'] = array( '"tag"' );
			self::assertSame( '', RequestInput::query_text( 'version', 16 ) );
			self::assertSame( '', RequestInput::header( 'if-none-match' ) );
		} finally { $_SERVER = $before; }
	}

	public function test_slashed_protocol_limits_use_real_unslash_semantics(): void {
		$process = proc_open( array( PHP_BINARY, __DIR__ . '/fixtures/request-input-slashed.php' ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		self::assertIsResource( $process );
		$output = stream_get_contents( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] ); fclose( $pipes[2] );
		self::assertSame( 0, proc_close( $process ), $error );
		$evidence = json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
		foreach ( $evidence as $check => $passed ) { self::assertTrue( $passed, $check ); }
	}

	public function test_array_notice_flags_do_not_display_success_or_busy_notices(): void {
		$before_get          = $_GET;
		$before_server       = $_SERVER;
		$before_capabilities = $GLOBALS['cybermaps_mock_current_user_capabilities'] ?? array();
		try {
			$_SERVER['REQUEST_METHOD'] = 'GET';
			$GLOBALS['cybermaps_mock_current_user_capabilities'] = array( 'manage_options' );
			$_GET['_wpnonce'] = wp_create_nonce( 'cybermaps_content_review_state' );
			$_GET['cybermaps_report_deleted'] = array( '1' );
			$_GET['cybermaps_report_busy']    = array( '1' );
			self::assertFalse( ( new \ReflectionMethod( ContentReview::class, 'report_deleted_notice_requested' ) )->invoke( null ) );
			self::assertFalse( ( new \ReflectionMethod( ContentReview::class, 'report_busy_notice_requested' ) )->invoke( null ) );
			$_GET['cybermaps_report_deleted'] = '1';
			$_GET['cybermaps_report_busy']    = '1';
			self::assertTrue( ( new \ReflectionMethod( ContentReview::class, 'report_deleted_notice_requested' ) )->invoke( null ) );
			self::assertTrue( ( new \ReflectionMethod( ContentReview::class, 'report_busy_notice_requested' ) )->invoke( null ) );
		} finally {
			$_GET    = $before_get;
			$_SERVER = $before_server;
			$GLOBALS['cybermaps_mock_current_user_capabilities'] = $before_capabilities;
		}
	}

	public function test_callback_values_are_plain_bounded_strings(): void {
		$before = $_GET;
		try {
			$client = ( new \ReflectionClass( EdgeOptimizationController::class ) )->newInstanceWithoutConstructor();
			$method = new \ReflectionMethod( $client, 'callback_value' );
			$_GET['code'] = array( 'injected' );
			self::assertSame( '', $method->invoke( $client, 'code', 8 ) );
			$_GET['code'] = '<b>abcdefghijk</b>';
			self::assertSame( 'abcdefgh', $method->invoke( $client, 'code', 8 ) );
		} finally {
			$_GET = $before;
		}
	}

	public function test_accept_header_preserves_media_types_and_quality_parameters(): void {
		$before = $_SERVER;
		try {
			$handler = ( new \ReflectionClass( MarkdownNegotiation::class ) )->newInstanceWithoutConstructor();
			$method = new \ReflectionMethod( $handler, 'accept_header' );
			$_SERVER['HTTP_ACCEPT'] = 'text/markdown; q=0.9, text/html; q=0.8';
			self::assertSame( $_SERVER['HTTP_ACCEPT'], $method->invoke( $handler ) );
			$_SERVER['HTTP_ACCEPT'] = array( 'text/markdown' );
			self::assertSame( '', $method->invoke( $handler ) );
		} finally {
			$_SERVER = $before;
		}
	}

	public function test_public_request_adapter_bounds_query_and_header_values_without_a_nonce(): void {
		$before = $_SERVER;
		try {
			$_SERVER['REQUEST_URI'] = '/cybermaps-openapi.json?version=3.1.2';
			$_SERVER['HTTP_ACCEPT'] = 'application/vnd.oai.openapi+json;version=3.2';
			self::assertSame( '3.1.2', RequestInput::query_text( 'version', 16 ) );
			self::assertSame( $_SERVER['HTTP_ACCEPT'], RequestInput::header( 'accept' ) );

			$_SERVER['REQUEST_URI'] = '/cybermaps-openapi.json?version=3%2E1%2E2';
			self::assertSame( '3.1.2', RequestInput::query_text( 'version', 16 ) );
			$_SERVER['REQUEST_URI'] = '/cybermaps-openapi.json?version%5B%5D=3.1.2';
			self::assertSame( '', RequestInput::query_text( 'version', 16 ) );
			$_SERVER['HTTP_IF_NONE_MATCH'] = '"opaque%25\\tag"';
			self::assertSame( wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ), RequestInput::header( 'if-none-match' ) );
			$_SERVER['REQUEST_URI'] = '/cybermaps-openapi.json?version=3.1.2%0D';
			self::assertSame( '', RequestInput::query_text( 'version', 16 ) );
			$_SERVER['HTTP_ACCEPT'] = "application/json\r\nX-Injected: yes";
			self::assertSame( '', RequestInput::header( 'accept' ) );
		} finally {
			$_SERVER = $before;
		}
	}
}
