<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Sitemap;

use Cybermaps\Sitemap\Orchestrator;
use Cybermaps\Sitemap\ProviderIdentity;
use Cybermaps\Sitemap\ProviderInterface;

final class OrchestratorInventoryTest extends \WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_options'] = array(
			'cybermaps_settings' => array(
				'include_homepage' => '1',
				'include_authors'   => '0',
				'include_archives'  => '0',
				'sitemap_url_base'  => 'site-map',
			),
		);
		$GLOBALS['cybermaps_mock_post_types']          = array();
		$GLOBALS['cybermaps_mock_taxonomies']          = array();
		$GLOBALS['cybermaps_mock_post_type_objects']   = array();
		$GLOBALS['cybermaps_mock_taxonomy_objects']    = array();
		$GLOBALS['cybermaps_mock_rewrite_rules']       = array();
		$GLOBALS['cybermaps_mock_status_headers']      = array();
	}

	public function test_index_and_materializer_share_the_same_internal_filename(): void {
		$orchestrator = new Orchestrator();
		$entries     = $orchestrator->get_internal_sitemap_entries();

		$this->assertCount( 1, $entries );
		$this->assertSame( ProviderIdentity::MISC, $entries[0]['provider_id'] );
		$this->assertSame( 'system', $entries[0]['provider_kind'] );
		$this->assertSame( 'misc', $entries[0]['provider_name'] );
		$this->assertSame( 1, $entries[0]['page'] );
		$this->assertSame( 'site-map-misc.xml', $entries[0]['filename'] );

		$index = $orchestrator->generate_xml( 'index', 1 );
		$this->assertStringContainsString(
			'https://example.com/site-map-misc.xml',
			$index
		);
	}

	public function test_dynamic_empty_raw_child_retains_the_wordpress_core_404_contract(): void {
		$GLOBALS['cybermaps_mock_post_types'] = array( 'post' );
		$GLOBALS['cybermaps_mock_post_type_objects']['post'] = (object) array(
			'name'   => 'post',
			'public' => true,
		);

		$provider = new class() implements ProviderInterface {
			public function get_urls( int $page ): array {
				unset( $page );
				return array();
			}

			public function get_count(): int {
				return 4000;
			}

			public function get_lastmod(): string {
				return '';
			}
		};

		$orchestrator = new Orchestrator();
		$this->inject_providers(
			$orchestrator,
			array( ProviderIdentity::post_type( 'post' ) => $provider )
		);

		$index = $orchestrator->generate_xml( 'index', 1 );
		$this->assertStringContainsString( 'site-map-posts-post-1.xml', $index );
		$this->assertStringContainsString( 'site-map-posts-post-2.xml', $index );

		$GLOBALS['cybermaps_mock_status_headers'] = array();
		$child = $orchestrator->generate_xml( ProviderIdentity::post_type( 'post' ), 1 );

		$this->assertStringContainsString( '<urlset', $child );
		$this->assertStringNotContainsString( '<url>', $child );
		$this->assertContains( 404, $GLOBALS['cybermaps_mock_status_headers'] );
	}

	public function test_reserved_object_names_and_shared_slugs_have_unique_identities_and_files(): void {
		$object_names = array( 'news', 'misc', 'authors', 'archives', 'shared' );
		$GLOBALS['cybermaps_mock_post_types'] = $object_names;
		$GLOBALS['cybermaps_mock_taxonomies'] = $object_names;
		foreach ( $object_names as $name ) {
			$GLOBALS['cybermaps_mock_post_type_objects'][ $name ] = (object) array(
				'name'   => $name,
				'public' => true,
				'labels' => (object) array( 'name' => 'Post ' . $name ),
			);
			$GLOBALS['cybermaps_mock_taxonomy_objects'][ $name ] = (object) array(
				'name'   => $name,
				'public' => true,
				'labels' => (object) array( 'name' => 'Taxonomy ' . $name ),
			);
		}
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'enable_google_news' => '1',
			'include_homepage'   => '1',
			'include_authors'    => '1',
			'include_archives'   => '1',
			'sitemap_url_base'   => 'site-map',
		);
		$GLOBALS['cybermaps_mock_options']['cybermaps_discovery_center'] = array(
			'overrides' => array_fill_keys(
				array( 'page', 'news', 'authors', 'archives', ...$object_names ),
				0.5
			),
		);

		$orchestrator = new Orchestrator();
		$provider_ids = ProviderIdentity::system_providers( true );
		foreach ( $object_names as $name ) {
			$provider_ids[] = ProviderIdentity::post_type( $name );
			$provider_ids[] = ProviderIdentity::taxonomy( $name );
		}
		$this->inject_providers(
			$orchestrator,
			array_fill_keys( $provider_ids, $this->provider() )
		);

		$entries   = $orchestrator->get_internal_sitemap_entries();
		$filenames = array_column( $entries, 'filename', 'provider_id' );

		$this->assertCount( 14, $entries );
		$this->assertCount( 14, array_unique( array_column( $entries, 'provider_id' ) ) );
		$this->assertCount( 14, array_unique( array_column( $entries, 'filename' ) ) );
		$this->assertSame( 'site-map-news.xml', $filenames[ ProviderIdentity::NEWS ] );
		$this->assertSame( 'site-map-misc.xml', $filenames[ ProviderIdentity::MISC ] );
		$this->assertSame( 'site-map-authors-1.xml', $filenames[ ProviderIdentity::AUTHORS ] );
		$this->assertSame( 'site-map-archives-1.xml', $filenames[ ProviderIdentity::ARCHIVES ] );
		$this->assertSame( 'site-map-posts-news-1.xml', $filenames[ ProviderIdentity::post_type( 'news' ) ] );
		$this->assertSame( 'site-map-taxonomies-news-1.xml', $filenames[ ProviderIdentity::taxonomy( 'news' ) ] );
		$this->assertSame( 'site-map-posts-shared-1.xml', $filenames[ ProviderIdentity::post_type( 'shared' ) ] );
		$this->assertSame( 'site-map-taxonomies-shared-1.xml', $filenames[ ProviderIdentity::taxonomy( 'shared' ) ] );
		$index = $orchestrator->generate_xml( 'index', 1 );
		$this->assertStringContainsString( 'https://example.com/site-map-posts-shared-1.xml', $index );
		$this->assertStringContainsString( 'https://example.com/site-map-taxonomies-shared-1.xml', $index );
		$this->assertTrue( ( new \DOMDocument() )->loadXML( $index ) );

		$this->assertInstanceOf(
			\Cybermaps\Sitemap\NewsProvider::class,
			( new Orchestrator() )->get_provider( 'news' )
		);
		$this->assertNull(
			( new Orchestrator() )->get_provider( 'shared' ),
			'A raw slug shared by a post type and taxonomy must never select one arbitrarily.'
		);
		$this->assertInstanceOf(
			\Cybermaps\Sitemap\PostTypeProvider::class,
			( new Orchestrator() )->get_provider( ProviderIdentity::post_type( 'shared' ) )
		);
		$this->assertInstanceOf(
			\Cybermaps\Sitemap\TaxonomyProvider::class,
			( new Orchestrator() )->get_provider( ProviderIdentity::taxonomy( 'shared' ) )
		);
	}

	public function test_rewrite_rules_encode_provider_kind_and_do_not_register_an_ambiguous_slug_route(): void {
		Orchestrator::add_rewrite_rules();

		$rules   = $GLOBALS['cybermaps_mock_rewrite_rules'];
		$queries = array_column( $rules, 'query' );
		$regexes = array_column( $rules, 'regex' );

		$this->assertContains(
			'index.php?cybermaps_sitemap=post_type%3A$matches[1]&cybermaps_page=$matches[2]',
			$queries
		);
		$this->assertContains(
			'index.php?cybermaps_sitemap=taxonomy%3A$matches[1]&cybermaps_page=$matches[2]',
			$queries
		);
		$this->assertContains( '^site\\-map-posts-([a-z0-9_-]+)-([1-9][0-9]*)\\.xml$', $regexes );
		$this->assertContains( '^site\\-map-taxonomies-([a-z0-9_-]+)-([1-9][0-9]*)\\.xml$', $regexes );
		$post_query = str_replace(
			array( '$matches[1]', '$matches[2]' ),
			array( 'shared', '3' ),
			(string) $queries[
				array_search(
					'index.php?cybermaps_sitemap=post_type%3A$matches[1]&cybermaps_page=$matches[2]',
					$queries,
					true
				)
			]
		);
		parse_str( (string) wp_parse_url( $post_query, PHP_URL_QUERY ), $post_vars );
		$this->assertSame( ProviderIdentity::post_type( 'shared' ), $post_vars['cybermaps_sitemap'] );
		$this->assertSame( '3', $post_vars['cybermaps_page'] );

		foreach ( $regexes as $regex ) {
			$this->assertSame(
				0,
				preg_match( '#' . $regex . '#', 'site-map-shared-1.xml' ),
				'The former kind-less child route must not be registered.'
			);
		}
	}

	public function test_zero_priority_system_provider_is_not_reachable_outside_the_index(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_discovery_center'] = array(
			'overrides' => array( 'news' => 0 ),
		);

		$this->assertNull( ( new Orchestrator() )->get_provider( ProviderIdentity::NEWS ) );
		$this->assertNull( ( new Orchestrator() )->get_provider( 'news' ) );
	}

	public function test_same_slug_post_type_and_taxonomy_can_be_enabled_independently(): void {
		$GLOBALS['cybermaps_mock_post_types'] = array( 'shared' );
		$GLOBALS['cybermaps_mock_taxonomies'] = array( 'shared' );
		$GLOBALS['cybermaps_mock_post_type_objects']['shared'] = (object) array(
			'name'   => 'shared',
			'public' => true,
		);
		$GLOBALS['cybermaps_mock_taxonomy_objects']['shared'] = (object) array(
			'name'   => 'shared',
			'public' => true,
		);
		$GLOBALS['cybermaps_mock_options']['cybermaps_discovery_center'] = array(
			'overrides' => array(
				'post_type:shared' => 0,
				'taxonomy:shared'  => 0.8,
			),
		);

		$orchestrator = new Orchestrator();
		$this->inject_providers(
			$orchestrator,
			array(
				ProviderIdentity::post_type( 'shared' ) => $this->provider(),
				ProviderIdentity::taxonomy( 'shared' )  => $this->provider(),
			)
		);
		$entries = $orchestrator->get_internal_sitemap_entries();

		$this->assertContains( ProviderIdentity::taxonomy( 'shared' ), array_column( $entries, 'provider_id' ) );
		$this->assertNotContains( ProviderIdentity::post_type( 'shared' ), array_column( $entries, 'provider_id' ) );
	}

	public function test_only_system_news_output_receives_the_google_news_namespace(): void {
		$GLOBALS['cybermaps_mock_post_types'] = array( 'news' );
		$GLOBALS['cybermaps_mock_post_type_objects']['news'] = (object) array(
			'name'   => 'news',
			'public' => true,
		);
		$news_urls = array(
			array(
				'loc'  => 'https://example.com/news/story/',
				'news' => array(
					'publication'     => array(
						'name'     => 'Example News',
						'language' => 'en',
					),
					'publication_date' => '2026-07-30T10:00:00+00:00',
					'title'            => 'Story',
				),
			),
		);
		$post_urls = array( array( 'loc' => 'https://example.com/news/item/' ) );

		$orchestrator = new Orchestrator();
		$this->inject_providers(
			$orchestrator,
			array(
				ProviderIdentity::NEWS              => $this->provider( $news_urls ),
				ProviderIdentity::post_type( 'news' ) => $this->provider( $post_urls ),
			)
		);

		$system_xml = $orchestrator->generate_xml( ProviderIdentity::NEWS, 1 );
		$post_xml   = $orchestrator->generate_xml( ProviderIdentity::post_type( 'news' ), 1 );

		$this->assertStringContainsString(
			'xmlns:news="http://www.google.com/schemas/sitemap-news/0.9"',
			$system_xml
		);
		$this->assertStringContainsString( '<news:news>', $system_xml );
		$this->assertStringNotContainsString( 'xmlns:news=', $post_xml );
		$this->assertTrue( ( new \DOMDocument() )->loadXML( $system_xml ) );
		$this->assertTrue( ( new \DOMDocument() )->loadXML( $post_xml ) );
	}

	public function test_enabled_empty_news_sitemap_remains_a_valid_http_200_publication(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['enable_google_news'] = '1';
		$GLOBALS['cybermaps_mock_status_headers'] = array();

		$orchestrator = new Orchestrator();
		$this->inject_providers(
			$orchestrator,
			array( ProviderIdentity::NEWS => $this->provider( array() ) )
		);

		$entries = $orchestrator->get_internal_sitemap_entries();
		$this->assertContains( ProviderIdentity::NEWS, array_column( $entries, 'provider_id' ) );

		$xml = $orchestrator->generate_xml( ProviderIdentity::NEWS, 1 );
		$this->assertStringContainsString( '<urlset', $xml );
		$this->assertStringNotContainsString( '<url>', $xml );
		$this->assertNotContains( 404, $GLOBALS['cybermaps_mock_status_headers'] );
	}

	/**
	 * @param array<string, ProviderInterface> $providers
	 */
	private function inject_providers( Orchestrator $orchestrator, array $providers ): void {
		$property = new \ReflectionProperty( Orchestrator::class, 'providers' );
		$property->setValue( $orchestrator, $providers );
	}

	/**
	 * @param array<int, array<string, mixed>> $urls
	 */
	private function provider( array $urls = array( array( 'loc' => 'https://example.com/item/' ) ) ): ProviderInterface {
		return new class( $urls ) implements ProviderInterface {
			/**
			 * @param array<int, array<string, mixed>> $urls
			 */
			public function __construct( private readonly array $urls ) {}

			public function get_urls( int $page ): array {
				unset( $page );
				return $this->urls;
			}

			public function get_count(): int {
				return count( $this->urls );
			}

			public function get_lastmod(): string {
				return '2026-07-30T10:00:00+00:00';
			}
		};
	}
}
