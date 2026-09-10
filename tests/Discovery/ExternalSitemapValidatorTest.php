<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Sitemap\ExternalSitemapValidator;
use PHPUnit\Framework\TestCase;

class ExternalSitemapValidatorTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['cybermaps_mock_http_url_validation'] = array();
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['cybermaps_mock_http_url_validation'],
			$GLOBALS['cybermaps_mock_home_url']
		);

		parent::tearDown();
	}

	public function test_unsafe_url_is_rejected(): void {
		$url = 'http://127.0.0.1/private.xml';
		$GLOBALS['cybermaps_mock_http_url_validation'][ $url ] = false;

		$this->assertSame( '', ExternalSitemapValidator::filter_textarea( $url ) );
	}

	public function test_non_xml_url_is_rejected(): void {
		$this->assertSame( '', ExternalSitemapValidator::filter_textarea( 'https://example.net/sitemap.json' ) );
	}

	public function test_textarea_deduplicates_and_caps_structurally_valid_urls_in_publication_scope(): void {
		$urls = array();
		for ( $index = 1; $index <= 105; ++$index ) {
			$urls[] = 'https://example.com/sitemap-' . $index . '.xml';
		}
		$urls[] = $urls[0];
		$urls[] = 'https://example.net/not-xml.txt';

		$filtered = ExternalSitemapValidator::filter_textarea( implode( "\n", $urls ) );
		$this->assertCount( 100, explode( "\n", $filtered ) );
		$this->assertStringContainsString( 'sitemap-1.xml', $filtered );
		$this->assertStringNotContainsString( 'not-xml.txt', $filtered );
	}

	public function test_page_textarea_accepts_absolute_http_urls_without_fabricating_metadata(): void {
		$filtered = ExternalSitemapValidator::filter_page_textarea(
			"https://example.com/landing?a=1\njavascript:alert(1)\nhttps://user:secret@example.com/private\nhttps://example.com/landing?a=1"
		);

		$this->assertSame( 'https://example.com/landing?a=1', $filtered );
	}

	public function test_cross_origin_and_out_of_home_path_urls_are_rejected(): void {
		$GLOBALS['cybermaps_mock_home_url'] = 'https://example.com/site';

		$this->assertSame( '', ExternalSitemapValidator::filter_textarea( 'https://elsewhere.example/sitemap.xml' ) );
		$this->assertSame( '', ExternalSitemapValidator::filter_page_textarea( 'https://example.com/outside' ) );
		$this->assertSame( 'https://example.com/site/inside', ExternalSitemapValidator::filter_page_textarea( 'https://example.com/site/inside' ) );
	}
}
