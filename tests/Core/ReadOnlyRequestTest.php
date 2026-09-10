<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Core;

use Cybermaps\Core\ReadOnlyRequest;
use PHPUnit\Framework\TestCase;

final class ReadOnlyRequestTest extends TestCase {
	public function test_only_get_and_head_are_allowed(): void {
		$this->assertTrue( ReadOnlyRequest::is_allowed( 'GET' ) );
		$this->assertTrue( ReadOnlyRequest::is_allowed( 'head' ) );
		$this->assertFalse( ReadOnlyRequest::is_allowed( 'POST' ) );
		$this->assertFalse( ReadOnlyRequest::is_allowed( 'OPTIONS' ) );
	}

	public function test_method_defaults_to_get_and_normalizes_input(): void {
		$original = $_SERVER['REQUEST_METHOD'] ?? null;
		unset( $_SERVER['REQUEST_METHOD'] );
		$this->assertSame( 'GET', ReadOnlyRequest::method() );

		$_SERVER['REQUEST_METHOD'] = 'head';
		$this->assertSame( 'HEAD', ReadOnlyRequest::method() );
		$this->assertTrue( ReadOnlyRequest::is_head() );

		if ( null === $original ) {
			unset( $_SERVER['REQUEST_METHOD'] );
		} else {
			$_SERVER['REQUEST_METHOD'] = $original;
		}
	}

	public function test_malformed_method_shape_fails_closed_without_a_php_warning(): void {
		$original = $_SERVER['REQUEST_METHOD'] ?? null;
		$_SERVER['REQUEST_METHOD'] = array( 'GET' );

		set_error_handler(
			static function ( int $severity, string $message, string $file, int $line ): never {
				throw new \ErrorException( $message, 0, $severity, $file, $line );
			}
		);
		try {
			$this->assertSame( '', ReadOnlyRequest::method() );
			$this->assertFalse( ReadOnlyRequest::is_allowed( ReadOnlyRequest::method() ) );
			$this->assertFalse( ReadOnlyRequest::is_head() );
		} finally {
			restore_error_handler();
			if ( null === $original ) {
				unset( $_SERVER['REQUEST_METHOD'] );
			} else {
				$_SERVER['REQUEST_METHOD'] = $original;
			}
		}
	}
}
