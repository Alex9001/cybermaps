<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Audit;

use Cybermaps\Audit\InternalLinkAnalyzer;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class InternalLinkBlockTest extends TestCase {
	protected function setUp(): void {
		require __DIR__ . '/fixtures/block-runtime.php';
		$GLOBALS['cybermaps_audit_parse_calls'] = array();
		$GLOBALS['cybermaps_audit_template_queries'] = array();
		$GLOBALS['cybermaps_audit_part_queries'] = array();
		$GLOBALS['wp_filesystem'] = new \CybermapsAuditFilesystem();
		$GLOBALS['wpdb'] = (object) array( 'last_error' => '' );
		$GLOBALS['cybermaps_mock_get_posts_callback'] = static fn( array $args ): array => array();
	}

	public function test_byte_guard_runs_before_parse_blocks(): void {
		$budget = array( 'remaining' => 20, 'bytes' => 10, 'complete' => true, 'page_list' => false );
		$this->collect( 'Elevenbytes', $budget );
		$this->assertFalse( $budget['complete'] );
		$this->assertSame( array(), $GLOBALS['cybermaps_audit_parse_calls'] );
	}

	public function test_marker_count_and_nesting_guards_run_before_parse_blocks(): void {
		foreach ( array( str_repeat( '<!-- wp:group -->', 9 ), str_repeat( '<!-- wp:navigation-link /-->', 3 ) ) as $content ) {
			$budget = array( 'remaining' => 2, 'bytes' => 1000, 'complete' => true, 'page_list' => false );
			$this->collect( $content, $budget );
			$this->assertFalse( $budget['complete'] );
		}
		$budget = array( 'remaining' => 20, 'bytes' => 1000, 'complete' => true, 'page_list' => false );
		$this->collect( str_repeat( '<!-- wp:group -->', 9 ), $budget );
		$this->assertFalse( $budget['complete'] );
		$this->assertSame( array(), $GLOBALS['cybermaps_audit_parse_calls'] );
	}

	public function test_children_are_traversed_without_serializing_and_reparsing(): void {
		$GLOBALS['cybermaps_audit_parsed']['group'] = array( array(
			'blockName' => 'core/group',
			'innerBlocks' => array( array( 'blockName' => 'core/navigation-link', 'attrs' => array( 'url' => '/target/' ) ) ),
		) );
		$budget = array( 'remaining' => 2, 'bytes' => 1000, 'complete' => true, 'page_list' => false );
		$sources = $this->collect( 'group', $budget );
		$this->assertSame( array( 'group' ), $GLOBALS['cybermaps_audit_parse_calls'] );
		$this->assertTrue( $budget['complete'] );
		$this->assertSame( 0, $budget['remaining'] );
		$this->assertSame( array( '/target/' => true ), $sources['fixture'] );
	}

	public function test_templates_use_explicit_slugs_and_only_referenced_parts_are_loaded(): void {
		$GLOBALS['cybermaps_audit_templates'] = array( 'page' => (object) array( 'content' => 'page', 'slug' => 'page' ) );
		$GLOBALS['cybermaps_audit_parts'] = array( 'fixture-theme//header' => (object) array( 'content' => 'header' ) );
		$GLOBALS['cybermaps_audit_parsed'] = array(
			'page' => array( array( 'blockName' => 'core/template-part', 'attrs' => array( 'slug' => 'header' ) ) ),
			'header' => array( array( 'blockName' => 'core/navigation-link', 'attrs' => array( 'url' => 'https://example.com/target/' ) ) ),
		);
		$result = ( new \ReflectionMethod( InternalLinkAnalyzer::class, 'navigation_targets' ) )->invoke( new InternalLinkAnalyzer(), array(), array( 'https://example.com/target' => 8 ) );
		$this->assertTrue( $result['complete'] );
		$this->assertSame( array( 'template:page' => true ), $result['targets'][8] );
		$this->assertCount( 2, $GLOBALS['wp_filesystem']->reads );
		$this->assertStringEndsWith( '/parts/header.html', $GLOBALS['wp_filesystem']->reads[1] );
		foreach ( $GLOBALS['cybermaps_mock_get_posts_args'] as $args ) {
			$this->assertLessThanOrEqual( 1001, $args['posts_per_page'] );
			$this->assertSame( 'publish', $args['post_status'] );
		}

	}

	public function test_template_candidate_limit_reports_incomplete_without_loading_all_templates(): void {
		$queries = array();
		$GLOBALS['cybermaps_mock_get_posts_callback'] = static function ( array $args ) use ( &$queries ): array {
			$queries[] = $args;
			return isset( $args['fields'] ) ? range( 1, 1001 ) : array();
		};
		$source = new \Cybermaps\Audit\StoredTemplateSource();
		iterator_to_array( $source->templates() );
		$this->assertFalse( $source->complete() );
		$this->assertSame( 1001, $queries[0]['posts_per_page'] );
		$this->assertCount( 1001, $queries );
		foreach ( array_slice( $queries, 1 ) as $query ) {
			$this->assertSame( 1, $query['posts_per_page'] );
		}
	}

	public function test_missing_referenced_template_part_marks_navigation_incomplete(): void {
		$GLOBALS['cybermaps_audit_parsed']['page'] = array( array( 'blockName' => 'core/template-part', 'attrs' => array( 'slug' => 'missing' ) ) );
		$budget = array( 'remaining' => 2, 'bytes' => 1000, 'complete' => true, 'page_list' => false );
		$this->collect( 'page', $budget );
		$this->assertFalse( $budget['complete'] );
	}

	public function test_theme_size_is_checked_before_content_read(): void {
		$GLOBALS['wp_filesystem']->reported_size = \Cybermaps\Audit\StoredTemplateSource::MAX_CONTENT_BYTES + 1;
		$source = new \Cybermaps\Audit\StoredTemplateSource();
		$this->assertSame( array(), iterator_to_array( $source->templates() ) );
		$this->assertFalse( $source->complete() );
		$this->assertSame( array(), $GLOBALS['wp_filesystem']->reads );
	}

	public function test_nonlocal_filesystem_is_incomplete_without_raw_fallback(): void {
		$GLOBALS['wp_filesystem']->method = 'ftpext';
		$source = new \Cybermaps\Audit\StoredTemplateSource();
		$this->assertSame( array(), iterator_to_array( $source->templates() ) );
		$this->assertFalse( $source->complete() );
		$this->assertSame( array(), $GLOBALS['wp_filesystem']->reads );
	}

	public function test_template_database_error_remains_incomplete_even_when_theme_scan_succeeds(): void {
		$GLOBALS['cybermaps_mock_get_posts_callback'] = static function ( array $args ): array {
			$GLOBALS['wpdb']->last_error = 'fixture unavailable';
			return array();
		};
		$source = new \Cybermaps\Audit\StoredTemplateSource();
		$this->assertCount( 1, iterator_to_array( $source->templates() ) );
		$this->assertFalse( $source->complete() );
	}

	public function test_metadata_name_budget_stops_before_hydration(): void {
		$source = new \Cybermaps\Audit\StoredTemplateSource();
		$budget = array( 'entries' => 0, 'bytes' => 1000, 'deadline' => microtime( true ) + 10 );
		$files = array();
		( new \ReflectionMethod( $source, 'collect_theme_names' ) )->invokeArgs( $source, array( __DIR__ . '/fixtures/theme/templates', '', $GLOBALS['wp_filesystem'], &$budget, &$files, 0 ) );
		$this->assertSame( array(), $files );
		$this->assertFalse( $source->complete() );
		$this->assertSame( array(), $GLOBALS['wp_filesystem']->reads );
	}

	public function test_theme_symlink_is_rejected_before_any_body_read(): void {
		$directory = dirname( __DIR__, 2 ) . '/docs/generated/tmp/audit-link-' . bin2hex( random_bytes( 4 ) );
		mkdir( $directory );
		symlink( __DIR__ . '/fixtures/theme/templates/page.html', $directory . '/escape.html' );
		try {
			$source = new \Cybermaps\Audit\StoredTemplateSource();
			$budget = array( 'entries' => 3, 'bytes' => 1000, 'deadline' => microtime( true ) + 10 );
			$files = array();
			( new \ReflectionMethod( $source, 'collect_theme_names' ) )->invokeArgs( $source, array( $directory, '', $GLOBALS['wp_filesystem'], &$budget, &$files, 0 ) );
			$this->assertSame( array(), $files );
			$this->assertFalse( $source->complete() );
			$this->assertSame( array(), $GLOBALS['wp_filesystem']->reads );
		} finally {
			unlink( $directory . '/escape.html' );
			rmdir( $directory );
		}
	}

	private function collect( string $content, array &$budget ): array {
		$analyzer = new InternalLinkAnalyzer();
		( new \ReflectionMethod( $analyzer, 'reset_source_budget' ) )->invoke( $analyzer );
		$sources = $visited = array();
		( new \ReflectionMethod( $analyzer, 'collect_block_urls' ) )->invokeArgs( $analyzer, array( $content, 'fixture', &$sources, &$visited, array(), &$budget, 0 ) );
		return $sources;
	}
}
