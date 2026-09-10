<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Discovery\PublicationInventory;

final class PublicationInventoryTest extends \WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_options']          = array( 'blog_public' => '1' );
		$GLOBALS['cybermaps_mock_posts']            = array();
		$GLOBALS['cybermaps_mock_post_meta']        = array();
		$GLOBALS['cybermaps_mock_taxonomies']       = array();
		$GLOBALS['cybermaps_mock_terms']            = array();
		$GLOBALS['cybermaps_mock_get_terms_args']   = array();
		$GLOBALS['cybermaps_mock_post_type_objects'] = array();
		$GLOBALS['cybermaps_mock_post_types']       = array( 'post', 'page', 'attachment' );
		$GLOBALS['cybermaps_mock_get_posts_args']   = array();
	}

	public function test_explicit_empty_included_types_publishes_no_content(): void {
		$GLOBALS['cybermaps_mock_posts'][10] = (object) array(
			'ID'                => 10,
			'post_type'         => 'post',
			'post_status'       => 'publish',
			'post_password'     => '',
			'post_modified_gmt' => '2026-07-01 00:00:00',
		);

		$inventory = new PublicationInventory(
			array( 'llms_included_types' => array() )
		);

		$this->assertFalse( $inventory->has_included_post_types() );
		$this->assertSame( array(), iterator_to_array( $inventory->iterate_posts(), false ) );
		$this->assertSame( array(), $inventory->get_query_args()['post_type'] );
	}

	public function test_query_helper_applies_types_ids_and_taxonomy_policy(): void {
		$GLOBALS['cybermaps_mock_taxonomies'] = array( 'category' );
		$inventory = new PublicationInventory(
			array(
				'llms_included_types'    => array( 'Page', 'page' ),
				'llms_exclude_ids'       => '12, 4',
				'llms_filter_taxonomies' => 'category,missing',
			)
		);

		$args = $inventory->get_query_args(
			array(
				's'              => 'support',
				'posts_per_page' => 25,
			)
		);

		$this->assertSame( array( 'page' ), $args['post_type'] );
		$this->assertSame( 'publish', $args['post_status'] );
		$this->assertSame( 'support', $args['s'] );
		$this->assertSame( 25, $args['posts_per_page'] );
		$this->assertSame( array( 12, 4 ), $args['post__not_in'] );
		$this->assertSame( 'OR', $args['tax_query']['relation'] );
		$this->assertSame( 'category', $args['tax_query'][0]['taxonomy'] );
		$this->assertSame( 'EXISTS', $args['tax_query'][0]['operator'] );
		$this->assertArrayNotHasKey( 'terms', $args['tax_query'][0] );
		$this->assertSame( array(), $GLOBALS['cybermaps_mock_get_terms_args'] );
	}

	public function test_attachment_is_never_part_of_the_llms_inventory(): void {
		$inventory = new PublicationInventory(
			array( 'llms_included_types' => array( 'attachment', 'post' ) )
		);

		$this->assertSame( array( 'post' ), $inventory->get_query_args()['post_type'] );
	}

	public function test_matrix_disabled_types_are_removed_before_the_query_runs(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_discovery_center'] = wp_json_encode(
			array(
				'archetype' => 'blog',
				'disabled'  => array( 'post_type:post' => true ),
			)
		);
		$inventory = new PublicationInventory(
			array( 'llms_included_types' => array( 'post', 'page' ) )
		);

		$this->assertSame( array( 'page' ), $inventory->get_query_args()['post_type'] );
	}

	public function test_complete_inventory_uses_bounded_query_batches(): void {
		$inventory = new PublicationInventory(
			array( 'llms_included_types' => array( 'post' ) )
		);

		$this->assertSame( array(), iterator_to_array( $inventory->iterate_posts(), false ) );
		$this->assertCount( 1, $GLOBALS['cybermaps_mock_get_posts_args'] );
		$this->assertSame( 1, $GLOBALS['cybermaps_mock_get_posts_args'][0]['posts_per_page'] );
		$this->assertSame( 'ids', $GLOBALS['cybermaps_mock_get_posts_args'][0]['fields'] );
		$this->assertFalse( $GLOBALS['cybermaps_mock_get_posts_args'][0]['cache_results'] );
	}

	public function test_inventory_query_is_lazy_and_pages_without_collecting_the_corpus(): void {
		for ( $id = 1; $id <= 26; ++$id ) {
			$GLOBALS['cybermaps_mock_posts'][ $id ] = $this->post( $id );
		}
		$inventory = new PublicationInventory(
			array( 'llms_included_types' => array( 'post' ) )
		);

		$iterator = $inventory->iterate_posts();
		$this->assertSame( array(), $GLOBALS['cybermaps_mock_get_posts_args'] );

		$posts = iterator_to_array( $iterator, false );

		$this->assertCount( 26, $posts );
		$this->assertCount( 2, $GLOBALS['cybermaps_mock_get_posts_args'] );
		$this->assertSame( 100, $GLOBALS['cybermaps_mock_get_posts_args'][1]['posts_per_page'] );
		$this->assertArrayNotHasKey( 'paged', $GLOBALS['cybermaps_mock_get_posts_args'][1] );
		$this->assertArrayHasKey( 'cybermaps_snapshot_id', $GLOBALS['cybermaps_mock_get_posts_args'][1] );
	}

	public function test_priority_inventory_is_batched_and_preserves_requested_order(): void {
		for ( $id = 1; $id <= 30; ++$id ) {
			$GLOBALS['cybermaps_mock_posts'][ $id ] = $this->post( $id );
		}
		$requested = range( 30, 1 );
		$inventory = new PublicationInventory(
			array( 'llms_included_types' => array( 'post' ) )
		);

		$posts = iterator_to_array( $inventory->iterate_posts_by_ids( $requested ), false );

		$this->assertSame( $requested, array_map( static fn( object $post ): int => (int) $post->ID, $posts ) );
		$this->assertCount( 1, $GLOBALS['cybermaps_mock_get_posts_args'] );
		$this->assertSame( 30, $GLOBALS['cybermaps_mock_get_posts_args'][0]['posts_per_page'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['cybermaps_mock_post_meta_observer'], $GLOBALS['cybermaps_mock_prime_post_meta_observer'], $GLOBALS['cybermaps_mock_get_posts_callback'] );
		parent::tearDown();
	}

	public function test_raw_candidate_budget_bounds_metadata_even_when_every_post_is_excluded(): void {
		for ( $id = 1; $id <= 600; ++$id ) {
			$GLOBALS['cybermaps_mock_posts'][ $id ] = $this->post( $id );
			$GLOBALS['cybermaps_mock_post_meta'][ $id ]['_cybermaps_exclude_ai'] = '1';
		}
		$read_ids = array();
		$primed = array();
		$GLOBALS['cybermaps_mock_post_meta_observer'] = static function ( $id ) use ( &$read_ids ): void { $read_ids[ $id ] = true; };
		$GLOBALS['cybermaps_mock_prime_post_meta_observer'] = static function ( $ids ) use ( &$primed ): void { $primed = array_merge( $primed, $ids ); };
		$scan = new \Cybermaps\Discovery\PublicationScanBudget( 250 );
		$inventory = new PublicationInventory( array( 'llms_included_types' => array( 'post' ) ) );
		self::assertSame( array(), iterator_to_array( $inventory->iterate_posts( array(), $scan ), false ) );
		self::assertSame( 250, $scan->scanned() );
		self::assertTrue( $scan->truncated() );
		self::assertSame( range( 1, 250 ), array_keys( $read_ids ) );
		self::assertSame( range( 1, 250 ), $primed );
		self::assertSame( array( 1, 100, 100, 51 ), array_column( $GLOBALS['cybermaps_mock_get_posts_args'], 'posts_per_page' ) );
	}

	public function test_pinned_and_regular_posts_share_one_budget_without_rechecking_pinned_ids(): void {
		for ( $id = 1; $id <= 20; ++$id ) {
			$GLOBALS['cybermaps_mock_posts'][ $id ] = $this->post( $id );
		}
		$GLOBALS['cybermaps_mock_post_meta'][20]['_cybermaps_exclude_ai'] = '1';
		$scan = new \Cybermaps\Discovery\PublicationScanBudget( 5 );
		$inventory = new PublicationInventory( array( 'llms_included_types' => array( 'post' ) ) );
		$pinned = iterator_to_array( $inventory->iterate_posts_by_ids( array( 20, 1, 19 ), $scan ), false );
		$rest = iterator_to_array( $inventory->iterate_posts( array( 20, 1, 19 ), $scan ), false );
		self::assertSame( array( 1, 19, 2, 3 ), array_column( array_merge( $pinned, $rest ), 'ID' ) );
		self::assertSame( 5, $scan->scanned() );
		self::assertTrue( $scan->truncated() );
	}

	public function test_complete_inventory_crosses_batch_boundaries_with_equal_timestamps(): void {
		for ( $id = 1; $id <= 225; ++$id ) {
			$post = $this->post( $id );
			$post->post_modified_gmt = '2026-09-01 00:00:00';
			$GLOBALS['cybermaps_mock_posts'][ $id ] = $post;
		}
		$inventory = new PublicationInventory( array( 'llms_included_types' => array( 'post' ) ) );
		self::assertSame( range( 1, 225 ), array_column( iterator_to_array( $inventory->iterate_posts(), false ), 'ID' ) );
	}

	public function test_repeated_batch_fails_before_emitting_a_duplicate(): void {
		$posts = array_map( fn ( int $id ): object => $this->post( $id ), range( 1, 100 ) );
		$GLOBALS['cybermaps_mock_get_posts_callback'] = static fn( $args ): array => 'ids' === ( $args['fields'] ?? '' ) ? array( 100 ) : $posts;
		$inventory = new PublicationInventory( array( 'llms_included_types' => array( 'post' ) ) );
		$emitted = array();
		try {
			foreach ( $inventory->iterate_posts() as $post ) { $emitted[] = $post->ID; }
			self::fail( 'Repeated pagination must fail.' );
		} catch ( \Cybermaps\Core\BuildUnavailableException $error ) {
			self::assertStringContainsString( 'pagination', $error->getMessage() );
		}
		self::assertSame( range( 1, 100 ), $emitted );
	}

	public function test_split_query_filter_is_scoped_and_removed_even_when_query_fails(): void {
		$matched_query = null;
		$GLOBALS['cybermaps_mock_get_posts_callback'] = static function ( $args ) use ( &$matched_query ): array {
			if ( 'ids' === ( $args['fields'] ?? '' ) ) { return array( 1 ); }
			self::assertFalse( $args['suppress_filters'] );
			$matched_query = new class( $args ) {
				public function __construct( private array $args ) {}
				public function get( $key ) { return $this->args[ $key ] ?? null; }
			};
			$other_query = new class { public function get( $key ) { return null; } };
			self::assertFalse( self::apply_split_filters( $matched_query ) );
			self::assertTrue( self::apply_split_filters( $other_query ) );
			throw new \RuntimeException( 'Query failed' );
		};
		try {
			iterator_to_array( ( new PublicationInventory( array( 'llms_included_types' => array( 'post' ) ) ) )->iterate_posts() );
			self::fail( 'Expected the query failure.' );
		} catch ( \RuntimeException $error ) {
			self::assertSame( 'Query failed', $error->getMessage() );
		}
		self::assertTrue( self::apply_split_filters( $matched_query ) );
	}

	private static function apply_split_filters( object $query ): bool {
		$value = true;
		foreach ( $GLOBALS['wp_hooks'] ?? array() as $hook ) {
			if ( 'filter' === $hook['type'] && 'split_the_query' === $hook['hook'] ) {
				$value = ( $hook['callback'] )( $value, $query );
			}
		}
		return $value;
	}

	private function post( int $id ): object {
		return (object) array(
			'ID'                => $id,
			'post_title'        => 'Post ' . $id,
			'post_content'      => 'Visible content.',
			'post_excerpt'      => '',
			'post_type'         => 'post',
			'post_status'       => 'publish',
			'post_password'     => '',
			'post_modified_gmt' => gmdate( 'Y-m-d H:i:s', 100000 - $id ),
			'post_date_gmt'     => gmdate( 'Y-m-d H:i:s', 100000 - $id ),
		);
	}
}
