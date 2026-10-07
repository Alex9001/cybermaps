<?php
declare(strict_types=1);
namespace Cybermaps\Tests\SEO;
use Cybermaps\SEO\IndexabilityDecision;
final class IndexabilityDecisionTest extends \WP_UnitTestCase {
	public function test_additional_reasons_never_reverse_a_denial(): void {
		$denied = new IndexabilityDecision( false, array(), 'https://example.com/canonical', '/redirect' );
		$copy = $denied->with_reasons( array() );
		self::assertFalse( $copy->indexable );
		self::assertSame( $denied->canonical_url, $copy->canonical_url );
		self::assertSame( $denied->redirect_url, $copy->redirect_url );
		self::assertFalse( $denied->with_reasons( array( 'blocked', 'blocked' ) )->indexable );
		self::assertTrue( ( new IndexabilityDecision( true ) )->with_reasons( array() )->indexable );
		self::assertFalse( ( new IndexabilityDecision( true ) )->with_reasons( array( 'blocked' ) )->indexable );
	}
}
