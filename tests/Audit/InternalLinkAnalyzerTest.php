<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Audit;

use Cybermaps\Audit\InternalLinkAnalyzer;
use PHPUnit\Framework\TestCase;

final class InternalLinkAnalyzerTest extends TestCase {
	private mixed $previous_wpdb;

	protected function setUp(): void {
		$this->previous_wpdb                         = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']                             = new InternalLinkAnalyzerWpdbStub( 7 ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated database stub restored in tearDown().
		$GLOBALS['cybermaps_mock_options']           = array(
			'blog_public'        => '1',
			'show_on_front'      => 'page',
			'page_on_front'      => 1,
			'cybermaps_settings' => array(),
		);
		$GLOBALS['cybermaps_mock_post_meta']         = array();
		$GLOBALS['cybermaps_mock_post_types']        = array( 'page' );
		$GLOBALS['cybermaps_mock_post_type_objects'] = array(
			'page' => (object) array( 'public' => true ),
		);
		$GLOBALS['cybermaps_mock_posts']             = array(
			1 => $this->post( 1, 'Home', '<a href="/a/?utm_source=homepage#start">A</a>' ),
			2 => $this->post( 2, 'A', '<a href="/b/">B</a>' ),
			3 => $this->post( 3, 'B', '<a href="/deep/">Deep</a>' ),
			4 => $this->post( 4, 'Deep', '' ),
			5 => $this->post( 5, 'Orphan', '' ),
			6 => $this->post( 6, 'Island A', '<a href="/island-b/">Island B</a>' ),
			7 => $this->post( 7, 'Island B', '<a href="/island-a/">Island A</a>' ),
		);
		$GLOBALS['cybermaps_mock_permalinks']        = array(
			1 => 'https://example.com/',
			2 => 'https://example.com/a/',
			3 => 'https://example.com/b/',
			4 => 'https://example.com/deep/',
			5 => 'https://example.com/orphan/',
			6 => 'https://example.com/island-a/',
			7 => 'https://example.com/island-b/',
		);
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->previous_wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the test runner's database stub.
		unset( $GLOBALS['cybermaps_mock_permalinks'] );
	}

	public function test_reports_orphans_unreachable_islands_and_three_click_depth(): void {
		$result = ( new InternalLinkAnalyzer() )->analyze( array( 'page' ) );

		$this->assertTrue( $result['analysis']['complete'] );
		$this->assertSame( 5, $result['analysis']['edge_count'] );
		$this->assertSame( 'deeply_linked', $result['findings'][4][0]['key'] );
		$this->assertSame( 3, $result['findings'][4][0]['evidence']['homepage_click_depth'] );
		$this->assertSame( 'potential_orphan', $result['findings'][5][0]['key'] );
		$this->assertSame( 'no_homepage_path', $result['findings'][6][0]['key'] );
		$this->assertSame( 'no_homepage_path', $result['findings'][7][0]['key'] );
		$this->assertArrayNotHasKey( 1, $result['findings'] );
	}

	private function post( int $id, string $title, string $content ): object {
		return (object) array(
			'ID'                => $id,
			'post_type'         => 'page',
			'post_status'       => 'publish',
			'post_password'     => '',
			'post_title'        => $title,
			'post_content'      => $content,
			'post_excerpt'      => '',
			'post_date_gmt'     => '2026-01-01 00:00:00',
			'post_modified_gmt' => '2026-01-01 00:00:00',
		);
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test-only database collaborator lives beside its single consumer.
final class InternalLinkAnalyzerWpdbStub {
	public string $posts      = 'wp_posts';
	public string $last_error = '';

	public function __construct( private readonly int $maximum_id ) {}

	public function prepare( string $query, mixed ...$args ): string {
		foreach ( $args as $arg ) {
			preg_match( '/%[dis]/', $query, $placeholder );
			$replacement = '%i' === ( $placeholder[0] ?? '' )
				? (string) $arg
				: ( is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'" );
			$query       = (string) preg_replace( '/%[dis]/', $replacement, $query, 1 );
		}
		return $query;
	}

	public function get_var( string $query ): int {
		unset( $query );
		return $this->maximum_id;
	}

	/** @return int[] */
	public function get_col( string $query ): array {
		preg_match( '/ID > (\d+)/', $query, $after_match );
		preg_match( '/ID <= (\d+)/', $query, $maximum_match );
		preg_match( '/LIMIT (\d+)/', $query, $limit_match );
		$after   = (int) ( $after_match[1] ?? 0 );
		$maximum = (int) ( $maximum_match[1] ?? $this->maximum_id );
		$limit   = (int) ( $limit_match[1] ?? 100 );
		return array_slice( range( $after + 1, $maximum ), 0, $limit );
	}
}
