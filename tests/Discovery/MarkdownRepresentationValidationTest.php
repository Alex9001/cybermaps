<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use PHPUnit\Framework\TestCase;

final class MarkdownRepresentationValidationTest extends TestCase {
	/** @dataProvider routes */
	public function test_url_mapping_changes_cannot_return_a_post_date_304( string $route ): void {
		$before = $this->request( $route, 'https://first.example', '', '' );
		$after = $this->request( $route, 'https://second.example', '', 'Thu, 20 Aug 2026 12:00:00 GMT' );
		self::assertStringContainsString( 'https://first.example/guides/setup/', $before['body'] );
		self::assertStringContainsString( 'https://second.example/guides/setup/', $after['body'] );
		self::assertNotSame( $before['body'], $after['body'] );
		self::assertSame( array( 200 ), $after['status'] );
		self::assertSame( array(), array_values( preg_grep( '/^Last-Modified:/i', $after['headers'] ) ) );
		$etag = substr( array_values( preg_grep( '/^ETag:/', $after['headers'] ) )[0], 6 );
		$cached = $this->request( $route, 'https://second.example', $etag, '' );
		self::assertSame( array( 200, 304 ), $cached['status'] );
		self::assertSame( '', $cached['body'] );
		$fresh = $this->request( $route, 'https://first.example', $etag, 'Thu, 20 Aug 2026 12:00:00 GMT' );
		self::assertSame( array( 200 ), $fresh['status'] );
		self::assertNotSame( '', $fresh['body'] );
	}

	public static function routes(): array {
		return array( array( 'alternate' ), array( 'negotiated' ) );
	}

	private function request( string $route, string $frontend, string $etag, string $modified ): array {
		$script = <<<'PHP'
namespace Cybermaps\Discovery {
	function header( $value, $replace = true ) { $GLOBALS['captured_headers'][] = $value; }
	function headers_list() { return $GLOBALS['captured_headers']; }
	function header_remove( $name ) {}
}
namespace {
	require $argv[1];
	function get_queried_object() { return get_post( 7 ); }
	$GLOBALS['captured_headers'] = array();
	$GLOBALS['cybermaps_mock_status_headers'] = array();
	$GLOBALS['cybermaps_mock_is_singular'] = true;
	$GLOBALS['cybermaps_mock_options'] = array( 'blog_public' => '1', 'cybermaps_settings' => array(
		'enable_discovery_hub' => '1', 'enable_markdown_negotiation' => '1', 'frontend_base_url' => $argv[3],
	) );
	$GLOBALS['cybermaps_mock_post_types'] = array( 'post' );
	$GLOBALS['cybermaps_mock_post_type_objects'] = array( 'post' => (object) array( 'name' => 'post', 'public' => true ) );
	$GLOBALS['cybermaps_mock_posts'] = array( 7 => (object) array(
		'ID' => 7, 'post_type' => 'post', 'post_status' => 'publish', 'post_password' => '',
		'post_title' => 'Guide', 'post_content' => '<p>Stored body</p>', 'post_modified_gmt' => '2026-08-20 12:00:00',
	) );
	$GLOBALS['cybermaps_mock_permalinks'] = array( 7 => 'https://example.com/guides/setup/' );
	$GLOBALS['cybermaps_mock_url_to_postid'] = array( 'https://example.com/guides/setup/' => 7 );
	$_SERVER['REQUEST_METHOD'] = 'GET';
	$_SERVER['REQUEST_URI'] = 'alternate' === $argv[2] ? '/guides/setup/index.md' : '/guides/setup/';
	$_SERVER['HTTP_ACCEPT'] = 'text/markdown';
	$_SERVER['HTTP_IF_NONE_MATCH'] = $argv[4];
	$_SERVER['HTTP_IF_MODIFIED_SINCE'] = $argv[5];
	ob_start();
	register_shutdown_function( static function() {
		$body = ob_get_clean();
		echo json_encode( array( 'body' => $body, 'headers' => $GLOBALS['captured_headers'], 'status' => $GLOBALS['cybermaps_mock_status_headers'] ) );
	} );
	if ( 'alternate' === $argv[2] ) { ( new \Cybermaps\Discovery\MarkdownAlternate() )->handle(); }
	else { ( new \Cybermaps\Discovery\MarkdownNegotiation() )->handle(); }
}
PHP;
		$process = proc_open(
			array( PHP_BINARY, '-r', $script, dirname( __DIR__ ) . '/bootstrap.php', $route, $frontend, $etag, $modified ),
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
