<?php
declare(strict_types=1);

namespace Cybermaps\Discovery {
	/** Controlled read-only filesystem seam; ordinary tests retain the WP stub. */
	function WP_Filesystem(): bool {
		$filesystem = $GLOBALS['cybermaps_header_manifest_filesystem'] ?? null;
		if ( is_object( $filesystem ) ) {
			$GLOBALS['wp_filesystem'] = $filesystem;
			return true;
		}
		return \WP_Filesystem();
	}
}

namespace Cybermaps\Tests\Discovery {

use Cybermaps\Discovery\StaticHeaderManifest;
use PHPUnit\Framework\TestCase;

final class StaticHeaderManifestTest extends TestCase {
	private mixed $previous_filesystem;

	protected function setUp(): void {
		$this->previous_filesystem = $GLOBALS['wp_filesystem'] ?? null;
		$GLOBALS['cybermaps_header_manifest_filesystem'] = new class() {
			public array $files = array();
			public bool $regular_file = true;

			public function exists( string $path ): bool {
				return isset( $this->files[ basename( $path ) ] );
			}

			public function is_file( string $path ): bool {
				return $this->regular_file && $this->exists( $path );
			}

			public function get_contents( string $path ): string|false {
				return $this->files[ basename( $path ) ] ?? false;
			}

			public function mtime( string $path ): int {
				return 1785369600;
			}
		};
	}

	protected function tearDown(): void {
		unset( $GLOBALS['cybermaps_header_manifest_filesystem'] );
		$GLOBALS['wp_filesystem'] = $this->previous_filesystem;
		parent::tearDown();
	}

	public function test_header_guidance_does_not_override_origin_errors_or_private_responses(): void {
		$snippets = ( new \ReflectionMethod( StaticHeaderManifest::class, 'snippets' ) )->invoke(
			new StaticHeaderManifest(),
			array( '/ai.json' => array( 'etag' => '"frozen-etag"', 'repr_digest' => 'frozen-digest', 'cache_control' => 'public, max-age=3600' ) )
		);
		foreach ( array( 'apache', 'nginx', 'litespeed' ) as $server ) {
			foreach ( explode( "\n", $snippets[ $server ] ) as $line ) {
				$this->assertStringStartsWith( '# ', $line, 'Header guidance must contain no executable overrides or routing blocks.' );
			}
			$this->assertStringNotContainsString( 'frozen-etag', $snippets[ $server ] );
			$this->assertStringNotContainsString( 'frozen-digest', $snippets[ $server ] );
			$this->assertStringNotContainsString( 'public, max-age=3600', $snippets[ $server ] );
		}
		$cdn = json_decode( $snippets['cdn'], true );
		$this->assertSame( '/ai.json', $cdn['path'] );
		$this->assertArrayNotHasKey( 'headers', $cdn );
		$this->assertSame( 'preserve_origin', $cdn['action']['response_headers'] );
		$this->assertSame( 'respect_origin', $cdn['action']['cache_control'] );
		$this->assertStringNotContainsString( 'frozen-etag', $snippets['cdn'] );
		$this->assertStringNotContainsString( 'frozen-digest', $snippets['cdn'] );
	}

	public function test_enabled_unmaterialized_candidates_remain_observational_for_consumers(): void {
		$manifest = ( new StaticHeaderManifest() )->get_manifest( array( 'enable_discovery_hub' => '1' ) );
		$this->assertSame( 'origin', $manifest['header_authority'] );
		$this->assertArrayHasKey( '/ai.json', $manifest['policies'] );
		$policy = $manifest['policies']['/ai.json'];
		$this->assertTrue( $policy['snapshot_only'] );
		$this->assertFalse( $policy['existence_snapshot'] );
		$this->assertSame( '', $policy['etag'] );
		$this->assertSame( '', $policy['repr_digest'] );
	}

	public function test_disabled_publications_do_not_appear_as_active_candidates(): void {
		$manifest = ( new StaticHeaderManifest() )->get_manifest( array( 'enable_llms_full' => '0' ) );
		$this->assertArrayNotHasKey( '/llms-full.txt', $manifest['policies'] );
		$this->assertStringContainsString( '/.well-known/api-catalog', $manifest['snippets']['routing']['nginx'] );
	}

	public function test_snapshot_changes_and_file_removal_never_change_header_configuration(): void {
		$filesystem = $GLOBALS['cybermaps_header_manifest_filesystem'];
		$filesystem->files['ai.json'] = '{"revision":1}';
		$manifest = new StaticHeaderManifest();
		$first = $manifest->get_manifest( array( 'enable_discovery_hub' => '1' ) );
		$this->assertTrue( $first['policies']['/ai.json']['existence_snapshot'] );
		$filesystem->files['ai.json'] = '{"revision":2}';
		$second = $manifest->get_manifest( array( 'enable_discovery_hub' => '1' ) );
		$this->assertNotSame( $first['policies']['/ai.json']['etag'], $second['policies']['/ai.json']['etag'] );
		$this->assertNotSame( $first['policies']['/ai.json']['repr_digest'], $second['policies']['/ai.json']['repr_digest'] );
		$this->assertSame( $first['snippets'], $second['snippets'] );
		unset( $filesystem->files['ai.json'] );
		$third = $manifest->get_manifest( array( 'enable_discovery_hub' => '1' ) );
		$this->assertFalse( $third['policies']['/ai.json']['existence_snapshot'] );
		$this->assertSame( $first['snippets'], $third['snippets'] );
	}

	public function test_directory_is_not_observed_as_static_response_bytes(): void {
		$filesystem = $GLOBALS['cybermaps_header_manifest_filesystem'];
		$filesystem->files['ai.json'] = 'directory-fixture';
		$filesystem->regular_file = false;
		$policy = ( new StaticHeaderManifest() )->get_manifest( array( 'enable_discovery_hub' => '1' ) )['policies']['/ai.json'];
		$this->assertFalse( $policy['existence_snapshot'] );
		$this->assertSame( '', $policy['etag'] );
	}

	public function test_protocol_canonical_paths_and_aliases_have_dynamic_routing_recipes(): void {
		$manifest = new StaticHeaderManifest();
		$paths = ( new \ReflectionMethod( StaticHeaderManifest::class, 'dynamic_routing_paths' ) )->invoke( $manifest );
		$policies = $manifest->get_manifest()['policies'];
		foreach ( array( '/ai-discovery', '/.well-known/api-catalog', '/api-catalog', '/.well-known/ai-catalog.json', '/.well-known/mcp/server-card.json' ) as $path ) {
			$this->assertContains( $path, $paths );
			$this->assertArrayNotHasKey( $path, $policies );
		}
	}

	public function test_nginx_dynamic_routes_reach_wordpress_even_when_a_conflicting_file_is_retained(): void {
		$rules = ( new StaticHeaderManifest() )->get_manifest()['snippets']['routing']['nginx'];
		$this->assertStringContainsString( 'rewrite ^ /index.php last;', $rules );
		$this->assertStringNotContainsString( 'try_files', $rules );
	}
}
}
