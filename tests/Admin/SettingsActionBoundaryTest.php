<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use PHPUnit\Framework\TestCase;

final class SettingsActionBoundaryTest extends TestCase {
	public function test_wrong_methods_reject_before_authorization_or_side_effects(): void {
		foreach ( array( 'indexnow' => 'GET', 'export' => 'POST', 'logs' => 'POST' ) as $handler => $method ) {
			foreach ( array( $method, 'HEAD', 'ARRAY', 'MISSING' ) as $wrong_method ) {
				$result = $this->invoke_boundary( $handler, $wrong_method, true, true );
				self::assertSame( 405, $result['status'] );
				self::assertSame( array(), $result['boundary_reads'] );
				self::assertTrue( $result['options_unchanged'] );
				self::assertSame( 0, $result['remote_calls'] );
			}
		}
	}

	public function test_expected_methods_still_require_capability_and_valid_nonce(): void {
		foreach ( array( 'indexnow' => 'POST', 'export' => 'GET', 'logs' => 'GET' ) as $handler => $method ) {
			$no_capability = $this->invoke_boundary( $handler, $method, false, true );
			self::assertSame( 403, $no_capability['status'] );
			self::assertSame( array( 'capability' ), $no_capability['boundary_reads'] );
			$bad_nonce = $this->invoke_boundary( $handler, $method, true, false );
			self::assertSame( 403, $bad_nonce['status'] );
			self::assertSame( array( 'capability', 'nonce' ), $bad_nonce['boundary_reads'] );
			$authorized = $this->invoke_boundary( $handler, $method, true, true );
			self::assertSame( 200, $authorized['status'] );
			self::assertSame( array( 'capability', 'nonce' ), $authorized['boundary_reads'] );
		}
	}

	private function invoke_boundary( string $handler, string $method, bool $capability, bool $nonce ): array {
		$script = <<<'PHP'
namespace Cybermaps\Admin {
	function current_user_can( $capability ) { $GLOBALS['boundary_reads'][] = 'capability'; return $GLOBALS['allowed']; }
	function wp_die( $message = '', $title = '', $args = array() ) { throw new \RuntimeException( 'boundary', $args['response'] ?? 403 ); }
	function check_admin_referer( $action ) { $GLOBALS['boundary_reads'][] = 'nonce'; throw new \RuntimeException( 'authorized boundary reached', $GLOBALS['nonce_valid'] ? 200 : 403 ); }
}
namespace Cybermaps\Admin\Settings {
	function current_user_can( $capability ) { return \Cybermaps\Admin\current_user_can( $capability ); }
	function wp_die( $message = '', $title = '', $args = array() ) { \Cybermaps\Admin\wp_die( $message, $title, $args ); }
	function check_admin_referer( $action ) { \Cybermaps\Admin\check_admin_referer( $action ); }
}
namespace {
	require $argv[1];
	$GLOBALS['boundary_reads'] = array();
	$GLOBALS['allowed'] = '1' === $argv[4];
	$GLOBALS['nonce_valid'] = '1' === $argv[5];
	$GLOBALS['cybermaps_mock_options'] = array( 'boundary_sentinel' => 'unchanged' );
	$before = $GLOBALS['cybermaps_mock_options'];
	$_GET = array( 'include_values' => array( 'poisoned selector' ) );
	$_SERVER['REQUEST_METHOD'] = 'ARRAY' === $argv[3] ? array( 'GET' ) : $argv[3];
	if ( 'MISSING' === $argv[3] ) { unset( $_SERVER['REQUEST_METHOD'] ); }
	$status = 0;
	try {
		if ( 'indexnow' === $argv[2] ) { ( new \Cybermaps\Admin\Settings() )->handle_verify_indexnow_key(); }
		elseif ( 'logs' === $argv[2] ) { ( new \Cybermaps\Admin\Logs() )->handle_export_logs(); }
		else { \Cybermaps\Admin\Settings\SettingsAjax::handle_export_config(); }
	} catch ( \RuntimeException $error ) { $status = $error->getCode(); }
	echo json_encode( array( 'status' => $status, 'boundary_reads' => $GLOBALS['boundary_reads'], 'options_unchanged' => $before === $GLOBALS['cybermaps_mock_options'], 'remote_calls' => count( $GLOBALS['cybermaps_mock_safe_remote_get_calls'] ?? array() ) ) );
}
PHP;
		$process = proc_open(
			array( PHP_BINARY, '-r', $script, dirname( __DIR__ ) . '/bootstrap.php', $handler, $method, $capability ? '1' : '0', $nonce ? '1' : '0' ),
			array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ),
			$pipes
		);
		self::assertIsResource( $process );
		$output = stream_get_contents( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		self::assertSame( 0, proc_close( $process ), $error );
		self::assertSame( '', $error );
		return json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
	}
}
