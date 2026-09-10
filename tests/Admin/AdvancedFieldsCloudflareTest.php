<?php
/**
 * Advanced Cloudflare field regression tests.
 *
 * @package Cybermaps\Tests\Admin
 */

declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\Settings\Tabs\Advanced\AdvancedFields;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AdvancedFieldsCloudflareTest extends TestCase {
	public function test_confirmation_row_uses_native_hidden_attribute_when_detected(): void {
		$html = $this->render_detection( true );

		$this->assertMatchesRegularExpression( '/id="cybermaps-cloudflare-confirm-row"\s+hidden>/', $html );
		$this->assertStringContainsString( 'id="cybermaps-cloudflare-confirm"', $html );
	}

	public function test_confirmation_row_remains_visible_when_detection_fails(): void {
		$html = $this->render_detection( false );

		$this->assertStringContainsString( 'id="cybermaps-cloudflare-confirm-row"', $html );
		$this->assertDoesNotMatchRegularExpression( '/id="cybermaps-cloudflare-confirm-row"\s+hidden>/', $html );
	}

	private function render_detection( bool $detected ): string {
		$method = new ReflectionMethod( AdvancedFields::class, 'render_cloudflare_detection' );
		ob_start();
		$method->invoke( null, $detected, 'example.com' );

		return (string) ob_get_clean();
	}
}
