<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\AccessibleTooltip;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AccessibleTooltipTest extends TestCase {
	public function test_native_wordpress_toggletip_remains_the_default_when_available(): void {
		$html = AccessibleTooltip::get( 'Native help', 'tip-left' );

		$this->assertStringContainsString( 'wp-is-toggletip', $html );
		$this->assertStringContainsString( 'cybermaps-help-tip tip-left', $html );
		$this->assertStringContainsString( 'Native help', $html );
		$this->assertStringNotContainsString( 'cybermaps-legacy-toggletip__toggle', $html );
	}

	public function test_wordpress_70_fallback_has_unique_accessible_controls(): void {
		$renderer = new ReflectionMethod( AccessibleTooltip::class, 'get_legacy' );
		$first    = (string) $renderer->invoke( null, 'Legacy help', array( 'cybermaps-help-tip', 'tip-right' ) );
		$second   = (string) $renderer->invoke( null, 'Second help', array( 'cybermaps-help-tip' ) );

		$this->assertStringContainsString( 'cybermaps-legacy-toggletip', $first );
		$this->assertStringContainsString( 'aria-haspopup="dialog"', $first );
		$this->assertStringContainsString( 'aria-expanded="false"', $first );
		$this->assertStringContainsString( 'aria-controls="cybermaps-toggletip-', $first );
		$this->assertStringContainsString( 'role="dialog"', $first );
		$this->assertStringContainsString( 'tabindex="-1" hidden', $first );
		$this->assertStringContainsString( 'Legacy help', $first );
		$this->assertNotSame( $this->bubble_id( $first ), $this->bubble_id( $second ) );
	}

	public function test_fallback_renderer_escapes_content_classes_ids_and_labels(): void {
		$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Admin/AccessibleTooltip.php' );

		$this->assertStringContainsString( "esc_attr( implode( ' ', \$classes ) )", $source );
		$this->assertStringContainsString( 'esc_attr( $id )', $source );
		$this->assertStringContainsString( 'esc_attr( $label )', $source );
		$this->assertStringContainsString( 'esc_attr( $close_label )', $source );
		$this->assertStringContainsString( 'esc_html( $content )', $source );
	}

	private function bubble_id( string $html ): string {
		$this->assertSame( 1, preg_match( '/id="([^"]+)" class="cybermaps-legacy-toggletip__bubble"/', $html, $match ) );
		return (string) ( $match[1] ?? '' );
	}
}
