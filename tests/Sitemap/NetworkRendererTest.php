<?php
declare(strict_types=1);

namespace Cybermaps\Sitemap\Renderer {
	/** Faithful bounded pagination seam; shared mock intentionally remains intact. */
	function get_sites( array $args ): array {
		if ( isset( $GLOBALS['cybermaps_network_site_page_callback'] ) ) {
			return ( $GLOBALS['cybermaps_network_site_page_callback'] )( $args );
		}
		return array_slice( \get_sites( $args ), $args['offset'], $args['number'] );
	}
}

namespace Cybermaps\Tests\Sitemap {

use Cybermaps\Core\BuildUnavailableException;
use Cybermaps\Sitemap\Orchestrator;
use Cybermaps\Sitemap\PageOccupancyManifest;
use Cybermaps\Sitemap\Renderer\NetworkRenderer;
use PHPUnit\Framework\TestCase;

final class NetworkRendererTest extends TestCase {
	private mixed $previous_database;

	protected function setUp(): void {
		parent::setUp();
		$this->previous_database = $GLOBALS['wpdb'] ?? null;
		unset( $GLOBALS['wpdb'] );
		$GLOBALS['cybermaps_mock_current_blog_id'] = 1;
		$GLOBALS['cybermaps_mock_current_network_id'] = 4;
		$GLOBALS['cybermaps_mock_get_sites_args'] = array();
		$GLOBALS['cybermaps_mock_is_main_site'] = true;
		$GLOBALS['cybermaps_mock_site_ids_by_network'] = array( 4 => array( 41, 42 ) );
		$GLOBALS['cybermaps_mock_site_options'] = array(
			'cybermaps_network_settings' => array( 'enable_master_index' => '1' ),
			'active_sitewide_plugins' => array( 'cybermaps/cybermaps.php' => 1 ),
		);
		$GLOBALS['cybermaps_mock_options'] = array();
		$GLOBALS['cybermaps_mock_options_by_blog'] = array();
		$GLOBALS['cybermaps_mock_switched_blogs'] = array();
		$GLOBALS['cybermaps_mock_post_types'] = array( 'post' );
		$GLOBALS['cybermaps_mock_post_type_objects'] = array( 'post' => (object) array( 'name' => 'post', 'public' => true ) );
		$GLOBALS['cybermaps_mock_taxonomies'] = array();
		$GLOBALS['cybermaps_mock_filter_callbacks'] = array();
		$GLOBALS['cybermaps_mock_home_url'] = 'https://example.com';
		foreach ( array( 41, 42 ) as $id ) {
			$GLOBALS['cybermaps_mock_options_by_blog'][ $id ] = array(
				PageOccupancyManifest::GENERATION_OPTION => 0,
				PageOccupancyManifest::TOKEN_OPTION => 'member-token',
				PageOccupancyManifest::MANIFEST_OPTION => array(
					'complete' => true, 'generation' => 0, 'token' => 'member-token',
					'providers' => array( 'post_type:post' => array( 'raw_count' => 1, 'raw_page_count' => 1, 'non_empty_pages' => array( 1 ), 'page_lastmod' => array( 1 => '' ) ) ),
				),
			);
		}
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->previous_database;
		$GLOBALS['cybermaps_mock_current_network_id'] = 1;
		unset( $GLOBALS['cybermaps_network_site_page_callback'], $GLOBALS['cybermaps_mock_home_url'] );
		$GLOBALS['cybermaps_mock_filter_callbacks'] = array();
		parent::tearDown();
	}

	private function render( ?NetworkRenderer $renderer = null ): string {
		$writer = new \Cybermaps\Sitemap\XmlWriter();
		$writer->startDocument();
		( $renderer ?? new NetworkRenderer( new Orchestrator() ) )->render( $writer );
		$writer->endDocument();
		return $writer->outputMemory();
	}

