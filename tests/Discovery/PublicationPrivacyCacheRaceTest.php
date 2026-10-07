<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Core\BuildUnavailableException;
use Cybermaps\Core\CacheManager;
use Cybermaps\Discovery\AIContentSelector;
use Cybermaps\Discovery\Updates;

/** Interleave real cache invalidation with bounded production query adapters. */
final class PublicationPrivacyCacheRaceTest extends \WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();
		\cybermaps_mock_reset_cache_runtime();
		$GLOBALS['cybermaps_mock_options'] = array(
			'blog_public' => '1',
			'cybermaps_settings' => array( 'enable_discovery_hub' => '1', 'ai_sitemap_types' => array( 'post' ), 'ai_sitemap_limit' => 2, 'ai_feed_limit' => 2 ),
			'cybermaps_discovery_center' => '{"archetype":"blog"}',
		);
		$GLOBALS['cybermaps_mock_post_types'] = array( 'post' );
		$GLOBALS['cybermaps_mock_post_type_objects'] = array( 'post' => (object) array( 'name' => 'post', 'public' => true ) );
		$GLOBALS['cybermaps_mock_posts'] = array();
		$GLOBALS['cybermaps_mock_post_meta'] = array();
		$GLOBALS['cybermaps_mock_transients'] = array();
		$GLOBALS['cybermaps_mock_filter_callbacks'] = array();
		$GLOBALS['cybermaps_mock_get_posts_args'] = array();
		foreach ( range( 1, 3 ) as $id ) {
			$GLOBALS['cybermaps_mock_posts'][ $id ] = (object) array(
				'ID' => $id, 'post_type' => 'post', 'post_status' => 'publish', 'post_password' => '',
				'post_title' => 'Public resource ' . $id, 'post_content' => 'Public text.',
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s' ), 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s' ),
			);
		}
		$GLOBALS['cybermaps_mock_wp_query_callback'] = static fn(): array => array_values( $GLOBALS['cybermaps_mock_posts'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['cybermaps_mock_get_posts_callback'], $GLOBALS['cybermaps_mock_wp_query_callback'] );
		$GLOBALS['cybermaps_mock_filter_callbacks'] = array();
		\cybermaps_mock_reset_cache_runtime();
		parent::tearDown();
	}

	/** @dataProvider revoked_candidates */
	public function test_cached_ids_revalidate_current_policy_and_refill_the_limit( string $change ): void {
		CacheManager::put( 'cybermaps_ai_publication_inventory', array( 1, 2 ), 900, 'discovery' );
		if ( 'password' === $change ) {
			$GLOBALS['cybermaps_mock_posts'][1]->post_password = 'protected';
		} elseif ( 'private' === $change ) {
			$GLOBALS['cybermaps_mock_posts'][1]->post_status = 'private';
		} else {
			$GLOBALS['cybermaps_mock_post_meta'][1]['_cybermaps_exclude_ai'] = '1';
		}
		$selector = new AIContentSelector();
		$this->assertSame( array( 2, 3 ), array_column( $selector->get_posts(), 'ID' ) );
		$this->assertFalse( $selector->contains( 1 ) );
		$this->assertSame( array( 2, 3 ), CacheManager::get( 'cybermaps_ai_publication_inventory', 'discovery', $found ) );
		$this->assertTrue( $found );
		$this->assertCount( 2, $GLOBALS['cybermaps_mock_get_posts_args'] );
		$this->assertFalse( $GLOBALS['cybermaps_mock_get_posts_args'][0]['has_password'] );
		$this->assertLessThanOrEqual( 250, $GLOBALS['cybermaps_mock_get_posts_args'][0]['posts_per_page'] );
	}

	public static function revoked_candidates(): array {
		return array( array( 'password' ), array( 'private' ), array( 'ai-excluded' ) );
	}

	public function test_privacy_invalidation_during_hydration_discards_the_old_generation(): void {
		CacheManager::put( 'cybermaps_ai_publication_inventory', array( 1, 2 ), 900, 'discovery' );
		$changed = false;
		$GLOBALS['cybermaps_mock_get_posts_callback'] = static function( array $args ) use ( &$changed ): ?array {
			if ( ! $changed && isset( $args['post__in'] ) ) {
				$changed = true;
				$GLOBALS['cybermaps_mock_posts'][1]->post_password = 'protected';
				CacheManager::clear_family( 'discovery' );
			}
			return null;
		};
		$this->assertSame( array( 2, 3 ), array_column( ( new AIContentSelector() )->get_posts(), 'ID' ) );
		$this->assertTrue( $changed );
		$this->assertSame( array( 2, 3 ), CacheManager::get( 'cybermaps_ai_publication_inventory', 'discovery', $found ) );
		$this->assertTrue( $found );
		$this->assertLessThanOrEqual( 3, count( $GLOBALS['cybermaps_mock_get_posts_args'] ) );
	}

	public function test_repeated_changes_fail_closed_after_one_fresh_selection_retry(): void {
		$GLOBALS['cybermaps_mock_get_posts_callback'] = static function(): ?array {
			CacheManager::put( 'race-marker', true, 60, 'discovery' );
			CacheManager::clear_family( 'discovery' );
			return null;
		};
		try {
			( new AIContentSelector() )->get_posts();
			$this->fail( 'Changing selections must not publish a mixed generation.' );
		} catch ( BuildUnavailableException ) {
			$this->assertCount( 2, $GLOBALS['cybermaps_mock_get_posts_args'] );
			CacheManager::get( 'cybermaps_ai_publication_inventory', 'discovery', $found );
			$this->assertFalse( $found );
		}
	}

	public function test_request_local_selection_is_rebuilt_after_privacy_revocation(): void {
		$selector = new AIContentSelector();
		$this->assertSame( array( 1, 2 ), array_column( $selector->get_posts(), 'ID' ) );
		$GLOBALS['cybermaps_mock_posts'][1]->post_password = 'protected';
		CacheManager::clear_family( 'discovery' );
		$this->assertSame( array( 2, 3 ), array_column( $selector->get_posts(), 'ID' ) );
	}

	public function test_updates_build_privacy_change_never_returns_or_replays_old_entries(): void {
		$changed = false;
		$GLOBALS['cybermaps_mock_filter_callbacks']['cybermaps_publication_eligibility'][] = static function( $decision ) use ( &$changed ) {
			if ( ! $changed ) {
				$changed = true;
				$GLOBALS['cybermaps_mock_posts'][1]->post_status = 'private';
				CacheManager::clear_family( 'discovery' );
			}
			return $decision;
		};
		try {
			( new Updates() )->get_updates_data();
			$this->fail( 'Updates must not return the old eligible decision.' );
		} catch ( BuildUnavailableException ) {
			$this->assertTrue( $changed );
		}
		$GLOBALS['cybermaps_mock_filter_callbacks'] = array();
		CacheManager::get( 'cybermaps_adp_updates_v5', 'discovery', $found );
		$this->assertFalse( $found );
		$GLOBALS['cybermaps_mock_wp_query_callback'] = static fn(): array => array();
		$this->assertSame( array(), ( new Updates() )->get_updates_data()['updates'] );
	}

	public function test_updates_ignores_old_legacy_payload_and_reuses_only_scoped_cache(): void {
		set_transient( 'cybermaps_adp_updates_v4', array( 'updates' => array( array( 'url' => 'https://example.com/private' ) ) ), 900 );
		$first = ( new Updates() )->get_updates_data();
		$this->assertCount( 2, $first['updates'] );
		$this->assertNotContains( 'https://example.com/private', array_column( $first['updates'], 'url' ) );
		$GLOBALS['cybermaps_mock_wp_query_callback'] = static function(): never { throw new \RuntimeException( 'A current scoped cache should be reused.' ); };
		$this->assertSame( $first, ( new Updates() )->get_updates_data() );
		$this->assertFalse( get_transient( 'cybermaps_adp_updates_v5' ) );
	}

	public function test_updates_generation_change_during_cache_storage_fails_final_return(): void {
		$changed = false;
		$GLOBALS['cybermaps_mock_set_transient_observer'] = static function( string $key, mixed $value, int $ttl, string $phase ) use ( &$changed ): void {
			if ( ! $changed && 'before' === $phase && isset( $value['value']['updates'] ) ) {
				$changed = true;
				CacheManager::clear_family( 'discovery' );
			}
		};
		try {
			( new Updates() )->get_updates_data();
			$this->fail( 'An invalidation in backend storage must prevent the final return.' );
		} catch ( BuildUnavailableException ) {
			$this->assertTrue( $changed );
			CacheManager::get( 'cybermaps_adp_updates_v5', 'discovery', $found );
			$this->assertFalse( $found );
		}
	}

	public function test_updates_generation_change_during_cache_read_rejects_cached_body(): void {
		( new Updates() )->get_updates_data();
		$changed = false;
		$GLOBALS['cybermaps_mock_get_transient_observer'] = static function( string $key ) use ( &$changed ): void {
			if ( ! $changed && isset( $GLOBALS['cybermaps_mock_transients'][ $key ]['value']['updates'] ) ) {
				$changed = true;
				CacheManager::clear_family( 'discovery' );
			}
		};
		try {
			( new Updates() )->get_updates_data();
			$this->fail( 'An invalidation while reading must prevent cached-body return.' );
		} catch ( BuildUnavailableException ) {
			$this->assertTrue( $changed );
		}
	}
}
