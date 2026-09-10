<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Sitemap;

use Cybermaps\Sitemap\EligibleContentRepository;
use Cybermaps\Sitemap\MediaScanner;
use Cybermaps\Sitemap\PostTypeProvider;
use Cybermaps\Sitemap\TaxonomyProvider;

final class EligibleContentRepositoryScalingTest extends \WP_UnitTestCase {
	private mixed $previous_wpdb;

	protected function setUp(): void {
		parent::setUp();

		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new SitemapScalingWpdbStub();
		$GLOBALS['cybermaps_mock_options'] = array(
			'blog_public'        => '1',
			'cybermaps_settings' => array(
				'include_authors'     => '1',
				'include_archives'    => '1',
				'include_empty_terms' => '1',
			),
		);
		$GLOBALS['cybermaps_mock_post_types'] = array( 'post' );
		$GLOBALS['cybermaps_mock_post_type_objects'] = array(
			'post' => (object) array(
				'name'   => 'post',
				'public' => true,
			),
		);
		$GLOBALS['cybermaps_mock_taxonomies'] = array( 'category' );
		$GLOBALS['cybermaps_mock_taxonomy_objects'] = array(
			'category' => (object) array(
				'name'   => 'category',
				'public' => true,
			),
		);
		$GLOBALS['cybermaps_mock_filter_callbacks'] = array();
		$GLOBALS['cybermaps_mock_wp_query_args'] = array();
		$GLOBALS['cybermaps_mock_get_terms_args'] = array();
		$GLOBALS['cybermaps_mock_terms'] = array();
		$GLOBALS['cybermaps_mock_post_meta'] = array();
		$GLOBALS['cybermaps_mock_genesis_seo_active'] = false;
		unset( $GLOBALS['cybermaps_mock_wp_query_callback'] );
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->previous_wpdb;
		unset( $GLOBALS['cybermaps_mock_wp_query_callback'] );
		$GLOBALS['cybermaps_mock_filter_callbacks'] = array();
		$GLOBALS['cybermaps_mock_genesis_seo_active'] = false;
		parent::tearDown();
	}

	public function test_post_page_uses_one_bounded_raw_query_without_unbounded_refill(): void {
		$rows = array();
		for ( $id = 4000; $id > 2000; --$id ) {
			$rows[] = $this->post( $id );
		}
		$GLOBALS['cybermaps_mock_wp_query_callback'] = static fn ( array $args ): array => $rows;
		$GLOBALS['cybermaps_mock_filter_callbacks']['cybermaps_publication_eligibility'] = array(
			static fn ( $decision ) => $decision->with_reasons( array( 'test_exclusion' ) ),
		);

		$repository = new EligibleContentRepository();
		$this->assertSame( array(), $repository->get_post_page( 'post', 1, 2000 ) );
		$this->assertCount( 1, $GLOBALS['cybermaps_mock_wp_query_args'] );

		$args = $GLOBALS['cybermaps_mock_wp_query_args'][0];
		$this->assertSame( 2000, $args['posts_per_page'] );
		$this->assertSame( 1, $args['paged'] );
		$this->assertSame( 'ID', $args['orderby'] );
		$this->assertSame( 'DESC', $args['order'] );
		$this->assertTrue( $args['no_found_rows'] );
		$this->assertFalse( $args['has_password'] );
	}

	public function test_stable_raw_pages_cover_every_eligible_candidate_once(): void {
		$rows = array_map( $this->post( ... ), array_reverse( range( 1, 6 ) ) );
		$GLOBALS['cybermaps_mock_wp_query_callback'] = static function ( array $args ) use ( $rows ): array {
			$per_page = (int) $args['posts_per_page'];
			$page     = (int) $args['paged'];
			return array_slice( $rows, ( $page - 1 ) * $per_page, $per_page );
		};
		$GLOBALS['cybermaps_mock_post_meta'] = array(
			5 => array( '_cybermaps_exclude_sitemap' => '1' ),
			2 => array( '_cybermaps_exclude_sitemap' => '1' ),
		);

		$repository = new EligibleContentRepository();
		$eligible   = array_merge(
			$repository->get_post_page( 'post', 1, 2 ),
			$repository->get_post_page( 'post', 2, 2 ),
			$repository->get_post_page( 'post', 3, 2 )
		);

		$this->assertSame(
			array( 6, 4, 3, 1 ),
			array_map( static fn ( object $row ): int => (int) $row->ID, $eligible )
		);
		$this->assertCount( 3, $GLOBALS['cybermaps_mock_wp_query_args'] );
	}

