<?php
declare(strict_types=1);

namespace Cybermaps\Tests\MCP;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Task stub is shared by the focused MCP runtime tests.

use Cybermaps\MCP\CallerContext;
use Cybermaps\MCP\InMemoryConfirmationStateStore;
use Cybermaps\MCP\OneTimeConfirmationService;
use Cybermaps\MCP\TaskServiceInterface;
use Cybermaps\MCP\ToolRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ToolRegistryTest extends \WP_UnitTestCase {
	public function test_visibility_requires_mode_scope_capability_and_an_executor(): void {
		$registry = $this->registry();
		$public   = new CallerContext( '', '' );
		$operator = new CallerContext( '7', 'client-a', array( 'cybermaps:audit', 'cybermaps:purge' ), static fn( string $capability ): bool => 'manage_options' === $capability );

		$this->assertSame( array(), $registry->list( 'discovery', $public ) );
		$this->assertSame( array( 'cybermaps.search' ), array_column( $registry->list( 'read_only', $public ), 'name' ) );
		$this->assertSame( array( 'cybermaps.search' ), array_column( $registry->list( 'read_only', $operator ), 'name' ) );
		$this->assertSame( array( 'cybermaps.audit.run', 'cybermaps.search', 'cybermaps.static.purge' ), array_column( $registry->list( 'operations', $operator ), 'name' ) );
	}

	public function test_mutation_confirmation_is_bound_and_single_use(): void {
		$executions = 0;
		$registry   = $this->registry(
			static function () use ( &$executions ): array {
				++$executions;
				return array( 'purged' => true );
			}
		);
		$caller     = new CallerContext( '7', 'client-a', array( 'cybermaps:purge' ), static fn(): bool => true );

		$challenge = $registry->call( 'cybermaps.static.purge', array(), '', 'operations', $caller );
		$token     = $challenge['requestState'];
		$this->assertSame( 'input_required', $challenge['resultType'] );
		$this->assertSame( 'elicitation/create', $challenge['inputRequests']['confirmation']['method'] );
		$this->assertSame( 0, $executions );

		$result = $registry->call( 'cybermaps.static.purge', array(), $token, 'operations', $caller );
		$this->assertTrue( $result['structuredContent']['purged'] );
		$this->assertSame( 'complete', $result['resultType'] );
		$this->assertSame( 1, $executions );

		$this->expectException( \InvalidArgumentException::class );
		$registry->call( 'cybermaps.static.purge', array(), $token, 'operations', $caller );
	}

	private function registry( ?callable $purge = null ): ToolRegistry {
		$confirmations = new OneTimeConfirmationService(
			new InMemoryConfirmationStateStore(),
			'test-secret',
			static fn(): int => 1000,
			static fn(): string => 'fixed-nonce'
		);
		return new ToolRegistry(
			array(
				'cybermaps.search'       => static fn(): array => array( 'results' => array() ),
				'cybermaps.static.purge' => $purge ?? static fn(): array => array( 'purged' => true ),
			),
			new MCPTaskServiceStub(),
			$confirmations
		);
	}
}

final class MCPTaskServiceStub implements TaskServiceInterface {
	public function supports( string $type ): bool {
		return 'audit' === $type;
	}

	public function start( string $type, array $arguments, CallerContext $caller ): array {
		unset( $type, $arguments, $caller );
		return array(
			'taskId' => 'task-1',
			'status' => 'working',
		);
	}

	public function status( string $handle, CallerContext $caller ): ?array {
		unset( $caller );
		return 'task-1' === $handle ? array(
			'taskId' => $handle,
			'status' => 'working',
		) : null;
	}

	public function cancel( string $handle, CallerContext $caller ): bool {
		unset( $caller );
		return 'task-1' === $handle;
	}
}
