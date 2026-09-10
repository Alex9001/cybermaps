<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\SettingsPage;
use Cybermaps\Sitemap\Orchestrator;
use PHPUnit\Framework\TestCase;

final class RegenerationNoticeTest extends TestCase {

	public function test_redirect_args_preserve_structured_status_and_bound_counts(): void {
		$args = Orchestrator::get_regeneration_notice_args(
			array(
				'status'  => 'partial',
				'success' => false,
				'mode'    => 'all',
				'counts'  => array(
					'desired'    => 8,
					'written'    => 3,
					'unchanged'  => 2,
					'conflicted' => 1,
					'failed'     => 1,
					'skipped'    => 1,
					'deleted'    => -4,
					'retained'   => 2,
				),
			)
		);

		$this->assertSame( '1', $args['cybermaps_regenerated'] );
		$this->assertSame( 'partial', $args['cybermaps_regeneration_status'] );
		$this->assertSame( '0', $args['cybermaps_regeneration_success'] );
		$this->assertSame( 'all', $args['cybermaps_regeneration_mode'] );
		$this->assertSame( '8', $args['cybermaps_regeneration_desired'] );
		$this->assertSame( '1', $args['cybermaps_regeneration_conflicted'] );
		$this->assertSame( '0', $args['cybermaps_regeneration_deleted'] );
		$this->assertSame( '2', $args['cybermaps_regeneration_retained'] );
	}

	public function test_invalid_report_cannot_be_redirected_as_success(): void {
		$args = Orchestrator::get_regeneration_notice_args(
			array(
				'status'  => 'unexpected',
				'success' => true,
				'mode'    => 'unexpected',
			)
		);

		$this->assertSame( 'failed', $args['cybermaps_regeneration_status'] );
		$this->assertSame( '0', $args['cybermaps_regeneration_success'] );
		$this->assertSame( 'off', $args['cybermaps_regeneration_mode'] );
	}

	public function test_notice_types_follow_every_static_sync_outcome(): void {
		$cases = array(
			array( 'complete', true, 'all', 'success', 'static publication completed' ),
			array( 'partial', false, 'all', 'warning', 'only partially' ),
			array( 'failed', false, 'all', 'error', 'static publication failed' ),
			array( 'busy', false, 'all', 'warning', 'requested a background retry' ),
			array( 'skipped', true, 'off', 'info', 'intentionally skipped' ),
			array( 'skipped', false, 'off', 'warning', 'without a successful result' ),
		);

		foreach ( $cases as [ $status, $success, $mode, $expected_type, $expected_phrase ] ) {
			$query  = Orchestrator::get_regeneration_notice_args(
				array(
					'status'  => $status,
					'success' => $success,
					'mode'    => $mode,
					'counts'  => array(
						'desired'    => 4,
						'written'    => 1,
						'unchanged'  => 1,
						'conflicted' => 1,
						'failed'     => 1,
					),
				)
			);
			$notice = SettingsPage::get_regeneration_notice( $query );

			$this->assertNotNull( $notice, 'A regeneration result should always produce a notice.' );
			$this->assertSame( $expected_type, $notice['type'], 'Unexpected notice type for ' . $status . '.' );
			$this->assertStringContainsStringIgnoringCase( $expected_phrase, $notice['message'] );
			$this->assertStringContainsString( 'desired: 4', $notice['message'] );
			$this->assertStringContainsString( 'conflicted: 1', $notice['message'] );
		}
	}

	public function test_pending_notice_reports_background_continuation_as_in_progress(): void {
		$notice = SettingsPage::get_regeneration_notice(
			array(
				'cybermaps_regenerated'          => '1',
				'cybermaps_regeneration_status'  => 'pending',
				'cybermaps_regeneration_success' => '0',
				'cybermaps_regeneration_mode'    => 'all',
				'cybermaps_regeneration_desired' => '12',
				'cybermaps_regeneration_written' => '5',
			)
		);

		$this->assertNotNull( $notice );
		$this->assertSame( 'info', $notice['type'] );
		$this->assertStringContainsString( 'in progress', $notice['message'] );
		$this->assertStringContainsString( 'queued for background continuation', $notice['message'] );
		$this->assertStringContainsString( 'desired: 12', $notice['message'] );
		$this->assertStringContainsString( 'written: 5', $notice['message'] );
	}

	public function test_complete_dynamic_mode_is_reported_as_reconciliation_not_static_publication(): void {
		$query  = Orchestrator::get_regeneration_notice_args(
			array(
				'status'  => 'complete',
				'success' => true,
				'mode'    => 'off',
				'counts'  => array( 'deleted' => 2 ),
			)
		);
		$notice = SettingsPage::get_regeneration_notice( $query );

		$this->assertNotNull( $notice );
		$this->assertSame( 'success', $notice['type'] );
		$this->assertStringContainsString( 'Dynamic delivery is active', $notice['message'] );
		$this->assertStringContainsString( 'deleted: 2', $notice['message'] );
	}

	public function test_legacy_flag_without_a_structured_result_is_not_called_success(): void {
		$notice = SettingsPage::get_regeneration_notice(
			array( 'cybermaps_regenerated' => '1' )
		);

		$this->assertNotNull( $notice );
		$this->assertSame( 'warning', $notice['type'] );
		$this->assertStringContainsString( 'no valid static publication result', $notice['message'] );
	}

	public function test_unrelated_request_has_no_regeneration_notice(): void {
		$this->assertNull( SettingsPage::get_regeneration_notice( array() ) );
	}

	public function test_static_intent_resolution_notices_are_bounded_and_truthful(): void {
		$success = SettingsPage::get_intent_recovery_notice(
			array(
				'cybermaps_intent_resolution'         => '1',
				'cybermaps_intent_resolution_success' => '1',
				'cybermaps_intent_resolution_code'    => 'resolved',
			)
		);
		$this->assertSame( 'success', $success['type'] );
		$this->assertStringContainsString( 'was reconciled', $success['message'] );

		$incomplete = SettingsPage::get_intent_recovery_notice(
			array(
				'cybermaps_intent_resolution'         => '1',
				'cybermaps_intent_resolution_success' => '0',
				'cybermaps_intent_resolution_code'    => 'recovery_incomplete',
			)
		);
		$this->assertSame( 'error', $incomplete['type'] );
		$this->assertStringContainsString( 'preserved for review', $incomplete['message'] );
		$this->assertNull( SettingsPage::get_intent_recovery_notice( array() ) );
	}
}