	public function test_bounded_post_page_still_enforces_noindex_canonical_and_cybermaps_exclusions(): void {
		$GLOBALS['cybermaps_mock_genesis_seo_active'] = true;
		$GLOBALS['cybermaps_mock_post_meta'] = array(
			2 => array( '_genesis_noindex' => '1' ),
			3 => array( '_genesis_canonical_uri' => 'https://canonical.example/resource/' ),
			4 => array( '_cybermaps_exclude_sitemap' => '1' ),
		);
		$GLOBALS['cybermaps_mock_wp_query_callback'] = fn ( array $args ): array => array_map(
			$this->post( ... ),
			range( 1, 5 )
		);

		$repository = new EligibleContentRepository(
			array(
				'exclude_post_ids' => '5',
			)
		);
		$rows = $repository->get_post_page( 'post', 1, 2000 );

		$this->assertSame( array( 1 ), array_map( static fn ( object $row ): int => (int) $row->ID, $rows ) );
		$this->assertCount( 1, $GLOBALS['cybermaps_mock_wp_query_args'] );
	}

	public function test_large_post_count_is_one_aggregate_query_and_request_cached(): void {
		$GLOBALS['wpdb']->post_count = 850000;
		$repository = new EligibleContentRepository();

		$this->assertSame( 850000, $repository->get_post_count( 'post' ) );
		$this->assertSame( 850000, $repository->get_post_count( 'post' ) );
		$this->assertSame( 1, $GLOBALS['wpdb']->get_var_calls );
		$this->assertStringContainsString( 'COUNT(*)', $GLOBALS['wpdb']->prepared[0]['query'] );
		$this->assertSame( array( 'wp_posts', 'post' ), $GLOBALS['wpdb']->prepared[0]['args'] );
	}

	public function test_one_page_provider_counts_are_exact_after_runtime_policy(): void {
		$GLOBALS['wpdb']->post_count = 5;
		$GLOBALS['cybermaps_mock_wp_query_callback'] = fn ( array $args ): array => array_map(
			$this->post( ... ),
			range( 1, 5 )
		);
		$GLOBALS['cybermaps_mock_post_meta'] = array(
			2 => array( '_cybermaps_exclude_sitemap' => '1' ),
			3 => array( '_cybermaps_exclude_sitemap' => '1' ),
			4 => array( '_cybermaps_exclude_sitemap' => '1' ),
			5 => array( '_cybermaps_exclude_sitemap' => '1' ),
		);

		$this->assertSame( 1, ( new PostTypeProvider( 'post' ) )->get_count() );
		$this->assertCount( 1, $GLOBALS['cybermaps_mock_wp_query_args'] );

		$GLOBALS['cybermaps_mock_terms']['category'] = array(
			(object) array(
				'term_id'  => 1,
				'taxonomy' => 'category',
				'count'    => 1,
			),
			(object) array(
				'term_id'  => 2,
				'taxonomy' => 'category',
				'count'    => 1,
			),
		);
		$GLOBALS['cybermaps_mock_filter_callbacks']['cybermaps_publication_eligibility'] = array(
			static function ( $decision, $context ) {
				return 'term' === $context->type
					? $decision->with_reasons( array( 'global_taxonomy_noindex' ) )
					: $decision;
			},
		);

		$this->assertSame( 0, ( new TaxonomyProvider( 'category' ) )->get_count() );
		$this->assertSame( 2000, $GLOBALS['cybermaps_mock_get_terms_args'][0]['number'] );
	}

