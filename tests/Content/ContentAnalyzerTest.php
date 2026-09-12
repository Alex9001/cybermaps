<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Content;

use Cybermaps\Content\ContentAnalyzer;
use PHPUnit\Framework\TestCase;

final class ContentAnalyzerTest extends TestCase {
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
}
