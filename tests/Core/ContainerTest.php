<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Core;

use Cybermaps\Core\Container;
use PHPUnit\Framework\TestCase;

class ContainerTest extends TestCase {
	public function test_get_throws_for_unknown_service(): void {
		$container = new Container();
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'not-a-service' );
		$container->get( 'not-a-service' );
	}

	public function test_get_resolves_factory(): void {
		$container = new Container();
		$container->set( 'demo', fn() => 'ok' );
		$this->assertSame( 'ok', $container->get( 'demo' ) );
		$this->assertSame( 'ok', $container->get( 'demo' ) );
	}
}
