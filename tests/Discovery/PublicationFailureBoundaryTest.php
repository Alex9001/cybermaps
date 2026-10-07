<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Core\AtomicOptionSequence;
use Cybermaps\Core\BuildUnavailableException;
use Cybermaps\Core\CacheManager;
use Cybermaps\Discovery\AIContentSelector;
use Cybermaps\Discovery\LLMS;
use Cybermaps\Discovery\PublicationInventory;

/** Ordinary query/cache interleaves exercise complete-publication boundaries. */
final class PublicationFailureBoundaryTest extends \WP_UnitTestCase {
	private mixed $previous_database;

	protected function setUp(): void {
		parent::setUp();
		$this->previous_database = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = (object) array( 'last_error' => '' );
		\cybermaps_mock_reset_cache_runtime();
		$GLOBALS['cybermaps_mock_options'] = array(
			'blog_public' => '1',
			'cybermaps_settings' => array( 'enable_discovery_hub' => '1', 'enable_llms_full' => '1', 'llms_included_types' => array( 'post' ), 'llms_link_limit' => 200, 'ai_sitemap_types' => array( 'post' ), 'ai_sitemap_limit' => 200 ),
		);
		$GLOBALS['cybermaps_mock_post_types'] = array( 'post' );
		$GLOBALS['cybermaps_mock_post_type_objects'] = array( 'post' => (object) array( 'name' => 'post', 'public' => true, 'labels' => (object) array( 'name' => 'Posts' ) ) );
		$GLOBALS['cybermaps_mock_posts'] = array();
		$GLOBALS['cybermaps_mock_post_meta'] = array();
		$GLOBALS['cybermaps_mock_transients'] = array();
		$GLOBALS['cybermaps_mock_get_posts_args'] = array();
		$GLOBALS['wp_hooks'] = array();
		foreach ( range( 1, 100 ) as $id ) {
			$GLOBALS['cybermaps_mock_posts'][ $id ] = (object) array( 'ID' => $id, 'post_type' => 'post', 'post_status' => 'publish', 'post_password' => '', 'post_title' => 'Public title ' . $id, 'post_content' => 1 === $id ? 'Revoked literal secret' : 'Ordinary body.', 'post_excerpt' => '', 'post_modified_gmt' => '2026-08-01 00:00:00', 'post_date_gmt' => '2026-08-01 00:00:00' );
		}
	}

	protected function tearDown(): void {
		unset( $GLOBALS['cybermaps_mock_get_posts_callback'] );
		unset( $GLOBALS['cybermaps_mock_wp_query_callback'] );
		\cybermaps_mock_reset_cache_runtime();
		$GLOBALS['wpdb'] = $this->previous_database;
		parent::tearDown();
	}

	/** @dataProvider publication_variants */
	public function test_known_revocation_before_return_rejects_complete_body( bool $full, bool $skip_cache, string $language ): void {
		$fired = false;
		$GLOBALS['cybermaps_mock_get_posts_callback'] = static function ( array $args ) use ( &$fired ): ?array {
			if ( 100 === ( $args['cybermaps_after_id'] ?? 0 ) ) {
				$fired = true;
				$GLOBALS['cybermaps_mock_posts'][1]->post_status = 'private';
				AtomicOptionSequence::increment( 'cybermaps_cache_generation_discovery' );
				return array();
			}
			return null;
		};
		try {
			( new LLMS() )->get_llms_content( $full, $skip_cache, $language );
			self::fail( 'A revoked body must never be returned.' );
		} catch ( BuildUnavailableException $error ) {
			self::assertTrue( $fired );
			self::assertStringContainsString( 'content changed', $error->getMessage() );
			CacheManager::get( LLMS::SUMMARY_CACHE_KEY . ( '' === $language ? '' : ':' . $language ), 'discovery', $found );
			self::assertFalse( $found );
		}
	}

	public static function publication_variants(): array {
		return array( array( true, false, '' ), array( false, false, '' ), array( true, true, 'en' ), array( false, true, 'en' ) );
	}

