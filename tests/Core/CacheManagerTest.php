<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Core;

use Cybermaps\Core\CacheManager;

final class CacheManagerTest extends \WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();
		\cybermaps_mock_reset_cache_runtime();
		$GLOBALS['cybermaps_mock_options']    = array();
		$GLOBALS['cybermaps_mock_transients'] = array();
	}

	public function test_clear_all_deletes_only_exact_core_owned_keys(): void {
		CacheManager::set( 'cybermaps_v7_index', '<xml/>', HOUR_IN_SECONDS, 'sitemap' );
		set_transient( 'cybermaps_pro_cache', 'extension-owned', HOUR_IN_SECONDS );

		CacheManager::clear_all();

		$this->assertFalse( get_transient( 'cybermaps_v7_index' ) );
		$this->assertSame( 'extension-owned', get_transient( 'cybermaps_pro_cache' ) );
		$this->assertFalse( get_option( 'cybermaps_core_transient_inventory', false ) );
	}

	public function test_integrity_fallback_is_owned_by_core_cleanup(): void {
		set_transient( 'cybermaps_last_modified_fallback', 123, HOUR_IN_SECONDS );

		CacheManager::clear_all();

		$this->assertFalse( get_transient( 'cybermaps_last_modified_fallback' ) );
	}

	public function test_fixed_keys_are_not_duplicated_into_dynamic_inventory(): void {
		CacheManager::set( 'cybermaps_tldr_cache', 'briefing', HOUR_IN_SECONDS, 'discovery' );

		$this->assertSame( 'briefing', get_transient( 'cybermaps_tldr_cache' ) );
		$this->assertFalse( get_option( 'cybermaps_core_transient_inventory', false ) );

		CacheManager::clear_family( 'discovery' );
		$this->assertFalse( get_transient( 'cybermaps_tldr_cache' ) );
	}

	public function test_clear_family_preserves_other_core_and_extension_families(): void {
		CacheManager::set( 'cybermaps_v7_index', '<xml/>', HOUR_IN_SECONDS, 'sitemap' );
		CacheManager::set( 'cybermaps_chunks_1_hash', array( 'chunk' ), HOUR_IN_SECONDS, 'chunks' );
		set_transient( 'cybermaps_pro_sitemap_cache', 'extension-owned', HOUR_IN_SECONDS );

		CacheManager::clear_family( 'sitemap' );

		$this->assertFalse( get_transient( 'cybermaps_v7_index' ) );
		$this->assertSame( array( 'chunk' ), get_transient( 'cybermaps_chunks_1_hash' ) );
		$this->assertSame( 'extension-owned', get_transient( 'cybermaps_pro_sitemap_cache' ) );
	}

	public function test_inventory_cap_deletes_an_evicted_live_transient(): void {
		CacheManager::set( 'cybermaps_v7_short_lived_sitemap', '<xml/>', MINUTE_IN_SECONDS, 'sitemap' );
		set_transient( 'cybermaps_pro_cache', 'extension-owned', DAY_IN_SECONDS );

		for ( $index = 1; $index <= 2000; ++$index ) {
			CacheManager::set(
				'cybermaps_chunks_inventory_' . $index,
				array( 'chunk' => $index ),
				DAY_IN_SECONDS,
				'chunks'
			);
		}

		$inventory = get_option( 'cybermaps_core_transient_inventory', array() );
		$this->assertCount( 2000, $inventory );
		$this->assertArrayNotHasKey( 'cybermaps_v7_short_lived_sitemap', $inventory );
		$this->assertFalse( get_transient( 'cybermaps_v7_short_lived_sitemap' ) );
		$this->assertSame( 'extension-owned', get_transient( 'cybermaps_pro_cache' ) );
	}

	public function test_wrapped_values_distinguish_false_and_null_from_misses(): void {
		$this->assertTrue( CacheManager::put( 'false-value', false, HOUR_IN_SECONDS, 'discovery' ) );
		$this->assertFalse( CacheManager::get( 'false-value', 'discovery', $false_found ) );
		$this->assertTrue( $false_found );

		$this->assertTrue( CacheManager::put( 'null-value', null, HOUR_IN_SECONDS, 'discovery' ) );
		$this->assertNull( CacheManager::get( 'null-value', 'discovery', $null_found ) );
		$this->assertTrue( $null_found );

		$this->assertFalse( CacheManager::get( 'missing-value', 'discovery', $missing_found ) );
		$this->assertFalse( $missing_found );
	}

	public function test_mock_request_reset_clears_cache_backend_and_multisite_state(): void {
		$GLOBALS['cybermaps_mock_current_blog_id']        = 2;
		$GLOBALS['cybermaps_mock_options_by_blog']        = array( 2 => array( 'stale' => true ) );
		$GLOBALS['cybermaps_mock_using_ext_object_cache'] = true;
		$GLOBALS['cybermaps_mock_apcu_enabled']           = true;
		$GLOBALS['cybermaps_mock_object_cache']['stale']  = true;
		$GLOBALS['cybermaps_mock_apcu']['stale']          = true;
		$GLOBALS['cybermaps_mock_get_transient_observer'] = static function (): void {};

		\cybermaps_mock_reset_cache_runtime();

		$this->assertSame( 1, $GLOBALS['cybermaps_mock_current_blog_id'] );
		$this->assertArrayNotHasKey( 'cybermaps_mock_options_by_blog', $GLOBALS );
		$this->assertFalse( $GLOBALS['cybermaps_mock_using_ext_object_cache'] );
		$this->assertFalse( $GLOBALS['cybermaps_mock_apcu_enabled'] );
		$this->assertSame( array(), $GLOBALS['cybermaps_mock_object_cache'] );
		$this->assertSame( array(), $GLOBALS['cybermaps_mock_apcu'] );
		$this->assertArrayNotHasKey( 'cybermaps_mock_get_transient_observer', $GLOBALS );
	}

	public function test_family_generation_makes_prior_values_unreachable(): void {
		CacheManager::put( 'publication', 'old', HOUR_IN_SECONDS, 'discovery' );
		$this->assertSame( 'old', CacheManager::get( 'publication', 'discovery', $found ) );
		$this->assertTrue( $found );

		CacheManager::clear_family( 'discovery' );

		$this->assertFalse( CacheManager::get( 'publication', 'discovery', $found ) );
		$this->assertFalse( $found );
	}

	public function test_invalidation_during_build_prevents_stale_publication(): void {
		$value = CacheManager::remember(
			'publication',
			HOUR_IN_SECONDS,
			'discovery',
			static function (): string {
				CacheManager::clear_family( 'discovery' );
				return 'stale-build';
			}
		);

		$this->assertSame( 'stale-build', $value );
		$this->assertFalse( CacheManager::get( 'publication', 'discovery', $found ) );
		$this->assertFalse( $found );
	}

	public function test_external_cache_does_not_write_database_inventory(): void {
		$GLOBALS['cybermaps_mock_using_ext_object_cache'] = true;

		CacheManager::set( 'cybermaps_dynamic_external', 'value', HOUR_IN_SECONDS, 'discovery' );

		$this->assertFalse( get_option( 'cybermaps_core_transient_inventory', false ) );
		$this->assertSame( 'value', CacheManager::get( 'cybermaps_dynamic_external', 'discovery', $found ) );
		$this->assertTrue( $found );
	}

	public function test_large_external_value_is_segmented_and_partial_eviction_is_a_miss(): void {
		$GLOBALS['cybermaps_mock_using_ext_object_cache'] = true;
		$GLOBALS['cybermaps_mock_object_cache_max_bytes'] = 300000;
		$value = random_bytes( 800000 );

		$this->assertTrue( CacheManager::put( 'large-value', $value, HOUR_IN_SECONDS, 'discovery' ) );
		$this->assertSame( $value, CacheManager::get( 'large-value', 'discovery', $found ) );
		$this->assertTrue( $found );

		$part_keys = array_values(
			array_filter(
				array_keys( $GLOBALS['cybermaps_mock_object_cache'] ),
				static fn( string $key ): bool => str_contains( $key, ':p:' )
			)
		);
		$this->assertNotEmpty( $part_keys );
		unset( $GLOBALS['cybermaps_mock_object_cache'][ $part_keys[0] ] );

		$this->assertFalse( CacheManager::get( 'large-value', 'discovery', $found ) );
		$this->assertFalse( $found );
	}

	public function test_cache_groups_and_runtime_generations_are_site_local(): void {
		$GLOBALS['cybermaps_mock_using_ext_object_cache'] = true;
		$GLOBALS['cybermaps_mock_options_by_blog']        = array(
			1 => array(),
			2 => array(),
		);
		CacheManager::put( 'shared-name', 'site-one', HOUR_IN_SECONDS, 'discovery' );

		$GLOBALS['cybermaps_mock_current_blog_id'] = 2;
		$this->assertFalse( CacheManager::get( 'shared-name', 'discovery', $found ) );
		$this->assertFalse( $found );
		CacheManager::put( 'shared-name', 'site-two', HOUR_IN_SECONDS, 'discovery' );

		$GLOBALS['cybermaps_mock_current_blog_id'] = 1;
		$this->assertSame( 'site-one', CacheManager::get( 'shared-name', 'discovery', $found ) );
		$this->assertTrue( $found );
	}

	public function test_cache_keys_separate_installations_with_the_same_blog_id(): void {
		$GLOBALS['cybermaps_mock_using_ext_object_cache'] = true;
		$GLOBALS['cybermaps_mock_options']['siteurl']     = 'https://www.bayareatechpros.com';
		CacheManager::put( 'shared-name', 'tech-pros', HOUR_IN_SECONDS, 'discovery' );

		$GLOBALS['cybermaps_mock_options']['siteurl'] = 'https://www.bayareaplastering.com';
		CacheManager::reset_runtime();
		$this->assertFalse( CacheManager::get( 'shared-name', 'discovery', $found ) );
		$this->assertFalse( $found );
		CacheManager::put( 'shared-name', 'plastering', HOUR_IN_SECONDS, 'discovery' );

		$GLOBALS['cybermaps_mock_options']['siteurl'] = 'https://www.bayareatechpros.com';
		CacheManager::reset_runtime();
		$this->assertSame( 'tech-pros', CacheManager::get( 'shared-name', 'discovery', $found ) );
		$this->assertTrue( $found );
	}

	public function test_capability_profile_uses_wordpress_feature_checks(): void {
		$GLOBALS['cybermaps_mock_using_ext_object_cache'] = true;
		$GLOBALS['cybermaps_mock_cache_capabilities']     = array( 'flush_group', 'get_multiple' );

		$profile = CacheManager::capability_profile();

		$this->assertTrue( $profile['external'] );
		$this->assertTrue( $profile['flush_group'] );
		$this->assertTrue( $profile['get_multiple'] );
		$this->assertFalse( $profile['set_multiple'] );
		$this->assertTrue( $profile['site_local_groups'] );
	}

	public function test_fill_lease_and_visible_value_prevent_repeat_generation(): void {
		$GLOBALS['cybermaps_mock_using_ext_object_cache'] = true;
		$builds   = 0;
		$producer = static function () use ( &$builds ): string {
			++$builds;
			return 'built';
		};

		$this->assertSame( 'built', CacheManager::remember( 'leased', HOUR_IN_SECONDS, 'discovery', $producer ) );
		$this->assertSame( 'built', CacheManager::remember( 'leased', HOUR_IN_SECONDS, 'discovery', $producer ) );
		$this->assertSame( 1, $builds );
		$this->assertEmpty(
			array_filter(
				array_keys( $GLOBALS['cybermaps_mock_object_cache'] ),
				static fn( string $key ): bool => str_starts_with( $key, 'cybermaps_discovery_locks:' )
			)
		);
	}

	public function test_competing_fill_never_runs_without_ownership_even_after_invalidation_and_cache_eviction(): void {
		foreach ( array( false, true ) as $external ) {
			$GLOBALS['cybermaps_mock_using_ext_object_cache'] = $external;
			$key = 'competing-' . (int) $external;
			$builds = 0;
			$owner = new \Fiber( static function () use ( $key, &$builds ): string {
				return CacheManager::remember( $key, 60, 'discovery', static function () use ( &$builds ): string {
					++$builds;
					\Fiber::suspend();
					return 'complete';
				} );
			} );
			$owner->start();
			CacheManager::clear_family( 'discovery' );
			$GLOBALS['cybermaps_mock_object_cache'] = array();
			try {
				CacheManager::remember( $key, 60, 'discovery', static function () use ( &$builds ): string { ++$builds; return 'duplicate'; } );
				self::fail( 'A competing producer must defer.' );
			} catch ( \Cybermaps\Core\BuildUnavailableException $error ) {
				self::assertSame( 1, $builds );
			}
			$owner->resume();
			self::assertSame( 'complete', $owner->getReturn() );
			CacheManager::get( $key, 'discovery', $found );
			self::assertFalse( $found, 'An invalidated build must not populate the new generation.' );
			self::assertSame( 'next', CacheManager::remember( $key, 60, 'discovery', static fn(): string => 'next' ) );
		}
	}

	public function test_failed_and_uncacheable_fills_release_their_owner_immediately(): void {
		foreach ( array( false, true ) as $external ) {
			$GLOBALS['cybermaps_mock_using_ext_object_cache'] = $external;
			$key = 'failure-' . (int) $external;
			try {
				CacheManager::remember( $key, 60, 'discovery', static function (): never { throw new \RuntimeException( 'producer failed' ); } );
				self::fail( 'Expected producer failure.' );
			} catch ( \RuntimeException $error ) {
				self::assertSame( 'producer failed', $error->getMessage() );
			}
			self::assertSame( 'uncached', CacheManager::remember( $key, 60, 'discovery', static fn(): string => 'uncached', static fn(): bool => false ) );
			self::assertSame( 'retry', CacheManager::remember( $key, 60, 'discovery', static fn(): string => 'retry' ) );
		}
	}

	public function test_lost_fill_owner_cannot_cache_or_release_a_successors_lease(): void {
		$successor = array( 'token' => 'successor-token', 'time' => time() );
		$option = '';
		try {
			CacheManager::remember( 'lost-owner', 60, 'discovery', static function () use ( &$option, $successor ): string {
				foreach ( array_keys( $GLOBALS['cybermaps_mock_options'] ) as $key ) {
					if ( str_starts_with( $key, 'cybermaps_cache_fill_' ) ) { $option = $key; }
				}
				update_option( $option, $successor );
				return 'unsafe';
			} );
			self::fail( 'Lost ownership must fail.' );
		} catch ( \Cybermaps\Core\BuildUnavailableException $error ) {
			self::assertStringContainsString( 'lock was lost', $error->getMessage() );
		}
		CacheManager::get( 'lost-owner', 'discovery', $found );
		self::assertFalse( $found );
		self::assertSame( $successor, get_option( $option ) );
		delete_option( $option );
	}

	public function test_apcu_l1_is_generation_scoped_and_never_authoritative(): void {
		$GLOBALS['cybermaps_mock_apcu_enabled'] = true;
		if ( ! apcu_enabled() ) {
			$this->markTestSkipped( 'The host APCu extension cannot be enabled by the test harness.' );
		}

		CacheManager::put( 'apcu-derived', 'value', HOUR_IN_SECONDS, 'discovery' );
		CacheManager::reset_runtime();
		$GLOBALS['cybermaps_mock_transients'] = array();
		$this->assertFalse( CacheManager::get( 'apcu-derived', 'discovery', $found ) );
		$this->assertFalse( $found );

		CacheManager::clear_family( 'discovery' );
		$this->assertFalse( CacheManager::get( 'apcu-derived', 'discovery', $found ) );
		$this->assertFalse( $found );
	}

	public function test_apcu_accelerates_only_after_backend_coherence_in_current_request(): void {
		$GLOBALS['cybermaps_mock_apcu_enabled'] = true;
		if ( ! apcu_enabled() ) {
			$this->markTestSkipped( 'The host APCu extension cannot be enabled by the test harness.' );
		}
		CacheManager::put( 'request-derived', 'value', HOUR_IN_SECONDS, 'discovery' );
		CacheManager::reset_runtime();
		$backend_reads                                    = 0;
		$GLOBALS['cybermaps_mock_get_transient_observer'] = static function () use ( &$backend_reads ): void {
			++$backend_reads;
		};

		$this->assertSame( 'value', CacheManager::get( 'request-derived', 'discovery', $found ) );
		$this->assertTrue( $found );
		$reads_after_coherence = $backend_reads;
		$this->assertGreaterThan( 0, $reads_after_coherence );
		$this->assertSame( 'value', CacheManager::get( 'request-derived', 'discovery', $found ) );
		$this->assertSame( $reads_after_coherence, $backend_reads );
	}

	public function test_apcu_does_not_mask_backend_overwrite_in_a_new_request(): void {
		$GLOBALS['cybermaps_mock_apcu_enabled']           = true;
		$GLOBALS['cybermaps_mock_using_ext_object_cache'] = true;
		if ( ! apcu_enabled() ) {
			$this->markTestSkipped( 'The host APCu extension cannot be enabled by the test harness.' );
		}
		CacheManager::put( 'overwritten', 'old', HOUR_IN_SECONDS, 'discovery' );
		$backend_keys = array_values(
			array_filter(
				array_keys( $GLOBALS['cybermaps_mock_object_cache'] ),
				static fn( string $key ): bool => str_starts_with( $key, 'cybermaps_discovery:v' )
			)
		);
		$this->assertCount( 1, $backend_keys );
		$GLOBALS['cybermaps_mock_object_cache'][ $backend_keys[0] ] = array(
			'cybermaps_cache' => 2,
			'value'           => 'new',
		);
		CacheManager::reset_runtime();

		$this->assertSame( 'new', CacheManager::get( 'overwritten', 'discovery', $found ) );
		$this->assertTrue( $found );
	}

	public function test_apcu_does_not_mask_backend_delete_in_a_new_request(): void {
		$GLOBALS['cybermaps_mock_apcu_enabled']           = true;
		$GLOBALS['cybermaps_mock_using_ext_object_cache'] = true;
		if ( ! apcu_enabled() ) {
			$this->markTestSkipped( 'The host APCu extension cannot be enabled by the test harness.' );
		}
		CacheManager::put( 'deleted', 'old', HOUR_IN_SECONDS, 'discovery' );
		$backend_keys = array_values(
			array_filter(
				array_keys( $GLOBALS['cybermaps_mock_object_cache'] ),
				static fn( string $key ): bool => str_starts_with( $key, 'cybermaps_discovery:v' )
			)
		);
		$this->assertCount( 1, $backend_keys );
		unset( $GLOBALS['cybermaps_mock_object_cache'][ $backend_keys[0] ] );
		CacheManager::reset_runtime();

		$this->assertFalse( CacheManager::get( 'deleted', 'discovery', $found ) );
		$this->assertFalse( $found );
	}

	public function test_set_removes_legacy_value_when_invalidation_interleaves_with_write(): void {
		$key = 'cybermaps_dynamic_interleaved';
		$GLOBALS['cybermaps_mock_set_transient_observer'] = static function ( string $transient, $value, int $ttl, string $stage ) use ( $key ): void {
			unset( $value, $ttl );
			if ( $key === $transient && 'before' === $stage ) {
				CacheManager::clear_family( 'discovery' );
			}
		};

		CacheManager::set( $key, 'stale', HOUR_IN_SECONDS, 'discovery' );

		$this->assertFalse( get_transient( $key ) );
		$this->assertFalse( CacheManager::get( $key, 'discovery', $found ) );
		$this->assertFalse( $found );
	}

	public function test_compatible_set_removes_legacy_value_when_invalidation_interleaves(): void {
		$key        = 'cybermaps_tldr_cache';
		$generation = CacheManager::get_generation( 'discovery' );
		$GLOBALS['cybermaps_mock_set_transient_observer'] = static function ( string $transient, $value, int $ttl, string $stage ) use ( $key ): void {
			unset( $value, $ttl );
			if ( $key === $transient && 'before' === $stage ) {
				CacheManager::clear_family( 'discovery' );
			}
		};

		$this->assertFalse(
			CacheManager::set_compatible_if_current(
				$key,
				'stale',
				HOUR_IN_SECONDS,
				'discovery',
				$generation
			)
		);
		$this->assertFalse( get_transient( $key ) );
	}
}
