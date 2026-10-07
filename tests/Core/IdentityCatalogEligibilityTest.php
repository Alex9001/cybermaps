<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Core;

use Cybermaps\Core\IdentityEntityBuilder;
use Cybermaps\Discovery\KnowledgeGraph;
use Cybermaps\SEO\PublicationEligibility;
use Cybermaps\Sitemap\Schema;

final class IdentityCatalogEligibilityTest extends \WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_options'] = array( 'blog_public' => '1', 'cybermaps_settings' => array( 'enable_discovery_hub' => '1' ) );
		$GLOBALS['cybermaps_mock_post_types'] = array( 'page' );
		$GLOBALS['cybermaps_mock_post_type_objects'] = array( 'page' => (object) array( 'name' => 'page', 'public' => true, 'publicly_queryable' => true ) );
		$GLOBALS['cybermaps_mock_taxonomies'] = array();
		$GLOBALS['cybermaps_mock_post_meta'] = array();
		$GLOBALS['cybermaps_mock_genesis_seo_active'] = true;
		$GLOBALS['cybermaps_mock_genesis_seo_options'] = array();
		$GLOBALS['cybermaps_mock_get_pages_args'] = array();
		$GLOBALS['cybermaps_mock_posts'] = array();
		foreach ( array( 100 => 'Derived parent', 101 => 'Derived child', 102 => 'Allowed child' ) as $id => $title ) {
			$GLOBALS['cybermaps_mock_posts'][ $id ] = (object) array( 'ID' => $id, 'post_parent' => 100 === $id ? 0 : 100, 'post_type' => 'page', 'post_status' => 'publish', 'post_password' => '', 'post_title' => $title );
		}
		$GLOBALS['cybermaps_mock_pages'] = array( $GLOBALS['cybermaps_mock_posts'][101], $GLOBALS['cybermaps_mock_posts'][102] );
		$GLOBALS['cybermaps_mock_options']['cybermaps_identity_data'] = array( 'name' => 'Operator identity', 'catalogs' => array(
			array( 'mode' => 'auto', 'parent_id' => 100 ),
			array( 'mode' => 'manual', 'name' => 'Operator catalog', 'items' => array( 'Operator offer' ) ),
		) );
	}

	private function graph_identity(): array {
		return json_decode( ( new KnowledgeGraph() )->get_json_content(), true )['@graph'][0];
	}

	private function page_identity(): array {
		$GLOBALS['cybermaps_mock_is_front_page'] = true;
		$GLOBALS['cybermaps_mock_inline_scripts'] = array();
		ob_start();
		try { ( new Schema() )->inject_identity_schema(); }
		finally { ob_end_clean(); }
		return json_decode( $GLOBALS['cybermaps_mock_inline_scripts'][0]['data'], true );
	}

	public function test_ai_excluded_child_is_omitted_from_graph_but_remains_in_onpage_schema(): void {
		update_post_meta( 101, '_cybermaps_exclude_ai', '1' );
		$graph = $this->graph_identity();
		$this->assertSame( array( 'Allowed child' ), array_column( array_column( $graph['hasOfferCatalog'][0]['itemListElement'], 'itemOffered' ), 'name' ) );
		$this->assertSame( 'Operator offer', $graph['hasOfferCatalog'][1]['itemListElement'][0]['itemOffered']['name'] );
		$this->assertSame( array( 'Derived child', 'Allowed child' ), array_column( array_column( $this->page_identity()['hasOfferCatalog'][0]['itemListElement'], 'itemOffered' ), 'name' ) );
		$this->assertSame( IdentityEntityBuilder::MAX_OFFERS_PER_CATALOG, $GLOBALS['cybermaps_mock_get_pages_args'][0]['number'] );
	}

	public function test_ai_excluded_parent_suppresses_derived_catalog_before_query_or_title(): void {
		update_post_meta( 100, '_cybermaps_exclude_ai', '1' );
		$graph = $this->graph_identity();
		$this->assertCount( 1, $graph['hasOfferCatalog'] );
		$this->assertSame( 'Operator catalog', $graph['hasOfferCatalog'][0]['name'] );
		$this->assertStringNotContainsString( 'Derived parent', wp_json_encode( $graph ) );
		$this->assertSame( array(), $GLOBALS['cybermaps_mock_get_pages_args'] );
		$this->assertCount( 2, $this->page_identity()['hasOfferCatalog'] );
	}

	/** @dataProvider base_exclusions */
	public function test_base_exclusions_apply_to_both_channels( string $kind, bool $parent ): void {
		$id = $parent ? 100 : 101;
		if ( 'noindex' === $kind ) { update_post_meta( $id, '_genesis_noindex', '1' ); }
		elseif ( 'password' === $kind ) { $GLOBALS['cybermaps_mock_posts'][ $id ]->post_password = 'protected'; }
		else { $GLOBALS['cybermaps_mock_posts'][ $id ]->post_status = 'private'; }
		foreach ( array( PublicationEligibility::AI, PublicationEligibility::SCHEMA ) as $channel ) {
			$entity = IdentityEntityBuilder::build( $GLOBALS['cybermaps_mock_options']['cybermaps_identity_data'], false, $channel );
			$this->assertStringNotContainsString( $parent ? 'Derived parent' : 'Derived child', wp_json_encode( $entity ) );
			$this->assertStringContainsString( 'Operator offer', wp_json_encode( $entity ) );
		}
	}

	public static function base_exclusions(): array {
		return array( 'noindex parent' => array( 'noindex', true ), 'noindex child' => array( 'noindex', false ), 'private parent' => array( 'private', true ), 'private child' => array( 'private', false ), 'password parent' => array( 'password', true ), 'password child' => array( 'password', false ) );
	}
}
