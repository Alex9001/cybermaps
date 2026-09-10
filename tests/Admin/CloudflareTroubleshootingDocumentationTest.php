<?php
/**
 * Cloudflare fallback documentation contract tests.
 *
 * @package Cybermaps\Tests\Admin
 */

declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use PHPUnit\Framework\TestCase;

final class CloudflareTroubleshootingDocumentationTest extends TestCase {
	public function test_token_fallback_documents_complete_least_privilege_workflow(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Admin/Settings/Tabs/Advanced/AdvancedFields.php' );

		$this->assertStringContainsString( 'Create a least-privilege temporary token', $source );
		$this->assertStringContainsString( 'Zone Read', $source );
		$this->assertStringContainsString( 'Zone Transform Rules Write', $source );
		$this->assertStringContainsString( 'Cache Settings Write', $source );
		$this->assertStringContainsString( 'Selecting all zones is unnecessary', $source );
		$this->assertStringContainsString( 'Cybermaps cannot revoke an API token', $source );
		$this->assertStringContainsString( 'Paste the same still-valid token again', $source );
		$this->assertStringContainsString( 'DNS Read or DNS Write does not replace Zone Read', $source );
		$this->assertStringContainsString( 'Remove Cybermaps rules from Cloudflare', $source );
		$this->assertStringContainsString( 'Approving removal does not install rules', $source );
	}
}
