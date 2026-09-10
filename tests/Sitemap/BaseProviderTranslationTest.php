<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Sitemap;

use Cybermaps\Core\TranslationRegistry;
use Cybermaps\Sitemap\BaseProvider;
use PHPUnit\Framework\TestCase;

final class BaseProviderTranslationTest extends TestCase {
	/** @var array<string,mixed> */
	private array $saved_globals = array();

	protected function setUp(): void {
		parent::setUp();
		foreach (
			array(
				'cybermaps_mock_blog_stack',
				'cybermaps_mock_current_blog_id',
				'cybermaps_mock_genesis_seo_active',
				'cybermaps_mock_is_multisite',
				'cybermaps_mock_options',
				'cybermaps_mock_options_by_blog',
				'cybermaps_mock_permalinks',
				'cybermaps_mock_post_meta',
				'cybermaps_mock_post_type_objects',
				'cybermaps_mock_posts',
				'cybermaps_mock_switched_blogs',
				'cybermaps_mock_taxonomy_objects',
				'cybermaps_mock_term_meta',
				'cybermaps_mock_terms',
			) as $key
		) {
			$this->saved_globals[ $key ] = $GLOBALS[ $key ] ?? null;
		}

		$GLOBALS['cybermaps_mock_current_blog_id'] = 1;
		$GLOBALS['cybermaps_mock_genesis_seo_active'] = true;
		$GLOBALS['cybermaps_mock_blog_stack'] = array();
		$GLOBALS['cybermaps_mock_switched_blogs'] = array();
		$GLOBALS['cybermaps_mock_is_multisite'] = true;
		$GLOBALS['cybermaps_mock_options'] = array(
			'cybermaps_settings' => array(
				'enable_translation_integrations' => '1',
			),
			'cybermaps_discovery_center' => wp_json_encode(
				array(
					'overrides' => array(
						'post_type:post'     => 0.8,
						'taxonomy:category' => 0.8,
					),
				)
			),
		);
		$GLOBALS['cybermaps_mock_options_by_blog'] = array(
			2 => array(
				'cybermaps_settings' => array(
					'enable_translation_integrations' => '1',
					'frontend_base_url'                => 'https://it.example',
				),
			),
		);
		$GLOBALS['cybermaps_mock_post_type_objects'] = array(
			'post' => (object) array( 'public' => true ),
		);
		$GLOBALS['cybermaps_mock_taxonomy_objects'] = array(
			'category'    => (object) array( 'public' => true ),
			'private_tax' => (object) array( 'public' => false ),
		);
		$GLOBALS['cybermaps_mock_terms'] = array(
			'category' => array(
				(object) array( 'term_id' => 100, 'taxonomy' => 'category', 'slug' => 'source', 'count' => 2 ),
				(object) array( 'term_id' => 200, 'taxonomy' => 'category', 'slug' => 'empty', 'count' => 0 ),
				(object) array( 'term_id' => 300, 'taxonomy' => 'category', 'slug' => 'noindex', 'count' => 1 ),
				(object) array( 'term_id' => 500, 'taxonomy' => 'category', 'slug' => 'valid', 'count' => 3 ),
			),
			'private_tax' => array(
				(object) array( 'term_id' => 400, 'taxonomy' => 'private_tax', 'slug' => 'private', 'count' => 1 ),
			),
		);
		$GLOBALS['cybermaps_mock_term_meta'] = array(
			300 => array( 'noindex' => '1' ),
		);
		$GLOBALS['cybermaps_mock_posts'] = array(
			10 => (object) array( 'ID' => 10, 'post_status' => 'publish', 'post_type' => 'post', 'post_password' => '' ),
			11 => (object) array( 'ID' => 11, 'post_status' => 'publish', 'post_type' => 'post', 'post_password' => '' ),
			20 => (object) array( 'ID' => 20, 'post_status' => 'draft', 'post_type' => 'post', 'post_password' => '' ),
			30 => (object) array( 'ID' => 30, 'post_status' => 'publish', 'post_type' => 'post', 'post_password' => '' ),
			40 => (object) array( 'ID' => 40, 'post_status' => 'publish', 'post_type' => 'post', 'post_password' => 'secret' ),
			60 => (object) array( 'ID' => 60, 'post_status' => 'publish', 'post_type' => 'post', 'post_password' => '' ),
		);
		$GLOBALS['cybermaps_mock_post_meta'] = array(
			30 => array( '_cybermaps_exclude_sitemap' => '1' ),
		);
		$GLOBALS['cybermaps_mock_permalinks'] = array(
			10 => 'https://example.com/source/',
			11 => 'https://example.com/duplicate-language/',
			20 => 'https://example.com/draft/',
			30 => 'https://example.com/excluded/',
			40 => 'https://example.com/password/',
			60 => 'https://example.com/remote/',
		);
	}

