<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Discovery\IntentEngine;
use Cybermaps\Sitemap\ProviderIdentity;
use PHPUnit\Framework\TestCase;

final class IntentEngineTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_options'] = array();
		$GLOBALS['cybermaps_mock_post_meta'] = array();
		$GLOBALS['cybermaps_mock_post_types'] = array( 'shared' );
		$GLOBALS['cybermaps_mock_taxonomies'] = array( 'shared' );
		$GLOBALS['cybermaps_mock_post_type_objects'] = array(
			'shared' => (object) array(
				'name'   => 'shared',
				'public' => true,
			),
		);
		$GLOBALS['cybermaps_mock_taxonomy_objects'] = array(
			'shared' => (object) array(
				'name'   => 'shared',
				'public' => true,
			),
		);
	}

	public function test_post_and_term_scopes_resolve_their_independent_identity_intents(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_discovery_center'] = wp_json_encode(
			array(
				'type_intents' => array(
					'shared'           => 'informational',
					'post_type:shared' => 'transactional',
					'taxonomy:shared'  => 'informational',
				),
			)
		);

		$this->assertSame( 'transactional', IntentEngine::calculate( 10, 'post', 'shared' ) );
		$this->assertSame( 'informational', IntentEngine::calculate( 20, 'term', 'shared' ) );
		$this->assertSame(
			'transactional',
			IntentEngine::calculate( ProviderIdentity::post_type( 'shared' ), 'type' )
		);
		$this->assertSame(
			'informational',
			IntentEngine::calculate( ProviderIdentity::taxonomy( 'shared' ), 'type' )
		);
	}

	public function test_ambiguous_legacy_intent_is_a_deterministic_shared_fallback(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_discovery_center'] = wp_json_encode(
			array(
				'type_intents' => array( 'shared' => 'transactional' ),
			)
		);

		$this->assertSame( 'transactional', IntentEngine::calculate( 10, 'post', 'shared' ) );
		$this->assertSame( 'transactional', IntentEngine::calculate( 20, 'term', 'shared' ) );
		$this->assertSame( 'transactional', IntentEngine::calculate( 'shared', 'type' ) );
	}

	public function test_explicit_identity_overrides_legacy_only_for_its_kind(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_discovery_center'] = wp_json_encode(
			array(
				'type_intents' => array(
					'shared'           => 'informational',
					'post_type:shared' => 'transactional',
				),
			)
		);

		$this->assertSame( 'transactional', IntentEngine::calculate( 10, 'post', 'shared' ) );
		$this->assertSame( 'informational', IntentEngine::calculate( 20, 'term', 'shared' ) );
	}

	public function test_site_profile_supplies_runtime_intent_when_a_row_has_no_override(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_discovery_center'] = wp_json_encode(
			array(
				'archetype'    => 'medium-business',
				'type_intents' => array(),
			)
		);

		$this->assertSame( 'transactional', IntentEngine::calculate( 10, 'post', 'page' ) );
		$this->assertSame( 'informational', IntentEngine::calculate( 11, 'post', 'post' ) );
	}

	public function test_explicit_row_intent_takes_precedence_over_the_profile(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_discovery_center'] = wp_json_encode(
			array(
				'archetype' => 'medium-business',
				'type_intents' => array(
					'post_type:page' => 'informational',
				),
			)
		);

		$this->assertSame( 'informational', IntentEngine::calculate( 10, 'post', 'page' ) );
	}
}
