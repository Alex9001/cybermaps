<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Core;

use Cybermaps\Core\AbilityKernel;
use Cybermaps\MCP\CallerContext;
use PHPUnit\Framework\TestCase;

final class AbilityKernelTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_abilities'] = array();
		$GLOBALS['cybermaps_mock_ability_categories'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['cybermaps_mock_abilities'] = array();
		$GLOBALS['cybermaps_mock_ability_categories'] = array();
		parent::tearDown();
	}

	public function test_registers_five_public_core_abilities_from_tool_contract(): void {
		$kernel = AbilityKernel::get_instance();
		$kernel->register_categories();
		$kernel->register_abilities();

		$this->assertCount( 2, $GLOBALS['cybermaps_mock_ability_categories'] );
		$this->assertCount( 5, $GLOBALS['cybermaps_mock_abilities'] );
		$this->assertTrue( $GLOBALS['cybermaps_mock_abilities']['cybermaps/search']->get_meta()['public'] );
	}

	public function test_projects_public_third_party_ability_with_channel_override(): void {
		wp_register_ability(
			'example/read-status',
			array(
				'label'               => 'Read status',
				'description'         => 'Read a public status.',
				'execute_callback'    => static fn(): array => array( 'ok' => true ),
				'permission_callback' => '__return_true',
				'input_schema'        => array( 'type' => 'object', 'properties' => array() ),
				'output_schema'       => array( 'type' => 'object' ),
				'meta'                => array(
					'public'      => true,
					'mcp'         => array( 'public' => true ),
					'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
				),
			)
		);
		$caller = new CallerContext( '', '' );
		$tools  = AbilityKernel::get_instance()->mcp_tool_definitions( 'read_only', $caller );

		$this->assertArrayHasKey( 'wp.example.read-status', $tools );
		$this->assertTrue( $tools['wp.example.read-status']['annotations']['readOnlyHint'] );
	}
}
