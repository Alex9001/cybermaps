<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\SitemapStatus;
use PHPUnit\Framework\TestCase;

final class SitemapStatusTest extends TestCase {
	public function test_sitemap_reference_matches_robots_advertising_controls(): void {
		$this->assertTrue(
			SitemapStatus::robots_sitemap_reference_configured(
				array( 'inject_robots' => '1' ),
				array(),
				true
			)
		);
		$this->assertTrue(
			SitemapStatus::robots_sitemap_reference_configured(
				array(),
				array( 'takeover_enabled' => true ),
				true
			)
		);
	}

	public function test_manual_rules_and_content_signals_do_not_imply_a_sitemap_reference(): void {
		$this->assertFalse(
			SitemapStatus::robots_sitemap_reference_configured(
				array( 'inject_robots' => '0' ),
				array(
					'manual_directives' => 'Disallow: /private/',
					'content_signals'   => array( 'ai-train' => 'no' ),
				),
				true
			)
		);
	}

	public function test_private_site_never_reports_a_public_sitemap_reference(): void {
		$this->assertFalse(
			SitemapStatus::robots_sitemap_reference_configured(
				array( 'inject_robots' => '1' ),
				array( 'takeover_enabled' => true ),
				false
			)
		);
	}

	public function test_reconciliation_states_have_human_labels_and_semantic_tones(): void {
		$label = new \ReflectionMethod( SitemapStatus::class, 'reconciliation_status_label' );
		$tone  = new \ReflectionMethod( SitemapStatus::class, 'reconciliation_status_tone' );

		$this->assertSame( 'Not run', $label->invoke( null, 'not-run' ) );
		$this->assertSame( 'Unknown', $label->invoke( null, 'unexpected-state' ) );
		$this->assertSame( 'good', $tone->invoke( null, 'complete' ) );
		$this->assertSame( 'warning', $tone->invoke( null, 'partial' ) );
		$this->assertSame( 'error', $tone->invoke( null, 'failed' ) );
		$this->assertSame( 'neutral', $tone->invoke( null, 'not-run' ) );
		$this->assertSame( 'Continuation queued', $label->invoke( null, 'pending' ) );
		$this->assertSame( 'warning', $tone->invoke( null, 'pending' ) );
	}

	public function test_persisted_pending_reconciliation_is_displayed_as_queued(): void {
		$prior = $GLOBALS['cybermaps_mock_options'] ?? array();
		try {
			update_option( 'cybermaps_last_static_sync_report', array( 'status' => 'pending' ) );
			$context = ( new \ReflectionMethod( SitemapStatus::class, 'status_context' ) )->invoke( null, array( 'static_engine_mode' => 'all' ) );
			$this->assertSame( 'pending', $context['sync_status'] );
			$this->assertSame( 'Continuation queued', $context['sync_status_label'] );
			ob_start();
			( new \ReflectionMethod( SitemapStatus::class, 'render_full_mode_notice' ) )->invoke( null, $context['sync_status_label'], 'today', 'yesterday' );
			$html = (string) ob_get_clean();
			$this->assertStringContainsString( 'Latest reconciliation: Continuation queued', $html );
			$this->assertStringNotContainsString( 'Unknown', $html );
		} finally { $GLOBALS['cybermaps_mock_options'] = $prior; }
	}

	public function test_php_path_probe_uses_a_unique_query_and_matching_challenge_header(): void {
		$challenge = 'AbCdEf0123456789AbCdEf0123456789';
		$method    = new \ReflectionMethod( SitemapStatus::class, 'diagnostic_probe_request' );
		$probe     = $method->invoke( null, 'https://example.com/site-map.xml', $challenge );

		$this->assertSame(
			'https://example.com/site-map.xml?cybermaps_php_path_probe=' . $challenge,
			$probe['url']
		);
		$this->assertSame( $challenge, $probe['args']['headers']['X-Cybermaps-Diagnostic-Challenge'] );
		$this->assertSame( '1', $probe['args']['headers']['X-Cybermaps-Diagnostic'] );
		$prior = $_SERVER['HTTP_X_CYBERMAPS_DIAGNOSTIC'] ?? null;
		$_SERVER['HTTP_X_CYBERMAPS_DIAGNOSTIC'] = $probe['args']['headers']['X-Cybermaps-Diagnostic'];
		try {
			$this->assertTrue( ( new \ReflectionMethod( \Cybermaps\Admin\CrawlerAnalyticsRecorder::class, 'is_diagnostic_request' ) )->invoke( new \Cybermaps\Admin\CrawlerAnalyticsRecorder() ) );
		} finally {
			if ( null === $prior ) { unset( $_SERVER['HTTP_X_CYBERMAPS_DIAGNOSTIC'] ); } else { $_SERVER['HTTP_X_CYBERMAPS_DIAGNOSTIC'] = $prior; }
		}
		$this->assertSame( 'no-cache, no-store', $probe['args']['headers']['Cache-Control'] );
	}

	public function test_php_path_probe_accepts_only_the_exact_response_challenge(): void {
		$challenge = 'AbCdEf0123456789AbCdEf0123456789';
		$method    = new \ReflectionMethod( SitemapStatus::class, 'diagnostic_response_matches' );
		$response  = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array( 'X-Cybermaps-Diagnostic-Response' => $challenge ),
		);

		$this->assertTrue( $method->invoke( null, $response, $challenge ) );
		$response['headers']['X-Cybermaps-Diagnostic-Response'] = strtolower( $challenge );
		$this->assertFalse( $method->invoke( null, $response, $challenge ) );
		$response['headers']['X-Cybermaps-Diagnostic-Response'] = $challenge;
		$response['response']['code']                           = 304;
		$this->assertFalse( $method->invoke( null, $response, $challenge ) );
	}

	public function test_occupancy_status_rejects_state_from_an_obsolete_token_fence(): void {
		$method           = new \ReflectionMethod( SitemapStatus::class, 'effective_occupancy_status' );
		$prior_generation = get_option( 'cybermaps_sitemap_occupancy_generation', null );
		$prior_token      = get_option( 'cybermaps_sitemap_occupancy_token', null );
		update_option( 'cybermaps_sitemap_occupancy_generation', 5, false );
		update_option( 'cybermaps_sitemap_occupancy_token', str_repeat( 'a', 32 ), false );

		try {
			$this->assertSame(
				'ready',
				$method->invoke(
					null,
					array(
						'status'     => 'ready',
						'generation' => 5,
						'token'      => str_repeat( 'a', 32 ),
					)
				)
			);
			$this->assertSame(
				'stale',
				$method->invoke(
					null,
					array(
						'status'     => 'ready',
						'generation' => 5,
						'token'      => str_repeat( 'b', 32 ),
					)
				)
			);
		} finally {
			if ( null === $prior_generation ) {
				delete_option( 'cybermaps_sitemap_occupancy_generation' );
			} else {
				update_option( 'cybermaps_sitemap_occupancy_generation', $prior_generation, false );
			}
			if ( null === $prior_token ) {
				delete_option( 'cybermaps_sitemap_occupancy_token' );
			} else {
				update_option( 'cybermaps_sitemap_occupancy_token', $prior_token, false );
			}
		}
	}
}
