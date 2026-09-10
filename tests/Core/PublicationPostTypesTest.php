<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Core;

use Cybermaps\Core\PublicationPostTypes;
use PHPUnit\Framework\TestCase;

final class PublicationPostTypesTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_post_types'] = array( 'post', 'attachment', 'book', 'post' );
		$GLOBALS['cybermaps_mock_post_type_objects'] = array(
			'post'       => (object) array( 'name' => 'post', 'public' => true ),
			'attachment' => (object) array( 'name' => 'attachment', 'public' => true ),
			'book'       => (object) array( 'name' => 'book', 'public' => true ),
			'internal'   => (object) array( 'name' => 'internal', 'public' => false ),
		);
	}

	public function test_names_exclude_attachments_and_remove_duplicates(): void {
		self::assertSame( array( 'post', 'book' ), PublicationPostTypes::names() );
	}

	public function test_objects_follow_the_same_canonical_inventory(): void {
		self::assertSame(
			array( 'post', 'book' ),
			array_keys( PublicationPostTypes::objects() )
		);
	}

	public function test_contains_requires_a_registered_public_non_attachment_type(): void {
		self::assertTrue( PublicationPostTypes::contains( 'post' ) );
		self::assertTrue( PublicationPostTypes::contains( 'Book' ) );
		self::assertFalse( PublicationPostTypes::contains( 'attachment' ) );
		self::assertFalse( PublicationPostTypes::contains( 'internal' ) );
		self::assertFalse( PublicationPostTypes::contains( 'missing' ) );
	}

	public function test_configured_lists_preserve_order_but_never_allow_attachments(): void {
		self::assertSame(
			array( 'book', 'post', 'future_type' ),
			PublicationPostTypes::filter_names(
				array( 'Book', 'attachment', 'post', 'book', 'future_type' )
			)
		);
	}
}
