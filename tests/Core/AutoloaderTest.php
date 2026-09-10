<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Core;

use Cybermaps\Autoloader;
use PHPUnit\Framework\TestCase;

final class AutoloaderTest extends TestCase {
	public function test_valid_core_class_resolves_inside_the_source_root(): void {
		$file = $this->resolve_file( \Cybermaps\Core\CrawlerMatch::class );

		$this->assertSame(
			dirname( __DIR__, 2 ) . '/src/Core/CrawlerMatch.php',
			$file
		);
	}

	/**
	 * @dataProvider invalid_class_names
	 */
	public function test_invalid_or_traversal_shaped_class_names_are_ignored( string $class ): void {
		$this->assertSame( '', $this->resolve_file( $class ) );
	}

	public static function invalid_class_names(): array {
		return array(
			'foreign namespace' => array( 'Other\\Core\\Container' ),
			'namespace root'    => array( 'Cybermaps\\' ),
			'traversal'         => array( 'Cybermaps\\..\\..\\cybermaps' ),
			'hyphen'            => array( 'Cybermaps\\Core\\Invalid-Class' ),
			'empty segment'     => array( 'Cybermaps\\\\Core\\Container' ),
			'forward slash'     => array( 'Cybermaps\\Core/Container' ),
			'null byte'         => array( "Cybermaps\\Core\\Container\0Outside" ),
			'missing file'      => array( 'Cybermaps\\Core\\DefinitelyMissing' ),
		);
	}

	private function resolve_file( string $class ): string {
		$method = new \ReflectionMethod( Autoloader::class, 'resolve_file' );
		return (string) $method->invoke( null, $class );
	}
}
