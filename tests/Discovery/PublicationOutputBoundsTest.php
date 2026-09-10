<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Discovery\Actions;
use Cybermaps\Discovery\AIContentSelector;
use Cybermaps\Discovery\AISitemap;
use Cybermaps\Discovery\Feed;
use Cybermaps\Discovery\PublicationConstraints;
use PHPUnit\Framework\TestCase;

final class PublicationOutputBoundsTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_options']       = array();
		$GLOBALS['cybermaps_mock_wp_query_args'] = array();
		$GLOBALS['cybermaps_mock_wp_query_posts'] = array();
		$GLOBALS['cybermaps_mock_posts']          = array();
		$GLOBALS['cybermaps_mock_post_meta']      = array();
		$GLOBALS['cybermaps_mock_post_types']     = array();
		$GLOBALS['cybermaps_mock_post_type_objects'] = array();
		unset( $GLOBALS['cybermaps_mock_wp_query_callback'] );
	}

	public function test_feed_clamps_a_legacy_unbounded_saved_limit_at_runtime(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'ai_feed_limit' => PHP_INT_MAX,
		);

		$data = json_decode( ( new Feed() )->get_json_content(), true );

		self::assertLessThanOrEqual( 250, $GLOBALS['cybermaps_mock_wp_query_args'][0]['posts_per_page'] );
		self::assertLessThanOrEqual( PublicationConstraints::FEED_LIMIT_MAX, count( $data['items'] ) );
	}

	public function test_feed_is_valid_and_does_not_query_when_posts_are_publish_off(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'ai_feed_limit' => 25,
		);
		$GLOBALS['cybermaps_mock_options']['cybermaps_discovery_center'] = wp_json_encode(
			array(
				'archetype' => 'blog',
				'disabled'  => array( 'post_type:post' => true ),
			)
		);

		$data = json_decode( ( new Feed() )->get_json_content(), true );

		self::assertIsArray( $data );
		self::assertSame( 'https://jsonfeed.org/version/1.1', $data['version'] );
		self::assertSame( 'https://example.com/feed.json', $data['feed_url'] );
		self::assertSame( array(), $data['items'] );
		self::assertSame( array(), $GLOBALS['cybermaps_mock_wp_query_args'] );
	}

	public function test_feed_pages_past_excluded_recent_posts_to_fill_its_item_limit(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'ai_feed_limit' => 2,
		);
		$GLOBALS['cybermaps_mock_post_types'] = array( 'post' );
		$GLOBALS['cybermaps_mock_post_type_objects']['post'] = (object) array(
			'name'   => 'post',
			'public' => true,
		);
		$GLOBALS['cybermaps_mock_posts']     = array();
		$GLOBALS['cybermaps_mock_post_meta'] = array();

		$excluded = array();
		for ( $id = 1; $id <= 250; ++$id ) {
			$post = (object) array(
				'ID'            => $id,
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_password' => '',
				'post_title'    => 'Excluded ' . $id,
			);
			$excluded[] = $post;
			$GLOBALS['cybermaps_mock_posts'][ $id ] = $post;
			$GLOBALS['cybermaps_mock_post_meta'][ $id ]['_cybermaps_exclude_ai'] = '1';
		}

		$eligible = array();
		foreach ( array( 251, 252 ) as $id ) {
			$post = (object) array(
				'ID'            => $id,
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_password' => '',
				'post_title'    => 'Eligible ' . $id,
				'post_excerpt'  => 'Summary ' . $id,
			);
			$eligible[] = $post;
			$GLOBALS['cybermaps_mock_posts'][ $id ] = $post;
		}

		$GLOBALS['cybermaps_mock_wp_query_callback'] = static function ( array $args ) use ( $excluded, $eligible ): array {
			return 1 === (int) ( $args['paged'] ?? 1 ) ? $excluded : $eligible;
		};

		$data = json_decode( ( new Feed() )->get_json_content(), true );

		self::assertCount( 2, $data['items'] );
		self::assertSame( array( '251', '252' ), array_column( $data['items'], 'id' ) );
		self::assertSame( array( 1, 2 ), array_column( $GLOBALS['cybermaps_mock_wp_query_args'], 'paged' ) );
	}

	public function test_feed_uses_json_feed_websub_hub_descriptors(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'enable_discovery_hub' => '1',
			'enable_websub'        => '1',
			'websub_hubs'          => 'https://hub.example.com/',
		);

		$data = json_decode( ( new Feed() )->get_json_content(), true );

		self::assertIsArray( $data );
		self::assertSame(
			array(
				array(
					'type' => 'WebSub',
					'url'  => 'https://hub.example.com/',
				),
			),
			$data['hubs']
		);
		self::assertSame( 'https://example.com/feed.json', $data['feed_url'] );
	}

	public function test_actions_clamp_saved_rows_and_repair_legacy_lowercase_types(): void {
		$mappings = array();
		for ( $index = 1; $index <= 105; ++$index ) {
			$mappings[] = array(
				'url'  => 'https://example.com/contact-' . $index,
				'type' => 'contactaction',
				'desc' => 'Contact ' . $index,
			);
		}
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'ai_action_mappings' => $mappings,
		);

		$data    = ( new Actions() )->get_action_data();
		$actions = $data['potentialAction'];

		// One bounded custom set plus Cybermaps' automatic SearchAction.
		self::assertCount( PublicationConstraints::ACTION_MAPPINGS_MAX + 1, $actions );
		self::assertSame( 'ContactAction', $actions[0]['@type'] );
		self::assertSame( 'SearchAction', $actions[ PublicationConstraints::ACTION_MAPPINGS_MAX ]['@type'] );
	}

	public function test_actions_omit_unsafe_legacy_target_urls_at_runtime(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'ai_action_mappings' => array(
				array(
					'url'  => 'javascript:alert(1)',
					'type' => 'ContactAction',
					'desc' => 'Unsafe protocol',
				),
				array(
					'url'  => 'https://user:secret@example.com/contact',
					'type' => 'ContactAction',
					'desc' => 'Credentials',
				),
			),
		);

		$actions = ( new Actions() )->get_action_data()['potentialAction'];

		self::assertCount( 1, $actions );
		self::assertSame( 'SearchAction', $actions[0]['@type'] );
	}

	public function test_actions_search_template_preserves_plain_permalink_rest_query(): void {
		$GLOBALS['cybermaps_mock_rest_url_callback'] = static fn( string $path ): string =>
			'https://example.com/?rest_route=%2F' . rawurlencode( $path );

		try {
			$actions = ( new Actions() )->get_action_data()['potentialAction'];
		} finally {
			unset( $GLOBALS['cybermaps_mock_rest_url_callback'] );
		}

		self::assertSame(
			'https://example.com/?rest_route=%2Fcybermaps%2Fv1%2Fsearch&q={search_term_string}',
			$actions[0]['target']['urlTemplate']
		);
	}

	public function test_text_and_policy_publications_ignore_malformed_nested_settings_without_warnings(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'llms_title_override'       => array( 'Nested title' ),
			'llms_mission_statement'    => new \stdClass(),
			'llms_custom_instructions'  => array( 'Nested guidance' ),
			'site_guide_instructions'   => new \stdClass(),
			'llms_content_license'      => array( 'CC-BY-4.0' ),
			'ai_usage_rag'              => array( 'forbid' ),
			'ai_usage_training'         => new \stdClass(),
			'ai_usage_commercial'       => array( 'allow' ),
			'ai_licensing_email'        => array( 'licensing@example.com' ),
			'ai_action_mappings'        => array(
				array(
					'url'  => 'https://example.com/contact',
					'type' => array( 'ContactAction' ),
					'desc' => array( 'Description' ),
				),
			),
		);

		set_error_handler(
			static function ( int $severity, string $message, string $file, int $line ): never {
				throw new \ErrorException( $message, 0, $severity, $file, $line );
			}
		);
		try {
			$llms    = ( new \Cybermaps\Discovery\LLMS() )->get_llms_content( false, true );
			$guide   = ( new \Cybermaps\Discovery\Capabilities() )->get_skill_markdown();
			$policy  = ( new \Cybermaps\Discovery\UsagePolicy() )->get_policy_data();
			$actions = ( new Actions() )->get_action_data();
		} finally {
			restore_error_handler();
		}

		self::assertStringNotContainsString( 'Array', $llms );
		self::assertStringNotContainsString( 'Nested guidance', $guide );
		self::assertArrayNotHasKey( 'license', $policy );
		self::assertArrayNotHasKey( 'contacts', $policy );
		self::assertSame( 'allow', $policy['policy']['rag_usage'] );
		self::assertSame( 'forbid', $policy['policy']['foundation_training'] );
		self::assertSame( 'forbid', $policy['policy']['commercial_use'] );
		self::assertCount( 1, $actions['potentialAction'] );
		self::assertSame( 'SearchAction', $actions['potentialAction'][0]['@type'] );
	}

	public function test_ai_sitemap_clamps_custom_links_without_fabricating_lastmod(): void {
		$links = array();
		for ( $index = 1; $index <= 105; ++$index ) {
			$links[] = array(
				'url'      => 'https://external.example/resource-' . $index,
				'priority' => 0.5,
			);
		}
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'ai_sitemap_custom_links' => $links,
		);
		$selector = new class() extends AIContentSelector {
			public function get_posts(): array {
				return array();
			}
		};

		$xml = ( new AISitemap( $selector ) )->get_content();

		self::assertSame( PublicationConstraints::CUSTOM_LINKS_MAX, substr_count( $xml, '<url>' ) );
		self::assertStringNotContainsString( '<lastmod>', $xml );
		self::assertStringNotContainsString( 'resource-101', $xml );
	}
}
