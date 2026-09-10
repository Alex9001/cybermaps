<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Sitemap;

use Cybermaps\Sitemap\Orchestrator;
use Cybermaps\Sitemap\Renderer\NetworkRenderer;
use PHPUnit\Framework\TestCase;

final class NetworkRendererTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['cybermaps_mock_current_blog_id']         = 1;
		$GLOBALS['cybermaps_mock_current_network_id']      = 4;
		$GLOBALS['cybermaps_mock_get_sites_args']          = array();
		$GLOBALS['cybermaps_mock_is_main_site']            = true;
		$GLOBALS['cybermaps_mock_site_ids']                = array( 1 );
		$GLOBALS['cybermaps_mock_site_ids_by_network']     = array(
			1 => array( 1 ),
			4 => array( 41, 42 ),
		);
		$GLOBALS['cybermaps_mock_site_options']            = array(
			'cybermaps_network_settings' => array(
				'enable_master_index' => '1',
			),
			'active_sitewide_plugins' => array(
				'cybermaps/cybermaps.php' => 1,
			),
		);
		$GLOBALS['cybermaps_mock_options_by_blog']         = array();
		$GLOBALS['cybermaps_mock_switched_blogs']          = array();
	}

	protected function tearDown(): void {
		$GLOBALS['cybermaps_mock_current_network_id'] = 1;

		parent::tearDown();
	}

	public function test_master_index_queries_only_sites_in_the_current_network(): void {
		$writer = new \XMLWriter();
		$writer->openMemory();
		$writer->startDocument( '1.0', 'UTF-8' );

		( new NetworkRenderer( new Orchestrator() ) )->render( $writer );
		$writer->endDocument();
		$xml = $writer->outputMemory();

		$this->assertSame(
			array(
				'network_id' => 4,
				'fields'     => 'ids',
				'number'     => 0,
				'public'     => 1,
				'archived'   => 0,
				'spam'       => 0,
				'deleted'    => 0,
			),
			$GLOBALS['cybermaps_mock_get_sites_args'][0]
		);
		$this->assertSame( array( 41, 42 ), $GLOBALS['cybermaps_mock_switched_blogs'] );
		$this->assertSame( 1, $GLOBALS['cybermaps_mock_current_blog_id'] );
		$this->assertSame( 2, substr_count( $xml, '<sitemap>' ) );
		$this->assertStringNotContainsString( '<lastmod>', $xml );
	}

	public function test_master_index_omits_sites_where_plugin_is_inactive(): void {
		$GLOBALS['cybermaps_mock_site_options']['active_sitewide_plugins'] = array();
		$GLOBALS['cybermaps_mock_options_by_blog'] = array(
			41 => array( 'active_plugins' => array( 'cybermaps/cybermaps.php' ) ),
			42 => array( 'active_plugins' => array() ),
		);

		$writer = new \XMLWriter();
		$writer->openMemory();
		$writer->startDocument( '1.0', 'UTF-8' );

		( new NetworkRenderer( new Orchestrator() ) )->render( $writer );
		$writer->endDocument();
		$xml = $writer->outputMemory();

		$this->assertSame( 1, substr_count( $xml, '<sitemap>' ) );
	}
}