	protected function tearDown(): void {
		foreach ( $this->saved_globals as $key => $value ) {
			if ( null === $value ) {
				unset( $GLOBALS[ $key ] );
			} else {
				$GLOBALS[ $key ] = $value;
			}
		}
		parent::tearDown();
	}

	public function test_hreflang_includes_only_valid_eligible_resources_and_restores_blog(): void {
		$registry = new BaseProviderTranslationRegistrySpy(
			array(
				array( 'site_id' => 1, 'item_id' => 11, 'item_type' => 'post', 'lang_code' => 'en-US' ),
				array( 'site_id' => 1, 'item_id' => 10, 'item_type' => 'post', 'lang_code' => 'en_US' ),
				array( 'site_id' => 1, 'item_id' => 20, 'item_type' => 'post', 'lang_code' => 'fr-FR' ),
				array( 'site_id' => 1, 'item_id' => 30, 'item_type' => 'post', 'lang_code' => 'de-DE' ),
				array( 'site_id' => 1, 'item_id' => 40, 'item_type' => 'post', 'lang_code' => 'es-ES' ),
				array( 'site_id' => 1, 'item_id' => 999, 'item_type' => 'post', 'lang_code' => 'nl-NL' ),
				array( 'site_id' => 1, 'item_id' => 70, 'item_type' => 'term', 'lang_code' => 'pl-PL' ),
				array( 'site_id' => 1, 'item_id' => 80, 'item_type' => 'post', 'lang_code' => '--bad--' ),
				array( 'site_id' => array( 1 ), 'item_id' => 10, 'item_type' => 'post', 'lang_code' => 'sv-SE' ),
				array( 'site_id' => 1, 'item_id' => array( 10 ), 'item_type' => 'post', 'lang_code' => 'da-DK' ),
				array( 'site_id' => 1, 'item_id' => 10, 'item_type' => array( 'post' ), 'lang_code' => 'nb-NO' ),
				array( 'site_id' => 2, 'item_id' => 60, 'item_type' => 'post', 'lang_code' => 'it-IT' ),
			)
		);

		$alternates = $this->provider( $registry )->alternates( 10, 'post' );

		$this->assertSame(
			array(
				'en-US' => 'https://example.com/source/',
				'it-IT' => 'https://it.example/remote/',
			),
			$alternates
		);
		$this->assertSame( array( 2 ), $GLOBALS['cybermaps_mock_switched_blogs'] );
		$this->assertSame( 1, $GLOBALS['cybermaps_mock_current_blog_id'] );
		$this->assertSame( array(), $GLOBALS['cybermaps_mock_blog_stack'] );
	}

	public function test_term_hreflang_excludes_empty_private_and_noindex_targets(): void {
		$registry = new BaseProviderTranslationRegistrySpy(
			array(
				array( 'site_id' => 1, 'item_id' => 100, 'item_type' => 'term', 'lang_code' => 'en-US' ),
				array( 'site_id' => 1, 'item_id' => 200, 'item_type' => 'term', 'lang_code' => 'fr-FR' ),
				array( 'site_id' => 1, 'item_id' => 300, 'item_type' => 'term', 'lang_code' => 'de-DE' ),
				array( 'site_id' => 1, 'item_id' => 400, 'item_type' => 'term', 'lang_code' => 'es-ES' ),
				array( 'site_id' => 1, 'item_id' => 500, 'item_type' => 'term', 'lang_code' => 'it-IT' ),
			)
		);

		$this->assertSame(
			array(
				'en-US' => 'https://example.com/category/100/',
				'it-IT' => 'https://example.com/category/500/',
			),
			$this->provider( $registry )->alternates( 100, 'term' )
		);
	}

	private function provider( TranslationRegistry $registry ): BaseProvider {
		return new class( $registry ) extends BaseProvider {
			public function __construct( private TranslationRegistry $registry ) {}

			public function get_urls( int $page ): array {
				unset( $page );
				return array();
			}

			public function get_count(): int {
				return 0;
			}

			public function get_lastmod(): string {
				return '';
			}

			public function alternates( int $item_id, string $item_type ): array {
				return $this->get_alternates( $item_id, $item_type );
			}

			protected function get_translation_registry(): TranslationRegistry {
				return $this->registry;
			}
		};
	}
}

final class BaseProviderTranslationRegistrySpy extends TranslationRegistry {
	public function __construct( private array $rows ) {}

	public function get_translations( $site_id, $item_id, $type = 'post' ) {
		unset( $site_id, $item_id, $type );
		return $this->rows;
	}
}
