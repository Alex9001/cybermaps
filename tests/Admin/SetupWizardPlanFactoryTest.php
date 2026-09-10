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
		$GLOBALS['cybermaps_mock_options'] = array();
		$GLOBALS['cybermaps_mock_posts']   = array();
		$GLOBALS['cybermaps_mock_pages']   = array();
		$GLOBALS['cybermaps_mock_is_multisite'] = false;
		$GLOBALS['cybermaps_mock_post_types'] = array( 'post', 'page' );
		$GLOBALS['cybermaps_mock_post_type_objects'] = array(
			'post' => (object) array( 'name' => 'post', 'label' => 'Posts', 'public' => true ),
			'page' => (object) array( 'name' => 'page', 'label' => 'Pages', 'public' => true ),
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

	public function test_configure_plan_is_sanitized_by_the_existing_preview_and_preserves_unmanaged_values(): void {
		update_option(
			'cybermaps_settings',
			array(
				'api_secret'                => 'private-value',
				'llms_custom_instructions'  => 'Cite our primary documentation first.',
				'site_guide_instructions'   => 'Keep this detailed editorial guidance.',
			)
		);
		$context = SetupWizardContext::build();
		$data    = $context->client_data();
		$payload = $this->payload( $data, array( 'strategy', 'sitemaps', 'ai', 'analytics', 'delivery', 'reports' ) );
		$payload['answers']['sitemap_specials'] = array( 'html' );
		$payload['answers']['ai_features']      = array( 'headers', 'hints', 'sitemap_link' );
		$payload['answers']['ai_topics']        = 'WordPress, technical SEO';

		$plan    = SetupWizardPlanFactory::build( $payload, $context );
		$preview = MigrationHub::get_instance()->preview( (string) $plan['content'], 'merge' );

		self::assertSame( array(), $preview['errors'] );
		self::assertStringNotContainsString( 'api_secret', (string) $plan['content'] );
		self::assertStringNotContainsString( 'llms_custom_instructions', (string) $plan['content'] );
		self::assertSame( 'cybermaps-ai-configuration-changes', $plan['document']['format'] );

		MigrationHub::get_instance()->import_previewed(
			(string) $plan['content'],
			'merge',
			(string) $preview['content_hash'],
			(string) $preview['configuration_hash']
		);
		$settings = get_option( 'cybermaps_settings', array() );
		self::assertSame( 'private-value', $settings['api_secret'] );
		self::assertSame( 'Cite our primary documentation first.', $settings['llms_custom_instructions'] );
		self::assertSame( 'Keep this detailed editorial guidance.', $settings['site_guide_instructions'] );
		self::assertTrue( (bool) $settings['enable_discovery_hub'] );
		self::assertSame( 'WordPress, technical SEO', $settings['ai_topics'] );
	}

	public function test_automatic_catalog_edit_preserves_other_catalogs_and_uses_the_original_index(): void {
		$parent = (object) array( 'ID' => 10, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Services' );
		$child  = (object) array( 'ID' => 11, 'post_type' => 'page', 'post_status' => 'publish', 'post_parent' => 10, 'post_title' => 'Consulting' );
		$GLOBALS['cybermaps_mock_posts'] = array( 10 => $parent, 11 => $child );
		$GLOBALS['cybermaps_mock_pages'] = array( $child );
		update_option(
			'cybermaps_identity_data',
			array(
				'catalogs' => array(
					2 => array( 'mode' => 'manual', 'item_type' => 'Service', 'name' => 'Manual', 'items' => array( 'Audit' ), 'parent_id' => 0 ),
					7 => array( 'mode' => 'auto', 'item_type' => 'Service', 'name' => 'Existing', 'items' => array(), 'parent_id' => 10 ),
				),
			)
		);
		$context = SetupWizardContext::build();
		$data    = $context->client_data();
		$payload = $this->payload( $data, array( 'identity' ) );
		$payload['answers']['catalog_action']    = 'edit';
		$payload['answers']['catalog_index']     = 7;
		$payload['answers']['catalog_parent_id'] = 10;
		$payload['answers']['catalog_item_type'] = 'Product';
		$payload['answers']['catalog_name']      = 'Products';

		$plan    = SetupWizardPlanFactory::build( $payload, $context );
		$preview = MigrationHub::get_instance()->preview( (string) $plan['content'], 'merge' );

		self::assertSame( array(), $preview['errors'] );
		$catalogs = $plan['document']['changes']['site_identity']['identity_catalogs'];
		self::assertCount( 2, $catalogs );
		self::assertSame( 'Manual', $catalogs[0]['name'] );
		self::assertSame( 'Product', $catalogs[1]['item_type'] );
		self::assertSame( 'Products', $catalogs[1]['name'] );
	}

	/**
	 * @param array<string,mixed> $data
	 * @param string[] $configure
	 * @return array<string,mixed>
	 */
	private function payload( array $data, array $configure ): array {
		$modes = array_fill_keys( SetupWizardRegistry::section_ids(), 'keep' );
		foreach ( $configure as $section_id ) {
			$modes[ $section_id ] = 'configure';
		}

		return array(
			'wizard_version' => SetupWizardRegistry::VERSION,
			'section_modes'  => $modes,
			'answers'        => $data['answers'],
		);
	}
}
