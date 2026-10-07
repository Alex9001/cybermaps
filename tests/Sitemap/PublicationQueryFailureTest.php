<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Sitemap;

use Cybermaps\Core\BuildUnavailableException;
use Cybermaps\Core\CacheManager;
use Cybermaps\Core\ConfigurationStore;
use Cybermaps\Sitemap\EligibleContentRepository;
use Cybermaps\Sitemap\PublicationQuery;
use Cybermaps\Sitemap\RSSProvider;
use Cybermaps\Sitemap\ShortcodeHandler;

final class PublicationQueryFailureTest extends \WP_UnitTestCase {
	private mixed $previous_wpdb;

	protected function setUp(): void {
		parent::setUp();
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new PublicationFailureWpdb();
		$GLOBALS['wp_hooks'] = array();
		$GLOBALS['cybermaps_mock_filter_callbacks'] = array();
		$GLOBALS['cybermaps_mock_transients'] = array();
		$GLOBALS['cybermaps_mock_options'] = array(
			'blog_public' => '1',
			'cybermaps_settings' => array( 'enable_shortcode' => '1', 'enable_caching' => '1', 'include_authors' => '1', 'include_archives' => '1', 'rss_sitemap_types' => array( 'post' ) ),
		);
		$GLOBALS['cybermaps_mock_post_types'] = array( 'post' );
		$GLOBALS['cybermaps_mock_post_type_objects'] = array( 'post' => (object) array( 'name' => 'post', 'public' => true ) );
		$GLOBALS['cybermaps_mock_taxonomies'] = array();
		ConfigurationStore::reset_memo();
		\cybermaps_mock_reset_cache_runtime();
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->previous_wpdb;
		unset( $GLOBALS['cybermaps_mock_wp_query_callback'] );
		$GLOBALS['wp_hooks'] = array();
		$GLOBALS['cybermaps_mock_filter_callbacks'] = array();
		ConfigurationStore::reset_memo();
		parent::tearDown();
	}

	public function test_failed_empty_rss_is_not_cached_and_identical_healthy_retry_is_cached(): void {
		$this->failed_posts();
		$this->assertUnavailable( static fn() => ( new RSSProvider() )->generate() );
		$this->assertFalse( CacheManager::get( 'cybermaps_rss_sitemap_v2', 'sitemap' ) );
		$GLOBALS['cybermaps_mock_wp_query_callback'] = static function ( array $args ): array {
			self::assertFalse( $args['cache_results'] );
			self::assertTrue( $args['no_found_rows'] );
			return array();
		};
		$GLOBALS['wpdb']->last_error = 'Stale unrelated failure';
		$xml = ( new RSSProvider() )->generate();
		$this->assertStringContainsString( '</rss>', $xml );
		$this->assertSame( $xml, CacheManager::get( 'cybermaps_rss_sitemap_v2', 'sitemap' ) );
	}

	public function test_later_failed_batch_discards_rss_and_shortcode_prefixes(): void {
		$GLOBALS['cybermaps_mock_filter_callbacks']['cybermaps_publication_eligibility'] = array(
			static fn( $decision ) => $decision->with_reasons( array( 'excluded' ) ),
		);
		$GLOBALS['cybermaps_mock_wp_query_callback'] = static function ( array $args ): array {
			if ( 1 === $args['paged'] ) {
				return array_fill( 0, $args['posts_per_page'], (object) array( 'ID' => 1, 'post_type' => 'post', 'post_status' => 'publish', 'post_password' => '', 'post_name' => 'candidate' ) );
			}
			$GLOBALS['wpdb']->last_error = 'Candidate SELECT failed';
			return array();
		};
		$this->assertUnavailable( static fn() => ( new RSSProvider() )->generate() );
		$this->assertFalse( CacheManager::get( 'cybermaps_rss_sitemap_v2', 'sitemap' ) );
		$html = ( new ShortcodeHandler() )->render_shortcode( array( 'only' => 'post' ) );
		$this->assertStringContainsString( 'temporarily unavailable', $html );
		$this->assertStringNotContainsString( 'No pages found', $html );
		$this->assertFalse( has_filter( 'posts_where' ) );
		$this->assertFalse( has_filter( 'posts_results' ) );
	}

