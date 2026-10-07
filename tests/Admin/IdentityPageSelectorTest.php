<?php
declare(strict_types=1);
namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\IdentityPageSelector;
use PHPUnit\Framework\TestCase;

final class IdentityPageSelectorTest extends TestCase {
	public function test_search_is_keyset_bounded_and_requires_public_passwordless_pages(): void {
		$previous = $GLOBALS['wpdb'];
		$db = new class() {
			public string $posts = 'wp_posts';
			public string $last_error = '';
			public string $query = '';
			public array $args = array();
			public bool $fail = false;
			public function esc_like( string $value ): string { return addcslashes( $value, '_%\\' ); }
			public function prepare( string $query, mixed ...$args ): string { $this->query = $query; $this->args = $args; return $query; }
			public function get_results( string $query, string $output ): array {
				if ( $this->fail ) { $this->last_error = 'private SQL error'; return array(); }
				return array_map( static fn( int $id ): array => array( 'ID' => $id, 'post_title' => 'Page ' . $id ), range( 101, 121 ) );
			}
		};
		$GLOBALS['wpdb'] = $db;
		try {
			$result = ( new IdentityPageSelector() )->search( 'Page', 100 );
			$this->assertCount( 20, $result['pages'] );
			$this->assertSame( 120, $result['next_cursor'] );
			$this->assertTrue( $result['has_more'] );
			$this->assertSame( array( 'wp_posts', 'page', 'publish', '', 100, '%Page%', 21 ), $db->args );
			$this->assertStringContainsString( 'ID > %d', $db->query );
			$db->fail = true;
			$this->assertInstanceOf( \WP_Error::class, ( new IdentityPageSelector() )->search( 'Page', 100 ) );
			$this->assertInstanceOf( \WP_Error::class, ( new IdentityPageSelector() )->search( str_repeat( 'p', 201 ), 0 ) );
		} finally { $GLOBALS['wpdb'] = $previous; }
	}

	public function test_template_does_not_enumerate_pages_and_private_selection_does_not_expose_title(): void {
		$previous = $GLOBALS['cybermaps_mock_posts'] ?? array();
		$GLOBALS['cybermaps_mock_posts'][123] = (object) array( 'ID' => 123, 'post_type' => 'page', 'post_status' => 'private', 'post_password' => '', 'post_title' => 'Secret' );
		ob_start();
		IdentityPageSelector::render( 'selection', 'selector', 0 );
		$template = (string) ob_get_clean();
		ob_start();
		IdentityPageSelector::render( 'selection', 'selector', 123 );
		$selected = (string) ob_get_clean();
		$GLOBALS['cybermaps_mock_posts'] = $previous;
		$this->assertSame( 1, substr_count( $template, '<option' ) );
		$this->assertSame( 2, substr_count( $selected, '<option' ) );
		$this->assertStringNotContainsString( 'Secret', $selected );
		$this->assertStringContainsString( 'Page #123 (unavailable)', $selected );
	}
}
