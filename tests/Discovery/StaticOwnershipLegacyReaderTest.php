<?php
declare(strict_types=1);
namespace Cybermaps\Tests\Discovery;
use Cybermaps\Discovery\StaticOwnershipLegacyReader;
final class StaticOwnershipLegacyReaderTest extends \WP_UnitTestCase {
	public function test_large_legacy_array_decodes_in_bounded_chunks_and_resumes(): void {
		$records = array();
		for ( $i = 0; $i < 2000; ++$i ) { $records[ 'path/' . $i . str_repeat( 'x', 150 ) ] = md5( (string) $i ); }
		$bytes = serialize( $records );
		$requests = array();
		$read = static function ( int $offset, int $length ) use ( $bytes, &$requests ): string { $requests[] = $length; return substr( $bytes, $offset, $length ); };
		$reader = new StaticOwnershipLegacyReader( $read, strlen( $bytes ) );
		self::assertSame( 2000, $reader->begin() );
		foreach ( $records as $path => $hash ) {
			self::assertSame( array( 'path' => $path, 'hash' => $hash, 'generation' => 9 ), $reader->next( 0, 9 ) );
			$reader = new StaticOwnershipLegacyReader( $read, strlen( $bytes ), $reader->position() );
		}
		self::assertTrue( $reader->finish() );
		self::assertLessThanOrEqual( 65536, max( $requests ) );
	}
	public function test_schema_two_record_field_order_and_hash_shorthand_are_supported(): void {
		$bytes = serialize( array( 'a' => array( 'generation' => 8, 'hash' => str_repeat( 'A', 32 ) ), 'b' => str_repeat( 'b', 32 ) ) );
		$reader = new StaticOwnershipLegacyReader( static fn( $offset, $length ) => substr( $bytes, $offset, $length ), strlen( $bytes ) );
		self::assertSame( 2, $reader->begin() );
		self::assertSame( array( 'path' => 'a', 'hash' => str_repeat( 'a', 32 ), 'generation' => 8 ), $reader->next( 2, 0 ) );
		self::assertSame( array( 'path' => 'b', 'hash' => str_repeat( 'b', 32 ), 'generation' => 0 ), $reader->next( 2, 0 ) );
		self::assertTrue( $reader->finish() );
	}
	public function test_malformed_and_object_payloads_fail_without_decoding(): void {
		foreach ( array( 'O:8:"stdClass":0:{}', 'a:1:{s:1:"a";O:8:"stdClass":0:{}}', 'a:1:{s:999999999999999999999:"a";}', 'a:1:{s:1:"a";a:2:{s:4:"hash";s:32:"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa";s:10:"generation";i:-1;}}' ) as $bytes ) {
			$reader = new StaticOwnershipLegacyReader( static fn( $offset, $length ) => substr( $bytes, $offset, $length ), strlen( $bytes ) );
			if ( null !== $reader->begin() ) { self::assertNull( $reader->next( 2, 0 ) ); }
			self::assertFalse( $reader->finish() );
		}
	}
}
