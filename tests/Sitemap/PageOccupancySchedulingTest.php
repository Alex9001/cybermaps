<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Sitemap;

use Cybermaps\Sitemap\Orchestrator;
use Cybermaps\Sitemap\PageOccupancyBuilder;
use Cybermaps\Sitemap\PageOccupancyManifest;

final class PageOccupancySchedulingTest extends \WP_UnitTestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['cybermaps_mock_scheduled'] = array();
		unset( $GLOBALS['cybermaps_mock_schedule_failure'] );
		\delete_option( PageOccupancyBuilder::STATE_OPTION );
		\delete_option( PageOccupancyBuilder::WORK_OPTION );
		\delete_option( PageOccupancyManifest::MANIFEST_OPTION );
		\update_option( PageOccupancyManifest::GENERATION_OPTION, 0, false );
		\update_option( PageOccupancyManifest::TOKEN_OPTION, str_repeat( 'a', 32 ), false );
	}

	public function test_scheduler_failure_is_persisted_without_creating_an_event(): void {
		$GLOBALS['cybermaps_mock_schedule_failure'] = true;
		$builder = new PageOccupancyBuilder( new Orchestrator() );

		$builder->invalidate();

		$state = \get_option( PageOccupancyBuilder::STATE_OPTION );
		$this->assertSame( 'failed', $state['status'] );
		$this->assertNotSame( '', $state['last_error'] );
		$this->assertArrayNotHasKey( PageOccupancyBuilder::HOOK, $GLOBALS['cybermaps_mock_scheduled'] );
	}

	public function test_register_hooks_adds_later_request_retry_trigger(): void {
		$builder = new PageOccupancyBuilder( new Orchestrator() );
		$builder->register_hooks();

		$hooks = array_filter(
			$GLOBALS['wp_hooks'],
			static fn( array $hook ): bool => 'action' === $hook['type']
				&& 'init' === $hook['hook']
				&& array( $builder, 'ensure_scheduled' ) === $hook['callback']
		);
		$this->assertNotEmpty( $hooks );
	}

	public function test_later_request_retries_after_scheduler_failure(): void {
		$GLOBALS['cybermaps_mock_schedule_failure'] = true;
		$builder = new PageOccupancyBuilder( new Orchestrator() );
		$builder->invalidate();
		$this->assertArrayNotHasKey( PageOccupancyBuilder::HOOK, $GLOBALS['cybermaps_mock_scheduled'] );

		unset( $GLOBALS['cybermaps_mock_schedule_failure'] );
		$builder->ensure_scheduled();

		$this->assertArrayHasKey( PageOccupancyBuilder::HOOK, $GLOBALS['cybermaps_mock_scheduled'] );
		$builder->ensure_scheduled();
		$this->assertCount( 1, $GLOBALS['cybermaps_mock_scheduled'] );
	}
}
