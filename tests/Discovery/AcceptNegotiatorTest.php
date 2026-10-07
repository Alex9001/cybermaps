<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Discovery\AcceptNegotiator;
use PHPUnit\Framework\TestCase;

final class AcceptNegotiatorTest extends TestCase {
	/** @dataProvider preference_cases */
	public function test_rfc_quality_and_explicit_markdown_selection( mixed $header, bool $expected ): void {
		self::assertSame( $expected, AcceptNegotiator::prefers_markdown( $header ) );
	}

	/** @return array<string,array{mixed,bool}> */
	public static function preference_cases(): array {
		return array(
			'explicit only'       => array( 'text/markdown', true ),
			'unrelated higher q'  => array( 'text/markdown;q=0.5, application/json;q=1', true ),
			'unrelated first'     => array( 'application/json;q=1, text/markdown;q=0.1', true ),
			'html veto with unrelated' => array( 'text/markdown;q=0.5, application/json;q=1, text/html;q=0', true ),
			'matching wildcard higher q' => array( 'text/markdown;q=0.5, application/json;q=1, text/*;q=0.8', false ),
			'explicit tie'        => array( 'text/html, text/markdown', true ),
			'markdown preferred'  => array( 'text/html;q=0.5, text/markdown;q=0.9', true ),
			'html preferred'      => array( 'text/markdown;q=0.5, text/html', false ),
			'markdown rejected'   => array( 'text/markdown;q=0, text/html;q=0.5', false ),
			'wildcard only'       => array( '*/*', false ),
			'text wildcard only'  => array( 'text/*', false ),
			'explicit plus star'  => array( 'text/markdown, */*', true ),
			'invalid quality'     => array( 'text/markdown;q=2', false ),
			'header injection'    => array( "text/markdown\r\nX-Test: bad", false ),
			'non scalar'          => array( array( 'text/markdown' ), false ),
		);
	}

	public function test_oversized_accept_header_is_rejected(): void {
		self::assertFalse( AcceptNegotiator::prefers_markdown( 'text/markdown,' . str_repeat( 'x', 8192 ) ) );
	}
}
