<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Discovery\DiscoveryPublicationGenerator;

final class DiscoveryPublicationGeneratorTest extends \WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_options'] = array(
			'blog_public' => '1',
			'cybermaps_settings' => array(
				'enable_discovery_hub' => '1',
				'enable_llms_full'     => '1',
				'llms_included_types'  => array( 'post' ),
			),
		);
		$GLOBALS['cybermaps_mock_transients'] = array();
		$GLOBALS['cybermaps_mock_get_posts_args'] = array();
		$GLOBALS['cybermaps_mock_post_meta'] = array();
		$GLOBALS['cybermaps_mock_post_types'] = array( 'post' );
		$GLOBALS['cybermaps_mock_post_type_objects'] = array(
			'post' => (object) array(
				'name'   => 'post',
				'public' => true,
				'labels' => (object) array( 'name' => 'Posts' ),
			),
		);
		$GLOBALS['cybermaps_mock_posts'] = array(
			1 => (object) array(
				'ID'                => 1,
				'post_title'        => 'Literal resource',
				'post_content'      => '<p>Complete literal body.</p>',
				'post_excerpt'      => '',
				'post_type'         => 'post',
				'post_status'       => 'publish',
				'post_password'     => '',
				'post_modified_gmt' => '2026-01-01 00:00:00',
				'post_date_gmt'     => '2026-01-01 00:00:00',
			),
		);
	}

	public function test_retired_literal_caches_do_not_replay_before_current_analysis(): void {
		\Cybermaps\Core\CacheManager::set( 'cybermaps_llms_cache', 'Retired incorrect literal body', HOUR_IN_SECONDS, 'discovery' );
		\Cybermaps\Core\CacheManager::set( 'cybermaps_llms_full_cache', 'Retired incorrect full body', HOUR_IN_SECONDS, 'discovery' );
		$llms = new \Cybermaps\Discovery\LLMS();
		$summary = $llms->get_llms_content();
		$full = $llms->get_llms_content( true );
		$this->assertStringNotContainsString( 'Retired incorrect', $summary );
		$this->assertStringNotContainsString( 'Retired incorrect', $full );
		$this->assertStringContainsString( 'Literal resource', $summary );
		$this->assertStringContainsString( 'Complete literal body.', $full );
		$this->assertSame( $summary, \Cybermaps\Core\CacheManager::get( \Cybermaps\Discovery\LLMS::SUMMARY_CACHE_KEY, 'discovery', $found ) );
		$this->assertTrue( $found );
		\Cybermaps\Discovery\LLMS::invalidate_cache();
		$this->assertFalse( get_transient( 'cybermaps_llms_cache' ) );
		$this->assertFalse( get_transient( 'cybermaps_llms_full_cache' ) );
	}

	public function test_full_body_is_not_retained_in_the_generators_request_cache(): void {
		$settings  = $GLOBALS['cybermaps_mock_options']['cybermaps_settings'];
		$target    = array(
			'id'       => 'llms_full',
			'filename' => 'llms-full.txt',
			'type'     => 'text/plain',
		);
		$generator = new DiscoveryPublicationGenerator();

		$first = $generator->generate( 'llms_full', $target, $settings );
		$first_query_count = count( $GLOBALS['cybermaps_mock_get_posts_args'] );
		$second = $generator->generate( 'llms_full', $target, $settings );

		$this->assertSame( $first, $second );
		$this->assertGreaterThan( $first_query_count, count( $GLOBALS['cybermaps_mock_get_posts_args'] ) );
		$this->assertFalse( get_transient( \Cybermaps\Discovery\LLMS::FULL_CACHE_KEY ) );
	}
}
