<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\Settings\SettingsAjax;

final class StaticWriteAlertsTest extends \WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_current_user_capabilities'] = array( 'manage_options' );
		$GLOBALS['cybermaps_mock_current_screen']             = (object) array( 'id' => 'dashboard' );
		$GLOBALS['cybermaps_mock_options']                    = array();
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['cybermaps_mock_current_user_capabilities'],
			$GLOBALS['cybermaps_mock_current_screen']
		);
		parent::tearDown();
	}

	public function test_global_admin_notice_is_summary_only(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_static_write_errors'] = $this->errors( 10000 );

		ob_start();
		SettingsAjax::display_write_alerts();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '10000 unresolved generated-file errors', $output );
		$this->assertStringContainsString( 'Review Static File Engine settings', $output );
		$this->assertStringNotContainsString( '<li>', $output );
		$this->assertStringNotContainsString( 'discovery/chunks/1.json', $output );
	}

	public function test_cybermaps_screen_lists_only_a_bounded_sample(): void {
		$GLOBALS['cybermaps_mock_current_screen'] = (object) array(
			'id' => 'cybermaps_page_cybermaps-ai-discovery-status',
		);
		$GLOBALS['cybermaps_mock_options']['cybermaps_static_write_errors'] = $this->errors( 100 );

		ob_start();
		SettingsAjax::display_write_alerts();
		$output = (string) ob_get_clean();

		$this->assertSame( 20, substr_count( $output, '<li>' ) );
		$this->assertStringContainsString( 'Showing the first 20 of 100 errors.', $output );
		$this->assertStringContainsString( 'discovery/chunks/20.json', $output );
		$this->assertStringNotContainsString( 'discovery/chunks/21.json', $output );
	}

	public function test_notice_requires_capability_and_an_array_of_errors(): void {
		$GLOBALS['cybermaps_mock_options']['cybermaps_static_write_errors'] = 'invalid';

		ob_start();
		SettingsAjax::display_write_alerts();
		$this->assertSame( '', (string) ob_get_clean() );

		$GLOBALS['cybermaps_mock_options']['cybermaps_static_write_errors'] = $this->errors( 1 );
		$GLOBALS['cybermaps_mock_current_user_capabilities']                = array();

		ob_start();
		SettingsAjax::display_write_alerts();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	/**
	 * @return array<string, array<string, string>>
	 */
	private function errors( int $count ): array {
		$errors = array();
		for ( $index = 1; $index <= $count; ++$index ) {
			$errors[ 'discovery/chunks/' . $index . '.json' ] = array(
				'message' => 'Write failed.',
			);
		}
		return $errors;
	}
}
