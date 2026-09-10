<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Core;

use Cybermaps\Core\TermExclusionList;
use PHPUnit\Framework\TestCase;

final class TermExclusionListTest extends TestCase {
	public function test_normalizes_mixed_case_and_spaced_labels(): void {
		$parsed = TermExclusionList::parse( 'News, news, 42 , 42' );

		$this->assertSame( array( 'news', '42' ), $parsed );
	}

	public function test_to_csv_preserves_canonical_tokens(): void {
		$this->assertSame(
			'news, 42',
			TermExclusionList::to_csv( array( 'News', '42', 'news' ) )
		);
	}

	public function test_term_matches_id_and_slug(): void {
		$excluded = TermExclusionList::parse( '12, featured' );
		$term     = (object) array(
			'term_id' => 12,
			'slug'    => 'other',
		);

		$this->assertTrue( TermExclusionList::term_matches( $term, $excluded ) );
	}

	public function test_identifiers_match_sanitized_slug(): void {
		$excluded = TermExclusionList::parse( 'Featured Story' );

		$this->assertTrue(
			TermExclusionList::identifiers_match( 0, 'featured-story', $excluded )
		);
	}
}
