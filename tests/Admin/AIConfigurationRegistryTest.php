<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\AIConfigurationRegistry;
use Cybermaps\Discovery\PublicationConstraints;
use PHPUnit\Framework\TestCase;

final class AIConfigurationRegistryTest extends TestCase {
	public function test_registry_covers_every_existing_editable_mapping_exactly_once(): void {
		$fields = AIConfigurationRegistry::get_fields();
		self::assertCount( 121, $fields );
		self::assertCount(
			121,
			array_unique(
				array_map(
					static fn( array $field ): string => $field['option'] . '.' . $field['field'],
					$fields
				)
			)
		);

		self::assertSame(
			array(
				'cybermaps_settings'          => 95,
				'cybermaps_discovery_center'  => 4,
				'cybermaps_identity_data'     => 18,
				'cybermaps_robots_manager'    => 4,
			),
			array_count_values( array_column( $fields, 'option' ) )
		);
	}

	public function test_every_field_has_complete_bounded_metadata(): void {
		$sections = AIConfigurationRegistry::get_sections();
		$fields   = AIConfigurationRegistry::get_fields();
		$required = array(
			'id',
			'section_id',
			'section_label',
			'option',
			'field',
			'label',
			'purpose',
			'json_type',
			'allowed',
			'effective_default',
			'dependencies',
			'example',
			'risk',
		);

		foreach ( $fields as $id => $field ) {
			self::assertSame( $required, array_keys( $field ), $id );
			self::assertSame( $id, $field['id'], $id );
			self::assertArrayHasKey( $field['section_id'], $sections, $id );
			self::assertSame( $sections[ $field['section_id'] ]['label'], $field['section_label'], $id );
			self::assertContains( $field['option'], array( 'cybermaps_settings', 'cybermaps_discovery_center', 'cybermaps_identity_data', 'cybermaps_robots_manager' ), $id );
			self::assertContains( $field['json_type'], array( 'boolean', 'string', 'integer', 'number', 'array', 'object' ), $id );
			self::assertNotSame( '', $field['label'], $id );
			self::assertNotSame( '', $field['purpose'], $id );
			self::assertNotEmpty( $field['allowed'], $id );
			self::assertContains( $field['risk'], array( 'low', 'medium', 'high' ), $id );
			self::assertIsArray( $field['dependencies'], $id );
			foreach ( $field['dependencies'] as $dependency ) {
				self::assertArrayHasKey( (string) $dependency['field'], $fields, $id );
				self::assertContains(
					$dependency['condition'],
					array( 'equals', 'non_empty', 'one_of', 'maximum_fraction_of', 'resolved_type_is_local_business', 'paired_presence' ),
					$id
				);
				self::assertArrayHasKey( 'value', $dependency, $id );
			}
		}
	}

	public function test_catalog_and_schema_never_expose_forbidden_fields(): void {
		$catalog_data = AIConfigurationRegistry::get_catalog();
		$catalog = json_encode( $catalog_data, JSON_THROW_ON_ERROR );
		$schema  = json_encode( AIConfigurationRegistry::get_json_schema(), JSON_THROW_ON_ERROR );
		self::assertSame( CYBERMAPS_VERSION, $catalog_data['plugin_version'] );

		foreach ( AIConfigurationRegistry::FORBIDDEN_FIELDS as $forbidden ) {
			self::assertArrayNotHasKey( $forbidden, AIConfigurationRegistry::get_fields() );
			self::assertStringNotContainsString( '"' . $forbidden . '"', $catalog );
			self::assertStringNotContainsString( '"' . $forbidden . '"', $schema );
		}
		self::assertNull( AIConfigurationRegistry::get_field( 'api_secret' ) );
		self::assertNull( AIConfigurationRegistry::get_field( 'delete_data_on_uninstall' ) );
	}

