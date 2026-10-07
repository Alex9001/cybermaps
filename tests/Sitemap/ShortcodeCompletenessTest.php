<?php
declare(strict_types=1);

namespace Cybermaps\Sitemap {
	/** Test-only pagination seam; ordinary consumers use the shared WP stub. */
	function get_terms( array $args ): mixed {
		$callback = $GLOBALS['cybermaps_shortcode_terms_callback'] ?? null;
		return is_callable( $callback ) ? $callback( $args ) : \get_terms( $args );
	}
	/** Test-only count failure seam; other tests use the ordinary WP stub. */
	function wp_count_terms( array $args ): mixed {
		$callback = $GLOBALS['cybermaps_sitemap_term_count_callback'] ?? null;
		return is_callable( $callback ) ? $callback( $args ) : \wp_count_terms( $args );
	}

}

namespace Cybermaps\Tests\Sitemap {

use Cybermaps\Sitemap\ShortcodeHandler;
use PHPUnit\Framework\TestCase;

final class ShortcodeCompletenessTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['cybermaps_mock_options'] = array( 'blog_public' => '1', 'cybermaps_settings' => array( 'enable_shortcode' => '1' ) );
		$GLOBALS['cybermaps_mock_post_types'] = array();
		$GLOBALS['cybermaps_mock_taxonomies'] = array( 'category' );
		$GLOBALS['cybermaps_mock_taxonomy_objects'] = array( 'category' => (object) array( 'public' => true ) );
		$GLOBALS['cybermaps_mock_filter_callbacks'] = array();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['cybermaps_shortcode_terms_callback'], $GLOBALS['cybermaps_sitemap_term_count_callback'] );
		$GLOBALS['cybermaps_mock_filter_callbacks'] = array();
		parent::tearDown();
	}

	public function test_term_budget_exhaustion_does_not_claim_no_pages(): void {
		$queries = array();
		$GLOBALS['cybermaps_shortcode_terms_callback'] = static function ( array $args ) use ( &$queries ): array {
			$queries[] = $args;
			$id = $args['offset'] + 1;
			return $args['offset'] < 5000
				? array_fill( 0, $args['number'], (object) array( 'term_id' => $id, 'taxonomy' => 'category', 'name' => 'Excluded', 'count' => 1 ) )
				: array( (object) array( 'term_id' => 5001, 'taxonomy' => 'category', 'name' => 'Later eligible', 'count' => 1 ) );
		};
		$GLOBALS['cybermaps_mock_filter_callbacks']['cybermaps_publication_eligibility'] = array(
			static fn( $decision ) => $decision->with_reasons( array( 'test_exclusion' ) ),
		);
		$output = ( new ShortcodeHandler() )->render_shortcode( array( 'only' => 'category' ) );
		$this->assertStringContainsString( 'temporarily unavailable', $output );
		$this->assertStringNotContainsString( 'No pages found', $output );
		$this->assertCount( 20, $queries );
		$this->assertSame( 4750, $queries[19]['offset'] );
	}

	public function test_failed_term_query_is_unavailable_instead_of_complete_empty(): void {
		$GLOBALS['cybermaps_shortcode_terms_callback'] = static fn( array $args ) => new \WP_Error( 'query_failed', 'Database failed' );
		$output = ( new ShortcodeHandler() )->render_shortcode( array( 'only' => 'category' ) );
		$this->assertStringContainsString( 'temporarily unavailable', $output );
	}
	public function test_native_style_failed_empty_term_read_is_unavailable_and_recovers(): void {
		$previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = (object) array( 'last_error' => '' );
		$GLOBALS['cybermaps_shortcode_terms_callback'] = static function ( array $args ): array {
			self::assertFalse( $args['cache_results'] );
			$GLOBALS['wpdb']->last_error = 'Term SELECT failed';
			return array();
		};
		try {
			$output = ( new ShortcodeHandler() )->render_shortcode( array( 'only' => 'category' ) );
			$this->assertStringContainsString( 'temporarily unavailable', $output );
			$this->assertStringNotContainsString( 'No pages found', $output );
			$repository = new \Cybermaps\Sitemap\EligibleContentRepository();
			try {
				$repository->get_term_page( 'category', 1, 20 );
				$this->fail( 'Failed term read must not establish empty inventory.' );
			} catch ( \Cybermaps\Core\BuildUnavailableException ) {
				$this->addToAssertionCount( 1 );
			}
			$GLOBALS['cybermaps_shortcode_terms_callback'] = static fn( array $args ): array => array();
			$this->assertSame( array(), $repository->get_term_page( 'category', 1, 20 ) );
			$this->assertStringContainsString( 'No pages found', ( new ShortcodeHandler() )->render_shortcode( array( 'only' => 'category' ) ) );
		} finally {
			$GLOBALS['wpdb'] = $previous_wpdb;
		}
	}

	public function test_failed_term_counts_are_not_memoized_as_proven_zero(): void {
		$previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = (object) array( 'last_error' => '' );
		$repository = new \Cybermaps\Sitemap\EligibleContentRepository();
		try {
			foreach ( array( new \WP_Error( 'query_failed', 'SELECT failed' ), null, '0' ) as $failed_value ) {
				$GLOBALS['cybermaps_sitemap_term_count_callback'] = static function ( array $args ) use ( $failed_value ): mixed {
					self::assertFalse( $args['cache_results'] );
					$GLOBALS['wpdb']->last_error = '0' === $failed_value ? 'COUNT SELECT failed' : '';
					return $failed_value;
				};
				try {
					$repository->get_term_count( 'category' );
					$this->fail( 'Failed count must not prove zero terms.' );
				} catch ( \Cybermaps\Core\BuildUnavailableException ) {
					$this->addToAssertionCount( 1 );
				}
			}
			$GLOBALS['cybermaps_sitemap_term_count_callback'] = static fn( array $args ): string => '0';
			$this->assertSame( 0, $repository->get_term_count( 'category' ) );
			$GLOBALS['cybermaps_sitemap_term_count_callback'] = static fn( array $args ): string => '999';
			$this->assertSame( 0, $repository->get_term_count( 'category' ) );
		} finally {
			$GLOBALS['wpdb'] = $previous_wpdb;
		}
	}

}
}
