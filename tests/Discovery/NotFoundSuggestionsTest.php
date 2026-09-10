<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Discovery\NotFoundSuggestions;
use PHPUnit\Framework\TestCase;

final class NotFoundSuggestionsTest extends TestCase {

	public function test_problem_document_uses_the_generic_rfc_9457_not_found_type(): void {
		$document = ( new NotFoundSuggestions() )->get_problem_document(
			'/missing-resource',
			array(
				array(
					'url'   => 'https://example.org/found-resource/',
					'title' => 'Found resource',
				),
			)
		);

		$this->assertSame( 'about:blank', $document['type'] );
		$this->assertSame( 'Not Found', $document['title'] );
		$this->assertSame( 404, $document['status'] );
		$this->assertSame(
			array(
				array(
					'url'   => 'https://example.org/found-resource/',
					'title' => 'Found resource',
				),
			),
			$document['suggested_alternatives']
		);
	}
}