	public function test_schema_is_strict_sectioned_and_nullable(): void {
		$schema = AIConfigurationRegistry::get_json_schema();
		self::assertSame( 'https://json-schema.org/draft/2020-12/schema', $schema['$schema'] );
		self::assertSame( false, $schema['additionalProperties'] );
		self::assertSame( AIConfigurationRegistry::FORMAT_VERSION, $schema['format_version'] );
		self::assertSame( CYBERMAPS_VERSION, $schema['plugin_version'] );
		self::assertSame( array( 'format', 'format_version', 'plugin_version', 'changes' ), $schema['required'] );
		self::assertSame( AIConfigurationRegistry::CHANGES_FORMAT, $schema['properties']['format']['const'] );
		self::assertSame( AIConfigurationRegistry::FORMAT_VERSION, $schema['properties']['format_version']['const'] );
		self::assertSame( CYBERMAPS_VERSION, $schema['properties']['plugin_version']['const'] );
		self::assertSame( false, $schema['properties']['changes']['additionalProperties'] );

		$schema_field_count = 0;
		foreach ( $schema['properties']['changes']['properties'] as $section_id => $section ) {
			self::assertArrayHasKey( $section_id, AIConfigurationRegistry::get_sections() );
			self::assertSame( false, $section['additionalProperties'] );
			$schema_field_count += count( $section['properties'] );
			foreach ( $section['properties'] as $field_id => $property ) {
				$field = AIConfigurationRegistry::get_field( $field_id );
				self::assertNotNull( $field );
				self::assertSame( $section_id, $field['section_id'] );
				self::assertSame( $field['json_type'], $property['anyOf'][0]['type'] );
				self::assertSame( array( 'type' => 'null' ), $property['anyOf'][1] );
			}
		}
		self::assertSame( 121, $schema_field_count );
		self::assertSame(
			array( true, false ),
			$schema['properties']['changes']['properties']['core_settings']['properties']['include_homepage']['anyOf'][0]['enum']
		);
		self::assertFalse(
			in_array(
				'false',
				$schema['properties']['changes']['properties']['core_settings']['properties']['include_homepage']['anyOf'][0]['enum'],
				true
			)
		);
	}

