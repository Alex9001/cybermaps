<?php
declare(strict_types=1);
namespace Cybermaps\Tests\Discovery;
use Cybermaps\Discovery\NotFoundSuggestions;
use Cybermaps\Discovery\Search;
use Cybermaps\Discovery\Updates;
use Cybermaps\Content\VisibleTextExtractor;
final class DiscoverySearchBudgetTest extends \WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();
		\cybermaps_mock_reset_cache_runtime();
		$GLOBALS['cybermaps_mock_transients'] = array();
		$GLOBALS['cybermaps_mock_options'] = array( 'blog_public' => '1', 'cybermaps_settings' => array( 'enable_discovery_hub' => '1', 'enable_content_hints' => '0', 'llms_included_types' => array( 'post' ) ) );
		$GLOBALS['cybermaps_mock_post_types'] = array( 'post' );
		$GLOBALS['cybermaps_mock_post_type_objects'] = array( 'post' => (object) array( 'public' => true ) );
		$GLOBALS['cybermaps_mock_post_meta'] = array();
		$GLOBALS['cybermaps_mock_current_user_capabilities'] = array();
		$GLOBALS['cybermaps_mock_posts'] = array( 1 => new \WP_Post( array( 'ID' => 1, 'post_type' => 'post', 'post_status' => 'publish', 'post_password' => '', 'post_title' => str_repeat( '界Title', 200 ), 'post_content' => 'Ordinary visible content.', 'post_date_gmt' => gmdate( 'Y-m-d H:i:s' ), 'post_modified_gmt' => gmdate( 'Y-m-d H:i:s' ) ) ) );
		$GLOBALS['cybermaps_mock_wp_query_args'] = array();
		$GLOBALS['cybermaps_mock_wp_query_callback'] = static fn(): array => array_values( $GLOBALS['cybermaps_mock_posts'] );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.99';
	}
	protected function tearDown(): void {
		unset( $GLOBALS['cybermaps_mock_wp_query_callback'], $_SERVER['REMOTE_ADDR'] );
		parent::tearDown();
	}
	public function test_rest_and_distinct_missing_slugs_share_one_search_budget_and_bounded_titles(): void {
		$request = new class { public function get_param( string $key ): mixed { return 'q' === $key ? 'ordinary' : 1; } };
		$data = ( new Search() )->handle_search( $request )->get_data();
		self::assertLessThanOrEqual( 512, strlen( $data['results'][0]['title'] ) );
		$finder = new \ReflectionMethod( NotFoundSuggestions::class, 'find_alternatives' );
		for ( $request_id = 1; $request_id <= 29; ++$request_id ) {
			$alternatives = $finder->invoke( new NotFoundSuggestions(), 'missing-' . $request_id );
			self::assertCount( 1, $alternatives );
			self::assertLessThanOrEqual( 512, strlen( $alternatives[0]['title'] ) );
			self::assertSame( 1, preg_match( '//u', $alternatives[0]['title'] ) );
		}
		self::assertSame( array(), $finder->invoke( new NotFoundSuggestions(), 'another-path' ) );
		self::assertCount( 30, $GLOBALS['cybermaps_mock_wp_query_args'] );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.100';
		self::assertCount( 1, $finder->invoke( new NotFoundSuggestions(), 'another-path' ) );
	}
	public function test_updates_bound_long_utf8_public_titles_and_encode_valid_json(): void {
		$body = ( new Updates() )->get_json_content();
		$data = json_decode( $body, true, 512, JSON_THROW_ON_ERROR );
		self::assertCount( 1, $data['updates'] );
		self::assertLessThanOrEqual( 512, strlen( $data['updates'][0]['title'] ) );
		self::assertSame( 1, preg_match( '//u', $data['updates'][0]['title'] ) );
	}
	public function test_invalid_utf8_leading_source_returns_empty_without_byte_by_byte_retries(): void {
		$source = "\xFF" . str_repeat( 'x', VisibleTextExtractor::SUMMARY_SOURCE_MAX_BYTES + 1 );
		$prefix = ( new \ReflectionMethod( VisibleTextExtractor::class, 'leading_bytes' ) )->invoke( new VisibleTextExtractor(), $source );
		self::assertSame( '', $prefix );
		$valid = str_repeat( 'x', VisibleTextExtractor::SUMMARY_SOURCE_MAX_BYTES - 2 ) . '界';
		$prefix = ( new \ReflectionMethod( VisibleTextExtractor::class, 'leading_bytes' ) )->invoke( new VisibleTextExtractor(), $valid );
		self::assertSame( str_repeat( 'x', VisibleTextExtractor::SUMMARY_SOURCE_MAX_BYTES - 2 ), $prefix );
	}
}