	public function test_result_error_is_captured_before_later_hook_can_clear_it(): void {
		$GLOBALS['cybermaps_mock_wp_query_callback'] = static function ( array $args ): array {
			$query = new PublicationQueryView( $args );
			$hooks = $GLOBALS['wp_hooks'];
			usort( $hooks, static fn( array $a, array $b ): int => $a['priority'] <=> $b['priority'] );
			foreach ( $hooks as $hook ) {
				if ( 'split_the_query' === $hook['hook'] ) {
					self::assertFalse( ( $hook['callback'] )( true, $query ) );
				}
			}
			$unrelated = new PublicationQueryView( array() );
			$GLOBALS['wpdb']->last_error = 'Unrelated nested query failure';
			foreach ( $hooks as $hook ) {
				if ( 'posts_request' === $hook['hook'] ) {
					( $hook['callback'] )( 'SELECT nested', $unrelated );
					self::assertSame( 'Unrelated nested query failure', $GLOBALS['wpdb']->last_error );
				}
				if ( 'posts_results' === $hook['hook'] ) {
					self::assertSame( array(), ( $hook['callback'] )( array(), $unrelated ) );
				}
			}
			$GLOBALS['wpdb']->last_error = 'Native SELECT failed';
			foreach ( $hooks as $hook ) {
				if ( 'posts_results' === $hook['hook'] ) {
					( $hook['callback'] )( array(), $query );
				}
			}
			$GLOBALS['wpdb']->last_error = '';
			return array();
		};
		$this->assertUnavailable( static fn() => PublicationQuery::posts( array( 'posts_per_page' => 1 ) ) );
		$this->assertSame( 'Native SELECT failed', $GLOBALS['wpdb']->last_error );
		$this->assertFalse( has_filter( 'split_the_query' ) );
		$this->assertFalse( has_filter( 'posts_request' ) );
	}

	public function test_direct_count_author_and_archive_failures_are_not_memoized_as_empty(): void {
		$repository = new EligibleContentRepository();
		foreach ( array(
			static fn() => $repository->get_post_count( 'post' ),
			static fn() => $repository->get_author_count(),
			static fn() => $repository->get_author_page( 1, 20 ),
			static fn() => $repository->get_archive_page( 1, 20 ),
			static fn() => $repository->get_term_lastmods( array( 1 ), 'category' ),
			static fn() => $repository->get_taxonomy_lastmod( 'category' ),
		) as $index => $read ) {
			$GLOBALS['wpdb']->fail = true;
			$this->assertUnavailable( $read );
			if ( 3 === $index ) {
				$this->assertFalse( CacheManager::get( 'cybermaps_archive_month_inventory_v2', 'sitemap' ) );
			}
			$GLOBALS['wpdb']->fail = false;
			$this->assertTrue( in_array( $read(), array( 0, array(), array( 1 => '' ), '' ), true ) );
		}
	}

	public function test_failed_candidate_page_can_be_retried_on_same_repository(): void {
		$repository = new EligibleContentRepository();
		$this->failed_posts();
		$this->assertUnavailable( static fn() => $repository->get_post_page( 'post', 1, 20 ) );
		$GLOBALS['cybermaps_mock_wp_query_callback'] = static fn( array $args ): array => array();
		$this->assertSame( array(), $repository->get_post_page( 'post', 1, 20 ) );
	}

	private function failed_posts(): void {
		$GLOBALS['cybermaps_mock_wp_query_callback'] = static function ( array $args ): array {
			$GLOBALS['wpdb']->last_error = 'Injected SELECT failure';
			return array();
		};
	}

	private function assertUnavailable( callable $read ): void {
		try {
			$read();
			$this->fail( 'Failed SELECT must not establish an empty publication.' );
		} catch ( BuildUnavailableException ) {
			$this->addToAssertionCount( 1 );
		}
	}
}

final class PublicationQueryView extends \WP_Query {
	private array $args;
	public function __construct( array $args ) { $this->args = $args; }
	public function get( string $name ): mixed { return $this->args[ $name ] ?? null; }
	public function set( string $name, mixed $value ): void { $this->args[ $name ] = $value; }
}

final class PublicationFailureWpdb {
	public string $posts = 'wp_posts';
	public string $term_taxonomy = 'wp_term_taxonomy';
	public string $term_relationships = 'wp_term_relationships';
	public string $last_error = '';
	public bool $fail = true;
	public function prepare( string $query, mixed ...$args ): string { return $query; }
	public function get_var( string $query ): mixed {
		$this->last_error = $this->fail ? 'SELECT failed' : '';
		return $this->fail ? null : ( str_contains( $query, 'COUNT(' ) ? '0' : null );
	}
	public function get_results( string $query ): array {
		$this->last_error = $this->fail ? 'SELECT failed' : '';
		return array();
	}
}
