<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\DiscoveryScope;
use PHPUnit\Framework\TestCase;

final class DiscoveryScopeExecutionTest extends TestCase {
	private const POST_ID = 701;

	protected function setUp(): void {
		parent::setUp();
		$_POST = array();
		$GLOBALS['cybermaps_mock_current_user_capabilities'] = array( 'edit_post' );
		$GLOBALS['cybermaps_mock_post_meta'] = array();
		$GLOBALS['cybermaps_mock_post_type_objects'] = array(
			'post' => (object) array( 'name' => 'post', 'public' => true ),
		);
		$GLOBALS['cybermaps_mock_posts'] = array(
			self::POST_ID => (object) array( 'ID' => self::POST_ID, 'post_type' => 'post' ),
		);
	}

	protected function tearDown(): void {
		$_POST = array();
		parent::tearDown();
	}

	public function test_classic_editor_saves_every_discovery_scope_control(): void {
		$_POST = array(
			'cybermaps_discovery_scope_nonce' => wp_create_nonce( 'cybermaps_discovery_scope_action' ),
			'cybermaps_include_sitemap'       => '1',
			'cybermaps_include_ai'            => '1',
			'cybermaps_intent_override'       => 'transactional',
			'cybermaps_sitemap_priority'      => '0.74',
			'cybermaps_sitemap_changefreq'    => 'daily',
		);

		( new DiscoveryScope() )->save_meta_box_data( self::POST_ID );

		self::assertSame(
			array(
				'_cybermaps_exclude_sitemap'  => '0',
				'_cybermaps_exclude_ai'       => '0',
				'_cybermaps_intent_override'  => 'transactional',
				'_cybermaps_sitemap_priority' => 0.7,
				'_cybermaps_sitemap_changefreq' => 'daily',
			),
			$GLOBALS['cybermaps_mock_post_meta'][ self::POST_ID ]
		);
	}

	public function test_classic_editor_absent_checkboxes_and_default_selects_clear_their_item_overrides(): void {
		$GLOBALS['cybermaps_mock_post_meta'][ self::POST_ID ] = array(
			'_cybermaps_exclude_sitemap'   => '0',
			'_cybermaps_exclude_ai'        => '0',
			'_cybermaps_exclude_search'    => '1',
			'_cybermaps_intent_override'   => 'transactional',
			'_cybermaps_sitemap_priority'  => 0.8,
			'_cybermaps_sitemap_changefreq' => 'monthly',
		);
		$_POST = array(
			'cybermaps_discovery_scope_nonce' => wp_create_nonce( 'cybermaps_discovery_scope_action' ),
			'cybermaps_intent_override'       => '',
			'cybermaps_sitemap_priority'      => '0',
			'cybermaps_sitemap_changefreq'    => '',
		);

		( new DiscoveryScope() )->save_meta_box_data( self::POST_ID );

		self::assertSame(
			array(
				'_cybermaps_exclude_sitemap' => '1',
				'_cybermaps_exclude_ai'      => '1',
			),
			$GLOBALS['cybermaps_mock_post_meta'][ self::POST_ID ]
		);
	}

	public function test_classic_editor_rejects_invalid_item_select_values(): void {
		$_POST = array(
			'cybermaps_discovery_scope_nonce' => wp_create_nonce( 'cybermaps_discovery_scope_action' ),
			'cybermaps_include_sitemap'       => '1',
			'cybermaps_include_ai'            => '1',
			'cybermaps_intent_override'       => 'not-a-real-intent',
			'cybermaps_sitemap_priority'      => '100',
			'cybermaps_sitemap_changefreq'    => 'sometimes',
		);

		( new DiscoveryScope() )->save_meta_box_data( self::POST_ID );

		self::assertSame( 1.0, $GLOBALS['cybermaps_mock_post_meta'][ self::POST_ID ]['_cybermaps_sitemap_priority'] );
		self::assertArrayNotHasKey( '_cybermaps_intent_override', $GLOBALS['cybermaps_mock_post_meta'][ self::POST_ID ] );
		self::assertArrayNotHasKey( '_cybermaps_sitemap_changefreq', $GLOBALS['cybermaps_mock_post_meta'][ self::POST_ID ] );
	}
}
