<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

use Cybermaps\Discovery\UsagePolicy;

final class UsagePolicyTest extends \WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_options'] = array();
	}

	public function test_blank_licensing_email_does_not_publish_admin_email(): void {
		update_option(
			'cybermaps_settings',
			array( 'ai_licensing_email' => '' )
		);
		update_option( 'admin_email', 'private-admin@example.com' );

		$data = ( new UsagePolicy() )->get_policy_data();

		$this->assertArrayNotHasKey( 'contacts', $data );
		$this->assertStringNotContainsString( 'private-admin@example.com', wp_json_encode( $data ) );
	}

	public function test_explicit_licensing_email_is_published(): void {
		update_option(
			'cybermaps_settings',
			array( 'ai_licensing_email' => 'licensing@example.com' )
		);

		$data = ( new UsagePolicy() )->get_policy_data();

		$this->assertSame( 'licensing@example.com', $data['contacts']['licensing'] );
	}

	public function test_policy_revalidates_supported_controls_and_uses_ui_defaults(): void {
		update_option(
			'cybermaps_settings',
			array(
				'ai_usage_rag'         => 'yes',
				'ai_usage_training'    => 'no',
				'ai_usage_commercial'  => 'conditional',
				'ai_usage_attribution' => 'orphaned-value',
			)
		);

		$data = ( new UsagePolicy() )->get_policy_data();

		$this->assertSame(
			array(
				'rag_usage'           => 'allow',
				'foundation_training' => 'forbid',
				'commercial_use'      => 'forbid',
			),
			$data['policy']
		);
		$this->assertArrayNotHasKey( 'attribution', $data['policy'] );
	}

	public function test_policy_defaults_exist_before_the_ai_tab_is_saved(): void {
		$data = ( new UsagePolicy() )->get_policy_data();

		$this->assertSame(
			array(
				'rag_usage'           => 'allow',
				'foundation_training' => 'forbid',
				'commercial_use'      => 'forbid',
			),
			$data['policy']
		);
	}
}
