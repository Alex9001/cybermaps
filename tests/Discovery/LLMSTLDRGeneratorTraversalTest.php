<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Discovery\LLMSTLDR\LLMSTLDRGenerator;
use PHPUnit\Framework\TestCase;

final class LLMSTLDRGeneratorTraversalTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_options'] = array(
			'cybermaps_settings' => array(
				'llms_included_types' => array( 'post' ),
			),
		);
		$GLOBALS['cybermaps_mock_post_types'] = array( 'post' );
		$GLOBALS['cybermaps_mock_post_type_objects'] = array(
			'post' => (object) array( 'public' => true ),
		);
		$GLOBALS['cybermaps_mock_posts'] = array(
			1 => (object) array(
				'ID'                => 1,
				'post_type'         => 'post',
				'post_status'       => 'publish',
				'post_title'        => 'One',
				'post_content'      => 'Body one.',
				'post_modified_gmt' => '2026-01-01 00:00:00',
			),
		);
		$GLOBALS['cybermaps_mock_get_posts_args'] = array();
		$GLOBALS['cybermaps_mock_wp_count_posts_calls'] = 0;
	}

	public function test_generate_publication_uses_upper_bound_without_separate_count_traversal(): void {
		$result = ( new LLMSTLDRGenerator() )->generate_publication(
			array(
				'llms_tldr_token_budget' => 8000,
				'llms_pinned_ids'        => '',
			),
			'Mock Site'
		);

		$this->assertSame( 1, (int) ( $GLOBALS['cybermaps_mock_wp_count_posts_calls'] ?? 0 ) );
		$this->assertGreaterThanOrEqual( 1, (int) ( $result['eligible_count'] ?? 0 ) );
		$this->assertSame( 0, (int) ( $result['partial_count'] ?? -1 ) );
	}
}
