<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Content\VisibleTextExtractor;
use Cybermaps\Discovery\LLMS;
use Cybermaps\Discovery\LLMSTLDR;
use Cybermaps\Discovery\LLMSTLDR\LLMSTLDRGenerator;
use Cybermaps\Discovery\LLMSTLDR\TokenBudget;
use Cybermaps\Discovery\PublicationSizeLimitException;
use PHPUnit\Framework\TestCase;

final class LLMSTLDRTest extends TestCase {
	protected function setUp(): void {
		global $cybermaps_mock_posts, $cybermaps_mock_post_meta, $cybermaps_mock_transients, $cybermaps_mock_options;

		$cybermaps_mock_posts      = array();
		$cybermaps_mock_post_meta  = array();
		$cybermaps_mock_transients = array();
		$cybermaps_mock_options    = array(
			'blog_public'       => '1',
			'cybermaps_settings' => array(
				'enable_discovery_hub' => '1',
				'enable_llms_full'     => '1',
				'enable_llms_tldr'     => '1',
				'llms_included_types'  => array( 'post', 'page' ),
			),
		);
		$GLOBALS['cybermaps_mock_post_type_objects'] = array(
			'post' => (object) array(
				'public' => true,
				'labels' => (object) array( 'name' => 'Posts' ),
			),
			'page' => (object) array(
				'public' => true,
				'labels' => (object) array( 'name' => 'Pages' ),
			),
		);
		$GLOBALS['cybermaps_mock_post_types'] = array( 'post', 'page', 'attachment' );
	}

	public function test_visible_text_is_literal_and_does_not_execute_embedded_features(): void {
		$extractor = new VisibleTextExtractor();
		$text      = $extractor->normalize(
			'<h2>Useful &amp; clear</h2><script>secret()</script>[gallery ids="1"]<p>Second line.</p>'
		);

		$this->assertSame( "Useful & clear\n\nSecond line.", $text );
		$this->assertSame( 4, $extractor->word_count( $text ) );
	}

	public function test_summary_is_h1_first_and_makes_no_default_license_assertion(): void {
		$this->install_post( 1, 'A guide', '<p>Literal guide content for readers.</p>', '2026-01-02 10:00:00' );

		$output = ( new LLMS() )->get_llms_content( false, true );

		$this->assertStringStartsWith( "# Mock Site\n", $output );
		$this->assertStringContainsString( '## Posts', $output );
		$this->assertStringContainsString( '[A guide](https://example.com/?p=1&cybermaps_markdown=1)', $output );
		$this->assertStringNotContainsString( 'CC-BY-4.0', $output );
		$this->assertStringNotContainsString( "---\n", $output );
	}

	public function test_full_publication_includes_every_eligible_literal_body(): void {
		$this->install_post( 1, 'Older', '<p>Older complete body.</p>', '2025-01-01 00:00:00' );
		$this->install_post( 2, 'Newer', '<p>Newer complete body.</p>', '2026-01-01 00:00:00' );

		$output = ( new LLMS() )->get_llms_content( true, true );

		$this->assertStringContainsString( '## Older', $output );
		$this->assertStringContainsString( 'Older complete body.', $output );
		$this->assertStringContainsString( '## Newer', $output );
		$this->assertStringContainsString( 'Newer complete body.', $output );
		$this->assertStringNotContainsString( '<p>', $output );
	}

	public function test_full_publication_fails_instead_of_returning_a_partial_body(): void {
		$this->install_post(
			1,
			'Oversized',
			str_repeat( 'x', LLMS::FULL_OUTPUT_MAX_BYTES + 1 ),
			'2026-01-01 00:00:00'
		);

		$this->expectException( PublicationSizeLimitException::class );
		$this->expectExceptionMessage( 'No partial output was returned or written' );

		( new LLMS() )->get_llms_content( true, true );
	}

	public function test_full_publication_is_never_written_to_a_transient(): void {
		$this->install_post( 1, 'Complete', '<p>Complete literal body.</p>', '2026-01-01 00:00:00' );

		( new LLMS() )->get_llms_content( true );

		$this->assertFalse( get_transient( LLMS::FULL_CACHE_KEY ) );
	}

	public function test_summary_slice_does_not_leak_an_unclosed_nonvisible_block(): void {
		$extractor = new VisibleTextExtractor();
		$post      = (object) array(
			'post_excerpt' => '',
			'post_content' => '<p>Visible first.</p><script>' . str_repeat( 'secret ', 50000 ),
		);

		$this->assertSame( 'Visible first.', $extractor->summary( $post, 40 ) );
	}

