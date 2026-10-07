<?php
declare(strict_types=1);
namespace Cybermaps\Tests\Discovery;
use PHPUnit\Framework\TestCase;
final class MarkdownCollectionCanonicalTest extends TestCase {
	/** @dataProvider collections */
	public function test_source_and_canonical_preserve_plain_resource_identity( string $path, array $query, string $suffix ): void {
		$script = <<<'CODE'
namespace Cybermaps\Discovery { function get_query_var( $key, $default = '' ) { return $GLOBALS['canonical_query'][$key] ?? $default; } }
namespace {
require $argv[1];
$GLOBALS['canonical_query'] = json_decode( $argv[3], true );
$GLOBALS['cybermaps_mock_options'] = array( 'blog_public' => '1', 'cybermaps_settings' => array( 'frontend_base_url' => 'https://frontend.example' ) );
$_SERVER['REQUEST_URI'] = $argv[2];
$GLOBALS['wp_query'] = (object) array( 'posts' => array(), 'max_num_pages' => 3 );
$data = ( new ReflectionMethod( \Cybermaps\Discovery\MarkdownNegotiation::class, 'collection_representation' ) )->invoke( new \Cybermaps\Discovery\MarkdownNegotiation(), array() );
echo json_encode( $data );
}
CODE;
		$process = proc_open( array( PHP_BINARY, '-r', $script, dirname( __DIR__ ) . '/bootstrap.php', $path, json_encode( $query ) ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		self::assertIsResource( $process );
		$output = stream_get_contents( $pipes[1] ); $error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] ); fclose( $pipes[2] );
		self::assertSame( 0, proc_close( $process ), $error );
		self::assertSame( '', $error );
		$data = json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
		$url = 'https://frontend.example' . $suffix;
		self::assertStringContainsString( $url, $data['content'] );
		self::assertSame( array( '<' . $url . '>; rel="canonical"; type="text/html"' ), $data['links'] );
		self::assertStringNotContainsString( 'private-token', $data['content'] );
	}
	public static function collections(): array {
		return array(
			array( '/?cat=3&paged=2', array( 'cat' => 3, 'paged' => 2, 'private-token' => 'secret' ), '/?cat=3&paged=2' ),
			array( '/?tag=guide&page=2', array( 'tag' => 'guide', 'page' => 2 ), '/?tag=guide&page=2' ),
			array( '/?author=5&paged=2', array( 'author' => 5, 'paged' => 2 ), '/?author=5&paged=2' ),
			array( '/?year=2026&monthnum=9', array( 'year' => 2026, 'monthnum' => 9 ), '/?year=2026&monthnum=9' ),
			array( '/?post_type=portfolio&paged=2', array( 'post_type' => 'portfolio', 'paged' => 2 ), '/?post_type=portfolio&paged=2' ),
			array( '/?taxonomy=genre&term=guide', array( 'taxonomy' => 'genre', 'term' => 'guide' ), '/?taxonomy=genre&term=guide' ),
			array( '/category/guide/page/2/', array( 'category_name' => 'guide', 'paged' => 2 ), '/category/guide/page/2/' ),
		);
	}
}
