<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Discovery\Search;

class SearchTest extends \WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['cybermaps_mock_options'] = array(
			'blog_public' => '1',
			'cybermaps_settings' => array(
				'enable_discovery_hub' => '1',
				'llms_included_types'  => array( 'post' ),
			),
			'cybermaps_discovery_center' => wp_json_encode(
				array(
					'overrides' => array( 'post' => 0.5 ),
				)
			),
		);
		$GLOBALS['cybermaps_mock_post_types'] = array( 'post' );
		$GLOBALS['cybermaps_mock_post_type_objects'] = array(
			'post' => (object) array(
				'name'   => 'post',
				'public' => true,
			),
		);
		$GLOBALS['cybermaps_mock_posts']                     = array();
		$GLOBALS['cybermaps_mock_post_meta']                 = array();
		$GLOBALS['cybermaps_mock_transients']                = array();
		$GLOBALS['cybermaps_mock_wp_query_args']             = array();
		$GLOBALS['cybermaps_mock_current_user_capabilities'] = array();
		$_SERVER['REMOTE_ADDR'] = '203.0.113.50';
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['cybermaps_mock_wp_query_callback'],
			$GLOBALS['cybermaps_mock_current_user_capabilities'],
			$_SERVER['REMOTE_ADDR']
		);
		wp_reset_postdata();

		parent::tearDown();
	}

	/**
	 * @dataProvider limit_provider
	 */
	public function test_normalize_limit( $value, int $expected ): void {
		$this->assertSame( $expected, Search::normalize_limit( $value ) );
	}

	public function test_public_query_input_is_scalar_nonempty_and_bounded(): void {
		$this->assertSame( '', Search::normalize_query( array( 'unsafe' ) ) );
		$this->assertFalse( Search::is_valid_query( array( 'unsafe' ) ) );
		$this->assertFalse( Search::is_valid_query( " \n " ) );
		$this->assertTrue( Search::is_valid_query( str_repeat( 'q', 200 ) ) );
		$this->assertFalse( Search::is_valid_query( str_repeat( 'q', 201 ) ) );
		$this->assertFalse( Search::is_valid_query( str_repeat( '界', 100 ) ) );
		$this->assertSame( 200, strlen( Search::normalize_query( str_repeat( 'q', 201 ) ) ) );
		$this->assertLessThanOrEqual(
			200,
			strlen( Search::normalize_query( str_repeat( '界', 100 ) ) )
		);
	}

	public function test_rate_limit_identifier_is_salted_and_not_raw_md5(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/src/Discovery/Search.php'
		);

		$this->assertStringContainsString( 'ClientIPResolver::get_ip()', $source );
		$this->assertStringNotContainsString( "\$_SERVER['REMOTE_ADDR']", $source );
		$this->assertStringContainsString( 'AtomicMinuteCounter::requester_bucket', $source );
		$this->assertStringNotContainsString( 'md5( $ip )', $source );
	}

	public function test_search_uses_canonical_publication_query_and_no_fake_relevance(): void {
		$source = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/src/Discovery/Search.php'
		);

		$this->assertStringContainsString( 'new PublicationInventory( $settings )', $source );
		$this->assertStringContainsString( '$inventory->get_query_args(', $source );
		$this->assertStringNotContainsString( "'relevance' => 1.0", $source );
	}

	public function test_search_pages_past_excluded_candidates_to_fill_results(): void {
		for ( $post_id = 1; $post_id <= 251; ++$post_id ) {
			$GLOBALS['cybermaps_mock_posts'][ $post_id ] = (object) array(
				'ID'                => $post_id,
				'post_type'         => 'post',
				'post_status'       => 'publish',
				'post_password'     => '',
				'post_title'        => 'Search result ' . $post_id,
				'post_content'      => 'Stored searchable content for result ' . $post_id,
				'post_excerpt'      => 'Excerpt ' . $post_id,
				'post_date_gmt'     => '2026-07-01 00:00:00',
				'post_modified_gmt' => '2026-07-28 00:00:00',
			);
			if ( $post_id <= 250 ) {
				$GLOBALS['cybermaps_mock_post_meta'][ $post_id ]['_cybermaps_exclude_ai'] = '1';
			}
		}

		$GLOBALS['cybermaps_mock_wp_query_callback'] = static function ( array $args ): array {
			$page     = max( 1, (int) ( $args['paged'] ?? 1 ) );
			$per_page = max( 1, (int) ( $args['posts_per_page'] ?? 250 ) );
			return array_slice(
				array_values( $GLOBALS['cybermaps_mock_posts'] ),
				( $page - 1 ) * $per_page,
				$per_page
			);
		};

		$request = new class() {
			public function get_param( string $key ): mixed {
				return array(
					'q'     => 'searchable',
					'limit' => 1,
				)[ $key ] ?? null;
			}
		};

		$response = ( new Search() )->handle_search( $request );
		$data     = $response->get_data();

		$this->assertSame( 1, $data['total'] );
		$this->assertSame( 'Search result 251', $data['results'][0]['title'] );
		$this->assertCount( 2, $GLOBALS['cybermaps_mock_wp_query_args'] );
		$this->assertSame( 250, $GLOBALS['cybermaps_mock_wp_query_args'][0]['posts_per_page'] );
		$this->assertSame( 2, $GLOBALS['cybermaps_mock_wp_query_args'][1]['paged'] );
		$this->assertFalse( $GLOBALS['cybermaps_mock_wp_query_args'][0]['has_password'] );
	}

	public function test_search_bounds_large_stored_titles_and_manual_excerpts(): void {
		$post = (object) array(
			'ID'                => 1,
			'post_type'         => 'post',
			'post_status'       => 'publish',
			'post_password'     => '',
			'post_title'        => str_repeat( 'T', 1000 ),
			'post_content'      => '',
			'post_excerpt'      => str_repeat( 'E', 3000 ),
			'post_date_gmt'     => '2026-07-01 00:00:00',
			'post_modified_gmt' => '2026-07-28 00:00:00',
		);
		$GLOBALS['cybermaps_mock_posts'][1] = $post;
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['enable_content_hints'] = '0';
		$GLOBALS['cybermaps_mock_wp_query_callback'] = static fn( array $args ): array => array( $post );
		$request = new class() {
			public function get_param( string $key ): mixed {
				return 'q' === $key ? 'bounded' : 1;
			}
		};

		$data = ( new Search() )->handle_search( $request )->get_data();

		$this->assertSame( 512, strlen( $data['results'][0]['title'] ) );
		$this->assertSame( 1024, strlen( $data['results'][0]['snippet'] ) );
	}

	/**
	 * @return array<string, array{mixed, int}>
	 */
	public static function limit_provider(): array {
		return array(
			'default-null' => array( null, 20 ),
			'default-empty' => array( '', 20 ),
			'requested'     => array( 50, 50 ),
			'minimum'       => array( 0, 1 ),
			'maximum'       => array( 500, 100 ),
		);
	}
}