	public function test_post_type_sitemap_applies_shared_media_publication_bounds(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['media_discovery_intensity'] = 'advanced';
		$GLOBALS['wpdb']->post_count = 1;
		$post = $this->post( 1 );
		$GLOBALS['cybermaps_mock_wp_query_callback'] = static fn( array $args ): array => array( $post );

		$media = array();
		for ( $index = 1; $index <= 150; ++$index ) {
			$media[] = array(
				'type' => 'image',
				'url'  => 'https://example.com/image-' . $index . '.jpg',
			);
		}
		for ( $index = 1; $index <= 50; ++$index ) {
			$media[] = array(
				'type'          => 'video',
				'url'           => 'https://example.com/video-' . $index . '.mp4',
				'thumbnail_loc' => 'https://example.com/thumb-' . $index . '.jpg',
				'title'         => 'Video ' . $index,
			);
		}
		$GLOBALS['cybermaps_mock_post_meta'][1]['_cybermaps_media_audit'] = $media;
		$GLOBALS['cybermaps_mock_post_meta'][1]['_cybermaps_media_audit_mode'] = 'advanced';
		$GLOBALS['cybermaps_mock_post_meta'][1]['_cybermaps_media_audit_generation'] =
			MediaScanner::get_audit_generation();

		$urls = ( new PostTypeProvider( 'post' ) )->get_urls( 1 );

		$this->assertCount( 1, $urls );
		$this->assertCount(
			MediaScanner::MAX_MEDIA_ITEMS_PER_POST - MediaScanner::MAX_VIDEO_ITEMS_PER_POST,
			$urls[0]['images']
		);
		$this->assertCount( MediaScanner::MAX_VIDEO_ITEMS_PER_POST, $urls[0]['videos'] );
		$this->assertArrayHasKey( 'content_loc', $urls[0]['videos'][0] );
		$this->assertArrayNotHasKey( 'player_loc', $urls[0]['videos'][0] );
	}

	public function test_video_player_urls_use_public_frontend_while_media_assets_use_cdn(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'media_discovery_intensity' => 'advanced',
			'frontend_base_url'         => 'https://frontend.example/app',
			'cdn_enabled'               => '1',
			'cdn_base_url'              => 'https://cdn.example/media',
		);
		$GLOBALS['wpdb']->post_count = 1;
		$post = $this->post( 1 );
		$GLOBALS['cybermaps_mock_wp_query_callback'] = static fn( array $args ): array => array( $post );
		$GLOBALS['cybermaps_mock_post_meta'][1] = array(
			'_cybermaps_media_audit' => array(
				array(
					'type'           => 'video',
					'url'            => 'https://example.com/player/video-1',
					'video_url_type' => 'player',
					'thumbnail_loc'  => 'https://example.com/uploads/thumb.jpg',
					'title'          => 'Player test',
				),
			),
			'_cybermaps_media_audit_mode'       => 'advanced',
			'_cybermaps_media_audit_generation' => MediaScanner::get_audit_generation(),
		);

		$urls = ( new PostTypeProvider( 'post' ) )->get_urls( 1 );

