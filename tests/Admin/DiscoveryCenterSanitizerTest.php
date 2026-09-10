<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\Settings\Sanitizers\DiscoveryCenterSanitizer;
use Cybermaps\Core\CacheManager;
use PHPUnit\Framework\TestCase;

class DiscoveryCenterSanitizerTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_options'] = array(
			'cybermaps_settings' => array(
				'static_engine_mode' => 'off',
			),
		);
		$GLOBALS['cybermaps_mock_scheduled'] = array();
		$GLOBALS['cybermaps_mock_post_types'] = array();
		$GLOBALS['cybermaps_mock_taxonomies'] = array();
		$GLOBALS['cybermaps_mock_post_type_objects'] = array();
		$GLOBALS['cybermaps_mock_taxonomy_objects'] = array();
	}

	public function test_current_matrix_payload_is_preserved_in_a_canonical_shape(): void {
		$result = $this->sanitize(
			array(
				'archetype' => 'blog',
				'overrides' => array(
					'post'     => 0.8,
					'page'     => 0,
					'product'  => '1.0',
				),
				'type_intents' => array(
					'post'    => 'informational',
					'page'    => 'transactional',
					'product' => 'commercial',
				),
			)
		);

		$this->assertSame( 'blog', $result['archetype'] );
		$this->assertSame(
			array(
				'post'    => 0.8,
				'product' => 1,
			),
			$result['overrides']
		);
		$this->assertSame( array( 'page' => true ), $result['disabled'] );
		$this->assertSame(
			array(
				'post'    => 'informational',
				'page'    => 'transactional',
				'product' => 'transactional',
			),
			$result['type_intents']
		);
		$this->assertArrayNotHasKey( 'intents', $result );
		$this->assertArrayNotHasKey( 'blueprint', $result );
	}

	public function test_positive_weights_clamp_to_the_visible_minimum_while_zero_remains_disabled(): void {
		$result = $this->sanitize(
			array(
				'archetype' => 'blog',
				'overrides' => array(
					'post' => 0.01,
					'page' => 0,
				),
			)
		);

		$this->assertSame( array( 'post' => 0.1 ), $result['overrides'] );
		$this->assertSame( array( 'page' => true ), $result['disabled'] );
	}

	public function test_nested_values_are_sanitized_clamped_and_unknown_fields_are_removed(): void {
		$result = $this->sanitize(
			array(
				'archetype' => 'blog<script>',
				'intents' => array(
					'freshness',
					'<script>alert(1)</script>',
					'social_proof',
					'freshness',
					array( 'media_reach' ),
				),
				'overrides' => array(
					'post<script>' => '9.7',
					'page'         => '-4',
					'bad value'    => 'not-numeric',
					'array'        => array( 0.5 ),
				),
				'type_intents' => array(
					'Page<script>' => 'transactional',
					'post'         => '<script>',
					'product'      => 'Commercial',
				),
				'blueprint' => array(
					'post<script>' => '1.4',
					'page'         => '-3',
					'tpm'          => '99999',
					'bad'          => array( 1 ),
				),
				'unknown_html' => '<script>alert(1)</script>',
				'unknown_array' => array( 'unsafe' => '<img src=x onerror=alert(1)>' ),
			)
		);

		$this->assertSame( 'medium-business', $result['archetype'] );
		$this->assertArrayNotHasKey( 'intents', $result );
		$this->assertSame(
			array(
				'postscript' => 1,
			),
			$result['overrides']
		);
		$this->assertSame( array( 'page' => true ), $result['disabled'] );
		$this->assertSame(
			array(
				'pagescript' => 'transactional',
				'post'       => 'informational',
				'product'    => 'transactional',
			),
			$result['type_intents']
		);
		$this->assertArrayNotHasKey( 'blueprint', $result );
		$this->assertArrayNotHasKey( 'unknown_html', $result );
		$this->assertArrayNotHasKey( 'unknown_array', $result );
	}

	public function test_legacy_tag_key_is_normalized_to_wordpress_taxonomy_slug(): void {
		$result = $this->sanitize(
			array(
				'archetype' => 'blog',
				'overrides' => array( 'tag' => 0.4 ),
				'type_intents' => array( 'tag' => 'informational' ),
			)
		);

		$this->assertSame( array( 'post_tag' => 0.4 ), $result['overrides'] );
		$this->assertSame( array( 'post_tag' => 'informational' ), $result['type_intents'] );
	}

	public function test_legacy_four_bucket_intents_fold_into_the_current_two_choices(): void {
		$result = $this->sanitize(
			array(
				'archetype'    => 'blog',
				'type_intents' => array(
					'page' => 'commercial',
					'post' => 'navigational',
				),
			)
		);

		$this->assertSame(
			array(
				'page' => 'transactional',
				'post' => 'informational',
			),
			$result['type_intents']
		);
	}

	public function test_ambiguous_legacy_slug_is_migrated_to_both_object_identities(): void {
		$this->register_shared_objects();

		$result = $this->sanitize(
			array(
				'archetype' => 'blog',
				'overrides' => array( 'shared' => 0.6 ),
				'type_intents' => array( 'shared' => 'transactional' ),
			)
		);

		$this->assertSame(
			array(
				'post_type:shared' => 0.6,
				'taxonomy:shared'  => 0.6,
			),
			$result['overrides']
		);
		$this->assertSame(
			array(
				'post_type:shared' => 'transactional',
				'taxonomy:shared'  => 'transactional',
			),
			$result['type_intents']
		);
	}

	public function test_explicit_identity_wins_while_legacy_slug_fills_the_other_kind(): void {
		$this->register_shared_objects();

		$result = $this->sanitize(
			array(
				'archetype' => 'blog',
				'overrides' => array(
					'shared'           => 0.3,
					'post_type:shared' => 0.9,
				),
				'type_intents' => array(
					'shared'          => 'informational',
					'taxonomy:shared' => 'transactional',
				),
			)
		);

		$this->assertSame( 0.9, $result['overrides']['post_type:shared'] );
		$this->assertSame( 0.3, $result['overrides']['taxonomy:shared'] );
		$this->assertSame( 'informational', $result['type_intents']['post_type:shared'] );
		$this->assertSame( 'transactional', $result['type_intents']['taxonomy:shared'] );
		$this->assertArrayNotHasKey( 'shared', $result['overrides'] );
		$this->assertArrayNotHasKey( 'shared', $result['type_intents'] );
	}

	public function test_sanitizing_strategy_has_no_persistence_side_effects(): void {
		CacheManager::set( 'cybermaps_rss_sitemap', '<xml />', HOUR_IN_SECONDS, 'sitemap' );
		CacheManager::set( 'cybermaps_llms_cache', 'llms', HOUR_IN_SECONDS, 'discovery' );
		CacheManager::set( 'cybermaps_chunk_fixture', array( 'chunk' ), HOUR_IN_SECONDS, 'chunks' );

		$this->sanitize( array( 'archetype' => 'blog' ) );

		$this->assertSame( '<xml />', get_transient( 'cybermaps_rss_sitemap' ) );
		$this->assertSame( 'llms', get_transient( 'cybermaps_llms_cache' ) );
		$this->assertSame( array( 'chunk' ), get_transient( 'cybermaps_chunk_fixture' ) );
	}

	public function test_absent_inactive_tab_payload_preserves_the_saved_strategy(): void {
		$stored = wp_json_encode(
			array(
				'archetype'    => 'blog',
				'overrides'    => array( 'post' => 0.8 ),
				'type_intents' => array( 'post' => 'informational' ),
			)
		);
		$GLOBALS['cybermaps_mock_options']['cybermaps_discovery_center'] = $stored;

		$this->assertSame( $stored, DiscoveryCenterSanitizer::sanitize( null ) );
	}

	public function test_disabled_groups_are_kind_aware_and_false_values_are_removed(): void {
		$this->register_shared_objects();

		$result = $this->sanitize(
			array(
				'archetype' => 'blog',
				'disabled'  => array(
					'shared'           => true,
					'post_type:shared' => false,
					'unknown'          => '0',
				),
			)
		);

		$this->assertSame(
			array(
				'post_type:shared' => true,
				'taxonomy:shared'  => true,
			),
			$result['disabled']
		);
	}

	public function test_absent_payload_encodes_a_historical_array_value_instead_of_erasing_it(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_discovery_center'] = array(
			'archetype' => 'blog',
			'overrides' => array( 'post' => 0.8 ),
		);

		$result = json_decode( DiscoveryCenterSanitizer::sanitize( null ), true );

		$this->assertSame( 'blog', $result['archetype'] );
		$this->assertSame( 0.8, $result['overrides']['post'] );
	}

	public function test_strategy_maps_are_bounded_after_identity_expansion(): void {
		$overrides = array();
		$intents   = array();
		$disabled  = array();
		for ( $index = 0; $index < \Cybermaps\Discovery\PublicationConstraints::CONTENT_GROUP_MAP_MAX + 50; ++$index ) {
			$key = 'late_type_' . $index;
			$overrides[ $key ] = 0.5;
			$intents[ $key ]   = 'informational';
			$disabled[ $key ]  = true;
		}

		$result = $this->sanitize(
			array(
				'overrides'    => $overrides,
				'type_intents' => $intents,
				'disabled'     => $disabled,
			)
		);

		$this->assertCount( \Cybermaps\Discovery\PublicationConstraints::CONTENT_GROUP_MAP_MAX, $result['overrides'] );
		$this->assertCount( \Cybermaps\Discovery\PublicationConstraints::CONTENT_GROUP_MAP_MAX, $result['type_intents'] );
		$this->assertCount( \Cybermaps\Discovery\PublicationConstraints::CONTENT_GROUP_MAP_MAX, $result['disabled'] );
	}

	/**
	 * @param array<string,mixed> $input Raw Discovery Center payload.
	 * @return array<string,mixed>
	 */
	private function sanitize( array $input ): array {
		$encoded = DiscoveryCenterSanitizer::sanitize( wp_json_encode( $input ) );
		$result  = json_decode( (string) $encoded, true );

		$this->assertIsArray( $result );
		return $result;
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