	public function test_cached_summary_revocation_during_read_is_not_returned(): void {
		CacheManager::put( LLMS::SUMMARY_CACHE_KEY, 'Old public title', 900, 'discovery' );
		$fired = false;
		$GLOBALS['cybermaps_mock_get_transient_observer'] = static function ( string $key ) use ( &$fired ): void {
			if ( ! $fired && str_starts_with( $key, 'v2:g' ) ) {
				$fired = true;
				AtomicOptionSequence::increment( 'cybermaps_cache_generation_discovery' );
			}
		};
		try {
			( new LLMS() )->get_llms_content();
			self::fail( 'A cached body must pass the final generation fence.' );
		} catch ( BuildUnavailableException ) {
			self::assertTrue( $fired );
			self::assertSame( array(), $GLOBALS['cybermaps_mock_get_posts_args'] );
		}
	}

	public function test_revocation_during_cache_storage_is_not_returned(): void {
		$fired = false;
		$GLOBALS['cybermaps_mock_set_transient_observer'] = static function ( string $key, mixed $value, int $expiration, string $phase ) use ( &$fired ): void {
			if ( ! $fired && 'after' === $phase && is_array( $value ) && is_string( $value['value'] ?? null ) && str_contains( $value['value'], 'Public title' ) ) {
				$fired = true;
				AtomicOptionSequence::increment( 'cybermaps_cache_generation_discovery' );
			}
		};
		try {
			( new LLMS() )->get_llms_content();
			self::fail( 'Storage completion cannot authorize an old body.' );
		} catch ( BuildUnavailableException ) {
			self::assertTrue( $fired );
			CacheManager::get( LLMS::SUMMARY_CACHE_KEY, 'discovery', $found );
			self::assertFalse( $found );
		}
	}

	/** @dataProvider inventory_failures */
	public function test_inventory_select_errors_are_distinct_from_successful_exhaustion( string $stage ): void {
		$GLOBALS['cybermaps_mock_get_posts_callback'] = static function ( array $args ) use ( $stage ): ?array {
			if ( ( 'snapshot' === $stage && 'ids' === ( $args['fields'] ?? '' ) ) || ( 'later' === $stage && 100 === ( $args['cybermaps_after_id'] ?? 0 ) ) || ( 'pinned' === $stage && isset( $args['post__in'] ) ) ) {
				$GLOBALS['wpdb']->last_error = 'Injected native empty-array SQL failure';
				return array();
			}
			return null;
		};
		try {
			if ( 'pinned' === $stage ) {
				iterator_to_array( ( new PublicationInventory() )->iterate_posts_by_ids( array( 1 ) ) );
			} else {
				( new LLMS() )->get_llms_content( true );
			}
			self::fail( 'A failed SELECT must not publish an empty or partial corpus.' );
		} catch ( BuildUnavailableException $error ) {
			self::assertStringContainsString( 'could not read', $error->getMessage() );
			foreach ( $GLOBALS['cybermaps_mock_get_posts_args'] as $args ) {
				self::assertFalse( $args['cache_results'] );
			}
		}
	}

	public static function inventory_failures(): array {
		return array( array( 'snapshot' ), array( 'later' ), array( 'pinned' ) );
	}

	/** @dataProvider selector_failures */
	public function test_selector_native_failures_never_become_complete_empty_selections( string $path ): void {
		if ( 'hydration' === $path ) {
			CacheManager::put( 'cybermaps_ai_publication_inventory', array( 1 ), 900, 'discovery' );
		}
		$GLOBALS['cybermaps_mock_get_posts_callback'] = static function (): array {
			$GLOBALS['wpdb']->last_error = 'Injected native SELECT failure';
			return array();
		};
		try {
			$selector = new AIContentSelector();
			'static' === $path ? $selector->get_id_batch() : $selector->get_posts();
			self::fail( 'Failed native selection must not be complete.' );
		} catch ( BuildUnavailableException ) {
			$cached = CacheManager::get( 'cybermaps_ai_publication_inventory', 'discovery', $found );
			self::assertSame( 'hydration' === $path, $found );
			if ( $found ) {
				self::assertSame( array( 1 ), $cached );
			}
			self::assertFalse( $GLOBALS['cybermaps_mock_get_posts_args'][0]['cache_results'] );
		}
	}

