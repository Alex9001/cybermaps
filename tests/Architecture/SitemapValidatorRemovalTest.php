<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Architecture;

use PHPUnit\Framework\TestCase;

final class SitemapValidatorRemovalTest extends TestCase {
	public function test_unused_validator_is_absent_from_runtime_and_generator_assumptions(): void {
		$root = dirname( __DIR__, 2 );

		$this->assertFileDoesNotExist( $root . '/src/Sitemap/SitemapValidator.php' );
		$this->assertFalse( class_exists( 'Cybermaps\\Sitemap\\SitemapValidator' ) );
		$this->assertStringNotContainsString(
			'SitemapValidator',
			(string) file_get_contents( $root . '/docs/dev/generate-docs.php' )
		);
	}
}
