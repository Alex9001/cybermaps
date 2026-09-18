<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Core;

use Cybermaps\Core\ProtocolOutput;
use PHPUnit\Framework\TestCase;

final class ProtocolOutputTest extends TestCase {
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
