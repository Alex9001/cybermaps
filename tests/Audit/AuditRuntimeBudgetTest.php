<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Audit;

use Cybermaps\Audit\AuditRuntimeBudget;
use PHPUnit\Framework\TestCase;

final class AuditRuntimeBudgetTest extends TestCase {
	public function test_cooperative_deadline_throws_without_waiting_or_allocating_pressure(): void {
		$budget = new AuditRuntimeBudget();
		( new \ReflectionProperty( AuditRuntimeBudget::class, 'started' ) )->setValue( $budget, hrtime( true ) / 1e9 - 21 );
		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'No partial report was completed' );
		$budget->claim();
	}

	public function test_unsupported_cache_budget_fails_run_before_next_hydration_and_releases_lease(): void {
		$process = proc_open( array( PHP_BINARY, __DIR__ . '/fixtures/runtime-budget.php' ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $process );
		$output = stream_get_contents( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		$this->assertSame( 0, proc_close( $process ), $error );
		$cases = json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
		$this->assertStringContainsString( 'safety budget', $cases['unsupported']['error'] );
		$this->assertSame( array( 'running', 'failed' ), $cases['unsupported']['statuses'] );
		$this->assertSame( 100, $cases['unsupported']['resources'] );
		$this->assertSame( array( 100, 1, 100 ), $cases['unsupported']['batches'] );
		$this->assertSame( 0, $cases['unsupported']['flushes'] );
		$this->assertSame( '', $cases['supported']['error'] );
		$this->assertSame( array( 'running', 'complete' ), $cases['supported']['statuses'] );
		$this->assertSame( 101, $cases['supported']['resources'] );
		$this->assertGreaterThan( 0, $cases['supported']['flushes'] );
		$this->assertStringContainsString( 'could not read publication candidates', $cases['sql-failure']['error'] );
		$this->assertSame( array( 'running', 'failed' ), $cases['sql-failure']['statuses'] );
		$this->assertSame( 0, $cases['sql-failure']['resources'] );
		$this->assertSame( array(), $cases['sql-failure']['batches'] );
		foreach ( $cases as $case ) {
			$this->assertFalse( $case['lock'] );
			$this->assertSame( 'retained backend entry', $case['persistent_sentinel'] );
		}
	}
}