		$this->assertSame(
			'https://frontend.example/app/player/video-1',
			$urls[0]['videos'][0]['player_loc']
		);
		$this->assertSame(
			'https://cdn.example/media/uploads/thumb.jpg',
			$urls[0]['videos'][0]['thumbnail_loc']
		);
		$this->assertArrayNotHasKey( 'content_loc', $urls[0]['videos'][0] );
	}

	public function test_term_page_lastmods_are_loaded_in_one_aggregate_query(): void {
		$term_ids = range( 1, 2000 );
		$GLOBALS['wpdb']->term_lastmod_rows = array(
			(object) array(
				'term_id' => 1,
				'lastmod' => '2026-07-29 10:00:00',
			),
			(object) array(
				'term_id' => 2000,
				'lastmod' => '2026-07-30 11:00:00',
			),
		);

		$lastmods = ( new EligibleContentRepository() )->get_term_lastmods( $term_ids, 'category' );

		$this->assertCount( 2000, $lastmods );
		$this->assertSame( '2026-07-29T10:00:00+00:00', $lastmods[1] );
		$this->assertSame( '2026-07-30T11:00:00+00:00', $lastmods[2000] );
		$this->assertSame( '', $lastmods[1000] );
		$this->assertSame( 1, $GLOBALS['wpdb']->get_results_calls );
		$this->assertCount( 2004, $GLOBALS['wpdb']->prepared[0]['args'] );
		$this->assertSame( 'category', $GLOBALS['wpdb']->prepared[0]['args'][3] );
	}

	public function test_author_and_archive_pages_use_one_grouped_query_each(): void {
		$author_rows = array();
		for ( $user_id = 1; $user_id <= 2000; ++$user_id ) {
			$author_rows[] = (object) array(
				'user_id' => $user_id,
				'lastmod' => '2026-07-29 10:00:00',
			);
		}
		$GLOBALS['wpdb']->result_rows = $author_rows;
		$repository = new EligibleContentRepository();

		$this->assertCount( 2000, $repository->get_author_page( 1, 2000 ) );
		$this->assertSame( 1, $GLOBALS['wpdb']->get_results_calls );
		$this->assertStringContainsString( 'GROUP BY post_author', $GLOBALS['wpdb']->prepared[0]['query'] );
		$this->assertSame( array( 'wp_posts', 'post', 2000, 0 ), $GLOBALS['wpdb']->prepared[0]['args'] );

		$GLOBALS['wpdb']->result_rows = array(
			(object) array(
				'archive_year'  => 2026,
				'archive_month' => 7,
				'lastmod'       => '2026-07-30 11:00:00',
			),
		);
		$this->assertCount( 1, $repository->get_archive_page( 1, 2000 ) );
		$this->assertSame( 1, $repository->get_archive_count() );
		$this->assertSame( '2026-07-30T11:00:00+00:00', $repository->get_archive_lastmod() );
		$this->assertSame( 2, $GLOBALS['wpdb']->get_results_calls );
		$this->assertStringContainsString( 'GROUP BY YEAR(post_date_gmt)', $GLOBALS['wpdb']->prepared[1]['query'] );
		$this->assertSame( array( 'wp_posts', 2400 ), $GLOBALS['wpdb']->prepared[1]['args'] );
	}

	public function test_recent_window_has_a_fixed_ten_query_scan_ceiling(): void {
		$rows = array();
		for ( $id = 1; $id <= 1000; ++$id ) {
			$rows[] = $this->post( $id );
		}
		$GLOBALS['cybermaps_mock_wp_query_callback'] = static fn ( array $args ): array => $rows;
		$GLOBALS['cybermaps_mock_filter_callbacks']['cybermaps_publication_eligibility'] = array(
			static fn ( $decision ) => $decision->with_reasons( array( 'test_exclusion' ) ),
		);

		$repository = new EligibleContentRepository();
		$this->assertSame(
			array(),
			$repository->get_recent_post_rows( 'post', 1785369600, 2 * DAY_IN_SECONDS, 1000 )
		);
		$this->assertCount( 10, $GLOBALS['cybermaps_mock_wp_query_args'] );
		foreach ( $GLOBALS['cybermaps_mock_wp_query_args'] as $index => $args ) {
			$this->assertSame( 1000, $args['posts_per_page'] );
			$this->assertSame( $index + 1, $args['paged'] );
			$this->assertTrue( $args['no_found_rows'] );
			$this->assertArrayHasKey( 'date_query', $args );
		}
	}

	private function post( int $id ): object {
		return (object) array(
			'ID'                => $id,
			'post_type'         => 'post',
			'post_status'       => 'publish',
			'post_password'     => '',
			'post_author'       => 1,
			'post_title'        => 'Post ' . $id,
			'post_date'         => '2026-07-29 09:00:00',
			'post_date_gmt'     => '2026-07-29 09:00:00',
			'post_modified_gmt' => '2026-07-29 10:00:00',
			'comment_count'     => 0,
		);
	}
}

final class SitemapScalingWpdbStub {
	public string $posts = 'wp_posts';
	public string $terms = 'wp_terms';
	public string $term_taxonomy = 'wp_term_taxonomy';
	public string $term_relationships = 'wp_term_relationships';

	public int $post_count = 0;
	public int $get_var_calls = 0;
	public int $get_results_calls = 0;

	/**
	 * @var array<int,array{query:string,args:array<int,mixed>}>
	 */
	public array $prepared = array();

	/**
	 * @var object[]
	 */
	public array $term_lastmod_rows = array();

	/**
	 * @var object[]
	 */
	public array $result_rows = array();

	public function prepare( string $query, mixed ...$args ): string {
		$this->prepared[] = array(
			'query' => $query,
			'args'  => $args,
		);
		return $query;
	}

	public function get_var( string $query ): mixed {
		unset( $query );
		++$this->get_var_calls;
		return $this->post_count;
	}

	/**
	 * @return object[]
	 */
	public function get_results( string $query ): array {
		unset( $query );
		++$this->get_results_calls;
		return ! empty( $this->term_lastmod_rows )
			? $this->term_lastmod_rows
			: $this->result_rows;
	}
}
