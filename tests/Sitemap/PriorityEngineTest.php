<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Sitemap;

use Cybermaps\Sitemap\ProviderIdentity;
use Cybermaps\Sitemap\PriorityEngine;
use PHPUnit\Framework\TestCase;

class PriorityEngineTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_options'] = array();
		$GLOBALS['cybermaps_mock_post_types'] = array();
		$GLOBALS['cybermaps_mock_taxonomies'] = array();
		$GLOBALS['cybermaps_mock_post_type_objects'] = array();
		$GLOBALS['cybermaps_mock_taxonomy_objects'] = array();
	}

	public function test_stored_manual_overrides_are_defensively_clamped(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_discovery_center'] = wp_json_encode(
			array(
				'archetype' => 'blog',
				'overrides' => array(
					'post'    => 9.7,
					'page'    => -4,
					'product' => 'not-numeric',
				),
			)
		);

		$this->assertSame( 1.0, PriorityEngine::calculate( 'post' ) );
		$this->assertSame( 0.0, PriorityEngine::calculate( 'page' ) );
		$this->assertSame( 0.5, PriorityEngine::calculate( 'product' ) );
	}

	public function test_taxonomy_uses_its_wordpress_slug_and_ignores_retired_intent_offsets(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_discovery_center'] = wp_json_encode(
			array(
				'archetype' => 'blog',
				'intents'   => array( 'freshness' ),
			)
		);

		$this->assertSame( 0.9, PriorityEngine::calculate( 'post' ) );
		$this->assertSame( 0.4, PriorityEngine::calculate( 'post_tag' ) );
	}

	public function test_equal_post_type_and_taxonomy_slugs_use_independent_explicit_priorities(): void {
		$this->register_shared_objects();
		$GLOBALS['cybermaps_mock_options']['cybermaps_discovery_center'] = wp_json_encode(
			array(
				'archetype' => 'blog',
				'overrides' => array(
					'shared'           => 0.5,
					'post_type:shared' => 0.9,
					'taxonomy:shared'  => 0.2,
				),
			)
		);

		$this->assertSame( 0.9, PriorityEngine::calculate( ProviderIdentity::post_type( 'shared' ) ) );
		$this->assertSame( 0.2, PriorityEngine::calculate( ProviderIdentity::taxonomy( 'shared' ) ) );
		$this->assertSame(
			0.5,
			PriorityEngine::calculate( 'shared' ),
			'An ambiguous raw caller must use the deterministic legacy fallback.'
		);
	}

	public function test_ambiguous_legacy_priority_remains_a_shared_fallback_until_saved(): void {
		$this->register_shared_objects();
		$GLOBALS['cybermaps_mock_options']['cybermaps_discovery_center'] = wp_json_encode(
			array(
				'archetype' => 'blog',
				'overrides' => array( 'shared' => 0.6 ),
			)
		);

		$this->assertSame( 0.6, PriorityEngine::calculate( ProviderIdentity::post_type( 'shared' ) ) );
		$this->assertSame( 0.6, PriorityEngine::calculate( ProviderIdentity::taxonomy( 'shared' ) ) );
	}

	public function test_misc_inherits_page_weight_but_not_page_disabled_state(): void {
		$GLOBALS['cybermaps_mock_post_types'] = array( 'news', 'page' );
		foreach ( array( 'news', 'page' ) as $name ) {
			$GLOBALS['cybermaps_mock_post_type_objects'][ $name ] = (object) array(
				'name'   => $name,
				'public' => true,
			);
		}
		$GLOBALS['cybermaps_mock_options']['cybermaps_discovery_center'] = wp_json_encode(
			array(
				'archetype' => 'blog',
				'overrides' => array(
					'post_type:news' => 0,
					'post_type:page' => 0.7,
				),
				'disabled' => array(
					'post_type:page' => true,
				),
			)
		);

		$this->assertSame( 0.0, PriorityEngine::calculate( ProviderIdentity::post_type( 'news' ) ) );
		$this->assertSame( 0.0, PriorityEngine::calculate( ProviderIdentity::post_type( 'page' ) ) );
		$this->assertSame( 0.5, PriorityEngine::calculate( ProviderIdentity::NEWS ) );
		$this->assertSame( 0.7, PriorityEngine::calculate( ProviderIdentity::MISC ) );
	}

	public function test_disabled_state_wins_without_erasing_the_positive_override(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_discovery_center'] = wp_json_encode(
			array(
				'archetype' => 'blog',
				'overrides' => array( 'post_type:post' => 0.7 ),
				'disabled'  => array( 'post_type:post' => true ),
			)
		);

		$this->assertSame( 0.0, PriorityEngine::calculate( ProviderIdentity::post_type( 'post' ) ) );

		$GLOBALS['cybermaps_mock_options']['cybermaps_discovery_center'] = wp_json_encode(
			array(
				'archetype' => 'blog',
				'overrides' => array( 'post_type:post' => 0.7 ),
				'disabled'  => array(),
			)
		);

		$this->assertSame( 0.7, PriorityEngine::calculate( ProviderIdentity::post_type( 'post' ) ) );
	}

	private function register_shared_objects(): void {
		$GLOBALS['cybermaps_mock_post_types'] = array( 'shared' );
		$GLOBALS['cybermaps_mock_taxonomies'] = array( 'shared' );
		$GLOBALS['cybermaps_mock_post_type_objects']['shared'] = (object) array(
			'name'   => 'shared',
			'public' => true,
		);
		$GLOBALS['cybermaps_mock_taxonomy_objects']['shared'] = (object) array(
			'name'   => 'shared',
			'public' => true,
		);
	}
}
