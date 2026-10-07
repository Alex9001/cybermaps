<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Discovery\ADPNews;
use Cybermaps\Discovery\AIMetadata;
use Cybermaps\Discovery\Chunker;
use Cybermaps\Discovery\Feed;
use Cybermaps\Discovery\RAGChunk;
use Cybermaps\Discovery\PublicationSizeLimitException;
use Cybermaps\Discovery\LLMSTLDR\LLMSTLDRGenerator;

final class DiscoveryResourceBoundsTest extends \WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();
		\cybermaps_mock_reset_cache_runtime();
		$GLOBALS['cybermaps_mock_posts'] = array();
		$GLOBALS['cybermaps_mock_post_meta'] = array();
		$GLOBALS['cybermaps_mock_transients'] = array();
		$GLOBALS['cybermaps_mock_options'] = array( 'blog_public' => '1', 'cybermaps_settings' => array(
			'enable_discovery_hub' => '1', 'enable_rag_chunks' => '1',
			'llms_included_types' => array( 'post' ), 'enable_content_hints' => '1',
		) );
		$GLOBALS['cybermaps_mock_post_types'] = array( 'post' );
		$GLOBALS['cybermaps_mock_post_type_objects'] = array( 'post' => (object) array( 'name' => 'post', 'public' => true ) );
		$GLOBALS['cybermaps_mock_wp_query_callback'] = static fn( array $args ): array => array_values( $GLOBALS['cybermaps_mock_posts'] );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['cybermaps_mock_wp_query_callback'] );
		parent::tearDown();
	}

	public function test_news_counts_complete_unicode_content_and_uses_literal_bounded_summary(): void {
		$this->post( 1, '<p>' . str_repeat( "0 Alpha 42\t", 5000 ) . "β\u{00A0}γ</p>" );
		$GLOBALS['cybermaps_mock_posts'][1]->post_excerpt = '<b>Literal excerpt</b><template>Hidden</template>[demo]No execution[/demo]';
		$news = new ADPNews();
		$record = json_decode( trim( $news->get_content( 'adp_news_archive' ) ), true, 512, JSON_THROW_ON_ERROR );
		self::assertSame( 15002, $record['wordCount'] );
		$speakable = json_decode( $news->get_content( 'adp_news_speakable' ), true, 512, JSON_THROW_ON_ERROR );
		self::assertSame( 'Literal excerpt No execution', $speakable['itemListElement'][0]['speakableText'] );
		self::assertStringNotContainsString( 'Hidden', $news->get_content( 'adp_news_llms' ) );
		self::assertLessThanOrEqual( 1024, strlen( $speakable['itemListElement'][0]['speakableText'] ) );
	}

	public function test_failed_news_collection_cannot_replay_a_cached_prefix_on_retry(): void {
		$this->post( 1, 'First ordinary article.' );
		$this->post( 2, 'Second ordinary article.' );
		$GLOBALS['cybermaps_mock_permalinks'][2] = 'https://example.com/' . str_repeat( 'path/', 110000 );
		$news = new ADPNews();
		try {
			foreach ( array( 'adp_news_archive', 'adp_news_speakable' ) as $endpoint ) {
				try {
					$news->get_content( $endpoint );
					self::fail( 'A previous failed collection must not become a partial publication.' );
				} catch ( PublicationSizeLimitException $error ) {
					self::assertSame( 'news', $error->get_publication() );
				}
			}
		} finally {
			unset( $GLOBALS['cybermaps_mock_permalinks'][2] );
		}
	}

	public function test_metadata_retains_first_distinct_candidates_and_actual_final_paragraph(): void {
		$this->post( 1, '<p>' . str_repeat( 'Alpha Alpha Bravo Charlie Delta Echo Foxtrot Golf Hotel India 1 1 2 3 4 5 6. ', 2000 ) . '</p>' . str_repeat( '<p>ordinary paragraph</p>', 1000 ) . '<p>final closing words</p>' );
		$metadata = AIMetadata::calculate( 1 );
		self::assertStringContainsString( 'Capitalized terms: Alpha, Bravo, Charlie, Delta, Echo, Foxtrot, Golf, Hotel.', $metadata['snippet'] );
		self::assertStringContainsString( 'Numeric text: 1, 2, 3, 4, 5.', $metadata['snippet'] );
		self::assertStringContainsString( 'Closing excerpt: final closing words', $metadata['snippet'] );
		self::assertSame( 'long', $metadata['length_band'] );
		self::assertLessThanOrEqual( 1024, strlen( $metadata['snippet'] ) );
		self::assertSame( '', get_post_meta( 1, '_cybermaps_ai_meta', true ) );
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['enable_content_hints'] = '0';
		self::assertSame( '', AIMetadata::calculate( 1 )['snippet'] );
	}

	public function test_single_large_paragraph_has_bounded_closing_excerpt(): void {
		$this->post( 1, str_repeat( 'ordinary words ', 40000 ) );
		$metadata = AIMetadata::calculate( 1 );
		self::assertStringStartsWith( 'Closing excerpt: ordinary words', $metadata['snippet'] );
		self::assertLessThan( 400, strlen( $metadata['snippet'] ) );
	}

	public function test_legacy_metadata_cache_cannot_replay_pre_fix_hidden_text(): void {
		$this->post( 1, '<p>Visible words</p><template><template>inner</template>hidden outer</template>' );
		AIMetadata::refresh( 1 );
		$GLOBALS['cybermaps_mock_post_meta'][1]['_cybermaps_ai_meta']['snippet'] = 'hidden outer';
		unset( $GLOBALS['cybermaps_mock_post_meta'][1]['_cybermaps_ai_meta_version'] );
		self::assertStringNotContainsString( 'hidden outer', AIMetadata::calculate( 1 )['snippet'] );
	}

	public function test_exact_chunk_count_boundary_preserves_overlap_and_complete_tail(): void {
		$text = str_repeat( 'x', Chunker::MAX_CHUNKS * 50 );
		$this->post( 1, $text );
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['rag_chunk_size'] = 100;
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['rag_chunk_overlap'] = 50;
		$payload = ( new Chunker( false ) )->get_chunks( 1 );
		self::assertCount( Chunker::MAX_CHUNKS, $payload['chunks'] );
		self::assertSame( range( 0, Chunker::MAX_CHUNKS - 1 ), array_column( $payload['chunks'], 'index' ) );
		$reconstructed = $payload['chunks'][0]['text'];
		foreach ( array_slice( $payload['chunks'], 1 ) as $chunk ) {
			$reconstructed .= substr( $chunk['text'], 50 );
		}
		self::assertSame( $text, $reconstructed );
	}

	public function test_chunk_count_overflow_fails_without_caching_a_prefix(): void {
		$this->post( 1, str_repeat( 'x', Chunker::MAX_CHUNKS * 50 + 1 ) );
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['rag_chunk_size'] = 100;
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['rag_chunk_overlap'] = 50;
		try {
			( new Chunker() )->get_chunks( 1 );
			self::fail( 'The complete chunk publication exceeds its count bound.' );
		} catch ( PublicationSizeLimitException $error ) {
			self::assertSame( 'chunks.json', $error->get_publication() );
			foreach ( $GLOBALS['cybermaps_mock_transients'] as $cached ) {
				self::assertArrayNotHasKey( 'chunks', (array) ( $cached['value'] ?? array() ) );
			}
		}
	}

	/** @dataProvider incomplete_sources */
	public function test_rag_dynamic_and_selected_static_generators_reject_incomplete_sources( string $content ): void {
		$this->post( 1, $content );
		foreach ( array( 'get_content', 'get_content_for_selected_post' ) as $method ) {
			try {
				( new RAGChunk() )->$method( 1 );
				self::fail( 'Incomplete analysis must not be published.' );
			} catch ( PublicationSizeLimitException $error ) {
				self::assertSame( 'chunks.json', $error->get_publication() );
			}
		}
	}

	public static function incomplete_sources(): array {
		return array(
			'unclosed hidden tree' => array( '<p>Visible</p><template>hidden to end' ),
			'structural overflow' => array( str_repeat( '<h2>Visible</h2>', 10001 ) ),
			'source overflow' => array( str_repeat( 'x', Chunker::MAX_SOURCE_BYTES + 1 ) ),
			'oversized attribute' => array( '<a href=data:text/plain,' . str_repeat( 'x', Chunker::MAX_SOURCE_BYTES ) . '>Link</a>' ),
		);
	}

	public function test_utf8_chunks_reconstruct_the_source_without_overlap_in_both_modes(): void {
		$text = str_repeat( 'a🗺️b', 70 );
		foreach ( array( false, true ) as $multibyte ) {
			$chunks = ( new Chunker( false, $multibyte ) )->split_text( $text, 100, 0 );
			self::assertSame( $text, implode( '', $chunks ) );
			foreach ( $chunks as $chunk ) {
				self::assertSame( 1, preg_match( '//u', $chunk ) );
			}
		}
	}

	/** @dataProvider relative_link_attributes */
	public function test_relative_link_expansion_is_bounded_before_complete_analysis( string $attribute ): void {
		$this->post( 1, str_repeat( '<a ' . $attribute . '>Link</a>', 500 ) );
		$GLOBALS['cybermaps_mock_permalinks'][1] = 'https://example.com/' . str_repeat( 'path/', 600 );
		try {
			$this->expectException( PublicationSizeLimitException::class );
			( new Chunker( false ) )->get_chunks( 1 );
		} finally {
			unset( $GLOBALS['cybermaps_mock_permalinks'][1] );
		}
	}

	public static function relative_link_attributes(): array {
		return array(
			array( 'href="#reference"' ),
			array( "href='#reference'" ),
		);
	}

	/** @dataProvider oversized_feed_sources */
	public function test_full_feed_fails_as_a_whole_for_source_aggregate_and_encoded_bounds( array $sources ): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['ai_feed_full_content'] = '1';
		foreach ( $sources as $index => $source ) {
			$this->post( $index + 1, $source );
		}
		$this->expectException( PublicationSizeLimitException::class );
		$this->expectExceptionMessage( 'No partial output was returned or written' );
		( new Feed() )->get_json_content();
	}

	public static function oversized_feed_sources(): array {
		return array(
			'individual source' => array( array( str_repeat( 'x', Feed::MAX_OUTPUT_BYTES + 1 ) ) ),
			'aggregate body' => array( array_fill( 0, 3, str_repeat( 'x', 1500000 ) ) ),
			'JSON expansion' => array( array( str_repeat( '"', 2200000 ) ) ),
		);
	}

	public function test_long_unicode_site_name_cannot_exceed_minimum_briefing_budget(): void {
		$this->post( 1, '<p>Ordinary extract.</p>' );
		$result = ( new LLMSTLDRGenerator() )->generate_publication( array( 'llms_tldr_token_budget' => 1000, 'llms_included_types' => array( 'post' ) ), str_repeat( '🗺️Site', 1000 ) );
		self::assertLessThanOrEqual( 1000, $result['token_estimate'] );
		self::assertLessThanOrEqual( 4000, strlen( $result['output'] ) );
		self::assertStringContainsString( '> Token-Budget: 1000', $result['output'] );
		self::assertStringContainsString( '> Coverage:', $result['output'] );
	}

	public function test_llms_summary_rejects_title_expansion_before_caching_a_partial_publication(): void {
		$this->post( 1, 'Ordinary visible article.' );
		$GLOBALS['cybermaps_mock_posts'][1]->post_title = str_repeat( '[', intdiv( \Cybermaps\Discovery\LLMS::OUTPUT_MAX_BYTES, 2 ) );
		try {
			( new \Cybermaps\Discovery\LLMS() )->get_llms_content();
			self::fail( 'Title expansion must fail the complete summary publication.' );
		} catch ( PublicationSizeLimitException $error ) {
			self::assertSame( 'llms.txt', $error->get_publication() );
			\Cybermaps\Core\CacheManager::get( \Cybermaps\Discovery\LLMS::SUMMARY_CACHE_KEY, 'discovery', $found );
			self::assertFalse( $found );
		}
	}

	private function post( int $id, string $content ): void {
		$GLOBALS['cybermaps_mock_posts'][ $id ] = new \WP_Post( array(
			'ID' => $id, 'post_type' => 'post', 'post_status' => 'publish', 'post_password' => '',
			'post_title' => 'Resource', 'post_excerpt' => '', 'post_content' => $content,
			'post_date_gmt' => '2026-01-01 00:00:00', 'post_modified_gmt' => '2026-01-01 00:00:00',
		) );
	}
}
