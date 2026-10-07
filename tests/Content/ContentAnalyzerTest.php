<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Content;

use Cybermaps\Content\ContentAnalyzer;
use PHPUnit\Framework\TestCase;

final class ContentAnalyzerTest extends TestCase {
	/** @dataProvider hidden_trees */
	public function test_nested_hidden_trees_are_absent_from_every_public_analysis_field( string $hidden, bool $complete ): void {
		$content = '<p>Visible start.</p>' . $hidden;
		$analyzer = new ContentAnalyzer( false );
		$analysis = $analyzer->analyze_content( $content, 'https://example.com/' );
		$fallback = ( new \ReflectionMethod( ContentAnalyzer::class, 'extract_fallback' ) )->invoke( $analyzer, $content, 'https://example.com/' );
		self::assertStringNotContainsString( 'Hidden', $analysis['text'] );
		self::assertStringNotContainsString( 'Hidden', $analysis['markdown'] );
		self::assertStringNotContainsString( 'Hidden', $fallback['markdown'] );
		self::assertSame( array(), $analysis['links'] );
		self::assertSame( array(), $analysis['headings'] );
		self::assertSame( $complete, $analysis['complete'] );
		self::assertSame( $complete, $fallback['complete'] );
		self::assertSame( $complete ? "Visible start.\n\nVisible end." : 'Visible start.', $analysis['text'] );
	}

	public static function hidden_trees(): array {
		return array(
			'nested templates' => array( '<template><template>Hidden inner</template><h2>Hidden outer</h2><a href="/hidden">Hidden link</a></template><p>Visible end.</p>', true ),
			'quoted tag attribute' => array( '<template title="a > b"><template>Hidden inner</template>Hidden outer</template><p>Visible end.</p>', true ),
			'nested noscript' => array( '<noscript><noscript>Hidden inner</noscript>Hidden outer</noscript><p>Visible end.</p>', true ),
			'unclosed outer' => array( '<template><template>Hidden inner</template>Hidden outer<p>Hidden trailing</p>', false ),
		);
	}

	public function test_preserves_headings_and_resolves_safe_links_in_markdown(): void {
		$analysis = ( new ContentAnalyzer( false ) )->analyze_content(
			'<h2>Useful guide</h2><p>Read <a href="../start/">the start</a>, <a href="javascript:alert(1)">avoid this</a>, and <a href="mailto:test@example.com">email</a>.</p>',
			'https://example.com/guides/topic/'
		);

		$this->assertStringContainsString( '## Useful guide', $analysis['markdown'] );
		$this->assertStringContainsString( '[the start](https://example.com/guides/start/)', $analysis['markdown'] );
		$this->assertStringContainsString( 'avoid this', $analysis['markdown'] );
		$this->assertStringNotContainsString( 'javascript:', $analysis['markdown'] );
		$this->assertStringNotContainsString( 'mailto:', $analysis['markdown'] );
		$this->assertSame(
			array(
				array(
					'level' => 2,
					'text'  => 'Useful guide',
				),
			),
			$analysis['headings']
		);
		$this->assertSame( 'https://example.com/guides/start/', $analysis['links'][0]['url'] );
	}

	public function test_nonvisible_content_is_removed_from_all_outputs(): void {
		$analysis = ( new ContentAnalyzer( false ) )->analyze_content(
			'<p>Visible.</p><script>secret()</script><!-- <a href="/hidden/">hidden</a> -->'
		);

		$this->assertSame( 'Visible.', $analysis['text'] );
		$this->assertSame( 'Visible.', $analysis['markdown'] );
		$this->assertSame( array(), $analysis['links'] );
	}

