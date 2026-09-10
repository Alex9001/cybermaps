<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Discovery\LLMS;

final class LLMSSummaryTest extends \WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_options'] = array(
			'blog_public'       => '1',
			'cybermaps_settings' => array(
				'enable_discovery_hub' => '1',
				'llms_included_types'  => array( 'post' ),
				'llms_link_limit'      => 20,
				'sitemap_url_base'     => 'sitemap',
			),
		);
		$GLOBALS['cybermaps_mock_post_meta'] = array();
		$GLOBALS['cybermaps_mock_post_types'] = array( 'post' );
		$GLOBALS['cybermaps_mock_post_type_objects'] = array(
			'post' => (object) array(
				'name'   => 'post',
				'public' => true,
				'labels' => (object) array( 'name' => 'Posts' ),
			),
		);
		$GLOBALS['cybermaps_mock_posts']      = array();
		$GLOBALS['cybermaps_mock_permalinks'] = array();
		for ( $id = 1; $id <= 22; ++$id ) {
			$GLOBALS['cybermaps_mock_posts'][ $id ] = (object) array(
				'ID'                => $id,
				'post_title'        => 'Resource ' . $id,
				'post_content'      => 'Literal summary ' . $id,
				'post_excerpt'      => '',
				'post_type'         => 'post',
				'post_status'       => 'publish',
				'post_password'     => '',
				'post_modified_gmt' => sprintf( '2026-08-%02d 12:00:00', $id ),
				'post_date_gmt'     => sprintf( '2026-08-%02d 12:00:00', $id ),
			);
			$GLOBALS['cybermaps_mock_permalinks'][ $id ] = 'https://example.com/resource-' . $id . '/';
		}
		LLMS::invalidate_cache();
	}

	protected function tearDown(): void {
		LLMS::invalidate_cache();
		unset( $GLOBALS['cybermaps_mock_permalinks'] );
		parent::tearDown();
	}

	public function test_summary_bounds_excluded_rows_and_does_not_claim_the_site_has_no_content(): void {
		$template = $GLOBALS['cybermaps_mock_posts'][1];
		$GLOBALS['cybermaps_mock_posts'] = array();
		for ( $id = 1; $id <= 1200; ++$id ) {
			$post = clone $template;
			$post->ID = $id;
			$GLOBALS['cybermaps_mock_posts'][ $id ] = $post;
			if ( $id <= 1000 ) { $GLOBALS['cybermaps_mock_post_meta'][ $id ]['_cybermaps_exclude_ai'] = '1'; }
		}
		$output = ( new LLMS() )->get_llms_content( false, true );
		self::assertStringContainsString( 'selected 0 eligible resources within the candidate scan limit', $output );
		self::assertStringContainsString( 'additional content may be available', $output );
		self::assertStringContainsString( '[XML sitemap]', $output );
		self::assertStringNotContainsString( 'No eligible published content is available', $output );
	}

	public function test_concise_map_is_bounded_discloses_coverage_and_links_markdown(): void {
		$output = ( new LLMS() )->get_llms_content();

		self::assertStringContainsString(
			'- [Resource 22](https://example.com/resource-22/index.md): Literal summary 22',
			$output
		);
		self::assertStringContainsString(
			'- [Resource 3](https://example.com/resource-3/index.md): Literal summary 3',
			$output
		);
		self::assertStringNotContainsString( '[Resource 2](', $output );
		self::assertStringContainsString( 'Coverage: selected 20 eligible resources; additional eligible resources are available.', $output );
		self::assertStringContainsString( '- [XML sitemap](https://example.com/sitemap.xml)', $output );
	}
}
