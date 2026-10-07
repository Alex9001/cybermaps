<?php
declare(strict_types=1);

namespace Cybermaps\Tests\Discovery;

final class PublicationNotifierMultisiteTest extends \PHPUnit\Framework\TestCase {
	public function test_origin_sites_keep_same_ids_settings_and_durable_queues_separate(): void {
		$case = $this->fixture( 'origins' );
		self::assertSame( array( 'https://front-one.example/old/', 'https://front-one.example/new/' ), $case['queues']['1'] );
		self::assertSame( array( 'https://front-two.example/old/', 'https://front-two.example/new/' ), $case['queues']['2'] );
		self::assertCount( 1, $case['websub'] );
		self::assertSame( 'https://hub-two.example/', $case['websub'][0]['url'] );
		self::assertSame( 'https://front-two.example/feed.json', $case['websub'][0]['args']['body']['hub.url'] );
		self::assertSame( 1, $case['current_blog'] );
		self::assertSame( array(), $case['blog_stack'] );
	}

	public function test_shutdown_uses_latest_origin_opt_in_not_boot_site_settings(): void {
		$case = $this->fixture( 'disabled' );
		self::assertSame( array(), $case['queues']['2'] );
		self::assertSame( array(), $case['websub'] );
		self::assertSame( 1, $case['current_blog'] );
	}

	public function test_delivery_failure_restores_previous_blog_in_finally(): void {
		$case = $this->fixture( 'failure' );
		self::assertSame( 'Injected notification failure', $case['error'] );
		self::assertSame( 1, $case['current_blog'] );
		self::assertSame( array(), $case['blog_stack'] );
	}

	public function test_snapshot_overflow_is_bounded_and_explicit_without_premature_delivery(): void {
		$case = $this->fixture( 'overflow' );
		self::assertSame( 1000, $case['pending_count'] );
		self::assertSame( 0, $case['before_flush_count'] );
		self::assertSame( 1000, $case['after_flush_count'] );
		self::assertSame( 'publication.notifications.incomplete', $case['diagnostic']['event'] );
		self::assertSame( array( 'status' => 'incomplete', 'retained' => 1000, 'skipped' => 1, 'reason_code' => 'snapshot_limit' ), $case['diagnostic']['context'] );
	}

	public function test_diagnostics_disabled_overflow_is_best_effort_without_save_failure_or_log_claim(): void {
		$case = $this->fixture( 'overflow-disabled' );
		self::assertSame( 1000, $case['pending_count'] );
		self::assertSame( 0, $case['before_flush_count'] );
		self::assertSame( 1000, $case['after_flush_count'] );
		self::assertFalse( $case['diagnostic'] );
	}

	private function fixture( string $case ): array {
		$process = proc_open( array( PHP_BINARY, __DIR__ . '/fixtures/notifier-multisite.php', $case ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		self::assertIsResource( $process );
		$output = stream_get_contents( $pipes[1] );
		$error = stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );
		self::assertSame( 0, proc_close( $process ), $error );
		return json_decode( $output, true, 512, JSON_THROW_ON_ERROR );
	}
}