	/** @dataProvider literal_relative_links */
	public function test_relative_resolution_applies_dot_segments_only_to_the_path( string $href, string $expected ): void {
		$analyzer = new ContentAnalyzer( false );
		$this->assertSame( $expected, $analyzer->resolve_link_url( $href, 'https://example.com/docs/' ) );
		$analysis = $analyzer->analyze_content( '<a href="' . htmlspecialchars( $href, ENT_QUOTES ) . '">Literal link</a>', 'https://example.com/docs/' );
		$this->assertTrue( $analysis['complete'] );
		$this->assertSame( $expected, $analysis['links'][0]['url'] );
		$this->assertStringContainsString( '](' . $expected . ')', $analysis['markdown'] );
	}

	public static function literal_relative_links(): array {
		return array(
			array( 'item?next=/../private', 'https://example.com/docs/item?next=/../private' ),
			array( 'item#section/../target', 'https://example.com/docs/item#section/../target' ),
			array( '../item?next=../../private#section/../target', 'https://example.com/item?next=../../private#section/../target' ),
			array( './child/../item/?next=../x', 'https://example.com/docs/item/?next=../x' ),
			array( '#section/../target', 'https://example.com/docs/#section/../target' ),
			array( '?next=/../private', 'https://example.com/docs/?next=/../private' ),
		);
	}

	public function test_fallback_decodes_literal_entities_once_after_removing_actual_tags(): void {
		$analysis = ( new ContentAnalyzer( false ) )->analyze_content( '<h2>&amp;copy;</h2><p>&amp;lt;em&amp;gt;literal&amp;lt;/em&amp;gt;</p><a href="/guide">&lt;em&gt;literal&lt;/em&gt;</a>', 'https://example.com/' );
		$this->assertSame( '&copy;', $analysis['headings'][0]['text'] );
		$this->assertSame( '<em>literal</em>', $analysis['links'][0]['text'] );
		$this->assertStringContainsString( '&lt;em&gt;literal&lt;/em&gt;', $analysis['text'] );
		$this->assertStringContainsString( '&copy;', $analysis['markdown'] );
	}

	/** @dataProvider fallback_collection_cases */
	public function test_fallback_bounds_valid_collections_and_reports_overflow(
		string $record,
		string $collection,
		int $count,
		int $maximum,
		string $extra,
		bool $complete
	): void {
		$method = new \ReflectionMethod( ContentAnalyzer::class, 'extract_fallback' );
		$result = $method->invoke( new ContentAnalyzer( false, 33554431 ), $extra . str_repeat( $record, $count ) . $extra, 'https://example.com/' );
		self::assertCount( min( $count, $maximum ), $result[ $collection ] );
		self::assertSame( $complete, $result['complete'] );
		self::assertStringContainsString( 'Visible', $result['markdown'] );
	}

	public static function fallback_collection_cases(): array {
		$link = '<a href="https://example.com/">Visible</a>';
		$heading = '<h2>Visible</h2>';
		return array(
			'exact link limit' => array( $link, 'links', 100000, 100000, '', true ),
			'link overflow' => array( $link, 'links', 100001, 100000, '', false ),
			'unsafe links do not consume capacity' => array( $link, 'links', 100000, 100000, '<a href="javascript:alert(1)">Unsafe</a><a href="mailto:a@example.com">Email</a>', true ),
			'exact heading limit' => array( $heading, 'headings', 10000, 10000, '', true ),
			'heading overflow' => array( $heading, 'headings', 10001, 10000, '', false ),
			'empty headings do not consume capacity' => array( $heading, 'headings', 10000, 10000, '<h2> </h2>', true ),
		);
	}

	public function test_public_analysis_propagates_fallback_overflow_completeness(): void {
		if ( class_exists( '\\WP_HTML_Tag_Processor' ) ) {
			self::markTestSkipped( 'This regression targets the isolated environment without the WordPress HTML API.' );
		}
		$result = ( new ContentAnalyzer( false ) )->analyze_content( str_repeat( '<h3>Visible</h3>', 10001 ) );
		self::assertCount( 10000, $result['headings'] );
		self::assertFalse( $result['complete'] );
	}
}