	public function test_master_index_lists_member_leaf_sitemaps_with_bounded_queries(): void {
		$xml = $this->render();
		$this->assertSame( 4, $GLOBALS['cybermaps_mock_get_sites_args'][0]['network_id'] );
		$this->assertSame( 100, $GLOBALS['cybermaps_mock_get_sites_args'][0]['number'] );
		$this->assertSame( array( 41, 42 ), $GLOBALS['cybermaps_mock_switched_blogs'] );
		$this->assertSame( 1, $GLOBALS['cybermaps_mock_current_blog_id'] );
		$this->assertSame( 2, substr_count( $xml, '<sitemap>' ) );
		$this->assertStringContainsString( '/sitemap-posts-post-1.xml</loc>', $xml );
		$this->assertStringNotContainsString( '/sitemap_index.xml</loc>', $xml );
	}

	public function test_inactive_member_is_omitted(): void {
		$GLOBALS['cybermaps_mock_site_options']['active_sitewide_plugins'] = array();
		$GLOBALS['cybermaps_mock_options_by_blog'][41]['active_plugins'] = array( 'cybermaps/cybermaps.php' );
		$GLOBALS['cybermaps_mock_options_by_blog'][42]['active_plugins'] = array();
		$this->assertSame( 1, substr_count( $this->render(), '<sitemap>' ) );
	}

	public function test_missing_manifest_is_unavailable_and_restores_blog_context(): void {
		unset( $GLOBALS['cybermaps_mock_options_by_blog'][41][ PageOccupancyManifest::MANIFEST_OPTION ] );
		try {
			$this->render();
			$this->fail( 'Pending occupancy cannot be published as a complete network.' );
		} catch ( BuildUnavailableException ) {
			$this->assertSame( 1, $GLOBALS['cybermaps_mock_current_blog_id'] );
		}
	}

	public function test_paged_network_covers_every_member_without_nested_indexes(): void {
		$queries = array();
		$GLOBALS['cybermaps_network_site_page_callback'] = static function ( array $args ) use ( &$queries ): array {
			$queries[] = $args;
			return array_slice( range( 1, 201 ), $args['offset'], $args['number'] );
		};
		$renderer = new class( new Orchestrator() ) extends NetworkRenderer {
			protected function site_locations( int $blog_id ): array {
				return array( 'https://example.com/member-' . $blog_id . '/sitemap-post-1.xml' );
			}
		};
		$xml = $this->render( $renderer );
		$this->assertSame( array( 0, 100, 200 ), array_column( $queries, 'offset' ) );
		$this->assertSame( 201, substr_count( $xml, '<sitemap>' ) );
		$this->assertStringContainsString( '/member-201/sitemap-post-1.xml', $xml );
	}

	public function test_protocol_overflow_and_cross_origin_fail_without_partial_success(): void {
		foreach ( array( array_fill( 0, 50001, 'https://example.com/post.xml' ), array( 'https://other.example/post.xml' ) ) as $locations ) {
			$renderer = new class( new Orchestrator(), $locations ) extends NetworkRenderer {
				public function __construct( Orchestrator $orchestrator, private array $locations ) {
					parent::__construct( $orchestrator );
				}
				protected function site_locations( int $blog_id ): array {
					return $this->locations;
				}
			};
			try {
				$this->render( $renderer );
				$this->fail( 'An unrepresentable network must not publish a prefix.' );
			} catch ( BuildUnavailableException ) {
				$this->assertTrue( true );
			}
		}
	}
	public function test_database_failure_does_not_publish_an_empty_network(): void {
		$GLOBALS['cybermaps_network_site_page_callback'] = static function ( array $args ): array {
			$GLOBALS['wpdb'] = (object) array( 'last_error' => 'Database unavailable' );
			return array();
		};
		$this->expectException( BuildUnavailableException::class );
		$this->render();
	}

	public function test_expired_deadline_refuses_the_entire_result(): void {
		$renderer = new NetworkRenderer( new Orchestrator() );
		$this->expectException( BuildUnavailableException::class );
		( new \ReflectionMethod( NetworkRenderer::class, 'collect_locations' ) )->invoke( $renderer, microtime( true ) - 1 );
	}

	public function test_unverified_external_leaf_shape_is_unavailable(): void {
		$GLOBALS['cybermaps_mock_options_by_blog'][41]['cybermaps_settings'] = array( 'external_sitemaps' => 'https://example.com/other.xml' );
		$this->expectException( BuildUnavailableException::class );
		$this->render();
	}

}
}
