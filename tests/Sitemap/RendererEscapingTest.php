<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Sitemap;

use Cybermaps\Sitemap\Orchestrator;
use Cybermaps\Sitemap\ProviderInterface;
use Cybermaps\Sitemap\Renderer\SitemapRenderer;
use PHPUnit\Framework\TestCase;

final class RendererEscapingTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['cybermaps_mock_options'] = array(
			'blog_public'        => '1',
			'cybermaps_settings' => array(
				'include_homepage'          => '0',
				'include_authors'           => '0',
				'include_archives'          => '0',
				'media_discovery_intensity' => 'standard',
			),
		);
		$GLOBALS['cybermaps_mock_post_types']        = array();
		$GLOBALS['cybermaps_mock_taxonomies']        = array();
		$GLOBALS['cybermaps_mock_post_type_objects'] = array();
		$GLOBALS['cybermaps_mock_taxonomy_objects']  = array();
		$GLOBALS['cybermaps_mock_safe_remote_head_response'] = array(
			'response' => array( 'code' => 200 ),
		);
	}

	public function test_video_titles_are_escaped_once_and_deprecated_image_titles_are_omitted(): void {
		$title    = 'Research & Development <Guide>';
		$provider = new class( $title ) implements ProviderInterface {
			public function __construct( private readonly string $title ) {}

			public function get_urls( int $page ): array {
				unset( $page );
				return array(
					array(
						'loc'        => 'https://example.com/resource',
						'lastmod'    => '2026-07-29T00:00:00+00:00',
						'changefreq' => 'weekly',
						'priority'   => 0.5,
						'images'     => array(
							array(
								'url'   => 'https://example.com/image.jpg',
								'title' => $this->title,
							),
						),
						'videos'     => array(
							array(
								'thumbnail_loc' => 'https://example.com/thumb.jpg',
								'title'         => $this->title,
								'description'   => str_repeat( 'D', 3000 ),
								'url'           => 'https://example.com/video.mp4',
							),
						),
					),
				);
			}

			public function get_count(): int {
				return 1;
			}

			public function get_lastmod(): string {
				return '2026-07-29T00:00:00+00:00';
			}
		};

		$writer = new \XMLWriter();
		$writer->openMemory();
		$writer->startDocument( '1.0', 'UTF-8' );
		( new SitemapRenderer( new Orchestrator() ) )->render( $writer, $provider, 'post', 1 );
		$writer->endDocument();
		$xml = $writer->outputMemory();

		$document = new \DOMDocument();
		$this->assertTrue( $document->loadXML( $xml ) );
		$this->assertSame(
			0,
			$document->getElementsByTagNameNS(
				'http://www.google.com/schemas/sitemap-image/1.1',
				'title'
			)->length
		);
		$this->assertSame(
			$title,
			$document->getElementsByTagNameNS(
				'http://www.google.com/schemas/sitemap-video/1.1',
				'title'
			)->item( 0 )?->textContent
		);
		$this->assertSame(
			2048,
			strlen(
				(string) $document->getElementsByTagNameNS(
					'http://www.google.com/schemas/sitemap-video/1.1',
					'description'
				)->item( 0 )?->textContent
			)
		);
		$this->assertStringNotContainsString( '&amp;amp;', $xml );
		$this->assertStringNotContainsString( '&amp;lt;', $xml );
	}

	public function test_external_sitemap_url_is_escaped_once_by_xmlwriter(): void {
		$url = 'https://example.com/external.xml?part=1&lang=en';
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['external_sitemaps'] = $url;

		$xml      = ( new Orchestrator() )->generate_xml( 'index', 1 );
		$document = new \DOMDocument();

		$this->assertTrue( $document->loadXML( $xml ) );
		$this->assertSame( $url, $document->getElementsByTagName( 'loc' )->item( 0 )?->textContent );
		$this->assertStringContainsString( 'part=1&amp;lang=en', $xml );
		$this->assertStringNotContainsString( '&amp;#038;', $xml );
	}

	public function test_invalid_video_rows_are_omitted_instead_of_emitting_incomplete_protocol_entries(): void {
		$provider = new class() implements ProviderInterface {
			public function get_urls( int $page ): array {
				unset( $page );
				return array(
					array(
						'loc'    => 'https://example.com/resource',
						'videos' => array(
							array(
								'thumbnail_loc' => '',
								'title'         => 'Missing thumbnail',
								'description'   => 'Incomplete row',
								'url'           => 'https://example.com/video.mp4',
							),
							array(
								'thumbnail_loc' => 'https://example.com/thumb.jpg',
								'title'         => '',
								'description'   => '',
								'url'           => 'https://example.com/video.mp4',
							),
							array(
								'thumbnail_loc' => 'javascript:alert(1)',
								'title'         => 'Unsafe thumbnail',
								'description'   => 'Unsafe protocol',
								'url'           => 'https://example.com/video.mp4',
							),
						),
						'images' => array(
							array(
								'url' => 'javascript:alert(1)',
							),
						),
					),
				);
			}

			public function get_count(): int {
				return 1;
			}

			public function get_lastmod(): string {
				return '';
			}
		};

		$writer = new \XMLWriter();
		$writer->openMemory();
		$writer->startDocument( '1.0', 'UTF-8' );
		( new SitemapRenderer( new Orchestrator() ) )->render( $writer, $provider, 'post', 1 );
		$writer->endDocument();
		$xml = $writer->outputMemory();

		$this->assertStringNotContainsString( '<video:video>', $xml );
		$this->assertStringNotContainsString( '<image:image>', $xml );
		$this->assertStringNotContainsString( 'javascript:', $xml );
		$this->assertTrue( ( new \DOMDocument() )->loadXML( $xml ) );
	}

	public function test_hosted_players_are_not_mislabeled_as_video_content_locations(): void {
		$provider = new class() implements ProviderInterface {
			public function get_urls( int $page ): array {
				unset( $page );
				return array(
					array(
						'loc'    => 'https://example.com/resource',
						'videos' => array(
							array(
								'thumbnail_loc' => 'https://img.youtube.com/vi/dQw4w9WgXcQ/hqdefault.jpg',
								'title'         => 'Hosted walkthrough',
								'description'   => 'A hosted walkthrough.',
								'url'           => 'youtube.com/watch?v=dQw4w9WgXcQ',
							),
						),
					),
				);
			}

			public function get_count(): int {
				return 1;
			}

			public function get_lastmod(): string {
				return '';
			}
		};

		$writer = new \XMLWriter();
		$writer->openMemory();
		$writer->startDocument( '1.0', 'UTF-8' );
		( new SitemapRenderer( new Orchestrator() ) )->render( $writer, $provider, 'post', 1 );
		$writer->endDocument();
		$xml = $writer->outputMemory();

		$this->assertStringContainsString(
			'<video:player_loc>https://www.youtube.com/embed/dQw4w9WgXcQ</video:player_loc>',
			$xml
		);
		$this->assertStringNotContainsString( '<video:content_loc>', $xml );
		$this->assertTrue( ( new \DOMDocument() )->loadXML( $xml ) );
	}

	public function test_malformed_provider_rows_are_omitted_without_runtime_warnings(): void {
		$provider = new class() implements ProviderInterface {
			public function get_urls( int $page ): array {
				unset( $page );
				return array(
					'not-an-array',
					array( 'loc' => array( 'https://example.com/invalid' ) ),
					array(
						'loc'        => 'https://example.com/valid',
						'lastmod'    => array( 'bad' ),
						'changefreq' => new \stdClass(),
						'priority'   => array( 1 ),
						'alternates' => array( 'en' => array( 'bad' ) ),
						'news'       => array(
							'publication'      => array( 'name' => array( 'bad' ), 'language' => 'en' ),
							'publication_date' => array( 'bad' ),
							'title'            => new \stdClass(),
						),
						'images'     => array(
							'bad',
							array(
								'url'   => 'https://example.com/image.jpg',
								'title' => array( 'bad' ),
							),
						),
						'videos'     => array(
							'bad',
							array(
								'thumbnail_loc' => 'https://example.com/thumb.jpg',
								'content_loc'   => 'https://example.com/video.mp4',
								'title'         => array( 'bad' ),
								'description'   => new \stdClass(),
							),
						),
					),
				);
			}

			public function get_count(): int {
				return 3;
			}

			public function get_lastmod(): string {
				return '';
			}
		};

		set_error_handler(
			static function ( int $severity, string $message, string $file, int $line ): never {
				throw new \ErrorException( $message, 0, $severity, $file, $line );
			}
		);
		try {
			$writer = new \XMLWriter();
			$writer->openMemory();
			$writer->startDocument( '1.0', 'UTF-8' );
			( new SitemapRenderer( new Orchestrator() ) )->render( $writer, $provider, 'post', 1 );
			$writer->endDocument();
			$xml = $writer->outputMemory();
		} finally {
			restore_error_handler();
		}

		$document = new \DOMDocument();
		$this->assertTrue( $document->loadXML( $xml ) );
		$sitemap_locations = $document->getElementsByTagNameNS(
			'http://www.sitemaps.org/schemas/sitemap/0.9',
			'loc'
		);
		$this->assertSame( 1, $sitemap_locations->length );
		$this->assertSame( 'https://example.com/valid', $sitemap_locations->item( 0 )?->textContent );
		$this->assertSame( 1, $document->getElementsByTagNameNS( 'http://www.google.com/schemas/sitemap-image/1.1', 'image' )->length );
		$this->assertSame( 0, $document->getElementsByTagNameNS( 'http://www.google.com/schemas/sitemap-video/1.1', 'video' )->length );
		$this->assertSame( 0, $document->getElementsByTagNameNS( 'http://www.google.com/schemas/sitemap-news/0.9', 'news' )->length );
	}
}
