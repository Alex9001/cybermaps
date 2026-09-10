<?php
declare(strict_types=1);

namespace Cybermaps\Tests\MCP;

use Cybermaps\MCP\WordPressTaskService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WordPressTaskServiceTest extends \WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_scheduled'] = array();
	}

	public function test_cleanup_schedule_is_removed_when_mcp_is_disabled(): void {
		( new WordPressTaskService() )->register_hooks();
		$this->assertArrayHasKey( WordPressTaskService::CLEANUP_HOOK, $GLOBALS['cybermaps_mock_scheduled'] );

		WordPressTaskService::clear_scheduled_hooks();
		$this->assertArrayNotHasKey( WordPressTaskService::CLEANUP_HOOK, $GLOBALS['cybermaps_mock_scheduled'] );
		$this->assertArrayNotHasKey( WordPressTaskService::RUN_HOOK, $GLOBALS['cybermaps_mock_scheduled'] );
	}
}
