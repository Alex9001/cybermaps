<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Discovery\StaticHeaderManifest;
use PHPUnit\Framework\TestCase;

final class StaticHeaderManifestTest extends TestCase {
	public function test_advisory_snippets_emit_one_valid_quoted_etag_value(): void {
		$manifest = new StaticHeaderManifest();
		$method   = new \ReflectionMethod( StaticHeaderManifest::class, 'snippets' );
		$snippets = $method->invoke(
			$manifest,
			array(
				'/ai.json' => array(
					'mime'          => 'application/json',
					'cache_control' => 'public, max-age=3600, must-revalidate',
					'expose_headers' => 'ETag, Repr-Digest',
					'repr_digest'   => '',
					'etag'          => '"abc"',
					'last_modified' => '',
					'content_usage' => '',
					'tags'          => array( 'cm-site-a' ),
				),
			)
		);

		$this->assertStringContainsString( 'Header always set ETag "' . '\\"abc\\"' . '"', $snippets['apache'] );
		$this->assertStringContainsString( "add_header ETag '\"abc\"' always;", $snippets['nginx'] );
		$this->assertStringNotContainsString( 'ETag ""abc""', $snippets['apache'] );
	}

	public function test_nginx_dynamic_route_recipe_uses_the_standard_exact_path_front_controller_fallback(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Discovery/StaticHeaderManifest.php' );
		$this->assertStringContainsString( 'try_files $uri /index.php?$args;', $source );
		$this->assertStringNotContainsString( 'try_files "" /index.php', $source );
	}
}
