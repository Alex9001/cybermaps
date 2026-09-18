<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\EdgeOptimizationController;
use Cybermaps\Admin\Settings\Tabs\ContentReview;
use Cybermaps\Core\RequestInput;
use Cybermaps\Discovery\MarkdownNegotiation;
use Cybermaps\MCP\OAuth\DeviceAuthorizationPage;
use PHPUnit\Framework\TestCase;

final class RequestInputBoundaryTest extends TestCase {
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

	public function test_device_codes_reject_arrays_and_overlong_values(): void {
		$before = $_REQUEST;
		try {
			$page = ( new \ReflectionClass( DeviceAuthorizationPage::class ) )->newInstanceWithoutConstructor();
			$method = new \ReflectionMethod( $page, 'request_value' );
			foreach ( array( array( 'ABCD-EFGH' ), str_repeat( 'x', 21 ) ) as $invalid ) {
				$_REQUEST['user_code'] = $invalid;
				self::assertSame( '', $method->invoke( $page, 'user_code', 20 ) );
			}
			$_REQUEST['user_code'] = '<b>ABCD-EFGH</b>';
			self::assertSame( 'ABCD-EFGH', $method->invoke( $page, 'user_code', 20 ) );
		} finally {
			$_REQUEST = $before;
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

			$_SERVER['REQUEST_URI'] = '/cybermaps-openapi.json?version%5B%5D=3.1.2';
			self::assertSame( '', RequestInput::query_text( 'version', 16 ) );
			$_SERVER['HTTP_ACCEPT'] = "application/json\r\nX-Injected: yes";
			self::assertSame( '', RequestInput::header( 'accept' ) );
		} finally {
			$_SERVER = $before;
		}
	}
}
