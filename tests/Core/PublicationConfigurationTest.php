<?php
declare(strict_types=1);
namespace Cybermaps\Tests\Core;

final class PublicationConfigurationTest extends \PHPUnit\Framework\TestCase {
	public function test_production_snapshot_bypasses_stale_memos_and_native_option_cache_with_native_filters(): void {
		$process = proc_open( array( PHP_BINARY, dirname( __DIR__ ) . '/fixtures/publication-configuration-snapshot.php' ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $process );
		$output = stream_get_contents( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$this->assertSame( 0, proc_close( $process ), $error );
		$result = json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
		$this->assertSame( array( 'exclude_post_ids' => '' ), $result['old_memo'][0] );
		$this->assertSame( array( array( 'exclude_post_ids' => '7' ), array( 'disabled' => array( 'post_type:post' => true ) ) ), $result['fresh'] );
		$this->assertSame( $result['fresh'], $result['refreshed_memo'] );
		$this->assertSame( array( 'pre_option_cybermaps_settings', 'pre_option', 'option_cybermaps_settings', 'pre_option_cybermaps_discovery_center', 'pre_option', 'option_cybermaps_discovery_center' ), array_column( $result['hooks'], 0 ) );
		$this->assertSame( array( 'exclude_post_ids' => '9' ), $result['pre_short_circuit'] );
		$this->assertSame( 0, $result['pre_short_circuit_reads'] );
		$this->assertSame( array( 'passed_default' => true ), $result['absent_default'] );
		$this->assertTrue( $result['sql_failure_unavailable'] );
		$this->assertSame( array( 'filtered' => '7', 'name' => 'cybermaps_settings' ), $result['present_filter'] );
	}
}
