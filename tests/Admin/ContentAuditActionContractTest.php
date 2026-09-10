<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

final class ContentAuditActionContractTest extends \WP_UnitTestCase {
	public function test_report_generation_uses_a_nonce_protected_post_action(): void {
		$tab_source = (string) file_get_contents(
			CYBERMAPS_PLUGIN_DIR . 'src/Admin/Settings/Tabs/ContentReview.php'
		);
		$manager_source = (string) file_get_contents(
			CYBERMAPS_PLUGIN_DIR . 'src/Admin/ContentAuditManager.php'
		);

		$this->assertStringContainsString( 'value="cybermaps_run_content_audit"', $tab_source );
		$this->assertStringContainsString( 'form="cybermaps-run-content-audit-form"', $tab_source );
		$this->assertStringNotContainsString( 'formmethod="post"', $tab_source );
		$this->assertStringContainsString( 'cybermaps_content_audit_nonce', $tab_source );
		$this->assertStringNotContainsString(
			'admin-post.php?action=cybermaps_run_content_audit',
			$tab_source
		);
		$this->assertStringContainsString( "'POST' !== \$request_method", $manager_source );
		$this->assertStringContainsString(
			"check_admin_referer( 'cybermaps_content_audit', 'cybermaps_content_audit_nonce' )",
			$manager_source
		);
		$this->assertStringContainsString( 'ContentAuditService::RUN_LOCKED_ERROR_CODE', $manager_source );
		$this->assertStringContainsString( "'cybermaps_report_busy' => '1'", $manager_source );
	}

	public function test_report_service_uses_an_expiring_ownership_safe_run_lock(): void {
		$service_source = (string) file_get_contents(
			CYBERMAPS_PLUGIN_DIR . 'src/Audit/ContentAuditService.php'
		);
		$repository_source = (string) file_get_contents(
			CYBERMAPS_PLUGIN_DIR . 'src/Audit/AuditRunRepository.php'
		);

		$this->assertStringContainsString( 'acquire_run_lock', $service_source );
		$this->assertStringContainsString( 'refresh_run_lock', $service_source );
		$this->assertStringContainsString( 'release_run_lock', $service_source );
		$this->assertStringContainsString( 'finally', $service_source );
		$this->assertStringContainsString( 'option_value', $repository_source );
		$this->assertStringContainsString( 'RUN_LOCK_OPTION', $repository_source );
	}

	public function test_report_exports_are_bounded_before_hydration_and_csv_is_chunked(): void {
		$manager_source = (string) file_get_contents(
			CYBERMAPS_PLUGIN_DIR . 'src/Admin/ContentAuditManager.php'
		);

		$this->assertStringContainsString( 'get_run_export_profile', $manager_source );
		$this->assertStringContainsString( 'MAX_CSV_EXPORT_FINDINGS', $manager_source );
		$this->assertStringContainsString( 'MAX_HTML_EXPORT_FINDING_ROWS', $manager_source );
		$this->assertStringContainsString( 'MAX_JSON_EXPORT_SNAPSHOT_ROWS', $manager_source );
		$this->assertStringContainsString( "'response' => 413", $manager_source );
		$this->assertStringContainsString( 'csv_chunks', $manager_source );
	}

	public function test_report_deletion_uses_a_nonce_protected_post_action(): void {
		$tab_source = (string) file_get_contents(
			CYBERMAPS_PLUGIN_DIR . 'src/Admin/Settings/Tabs/ContentReview.php'
		);
		$manager_source = (string) file_get_contents(
			CYBERMAPS_PLUGIN_DIR . 'src/Admin/ContentAuditManager.php'
		);

		$this->assertStringContainsString( 'value="cybermaps_delete_content_audit"', $tab_source );
		$this->assertStringContainsString( 'form="cybermaps-delete-content-audit-form"', $tab_source );
		$this->assertStringNotContainsString( 'formaction=', $tab_source );
		$this->assertStringContainsString( 'cybermaps_delete_nonce', $tab_source );
		$this->assertStringContainsString( 'cybermaps_delete_content_audit_', $manager_source );
		$this->assertStringContainsString( "is_scalar( \$_POST['run_id'] )", $manager_source );
		$this->assertGreaterThanOrEqual( 2, substr_count( $manager_source, "'POST' !== \$request_method" ) );
	}
}
