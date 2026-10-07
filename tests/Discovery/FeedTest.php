<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Discovery\Feed;

final class FeedTest extends \WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_options'] = array();
		$GLOBALS['cybermaps_mock_post_meta'] = array();
		$GLOBALS['cybermaps_mock_post_types'] = array( 'post' );
		$GLOBALS['cybermaps_mock_post_type_objects'] = array( 'post' => (object) array( 'name' => 'post', 'public' => true ) );
		$GLOBALS['cybermaps_mock_posts'] = array();
		foreach ( range( 1, 4 ) as $id ) {
			$GLOBALS['cybermaps_mock_posts'][ $id ] = (object) array(
				'ID' => $id,
				'post_type' => 'post',
				'post_status' => 4 === $id ? 'private' : 'publish',
				'post_password' => 3 === $id ? 'secret' : '',
				'post_title' => 'Feed article',
				'post_excerpt' => '<p>Hello <strong>world</strong>.</p><script>hidden()</script>[demo]Literal[/demo]',
				'post_content' => '<p>Stored content</p>',
			);
		}
		$GLOBALS['cybermaps_mock_post_meta'][2]['_cybermaps_exclude_ai'] = '1';
		$GLOBALS['cybermaps_mock_wp_query_callback'] = static fn( array $args ): array => array_values( $GLOBALS['cybermaps_mock_posts'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['cybermaps_mock_wp_query_callback'] );
		parent::tearDown();
	}

	public function test_summary_mode_has_required_plain_content_and_excludes_nonpublic_posts(): void {
		$data = json_decode( ( new Feed() )->get_json_content(), true, 512, JSON_THROW_ON_ERROR );
		self::assertCount( 1, $data['items'] );
		$item = $data['items'][0];
		self::assertSame( '1', $item['id'] );
		self::assertSame( 'Hello world. Literal', $item['content_text'] );
		self::assertSame( $item['content_text'], $item['summary'] );
		self::assertArrayNotHasKey( 'content_html', $item );
	}

	public function test_summary_without_explicit_excerpt_uses_bounded_literal_stored_content(): void {
		$GLOBALS['cybermaps_mock_posts'][1]->post_excerpt = '';
		$GLOBALS['cybermaps_mock_posts'][1]->post_content = '<p>' . str_repeat( 'word ', 200000 ) . '</p>[demo]Never rendered[/demo]';
		$data = json_decode( ( new Feed() )->get_json_content(), true, 512, JSON_THROW_ON_ERROR );
		self::assertStringStartsWith( 'word word', $data['items'][0]['content_text'] );
		self::assertLessThan( 500, strlen( $data['items'][0]['content_text'] ) );
		self::assertStringNotContainsString( 'Never rendered', $data['items'][0]['content_text'] );
	}

	public function test_full_mode_retains_required_html_content(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array( 'ai_feed_full_content' => '1' );
		$data = json_decode( ( new Feed() )->get_json_content(), true, 512, JSON_THROW_ON_ERROR );
		self::assertCount( 1, $data['items'] );
		self::assertSame( '<p>Stored content</p>', $data['items'][0]['content_html'] );
	}
}
