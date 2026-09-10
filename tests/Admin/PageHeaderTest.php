<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\PageHeader;
use PHPUnit\Framework\TestCase;

final class PageHeaderTest extends TestCase {
	public function test_shared_header_renders_structured_copy_status_and_action(): void {
		ob_start();
		PageHeader::render(
			'Publication Status',
			'Verify the public response.',
			array( array( 'label' => 'Dynamic-only', 'tone' => 'info' ) ),
			array( array( 'label' => 'Refresh', 'url' => 'https://example.com/refresh', 'primary' => true ) )
		);
		$output = (string) ob_get_clean();

		self::assertStringContainsString( 'class="cm-page-header"', $output );
		self::assertStringContainsString( '<h1 class="screen-reader-text">Publication Status</h1>', $output );
		self::assertStringContainsString( '<h2>Publication Status</h2>', $output );
		self::assertStringContainsString( 'Verify the public response.', $output );
		self::assertStringContainsString( 'cm-page-badge-info', $output );
		self::assertStringContainsString( 'button-primary', $output );
		self::assertStringContainsString( '>Refresh</a>', preg_replace( '/\s+/', '', $output ) ?: '' );
	}
}
