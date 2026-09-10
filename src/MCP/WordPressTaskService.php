<?php
declare(strict_types=1);

namespace Cybermaps\MCP;

use Cybermaps\Audit\AuditPolicy;
use Cybermaps\Audit\ContentAuditService;
use Cybermaps\Discovery\StaticBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bounded WordPress-backed executors for MCP asynchronous operations.
 */
final class WordPressTaskService implements TaskServiceInterface {
	public const RUN_HOOK     = 'cybermaps_mcp_run_task';
	public const CLEANUP_HOOK = 'cybermaps_mcp_cleanup_tasks';

	public function __construct( private readonly TaskRepository $repository = new TaskRepository() ) {}

	public function register_hooks(): void {
		add_action( self::RUN_HOOK, array( $this, 'run_task' ), 10, 1 );
		add_action( self::CLEANUP_HOOK, array( $this, 'cleanup' ) );
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CLEANUP_HOOK );
		}
	}

	public static function clear_scheduled_hooks(): void {
		wp_clear_scheduled_hook( self::RUN_HOOK );
		wp_clear_scheduled_hook( self::CLEANUP_HOOK );
	}

	public function supports( string $type ): bool {
		return in_array( $type, array( 'audit', 'static_reconcile' ), true );
	}

	public function start( string $type, array $arguments, CallerContext $caller ): array {
		if ( ! $this->supports( $type ) || ! $this->owns_authenticated_caller( $caller ) ) {
			throw new \RuntimeException( 'The requested MCP task is unavailable.' );
		}

		$task_id   = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : uniqid( 'cybermaps-', true );
		$task      = $this->repository->create( $task_id, $type, (int) $caller->user_id, $caller->client_id, $arguments );
		$scheduled = wp_schedule_single_event( time() + 1, self::RUN_HOOK, array( $task_id ) );
		if ( false === $scheduled || is_wp_error( $scheduled ) ) {
			$this->repository->update( $task_id, 'failed', array(), 'schedule_failed' );
			throw new \RuntimeException( 'Cybermaps could not schedule the MCP task.' );
		}

		return $this->task_response( $task );
	}

	public function status( string $handle, CallerContext $caller ): ?array {
		$task = $this->repository->get( $handle );
		return is_array( $task ) && $this->owns( $task, $caller ) ? $this->task_response( $task ) : null;
	}

	public function cancel( string $handle, CallerContext $caller ): bool {
		$task = $this->repository->get( $handle );
		return is_array( $task ) && $this->owns( $task, $caller ) && $this->repository->cancel( $handle );
	}

	/** Execute one persisted task. This hook accepts only a task ID created by this service. */
	public function run_task( string $task_id ): void {
		$task = $this->repository->get( $task_id );
		if ( ! is_array( $task ) || 'pending' !== (string) ( $task['status'] ?? '' ) ) {
			return;
		}

		$this->repository->update( $task_id, 'running' );
		try {
			if ( 'audit' === (string) $task['task_type'] ) {
				$run_id = ( new ContentAuditService() )->run( AuditPolicy::from_settings() );
				$this->repository->update( $task_id, 'complete', array( 'auditRunId' => $run_id ) );
				return;
			}
			if ( 'static_reconcile' === (string) $task['task_type'] ) {
				$report = StaticBridge::get_instance()->request_sync( true );
				$this->repository->update( $task_id, 'complete', is_array( $report ) ? $report : array( 'status' => 'queued' ) );
				return;
			}
			$this->repository->update( $task_id, 'failed', array(), 'unsupported_task' );
		} catch ( \Throwable $error ) {
			$this->repository->update( $task_id, 'failed', array(), 'execution_failed' );
		}
	}

	public function cleanup(): void {
		$this->repository->cleanup();
	}

	/** @param array<string,mixed> $task */
	private function owns( array $task, CallerContext $caller ): bool {
		return $this->owns_authenticated_caller( $caller )
			&& (int) ( $task['user_id'] ?? 0 ) === (int) $caller->user_id
			&& hash_equals( (string) ( $task['client_id'] ?? '' ), $caller->client_id );
	}

	private function owns_authenticated_caller( CallerContext $caller ): bool {
		return (int) $caller->user_id > 0 && '' !== $caller->client_id && $caller->can( 'manage_options' );
	}

	/** @param array<string,mixed> $task
	 * @return array<string,mixed>
	 */
	private function task_response( array $task ): array {
		return array(
			'taskId' => (string) ( $task['task_id'] ?? '' ),
			'type'   => (string) ( $task['task_type'] ?? '' ),
			'status' => (string) ( $task['status'] ?? '' ),
			'result' => is_array( $task['result'] ?? null ) ? $task['result'] : array(),
			'error'  => (string) ( $task['error_code'] ?? '' ),
		);
	}
}
