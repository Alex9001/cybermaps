<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Audit;

use Cybermaps\Audit\PublishedPostSource;
use PHPUnit\Framework\TestCase;

final class PublishedPostSourceTest extends TestCase {
	private mixed $previous_wpdb;

	protected function setUp(): void {
		$this->previous_wpdb = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb']     = new PublishedPostSourceWpdbStub( 205 );
		$GLOBALS['cybermaps_mock_posts']          = array();
		$GLOBALS['cybermaps_mock_get_posts_args'] = array();

		for ( $id = 1; $id <= 206; ++$id ) {
			if ( 100 === $id ) {
				continue;
			}
			$GLOBALS['cybermaps_mock_posts'][ $id ] = (object) array(
				'ID'          => $id,
				'post_type'   => 'post',
				'post_status' => 'publish',
			);
		}
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->previous_wpdb;
		unset( $GLOBALS['cybermaps_mock_get_posts_args'] );
	}

	public function test_batches_use_a_fixed_maximum_and_keyset_ids(): void {
		$batches = iterator_to_array( ( new PublishedPostSource() )->batches( array( 'post' ) ), false );
		$ids     = array_map(
			static fn( object $post ): int => (int) $post->ID,
			array_merge( ...$batches )
		);

		$this->assertSame( array( 99, 100, 5 ), array_map( 'count', $batches ) );
		$this->assertCount( 204, $ids );
		$this->assertNotContains( 100, $ids );
		$this->assertNotContains( 206, $ids );
		$this->assertSame( 205, max( $ids ) );

		$sql = implode( "\n", $GLOBALS['wpdb']->queries );
		$this->assertStringContainsString( 'SELECT MAX(ID)', $sql );
		$this->assertStringContainsString( 'WHERE ID > 0', $sql );
		$this->assertStringContainsString( 'WHERE ID > 100', $sql );
		$this->assertStringContainsString( 'WHERE ID > 200', $sql );
		$this->assertStringNotContainsString( 'OFFSET', $sql );

		foreach ( $GLOBALS['cybermaps_mock_get_posts_args'] as $args ) {
			$this->assertArrayHasKey( 'post__in', $args );
			$this->assertArrayNotHasKey( 'paged', $args );
			$this->assertLessThanOrEqual( 100, count( $args['post__in'] ) );
		}
	}

	public function test_database_read_errors_do_not_create_a_false_empty_report(): void {
		$GLOBALS['wpdb']->fail_next_get_var = true;

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'could not establish the content report boundary' );
		iterator_to_array( ( new PublishedPostSource() )->batches( array( 'post' ) ), false );
	}

	public function test_attached_images_are_resolved_with_one_bounded_parent_query(): void {
		$posts = array(
			(object) array( 'ID' => 4 ),
			(object) array( 'ID' => 9 ),
			(object) array( 'ID' => 12 ),
		);
		$GLOBALS['wpdb']->attached_parent_ids = array( 4, 12 );

		$parents = ( new PublishedPostSource() )->attached_image_parent_ids( $posts );

		$this->assertSame( array( 4 => true, 12 => true ), $parents );
		$query = end( $GLOBALS['wpdb']->queries );
		$this->assertIsString( $query );
		$this->assertStringContainsString( 'SELECT DISTINCT post_parent', $query );
		$this->assertStringContainsString( 'post_parent IN (4,9,12)', $query );
		$this->assertStringContainsString( "post_mime_type LIKE 'image/%'", $query );
		$this->assertStringContainsString( "post_status <> 'trash'", $query );
	}
}

/**
 * Minimal posts-table keyset query surface.
 */
final class PublishedPostSourceWpdbStub {
	public string $posts = 'wp_posts';
	public string $last_error = '';
	public bool $fail_next_get_var = false;
	/** @var array<int,int> */
	public array $attached_parent_ids = array();
	/** @var array<int,string> */
	public array $queries = array();

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
		$this->queries[] = $query;
		if ( $this->fail_next_get_var ) {
			$this->fail_next_get_var = false;
			$this->last_error        = 'Database unavailable';
			return 0;
		}
		return $this->maximum_id;
	}

	/**
	 * @return array<int,int>
	 */
	public function get_col( string $query ): array {
		$this->queries[] = $query;
		if ( str_contains( $query, 'SELECT DISTINCT post_parent' ) ) {
			return $this->attached_parent_ids;
		}
		preg_match( '/ID > (\\d+)/', $query, $after_match );
		preg_match( '/ID <= (\\d+)/', $query, $maximum_match );
		preg_match( '/LIMIT (\\d+)/', $query, $limit_match );
		$after   = (int) ( $after_match[1] ?? 0 );
		$maximum = (int) ( $maximum_match[1] ?? $this->maximum_id );
		$limit   = (int) ( $limit_match[1] ?? 100 );

		return array_slice( range( $after + 1, $maximum ), 0, $limit );
	}
}
