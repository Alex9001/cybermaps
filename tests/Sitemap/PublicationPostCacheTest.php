<?php
declare(strict_types=1);
namespace Cybermaps\Tests\Sitemap;

final class PublicationPostCacheTest extends \PHPUnit\Framework\TestCase {
	public function test_bounded_priming_is_failure_aware_and_preserves_cache_contracts(): void {
		$process = proc_open( array( PHP_BINARY, dirname( __DIR__ ) . '/fixtures/publication-post-cache.php' ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $process );
		$output = stream_get_contents( $pipes[1] ); $error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] ); fclose( $pipes[2] );
		$this->assertSame( 0, proc_close( $process ), $error );
		$result = json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
		$this->assertSame( 3, $result['bulk']['queries'] );
		$this->assertSame( 201, $result['bulk']['posts'] );
		$this->assertSame( 201, $result['bulk']['meta'] );
		$this->assertTrue( $result['bulk']['existing_post'] );
		$this->assertSame( array( 'kept' => array( 'existing' ) ), $result['bulk']['existing_meta'] );
		$this->assertSame( array( 'duplicate' => array( 'first', 'second' ), 'serialized' => array( 'a:1:{i:0;s:3:"raw";}' ) ), $result['bulk']['raw_values'] );
		$this->assertFalse( $result['bulk']['suspended'] );
		$this->assertSame( array( 0, false, 3 ), $result['false_flag'] );
		$this->assertTrue( $result['failed'] );
		$this->assertSame( array(), $result['failure_cache'] );
		$this->assertFalse( $result['failure_suspended'] );
		$this->assertFalse( $result['exception_suspended'] );
		$this->assertSame( array( true, 0, array() ), $result['already_suspended'] );
		$this->assertSame( array( false, 3 ), $result['short_0'] );
		$this->assertSame( array( false, 3 ), $result['short_1'] );
		$this->assertSame( array(), $result['unrelated'] );
		$this->assertSame( 'raw', $result['raw_post'] );
		$this->assertSame( array( 0, array() ), $result['partial_projection'] );
		$this->assertSame( array( 0, array() ), $result['missing_slug'] );
	}
}
