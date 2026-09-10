<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Sitemap;

use Cybermaps\Core\IdentityEntityBuilder;
use Cybermaps\Core\SchemaRegistry;
use Cybermaps\Discovery\KnowledgeGraph;
use Cybermaps\Sitemap\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SchemaTest extends \WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_is_front_page']   = false;
		$GLOBALS['cybermaps_mock_is_singular']     = false;
		$GLOBALS['cybermaps_mock_current_post_id'] = 0;
		$GLOBALS['cybermaps_mock_inline_scripts']  = array();
		$GLOBALS['cybermaps_mock_post_types']      = array();
		$GLOBALS['cybermaps_mock_taxonomies']      = array();
		$GLOBALS['cybermaps_mock_post_type_objects'] = array();
		$GLOBALS['cybermaps_mock_taxonomy_objects']  = array();
		$GLOBALS['cybermaps_mock_post_type_archive_links'] = array();
		$GLOBALS['cybermaps_mock_posts']           = array();
		$GLOBALS['cybermaps_mock_pages']           = array();
		$GLOBALS['cybermaps_mock_get_pages_args']  = array();
		$GLOBALS['cybermaps_mock_permalinks']      = array();
		$GLOBALS['cybermaps_mock_post_meta']       = array();
		$GLOBALS['cybermaps_mock_attachment_images'] = array();
		$GLOBALS['cybermaps_mock_attachment_urls']   = array();
		$GLOBALS['cybermaps_mock_users_by_email']    = array();
		$GLOBALS['cybermaps_mock_options'] = array(
			'blog_public'         => '1',
			'cybermaps_media_audit_generation' => 1,
			'cybermaps_settings' => array(
				'enable_discovery_hub' => '1',
				'enable_video_schema'  => '1',
				'media_discovery_intensity' => 'advanced',
			),
		);
		$GLOBALS['cybermaps_mock_post_meta'][42] = array(
			'_cybermaps_media_audit_generation' => 1,
			'_cybermaps_media_audit_mode'       => 'advanced',
		);
	}

	public function test_schema_registry_keeps_broad_and_precise_types_compatible(): void {
		$this->assertSame(
			'Person',
			SchemaRegistry::get_entity_type(
				array(
					'type'         => 'Person',
					'precise_type' => 'Restaurant',
				)
			)
		);
		$this->assertSame(
			'Organization',
			SchemaRegistry::get_entity_type(
				array(
					'type'         => array( 'Person' ),
					'precise_type' => array( 'Restaurant' ),
				)
			)
		);
		$this->assertSame(
			'LocalBusiness',
			SchemaRegistry::get_entity_type(
				array(
					'type'         => 'LocalBusiness',
					'precise_type' => 'Corporation',
				)
			)
		);
		$this->assertSame(
			'Restaurant',
			SchemaRegistry::get_entity_type(
				array(
					'type'         => 'LocalBusiness',
					'precise_type' => 'Restaurant',
				)
			)
		);
		$this->assertSame(
			'Organization',
			SchemaRegistry::get_entity_type(
				array(
					'type'         => 'Organization',
					'precise_type' => 'Person',
				)
			)
		);
	}

	public function test_homepage_person_schema_uses_person_type_and_stable_person_id(): void {
		$GLOBALS['cybermaps_mock_is_front_page'] = true;
		$GLOBALS['cybermaps_mock_options']['cybermaps_identity_data'] = array(
			'type'         => 'Person',
			'precise_type' => 'Restaurant',
			'name'         => 'Alex Example',
			'description'  => 'Independent publisher',
		);

		ob_start();
		( new Schema() )->inject_identity_schema();
		ob_end_clean();

		$this->assertCount( 1, $GLOBALS['cybermaps_mock_inline_scripts'] );
		$schema = json_decode( $GLOBALS['cybermaps_mock_inline_scripts'][0]['data'], true );
		$this->assertSame( 'Person', $schema['@type'] );
		$this->assertSame( 'https://example.com/#person', $schema['@id'] );
	}

	public function test_homepage_identity_schema_rejects_a_malformed_name(): void {
		$GLOBALS['cybermaps_mock_is_front_page'] = true;
		$GLOBALS['cybermaps_mock_options']['cybermaps_identity_data'] = array(
			'type' => 'Organization',
			'name' => array( 'Not a scalar name' ),
		);

		ob_start();
		( new Schema() )->inject_identity_schema();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
		$this->assertSame( array(), $GLOBALS['cybermaps_mock_inline_scripts'] );
	}

	public function test_video_schema_skips_incomplete_or_invalid_rows(): void {
		$this->configureSingularPost();
		$GLOBALS['cybermaps_mock_post_meta'][42]['_cybermaps_media_audit'] = array(
			array(
				'type'          => 'video',
				'url'           => '',
				'thumbnail_loc' => 'https://example.com/empty-source.jpg',
			),
			array(
				'type'          => 'video',
				'url'           => 'javascript:alert(1)',
				'thumbnail_loc' => 'https://example.com/unsafe.jpg',
			),
			array(
				'type'          => 'video',
				'url'           => 'https://video.example/watch?v=42',
				'thumbnail_loc' => '',
			),
		);

		ob_start();
		( new Schema() )->inject_video_schema();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
		$this->assertSame( array(), $GLOBALS['cybermaps_mock_inline_scripts'] );
	}

	public function test_video_schema_emits_only_complete_valid_objects(): void {
		$this->configureSingularPost();
		$GLOBALS['cybermaps_mock_post_meta'][42]['_cybermaps_media_audit'] = array(
			array(
				'type'          => 'video',
				'url'           => 'https://video.example/watch?v=42&source=site',
				'thumbnail_loc' => 'https://cdn.example/thumb.jpg',
				'title'         => 'Product walkthrough',
			),
			array(
				'type'          => 'video',
				'url'           => 'https://video.example/watch?v=43',
				'thumbnail_loc' => 'data:image/png;base64,unsafe',
				'title'         => 'Invalid thumbnail',
			),
		);

		ob_start();
		( new Schema() )->inject_video_schema();
		ob_end_clean();

		$this->assertCount( 1, $GLOBALS['cybermaps_mock_inline_scripts'] );
		$schema = json_decode( $GLOBALS['cybermaps_mock_inline_scripts'][0]['data'], true );
		$this->assertSame( 'VideoObject', $schema['@type'] );
		$this->assertSame( 'Product walkthrough', $schema['name'] );
		$this->assertSame( 'https://cdn.example/thumb.jpg', $schema['thumbnailUrl'] );
		$this->assertSame( 'https://video.example/watch?v=42&source=site', $schema['contentUrl'] );
		$this->assertStringStartsWith( 'https://example.com/?p=42#video-', $schema['@id'] );
	}

	public function test_video_schema_uses_headless_urls_without_applying_sitemap_cdn(): void {
		$this->configureSingularPost();
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['frontend_base_url'] = 'https://frontend.example/app';
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['cdn_enabled'] = '1';
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['cdn_base_url'] = 'https://cdn.example/media';
		$GLOBALS['cybermaps_mock_post_meta'][42]['_cybermaps_media_audit'] = array(
			array(
				'type'          => 'video',
				'url'           => 'https://example.com/uploads/video.mp4',
				'thumbnail_loc' => 'https://example.com/uploads/thumbnail.jpg',
				'title'         => 'Headless walkthrough',
			),
		);

		ob_start();
		( new Schema() )->inject_video_schema();
		ob_end_clean();

		$schema = json_decode( $GLOBALS['cybermaps_mock_inline_scripts'][0]['data'], true );
		$this->assertSame(
			'https://frontend.example/app/uploads/video.mp4',
			$schema['contentUrl']
		);
		$this->assertSame(
			'https://frontend.example/app/uploads/thumbnail.jpg',
			$schema['thumbnailUrl']
		);
		$this->assertStringStartsWith(
			'https://frontend.example/app/?p=42#video-',
			$schema['@id']
		);
		$this->assertStringNotContainsString( 'cdn.example', (string) wp_json_encode( $schema ) );
	}

	public function test_video_schema_default_matches_the_enabled_admin_toggle(): void {
		$this->configureSingularPost();
		unset( $GLOBALS['cybermaps_mock_options']['cybermaps_settings']['enable_video_schema'] );
		$GLOBALS['cybermaps_mock_post_meta'][42]['_cybermaps_media_audit'] = array(
			array(
				'type'          => 'video',
				'url'           => 'https://video.example/watch?v=42',
				'thumbnail_loc' => 'https://cdn.example/thumb.jpg',
				'title'         => 'Default-on walkthrough',
			),
		);

		ob_start();
		( new Schema() )->inject_video_schema();
		ob_end_clean();

		$this->assertCount( 1, $GLOBALS['cybermaps_mock_inline_scripts'] );

		$GLOBALS['cybermaps_mock_inline_scripts'] = array();
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['enable_video_schema'] = '0';
		ob_start();
		( new Schema() )->inject_video_schema();
		ob_end_clean();

		$this->assertSame( array(), $GLOBALS['cybermaps_mock_inline_scripts'] );
	}

	public function test_video_schema_does_not_reuse_stale_scan_data_when_media_discovery_is_off(): void {
		$this->configureSingularPost();
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['media_discovery_intensity'] = 'none';
		$GLOBALS['cybermaps_mock_post_meta'][42]['_cybermaps_media_audit'] = array(
			array(
				'type'          => 'video',
				'url'           => 'https://video.example/watch?v=42',
				'thumbnail_loc' => 'https://cdn.example/thumb.jpg',
				'title'         => 'Stale scan row',
			),
		);

		ob_start();
		( new Schema() )->inject_video_schema();
		ob_end_clean();

		$this->assertSame( array(), $GLOBALS['cybermaps_mock_inline_scripts'] );
	}

	public function test_video_schema_normalizes_legacy_domain_only_scanner_urls(): void {
		$this->configureSingularPost();
		$GLOBALS['cybermaps_mock_post_meta'][42]['_cybermaps_media_audit'] = array(
			array(
				'type'          => 'video',
				'url'           => 'youtube.com/watch?v=dQw4w9WgXcQ',
				'thumbnail_loc' => 'https://img.youtube.com/vi/dQw4w9WgXcQ/hqdefault.jpg',
				'title'         => 'Legacy scan',
			),
		);

		ob_start();
		( new Schema() )->inject_video_schema();
		ob_end_clean();

		$this->assertCount( 1, $GLOBALS['cybermaps_mock_inline_scripts'] );
		$schema = json_decode( $GLOBALS['cybermaps_mock_inline_scripts'][0]['data'], true );
		$this->assertSame(
			'https://www.youtube.com/embed/dQw4w9WgXcQ',
			$schema['embedUrl']
		);
		$this->assertArrayNotHasKey( 'contentUrl', $schema );
	}

	public function test_video_schema_applies_the_shared_per_post_video_bound(): void {
		$this->configureSingularPost();
		$rows = array();
		for ( $index = 1; $index <= 50; ++$index ) {
			$rows[] = array(
				'type'          => 'video',
				'url'           => 'https://video.example/video-' . $index . '.mp4',
				'thumbnail_loc' => 'https://video.example/thumb-' . $index . '.jpg',
				'title'         => 'Video ' . $index,
			);
		}
		$GLOBALS['cybermaps_mock_post_meta'][42]['_cybermaps_media_audit'] = $rows;

		ob_start();
		( new Schema() )->inject_video_schema();
		ob_end_clean();

		$this->assertCount(
			\Cybermaps\Sitemap\MediaScanner::MAX_VIDEO_ITEMS_PER_POST,
			$GLOBALS['cybermaps_mock_inline_scripts']
		);
	}

	public function test_knowledge_graph_search_template_preserves_plain_permalink_rest_query(): void {
		$GLOBALS['cybermaps_mock_rest_url_callback'] = static fn( string $path ): string =>
			'https://example.com/?rest_route=%2F' . rawurlencode( $path );

		try {
			$data = json_decode( ( new KnowledgeGraph() )->get_json_content(), true );
		} finally {
			unset( $GLOBALS['cybermaps_mock_rest_url_callback'] );
		}

		$website = end( $data['@graph'] );
		$this->assertSame(
			'https://example.com/?rest_route=%2Fcybermaps%2Fv1%2Fsearch&q={search_term_string}',
			$website['potentialAction']['target']['urlTemplate']
		);
	}

	public function test_knowledge_graph_defaults_do_not_expose_admin_or_add_publisher(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_identity_data'] = array(
			'type'         => 'Person',
			'precise_type' => 'Restaurant',
			'name'         => 'Alex Example',
			'description'  => 'Independent publisher',
		);

		$data  = json_decode( ( new KnowledgeGraph() )->get_json_content(), true );
		$graph = $data['@graph'];

		$this->assertSame( 'Person', $graph[0]['@type'] );
		$this->assertSame( 'https://example.com/#person', $graph[0]['@id'] );
		$this->assertArrayNotHasKey( 'publisher', $graph[1] );
		$this->assertCount( 2, $graph );
	}

	public function test_knowledge_graph_exposes_only_a_resolvable_named_admin_person(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['ai_kg_expose_admin'] = '1';
		$GLOBALS['cybermaps_mock_options']['admin_email'] = array( 'admin@example.com' );

		$data = json_decode( ( new KnowledgeGraph() )->get_json_content(), true );
		$this->assertCount( 1, $data['@graph'] );
		$this->assertSame( 'WebSite', $data['@graph'][0]['@type'] );

		$GLOBALS['cybermaps_mock_options']['admin_email'] = 'admin@example.com';
		$GLOBALS['cybermaps_mock_users_by_email']['admin@example.com'] = (object) array(
			'ID'           => 12,
			'display_name' => 'Site Editor',
		);

		$data   = json_decode( ( new KnowledgeGraph() )->get_json_content(), true );
		$author = $data['@graph'][1];
		$this->assertSame( 'Person', $author['@type'] );
		$this->assertSame( 'Site Editor', $author['name'] );
		$this->assertSame( 'https://example.com/author/12/', $author['url'] );
	}

	public function test_knowledge_graph_does_not_invent_an_entity_for_a_malformed_identity_option(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_identity_data'] = 'not-an-array';
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['ai_kg_link_org'] = '1';

		$data = json_decode( ( new KnowledgeGraph() )->get_json_content(), true );

		$this->assertCount( 1, $data['@graph'] );
		$this->assertSame( 'WebSite', $data['@graph'][0]['@type'] );
		$this->assertSame( 'https://example.com/#website', $data['@graph'][0]['@id'] );
		$this->assertArrayNotHasKey( 'publisher', $data['@graph'][0] );
	}

	public function test_knowledge_graph_publisher_reuses_the_resolved_entity_id(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['ai_kg_link_org'] = '1';
		$GLOBALS['cybermaps_mock_options']['cybermaps_identity_data'] = array(
			'type'         => 'LocalBusiness',
			'precise_type' => 'Restaurant',
			'name'         => 'Example Cafe',
			'description'  => 'Neighborhood cafe',
		);

		$data  = json_decode( ( new KnowledgeGraph() )->get_json_content(), true );
		$graph = $data['@graph'];

		$this->assertSame( 'Restaurant', $graph[0]['@type'] );
		$this->assertSame( 'https://example.com/#organization', $graph[0]['@id'] );
		$this->assertSame( $graph[0]['@id'], $graph[1]['publisher']['@id'] );
	}

	public function test_knowledge_graph_normalizes_legacy_intents_and_skips_stale_object_names(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_discovery_center'] = wp_json_encode(
			array(
				'archetype' => 'blog',
				'type_intents' => array(
					'product' => 'commercial',
					'ghost'   => 'transactional',
				),
			)
		);
		$GLOBALS['cybermaps_mock_post_types'] = array( 'product', 'ghost' );
		$GLOBALS['cybermaps_mock_post_type_objects']['product'] = (object) array(
			'label' => 'Products',
		);

		$data  = json_decode( ( new KnowledgeGraph() )->get_json_content(), true );
		$silos = array_values(
			array_filter(
				$data['@graph'],
				static fn( array $node ): bool => 'ItemList' === ( $node['@type'] ?? '' )
			)
		);

		$this->assertCount( 1, $silos );
		$this->assertSame( 'Transactional Content Inventory', $silos[0]['name'] );
		$this->assertSame( 'Products', $silos[0]['itemListElement'][0]['name'] );
		$this->assertCount( 1, $silos[0]['itemListElement'] );
	}

	public function test_knowledge_graph_omits_content_groups_disabled_by_the_strategy(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_discovery_center'] = wp_json_encode(
			array(
				'archetype' => 'blog',
				'disabled'  => array( 'post_type:post' => true ),
			)
		);
		$GLOBALS['cybermaps_mock_post_types'] = array( 'post', 'page' );
		$GLOBALS['cybermaps_mock_post_type_objects'] = array(
			'post' => (object) array( 'label' => 'Posts' ),
			'page' => (object) array( 'label' => 'Pages' ),
		);

		$data  = json_decode( ( new KnowledgeGraph() )->get_json_content(), true );
		$names = array();
		foreach ( $data['@graph'] as $node ) {
			foreach ( (array) ( $node['itemListElement'] ?? array() ) as $item ) {
				$names[] = $item['name'];
			}
		}

		$this->assertNotContains( 'Posts', $names );
		$this->assertContains( 'Pages', $names );
	}

	public function test_knowledge_graph_uses_real_post_type_archives_without_inventing_taxonomy_urls(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_discovery_center'] = wp_json_encode(
			array(
				'archetype' => 'blog',
				'type_intents' => array(
					'product' => 'transactional',
				),
			)
		);
		$GLOBALS['cybermaps_mock_post_types'] = array( 'product', 'page' );
		$GLOBALS['cybermaps_mock_post_type_objects'] = array(
			'product' => (object) array( 'label' => 'Products' ),
			'page'    => (object) array( 'label' => 'Pages' ),
		);
		$GLOBALS['cybermaps_mock_post_type_archive_links'] = array(
			'product' => 'https://example.com/shop/',
			'page'    => false,
		);
		$GLOBALS['cybermaps_mock_taxonomies'] = array( 'category' );
		$GLOBALS['cybermaps_mock_taxonomy_objects']['category'] = (object) array(
			'label' => 'Categories',
		);

		$data  = json_decode( ( new KnowledgeGraph() )->get_json_content(), true );
		$items = array();
		foreach ( $data['@graph'] as $node ) {
			foreach ( (array) ( $node['itemListElement'] ?? array() ) as $item ) {
				$items[ $item['name'] ] = $item;
			}
		}

		$this->assertSame( 'https://example.com/shop/', $items['Products']['url'] );
		$this->assertArrayNotHasKey( 'url', $items['Pages'] );
		$this->assertArrayNotHasKey( 'url', $items['Categories'] );
		$this->assertSame( 1, $items['Products']['position'] );
		$this->assertSame( 1, $items['Pages']['position'] );
		$this->assertSame( 2, $items['Categories']['position'] );
	}

	public function test_knowledge_graph_omits_malformed_archive_urls(): void {
		$GLOBALS['cybermaps_mock_post_types'] = array( 'product' );
		$GLOBALS['cybermaps_mock_post_type_objects']['product'] = (object) array(
			'label' => 'Products',
		);
		$GLOBALS['cybermaps_mock_post_type_archive_links']['product'] = 'javascript:alert(1)';

		$data = json_decode( ( new KnowledgeGraph() )->get_json_content(), true );
		$item = $data['@graph'][0]['itemListElement'][0];

		$this->assertSame( 'Products', $item['name'] );
		$this->assertArrayNotHasKey( 'url', $item );
	}

	public function test_knowledge_graph_omits_non_queryable_public_registrations(): void {
		$GLOBALS['cybermaps_mock_post_types'] = array( 'public_internal' );
		$GLOBALS['cybermaps_mock_post_type_objects']['public_internal'] = (object) array(
			'label'              => 'Internal Records',
			'publicly_queryable' => false,
		);
		$GLOBALS['cybermaps_mock_taxonomies'] = array( 'internal_group' );
		$GLOBALS['cybermaps_mock_taxonomy_objects']['internal_group'] = (object) array(
			'label'              => 'Internal Groups',
			'publicly_queryable' => false,
		);

		$data = json_decode( ( new KnowledgeGraph() )->get_json_content(), true );

		$this->assertCount( 1, $data['@graph'] );
		$this->assertSame( 'WebSite', $data['@graph'][0]['@type'] );
	}

	public function test_knowledge_graph_ignores_malformed_nested_identity_values_without_warnings(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_identity_data'] = array(
			'type'            => 'LocalBusiness',
			'name'            => 'Legacy Cafe',
			'description'     => array( 'not scalar' ),
			'image_id'        => array( 7 ),
			'address'         => array( 'not scalar' ),
			'city'            => 'Oakland',
			'address_country' => array( 'US' ),
			'phone'           => array( '+1 555 0100' ),
			'email'           => array( 'hello@example.com' ),
			'latitude'        => array( '37.8044' ),
			'longitude'       => '-122.2711',
			'social_profiles' => array(
				array( 'https://bad.example' ),
				'/relative-profile',
				'javascript:alert(1)',
				'https://social.example/legacy',
				'https://social.example/legacy',
			),
			'contact_points' => array(
				array(
					'type'  => array( 'Sales' ),
					'phone' => '+1 555 0101',
				),
				array(
					'type'  => 'Sales',
					'phone' => array( '+1 555 0102' ),
					'email' => 'sales@example.com',
				),
			),
			'hours' => array(
				'monday' => array(
					array( 'open' => array( '09:00' ), 'close' => '17:00' ),
					array( 'open' => '09:00', 'close' => '17:00' ),
				),
			),
			'catalogs' => array(
				array(
					'mode'  => array( 'manual' ),
					'items' => array( 'Ignored' ),
				),
				array(
					'mode'  => 'manual',
					'name'  => array( 'Services' ),
					'items' => array( array( 'Ignored' ), 'Consulting' ),
				),
			),
		);

		set_error_handler(
			static function ( int $severity, string $message ): never {
				throw new \ErrorException( $message, 0, $severity );
			}
		);
		try {
			$data = json_decode( ( new KnowledgeGraph() )->get_json_content(), true );
		} finally {
			restore_error_handler();
		}

		$entity = $data['@graph'][0];
		$this->assertSame( 'LocalBusiness', $entity['@type'] );
		$this->assertSame( 'Legacy Cafe', $entity['name'] );
		$this->assertArrayNotHasKey( 'description', $entity );
		$this->assertArrayNotHasKey( 'image', $entity );
		$this->assertSame(
			array(
				'@type'           => 'PostalAddress',
				'addressLocality' => 'Oakland',
			),
			$entity['address']
		);
		$this->assertArrayNotHasKey( 'telephone', $entity );
		$this->assertArrayNotHasKey( 'email', $entity );
		$this->assertArrayNotHasKey( 'geo', $entity );
		$this->assertSame( array( 'https://social.example/legacy' ), $entity['sameAs'] );
		$this->assertSame( 'sales@example.com', $entity['contactPoint'][0]['email'] );
		$this->assertArrayNotHasKey( 'telephone', $entity['contactPoint'][0] );
		$this->assertSame( array( 'Mo 09:00-17:00' ), $entity['openingHours'] );
		$this->assertSame( 'Services', $entity['hasOfferCatalog'][0]['name'] );
		$this->assertSame(
			'Consulting',
			$entity['hasOfferCatalog'][0]['itemListElement'][0]['itemOffered']['name']
		);
	}

	public function test_person_identity_does_not_claim_business_logo_geo_or_hours(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_identity_data'] = array(
			'type'      => 'Person',
			'name'      => 'Alex Example',
			'image_id'  => 7,
			'latitude'  => '37.8044',
			'longitude' => '-122.2711',
			'hours'     => array(
				'monday' => array(
					array( 'open' => '09:00', 'close' => '17:00' ),
				),
			),
		);

		$entity = json_decode( ( new KnowledgeGraph() )->get_json_content(), true )['@graph'][0];

		$this->assertSame( 'https://example.com/wp-content/uploads/attachment-7.jpg', $entity['image'] );
		$this->assertArrayNotHasKey( 'logo', $entity );
		$this->assertArrayNotHasKey( 'geo', $entity );
		$this->assertArrayNotHasKey( 'openingHours', $entity );
	}

	public function test_identity_image_uses_headless_frontend_but_not_sitemap_media_cdn(): void {
		$had_home_url = array_key_exists( 'cybermaps_mock_home_url', $GLOBALS );
		$old_home_url = $GLOBALS['cybermaps_mock_home_url'] ?? null;

		try {
			$GLOBALS['cybermaps_mock_home_url']          = 'https://backend.example/blog';
			$GLOBALS['cybermaps_mock_attachment_urls'][7] = 'https://backend.example/blog/uploads/identity.jpg';
			$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
				'enable_discovery_hub' => '1',
				'frontend_base_url'    => 'https://frontend.example/app',
				'cdn_enabled'          => '1',
				'cdn_base_url'         => 'https://cdn.example/media',
			);
			$GLOBALS['cybermaps_mock_options']['cybermaps_identity_data'] = array(
				'type'     => 'Organization',
				'name'     => 'Example Organization',
				'image_id' => 7,
			);

			$entity = json_decode( ( new KnowledgeGraph() )->get_json_content(), true )['@graph'][0];

			$this->assertSame(
				'https://frontend.example/app/uploads/identity.jpg',
				$entity['image']
			);
			$this->assertSame( $entity['image'], $entity['logo'] );
		} finally {
			if ( $had_home_url ) {
				$GLOBALS['cybermaps_mock_home_url'] = $old_home_url;
			} else {
				unset( $GLOBALS['cybermaps_mock_home_url'] );
			}
		}
	}

	public function test_identity_omits_non_image_attachment_from_image_and_logo_claims(): void {
		$GLOBALS['cybermaps_mock_attachment_images'][9] = false;
		$GLOBALS['cybermaps_mock_options']['cybermaps_identity_data'] = array(
			'type'     => 'Organization',
			'name'     => 'Example Organization',
			'image_id' => 9,
		);

		$entity = json_decode( ( new KnowledgeGraph() )->get_json_content(), true )['@graph'][0];

		$this->assertArrayNotHasKey( 'image', $entity );
		$this->assertArrayNotHasKey( 'logo', $entity );
	}

	public function test_automated_catalog_emits_only_published_public_child_pages_with_real_urls(): void {
		$parent = (object) array(
			'ID'            => 100,
			'post_type'     => 'page',
			'post_status'   => 'publish',
			'post_password' => '',
			'post_title'    => 'Services',
		);
		$valid_child = (object) array(
			'ID'            => 101,
			'post_parent'   => 100,
			'post_type'     => 'page',
			'post_status'   => 'publish',
			'post_password' => '',
			'post_title'    => 'Consulting',
		);
		$draft_child = (object) array(
			'ID'            => 102,
			'post_parent'   => 100,
			'post_type'     => 'page',
			'post_status'   => 'draft',
			'post_password' => '',
			'post_title'    => 'Draft service',
		);
		$password_child = (object) array(
			'ID'            => 103,
			'post_parent'   => 100,
			'post_type'     => 'page',
			'post_status'   => 'publish',
			'post_password' => 'secret',
			'post_title'    => 'Protected service',
		);
		$invalid_url_child = (object) array(
			'ID'            => 104,
			'post_parent'   => 100,
			'post_type'     => 'page',
			'post_status'   => 'publish',
			'post_password' => '',
			'post_title'    => 'Broken service',
		);
		$GLOBALS['cybermaps_mock_posts'][100] = $parent;
		$GLOBALS['cybermaps_mock_pages'] = array(
			$valid_child,
			$draft_child,
			$password_child,
			$invalid_url_child,
		);
		$GLOBALS['cybermaps_mock_permalinks'][104] = false;
		$GLOBALS['cybermaps_mock_options']['cybermaps_identity_data'] = array(
			'type' => 'Organization',
			'name' => 'Example Organization',
			'catalogs' => array(
				array(
					'mode'      => 'auto',
					'item_type' => 'Product',
					'name'      => '',
					'parent_id' => 100,
				),
				array(
					'mode'      => 'auto',
					'name'      => 'Missing parent',
					'parent_id' => 999,
				),
			),
		);

		$entity   = json_decode( ( new KnowledgeGraph() )->get_json_content(), true )['@graph'][0];
		$catalogs = $entity['hasOfferCatalog'];

		$this->assertCount( 1, $catalogs );
		$this->assertSame( 'Services', $catalogs[0]['name'] );
		$this->assertCount( 1, $catalogs[0]['itemListElement'] );
		$this->assertSame(
			'https://example.com/?p=101',
			$catalogs[0]['itemListElement'][0]['itemOffered']['url']
		);
		$this->assertSame(
			'Product',
			$catalogs[0]['itemListElement'][0]['itemOffered']['@type']
		);
		$this->assertSame( 'publish', $GLOBALS['cybermaps_mock_get_pages_args'][0]['post_status'] );
		$this->assertSame( 'page', $GLOBALS['cybermaps_mock_get_pages_args'][0]['post_type'] );
		$this->assertSame(
			IdentityEntityBuilder::MAX_OFFERS_PER_CATALOG,
			$GLOBALS['cybermaps_mock_get_pages_args'][0]['number']
		);
	}

	public function test_identity_collections_are_bounded_before_publication(): void {
		$social_profiles = array();
		for ( $index = 0; $index < IdentityEntityBuilder::MAX_SOCIAL_PROFILES + 5; $index++ ) {
			$social_profiles[] = 'https://social.example/profile-' . $index;
		}

		$contact_points = array();
		for ( $index = 0; $index < IdentityEntityBuilder::MAX_CONTACT_POINTS + 5; $index++ ) {
			$contact_points[] = array(
				'type'  => 'Sales',
				'email' => 'sales-' . $index . '@example.com',
			);
		}

		$hour_slots = array();
		for ( $index = 0; $index < IdentityEntityBuilder::MAX_HOURS_SLOTS_PER_DAY + 5; $index++ ) {
			$hour_slots[] = array(
				'open'  => '09:00',
				'close' => '17:00',
			);
		}

		$entity = IdentityEntityBuilder::build(
			array(
				'type'            => 'LocalBusiness',
				'name'            => 'Bounded Business',
				'social_profiles' => $social_profiles,
				'contact_points'  => $contact_points,
				'hours'           => array(
					'monday'       => $hour_slots,
					'not-a-weekday' => array_fill(
						0,
						IdentityEntityBuilder::MAX_HOURS_SLOTS_PER_DAY + 5,
						array(
							'open'  => '09:00',
							'close' => '17:00',
						)
					),
				),
			)
		);

		$this->assertCount( IdentityEntityBuilder::MAX_SOCIAL_PROFILES, $entity['sameAs'] );
		$this->assertCount( IdentityEntityBuilder::MAX_CONTACT_POINTS, $entity['contactPoint'] );
		$this->assertCount( IdentityEntityBuilder::MAX_HOURS_SLOTS_PER_DAY, $entity['openingHours'] );
	}

	public function test_identity_catalogs_bound_queries_and_total_offer_output(): void {
		$catalogs = array();
		$pages    = array();
		$expected_query_count = (int) ceil(
			IdentityEntityBuilder::MAX_OFFERS_TOTAL / IdentityEntityBuilder::MAX_OFFERS_PER_CATALOG
		);

		for ( $catalog_index = 1; $catalog_index <= $expected_query_count + 1; $catalog_index++ ) {
			$parent_id = 1000 + $catalog_index;
			$GLOBALS['cybermaps_mock_posts'][ $parent_id ] = (object) array(
				'ID'            => $parent_id,
				'post_type'     => 'page',
				'post_status'   => 'publish',
				'post_password' => '',
				'post_title'    => 'Catalog ' . $catalog_index,
			);
			$catalogs[] = array(
				'mode'      => 'auto',
				'item_type' => 'Service',
				'parent_id' => $parent_id,
			);
			for ( $item_index = 1; $item_index <= IdentityEntityBuilder::MAX_OFFERS_PER_CATALOG + 5; $item_index++ ) {
				$child_id = ( $parent_id * 100 ) + $item_index;
				$pages[] = (object) array(
					'ID'            => $child_id,
					'post_parent'   => $parent_id,
					'post_type'     => 'page',
					'post_status'   => 'publish',
					'post_password' => '',
					'post_title'    => 'Offer ' . $catalog_index . '-' . $item_index,
				);
			}
		}
		$GLOBALS['cybermaps_mock_pages'] = $pages;

		$entity = IdentityEntityBuilder::build(
			array(
				'type'     => 'Organization',
				'name'     => 'Bounded Organization',
				'catalogs' => $catalogs,
			)
		);

		$this->assertCount( $expected_query_count, $GLOBALS['cybermaps_mock_get_pages_args'] );
		$this->assertCount( $expected_query_count, $entity['hasOfferCatalog'] );
		$this->assertSame(
			IdentityEntityBuilder::MAX_OFFERS_TOTAL,
			array_sum(
				array_map(
					static fn( array $catalog ): int => count( $catalog['itemListElement'] ),
					$entity['hasOfferCatalog']
				)
			)
		);
		foreach ( $GLOBALS['cybermaps_mock_get_pages_args'] as $args ) {
			$this->assertLessThanOrEqual( IdentityEntityBuilder::MAX_OFFERS_PER_CATALOG, $args['number'] );
		}
	}

	public function test_identity_catalog_count_and_product_fallback_are_bounded(): void {
		$catalogs = array();
		for ( $index = 0; $index < IdentityEntityBuilder::MAX_CATALOGS + 1; $index++ ) {
			$catalogs[] = array(
				'mode'      => 'manual',
				'item_type' => 'Product',
				'name'      => '',
				'items'     => array( 'Product ' . $index ),
			);
		}

		$entity = IdentityEntityBuilder::build(
			array(
				'type'     => 'Organization',
				'name'     => 'Product Organization',
				'catalogs' => $catalogs,
			)
		);

		$this->assertCount( IdentityEntityBuilder::MAX_CATALOGS, $entity['hasOfferCatalog'] );
		$this->assertSame( 'Products', $entity['hasOfferCatalog'][0]['name'] );
		$this->assertSame(
			'Product ' . ( IdentityEntityBuilder::MAX_CATALOGS - 1 ),
			$entity['hasOfferCatalog'][ IdentityEntityBuilder::MAX_CATALOGS - 1 ]['itemListElement'][0]['itemOffered']['name']
		);
	}

	public function test_on_page_and_knowledge_graph_identity_entities_stay_in_sync(): void {
		$GLOBALS['cybermaps_mock_is_front_page'] = true;
		$GLOBALS['cybermaps_mock_options']['cybermaps_identity_data'] = array(
			'type'            => 'LocalBusiness',
			'precise_type'    => 'Restaurant',
			'name'            => 'Example Cafe',
			'description'     => 'Neighborhood cafe',
			'city'            => 'Oakland',
			'address_country' => 'US',
			'latitude'        => '0',
			'longitude'       => '-122.2711',
			'hours'           => array(
				'monday' => array(
					array( 'open' => '09:00', 'close' => '17:00' ),
				),
			),
			'catalogs' => array(
				array(
					'mode'      => 'manual',
					'name'      => 'Services',
					'items'     => array( 'Consulting' ),
					'parent_id' => 0,
				),
			),
		);

		ob_start();
		( new Schema() )->inject_identity_schema();
		ob_end_clean();

		$on_page   = json_decode( $GLOBALS['cybermaps_mock_inline_scripts'][0]['data'], true );
		$knowledge = json_decode( ( new KnowledgeGraph() )->get_json_content(), true )['@graph'][0];
		unset( $on_page['@context'] );

		$this->assertSame( $knowledge, $on_page );
		$this->assertSame( '0', $on_page['geo']['latitude'] );
		$this->assertSame( 'Oakland', $on_page['address']['addressLocality'] );
	}

	private function configureSingularPost(): void {
		$GLOBALS['cybermaps_mock_is_singular']     = true;
		$GLOBALS['cybermaps_mock_current_post_id'] = 42;
		$GLOBALS['cybermaps_mock_the_date']        = '2026-07-29T12:00:00+00:00';
		$GLOBALS['cybermaps_mock_posts'][42] = (object) array(
			'ID'            => 42,
			'post_type'     => 'post',
			'post_status'   => 'publish',
			'post_password' => '',
			'post_title'    => 'Fallback post title',
		);
	}
}
