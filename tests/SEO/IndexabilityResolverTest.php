<?php
declare(strict_types=1);

namespace Cybermaps\Tests\SEO;

use Cybermaps\SEO\GenesisMaiAdapter;
use Cybermaps\SEO\IndexabilityResolver;
use Cybermaps\SEO\PluginSeoAdapter;
use Cybermaps\SEO\PublicationEligibility;
use Cybermaps\SEO\SeoContext;
use PHPUnit\Framework\TestCase;

final class IndexabilityResolverTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['cybermaps_mock_options']                   = array(
			'blog_public'       => '1',
			'cybermaps_settings' => array(
				'include_homepage' => '1',
				'include_authors'  => '1',
				'include_archives' => '1',
			),
			'cybermaps_discovery_center' => wp_json_encode(
				array(
					'archetype'    => 'medium-business',
					'overrides'    => array(),
					'type_intents' => array(),
				)
			),
		);
		$GLOBALS['cybermaps_mock_posts']                     = array();
		$GLOBALS['cybermaps_mock_post_meta']                 = array();
		$GLOBALS['cybermaps_mock_term_meta']                 = array();
		$GLOBALS['cybermaps_mock_user_meta']                 = array();
		$GLOBALS['cybermaps_mock_genesis_seo_active']        = true;
		$GLOBALS['cybermaps_mock_genesis_seo_options']       = array();
		$GLOBALS['cybermaps_mock_genesis_archive_support']   = array();
		$GLOBALS['cybermaps_mock_genesis_cpt_options']       = array();
		$GLOBALS['cybermaps_mock_object_taxonomies']         = array();
		$GLOBALS['cybermaps_mock_object_terms']              = array();
		$GLOBALS['cybermaps_mock_post_type_objects']['post'] = (object) array( 'public' => true );
		$GLOBALS['cybermaps_mock_taxonomy_objects']['category'] = (object) array( 'public' => true );
	}

	public function test_published_post_is_indexable(): void {
		$post = $this->post( 10 );

		$decision = $this->resolver()->resolve( SeoContext::post( $post ) );

		self::assertTrue( $decision->indexable );
		self::assertSame( array(), $decision->reasons );
	}

	public function test_genesis_noindex_blocks_every_publication(): void {
		$post = $this->post( 11 );
		$GLOBALS['cybermaps_mock_post_meta'][11]['_genesis_noindex'] = '1';

		$decision = $this->resolver()->resolve( SeoContext::post( $post ) );

		self::assertFalse( $decision->indexable );
		self::assertContains( 'noindex:genesis', $decision->reasons );
	}

	public function test_nofollow_and_noarchive_do_not_block_publication(): void {
		$post = $this->post( 12 );
		$GLOBALS['cybermaps_mock_post_meta'][12]['_genesis_nofollow']  = '1';
		$GLOBALS['cybermaps_mock_post_meta'][12]['_genesis_noarchive'] = '1';

		$decision = $this->resolver()->resolve( SeoContext::post( $post ) );

		self::assertTrue( $decision->indexable );
		self::assertTrue( $decision->signals[0]->nofollow );
		self::assertTrue( $decision->signals[0]->noarchive );
	}

	public function test_self_canonical_is_allowed_and_other_canonical_is_blocked(): void {
		$self = $this->post( 13 );
		$GLOBALS['cybermaps_mock_post_meta'][13]['_genesis_canonical_uri'] = 'https://example.com/?p=13';

		self::assertTrue( $this->resolver()->resolve( SeoContext::post( $self ) )->indexable );

		$other = $this->post( 14 );
		$GLOBALS['cybermaps_mock_post_meta'][14]['_genesis_canonical_uri'] = 'https://example.com/canonical/';
		$decision = $this->resolver()->resolve( SeoContext::post( $other ) );

		self::assertFalse( $decision->indexable );
		self::assertContains( 'canonical_other:genesis', $decision->reasons );
	}

	public function test_genesis_redirect_blocks_even_when_genesis_seo_is_inactive(): void {
		$post = $this->post( 15 );
		$GLOBALS['cybermaps_mock_genesis_seo_active'] = false;
		$GLOBALS['cybermaps_mock_post_meta'][15]['redirect'] = 'https://example.net/new/';

		$decision = $this->resolver()->resolve( SeoContext::post( $post ) );

		self::assertFalse( $decision->indexable );
		self::assertContains( 'redirect:genesis_redirect', $decision->reasons );
	}

	public function test_global_genesis_archive_directives_are_honored(): void {
		$GLOBALS['cybermaps_mock_genesis_seo_options'] = array(
			'noindex_cat_archive'    => '1',
			'noindex_author_archive' => '1',
			'noindex_date_archive'   => '1',
		);

		$resolver = $this->resolver();

		self::assertFalse( $resolver->resolve( SeoContext::term( 2, 'category' ) )->indexable );
		self::assertFalse( $resolver->resolve( SeoContext::author( 3 ) )->indexable );
		self::assertFalse( $resolver->resolve( SeoContext::date_archive( 2026, 7 ) )->indexable );
	}

	public function test_channel_exclusion_is_separate_from_provider_indexability(): void {
		$post = $this->post( 16 );
		$GLOBALS['cybermaps_mock_post_meta'][16]['_cybermaps_exclude_ai'] = '1';
		$eligibility = new PublicationEligibility( $this->resolver() );

		self::assertTrue( $eligibility->post( $post, PublicationEligibility::SITEMAP )->indexable );
		self::assertFalse( $eligibility->post( $post, PublicationEligibility::AI )->indexable );
	}

	public function test_attachment_rows_are_excluded_from_every_publication_channel(): void {
		$GLOBALS['cybermaps_mock_post_type_objects']['attachment'] = (object) array( 'public' => true );
		$attachment = (object) array(
			'ID'            => 25,
			'post_type'     => 'attachment',
			'post_status'   => 'publish',
			'post_password' => '',
		);
		$GLOBALS['cybermaps_mock_posts'][25] = $attachment;
		$eligibility = new PublicationEligibility( $this->resolver() );

		foreach (
			array(
				PublicationEligibility::SITEMAP,
				PublicationEligibility::AI,
				PublicationEligibility::SCHEMA,
			) as $channel
		) {
			$decision = $eligibility->post( $attachment, $channel );
			self::assertFalse( $decision->indexable );
			self::assertContains( 'publication_post_type_excluded', $decision->reasons );
		}
	}

	public function test_global_ai_id_exclusions_apply_to_every_ai_publication(): void {
		$llms_post    = $this->post( 21 );
		$sitemap_post = $this->post( 22 );
		$settings     = array(
			'llms_exclude_ids' => '21, 22',
		);
		$eligibility  = new PublicationEligibility( $this->resolver(), $settings );

		self::assertFalse( $eligibility->post( $llms_post, PublicationEligibility::AI )->indexable );
		self::assertFalse( $eligibility->post( $sitemap_post, PublicationEligibility::AI )->indexable );
		self::assertTrue( $eligibility->post( $llms_post, PublicationEligibility::SITEMAP )->indexable );
		self::assertTrue( $eligibility->post( $sitemap_post, PublicationEligibility::SITEMAP )->indexable );
	}

	public function test_global_ai_term_exclusions_match_ids_and_slugs_across_taxonomies(): void {
		$slug_post = $this->post( 23 );
		$id_post   = $this->post( 24 );
		$GLOBALS['cybermaps_mock_object_taxonomies']['post'] = array( 'category', 'post_tag' );
		$GLOBALS['cybermaps_mock_object_terms'][23] = array(
			(object) array( 'term_id' => 4, 'slug' => 'members-only' ),
		);
		$GLOBALS['cybermaps_mock_object_terms'][24] = array(
			(object) array( 'term_id' => 77, 'slug' => 'internal' ),
		);
		$eligibility = new PublicationEligibility(
			$this->resolver(),
			array( 'ai_sitemap_exclude_terms' => 'members-only, 77' )
		);

		self::assertFalse( $eligibility->post( $slug_post, PublicationEligibility::AI )->indexable );
		self::assertFalse( $eligibility->post( $id_post, PublicationEligibility::AI )->indexable );
		self::assertTrue( $eligibility->post( $slug_post, PublicationEligibility::SITEMAP )->indexable );
	}

	public function test_matrix_zero_disables_sitemap_and_ai_but_not_schema(): void {
		$post = $this->post( 18 );
		$GLOBALS['cybermaps_mock_options']['cybermaps_discovery_center'] = wp_json_encode(
			array(
				'archetype' => 'medium-business',
				'overrides' => array( 'post' => 0 ),
			)
		);
		$eligibility = new PublicationEligibility( $this->resolver() );

		$sitemap = $eligibility->post( $post, PublicationEligibility::SITEMAP );
		$ai      = $eligibility->post( $post, PublicationEligibility::AI );
		$schema  = $eligibility->post( $post, PublicationEligibility::SCHEMA );

		self::assertFalse( $sitemap->indexable );
		self::assertContains( 'matrix_type_disabled', $sitemap->reasons );
		self::assertFalse( $ai->indexable );
		self::assertTrue( $schema->indexable );
	}

	public function test_discouraging_search_blocks_publication(): void {
		$post = $this->post( 17 );
		$GLOBALS['cybermaps_mock_options']['blog_public'] = '0';

		$decision = $this->resolver()->resolve( SeoContext::post( $post ) );

		self::assertFalse( $decision->indexable );
		self::assertContains( 'site_discourages_search', $decision->reasons );
	}

	public function test_current_aioseo_model_directives_are_honored(): void {
		require_once dirname( __DIR__ ) . '/fixtures/AioseoPostModelStub.php';

		$post = $this->post( 19 );
		\AIOSEO\Plugin\Common\Models\Post::$fixtures[19] = (object) array(
			'robots_default' => false,
			'robots_noindex' => true,
			'canonical_url'  => 'https://example.com/preferred/',
		);

		$decision = ( new IndexabilityResolver( array( new PluginSeoAdapter() ) ) )
			->resolve( SeoContext::post( $post ) );

		self::assertFalse( $decision->indexable );
		self::assertContains( 'noindex:seo_plugins', $decision->reasons );
		self::assertSame( 'https://example.com/preferred/', $decision->canonical_url );
	}

	public function test_rank_math_serialized_post_directives_are_honored(): void {
		if ( ! defined( 'RANK_MATH_VERSION' ) ) {
			define( 'RANK_MATH_VERSION', 'fixture' );
		}
		$post = $this->post( 20 );
		$GLOBALS['cybermaps_mock_post_meta'][20]['rank_math_robots'] = array( 'noindex', 'nofollow' );
		$GLOBALS['cybermaps_mock_post_meta'][20]['rank_math_canonical_url'] = 'https://example.com/rank-preferred/';

		$decision = ( new IndexabilityResolver( array( new PluginSeoAdapter() ) ) )
			->resolve( SeoContext::post( $post ) );

		self::assertFalse( $decision->indexable );
		self::assertContains( 'noindex:seo_plugins', $decision->reasons );
		self::assertSame( 'https://example.com/rank-preferred/', $decision->canonical_url );
	}

	private function resolver(): IndexabilityResolver {
		return new IndexabilityResolver( array( new GenesisMaiAdapter() ) );
	}

	private function post( int $id ): object {
		$post = (object) array(
			'ID'            => $id,
			'post_type'     => 'post',
			'post_status'   => 'publish',
			'post_password' => '',
		);
		$GLOBALS['cybermaps_mock_posts'][ $id ] = $post;
		return $post;
	}
}
