<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use PHPUnit\Framework\TestCase;

final class PublicationSizeFailureTest extends TestCase {
	/** @dataProvider size_limited_routes */
	public function test_real_get_and_head_handlers_report_same_size_failure( string $route, string $publication ): void {
		$get = $this->request( $route, 'GET' );
		$head = $this->request( $route, 'HEAD' );
		self::assertSame( array( 507 ), $get['status'] );
		self::assertSame( $get['status'], $head['status'] );
		self::assertSame( '', $head['body'] );
		$error = json_decode( $get['body'], true, 512, JSON_THROW_ON_ERROR );
		self::assertSame( 507, $error['status'] );
		self::assertSame( $publication, $error['publication'] );
		self::assertStringContainsString( 'No partial output was returned or written', $error['detail'] );
		self::assertArrayNotHasKey( 'items', $error );
		self::assertArrayNotHasKey( 'chunks', $error );
	}

	public static function size_limited_routes(): array {
		return array( array( 'feed', 'feed.json' ), array( 'rag', 'chunks.json' ) );
	}

	private function request( string $route, string $method ): array {
		$script = <<<'PHP'
require $argv[1];
$GLOBALS['cybermaps_mock_status_headers'] = array();
$GLOBALS['cybermaps_mock_options'] = array( 'blog_public' => '1', 'cybermaps_settings' => array(
	'enable_discovery_hub' => '1', 'enable_rag_chunks' => '1', 'ai_feed_full_content' => '1',
	'llms_included_types' => array( 'post' ), 'rag_chunk_size' => 100, 'rag_chunk_overlap' => 50,
) );
$GLOBALS['cybermaps_mock_post_types'] = array( 'post' );
$GLOBALS['cybermaps_mock_post_type_objects'] = array( 'post' => (object) array( 'name' => 'post', 'public' => true ) );
$length = 'feed' === $argv[2] ? \Cybermaps\Discovery\Feed::MAX_OUTPUT_BYTES + 1 : \Cybermaps\Discovery\Chunker::MAX_CHUNKS * 50 + 1;
$GLOBALS['cybermaps_mock_posts'] = array( 1 => new WP_Post( array(
	'ID' => 1, 'post_type' => 'post', 'post_status' => 'publish', 'post_password' => '',
	'post_title' => 'Resource', 'post_content' => str_repeat( 'x', $length ),
	'post_modified_gmt' => '2026-01-01 00:00:00', 'post_date_gmt' => '2026-01-01 00:00:00',
) ) );
$GLOBALS['cybermaps_mock_wp_query_callback'] = static fn( array $args ): array => array_values( $GLOBALS['cybermaps_mock_posts'] );
$_SERVER['REQUEST_METHOD'] = $argv[3];
$_SERVER['REQUEST_URI'] = 'feed' === $argv[2] ? '/feed.json' : '/discovery/chunks/1.json';
ob_start();
register_shutdown_function( static function() {
	$body = ob_get_clean();
	echo json_encode( array( 'body' => $body, 'status' => $GLOBALS['cybermaps_mock_status_headers'] ) );
} );
if ( 'feed' === $argv[2] ) { ( new \Cybermaps\Discovery\Feed() )->handle(); }
else { ( new \Cybermaps\Discovery\RAGChunk() )->handle(); }
PHP;
		$process = proc_open(
			array( PHP_BINARY, '-r', $script, dirname( __DIR__ ) . '/bootstrap.php', $route, $method ),
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
