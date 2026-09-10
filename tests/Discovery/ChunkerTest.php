<?php
namespace Cybermaps\Tests\Discovery;

use Cybermaps\Discovery\Chunker;

class ChunkerTest extends \WP_UnitTestCase {
	public function test_split_text_has_a_valid_utf8_fallback_without_mbstring(): void {
		$chunker = new Chunker( false, false );
		$chunks  = $chunker->split_text(
			str_repeat( 'Alpha 🗺️ beta ', 20 ),
			100,
			25
		);

		$this->assertGreaterThan( 1, count( $chunks ) );
		foreach ( $chunks as $chunk ) {
			$this->assertSame( 1, preg_match( '//u', $chunk ) );
			$this->assertLessThanOrEqual( 100, strlen( $chunk ) );
		}
	}

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_options']    = array();
		$GLOBALS['cybermaps_mock_posts']      = array();
		$GLOBALS['cybermaps_mock_post_meta']  = array();
		$GLOBALS['cybermaps_mock_transients'] = array();
	}

	public function test_get_chunks_basic() {
		$text = "This is a long piece of text that should be split into multiple chunks because it exceeds the window size limit.";
		$chunker = new Chunker();
		$chunks = $chunker->split_text( $text, 20, 5 );

		$this->assertGreaterThan( 1, count( $chunks ) );
		$this->assertStringContainsString( 'This is a long', $chunks[0] );
	}

	public function test_html_processor_headers_ignores_comments() {
		$chunker = new \Cybermaps\Discovery\Chunker();
		$html = '<h2>Header 2</h2><p>Content</p><!-- <h2>Ignored</h2> -->';

		$processed = $chunker->extract_headers_to_markdown( $html );

		$this->assertStringContainsString('## Header 2', $processed);
		$this->assertStringNotContainsString('## Ignored', $processed);
	}

	public function test_html_processor_preserves_content() {
		$chunker = new \Cybermaps\Discovery\Chunker();
		$html = '<p>Start</p><h2>Header</h2><p>End</p>';

		$processed = $chunker->extract_headers_to_markdown( $html );

		$this->assertStringContainsString('Start', $processed);
		$this->assertStringContainsString('## Header', $processed);
		$this->assertStringContainsString('End', $processed);
	}

	public function test_runtime_configuration_is_bounded_and_overlap_cannot_dominate(): void {
		$this->assertSame(
			array(
				'window_size' => Chunker::MIN_WINDOW_SIZE,
				'overlap'     => 50,
			),
			Chunker::normalize_configuration(
				array(
					'rag_chunk_size'    => 1,
					'rag_chunk_overlap' => 99999,
				)
			)
		);
		$this->assertSame(
			array(
				'window_size' => Chunker::MAX_WINDOW_SIZE,
				'overlap'     => 0,
			),
			Chunker::normalize_configuration(
				array(
					'rag_chunk_size'    => PHP_INT_MAX,
					'rag_chunk_overlap' => -10,
				)
			)
		);
	}

	public function test_cache_identity_changes_with_chunk_configuration(): void {
		$GLOBALS['cybermaps_mock_posts'][25] = (object) array(
			'ID'                => 25,
			'post_type'         => 'post',
			'post_content'      => str_repeat( 'Chunk boundary text. ', 50 ),
			'post_modified_gmt' => '2026-07-01 00:00:00',
		);
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'rag_chunk_size'    => 200,
			'rag_chunk_overlap' => 20,
		);
		$chunker = new Chunker();
		$first = $chunker->get_chunks( 25 );

		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['rag_chunk_size'] = 300;
		$second = $chunker->get_chunks( 25 );

		$this->assertNotSame( $first['chunks'], $second['chunks'] );
	}

	public function test_cache_identity_changes_with_resolved_intent(): void {
		$GLOBALS['cybermaps_mock_posts'][35] = (object) array(
			'ID'                => 35,
			'post_type'         => 'post',
			'post_status'       => 'publish',
			'post_content'      => str_repeat( 'Intent-aware chunk text. ', 30 ),
			'post_modified_gmt' => '2026-07-01 00:00:00',
		);
		$GLOBALS['cybermaps_mock_options']['cybermaps_discovery_center'] = wp_json_encode(
			array(
				'type_intents' => array( 'post_type:post' => 'informational' ),
			)
		);
		$chunker = new Chunker();
		$first   = $chunker->get_chunks( 35 );

		update_post_meta( 35, '_cybermaps_intent_override', 'transactional' );
		$second = $chunker->get_chunks( 35 );

		$this->assertSame( 'informational', $first['metadata']['intent'] );
		$this->assertSame( 'transactional', $second['metadata']['intent'] );
	}

	public function test_static_generation_can_skip_transient_and_inventory_writes(): void {
		$GLOBALS['cybermaps_mock_posts'][45] = (object) array(
			'ID'                => 45,
			'post_type'         => 'post',
			'post_status'       => 'publish',
			'post_content'      => str_repeat( 'Static chunk text. ', 30 ),
			'post_modified_gmt' => '2026-07-01 00:00:00',
		);

		$result = ( new Chunker( false ) )->get_chunks( 45 );

		$this->assertNotEmpty( $result['chunks'] );
		$this->assertSame( array(), $GLOBALS['cybermaps_mock_transients'] );
		$this->assertFalse( get_option( 'cybermaps_core_transient_inventory', false ) );
	}

	public function test_static_bridge_disables_chunk_caching_for_both_static_paths(): void {
		$bridge_source = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/src/Discovery/StaticBridge.php'
		);
		$runner_source = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/src/Discovery/StaticSyncRunner.php'
		);
		$source = $bridge_source . $runner_source;

		$this->assertSame( 2, substr_count( $source, 'new Chunker( false )' ) );
		$this->assertStringNotContainsString( 'new Chunker()', $source );
	}

	public function test_invalidated_chunk_cache_is_rebuilt(): void {
		$GLOBALS['cybermaps_mock_posts'][55] = (object) array(
			'ID'                => 55,
			'post_type'         => 'post',
			'post_status'       => 'publish',
			'post_content'      => str_repeat( 'Recoverable chunk cache. ', 30 ),
			'post_modified_gmt' => '2026-07-01 00:00:00',
		);
		$first = ( new Chunker() )->get_chunks( 55 );
		\Cybermaps\Core\CacheManager::clear_family( 'chunks' );

		$rebuilt = ( new Chunker() )->get_chunks( 55 );

		$this->assertIsArray( $rebuilt );
		$this->assertSame( $first, $rebuilt );
	}
}
