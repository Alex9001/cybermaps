<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Discovery\HeaderDiscovery;
use PHPUnit\Framework\TestCase;

final class HeaderDiscoveryTest extends TestCase {

	private mixed $original_home_url;
	private array $original_options;

	protected function setUp(): void {
		parent::setUp();

		$this->original_home_url = $GLOBALS['cybermaps_mock_home_url'] ?? null;
		$this->original_options  = (array) ( $GLOBALS['cybermaps_mock_options'] ?? array() );

		$GLOBALS['cybermaps_mock_home_url'] = 'https://backend.example/blog';
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'enable_discovery_hub' => '1',
			'frontend_base_url'    => 'https://frontend.example/app',
			'llms_included_types'  => array( 'post' ),
			'sitemap_url_base'     => 'sitemap',
		);
		$GLOBALS['cybermaps_mock_post_types'] = array( 'post' );
		$GLOBALS['cybermaps_mock_post_type_objects']['post'] = (object) array(
			'name'   => 'post',
			'public' => true,
		);
		$GLOBALS['cybermaps_mock_posts'][14] = (object) array(
			'ID'            => 14,
			'post_title'    => 'Header resource',
			'post_content'  => 'Body',
			'post_type'     => 'post',
			'post_status'   => 'publish',
			'post_password' => '',
		);
		$GLOBALS['cybermaps_mock_permalinks'][14] = 'https://backend.example/blog/header-resource/';
	}

	protected function tearDown(): void {
		$GLOBALS['cybermaps_mock_options'] = $this->original_options;
		if ( null === $this->original_home_url ) {
			unset( $GLOBALS['cybermaps_mock_home_url'] );
		} else {
			$GLOBALS['cybermaps_mock_home_url'] = $this->original_home_url;
		}
		unset( $GLOBALS['cybermaps_mock_permalinks'][14], $GLOBALS['cybermaps_mock_posts'][14] );

		parent::tearDown();
	}

	public function test_links_use_registry_canonical_urls_and_media_types(): void {
		$links  = ( new HeaderDiscovery() )->get_discovery_links();
		$joined = implode( "\n", $links );

		$this->assertContains(
			'<https://frontend.example/app/feed.json>; rel="alternate"; type="application/feed+json"',
			$links
		);
		$this->assertContains(
			'<https://frontend.example/.well-known/api-catalog>; rel="api-catalog"; type="application/linkset+json"; profile="https://www.rfc-editor.org/info/rfc9727"',
			$links
		);
		$this->assertStringNotContainsString( 'rel="discovery"', $joined );
		$this->assertStringNotContainsString( 'rel="manifest"', $joined );
		$this->assertStringNotContainsString( 'rel="sitemap"', $joined );
	}

	public function test_eligible_pages_advertise_markdown_and_describing_llms_file(): void {
		$links = ( new HeaderDiscovery() )->get_content_links( 14 );

		$this->assertSame(
			array(
				'<https://frontend.example/app/header-resource/index.md>; rel="alternate"; type="text/markdown"',
				'<https://frontend.example/app/llms.txt>; rel="describedby"; type="text/markdown"',
			),
			$links
		);
	}

	public function test_response_link_headers_require_the_header_discovery_toggle(): void {
		$headers = new HeaderDiscovery();

		$this->assertSame( array(), $headers->get_response_links( 14 ) );

		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['enable_header_discovery'] = '1';
		$links = $headers->get_response_links( 14 );

		$this->assertContains(
			'<https://frontend.example/app/header-resource/index.md>; rel="alternate"; type="text/markdown"',
			$links
		);
		$this->assertContains(
			'<https://frontend.example/app/feed.json>; rel="alternate"; type="application/feed+json"',
			$links
		);
	}
}