	public function test_mapping_and_contract_hashes_are_stable_and_addressable(): void {
		$field   = AIConfigurationRegistry::get_field( 'identity_catalogs' );
		$mapping = AIConfigurationRegistry::get_mapping_lookup()['identity_catalogs'];

		self::assertNotNull( $field );
		self::assertSame( 'site_identity', $field['section_id'] );
		self::assertSame( 'cybermaps_identity_data', $mapping['option'] );
		self::assertSame( 'catalogs', $mapping['field'] );
		self::assertSame( 'Identity Hub', $mapping['section_label'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', AIConfigurationRegistry::get_catalog_hash() );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', AIConfigurationRegistry::get_schema_hash() );
		self::assertSame( AIConfigurationRegistry::get_catalog_hash(), AIConfigurationRegistry::get_catalog_hash() );
		self::assertSame( AIConfigurationRegistry::get_schema_hash(), AIConfigurationRegistry::get_schema_hash() );
		self::assertNull( AIConfigurationRegistry::get_section( 'made_up_section' ) );
		self::assertTrue( AIConfigurationRegistry::get_field( 'redirect_wp_sitemap' )['effective_default'] );
		self::assertTrue( AIConfigurationRegistry::get_field( 'enable_video_schema' )['effective_default'] );
		self::assertTrue( AIConfigurationRegistry::get_field( 'enable_multimodal_discovery' )['effective_default'] );
		self::assertTrue( AIConfigurationRegistry::get_field( 'enable_content_hints' )['effective_default'] );
		self::assertSame( 'well_known', AIConfigurationRegistry::get_field( 'static_engine_mode' )['effective_default'] );
		self::assertSame( 'off', AIConfigurationRegistry::get_field( 'mcp_mode' )['effective_default'] );
		self::assertSame(
			array( 'off', 'discovery', 'read_only', 'operations' ),
			AIConfigurationRegistry::get_field( 'mcp_mode' )['allowed']['enum']
		);
		self::assertSame( 'off', AIConfigurationRegistry::get_field( 'trusted_proxy_header' )['effective_default'] );
		self::assertSame(
			array( 'off', 'forwarded', 'x_forwarded_for', 'x_real_ip' ),
			AIConfigurationRegistry::get_field( 'trusted_proxy_header' )['allowed']['enum']
		);
		self::assertSame( 64, AIConfigurationRegistry::get_field( 'trusted_proxy_cidrs' )['allowed']['maxItems'] );
		self::assertSame(
			array( 'forwarded', 'x_forwarded_for', 'x_real_ip' ),
			AIConfigurationRegistry::get_field( 'trusted_proxy_cidrs' )['dependencies'][0]['value']
		);
		self::assertInstanceOf( \stdClass::class, AIConfigurationRegistry::get_field( 'discovery_overrides' )['effective_default'] );
		self::assertInstanceOf( \stdClass::class, AIConfigurationRegistry::get_field( 'identity_hours' )['effective_default'] );
		self::assertInstanceOf( \stdClass::class, AIConfigurationRegistry::get_field( 'crawler_overrides' )['effective_default'] );
	}

	public function test_publication_text_and_type_collection_bounds_are_in_the_v2_schema(): void {
		$fields = AIConfigurationRegistry::get_fields();
		self::assertSame(
			PublicationConstraints::PUBLICATION_NAME_MAX_LENGTH,
			$fields['news_publication_name']['allowed']['maxLength']
		);
		self::assertSame(
			PublicationConstraints::TAXONOMY_FILTER_MAX_LENGTH,
			$fields['llms_filter_taxonomies']['allowed']['maxLength']
		);
		self::assertSame(
			PublicationConstraints::TAXONOMY_FILTER_ITEMS_MAX,
			$fields['llms_filter_taxonomies']['allowed']['x-max-items']
		);
		$taxonomy_pattern = '/' . str_replace( '/', '\\/', $fields['llms_filter_taxonomies']['allowed']['pattern'] ) . '/D';
		self::assertSame( 1, preg_match( $taxonomy_pattern, 'category, post_tag' ) );
		self::assertSame(
			0,
			preg_match(
				$taxonomy_pattern,
				implode(
					', ',
					array_map(
						static fn ( int $index ): string => 'taxonomy_' . $index,
						range( 0, PublicationConstraints::TAXONOMY_FILTER_ITEMS_MAX )
					)
				)
			)
		);
		self::assertSame(
			0,
			preg_match( $taxonomy_pattern, str_repeat( 'x', PublicationConstraints::TAXONOMY_NAME_MAX_LENGTH + 1 ) )
		);

		foreach ( array( 'rss_sitemap_types', 'llms_included_types', 'ai_sitemap_types' ) as $field_id ) {
			self::assertSame(
				PublicationConstraints::PUBLICATION_TYPE_ITEMS_MAX,
				$fields[ $field_id ]['allowed']['maxItems'],
				$field_id
			);
		}

		$schema     = AIConfigurationRegistry::get_json_schema();
		$properties = $schema['properties']['changes']['properties'];
		self::assertSame(
			PublicationConstraints::PUBLICATION_NAME_MAX_LENGTH,
			$properties['core_settings']['properties']['news_publication_name']['anyOf'][0]['maxLength']
		);
		self::assertSame(
			PublicationConstraints::TAXONOMY_FILTER_MAX_LENGTH,
			$properties['ai_publishing']['properties']['llms_filter_taxonomies']['anyOf'][0]['maxLength']
		);
		self::assertSame(
			PublicationConstraints::PUBLICATION_TYPE_ITEMS_MAX,
			$properties['core_settings']['properties']['rss_sitemap_types']['anyOf'][0]['maxItems']
		);
		self::assertSame(
			PublicationConstraints::PUBLICATION_TYPE_ITEMS_MAX,
			$properties['ai_publishing']['properties']['llms_included_types']['anyOf'][0]['maxItems']
		);
		self::assertSame(
			PublicationConstraints::PUBLICATION_TYPE_ITEMS_MAX,
			$properties['ai_publishing']['properties']['ai_sitemap_types']['anyOf'][0]['maxItems']
		);
	}
}
