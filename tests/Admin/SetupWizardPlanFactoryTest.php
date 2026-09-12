<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\AIConfigurationRegistry;
use Cybermaps\Admin\MigrationHub;
use Cybermaps\Admin\SetupWizard\SetupWizardContext;
use Cybermaps\Admin\SetupWizard\SetupWizardPlanFactory;
use Cybermaps\Admin\SetupWizard\SetupWizardRegistry;
use PHPUnit\Framework\TestCase;

final class SetupWizardPlanFactoryTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_options']           = array();
		$GLOBALS['cybermaps_mock_posts']             = array();
		$GLOBALS['cybermaps_mock_pages']             = array();
		$GLOBALS['cybermaps_mock_is_multisite']      = false;
		$GLOBALS['cybermaps_mock_post_types']        = array( 'post', 'page' );
		$GLOBALS['cybermaps_mock_post_type_objects'] = array(
			'post' => (object) array(
				'name'   => 'post',
				'label'  => 'Posts',
				'public' => true,
			),
			'page' => (object) array(
				'name'   => 'page',
				'label'  => 'Pages',
				'public' => true,
			),
		);
	}

	public function test_registry_assigns_a_deliberate_policy_to_every_editable_field(): void {
		$policies = SetupWizardRegistry::field_policies();

		self::assertSame( array_keys( AIConfigurationRegistry::get_fields() ), array_keys( $policies ) );
		foreach ( $policies as $field_id => $policy ) {
			self::assertContains( $policy, array( 'explicit', 'derived', 'manual_only', 'forbidden' ), $field_id );
		}
		self::assertSame( 'forbidden', $policies['ai_kg_expose_admin'] );
	}

	public function test_insights_preset_is_sanitized_and_preserves_expert_configuration(): void {
		update_option(
			'cybermaps_settings',
			array(
				'api_secret'               => 'private-value',
				'llms_custom_instructions' => 'Cite our documentation first.',
				'ai_usage_training'        => 'forbid',
			)
		);
		update_option(
			'cybermaps_discovery_center',
			array(
				'archetype' => 'corporate',
				'overrides' => array( 'page' => 0.42 ),
			)
		);

		$context = SetupWizardContext::build();
		$payload = $this->payload(
			$context,
			array(
				'website_type'         => 'blog',
				'ai_visibility'        => 'on',
				'operations'           => 'insights',
				'identity_type'        => 'Organization',
				'identity_name'        => 'Example Studio',
				'identity_description' => 'Independent technical publishing.',
				'identity_image_id'    => 23,
			)
		);
		$plan    = SetupWizardPlanFactory::build( $payload, $context );
		$preview = MigrationHub::get_instance()->preview( (string) $plan['content'], 'merge' );

		self::assertSame( array(), $preview['errors'] );
		self::assertStringNotContainsString( 'api_secret', (string) $plan['content'] );
		self::assertStringNotContainsString( 'llms_custom_instructions', (string) $plan['content'] );
		self::assertTrue( $this->value( $plan, 'enable_discovery_hub' ) );
		self::assertTrue( $this->value( $plan, 'enable_analytics' ) );
		self::assertTrue( $this->value( $plan, 'anonymize_analytics_ips' ) );
		self::assertSame( 30, $this->value( $plan, 'log_retention_days' ) );
		self::assertSame( 'off', $this->value( $plan, 'static_engine_mode' ) );
		self::assertTrue( $this->value( $plan, 'enable_rss_sitemap' ) );
		self::assertFalse( $this->value( $plan, 'enable_google_news' ) );

		MigrationHub::get_instance()->import_previewed(
			(string) $plan['content'],
			'merge',
			(string) $preview['content_hash'],
			(string) $preview['configuration_hash']
		);
		$settings = get_option( 'cybermaps_settings', array() );
		$strategy = get_option( 'cybermaps_discovery_center', array() );
		$identity = get_option( 'cybermaps_identity_data', array() );
		$strategy = is_string( $strategy ) ? json_decode( $strategy, true ) : $strategy;
		self::assertSame( 'private-value', $settings['api_secret'] );
		self::assertSame( 'Cite our documentation first.', $settings['llms_custom_instructions'] );
		self::assertSame( 'forbid', $settings['ai_usage_training'] );
		self::assertSame( array( 'page' => 0.42 ), $strategy['overrides'] );
		self::assertSame( 'Example Studio', $identity['name'] );
	}

	public function test_performance_preset_disables_logging_and_preserves_detailed_ai_values(): void {
		update_option(
			'cybermaps_settings',
			array(
				'enable_discovery_hub' => true,
				'enable_llms_full'     => true,
				'enable_rag_chunks'    => true,
				'enable_websub'        => true,
				'enable_analytics'     => true,
				'log_retention_days'   => 120,
			)
		);

		$context = SetupWizardContext::build();
		$plan    = SetupWizardPlanFactory::build(
			$this->payload(
				$context,
				array(
					'website_type'         => 'ecommerce',
					'ai_visibility'        => 'off',
					'operations'           => 'performance',
					'identity_type'        => 'Organization',
					'identity_name'        => 'Example Shop',
					'identity_description' => '',
					'identity_image_id'    => 0,
				)
			),
			$context
		);

		self::assertFalse( $this->value( $plan, 'enable_discovery_hub' ) );
		self::assertFalse( $this->value( $plan, 'enable_websub' ) );
		self::assertFalse( $this->value( $plan, 'enable_analytics' ) );
		self::assertSame( 'all', $this->value( $plan, 'static_engine_mode' ) );
		self::assertArrayNotHasKey( 'enable_llms_full', $plan['document']['changes']['ai_publishing'] );
		self::assertArrayNotHasKey( 'enable_rag_chunks', $plan['document']['changes']['ai_publishing'] );
		self::assertArrayNotHasKey( 'log_retention_days', $plan['document']['changes']['analytics'] );
	}

	public function test_website_types_map_to_the_expected_editorial_surfaces(): void {
		$context = SetupWizardContext::build();
		foreach ( array_keys( SetupWizardRegistry::choices()['website_type'] ) as $type ) {
			$plan      = SetupWizardPlanFactory::build(
				$this->payload(
					$context,
					array(
						'website_type'         => $type,
						'ai_visibility'        => 'off',
						'operations'           => 'insights',
						'identity_type'        => 'Person',
						'identity_name'        => 'Example Publisher',
						'identity_description' => '',
						'identity_image_id'    => 0,
					)
				),
				$context
			);
			$editorial = in_array( $type, array( 'blog', 'newspaper' ), true );
			self::assertSame( $type, $this->value( $plan, 'discovery_archetype' ) );
			self::assertSame( $editorial, $this->value( $plan, 'include_authors' ) );
			self::assertSame( $editorial, $this->value( $plan, 'enable_rss_sitemap' ) );
			self::assertSame( 'newspaper' === $type, $this->value( $plan, 'enable_google_news' ) );
		}
	}

	public function test_multisite_always_uses_dynamic_delivery(): void {
		$GLOBALS['cybermaps_mock_is_multisite'] = true;
		$context                                = SetupWizardContext::build();
		$plan                                   = SetupWizardPlanFactory::build(
			$this->payload(
				$context,
				array(
					'website_type'         => 'medium-business',
					'ai_visibility'        => 'on',
					'operations'           => 'performance',
					'identity_type'        => 'Organization',
					'identity_name'        => 'Network Site',
					'identity_description' => '',
					'identity_image_id'    => 0,
				)
			),
			$context
		);

		self::assertSame( 'off', $this->value( $plan, 'static_engine_mode' ) );
	}

	public function test_identity_name_and_all_vibe_answers_are_required(): void {
		$context = SetupWizardContext::build();

		$this->expectException( \InvalidArgumentException::class );
		SetupWizardPlanFactory::build(
			array(
				'wizard_version' => SetupWizardRegistry::VERSION,
				'answers'        => array(
					'website_type'  => 'blog',
					'ai_visibility' => 'on',
					'operations'    => 'insights',
					'identity_type' => 'Person',
					'identity_name' => ' ',
				),
			),
			$context
		);
	}

	/** @param array<string,mixed> $answers @return array<string,mixed> */
	private function payload( SetupWizardContext $context, array $answers ): array {
		$data = $context->client_data();
		return array(
			'wizard_version' => SetupWizardRegistry::VERSION,
			'answers'        => array_merge( $data['answers'], $answers ),
		);
	}

	/** @param array<string,mixed> $plan */
	private function value( array $plan, string $field_id ): mixed {
		$field   = AIConfigurationRegistry::get_field( $field_id );
		$section = is_array( $field ) ? (string) $field['section_id'] : '';
		return $plan['document']['changes'][ $section ][ $field_id ] ?? null;
	}
}
