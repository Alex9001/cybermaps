<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Sitemap;

use Cybermaps\Sitemap\ShortcodeHandler;
use PHPUnit\Framework\TestCase;

final class ShortcodeHandlerTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['cybermaps_mock_options'] = array(
			'blog_public'        => '1',
			'cybermaps_settings' => array(
				'enable_shortcode'    => '1',
				'include_empty_terms' => '0',
			),
		);
		$GLOBALS['cybermaps_mock_posts'] = array();
		$GLOBALS['cybermaps_mock_post_meta'] = array();
		$GLOBALS['cybermaps_mock_post_types'] = array( 'post', 'page' );
		$GLOBALS['cybermaps_mock_post_type_objects'] = array(
			'post' => (object) array(
				'public' => true,
				'labels' => (object) array( 'name' => 'Posts' ),
			),
			'page' => (object) array(
				'public' => true,
				'labels' => (object) array( 'name' => 'Pages' ),
			),
		);
		$GLOBALS['cybermaps_mock_taxonomies'] = array( 'category', 'post_tag' );
		$GLOBALS['cybermaps_mock_taxonomy_objects'] = array(
			'category' => (object) array(
				'public' => true,
				'labels' => (object) array( 'name' => 'Categories' ),
			),
			'post_tag' => (object) array(
				'public' => true,
				'labels' => (object) array( 'name' => 'Tags' ),
			),
		);
		$GLOBALS['cybermaps_mock_terms'] = array();
		$GLOBALS['cybermaps_mock_get_terms_args'] = array();
		$GLOBALS['cybermaps_mock_wp_query_args'] = array();
		$GLOBALS['cybermaps_mock_filter_callbacks'] = array();
		$GLOBALS['wp_hooks'] = array();
		unset(
			$GLOBALS['cybermaps_mock_wp_query_posts'],
			$GLOBALS['cybermaps_mock_wp_query_callback']
		);
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['cybermaps_mock_wp_query_posts'],
			$GLOBALS['cybermaps_mock_wp_query_callback'],
			$GLOBALS['cybermaps_mock_filter_callbacks']
		);
		parent::tearDown();
	}

	public function test_malformed_settings_object_disables_shortcode_without_runtime_warnings(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = (object) array(
			'enable_shortcode' => '1',
		);

		set_error_handler(
			static function ( int $severity, string $message, string $file, int $line ): never {
				throw new \ErrorException( $message, 0, $severity, $file, $line );
			}
		);
		try {
			$output = ( new ShortcodeHandler() )->render_shortcode( array() );
		} finally {
			restore_error_handler();
		}

		$this->assertSame( '', $output );
		$this->assertSame( array(), $GLOBALS['cybermaps_mock_wp_query_args'] );
	}

	public function test_taxonomy_only_mode_renders_term_archive_urls_and_tag_alias(): void {
		$GLOBALS['cybermaps_mock_terms'] = array(
			'category' => array(
				$this->term( 1, 'category', 'Guides', 'guides' ),
				$this->term( 2, 'category', 'Old Guides', 'old-guides' ),
			),
			'post_tag' => array(
				$this->term( 3, 'post_tag', 'Launch', 'launch' ),
			),
		);

		$output = ( new ShortcodeHandler() )->render_shortcode(
			array(
				'only'    => 'category,tag',
				'depth'   => '-1',
				'exclude' => 'old-*',
				'limit'   => '20',
			)
		);

		$this->assertSame( array(), $GLOBALS['cybermaps_mock_wp_query_args'] );
		$this->assertStringContainsString( 'Categories', $output );
		$this->assertStringContainsString( 'Tags', $output );
		$this->assertStringContainsString( 'https://example.com/category/1/', $output );
		$this->assertStringContainsString( 'https://example.com/post_tag/3/', $output );
		$this->assertStringNotContainsString( 'Old Guides', $output );
		$this->assertSame(
			array( 'category', 'post_tag' ),
			array_column( $GLOBALS['cybermaps_mock_get_terms_args'], 'taxonomy' )
		);
	}

	public function test_taxonomy_scan_fills_limit_after_early_exclusions_and_ineligible_terms(): void {
		$first_batch = array();
		for ( $id = 1; $id < 250; ++$id ) {
			$first_batch[] = $this->term( $id, 'category', 'Old ' . $id, 'old-' . $id );
		}
		$first_batch[] = $this->term( 250, 'category', 'Policy Blocked', 'policy-blocked' );
		$GLOBALS['cybermaps_mock_terms']['category'] = $first_batch;
		$GLOBALS['cybermaps_mock_filter_callbacks']['cybermaps_publication_eligibility'] = array(
			static function ( $decision, $context ) {
				if ( 250 !== (int) $context->object_id ) {
					return $decision;
				}

				$GLOBALS['cybermaps_mock_terms']['category'] = array(
					(object) array(
						'term_id'  => 251,
						'taxonomy' => 'category',
						'name'     => 'Current One',
						'slug'     => 'current-one',
						'parent'   => 0,
					),
					(object) array(
						'term_id'  => 252,
						'taxonomy' => 'category',
						'name'     => 'Current Two',
						'slug'     => 'current-two',
						'parent'   => 0,
					),
				);

				return $decision->with_reasons( array( 'test_policy_exclusion' ) );
			},
		);

		$output = ( new ShortcodeHandler() )->render_shortcode(
			array(
				'only'    => 'category',
				'exclude' => 'old-*',
				'limit'   => '2',
				'depth'   => '-1',
			)
		);

		$this->assertStringContainsString( 'Current One', $output );
		$this->assertStringContainsString( 'Current Two', $output );
		$this->assertStringNotContainsString( 'Policy Blocked', $output );
		$this->assertSame( 2, substr_count( $output, '<a href=' ) );
		$this->assertCount( 2, $GLOBALS['cybermaps_mock_get_terms_args'] );
		$this->assertSame( 250, $GLOBALS['cybermaps_mock_get_terms_args'][0]['number'] );
		$this->assertSame( 0, $GLOBALS['cybermaps_mock_get_terms_args'][0]['offset'] );
		$this->assertSame( 250, $GLOBALS['cybermaps_mock_get_terms_args'][1]['offset'] );
	}

	public function test_global_limit_skips_later_taxonomy_queries_after_capacity_is_filled(): void {
		$GLOBALS['cybermaps_mock_terms'] = array(
			'category' => array(
				$this->term( 1, 'category', 'First Category', 'first-category' ),
			),
			'post_tag' => array(
				$this->term( 2, 'post_tag', 'Unneeded Tag', 'unneeded-tag' ),
			),
		);

		$output = ( new ShortcodeHandler() )->render_shortcode(
			array(
				'only'  => 'taxonomy:category,taxonomy:post_tag',
				'depth' => '-1',
				'limit' => '1',
			)
		);

		$this->assertStringContainsString( 'First Category', $output );
		$this->assertStringNotContainsString( 'Unneeded Tag', $output );
		$this->assertSame(
			array( 'category' ),
			array_column( $GLOBALS['cybermaps_mock_get_terms_args'], 'taxonomy' )
		);
	}

	public function test_post_and_term_hierarchies_use_complete_parent_sets(): void {
		$posts = array(
			$this->post( 501, 'Parent Page', 'parent-page', 0 ),
			$this->post( 502, 'Child Page', 'child-page', 501 ),
			$this->post( 503, 'Promoted Orphan', 'promoted-orphan', 999 ),
		);
		$GLOBALS['cybermaps_mock_posts'] = array_column( $posts, null, 'ID' );
		$GLOBALS['cybermaps_mock_wp_query_posts'] = $posts;
		$GLOBALS['cybermaps_mock_terms']['category'] = array(
			$this->term( 10, 'category', 'Parent Term', 'parent-term' ),
			$this->term( 11, 'category', 'Child Term', 'child-term', 10 ),
		);

		$output = ( new ShortcodeHandler() )->render_shortcode(
			array(
				'only' => 'page,category',
				'depth' => '0',
				'limit' => '20',
			)
		);

		$this->assertSame( 250, $GLOBALS['cybermaps_mock_wp_query_args'][0]['posts_per_page'] );
		$this->assertStringContainsString(
			'Parent Page</a><ul class="cybermap-sublist">',
			$output
		);
		$this->assertStringContainsString( 'Child Page', $output );
		$this->assertStringContainsString( 'Promoted Orphan', $output );
		$this->assertStringContainsString(
			'Parent Term</a><ul class="cybermap-sublist">',
			$output
		);
		$this->assertStringContainsString( 'Child Term', $output );
	}

	public function test_depth_and_global_limit_apply_across_post_and_term_sections(): void {
		$posts = array(
			$this->post( 1, 'Parent', 'parent', 0 ),
			$this->post( 2, 'Child', 'child', 1 ),
		);
		$GLOBALS['cybermaps_mock_posts'] = array_column( $posts, null, 'ID' );
		$GLOBALS['cybermaps_mock_wp_query_posts'] = $posts;
		$GLOBALS['cybermaps_mock_terms']['category'] = array(
			$this->term( 20, 'category', 'Term One', 'term-one' ),
			$this->term( 21, 'category', 'Term Two', 'term-two' ),
		);

		$output = ( new ShortcodeHandler() )->render_shortcode(
			array(
				'only' => 'page,category',
				'depth' => '1',
				'limit' => '2',
			)
		);

		$this->assertStringContainsString( 'Parent', $output );
		$this->assertStringNotContainsString( '>Child<', $output );
		$this->assertStringContainsString( '>Term One<', $output );
		$this->assertStringNotContainsString( '>Term Two<', $output );
		$this->assertSame( 2, substr_count( $output, '<a href=' ) );
		$this->assertSame(
			array( '1', '1' ),
			$this->section_counts( $output ),
			'Each badge must describe links actually rendered in its section.'
		);
	}

	public function test_post_and_term_links_use_the_configured_headless_frontend(): void {
		$posts = array(
			$this->post( 501, 'Headless Page', 'headless-page', 0 ),
		);
		$GLOBALS['cybermaps_mock_posts']          = array_column( $posts, null, 'ID' );
		$GLOBALS['cybermaps_mock_wp_query_posts'] = $posts;
		$GLOBALS['cybermaps_mock_terms']['category'] = array(
			$this->term( 10, 'category', 'Headless Term', 'headless-term' ),
		);
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['frontend_base_url'] = 'https://frontend.example/app';

		$output = ( new ShortcodeHandler() )->render_shortcode(
			array(
				'only' => 'page,category',
				'depth' => '-1',
				'limit' => '20',
			)
		);

		$this->assertStringContainsString(
			'https://frontend.example/app/?p=501',
			$output
		);
		$this->assertStringContainsString(
			'https://frontend.example/app/category/10/',
			$output
		);
		$this->assertStringNotContainsString( 'https://example.com/', $output );
	}

	public function test_default_and_explicit_selection_never_query_attachments(): void {
		$GLOBALS['cybermaps_mock_post_types'] = array( 'post', 'attachment', 'page' );
		$GLOBALS['cybermaps_mock_wp_query_posts'] = array();

		( new ShortcodeHandler() )->render_shortcode( array() );
		$this->assertSame(
			array( 'post', 'page' ),
			$GLOBALS['cybermaps_mock_wp_query_args'][0]['post_type']
		);

		$GLOBALS['cybermaps_mock_wp_query_args'] = array();
		( new ShortcodeHandler() )->render_shortcode( array( 'only' => 'attachment' ) );
		$this->assertSame( array(), $GLOBALS['cybermaps_mock_wp_query_args'] );
	}

	public function test_namespaced_only_tokens_disambiguate_matching_object_slugs(): void {
		$GLOBALS['cybermaps_mock_post_types'][] = 'shared';
		$GLOBALS['cybermaps_mock_post_type_objects']['shared'] = (object) array(
			'public' => true,
			'labels' => (object) array( 'name' => 'Shared Posts' ),
		);
		$GLOBALS['cybermaps_mock_taxonomies'][] = 'shared';
		$GLOBALS['cybermaps_mock_taxonomy_objects']['shared'] = (object) array(
			'public' => true,
			'labels' => (object) array( 'name' => 'Shared Terms' ),
		);
		$GLOBALS['cybermaps_mock_terms']['shared'] = array(
			$this->term( 71, 'shared', 'Shared Term', 'shared-term' ),
		);

		$taxonomy_output = ( new ShortcodeHandler() )->render_shortcode(
			array(
				'only' => 'taxonomy:shared',
				'depth' => '-1',
			)
		);

		$this->assertSame( array(), $GLOBALS['cybermaps_mock_wp_query_args'] );
		$this->assertStringContainsString( 'Shared Term', $taxonomy_output );
		$this->assertSame( array( 'shared' ), array_column( $GLOBALS['cybermaps_mock_get_terms_args'], 'taxonomy' ) );

		$shared_post = (object) array(
			'ID'            => 701,
			'post_title'    => 'Shared Post',
			'post_name'     => 'shared-post',
			'post_type'     => 'shared',
			'post_parent'   => 0,
			'post_status'   => 'publish',
			'post_password' => '',
		);
		$GLOBALS['cybermaps_mock_posts'][701]      = $shared_post;
		$GLOBALS['cybermaps_mock_wp_query_posts'] = array( $shared_post );
		$GLOBALS['cybermaps_mock_wp_query_args']  = array();
		$GLOBALS['cybermaps_mock_get_terms_args'] = array();

		$post_output = ( new ShortcodeHandler() )->render_shortcode(
			array(
				'only' => 'post_type:shared',
				'depth' => '-1',
			)
		);

		$this->assertSame( array( 'shared' ), $GLOBALS['cybermaps_mock_wp_query_args'][0]['post_type'] );
		$this->assertSame( array(), $GLOBALS['cybermaps_mock_get_terms_args'] );
		$this->assertStringContainsString( 'Shared Post', $post_output );
	}

	public function test_namespaced_kind_wildcards_select_all_publishable_groups(): void {
		$GLOBALS['cybermaps_mock_terms'] = array(
			'category' => array(
				$this->term( 81, 'category', 'Guides', 'guides' ),
			),
			'post_tag' => array(
				$this->term( 82, 'post_tag', 'Launches', 'launches' ),
			),
		);

		$output = ( new ShortcodeHandler() )->render_shortcode(
			array(
				'only' => 'post_type:*,taxonomy:*',
				'depth' => '-1',
				'limit' => '20',
			)
		);

		$this->assertSame(
			array( 'post', 'page' ),
			$GLOBALS['cybermaps_mock_wp_query_args'][0]['post_type']
		);
		$this->assertSame(
			array( 'category', 'post_tag' ),
			array_column( $GLOBALS['cybermaps_mock_get_terms_args'], 'taxonomy' )
		);
		$this->assertStringContainsString( 'Guides', $output );
		$this->assertStringContainsString( 'Launches', $output );
	}

	public function test_bare_post_formats_section_uses_clear_label_and_rendered_count(): void {
		$GLOBALS['cybermaps_mock_taxonomies'][] = 'post_format';
		$GLOBALS['cybermaps_mock_taxonomy_objects']['post_format'] = (object) array(
			'public' => true,
			'label'  => 'Formats',
			'labels' => (object) array( 'name' => 'Formats' ),
		);
		$GLOBALS['cybermaps_mock_terms']['post_format'] = array(
			$this->term( 91, 'post_format', 'Aside', 'post-format-aside' ),
			$this->term( 92, 'post_format', 'Gallery', 'post-format-gallery' ),
		);

		$output = ( new ShortcodeHandler() )->render_shortcode(
			array(
				'only'   => 'taxonomy:post_format',
				'layout' => 'bare',
				'limit'  => '1',
			)
		);

		$this->assertStringContainsString(
			'<h4 class="cybermap-bare-section-head"><span class="cybermap-section-label">Post Formats</span><span class="cybermap-section-count">1</span></h4>',
			$output
		);
		$this->assertStringNotContainsString( '>Formats</', $output );
		$this->assertSame( 1, substr_count( $output, '<a href=' ) );
	}

	public function test_malformed_selection_attributes_are_bounded_without_broadening_scope(): void {
		$output = ( new ShortcodeHandler() )->render_shortcode(
			array(
				'only'   => array( 'page' ),
				'exclude' => array( '1' ),
			)
		);

		$this->assertSame( array(), $GLOBALS['cybermaps_mock_wp_query_args'] );
		$this->assertSame( array(), $GLOBALS['cybermaps_mock_get_terms_args'] );
		$this->assertStringContainsString( 'No pages found.', $output );

		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Sitemap/ShortcodeHandler.php' );
		$this->assertStringContainsString( 'MAX_ONLY_BYTES', $source );
		$this->assertStringContainsString( 'MAX_EXCLUDE_BYTES', $source );
		$this->assertStringContainsString( 'MAX_FILTER_TOKENS', $source );
	}

	/**
	 * @return object{ID:int,post_title:string,post_name:string,post_type:string,post_parent:int,post_status:string,post_password:string}
	 */
	private function post( int $id, string $title, string $slug, int $parent ): object {
		return (object) array(
			'ID'            => $id,
			'post_title'    => $title,
			'post_name'     => $slug,
			'post_type'     => 'page',
			'post_parent'   => $parent,
			'post_status'   => 'publish',
			'post_password' => '',
		);
	}

	/**
	 * @return object{term_id:int,taxonomy:string,name:string,slug:string,parent:int}
	 */
	private function term(
		int $id,
		string $taxonomy,
		string $name,
		string $slug,
		int $parent = 0
	): object {
		return (object) array(
			'term_id'  => $id,
			'taxonomy' => $taxonomy,
			'name'     => $name,
			'slug'     => $slug,
			'parent'   => $parent,
		);
	}

	/**
	 * @return string[]
	 */
	private function section_counts( string $output ): array {
		preg_match_all(
			'/<span class="cybermap-section-count">(\d+)<\/span>/',
			$output,
			$matches
		);

		return $matches[1] ?? array();
	}
}
