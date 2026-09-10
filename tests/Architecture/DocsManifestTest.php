<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Architecture;

use Cybermaps\Core\EndpointRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Keeps the generated developer manifest bound to Core's current contracts.
 */
final class DocsManifestTest extends TestCase {

	private string $project_root;

	/**
	 * @var array<string, mixed>
	 */
	private array $manifest;

	protected function setUp(): void {
		$this->project_root = dirname( __DIR__, 2 );
		$manifest = json_decode(
			(string) file_get_contents( $this->project_root . '/docs/dev/manifest.json' ),
			true
		);

		$this->assertIsArray( $manifest );
		$this->manifest = $manifest;
	}

	public function test_release_headers_drive_manifest_metadata(): void {
		$main = (string) file_get_contents( $this->project_root . '/cybermaps.php' );

		preg_match( '/^[ \t*]*Plugin Name:\s*(.+?)\s*$/mi', $main, $plugin );
		preg_match( '/^[ \t*]*Version:\s*([0-9.]+)\s*$/mi', $main, $version );
		preg_match( '/^[ \t*]*Requires PHP:\s*([0-9.]+)\s*$/mi', $main, $php_min );
		preg_match( '/^[ \t*]*Requires at least:\s*([0-9.]+)\s*$/mi', $main, $wp_min );

		$this->assertSame( $plugin[1] ?? null, $this->manifest['plugin'] ?? null );
		$this->assertSame( $version[1] ?? null, $this->manifest['version'] ?? null );
		$this->assertSame( $php_min[1] ?? null, $this->manifest['php_min'] ?? null );
		$this->assertSame( $wp_min[1] ?? null, $this->manifest['wp_min'] ?? null );
	}

	public function test_committed_manifest_matches_a_successful_generator_run(): void {
		$command = array(
			PHP_BINARY,
			$this->project_root . '/docs/dev/generate-docs.php',
		);
		$spec = array(
			0 => array( 'pipe', 'r' ),
			1 => array( 'pipe', 'w' ),
			2 => array( 'pipe', 'w' ),
		);
		$pipes = array();
		$process = proc_open( $command, $spec, $pipes, $this->project_root );

		$this->assertIsResource( $process );
		fclose( $pipes[0] );
		$output = stream_get_contents( $pipes[1] );
		$error  = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$status = proc_close( $process );

		$this->assertSame( 0, $status, (string) $error );
		$generated = json_decode( (string) $output, true );
		$this->assertIsArray( $generated, (string) $error );

		unset( $generated['generated_at'], $this->manifest['generated_at'] );
		$this->assertSame(
			$generated,
			$this->manifest,
			'Run php docs/dev/generate-docs.php > docs/dev/manifest.json after changing the public surface.'
		);
	}

	public function test_settings_inventory_includes_dynamic_policy_map_keys(): void {
		$settings = $this->manifest['settings'] ?? array();

		$this->assertSame( $settings, array_values( array_unique( $settings ) ) );
		$this->assertSame( $settings, $this->sorted( $settings ) );
		$this->assertCount( (int) ( $this->manifest['settings_count'] ?? -1 ), $settings );
		$this->assertContains( 'audit_post_min_words', $settings );
		$this->assertContains( 'audit_post_max_age_days', $settings );
		$this->assertContains( 'audit_page_min_words', $settings );
		$this->assertContains( 'audit_page_max_age_days', $settings );
	}

	public function test_manifest_inventories_all_configuration_roots_and_owners(): void {
		$options = $this->manifest['configuration_options'] ?? array();
		$by_name = array_column( $options, null, 'option' );

		$this->assertSame( 4, $this->manifest['configuration_option_count'] ?? null );
		$this->assertSame(
			array(
				'cybermaps_settings',
				'cybermaps_discovery_center',
				'cybermaps_robots_manager',
				'cybermaps_identity_data',
			),
			array_column( $options, 'option' )
		);
		$this->assertContains( 'type_intents', $by_name['cybermaps_discovery_center']['top_level_fields'] ?? array() );
		$this->assertContains( 'manual_directives', $by_name['cybermaps_robots_manager']['top_level_fields'] ?? array() );
		$this->assertContains( 'catalogs', $by_name['cybermaps_identity_data']['top_level_fields'] ?? array() );
		$this->assertSame(
			$this->manifest['settings'] ?? null,
			$by_name['cybermaps_settings']['top_level_fields'] ?? null
		);
	}

