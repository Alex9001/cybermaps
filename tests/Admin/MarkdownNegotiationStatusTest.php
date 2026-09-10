<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\MarkdownNegotiationStatus;
use PHPUnit\Framework\TestCase;

final class MarkdownNegotiationStatusTest extends TestCase {
	public function test_origin_variants_are_healthy(): void {
		$status = MarkdownNegotiationStatus::evaluate(
			$this->response( 'text/markdown; charset=utf-8', 'Accept', '725', 'origin', '# Page' ),
			$this->response( 'text/html; charset=utf-8', 'Accept', '', '', '<html></html>' ),
			'https://example.com/'
		);
		self::assertSame( 'healthy', $status['status'] );
		self::assertSame( 'origin', $status['provider'] );
		self::assertSame( 725, $status['tokens'] );
	}

	public function test_missing_origin_marker_is_reported_as_edge_delivery(): void {
		$status = MarkdownNegotiationStatus::evaluate(
			$this->response( 'text/markdown', 'Accept', '10', '', '# Edge' ),
			$this->response( 'text/html', 'Accept', '', '', '<html></html>' ),
			'https://example.com/'
		);
		self::assertSame( 'healthy', $status['status'] );
		self::assertSame( 'edge', $status['provider'] );
	}

	public function test_duplicate_vary_headers_are_normalized_without_array_casts(): void {
		$markdown                    = $this->response( 'text/markdown', 'Accept', '10', 'origin', '# Page' );
		$html                        = $this->response( 'text/html', 'Accept', '', '', '<html></html>' );
		$markdown['headers']['Vary'] = array( 'Accept-Encoding', 'Accept' );
		$html['headers']['Vary']     = array( 'Accept-Encoding', 'Accept' );

		$status = MarkdownNegotiationStatus::evaluate( $markdown, $html, 'https://example.com/' );

		self::assertSame( 'healthy', $status['status'] );
		self::assertSame( 'origin', $status['provider'] );
	}

	public function test_cache_contamination_is_an_error(): void {
		$status = MarkdownNegotiationStatus::evaluate(
			$this->response( 'text/markdown', 'Accept', '10', 'origin', '# Page' ),
			$this->response( 'text/markdown', 'Accept', '10', 'origin', '# Page' ),
			'https://example.com/'
		);
		self::assertSame( 'error', $status['status'] );
		self::assertSame( 'cache', $status['provider'] );
	}

	/** @return array<string,mixed> */
	private function response( string $type, string $vary, string $tokens, string $source, string $body ): array {
		return array(
			'response' => array( 'code' => 200 ),
			'headers'  => array(
				'Content-Type'                  => $type,
				'Vary'                          => $vary,
				'X-Markdown-Tokens'             => $tokens,
				'X-Cybermaps-Markdown-Source'   => $source,
			),
			'body' => $body,
		);
	}
}
