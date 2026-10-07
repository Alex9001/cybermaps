<?php
declare(strict_types=1);
namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\IdentityPageSelector;
use PHPUnit\Framework\TestCase;

final class IdentityPageSelectorTest extends TestCase {
	public function test_actual_ajax_boundary_preserves_raw_rejections_and_authorization_order(): void {
		foreach ( array( 'success', 'query-max', 'cursor-max', 'raw-query-limit', 'cursor-limit', 'cursor-punctuation', 'cursor-percent', 'cursor-escape', 'cursor-array', 'query-array', 'method-space', 'method-escape', 'method-array', 'capability', 'nonce' ) as $case ) {
			$process = proc_open( array( PHP_BINARY, __DIR__ . '/fixtures/identity-page-input.php', $case ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
			self::assertIsResource( $process );
			$output = stream_get_contents( $pipes[1] );
			$error = stream_get_contents( $pipes[2] );
			fclose( $pipes[1] ); fclose( $pipes[2] );
			self::assertSame( 0, proc_close( $process ), $error );
			$evidence = json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
			$expected = match ( $case ) { 'success', 'query-max', 'cursor-max' => 200, 'method-space', 'method-escape', 'method-array' => 405, 'capability', 'nonce' => 403, default => 400 };
			self::assertSame( $expected, $evidence['status'], $case );
			self::assertSame( 200 === $expected ? 1 : 0, $evidence['queries'] );
			if ( in_array( $case, array( 'method-space', 'method-escape', 'method-array', 'capability' ), true ) ) {
				self::assertSame( array(), $evidence['events'] );
			} else {
				self::assertSame( 'nonce', $evidence['events'][0] );
				if ( 'nonce' === $case ) { self::assertSame( array( 'nonce' ), $evidence['events'] ); }
			}
		}
	}

	public function test_search_is_keyset_bounded_and_requires_public_passwordless_pages(): void {
		$had_db = array_key_exists( 'wpdb', $GLOBALS );
		$previous = $GLOBALS['wpdb'] ?? null;
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
		} finally {
			if ( $had_db ) { $GLOBALS['wpdb'] = $previous; } else { unset( $GLOBALS['wpdb'] ); }
		}
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