	public function test_shared_guidance_is_published_in_summary_and_full_llms_output(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['llms_custom_instructions'] =
			"Prefer the canonical documentation.\r\nCite original URLs.";

		$summary = ( new LLMS() )->get_llms_content( false, true );
		$full    = ( new LLMS() )->get_llms_content( true, true );

		$this->assertStringContainsString(
			"Publisher guidance: Prefer the canonical documentation.\nCite original URLs.",
			$summary
		);
		$this->assertStringContainsString(
			"## Publisher guidance\n\nPrefer the canonical documentation.\nCite original URLs.",
			$full
		);
	}

	public function test_budgeted_briefing_is_transparent_and_has_no_semantic_claims(): void {
		$this->install_post( 1, 'First', str_repeat( 'Literal evidence. ', 10 ), '2026-01-02 00:00:00' );
		$settings = array(
			'llms_included_types'     => array( 'post' ),
			'llms_tldr_token_budget'  => 1000,
			'llms_pinned_ids'         => '1',
		);

		$result = ( new LLMSTLDRGenerator() )->generate_publication( $settings, 'Test Site' );

		$this->assertStringStartsWith( '# Test Site — Budgeted Site Briefing', $result['output'] );
		$this->assertStringContainsString( 'Status: Experimental vendor proposal', $result['output'] );
		$this->assertStringContainsString( 'Known-Automatic-Consumers: none documented', $result['output'] );
		$this->assertStringContainsString( 'Coverage: selected 1 of 1 eligible; omitted 0; partial 0', $result['output'] );
		$this->assertStringNotContainsString( 'semantic', strtolower( $result['output'] ) );
		$this->assertLessThanOrEqual( 1000, TokenBudget::estimate_tokens( $result['output'] ) );
	}

	public function test_priority_ids_change_literal_selection_order(): void {
		$this->install_post( 1, 'Newest', 'Newest body.', '2026-01-02 00:00:00' );
		$this->install_post( 2, 'Priority', 'Priority body.', '2025-01-01 00:00:00' );

		$result = ( new LLMSTLDRGenerator() )->generate_publication(
			array(
				'llms_included_types'    => array( 'post' ),
				'llms_tldr_token_budget' => 2000,
				'llms_pinned_ids'        => '2',
			),
			'Test Site'
		);

		$this->assertLessThan(
			strpos( $result['output'], '## Newest' ),
			strpos( $result['output'], '## Priority' )
		);
	}

	public function test_get_content_uses_transient_cache(): void {
		global $cybermaps_mock_transients;
		$cybermaps_mock_transients[ LLMSTLDR::CACHE_KEY ] = "# Cached briefing\n";

		$this->assertStringContainsString( 'Cached', ( new LLMSTLDR() )->get_content() );
	}

	public function test_large_briefing_stops_at_candidate_scan_limit_and_is_cacheable(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings']['llms_tldr_token_budget'] =
			\Cybermaps\Discovery\PublicationConstraints::BRIEFING_TOKEN_BUDGET_MAX;
		for ( $id = 1; $id <= 1500; ++$id ) {
			$this->install_post(
				$id,
				'Resource ' . $id,
				str_repeat( 'literal ', 80 ),
				'2026-01-01 00:00:00'
			);
		}

		$output = ( new LLMSTLDR() )->get_content();

		$this->assertStringContainsString( 'Candidate-Scan: scanned 250 of at most 1500 candidates; truncated yes', $output );
		$this->assertLessThan( 512 * 1024, strlen( $output ) );
		$this->assertFalse( get_transient( LLMSTLDR::CACHE_KEY ) );
	}

	public function test_localized_publications_never_reuse_canonical_cache_entries(): void {
		$GLOBALS['cybermaps_mock_transients'][ LLMS::SUMMARY_CACHE_KEY ] = "# Cached canonical LLMS\n";
		$GLOBALS['cybermaps_mock_transients'][ LLMSTLDR::CACHE_KEY ]     = "# Cached canonical briefing\n";

		$localized_llms = ( new LLMS() )->get_llms_content( false, false, 'en' );
		$localized_tldr = ( new LLMSTLDR() )->get_content( false, 'en' );

		$this->assertStringNotContainsString( 'Cached canonical', $localized_llms );
		$this->assertStringNotContainsString( 'Cached canonical', $localized_tldr );
	}

	public function test_invalidate_cache_clears_transient(): void {
		global $cybermaps_mock_transients;
		$cybermaps_mock_transients[ LLMSTLDR::CACHE_KEY ] = 'old';

		LLMSTLDR::invalidate_cache();

		$this->assertFalse( get_transient( LLMSTLDR::CACHE_KEY ) );
	}

	private function install_post( int $id, string $title, string $content, string $modified ): void {
		$GLOBALS['cybermaps_mock_posts'][ $id ] = (object) array(
			'ID'                => $id,
			'post_title'        => $title,
			'post_content'      => $content,
			'post_excerpt'      => '',
			'post_type'         => 'post',
			'post_status'       => 'publish',
			'post_password'     => '',
			'post_modified_gmt' => $modified,
			'post_date_gmt'     => $modified,
		);
	}
}
