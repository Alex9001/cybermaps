<?php
declare(strict_types=1);

namespace Cybermaps\Tests\CLI;

use Cybermaps\CLI\Command;

final class CommandTest extends \WP_UnitTestCase {
	public function test_status_values_preserve_list_and_structured_content(): void {
		$this->assertSame( '["12","45"]', Command::format_status_value( array( '12', '45' ) ) );
		$this->assertSame(
			'[{"url":"https://example.com/map.xml","type":"xml"}]',
			Command::format_status_value(
				array(
					array(
						'url'  => 'https://example.com/map.xml',
						'type' => 'xml',
					),
				)
			)
		);
		$this->assertSame( 'true', Command::format_status_value( true ) );
		$this->assertSame( 'null', Command::format_status_value( null ) );
	}

	public function test_pending_regeneration_is_reported_as_queued_continuation(): void {
		$message = Command::get_regeneration_non_error_message(
			array(
				'status'  => 'pending',
				'success' => false,
				'mode'    => 'all',
			)
		);

		$this->assertNotNull( $message );
		$this->assertStringContainsString( 'in progress', $message );
		$this->assertStringContainsString( 'queued for background continuation', $message );
	}

	public function test_failed_regeneration_has_no_non_error_message(): void {
		$this->assertNull(
			Command::get_regeneration_non_error_message(
				array(
					'status'  => 'failed',
					'success' => false,
					'mode'    => 'all',
				)
			)
		);
	}
}
