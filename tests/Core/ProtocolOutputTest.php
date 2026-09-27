<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Core;

use Cybermaps\Core\ProtocolOutput;
use PHPUnit\Framework\TestCase;

final class ProtocolOutputTest extends TestCase {
	public function test_json_emission_preserves_canonical_bytes_and_object_types(): void {
		$payload = array( 'object' => (object) array(), 'list' => array(), 'unicode' => 'Résumé', 'url' => 'https://example.com/?a=1&b=2', 'text' => '<script>alert("x")</script>' );
		$document = ProtocolOutput::json( $payload );
		ob_start();
		ProtocolOutput::emit( $document, 'json' );
		$emitted = ob_get_clean();
		self::assertSame( $document, $emitted );
		self::assertSame( hash( 'sha256', $document ), hash( 'sha256', $emitted ) );
		self::assertInstanceOf( \stdClass::class, json_decode( $emitted )->object );
	}

	public function test_xml_and_markdown_are_not_html_escaped(): void {
		foreach ( array( 'xml' => '<?xml version="1.0"?><urlset><url><loc>https://example.com/?a=1&amp;b=2</loc></url></urlset>', 'text' => "# Résumé\n[Link](https://example.com/?a=1&b=2)\n" ) as $protocol => $document ) {
			ob_start();
			ProtocolOutput::emit( $document, $protocol );
			self::assertSame( $document, ob_get_clean() );
		}
	}

	public function test_public_json_is_compact_and_round_trips_structured_values(): void {
		$output = ProtocolOutput::json(
			array(
				'url'   => 'https://example.com/path',
				'label' => 'Résumé',
			)
		);

		self::assertStringNotContainsString( "\n", $output );
		self::assertSame(
			array( 'url' => 'https://example.com/path', 'label' => 'Résumé' ),
			json_decode( $output, true, 512, JSON_THROW_ON_ERROR )
		);
	}

	public function test_json_emitter_rejects_an_invalid_document(): void {
		$this->expectException( \UnexpectedValueException::class );
		ProtocolOutput::emit( '{invalid', 'json' );
	}

	public function test_xml_emitter_rejects_document_type_and_entity_declarations(): void {
		$this->expectException( \UnexpectedValueException::class );
		ProtocolOutput::emit( '<!DOCTYPE root [<!ENTITY x "unsafe">]><root>&x;</root>', 'xml' );
	}

	public function test_text_and_csv_emitters_remove_null_bytes(): void {
		ob_start();
		ProtocolOutput::emit( "safe\0text", 'text' );
		ProtocolOutput::emit( "one,\0two\r\n", 'csv' );
		$output = ob_get_clean();

		self::assertSame( "safetextone,two\r\n", $output );
	}
}
