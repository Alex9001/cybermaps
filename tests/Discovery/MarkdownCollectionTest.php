<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Discovery\MarkdownCollection;

final class MarkdownCollectionTest extends \WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_home_url'] = 'https://example.com';
		$GLOBALS['cybermaps_mock_post_types'] = array( 'post' );
		$GLOBALS['cybermaps_mock_post_type_objects'] = array(
			'post' => (object) array( 'name' => 'post', 'public' => true ),
		);
		$GLOBALS['cybermaps_mock_permalinks'] = array( 7 => 'https://example.com/guide/' );
		$GLOBALS['cybermaps_mock_posts'] = array(
			7 => (object) array(
				'ID'                => 7,
				'post_title'        => 'Agent [Guide]',
				'post_content'      => '<p>Useful stored content for agents.</p><script>hidden</script>',
				'post_excerpt'      => '',
				'post_type'         => 'post',
				'post_status'       => 'publish',
				'post_password'     => '',
				'post_date_gmt'     => '2026-08-01 10:00:00',
				'post_modified_gmt' => '2026-08-02 10:00:00',
			),
		);
	}

	public function test_collection_uses_eligible_stored_summaries_and_navigation(): void {
		$output = ( new MarkdownCollection() )->render(
			'Guides',
			'https://example.com/guides/',
			array( $GLOBALS['cybermaps_mock_posts'][7] ),
			2,
			'https://example.com/guides/',
			'https://example.com/guides/page/3/',
			array( 'llms_included_types' => array( 'post' ) )
		);

		self::assertStringStartsWith( "# Guides\n", $output );
		self::assertStringContainsString( '- Page: 2', $output );
		self::assertStringContainsString( '### [Agent \\[Guide\\]](https://example.com/guide/)', $output );
		self::assertStringContainsString( 'Useful stored content for agents.', $output );
		self::assertStringNotContainsString( 'hidden', $output );
		self::assertStringContainsString( '- Next: https://example.com/guides/page/3/', $output );
	}

	public function test_collection_reports_an_empty_eligible_page(): void {
		$output = ( new MarkdownCollection() )->render( 'Empty', 'https://example.com/', array(), 1, '', '', array() );
		self::assertStringContainsString( 'No eligible public items', $output );
	}
}
