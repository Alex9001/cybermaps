<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Sitemap;

use Cybermaps\Sitemap\XmlWriter;
use PHPUnit\Framework\TestCase;

final class XmlWriterFallbackTest extends TestCase {
	public function test_extension_free_writer_emits_escaped_balanced_xml(): void {
		$writer = new XmlWriter( true );
		$writer->startDocument( '1.0', 'UTF-8' );
		$writer->writePI( 'xml-stylesheet', 'type="text/xsl" href="https://example.com/a?x=1&y=2"' );
		$writer->startElement( 'urlset' );
		$writer->writeAttribute( 'xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9' );
		$writer->writeAttribute( 'xmlns:xhtml', 'http://www.w3.org/1999/xhtml' );
		$writer->writeAttribute( 'data-label', 'A & "B"' );
		$writer->startElement( 'url' );
		$writer->writeElement( 'loc', 'https://example.com/?a=1&b=<two>' );
		$writer->startElement( 'xhtml:link' );
		$writer->writeAttribute( 'rel', 'alternate' );
		$writer->endElement();
		$writer->endElement();
		$writer->endElement();
		$writer->endDocument();

		$xml = $writer->outputMemory();
		$this->assertSame(
			'<?xml version="1.0" encoding="UTF-8"?>'
				. '<?xml-stylesheet type="text/xsl" href="https://example.com/a?x=1&y=2"?>'
				. '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'
				. ' xmlns:xhtml="http://www.w3.org/1999/xhtml" data-label="A &amp; &quot;B&quot;">'
				. '<url><loc>https://example.com/?a=1&amp;b=&lt;two&gt;</loc>'
				. '<xhtml:link rel="alternate"/></url></urlset>',
			$xml
		);
		if ( \class_exists( \DOMDocument::class ) ) {
			$this->assertTrue( ( new \DOMDocument() )->loadXML( $xml ) );
		}
	}

	public function test_extension_free_writer_rejects_invalid_names(): void {
		$this->expectException( \InvalidArgumentException::class );

		( new XmlWriter( true ) )->startElement( 'bad element' );
	}

	public function test_fallback_replaces_forbidden_xml_scalars_and_invalid_utf8(): void {
		$value = "A\x01\x0B\u{FFFE}\u{FFFF}\xC3\x28B\t\n\r\u{1F600}";
		$writer = new XmlWriter( true );
		$writer->startDocument();
		$writer->startElement( 'root' );
		$writer->writeAttribute( 'label', $value );
		$writer->writeElement( 'text', $value );
		$writer->endDocument();

		$xml = $writer->outputMemory();
		$this->assertStringNotContainsString( "\x01", $xml );
		$this->assertStringNotContainsString( "\x0B", $xml );
		$this->assertStringNotContainsString( "\u{FFFE}", $xml );
		$this->assertStringNotContainsString( "\u{FFFF}", $xml );
		$this->assertStringContainsString( "\u{FFFD}", $xml );
		$this->assertStringContainsString( "B\t\n\r\u{1F600}", $xml );
		if ( \class_exists( \DOMDocument::class ) ) {
			$this->assertTrue( ( new \DOMDocument() )->loadXML( $xml, LIBXML_NONET ) );
		}
	}

	public function test_fallback_and_native_replace_controls_with_the_same_scalar(): void {
		if ( ! \class_exists( \XMLWriter::class ) || ! \class_exists( \DOMDocument::class ) ) {
			$this->markTestSkipped( 'Native writer and DOM are required for semantic comparison.' );
		}
		$values = array();
		foreach ( array( true, false ) as $fallback ) {
			$writer = new XmlWriter( $fallback );
			$writer->startDocument();
			$writer->startElement( 'root' );
			$writer->writeAttribute( 'label', "A\x01\u{FFFE}\u{FFFF}\xC3\x28B &amp; <x>" );
			$writer->writeElement( 'text', "A\x01\u{FFFE}\u{FFFF}\xC3\x28B &amp; <x>" );
			$writer->endDocument();
			$dom = new \DOMDocument();
			$this->assertTrue( $dom->loadXML( $writer->outputMemory(), LIBXML_NONET ) );
			$values[] = array( $dom->documentElement->getAttribute( 'label' ), $dom->getElementsByTagName( 'text' )->item( 0 )->textContent );
		}
		$this->assertSame( $values[0], $values[1] );
		$this->assertSame( array( "A\u{FFFD}\u{FFFD}\u{FFFD}\u{FFFD}(B &amp; <x>", "A\u{FFFD}\u{FFFD}\u{FFFD}\u{FFFD}(B &amp; <x>" ), $values[0] );
	}
	public function test_fallback_rejects_unmatched_close(): void {
		$this->expectException( \LogicException::class );
		( new XmlWriter( true ) )->endElement();
	}

	public function test_fallback_rejects_attribute_after_closed_start(): void {
		$writer = new XmlWriter( true );
		$writer->startElement( 'root' );
		$writer->writeElement( 'child', 'text' );
		$this->expectException( \LogicException::class );
		$writer->writeAttribute( 'late', 'value' );
	}
}
