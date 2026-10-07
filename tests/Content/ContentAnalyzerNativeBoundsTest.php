<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Content;

use PHPUnit\Framework\TestCase;

/** Ordinary fixtures exercised through the installed WordPress HTML API. */
final class ContentAnalyzerNativeBoundsTest extends TestCase {
	public function test_native_and_fallback_preserve_literal_entities_and_url_suffixes(): void {
		$root = getenv( 'CYBERMAPS_WP_HTML_API_ROOT' );
		if ( ! is_string( $root ) || ! is_file( $root . '/class-wp-html-tag-processor.php' ) ) {
			self::markTestSkipped( 'Set CYBERMAPS_WP_HTML_API_ROOT to WordPress 7.1 wp-includes/html-api.' );
		}
		$script = <<<'CODE'
require $argv[1];
require $argv[2] . '/../class-wp-token-map.php';
require $argv[2] . '/html5-named-character-references.php';
spl_autoload_register( static function( $class ) use ( $argv ): void {
	if ( str_starts_with( $class, 'WP_HTML_' ) ) {
		$file = $argv[2] . '/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
		if ( is_file( $file ) ) { require_once $file; }
	}
} );
$analyzer = new \Cybermaps\Content\ContentAnalyzer( false );
$source = '<h2>&amp;copy;</h2><p>&amp;lt;em&amp;gt;literal&amp;lt;/em&amp;gt;</p><a href="/guide">&lt;em&gt;literal&lt;/em&gt;</a>';
$native = $analyzer->analyze_content( $source, 'https://example.com/docs/' );
$fallback = ( new ReflectionMethod( $analyzer, 'extract_fallback' ) )->invoke( $analyzer, $source, 'https://example.com/docs/' );
$links = array();
foreach ( array( 'item?next=/../private', 'item#section/../target', '../item?next=../../private#section/../target', './child/../item/?next=../x' ) as $href ) {
	$source = '<a href="' . htmlspecialchars( $href, ENT_QUOTES ) . '">Literal link</a>';
	$analysis = $analyzer->analyze_content( $source, 'https://example.com/docs/' );
	$fallback_link = ( new ReflectionMethod( $analyzer, 'extract_fallback' ) )->invoke( $analyzer, $source, 'https://example.com/docs/' );
	$links[] = array( $analysis['links'], $fallback_link['links'], $analysis['markdown'], $fallback_link['markdown'] );
}
echo json_encode( array( $native, $fallback, $links ) );
CODE;
		$process = proc_open( array( PHP_BINARY, '-r', $script, dirname( __DIR__ ) . '/bootstrap.php', $root ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		self::assertIsResource( $process );
		$output = stream_get_contents( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] ); fclose( $pipes[2] );
		self::assertSame( 0, proc_close( $process ), $error );
		self::assertSame( '', $error );
		[$native, $fallback, $links] = json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
		foreach ( array( 'markdown', 'headings', 'links', 'complete' ) as $field ) {
			self::assertSame( $native[ $field ], $fallback[ $field ] );
		}
		self::assertSame( '&copy;', $native['headings'][0]['text'] );
		self::assertSame( '<em>literal</em>', $native['links'][0]['text'] );
		self::assertStringContainsString( '&lt;em&gt;literal&lt;/em&gt;', $native['markdown'] );
		$expected = array( 'https://example.com/docs/item?next=/../private', 'https://example.com/docs/item#section/../target', 'https://example.com/item?next=../../private#section/../target', 'https://example.com/docs/item/?next=../x' );
		foreach ( $links as $index => [$native_links, $fallback_links, $native_markdown, $fallback_markdown] ) {
			self::assertSame( $expected[ $index ], $native_links[0]['url'] );
			self::assertSame( $native_links, $fallback_links );
			self::assertSame( $native_markdown, $fallback_markdown );
		}
	}

	public function test_native_attribute_parsing_and_publication_completeness(): void {
		$root = getenv( 'CYBERMAPS_WP_HTML_API_ROOT' );
		if ( ! is_string( $root ) || ! is_file( $root . '/class-wp-html-tag-processor.php' ) ) {
			self::markTestSkipped( 'Set CYBERMAPS_WP_HTML_API_ROOT to WordPress 7.1 wp-includes/html-api.' );
		}
		$script = <<<'CODE'
require $argv[1];
spl_autoload_register( static function( $class ) use ( $argv ): void {
	if ( str_starts_with( $class, 'WP_HTML_' ) ) {
		$file = $argv[2] . '/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
		if ( is_file( $file ) ) { require_once $file; }
	}
} );
$base = 'https://example.com/' . str_repeat( 'path/', 250 );
$output = array();
foreach ( array( 'href="#reference"', "href='#reference'", 'href=#reference', 'HREF = #reference' ) as $attribute ) {
	$source = str_repeat( '<a title=">" ' . $attribute . '>Link</a>', 3 );
	$bounded = ( new \Cybermaps\Content\ContentAnalyzer( false, 3000 ) )->analyze_content( $source, $base );
	$ordinary = ( new \Cybermaps\Content\ContentAnalyzer( false ) )->analyze_content( $source, 'https://example.com/guide/' );
	$output[] = array( 'complete' => $bounded['complete'], 'markdown_bytes' => strlen( $bounded['markdown'] ),
		'link_bytes' => array_sum( array_map( 'strlen', array_column( $bounded['links'], 'url' ) ) ),
		'ordinary_complete' => $ordinary['complete'], 'ordinary_links' => count( $ordinary['links'] ),
		'ordinary_markdown' => $ordinary['markdown'] );
}
$GLOBALS['cybermaps_mock_options'] = array( 'blog_public' => '1', 'cybermaps_settings' => array( 'enable_discovery_hub' => '1', 'enable_rag_chunks' => '1', 'llms_included_types' => array( 'post' ) ) );
$GLOBALS['cybermaps_mock_post_types'] = array( 'post' );
$GLOBALS['cybermaps_mock_post_type_objects'] = array( 'post' => (object) array( 'public' => true ) );
$GLOBALS['cybermaps_mock_posts'][1] = new WP_Post( array( 'ID' => 1, 'post_type' => 'post', 'post_status' => 'publish', 'post_password' => '', 'post_title' => 'Resource', 'post_content' => str_repeat( '<a title=">" href=#reference>Link</a>', 500 ), 'post_modified_gmt' => '2026-01-01 00:00:00' ) );
$GLOBALS['cybermaps_mock_permalinks'][1] = 'https://example.com/' . str_repeat( 'path/', 600 );
$dense = ( new \Cybermaps\Content\ContentAnalyzer( false ) )->analyze_content( '<a href=relative>Link</a>', 'https://example.com/' . str_repeat( 'a/', 4097 ) );
$failures = array();
foreach ( array( new \Cybermaps\Discovery\Chunker( false ), new \Cybermaps\Discovery\MarkdownAlternate(), new \Cybermaps\Discovery\LLMS() ) as $handler ) {
	try {
		if ( $handler instanceof \Cybermaps\Discovery\Chunker ) { $handler->get_chunks( 1 ); }
		elseif ( $handler instanceof \Cybermaps\Discovery\LLMS ) { $handler->get_llms_content( true, true ); }
		else { $handler->get_content( $GLOBALS['cybermaps_mock_posts'][1] ); }
		$failures[] = false;
	} catch ( \Cybermaps\Discovery\PublicationSizeLimitException $error ) { $failures[] = true; }
}
echo json_encode( array( $output, $failures, $dense['complete'], count( $dense['links'] ) ) );
CODE;
		$process = proc_open( array( PHP_BINARY, '-r', $script, dirname( __DIR__ ) . '/bootstrap.php', $root ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		self::assertIsResource( $process );
		$output = stream_get_contents( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		self::assertSame( 0, proc_close( $process ), $error );
		self::assertSame( '', $error );
		[$fixtures, $failures, $dense_complete, $dense_links] = json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
		foreach ( $fixtures as $fixture ) {
			self::assertFalse( $fixture['complete'] );
			self::assertLessThanOrEqual( 3000, $fixture['markdown_bytes'] + $fixture['link_bytes'] );
			self::assertTrue( $fixture['ordinary_complete'] );
			self::assertSame( 3, $fixture['ordinary_links'] );
			self::assertStringContainsString( 'https://example.com/guide/#reference', $fixture['ordinary_markdown'] );
		}
		self::assertSame( array( true, true, true ), $failures );
		self::assertFalse( $dense_complete );
		self::assertSame( 0, $dense_links );
	}
}