	public static function selector_failures(): array {
		return array( array( 'fresh' ), array( 'static' ), array( 'hydration' ) );
	}

	public function test_successful_empty_query_ignores_prior_unrelated_sql_error(): void {
		$GLOBALS['wpdb']->last_error = 'Unrelated preceding request error';
		$GLOBALS['cybermaps_mock_posts'] = array();
		self::assertStringContainsString( 'No eligible published content', ( new LLMS() )->get_llms_content( true ) );
		self::assertSame( array(), ( new AIContentSelector() )->get_posts() );
	}

	/** @dataProvider filtered_read_paths */
	public function test_filtered_query_error_is_rejected_before_later_filters_clear_it( bool $hydration ): void {
		if ( $hydration ) {
			CacheManager::put( 'cybermaps_ai_publication_inventory', array( 1 ), 900, 'discovery' );
		}
		$reached_later_filter = false;
		$GLOBALS['cybermaps_mock_get_posts_callback'] = static function ( array $args ) use ( &$reached_later_filter ): array {
			if ( 'ids' === ( $args['fields'] ?? '' ) ) {
				return array( 1 );
			}
			self::assertFalse( $args['suppress_filters'] );
			$query = new class( $args ) { public function __construct( private array $args ) {} public function get( string $key ): mixed { return $this->args[ $key ] ?? null; } };
			$GLOBALS['wpdb']->last_error = 'Native failed full-row query';
			foreach ( $GLOBALS['wp_hooks'] as $hook ) {
				if ( 'posts_results' === $hook['hook'] ) {
					self::assertSame( PHP_INT_MIN, $hook['priority'] );
					( $hook['callback'] )( array(), $query );
				}
			}
			$reached_later_filter = true;
			$GLOBALS['wpdb']->last_error = '';
			return array();
		};
		try {
			if ( $hydration ) {
				( new AIContentSelector() )->get_posts();
			} else {
				iterator_to_array( ( new PublicationInventory() )->iterate_posts() );
			}
			self::fail( 'The first result boundary must preserve the query failure.' );
		} catch ( BuildUnavailableException ) {
			self::assertFalse( $reached_later_filter );
			self::assertFalse( has_filter( 'posts_results' ) );
			self::assertFalse( has_filter( 'split_the_query' ) );
		}
	}

	public static function filtered_read_paths(): array {
		return array( array( false ), array( true ) );
	}

