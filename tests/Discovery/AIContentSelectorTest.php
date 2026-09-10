<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Discovery\AIContentSelector;
use Cybermaps\Discovery\Chunker;
use Cybermaps\Discovery\PublicationConstraints;
use Cybermaps\Discovery\RAGChunk;

class AIContentSelectorTest extends \WP_UnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_post_types']     = array( 'post', 'page' );
		$GLOBALS['cybermaps_mock_post_meta']      = array();
		$GLOBALS['cybermaps_mock_posts']          = array();
		$GLOBALS['cybermaps_mock_transients']     = array();
		$GLOBALS['cybermaps_mock_get_posts_args'] = array();
		unset( $GLOBALS['cybermaps_mock_get_transient_observer'] );
		$GLOBALS['cybermaps_mock_options']        = array(
			'cybermaps_discovery_center' => \wp_json_encode(
				array( 'archetype' => 'medium-business' )
			),
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['cybermaps_mock_get_transient_observer'] );
		parent::tearDown();
	}

	public function test_manual_priority_override_controls_ai_inventory(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_discovery_center'] = \wp_json_encode(
			array(
				'archetype' => 'blog',
				'overrides' => array(
					'post' => 0,
					'page' => 0.7,
				),
			)
		);
		$queried  = array();
		$selector = new AIContentSelector(
			array( 'ai_sitemap_types' => array( 'post', 'page' ) ),
			static function ( array $args ) use ( &$queried ): array {
				$queried[] = $args['post_type'];
				return array(
					(object) array(
						'ID'        => 30,
						'post_type' => $args['post_type'],
					),
				);
			}
		);

		$this->assertSame( array( 30 ), array_map( static fn( object $post ): int => (int) $post->ID, $selector->get_posts() ) );
		$this->assertSame( array( 'page' ), $queried );
		$this->assertSame( 0.0, $selector->get_weight( 'post' ) );
		$this->assertSame( 0.7, $selector->get_weight( 'page' ) );
	}

	public function test_ai_weights_use_the_post_type_identity_when_a_taxonomy_shares_the_slug(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_discovery_center'] = \wp_json_encode(
			array(
				'archetype' => 'blog',
				'overrides' => array(
					'post_type:shared' => 0.8,
					'taxonomy:shared'  => 0.1,
				),
			)
		);

		$selector = new AIContentSelector( array( 'ai_sitemap_types' => array( 'shared' ) ) );
		$this->assertSame( 0.8, $selector->get_weight( 'shared' ) );
	}

	public function test_selector_pages_past_ineligible_candidates_to_fill_limit(): void {
		$GLOBALS['cybermaps_mock_post_types'] = array( 'post' );
		for ( $id = 1; $id <= 250; ++$id ) {
			if ( 2 !== $id ) {
				$GLOBALS['cybermaps_mock_post_meta'][ $id ]['_cybermaps_exclude_ai'] = '1';
			}
		}
		$pages    = array();
		$selector = new AIContentSelector(
			array(
				'ai_sitemap_types' => array( 'post' ),
				'ai_sitemap_limit' => 2,
			),
			static function ( array $args ) use ( &$pages ): array {
				$pages[] = $args['paged'];
				$ids     = 1 === $args['paged'] ? range( 1, 250 ) : array( 251 );
				return array_map(
					static fn( int $id ): object => (object) array(
						'ID'            => $id,
						'post_type'     => 'post',
						'post_status'   => 'publish',
						'post_password' => '',
					),
					$ids
				);
			}
		);

		$this->assertSame(
			array( 2, 251 ),
			array_map( static fn( object $post ): int => (int) $post->ID, $selector->get_posts() )
		);
		$this->assertSame( array( 1, 2 ), $pages );
	}

	public function test_selector_excludes_attachments_and_clamps_legacy_unbounded_limits(): void {
		$queries  = array();
		$selector = new AIContentSelector(
			array(
				'ai_sitemap_types' => array( 'attachment', 'post' ),
				'ai_sitemap_limit' => PHP_INT_MAX,
			),
			static function ( array $args ) use ( &$queries ): array {
				$queries[] = $args;
				return array();
			}
		);

		$this->assertSame( array(), $selector->get_posts() );
		$this->assertCount( 1, $queries );
		$this->assertSame( 'post', $queries[0]['post_type'] );
		$this->assertSame( 250, $queries[0]['posts_per_page'] );
	}

	public function test_selector_applies_one_deterministic_exclusion_policy(): void {
		$queries  = array();
		$settings = array(
			'ai_sitemap_types'         => array( 'post', 'page' ),
			'ai_sitemap_limit'         => 25,
			'llms_exclude_ids'         => '7, 12',
			'ai_sitemap_exclude_terms' => 'private, Members Only',
		);
		$query    = static function ( array $args ) use ( &$queries ): array {
			$queries[] = $args;
			$id        = 'post' === $args['post_type'] ? 101 : 202;
			return array(
				(object) array(
					'ID'        => $id,
					'post_type' => $args['post_type'],
				),
			);
		};
		$selector = new AIContentSelector(
			$settings,
			$query,
			static fn( string $post_type ): array => array( $post_type . '_category' )
		);

		$posts = $selector->get_posts();

		$this->assertSame( array( 101, 202 ), \array_map( static fn( object $post ): int => (int) $post->ID, $posts ) );
		$this->assertTrue( $selector->contains( 101 ) );
		$this->assertFalse( $selector->contains( 7 ) );
		$this->assertCount( 2, $queries );

		foreach ( $queries as $args ) {
			$this->assertSame( 'publish', $args['post_status'] );
			$this->assertFalse( $args['has_password'] );
			$this->assertSame( 250, $args['posts_per_page'] );
			$this->assertSame(
				array(
					'modified' => 'DESC',
					'ID'       => 'DESC',
				),
				$args['orderby']
			);
			$this->assertSame( array( 7, 12 ), $args['post__not_in'] );
			$this->assertSame( '_cybermaps_exclude_ai', $args['meta_query'][1]['key'] );
			$this->assertSame( array( 'private', 'members-only' ), $args['tax_query'][0]['terms'] );
			$this->assertSame( 'NOT IN', $args['tax_query'][0]['operator'] );
		}
	}

	public function test_disabled_content_group_is_not_queried_for_the_sitemap_or_chunks(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_discovery_center'] = \wp_json_encode(
			array(
				'archetype' => 'corporate',
				'disabled'  => array( 'post_type:post' => true ),
			)
		);
		$queried_types = array();
		$selector      = new AIContentSelector(
			array( 'ai_sitemap_types' => array( 'post', 'page' ) ),
			static function ( array $args ) use ( &$queried_types ): array {
				$queried_types[] = $args['post_type'];
				return array(
					(object) array(
						'ID'        => 55,
						'post_type' => $args['post_type'],
					),
				);
			}
		);

		$selector->get_posts();
		$this->assertSame( array( 'page' ), $queried_types );
		$this->assertTrue( $selector->contains( 55 ) );
	}

	public function test_dynamic_chunk_never_invokes_chunker_for_unselected_content(): void {
		$selector = new class() extends AIContentSelector {
			public function contains( int $post_id ): bool {
				return 10 === $post_id;
			}
		};
		$chunker  = new class() extends Chunker {
			public int $calls = 0;

			public function get_chunks( $post_id ) {
				++$this->calls;
				return array(
					'post_id' => (int) $post_id,
					'chunks'  => array(
						array(
							'index' => 0,
							'text'  => 'Allowed',
						),
					),
				);
			}
		};
		$endpoint = new RAGChunk( $selector, $chunker );

		$this->assertNull( $endpoint->get_payload( 11 ) );
		$this->assertSame( 0, $chunker->calls );
		$this->assertSame( 10, $endpoint->get_payload( 10 )['post_id'] );
		$this->assertSame( 10, \json_decode( (string) $endpoint->get_content( 10 ), true )['post_id'] );
		$this->assertSame( 2, $chunker->calls );
	}

	public function test_cached_inventory_hydrates_in_bounded_batches_and_preserves_order(): void {
		$GLOBALS['cybermaps_mock_post_types'] = array( 'post' );
		$cached_ids                           = range( 1, 501 );
		foreach ( $cached_ids as $post_id ) {
			$GLOBALS['cybermaps_mock_posts'][ $post_id ] = (object) array(
				'ID'            => $post_id,
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_password' => '',
			);
		}
		set_transient(
			'cybermaps_ai_publication_inventory',
			array_merge( array( 0, 'invalid', 1 ), $cached_ids, array( 501 ) ),
			MINUTE_IN_SECONDS
		);

		$posts = ( new AIContentSelector(
			array( 'ai_sitemap_types' => array( 'post' ) )
		) )->get_posts();

		$this->assertSame(
			$cached_ids,
			array_map( static fn( object $post ): int => (int) $post->ID, $posts )
		);
		$this->assertCount( 3, $GLOBALS['cybermaps_mock_get_posts_args'] );
		foreach ( $GLOBALS['cybermaps_mock_get_posts_args'] as $args ) {
			$this->assertLessThanOrEqual( 250, count( $args['post__in'] ) );
			$this->assertSame( count( $args['post__in'] ), $args['posts_per_page'] );
			$this->assertSame( 'post__in', $args['orderby'] );
			$this->assertSame( 'publish', $args['post_status'] );
		}
	}

	public function test_cached_chunk_membership_does_not_hydrate_the_whole_inventory(): void {
		$GLOBALS['cybermaps_mock_post_types'] = array( 'post' );
		$GLOBALS['cybermaps_mock_posts'][400] = (object) array(
			'ID'            => 400,
			'post_type'     => 'post',
			'post_status'   => 'publish',
			'post_password' => '',
		);
		set_transient(
			'cybermaps_ai_publication_inventory',
			range( 1, 501 ),
			MINUTE_IN_SECONDS
		);

		$this->assertTrue(
			( new AIContentSelector(
				array( 'ai_sitemap_types' => array( 'post' ) )
			) )->contains( 400 )
		);
		$this->assertSame( array(), $GLOBALS['cybermaps_mock_get_posts_args'] );
	}

	public function test_repeated_membership_checks_normalize_the_cached_inventory_only_once(): void {
		$GLOBALS['cybermaps_mock_post_types'] = array( 'post' );
		foreach ( array( 7, 9, 11 ) as $post_id ) {
			$GLOBALS['cybermaps_mock_posts'][ $post_id ] = (object) array(
				'ID'            => $post_id,
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_password' => '',
			);
		}
		set_transient(
			'cybermaps_ai_publication_inventory',
			array( 0, 'invalid', '7', 7, 9, array( 11 ) ),
			MINUTE_IN_SECONDS
		);

		$inventory_reads = 0;
		$GLOBALS['cybermaps_mock_get_transient_observer'] = static function ( string $key ) use ( &$inventory_reads ): void {
			if ( 'cybermaps_ai_publication_inventory' === $key ) {
				++$inventory_reads;
			}
		};
		$selector = new AIContentSelector( array( 'ai_sitemap_types' => array( 'post' ) ) );

		$this->assertTrue( $selector->contains( 7 ) );
		$this->assertTrue( $selector->contains( 9 ) );
		$this->assertFalse( $selector->contains( 11 ) );
		$this->assertTrue( $selector->contains( 7 ) );
		$this->assertSame( 1, $inventory_reads );
		$this->assertSame( array(), $GLOBALS['cybermaps_mock_get_posts_args'] );
	}

	public function test_runtime_inventory_is_shared_across_chunk_authorization_requests(): void {
		$GLOBALS['cybermaps_mock_post_types'] = array( 'post' );
		$GLOBALS['cybermaps_mock_posts'][81]  = (object) array(
			'ID'                => 81,
			'post_type'         => 'post',
			'post_status'       => 'publish',
			'post_password'     => '',
			'post_modified_gmt' => '2026-07-29 00:00:00',
		);
		$settings                             = array(
			'ai_sitemap_types' => array( 'post' ),
			'ai_sitemap_limit' => 20,
		);

		$this->assertTrue( ( new AIContentSelector( $settings ) )->contains( 81 ) );
		$first_query_count = count( $GLOBALS['cybermaps_mock_get_posts_args'] );
		$this->assertGreaterThan( 0, $first_query_count );

		$this->assertTrue( ( new AIContentSelector( $settings ) )->contains( 81 ) );
		$this->assertSame( $first_query_count, count( $GLOBALS['cybermaps_mock_get_posts_args'] ) );
	}

	public function test_runtime_chunk_authorization_rejects_missing_posts_without_inventory_scan(): void {
		$GLOBALS['cybermaps_mock_post_types'] = array( 'post' );

		$this->assertFalse(
			( new AIContentSelector( array( 'ai_sitemap_types' => array( 'post' ) ) ) )->contains( 999 )
		);
		$this->assertSame( array(), $GLOBALS['cybermaps_mock_get_posts_args'] );
	}

	public function test_id_batches_resume_without_duplicates_and_match_the_canonical_inventory(): void {
		$GLOBALS['cybermaps_mock_post_types'] = array( 'post' );
		$posts                                = array();
		for ( $post_id = 1; $post_id <= 275; ++$post_id ) {
			$posts[] = (object) array(
				'ID'            => $post_id,
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'post_password' => '',
			);
		}

		$query    = static function ( array $args ) use ( $posts ): array {
			$per_page = max( 1, (int) ( $args['posts_per_page'] ?? 1 ) );
			$page     = max( 1, (int) ( $args['paged'] ?? 1 ) );
			return \array_slice( $posts, ( $page - 1 ) * $per_page, $per_page );
		};
		$settings = array(
			'ai_sitemap_types' => array( 'post' ),
			'ai_sitemap_limit' => 300,
		);
		$expected = \array_map(
			static fn ( object $post ): int => (int) $post->ID,
			( new AIContentSelector( $settings, $query ) )->get_posts()
		);

		$selector   = new AIContentSelector( $settings, $query );
		$cursor     = array();
		$selected   = array();
		$iterations = 0;
		do {
			$batch = $selector->get_id_batch( $cursor, 25 );
			$this->assertLessThanOrEqual( 25, \count( $batch['ids'] ) );
			$this->assertSame(
				array( 'type_index', 'page', 'position', 'type_selected', 'inspected' ),
				\array_keys( $batch['cursor'] )
			);
			$selected = \array_merge( $selected, $batch['ids'] );
			$cursor   = $batch['cursor'];
			++$iterations;
			$this->assertLessThanOrEqual( 12, $iterations, 'The bounded cursor must make forward progress.' );
		} while ( ! $batch['complete'] );

		$this->assertSame( 11, $iterations );
		$this->assertSame( $expected, $selected );
		$this->assertSame( $selected, \array_values( \array_unique( $selected ) ) );
	}

	public function test_id_batch_rejects_malformed_or_unbounded_cursors(): void {
		$selector = new AIContentSelector( array( 'ai_sitemap_types' => array( 'post' ) ) );
		$cursors  = array(
			'unknown field'        => array( 'unexpected' => 1 ),
			'non-integer position' => array( 'position' => '1' ),
			'zero page'            => array( 'page' => 0 ),
			'oversized position'   => array( 'position' => 251 ),
			'oversized inspected'  => array( 'inspected' => 5001 ),
		);

		foreach ( $cursors as $label => $cursor ) {
			try {
				$selector->get_id_batch( $cursor );
				$this->fail( $label . ' should have been rejected.' );
			} catch ( \InvalidArgumentException ) {
				$this->addToAssertionCount( 1 );
			}
		}
	}
}
