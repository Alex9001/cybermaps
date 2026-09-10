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
