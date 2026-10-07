<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Admin;

use Cybermaps\Admin\AIDiscoveryStatus;
use Cybermaps\Admin\DiscoveryStatus;
use Cybermaps\Admin\MaturityGuidance;
use Cybermaps\Core\EndpointRegistry;
use PHPUnit\Framework\TestCase;

final class StaticPublicationGuidanceTest extends TestCase {
	public function test_the_four_header_sensitive_protocols_have_no_static_target_in_any_mode(): void {
		$registry = EndpointRegistry::get_instance();
		$paths = array();
		foreach ( array( 'api_catalog', 'ai_catalog', 'mcp_server_card', 'discovery_index' ) as $id ) {
			$definition = $registry->all()[ $id ];
			self::assertSame( 'runtime_headers_required', $definition['delivery_requirement'] );
			$paths[] = $definition['path'];
		}
		foreach ( array( 'off', 'well_known', 'all' ) as $mode ) {
			$static_paths = array_column( $registry->get_static_targets( $mode, array(), true ), 'path' );
			self::assertSame( array(), array_values( array_intersect( $paths, $static_paths ) ) );
		}
	}
	public function test_rendered_static_requirements_name_the_five_registered_targets(): void {
		$targets = array_column( EndpointRegistry::get_instance()->get_static_targets( 'well_known', array( 'enable_discovery_hub' => '1' ), true ), 'path' );
		self::assertCount( 5, $targets );
		ob_start();
		( new \ReflectionMethod( AIDiscoveryStatus::class, 'render_static_server_requirements' ) )->invoke( null );
		$html = (string) ob_get_clean();
		foreach ( $targets as $path ) {
			self::assertStringContainsString( $path, $html );
		}
		self::assertStringContainsString( 'remain dynamic in every mode', $html );
		self::assertStringContainsString( 'Route these requests to WordPress', $html );
	}

	public function test_deployment_guidance_and_environment_notice_identify_dynamic_protocols(): void {
		$guidance = MaturityGuidance::definition( 'deployment' )['text'];
		$notices = ( new \ReflectionMethod( DiscoveryStatus::class, 'get_environment_notices' ) )->invoke( new DiscoveryStatus(), array(), 0 );
		$notice = $notices[ count( $notices ) - 1 ]['message'];
		foreach ( array( $guidance, $notice ) as $text ) {
			foreach ( array( '/ai.json', '/ai-usage.json', '/ai-actions.json', 'Site Guide SKILL.md', 'Agent Skills index', 'API Catalog', 'AI Catalog', 'MCP Server Card', '/ai-discovery' ) as $publication ) {
				self::assertStringContainsString( $publication, $text );
			}
			self::assertStringContainsString( 'remain dynamic in every mode and must reach WordPress', $text );
			self::assertStringNotContainsString( 'fallback bodies', $text );
		}
	}
}
