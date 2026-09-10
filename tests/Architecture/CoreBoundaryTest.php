<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Prevent the standalone Core boundary from drifting back into a split build.
 */
final class CoreBoundaryTest extends TestCase {

	private string $project_root;

	protected function setUp(): void {
		$this->project_root = dirname( __DIR__, 2 );
	}

	public function test_core_contains_no_licensing_or_preprocessor_runtime(): void {
		$contents = $this->runtime_contents();

		foreach (
			array(
				'/\bcyb_pro\s*\(/',
				'/@fs_[a-z_]+/',
				'/\bfs_[a-z_]+/',
				'/__premium_only/',
				'/freemius\/wordpress-sdk/',
				'/\bUpgradePromo\b/',
			) as $pattern
		) {
			$this->assertDoesNotMatchRegularExpression( $pattern, $contents );
		}
	}

	public function test_release_versions_are_synchronized(): void {
		$main   = (string) file_get_contents( $this->project_root . '/cybermaps.php' );
		$readme = (string) file_get_contents( $this->project_root . '/readme.txt' );
		$manifest = json_decode(
			(string) file_get_contents( $this->project_root . '/docs/dev/manifest.json' ),
			true
		);

		preg_match( '/^[ \t*]*Version:\s*([0-9.]+)/mi', $main, $header );
		preg_match( '/define\(\s*[\'"]CYBERMAPS_VERSION[\'"]\s*,\s*[\'"]([0-9.]+)[\'"]\s*\)/', $main, $constant );
		preg_match( '/^Stable tag:\s*([0-9.]+)/mi', $readme, $stable );

		$this->assertNotEmpty( $header[1] ?? '' );
		$this->assertSame( $header[1], $constant[1] ?? null );
		$this->assertSame( $header[1], $stable[1] ?? null );
		$this->assertIsArray( $manifest );
		$this->assertSame( $header[1], $manifest['version'] ?? null );
	}

	public function test_manifest_rest_routes_match_the_endpoint_registry(): void {
		$manifest = json_decode(
			(string) file_get_contents( $this->project_root . '/docs/dev/manifest.json' ),
			true
		);
		$this->assertIsArray( $manifest );

			$core_route_ids = array(
				'rest_root',
				'public_health',
				'rest_mcp',
				'rest_mcp_server_card',
			'rest_llms_tldr',
			'rest_search',
			'rest_urls',
			'rest_status',
			'rest_audit_latest',
			'rest_audit_run',
			'rest_purge',
		);
		$expected = array();
		$registry = \Cybermaps\Core\EndpointRegistry::get_instance();
		foreach ( $core_route_ids as $endpoint_id ) {
			$endpoint = $registry->get_rest_route( $endpoint_id );
			$this->assertNotNull( $endpoint );
			$namespace = $endpoint['namespace'];
			$route     = $endpoint['route'];
			$expected[] = array(
				'namespace' => $namespace,
				'route'     => $route,
				'full'      => '/wp-json/' . $namespace . $route,
			);
		}

		$this->assertSame( $expected, $manifest['rest_api_routes'] ?? null );
	}

	public function test_manifest_class_inventory_uses_real_declarations(): void {
		$manifest = json_decode(
			(string) file_get_contents( $this->project_root . '/docs/dev/manifest.json' ),
			true
		);
		$this->assertIsArray( $manifest );

		$classes = array_column( $manifest['source_classes'] ?? array(), 'fqcn' );
		$this->assertContains( 'Cybermaps\\Core\\CrawlerRegistry', $classes );
		$this->assertContains( 'Cybermaps\\Discovery\\DiscoveryIndex', $classes );
		$this->assertNotContains( 'Cybermaps\\Discovery\\BaseHandler', $classes );
		$this->assertNotContains( 'Cybermaps\\Core\\provides', $classes );
		$this->assertNotContains( 'Cybermaps\\Discovery\\handles', $classes );
		$this->assertCount( (int) ( $manifest['class_count'] ?? -1 ), $classes );
	}

	public function test_manifest_discovery_metadata_matches_served_endpoints(): void {
		$manifest = json_decode(
			(string) file_get_contents( $this->project_root . '/docs/dev/manifest.json' ),
			true
		);
		$this->assertIsArray( $manifest );

		$endpoints = array();
		foreach ( $manifest['discovery_endpoints'] ?? array() as $endpoint ) {
			$endpoints[ $endpoint['path'] ] = $endpoint;
		}

			$this->assertSame(
				'application/linkset+json',
			$endpoints['/.well-known/api-catalog']['type'] ?? null
			);
			$this->assertSame(
				'application/json',
				$endpoints['/.well-known/ai-catalog.json']['type'] ?? null
			);
		$this->assertSame(
			'Bounded public search over the configured, indexable AI publication inventory.',
			$endpoints['/wp-json/cybermaps/v1/search']['desc'] ?? null
		);
		$this->assertArrayNotHasKey( '/cybermaps/v1/search', $endpoints );
	}

	public function test_manifest_includes_literal_and_variable_static_inventory(): void {
		$manifest = json_decode(
			(string) file_get_contents( $this->project_root . '/docs/dev/manifest.json' ),
			true
		);
		$this->assertIsArray( $manifest );

		$this->assertContains( 'ai.json', $manifest['static_files'] ?? array() );
		$this->assertContains( 'llms.txt', $manifest['static_files'] ?? array() );
		$this->assertContains( '{index_child}.xml', $manifest['static_files'] ?? array() );
		$this->assertContains( '{rss_base}.xml', $manifest['static_files'] ?? array() );
		$this->assertStringContainsString(
			'Dynamic delivery only',
			(string) ( $manifest['static_engine']['multisite'] ?? '' )
		);
	}

	public function test_release_php_avoids_direct_file_stream_writes(): void {
		$this->assertDoesNotMatchRegularExpression(
			'/\b(?:fopen|fwrite|fclose|file_put_contents)\s*\(/',
			$this->runtime_contents()
		);
	}

	public function test_admin_php_contains_no_inline_script_or_event_handler(): void {
		$admin = $this->php_directory_contents( $this->project_root . '/src/Admin' );

		$this->assertDoesNotMatchRegularExpression( '/<script\b/i', $admin );
		$this->assertDoesNotMatchRegularExpression( '/\bon(?:click|change|input|submit)\s*=/i', $admin );
	}

	private function runtime_contents(): string {
		return implode(
			"\n",
			array(
				(string) file_get_contents( $this->project_root . '/cybermaps.php' ),
				(string) file_get_contents( $this->project_root . '/uninstall.php' ),
				(string) file_get_contents( $this->project_root . '/composer.json' ),
				$this->php_directory_contents( $this->project_root . '/src' ),
			)
		);
	}

	private function php_directory_contents( string $directory ): string {
		$contents = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, RecursiveDirectoryIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( $file instanceof SplFileInfo && 'php' === $file->getExtension() ) {
				$contents[] = (string) file_get_contents( $file->getPathname() );
			}
		}

		return implode( "\n", $contents );
	}
}