	public function test_shortcode_defaults_are_typed_values(): void {
		$this->assertSame(
			array(
				array( 'name' => 'only', 'default' => '' ),
				array( 'name' => 'exclude', 'default' => '' ),
				array( 'name' => 'limit', 'default' => 50 ),
				array( 'name' => 'depth', 'default' => 0 ),
				array( 'name' => 'sort', 'default' => 'asc' ),
				array( 'name' => 'nofollow', 'default' => 'false' ),
				array( 'name' => 'display_title', 'default' => 'true' ),
				array( 'name' => 'layout', 'default' => 'list' ),
			),
			$this->manifest['shortcode_attributes'] ?? null
		);
		$this->assertSame(
			count( $this->manifest['shortcode_attributes'] ?? array() ),
			$this->manifest['shortcode_attribute_count'] ?? null
		);
	}

	public function test_rest_and_cli_inventory_counts_match_their_surfaces(): void {
		$routes = $this->manifest['rest_api_routes'] ?? array();
		$this->assertSame( count( $routes ), $this->manifest['rest_api_route_count'] ?? null );
		$this->assertSame(
				array(
					'/wp-json/cybermaps/v1/discovery',
					'/wp-json/cybermaps/v1/health',
					'/wp-json/cybermaps/v1/mcp',
					'/wp-json/cybermaps/v1/mcp/server-card',
				'/wp-json/cybermaps/v1/llms-tldr',
				'/wp-json/cybermaps/v1/search',
				'/wp-json/cybermaps/v1/urls',
				'/wp-json/cybermaps/v1/status',
				'/wp-json/cybermaps/v1/audit-latest',
				'/wp-json/cybermaps/v1/audit-run',
				'/wp-json/cybermaps/v1/purge',
			),
			array_column( $routes, 'full' )
		);

		$commands = $this->manifest['wp_cli_commands'] ?? array();
		$this->assertSame( count( $commands ), $this->manifest['wp_cli_command_count'] ?? null );
		$this->assertSame(
			array(
				'wp cybermaps flush_rules',
				'wp cybermaps clear_cache',
				'wp cybermaps regenerate',
				'wp cybermaps status',
			),
			array_column( $commands, 'full' )
		);
	}

	public function test_discovery_inventory_includes_fixed_rest_and_parameterized_routes(): void {
		$endpoints = $this->manifest['discovery_endpoints'] ?? array();
		$by_path = array_column( $endpoints, null, 'path' );

		$this->assertSame( count( $endpoints ), $this->manifest['discovery_endpoint_count'] ?? null );
		$this->assertArrayHasKey( '/ai.json', $by_path );
			$this->assertArrayHasKey( '/.well-known/api-catalog', $by_path );
			$this->assertArrayHasKey( '/.well-known/ai-catalog.json', $by_path );
			$this->assertArrayHasKey( '/.well-known/mcp/server-card.json', $by_path );
			$this->assertArrayHasKey( '/auth.md', $by_path );
		$this->assertArrayHasKey( '/.well-known/agent-skills/cybermaps-site-guide/SKILL.md', $by_path );
		$this->assertTrue( $by_path['/.well-known/agent-skills/cybermaps-site-guide/SKILL.md']['canonical'] ?? false );
		$this->assertFalse( $by_path['/skill.md']['canonical'] ?? true );
		$this->assertArrayHasKey( '/cybermaps-openapi.json', $by_path );
		$this->assertArrayHasKey( '/wp-json/cybermaps/v1/discovery', $by_path );
		$this->assertArrayHasKey( '/wp-json/cybermaps/v1/llms-tldr', $by_path );
		$this->assertArrayHasKey( '/wp-json/cybermaps/v1/search', $by_path );
		$this->assertArrayHasKey( '/{language}/llms.txt', $by_path );
		$this->assertArrayHasKey( '/{language}/llms-full.txt', $by_path );
		$this->assertArrayHasKey( '/{language}/llms-tldr.txt', $by_path );
		$this->assertArrayHasKey( '/discovery/chunks/{post_id}.json', $by_path );

		$this->assertTrue( $by_path['/ai.json']['canonical'] ?? false );
		$this->assertTrue( $by_path['/{language}/llms.txt']['parameterized'] ?? false );
		$this->assertSame(
			'enable_rag_chunks',
			$by_path['/discovery/chunks/{post_id}.json']['enabled_setting'] ?? null
		);
		$this->assertSame(
			'well_known',
			$by_path['/ai.json']['static_bucket'] ?? null
		);
	}

