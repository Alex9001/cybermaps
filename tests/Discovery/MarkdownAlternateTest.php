<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Discovery\MarkdownAlternate;

final class MarkdownAlternateTest extends \WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_options'] = array(
			'blog_public'       => '1',
			'cybermaps_settings' => array(
				'enable_discovery_hub' => '1',
				'llms_included_types'  => array( 'post' ),
			),
		);
		$GLOBALS['cybermaps_mock_home_url'] = 'https://example.com';
		$GLOBALS['cybermaps_mock_post_meta'] = array();
		$GLOBALS['cybermaps_mock_post_types'] = array( 'post' );
		$GLOBALS['cybermaps_mock_post_type_objects'] = array(
			'post' => (object) array(
				'name'   => 'post',
				'public' => true,
				'labels' => (object) array( 'name' => 'Posts' ),
			),
		);
		$GLOBALS['cybermaps_mock_posts'] = array(
			7 => (object) array(
				'ID'                => 7,
				'post_title'        => 'Setup [Guide]',
				'post_content'      => '<p>Hello <strong>world</strong>.</p>[demo]Literal shortcode body[/demo]<script>ignore me</script>',
				'post_excerpt'      => '',
				'post_type'         => 'post',
				'post_status'       => 'publish',
				'post_password'     => '',
				'post_modified_gmt' => '2026-08-20 12:00:00',
				'post_date_gmt'     => '2026-08-19 12:00:00',
			),
		);
		$GLOBALS['cybermaps_mock_permalinks'] = array(
			7 => 'https://example.com/guides/setup/',
		);
		$GLOBALS['cybermaps_mock_url_to_postid'] = array(
			'https://example.com/guides/setup/' => 7,
		);
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['cybermaps_mock_home_url'],
			$GLOBALS['cybermaps_mock_permalinks'],
			$GLOBALS['cybermaps_mock_url_to_postid']
		);
		parent::tearDown();
	}

	public function test_builds_markdown_urls_for_pretty_filename_and_plain_permalinks(): void {
		self::assertSame(
			'https://example.com/guides/setup/index.md',
			MarkdownAlternate::url_for_post( 7 )
		);

		$GLOBALS['cybermaps_mock_permalinks'][7] = 'https://example.com/about.html';
		self::assertSame(
			'https://example.com/about.html.md',
			MarkdownAlternate::url_for_post( 7 )
		);

		$GLOBALS['cybermaps_mock_permalinks'][7] = 'https://example.com/?p=7';
		self::assertSame(
			'https://example.com/?p=7&cybermaps_markdown=1',
			MarkdownAlternate::url_for_post( 7 )
		);
	}

	public function test_resolves_pretty_and_plain_candidates_without_broad_path_claims(): void {
		$alternate = new MarkdownAlternate();

		self::assertTrue( MarkdownAlternate::is_candidate_request( '/guides/setup/index.md', array() ) );
		self::assertSame( '/guides/setup/', MarkdownAlternate::source_path( '/guides/setup/index.md' ) );
		self::assertSame( 7, $alternate->resolve_post_id( '/guides/setup/index.md' ) );
		self::assertSame(
			7,
			$alternate->resolve_post_id(
				'/',
				array(
					'p'                    => '7',
					'cybermaps_markdown' => '1',
				)
			)
		);
		self::assertFalse( MarkdownAlternate::is_candidate_request( '/guides/setup/', array() ) );
	}

	public function test_content_is_literal_bounded_markdown_with_source_metadata(): void {
		$content = ( new MarkdownAlternate() )->get_content( 7 );

		self::assertStringStartsWith( "# Setup \\[Guide\\]\n", $content );
		self::assertStringContainsString( '- Source: https://example.com/guides/setup/', $content );
		self::assertStringContainsString( '- Language: en-US', $content );
		self::assertStringContainsString( '- Last-Modified: 2026-08-20T12:00:00+00:00', $content );
		self::assertStringContainsString( "## Content\n\nHello world.", $content );
		self::assertStringContainsString( 'Literal shortcode body', $content );
		self::assertStringNotContainsString( '[demo]', $content );
		self::assertStringNotContainsString( 'ignore me', $content );
	}

	public function test_markdown_response_links_back_to_html_and_llms(): void {
		$links = ( new MarkdownAlternate() )->get_markdown_response_links( 7 );

		self::assertSame(
			array(
				'<https://example.com/guides/setup/>; rel="canonical"; type="text/html"',
				'<https://example.com/llms.txt>; rel="describedby"; type="text/markdown"',
			),
			$links
		);
	}
}
