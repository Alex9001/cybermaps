<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Discovery\PublicationConstraints;
use PHPUnit\Framework\TestCase;

final class PublicationConstraintsTest extends TestCase {
	public function test_ai_sitemap_and_feed_limits_are_bounded(): void {
		self::assertSame(
			PublicationConstraints::AI_SITEMAP_LIMIT_MIN,
			PublicationConstraints::ai_sitemap_limit( 0 )
		);
		self::assertSame(
			PublicationConstraints::AI_SITEMAP_LIMIT_MAX,
			PublicationConstraints::ai_sitemap_limit( PHP_INT_MAX )
		);
		self::assertSame(
			PublicationConstraints::FEED_LIMIT_MIN,
			PublicationConstraints::feed_limit( 0 )
		);
		self::assertSame(
			PublicationConstraints::FEED_LIMIT_MAX,
			PublicationConstraints::feed_limit( PHP_INT_MAX )
		);
		self::assertSame(
			PublicationConstraints::LLMS_LINK_LIMIT_MIN,
			PublicationConstraints::llms_link_limit( 0 )
		);
		self::assertSame(
			PublicationConstraints::LLMS_LINK_LIMIT_MAX,
			PublicationConstraints::llms_link_limit( PHP_INT_MAX )
		);
	}

	public function test_action_types_are_canonical_and_restricted(): void {
		self::assertSame( 'ContactAction', PublicationConstraints::action_type( 'contactaction' ) );
		self::assertSame( 'SearchAction', PublicationConstraints::action_type( 'SearchAction' ) );
		self::assertSame( '', PublicationConstraints::action_type( 'DeleteEverythingAction' ) );
	}

	public function test_topics_are_nonempty_unique_and_bounded(): void {
		$labels = array( '', 'WordPress', 'wordpress', ' SEO ', str_repeat( 'x', 100 ) );
		for ( $index = 0; $index < PublicationConstraints::TOPICS_MAX + 5; ++$index ) {
			$labels[] = 'Topic ' . $index;
		}

		$topics = PublicationConstraints::topics( implode( ',', $labels ) );

		self::assertCount( PublicationConstraints::TOPICS_MAX, $topics );
		self::assertSame( 'WordPress', $topics[0] );
		self::assertSame( 'SEO', $topics[1] );
		self::assertSame(
			PublicationConstraints::TOPIC_LABEL_MAX_LENGTH,
			strlen( $topics[2] )
		);
		self::assertLessThanOrEqual(
			PublicationConstraints::TOPICS_TOTAL_MAX_LENGTH,
			strlen( implode( ', ', $topics ) )
		);
	}

	public function test_bounded_text_preserves_valid_utf8_at_the_byte_ceiling(): void {
		$value = PublicationConstraints::bounded_text( str_repeat( 'é', 20 ), 15 );

		self::assertSame( 1, preg_match( '//u', $value ) );
		self::assertLessThanOrEqual( 15, strlen( $value ) );
	}
}
