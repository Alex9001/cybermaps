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
}