	public function test_static_mode_and_file_counts_are_derived_from_core(): void {
		$engine             = $this->manifest['static_engine'] ?? array();
		$registry           = EndpointRegistry::get_instance();
		$well_known_targets = $registry->get_static_targets( 'well_known', null, true );
		$all_targets        = $registry->get_static_targets( 'all', null, true );

		$this->assertSame( 'well_known', $engine['default'] ?? null );
		$this->assertSame(
			array( 'off', 'well_known', 'all' ),
			array_column( $engine['modes'] ?? array(), 'mode' )
		);
		$this->assertSame(
			array(
				'/ai.json',
				'/ai-discovery',
				'/ai-usage.json',
				'/ai-actions.json',
				'/.well-known/agent-skills/cybermaps-site-guide/SKILL.md',
				'/.well-known/agent-skills/index.json',
				'/.well-known/api-catalog',
				'/.well-known/ai-catalog.json',
				'/.well-known/mcp/server-card.json',
				'/.well-known/oauth-authorization-server',
				'/.well-known/oauth-protected-resource',
			),
			array_column( $well_known_targets, 'path' )
		);
		$this->assertSame(
			array(
				'/ai.json',
				'/ai-discovery',
				'/llms.txt',
				'/llms-full.txt',
				'/llms-tldr.txt',
					'/knowledge-graph.json',
					'/updates.json',
					'/news/llms.txt',
					'/news/speakable.json',
					'/news/changelog.json',
					'/news/archive.jsonl',
				'/ai-sitemap.xml',
				'/ai-usage.json',
				'/ai-actions.json',
				'/.well-known/agent-skills/cybermaps-site-guide/SKILL.md',
				'/.well-known/agent-skills/index.json',
				'/.well-known/api-catalog',
				'/.well-known/ai-catalog.json',
				'/.well-known/mcp/server-card.json',
				'/.well-known/oauth-authorization-server',
				'/.well-known/oauth-protected-resource',
			),
			array_column( $all_targets, 'path' )
		);
		$this->assertSame(
			array( 'off', 'well_known', 'all' ),
			array_keys( $engine['target_counts'] ?? array() )
		);
		$this->assertSame(
			0,
			$engine['target_counts']['off'] ?? null
		);
		$this->assertSame(
			count( $this->manifest['static_files'] ?? array() ),
			$this->manifest['static_file_count'] ?? null
		);
	}

	public function test_sitemap_feature_inventory_contains_no_duplicate_claims(): void {
		$features = $this->manifest['sitemap_features'] ?? array();

		$this->assertSame( $features, array_values( array_unique( $features ) ) );
	}

	public function test_class_inventory_is_stable_and_uses_declaration_docblocks(): void {
		$classes = $this->manifest['source_classes'] ?? array();
		$fqcns = array_column( $classes, 'fqcn' );

		$this->assertCount( (int) ( $this->manifest['class_count'] ?? -1 ), $classes );
		$this->assertSame( $fqcns, array_values( array_unique( $fqcns ) ) );
		$this->assertSame(
			$classes,
			$this->sorted(
				$classes,
				static fn ( array $left, array $right ): int => [
					$left['file'],
					$left['class'],
				] <=> [
					$right['file'],
					$right['class'],
				]
			)
		);

		$by_fqcn = array_column( $classes, null, 'fqcn' );
		$this->assertSame(
			'Records canonical, publicly consumable endpoint metadata.',
			$by_fqcn['Cybermaps\\Core\\EndpointRegistry']['summary'] ?? null
		);
	}

	/**
	 * @template T
	 * @param T[]                         $values
	 * @param (callable(T, T): int)|null $callback
	 * @return T[]
	 */
	private function sorted( array $values, ?callable $callback = null ): array {
		$sorted = $values;
		if ( null === $callback ) {
			sort( $sorted );
		} else {
			usort( $sorted, $callback );
		}
		return $sorted;
	}
}
