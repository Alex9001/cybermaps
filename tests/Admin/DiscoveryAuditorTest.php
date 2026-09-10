<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\DiscoveryAuditor;
use ReflectionMethod;

final class DiscoveryAuditorTest extends \WP_UnitTestCase {
	private mixed $original_wpdb;

	protected function setUp(): void {
		parent::setUp();

		$this->original_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = new DiscoveryAuditorWpdbStub();
		$GLOBALS['cybermaps_mock_post_types'] = array( 'post', 'page' );
		$GLOBALS['cybermaps_mock_options'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->original_wpdb;
		unset(
			$GLOBALS['cybermaps_mock_post_types'],
			$GLOBALS['cybermaps_mock_options']
		);

		parent::tearDown();
	}

	public function test_explicit_content_structures_drive_the_matching_profiles_first(): void {
		$cases = array(
			'ecommerce'      => array( 'product' => 4, 'docs' => 30 ),
			'knowledgebase'  => array( 'documentation' => 8, 'portfolio' => 20 ),
			'corporate'      => array( 'project' => 6, 'service' => 15 ),
			'small-business' => array( 'services' => 12, 'post' => 1200 ),
			'newspaper'      => array( 'press_release' => 5, 'page' => 100 ),
		);

		foreach ( $cases as $expected => $post_types ) {
			$this->assertSame(
				$expected,
				$this->determine_archetype(
					array(
						'post_types'   => $post_types,
						'total_posts'  => array_sum( $post_types ),
					)
				),
				$expected
			);
		}
	}

	public function test_volume_without_a_post_led_inventory_does_not_invent_a_news_profile(): void {
		$this->assertSame(
			'medium-business',
			$this->determine_archetype(
				array(
					'post_types'   => array( 'page' => 1201 ),
					'total_posts'  => 1201,
				)
			)
		);
	}

	public function test_editorial_profiles_require_a_post_led_inventory(): void {
		$this->assertSame(
			'blog',
			$this->determine_archetype(
				array(
					'post_types'   => array( 'post' => 101, 'page' => 99 ),
					'total_posts'  => 200,
				)
			)
		);
		$this->assertSame(
			'medium-business',
			$this->determine_archetype(
				array(
					'post_types'   => array( 'post' => 100, 'page' => 100 ),
					'total_posts'  => 200,
				)
			)
		);
		$this->assertSame(
			'newspaper',
			$this->determine_archetype(
				array(
					'post_types'   => array( 'post' => 1100, 'page' => 100 ),
					'total_posts'  => 1200,
				)
			)
		);
		$this->assertSame(
			'blog',
			$this->determine_archetype(
				array(
					'post_types'   => array( 'post' => 20, 'page' => 2 ),
					'total_posts'  => 22,
				)
			),
			'Small post-led sites should remain Blog / Editorial regardless of comment settings.'
		);
		$this->assertSame(
			'blog',
			$this->determine_archetype(
				array(
					'post_types'   => array( 'post' => 600 ),
					'total_posts'  => 600,
				)
			),
			'Established post-led sites should remain Blog / Editorial regardless of comment settings.'
		);
	}

	public function test_profiles_never_disable_posts_as_an_implicit_default(): void {
		$auditor = new DiscoveryAuditor();

		foreach ( array_keys( $auditor->get_all_archetypes() ) as $profile ) {
			$this->assertGreaterThan(
				0,
				$auditor->get_archetype_defaults( $profile )['post'],
				$profile
			);
		}
	}

	public function test_detected_store_and_news_types_receive_matching_profile_defaults(): void {
		$auditor = new DiscoveryAuditor();
		$store = $auditor->get_archetype_defaults( 'ecommerce' );
		$store_intents = $auditor->get_archetype_intents( 'ecommerce' );
		$news = $auditor->get_archetype_defaults( 'newspaper' );

		$this->assertSame( 1.0, $store['download'] );
		$this->assertSame( 0.9, $store['download_category'] );
		$this->assertSame( 0.6, $store['download_tag'] );
		$this->assertSame( 'transactional', $store_intents['download'] );
		$this->assertSame( 'transactional', $store_intents['download_category'] );
		$this->assertSame( 'transactional', $store_intents['download_tag'] );

		foreach ( array( 'news', 'press', 'press_release', 'press-release' ) as $post_type ) {
			$this->assertSame( 0.9, $news[ $post_type ], $post_type );
		}
	}

	public function test_unknown_and_malformed_profiles_use_the_business_intent_baseline(): void {
		$auditor = new DiscoveryAuditor();
		$expected = $auditor->get_archetype_intents( 'medium-business' );

		$this->assertSame( $expected, $auditor->get_archetype_intents( 'unknown-profile' ) );
		$this->assertSame( $expected, $auditor->get_archetype_intents( array( 'blog' ) ) );
		$this->assertSame( 'transactional', $expected['page'] );
	}

	public function test_database_failure_does_not_become_an_empty_site_suggestion(): void {
		$GLOBALS['wpdb']->last_error = 'Inventory query failed';

		$this->assertSame( array(), $this->perform_scan() );
	}

	public function test_recommendation_reason_reports_the_decisive_evidence(): void {
		$auditor = new DiscoveryAuditor();
		$method = new ReflectionMethod( DiscoveryAuditor::class, 'recommendation_reason' );

		$catalog_reason = (string) $method->invoke(
			$auditor,
			array(
				'post_types'  => array( 'product' => 12 ),
				'total_posts' => 20,
			)
		);
		$this->assertStringContainsString( '12 published catalog items', $catalog_reason );
		$this->assertStringContainsString( 'Online Store', $catalog_reason );

		$documentation_reason = (string) $method->invoke(
			$auditor,
			array(
				'post_types'   => array( 'docs' => 7 ),
				'total_posts'  => 7,
			)
		);
		$this->assertStringContainsString( '7 published items in documentation post types', $documentation_reason );
		$this->assertStringContainsString( 'Documentation / Knowledge Base', $documentation_reason );

		$mixed_reason = (string) $method->invoke(
			$auditor,
			array(
				'post_types'   => array( 'post' => 30, 'page' => 30 ),
				'total_posts'  => 60,
			)
		);
		$this->assertStringContainsString( 'Company / Mixed Content', $mixed_reason );
		$this->assertStringNotContainsString( 'Portfolio / Agency', $mixed_reason );

		$blog_reason = (string) $method->invoke(
			$auditor,
			array(
				'post_types'   => array( 'post' => 20, 'page' => 2 ),
				'total_posts'  => 22,
			)
		);
		$this->assertStringContainsString( '20 of 22 published items', $blog_reason );
		$this->assertStringContainsString( 'Blog / Editorial', $blog_reason );

		$news_reason = (string) $method->invoke(
			$auditor,
			array(
				'post_types'   => array( 'press-release' => 3, 'page' => 100 ),
				'total_posts'  => 103,
			)
		);
		$this->assertStringContainsString( '3 published items in news or press post types', $news_reason );
		$this->assertStringContainsString( 'News / Magazine', $news_reason );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function perform_scan(): array {
		$method = new ReflectionMethod( DiscoveryAuditor::class, 'perform_scan' );
		$result = $method->invoke( new DiscoveryAuditor() );
		return is_array( $result ) ? $result : array();
	}

	/**
	 * @param array<string,mixed> $stats Scan statistics.
	 */
	private function determine_archetype( array $stats ): string {
		$method = new ReflectionMethod( DiscoveryAuditor::class, 'determine_archetype' );
		return (string) $method->invoke( new DiscoveryAuditor(), $stats );
	}

}

final class DiscoveryAuditorWpdbStub {
	public string $posts = 'wp_posts';
	public string $last_error = '';

	/** @var object[] */
	public array $post_count_rows = array();

	/** @var array<int,array{query:string,arguments:array<int,mixed>}> */
	public array $prepared_queries = array();

	public function prepare( string $query, mixed ...$arguments ): string {
		if ( 1 === count( $arguments ) && is_array( $arguments[0] ) ) {
			$arguments = $arguments[0];
		}
		$this->prepared_queries[] = array(
			'query'     => $query,
			'arguments' => array_values( $arguments ),
		);
		return $query;
	}

	/**
	 * @return object[]
	 */
	public function get_results( string $query ): array {
		if ( str_contains( $query, 'GROUP BY post_type' ) ) {
			return $this->post_count_rows;
		}
		return array();
	}

	/**
	 * @return array{query:string,arguments:array<int,mixed>}|null
	 */
	public function prepared_query_containing( string $needle ): ?array {
		foreach ( $this->prepared_queries as $prepared ) {
			if ( str_contains( $prepared['query'], $needle ) ) {
				return $prepared;
			}
		}
		return null;
	}
}
