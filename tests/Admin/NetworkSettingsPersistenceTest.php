<?php
declare(strict_types=1);
namespace Cybermaps\Tests\Admin;

final class NetworkSettingsPersistenceTest extends \PHPUnit\Framework\TestCase {
	public function test_failed_write_does_not_redirect_to_success(): void {
		$result = $this->run_save( 'failure' );
		self::assertSame( array( 'enable_master_index' => '0' ), $result['stored'] );
		self::assertStringContainsString( 'cybermaps_network_save_error=true', $result['redirect'] );
		self::assertStringNotContainsString( 'updated=true', $result['redirect'] );
		self::assertSame( array( 'nonce:cybermaps_network_settings_save', 'write', 'read' ), $result['calls'] );
	}
	public function test_unchanged_value_is_verified_before_success(): void {
		$result = $this->run_save( 'unchanged' );
		self::assertSame( array( 'enable_master_index' => '1' ), $result['stored'] );
		self::assertStringContainsString( 'updated=true', $result['redirect'] );
		self::assertSame( array( 'nonce:cybermaps_network_settings_save', 'write', 'read' ), $result['calls'] );
	}
	public function test_successful_write_retains_the_normal_success_redirect(): void {
		$result = $this->run_save( 'success' );
		self::assertSame( array( 'enable_master_index' => '1' ), $result['stored'] );
		self::assertStringContainsString( 'updated=true', $result['redirect'] );
		self::assertSame( array( 'nonce:cybermaps_network_settings_save', 'write' ), $result['calls'] );
	}
	private function run_save( string $mode ): array {
		$lines = array();
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/fixtures/network-settings-save.php' ) . ' ' . escapeshellarg( $mode ), $lines, $status );
		self::assertSame( 0, $status );
		return json_decode( implode( "\n", $lines ), true, 512, JSON_THROW_ON_ERROR );
	}
}
