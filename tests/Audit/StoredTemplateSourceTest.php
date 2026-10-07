<?php
declare(strict_types=1);
namespace Cybermaps\Tests\Audit;

use Cybermaps\Audit\StoredTemplateSource;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class StoredTemplateSourceTest extends \PHPUnit\Framework\TestCase {
	protected function setUp(): void {
		require __DIR__ . '/fixtures/block-runtime.php';
		$GLOBALS['wpdb'] = new StoredTemplateWpdb();
		$GLOBALS['wp_hooks'] = array();
		$GLOBALS['cybermaps_mock_get_terms_args'] = array();
		$GLOBALS['cybermaps_mock_get_posts_args'] = array();
		$GLOBALS['wp_filesystem'] = new \CybermapsAuditFilesystem();
	}

	public function test_membership_is_prepared_and_scoped_to_exact_query_object(): void {
		$GLOBALS['cybermaps_mock_get_posts_callback'] = function ( array $args ): array {
			$this->assertFalse( $args['cache_results'] );
			$this->assertFalse( $args['update_post_meta_cache'] );
			$this->assertFalse( $args['update_post_term_cache'] );
			$this->assertFalse( $args['suppress_filters'] );
			$this->assertSame( 'publish', $args['post_status'] );
			$query = new StoredTemplateQueryView( $args );
			$hooks = $GLOBALS['wp_hooks'];
			usort( $hooks, static fn( $a, $b ) => $a['priority'] <=> $b['priority'] );
			foreach ( $hooks as $hook ) {
				if ( 'pre_get_posts' === $hook['hook'] ) { ( $hook['callback'] )( $query ); }
			}
			$copy = new StoredTemplateQueryView( $args );
			foreach ( $hooks as $hook ) {
				if ( 'posts_where' === $hook['hook'] ) {
					$this->assertSame( ' WHERE 1=1', ( $hook['callback'] )( ' WHERE 1=1', $copy ) );
					$sql = ( $hook['callback'] )( ' WHERE 1=1', $query );
					$this->assertStringContainsString( 'EXISTS', $sql );
					$this->assertStringContainsString( '`wp_term_relationships`', $sql );
					$this->assertStringContainsString( '`wp_posts`.ID', $sql );
					$this->assertStringContainsString( '= 171', $sql );
				}
				if ( 'split_the_query' === $hook['hook'] ) {
					$this->assertFalse( ( $hook['callback'] )( true, $query ) );
					$this->assertTrue( ( $hook['callback'] )( true, $copy ) );
				}
			}
			return array( 2 );
		};
		$source = new StoredTemplateSource();
		$result = ( new \ReflectionMethod( $source, 'stored_ids' ) )->invoke( $source );
		$this->assertSame( array( 2 ), $result );
		$this->assertTrue( $source->complete() );
		$this->assertSame( array(), $GLOBALS['wp_hooks'] );
		$this->assertSame( array( '%i', '%i', '%d' ), $GLOBALS['wpdb']->placeholders );
		$terms = $GLOBALS['cybermaps_mock_get_terms_args'][0];
		$this->assertSame( array( 'fixture-theme' ), $terms['name'] );
		$this->assertSame( 1, $terms['number'] );
		$this->assertFalse( $terms['hierarchical'] );
		$this->assertSame( 0, $terms['child_of'] );
		$this->assertSame( '', $terms['parent'] );
		$this->assertFalse( $terms['cache_results'] );
	}

	public function test_throwing_query_removes_all_scoped_hooks(): void {
		$GLOBALS['cybermaps_mock_get_posts_callback'] = static function (): array { throw new \RuntimeException( 'query extension failed' ); };
		try { ( new \ReflectionMethod( StoredTemplateSource::class, 'stored_ids' ) )->invoke( new StoredTemplateSource() ); $this->fail( 'Expected extension exception.' ); }
		catch ( \RuntimeException $exception ) { $this->assertSame( 'query extension failed', $exception->getMessage() ); }
		$this->assertSame( array(), $GLOBALS['wp_hooks'] );
	}

	public function test_invalid_theme_lookup_marks_incomplete_without_post_query(): void {
		foreach ( array( new \WP_Error( 'db_failed' ), array( (object) array( 'term_taxonomy_id' => 171, 'taxonomy' => 'wp_theme', 'name' => 'inactive-theme' ) ) ) as $terms ) {
			$GLOBALS['cybermaps_mock_terms']['wp_theme'] = $terms;
			$source = new StoredTemplateSource();
			$this->assertSame( array(), ( new \ReflectionMethod( $source, 'stored_ids' ) )->invoke( $source ) );
			$this->assertFalse( $source->complete() );
		}
		$this->assertSame( array(), $GLOBALS['cybermaps_mock_get_posts_args'] );
		$this->assertSame( array(), $GLOBALS['wp_hooks'] );
	}

	public function test_raw_stored_part_is_not_built_and_result_contract_is_rechecked(): void {
		$raw = (object) array( 'ID' => 2, 'post_type' => 'wp_template_part', 'post_status' => 'publish', 'post_name' => 'header', 'post_content' => '<!-- wp:paragraph --><p>literal</p><!-- /wp:paragraph -->' );
		$GLOBALS['cybermaps_mock_get_posts_callback'] = static fn() => array( $raw );
		$this->assertSame( $raw->post_content, ( new StoredTemplateSource() )->part( 'header', 'fixture-theme' ) );
		foreach ( array( array( 'post_status', 'draft' ), array( 'post_type', 'post' ), array( 'post_name', 'other' ), array( 'post_content', array() ) ) as list( $key, $value ) ) {
			$invalid = clone $raw; $invalid->$key = $value;
			$GLOBALS['cybermaps_mock_get_posts_callback'] = static fn() => array( $invalid );
			$source = new StoredTemplateSource();
			$this->assertNull( ( new \ReflectionMethod( $source, 'stored_post' ) )->invoke( $source, array( 'post_name__in' => array( 'header' ) ), 'wp_template_part', 'fixture-theme' ) );
			$this->assertFalse( $source->complete() );
		}
	}
}
final class StoredTemplateWpdb {
	public string $last_error = '';
	public string $posts = 'wp_posts';
	public string $term_relationships = 'wp_term_relationships';
	public array $placeholders = array();
	public function prepare( string $sql, mixed ...$values ): string {
		preg_match_all( '/%[id]/', $sql, $matches ); $this->placeholders = $matches[0];
		foreach ( $values as $value ) {
			$sql = preg_replace_callback( '/%[id]/', static fn( $match ) => '%i' === $match[0] ? '`' . $value . '`' : (string) (int) $value, $sql, 1 );
		}
		return $sql;
	}
}
final class StoredTemplateQueryView extends \WP_Query {
	public function __construct( private array $args ) {}
	public function get( string $key ): mixed { return $this->args[$key] ?? null; }
	public function set( string $key, mixed $value ): void { $this->args[$key] = $value; }
}
