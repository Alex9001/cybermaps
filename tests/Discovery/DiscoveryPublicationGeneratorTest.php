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
