<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Audit;

use Cybermaps\Audit\AuditExporter;
use Cybermaps\Audit\AuditPolicy;
use Cybermaps\Audit\ContentAuditEvaluator;
use Cybermaps\Audit\ContentAuditService;
use Cybermaps\Audit\DiscoveryReportExporter;
use Cybermaps\Audit\ReportPresentation;
use PHPUnit\Framework\TestCase;

final class ContentAuditTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['cybermaps_mock_options'] = array(
			'blog_public'        => '1',
			'cybermaps_settings' => array(),
		);
		$GLOBALS['cybermaps_mock_posts'] = array();
		$GLOBALS['cybermaps_mock_post_meta'] = array();
		$GLOBALS['cybermaps_mock_filter_callbacks'] = array();
		$GLOBALS['cybermaps_mock_post_types'] = array( 'post', 'page', 'portfolio' );
		$GLOBALS['cybermaps_mock_post_type_objects'] = array(
			'post' => (object) array( 'public' => true ),
			'page' => (object) array( 'public' => true ),
		);
	}

	public function test_default_policy_matches_professional_post_and_page_rules(): void {
		$policy = AuditPolicy::from_settings( array() );

		$this->assertSame(
			array( 'min_words' => 300, 'max_age_days' => 365, 'require_media' => true ),
			$policy->for_post_type( 'post' )
		);
		$this->assertSame(
			array( 'min_words' => 150, 'max_age_days' => 0, 'require_media' => false ),
			$policy->for_post_type( 'page' )
		);
	}

	public function test_post_findings_include_exact_measurements_without_a_score(): void {
		$now  = strtotime( '2026-07-26 00:00:00 UTC' );
		$post = $this->post(
			1,
			'post',
			implode( ' ', array_fill( 0, 250, 'word' ) ),
			'2025-01-01 00:00:00'
		);

		$result = ( new ContentAuditEvaluator() )->evaluate( $post, AuditPolicy::from_settings( array() ), $now );
		$keys   = array_column( $result['findings'], 'key' );

		$this->assertSame( 250, $result['resource']['word_count'] );
		$this->assertContains( 'thin_content', $keys );
		$this->assertContains( 'stale_content', $keys );
		$this->assertContains( 'missing_media', $keys );
		$this->assertArrayNotHasKey( 'score', $result['resource'] );
		$this->assertSame( 300, $result['findings'][0]['evidence']['minimum_words'] );
	}

	public function test_page_has_no_default_age_or_media_finding(): void {
		$post   = $this->post( 2, 'page', implode( ' ', array_fill( 0, 100, 'word' ) ), '2020-01-01 00:00:00' );
		$result = ( new ContentAuditEvaluator() )->evaluate(
			$post,
			AuditPolicy::from_settings( array() ),
			strtotime( '2026-07-26 00:00:00 UTC' )
		);

		$this->assertSame( array( 'thin_content' ), array_column( $result['findings'], 'key' ) );
	}

	public function test_finding_summaries_pluralize_measured_units(): void {
		$policy = AuditPolicy::from_settings(
			array(
				'audit_post_min_words'    => 2,
				'audit_post_max_age_days' => 1,
				'audit_post_require_media' => '0',
			)
		);
		$post = $this->post( 20, 'post', 'word', '2026-07-24 00:00:00' );

		$result = ( new ContentAuditEvaluator() )->evaluate(
			$post,
			$policy,
			strtotime( '2026-07-26 00:00:00 UTC' ),
			true
		);

		$this->assertSame( 'Visible text has 1 word; policy minimum is 2 words.', $result['findings'][0]['summary'] );
		$this->assertSame( 'Last modified 2 days ago; review interval is 1 day.', $result['findings'][1]['summary'] );
	}

	public function test_stored_image_block_satisfies_post_media_rule(): void {
		$post = $this->post(
			3,
			'post',
			'<!-- wp:image {"id":9} --><figure><img src="image.jpg" alt=""></figure><!-- /wp:image --> '
				. implode( ' ', array_fill( 0, 310, 'word' ) ),
			'2026-07-01 00:00:00'
		);
		$result = ( new ContentAuditEvaluator() )->evaluate(
			$post,
			AuditPolicy::from_settings( array() ),
			strtotime( '2026-07-26 00:00:00 UTC' )
		);

		$this->assertTrue( $result['resource']['has_media'] );
		$this->assertNotContains( 'missing_media', array_column( $result['findings'], 'key' ) );
	}

	public function test_search_nonindexable_resource_is_measured_without_optimization_findings(): void {
		$post = $this->post( 5, 'post', 'Short text.', '2020-01-01 00:00:00' );
		$post->post_password = 'private';

		$result = ( new ContentAuditEvaluator() )->evaluate(
			$post,
			AuditPolicy::from_settings( array() ),
			strtotime( '2026-07-26 00:00:00 UTC' )
		);

		$this->assertFalse( $result['resource']['indexable'] );
		$this->assertSame( array(), $result['findings'] );
		$this->assertContains( 'password_protected', $result['resource']['indexability']['reasons'] );
	}

	public function test_channel_exclusions_do_not_hide_search_indexable_content_findings(): void {
		$post = $this->post( 6, 'post', 'Short text.', '2020-01-01 00:00:00' );
		$GLOBALS['cybermaps_mock_post_meta'][6]['_cybermaps_exclude_ai']      = '1';
		$GLOBALS['cybermaps_mock_post_meta'][6]['_cybermaps_exclude_sitemap'] = '1';

		$result = ( new ContentAuditEvaluator() )->evaluate(
			$post,
			AuditPolicy::from_settings( array() ),
			strtotime( '2026-07-26 00:00:00 UTC' ),
			false
		);

		$this->assertTrue( $result['resource']['indexable'] );
		$this->assertContains( 'thin_content', array_column( $result['findings'], 'key' ) );
		$this->assertNotContains( 'cybermaps_ai_exclusion', $result['resource']['indexability']['reasons'] );
		$this->assertNotContains( 'cybermaps_sitemap_exclusion', $result['resource']['indexability']['reasons'] );
	}

	public function test_report_eligibility_extension_filter_remains_authoritative(): void {
		$post = $this->post( 9, 'post', 'Short text.', '2020-01-01 00:00:00' );
		$GLOBALS['cybermaps_mock_filter_callbacks']['cybermaps_publication_eligibility'][] = static function (
			\Cybermaps\SEO\IndexabilityDecision $decision,
			mixed $context,
			string $channel
		): \Cybermaps\SEO\IndexabilityDecision {
			unset( $context );
			if ( \Cybermaps\SEO\PublicationEligibility::REPORT !== $channel ) {
				return $decision;
			}
			return new \Cybermaps\SEO\IndexabilityDecision(
				false,
				array( 'extension_report_exclusion' )
			);
		};

		$result = ( new ContentAuditEvaluator() )->evaluate(
			$post,
			AuditPolicy::from_settings( array() ),
			strtotime( '2026-07-26 00:00:00 UTC' ),
			false
		);

		$this->assertFalse( $result['resource']['indexable'] );
		$this->assertSame( array(), $result['findings'] );
		$this->assertContains( 'extension_report_exclusion', $result['resource']['indexability']['reasons'] );
	}

	public function test_explicit_attached_image_measurement_avoids_a_false_media_finding(): void {
		$post = $this->post(
			7,
			'post',
			implode( ' ', array_fill( 0, 310, 'word' ) ),
			'2026-07-01 00:00:00'
		);

		$result = ( new ContentAuditEvaluator() )->evaluate(
			$post,
			AuditPolicy::from_settings( array() ),
			strtotime( '2026-07-26 00:00:00 UTC' ),
			true
		);

		$this->assertTrue( $result['resource']['has_media'] );
		$this->assertNotContains( 'missing_media', array_column( $result['findings'], 'key' ) );
	}

	public function test_zero_thumbnail_meta_is_not_counted_as_media(): void {
		$post = $this->post(
			8,
			'post',
			implode( ' ', array_fill( 0, 310, 'word' ) ),
			'2026-07-01 00:00:00'
		);
		$GLOBALS['cybermaps_mock_post_meta'][8]['_thumbnail_id'] = '0';

		$result = ( new ContentAuditEvaluator() )->evaluate(
			$post,
			AuditPolicy::from_settings( array() ),
			strtotime( '2026-07-26 00:00:00 UTC' ),
			false
		);

		$this->assertFalse( $result['resource']['has_media'] );
		$this->assertContains( 'missing_media', array_column( $result['findings'], 'key' ) );
	}

	public function test_diff_uses_resource_and_finding_identity(): void {
		$service = new ContentAuditService();
		$baseline = array(
			'id'       => 7,
			'findings' => array(
				array( 'resource_key' => 'post:1:1', 'finding_key' => 'thin_content' ),
				array( 'resource_key' => 'post:1:2', 'finding_key' => 'stale_content' ),
			),
		);
		$current = array(
			'id'       => 8,
			'findings' => array(
				array( 'resource_key' => 'post:1:1', 'finding_key' => 'thin_content' ),
				array( 'resource_key' => 'post:1:3', 'finding_key' => 'missing_media' ),
			),
		);

		$diff = $service->compare( $current, $baseline );

		$this->assertCount( 1, $diff['added'] );
		$this->assertCount( 1, $diff['resolved'] );
		$this->assertCount( 1, $diff['persisting'] );
		$this->assertSame( 7, $diff['baseline_run_id'] );
	}

	public function test_diff_omits_link_findings_when_baseline_link_coverage_is_not_comparable(): void {
		$baseline = array(
			'id'       => 12,
			'analysis' => array(),
			'findings' => array(
				array( 'resource_key' => 'post:1:1', 'finding_key' => 'thin_content' ),
				array( 'resource_key' => 'post:1:2', 'finding_key' => 'potential_orphan' ),
			),
		);
		$current  = array(
			'id'       => 13,
			'analysis' => array(
				'internal_link_version' => 1,
				'complete'              => true,
			),
			'findings' => array(
				array( 'resource_key' => 'post:1:1', 'finding_key' => 'thin_content' ),
				array( 'resource_key' => 'post:1:3', 'finding_key' => 'deeply_linked' ),
			),
		);

		$diff = ( new ContentAuditService() )->compare( $current, $baseline );

		$this->assertFalse( $diff['internal_links_comparable'] );
		$this->assertSame( array(), $diff['added'] );
		$this->assertSame( array(), $diff['resolved'] );
		$this->assertCount( 1, $diff['persisting'] );
	}

	public function test_exports_use_preserved_findings_and_escape_spreadsheet_formulas(): void {
		$run = array(
			'id'              => 9,
			'completed_gmt'   => '2026-07-26 12:00:00',
			'resource_count'  => 1,
			'finding_count'   => 1,
			'resources'       => array(),
			'diff'            => array( 'baseline_run_id' => 8, 'added' => array(), 'resolved' => array(), 'persisting' => array() ),
			'findings'        => array(
				array(
					'resource_key'  => 'post:1:4',
					'post_type'     => 'post',
					'object_id'     => 4,
					'title'         => '  =IMPORTXML("bad")',
					'url'           => 'https://example.com/item',
					'finding_key'   => 'thin_content',
					'severity'      => 'warning',
					'summary'       => '100 words; minimum 300.',
					'evidence'      => array( 'actual_words' => 100, 'minimum_words' => 300 ),
					'recommendation' => 'Review it.',
				),
			),
		);
		$exporter = new AuditExporter();

		$csv = $exporter->csv( $run );
		$this->assertSame( $csv, implode( '', iterator_to_array( $exporter->csv_chunks( $run ) ) ) );
		$this->assertStringContainsString( '\'  =IMPORTXML', $csv );
		$this->assertStringContainsString( 'Generated by CYBER MAPS', $exporter->html( $run ) );
		$this->assertStringContainsString( 'href="https://cybermaps.dev" target="_blank"', $exporter->html( $run ) );
		$this->assertStringContainsString( '<html lang="en-US">', $exporter->html( $run ) );
		$this->assertStringContainsString( '<meta name="referrer" content="no-referrer">', $exporter->html( $run ) );
		$this->assertStringNotContainsString( 'Immutable evidence snapshot', $exporter->html( $run ) );
		$this->assertStringNotContainsString( 'Evidence-based content findings', $exporter->html( $run ) );
		$this->assertStringContainsString( '"finding_count": 1', $exporter->json( $run ) );
	}

	public function test_printable_reports_restore_branding_themes_and_professional_sections(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'report_theme'      => 'midnight',
			'agency_name'       => 'Example Studio',
			'site_name_override' => 'Example Client',
		);
		$run = array(
			'id'             => 10,
			'completed_gmt'  => '2026-07-27 12:00:00',
			'resource_count' => 4,
			'finding_count'  => 1,
			'diff'           => array( 'baseline_run_id' => 9, 'added' => array(), 'resolved' => array(), 'persisting' => array() ),
			'findings'       => array(
				array(
					'post_type'      => 'post',
					'object_id'      => 7,
					'title'          => 'Short article',
					'url'            => 'https://example.com/short',
					'finding_key'    => 'thin_content',
					'summary'        => '100 words; minimum 300.',
					'recommendation' => 'Review it.',
				),
			),
		);

		$html = ( new AuditExporter() )->html( $run );

		$this->assertStringContainsString( 'Content Intelligence Report', $html );
		$this->assertStringContainsString( 'Action inventory', $html );
		$this->assertStringContainsString( 'Added since baseline', $html );
		$this->assertStringContainsString( 'Example Studio', $html );
		$this->assertStringContainsString( '--cmr-bg:#0f172a', $html );
		$this->assertArrayHasKey( 'cyberbrand', ReportPresentation::themes() );
		$this->assertStringNotContainsString( 'Global Authority', $html );

		$run['report_filter'] = 'thin_content';
		$this->assertStringContainsString( 'Thin Content Action Report', ( new AuditExporter() )->html( $run ) );

		$run['diff'] = array( 'baseline_run_id' => 0, 'added' => $run['findings'], 'resolved' => array(), 'persisting' => array() );
		$baseline_html = ( new AuditExporter() )->html( $run );
		$this->assertStringContainsString( '<span>Comparison</span><strong>Baseline</strong>', $baseline_html );
		$this->assertStringNotContainsString( 'Added since baseline', $baseline_html );
	}

	public function test_report_identity_revalidates_saved_and_legacy_url_values(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'agency_name' => 'Example Agency',
			'agency_url'  => 'javascript:alert(1)',
			'agency_logo' => 'data:image/svg+xml,<svg onload=alert(1)>',
		);
		$identity = ReportPresentation::identity();

		$this->assertSame( 'Example Agency', $identity['agency_name'] );
		$this->assertSame( '', $identity['agency_url'] );
		$this->assertSame( '', $identity['agency_logo'] );
	}

	public function test_report_identity_uses_the_configured_public_frontend(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_settings'] = array(
			'frontend_base_url' => 'https://frontend.example/app',
		);

		$this->assertSame(
			'https://frontend.example/app/',
			ReportPresentation::identity()['site_url']
		);
	}

	public function test_discovery_report_is_a_time_stamped_inventory_without_a_synthetic_score(): void {
		$status = array(
				'active_count'     => 1,
				'error_count'      => 0,
				'unverified_count' => 0,
				'endpoints'        => array(
					array(
						'label'             => 'LLMS',
						'path'              => '/llms.txt',
						'description'       => 'Machine-readable site guide.',
						'type'              => 'text/plain',
						'content_type'      => 'text/plain; charset=utf-8',
						'content_type_valid' => true,
						'body_valid'        => true,
						'intended_delivery' => 'dynamic',
						'status'            => 'healthy',
						'message'           => 'Validated.',
						'static_file'       => '/var/www/private/.well-known/llms.txt',
					),
					array(
						'label'             => 'Optional briefing',
						'path'              => '/llms-tldr.txt',
						'description'       => 'Optional summary.',
						'type'              => 'text/plain',
						'intended_delivery' => 'disabled',
						'status'            => 'disabled',
						'message'           => 'Disabled.',
					),
				),
			);
		$exporter = new DiscoveryReportExporter();
		$html     = $exporter->html( $status );
		$json     = $exporter->json( $status );

		$this->assertStringContainsString( 'AI Discovery Publication Report', $html );
		$this->assertStringContainsString( '/llms.txt', $html );
		$this->assertStringContainsString( 'point-in-time HTTP validation', $html );
		$this->assertStringContainsString( 'Disabled', $html );
		$this->assertStringNotContainsString( 'Authority Score', $html );
		$this->assertStringNotContainsString( '/var/www/private', $json );
		$this->assertStringContainsString( '"disabled_count": 1', $json );
		$this->assertStringContainsString( '"observed_type": "text/plain; charset=utf-8"', $json );
		$this->assertStringContainsString( '"content_type_valid": true', $json );
		$this->assertStringContainsString( '"body_valid": true', $json );
		$this->assertStringContainsString( '<meta name="referrer" content="no-referrer">', $html );
	}

	private function post( int $id, string $type, string $content, string $modified ): object {
		$post = (object) array(
			'ID'                => $id,
			'post_title'        => 'Resource ' . $id,
			'post_content'      => $content,
			'post_excerpt'      => '',
			'post_type'         => $type,
			'post_status'       => 'publish',
			'post_password'     => '',
			'post_modified_gmt' => $modified,
			'post_date_gmt'     => $modified,
		);
		$GLOBALS['cybermaps_mock_posts'][ $id ] = $post;
		return $post;
	}
}