	/** @dataProvider search_entry_points */
	public function test_search_revocation_returns_503_without_hits_to_rest_and_ability( bool $ability ): void {
		$process = proc_open( array( PHP_BINARY, __DIR__ . '/fixtures/search-return-race.php', $ability ? 'ability' : 'rest' ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		self::assertIsResource( $process );
		$output = stream_get_contents( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		self::assertSame( 0, proc_close( $process ), $error );
		$case = json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
		self::assertTrue( $case['fired'] );
		self::assertSame( 'publication_unavailable', $case['code'] );
		self::assertSame( array( 'status' => 503 ), $case['data'] );
		self::assertStringNotContainsString( 'Revoked literal secret', $case['message'] );
	}

	public function test_updates_sql_failure_is_not_cached_and_recovery_runs_a_new_query(): void {
		$calls = 0;
		$GLOBALS['cybermaps_mock_wp_query_callback'] = static function ( array $args ) use ( &$calls ): array {
			++$calls;
			self::assertFalse( $args['cache_results'] );
			$GLOBALS['wpdb']->last_error = 'Injected posts SELECT failure';
			return array();
		};
		try {
			( new \Cybermaps\Discovery\Updates() )->get_updates_data();
			self::fail( 'Failed updates selection must be unavailable.' );
		} catch ( BuildUnavailableException ) {
			CacheManager::get( 'cybermaps_adp_updates_v5', 'discovery', $found );
			self::assertFalse( $found );
		}
		$post = $GLOBALS['cybermaps_mock_posts'][1];
		$post->post_modified_gmt = gmdate( 'Y-m-d H:i:s' );
		$post->post_date_gmt = $post->post_modified_gmt;
		$GLOBALS['cybermaps_mock_wp_query_callback'] = static function () use ( &$calls, $post ): array { ++$calls; return array( $post ); };
		$updates = ( new \Cybermaps\Discovery\Updates() )->get_updates_data();
		self::assertCount( 1, $updates['updates'] );
		self::assertSame( 2, $calls );
	}

	public function test_optional_404_sql_failure_does_not_poison_recovered_suggestions(): void {
		$GLOBALS['cybermaps_mock_wp_query_callback'] = static function ( array $args ): array {
			self::assertFalse( $args['cache_results'] );
			$GLOBALS['wpdb']->last_error = 'Injected optional SELECT failure';
			return array();
		};
		$finder = new \ReflectionMethod( \Cybermaps\Discovery\NotFoundSuggestions::class, 'find_alternatives' );
		self::assertSame( array(), $finder->invoke( new \Cybermaps\Discovery\NotFoundSuggestions(), 'ordinary' ) );
		$GLOBALS['cybermaps_mock_wp_query_callback'] = static fn(): array => array( $GLOBALS['cybermaps_mock_posts'][1] );
		self::assertCount( 1, $finder->invoke( new \Cybermaps\Discovery\NotFoundSuggestions(), 'ordinary' ) );
	}

	/** @dataProvider search_entry_points */
	public function test_search_sql_failure_returns_unavailable_to_rest_and_ability( bool $ability ): void {
		$process = proc_open( array( PHP_BINARY, __DIR__ . '/fixtures/search-return-race.php', $ability ? 'ability' : 'rest', 'sql' ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		self::assertIsResource( $process );
		$output = stream_get_contents( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		self::assertSame( 0, proc_close( $process ), $error );
		$case = json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
		self::assertTrue( $case['fired'] );
		self::assertSame( 'publication_unavailable', $case['code'] );
		self::assertSame( array( 'status' => 503 ), $case['data'] );
	}

	public function test_rag_authorization_to_chunk_revocation_rejects_private_text(): void {
		$case = $this->run_fixture( 'rag-return-race.php', 'rag' );
		self::assertTrue( $case['blocked'] );
		self::assertSame( 'private', $case['status'] );
		self::assertFalse( $case['returned_private_text'] );
	}

	/** @dataProvider fresh_policy_publications */
	public function test_production_memo_primed_before_new_generation_cannot_bypass_current_policy( string $publication ): void {
		$case = $this->run_fixture( 'publication-policy-memo.php', $publication );
		self::assertSame( 1, $case['generation'] );
		self::assertFalse( $case['returned_excluded_title'] );
		self::assertSame( '1', $case['current_exclusion'] );
	}

	public static function fresh_policy_publications(): array {
		return array( array( 'llms' ), array( 'selector' ), array( 'search' ), array( 'updates' ) );
	}

	public function test_full_llms_http_activation_uses_current_policy_after_a_primed_old_memo(): void {
		$case = $this->run_fixture( 'publication-policy-memo.php', 'llms-disabled-route' );
		self::assertSame( 1, $case['generation'] );
		self::assertSame( array(), $case['status'] );
		self::assertSame( 'fell_through', $case['body'] );
	}

	private function run_fixture( string $fixture, string $case ): array {
		$process = proc_open( array( PHP_BINARY, __DIR__ . '/fixtures/' . $fixture, $case ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		self::assertIsResource( $process );
		$output = stream_get_contents( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		self::assertSame( 0, proc_close( $process ), $error );
		return json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
	}

	public static function search_entry_points(): array {
		return array( array( false ), array( true ) );
	}
}
