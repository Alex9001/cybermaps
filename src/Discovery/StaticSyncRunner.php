<?php
declare(strict_types=1);

namespace Cybermaps\Discovery;

use Cybermaps\Core\EndpointRegistry;
use Cybermaps\Sitemap\Orchestrator;
use Cybermaps\Sitemap\PageOccupancyManifest;
use Cybermaps\Sitemap\ProviderIdentity;
use Cybermaps\Sitemap\PublicationRouteSlugs;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Phase-and-cursor static synchronization runner (schema 4).
 */
final class StaticSyncRunner {
	public const STATE_SCHEMA         = 5;
	private const REPORT_SAMPLE_LIMIT = 100;
	private const MAX_STATE_TEXT      = 4096;
	private const MAX_DIAGNOSTIC_KEYS = 32;
	private const MAX_PLAN_CLAIMS     = 10000;
	private const MAX_PLAN_BYTES      = 4194304;
	private const MAX_OMISSION_PAGES  = 50000;
	private const MAX_OMISSION_BYTES  = 4194304;

	public const PHASE_LEGACY_PURGE         = 'legacy_purge';
	public const PHASE_SITEMAP_CHILDREN     = 'sitemap_children';
	public const PHASE_RSS                  = 'rss';
	public const PHASE_RAG_CHUNKS           = 'rag_chunks';
	public const PHASE_DISCOVERY_LEAF       = 'discovery_leaf';
	public const PHASE_LOCALIZED            = 'localized';
	public const PHASE_DISCOVERY_INDEX      = 'discovery_index';
	public const PHASE_SITEMAP_INDEX        = 'sitemap_index';
	public const PHASE_STALE_RECONCILIATION = 'stale_reconciliation';
	public const PHASE_COMPLETE             = 'complete';

	private StaticBridge $bridge;

	public function __construct( StaticBridge $bridge ) {
		$this->bridge = $bridge;
	}

	/**
	 * Accept only continuation state that belongs to the exact durable run.
	 *
	 * @param array<string,mixed> $state Candidate state.
	 * @param array<string,mixed> $settings Current settings snapshot.
	 */
	public function can_resume(
		array $state,
		array $settings,
		int $generation,
		string $mode,
		int $persisted_epoch
	): bool {
		if ( ! $this->has_valid_state_header( $state, $settings, $generation, $mode, $persisted_epoch ) ) {
			return false;
		}

		$context = $state['context'] ?? array();
		if ( ! $this->is_valid_context( $context ) ) {
			return false;
		}

		$phase = (string) $state['phase'];
		return \hash_equals(
			$this->topology_fingerprint( $settings, $mode ),
			(string) $context['topology']
		) && $this->is_valid_cursor( $phase, $state['cursor'] ?? array() );
	}

	/**
	 * @param array<string,mixed> $state
	 * @param array<string,mixed> $settings
	 */
	private function has_valid_state_header(
		array $state,
		array $settings,
		int $generation,
		string $mode,
		int $persisted_epoch
	): bool {
		return $this->has_expected_state_keys( $state )
			&& $this->has_valid_state_status( $state )
			&& $this->has_matching_state_identity( $state, $generation, $mode, $persisted_epoch )
			&& $this->has_valid_state_phase( $state, $settings, $mode )
			&& $this->has_valid_state_timing( $state )
			&& $this->has_valid_state_reporting( $state );
	}

	/** @param array<string,mixed> $state */
	private function has_expected_state_keys( array $state ): bool {
		$expected = array(
			'context',
			'cursor',
			'epoch',
			'generation',
			'last_error',
			'mode',
			'phase',
			'retry_at',
			'schema',
			'started_at',
			'status',
			'writes',
		);
		$actual   = \array_keys( $state );
		\sort( $expected, SORT_STRING );
		\sort( $actual, SORT_STRING );
		return $actual === $expected;
	}

	/** @param array<string,mixed> $state */
	private function has_valid_state_status( array $state ): bool {
		$status = $state['status'] ?? null;
		return \is_string( $status )
			&& \in_array( $status, array( 'pending', 'interrupted', 'running' ), true );
	}

	/** @param array<string,mixed> $state */
	private function has_matching_state_identity(
		array $state,
		int $generation,
		string $mode,
		int $persisted_epoch
	): bool {
		return self::STATE_SCHEMA === ( $state['schema'] ?? null )
			&& ( $state['generation'] ?? null ) === $generation
			&& ( $state['mode'] ?? null ) === $mode
			&& $this->is_matching_state_epoch( $state['epoch'] ?? null, $persisted_epoch );
	}

	private function is_matching_state_epoch( mixed $epoch, int $persisted_epoch ): bool {
		return \is_int( $epoch ) && $epoch >= 1 && $epoch === $persisted_epoch;
	}

	/**
	 * @param array<string,mixed> $state
	 * @param array<string,mixed> $settings
	 */
	private function has_valid_state_phase( array $state, array $settings, string $mode ): bool {
		$phase = $state['phase'] ?? null;
		return \is_string( $phase )
			&& self::PHASE_COMPLETE !== $phase
			&& \in_array( $phase, $this->ordered_phases( $settings, 'all' === $mode, $mode ), true );
	}

	/** @param array<string,mixed> $state */
	private function has_valid_state_timing( array $state ): bool {
		return $this->is_valid_started_at( $state['started_at'] ?? null )
			&& $this->is_non_negative_integer( $state['retry_at'] ?? null );
	}

	/** @param array<string,mixed> $state */
	private function has_valid_state_reporting( array $state ): bool {
		$last_error = $state['last_error'] ?? null;
		return $this->is_non_negative_integer( $state['writes'] ?? null )
			&& \is_string( $last_error )
			&& \strlen( $last_error ) <= self::MAX_STATE_TEXT;
	}

	private function is_non_negative_integer( mixed $value ): bool {
		return \is_int( $value ) && $value >= 0;
	}

	/**
	 * @param array<string,mixed> $settings
	 * @param array<string,mixed> $report
	 * @return array<string,mixed>
	 */
	public function run( array $settings, array &$report ): array {
		$mode = $this->report_mode( $report, $settings );
		$plan = $this->validate_publication_plan( $settings, $mode );
		if ( ! $plan['valid'] ) {
			unset( $plan['valid'] );
			$report['failed']['publication_plan'] = $plan;
			return $report;
		}

		$write_all            = 'all' === $mode;
		$run_state            = $this->initial_run_state( $settings, $mode );
		$phase                = $run_state['phase'];
		$cursor               = $run_state['cursor'];
		$context              = $run_state['context'];
		$report['sync_epoch'] = $this->bridge->runner_get_epoch();
		$services             = array(
			'orchestrator' => null,
			'selector'     => null,
			'generator'    => null,
		);
		$phases               = $this->ordered_phases( $settings, $write_all, $mode );
		while ( self::PHASE_COMPLETE !== $phase ) {
			if ( ! \in_array( $phase, $phases, true ) ) {
				$phase  = $this->next_scheduled_phase( $phase, $phases );
				$cursor = array();
				if ( self::PHASE_COMPLETE === $phase ) {
					break;
				}
				continue;
			}

			if ( $this->save_deferred_run( $phase, $cursor, $context, $report ) ) {
				return $report;
			}

			$phase = $this->advance_run_phase(
				$phase,
				$settings,
				$mode,
				$write_all,
				$phases,
				$services,
				$context,
				$report,
				$cursor
			);

			if ( $this->save_deferred_run( $phase, $cursor, $context, $report ) ) {
				return $report;
			}

			$this->absorb_report( $context, $report );
			$this->bridge->runner_save_state( $phase, $cursor, false, $context );
		}

		$this->absorb_report( $context, $report );
		$this->bridge->runner_save_state( self::PHASE_COMPLETE, array(), false, $context );
		$this->hydrate_report( $report, $context );
		return $report;
	}

	/** @param array<string,mixed> $report */
	private function report_mode( array $report, array $settings ): string {
		return (string) ( $report['mode'] ?? StaticBridge::get_mode( $settings ) );
	}

	/**
	 * @param array<string,mixed> $settings
	 * @return array{phase:string,cursor:array<string,mixed>,context:array<string,mixed>}
	 */
	private function initial_run_state( array $settings, string $mode ): array {
		$state = $this->bridge->get_runner_state();
		return array(
			'phase'   => (string) ( $state['phase'] ?? self::PHASE_LEGACY_PURGE ),
			'cursor'  => \is_array( $state['cursor'] ?? null ) ? $state['cursor'] : array(),
			'context' => $this->normalize_context(
				$state['context'] ?? array(),
				$this->topology_fingerprint( $settings, $mode )
			),
		);
	}

	/**
	 * Preserve the exact phase checkpoint when this request cannot do more work.
	 *
	 * @param array<string,mixed> $cursor
	 * @param array<string,mixed> $context
	 * @param array<string,mixed> $report
	 */
	private function save_deferred_run( string $phase, array $cursor, array &$context, array &$report ): bool {
		if ( ! $this->bridge->runner_budget_exhausted() ) {
			return false;
		}

		$this->bridge->runner_mark_deferred();
		$this->absorb_report( $context, $report );
		$this->bridge->runner_save_state( $phase, $cursor, true, $context );
		$this->hydrate_report( $report, $context );
		return true;
	}

	/**
	 * @param array<string,mixed> $settings
	 * @param string[]            $phases
	 * @param array<string,mixed> $services
	 * @param array<string,mixed> $context
	 * @param array<string,mixed> $report
	 * @param array<string,mixed> $cursor
	 */
	private function advance_run_phase(
		string $phase,
		array $settings,
		string $mode,
		bool $write_all,
		array $phases,
		array &$services,
		array &$context,
		array &$report,
		array &$cursor
	): string {
		return match ( $phase ) {
			self::PHASE_LEGACY_PURGE => $this->advance_legacy_purge( $phases, $context, $report, $cursor ),
			self::PHASE_SITEMAP_CHILDREN => $this->advance_sitemap_children( $write_all, $phases, $services, $context, $report, $cursor ),
			self::PHASE_RSS => $this->advance_rss( $settings, $write_all, $phases, $context, $report, $cursor ),
			self::PHASE_SITEMAP_INDEX => $this->advance_sitemap_index( $write_all, $phases, $services, $context, $report, $cursor ),
			self::PHASE_STALE_RECONCILIATION => $this->advance_stale_reconciliation( $mode, $context, $report, $cursor ),
			default => $this->advance_discovery_phase( $phase, $settings, $mode, $write_all, $phases, $services, $context, $report, $cursor ),
		};
	}

	/**
	 * @param array<string,mixed> $settings
	 * @param string[]            $phases
	 * @param array<string,mixed> $services
	 * @param array<string,mixed> $context
	 * @param array<string,mixed> $report
	 * @param array<string,mixed> $cursor
	 */
	private function advance_discovery_phase(
		string $phase,
		array $settings,
		string $mode,
		bool $write_all,
		array $phases,
		array &$services,
		array &$context,
		array &$report,
		array &$cursor
	): string {
		return match ( $phase ) {
			self::PHASE_RAG_CHUNKS => $this->advance_rag_chunks( $settings, $write_all, $phases, $services, $context, $report, $cursor ),
			self::PHASE_DISCOVERY_LEAF => $this->advance_discovery_leaf( $settings, $mode, $phases, $services, $context, $report, $cursor ),
			self::PHASE_LOCALIZED => $this->advance_localized( $settings, $write_all, $phases, $context, $report, $cursor ),
			self::PHASE_DISCOVERY_INDEX => $this->advance_discovery_index( $settings, $mode, $phases, $services, $context, $report, $cursor ),
			default => self::PHASE_COMPLETE,
		};
	}

	/**
	 * @param string[]            $phases
	 * @param array<string,mixed> $context
	 * @param array<string,mixed> $report
	 * @param array<string,mixed> $cursor
	 */
	private function advance_legacy_purge( array $phases, array &$context, array &$report, array &$cursor ): string {
		$problem_count = $this->bridge->runner_get_problem_count( $report );
		if ( ! $this->bridge->runner_purge_legacy_publications( $report, $cursor ) ) {
			return self::PHASE_LEGACY_PURGE;
		}

		$this->capture_problem_delta( $report, $problem_count, $context );
		$cursor = array();
		return $this->next_scheduled_phase( self::PHASE_LEGACY_PURGE, $phases );
	}

	/**
	 * @param string[]            $phases
	 * @param array<string,mixed> $services
	 * @param array<string,mixed> $context
	 * @param array<string,mixed> $report
	 * @param array<string,mixed> $cursor
	 */
	private function advance_sitemap_children(
		bool $write_all,
		array $phases,
		array &$services,
		array &$context,
		array &$report,
		array &$cursor
	): string {
		if ( ! $write_all ) {
			$cursor = array();
			return $this->next_scheduled_phase( self::PHASE_SITEMAP_CHILDREN, $phases );
		}

		if ( ! $this->run_sitemap_children_phase( $report, $this->run_orchestrator( $services ), $cursor ) ) {
			return self::PHASE_SITEMAP_CHILDREN;
		}

		$context['sitemap_omissions']       = $this->normalize_sitemap_omissions( $cursor['omissions'] ?? array() );
		$context['sitemap_children_failed'] = ! empty( $cursor['failed'] );
		$context['had_problems']            = $context['had_problems'] || $context['sitemap_children_failed'];
		$cursor                             = array();
		return $this->next_scheduled_phase( self::PHASE_SITEMAP_CHILDREN, $phases );
	}

	/**
	 * @param array<string,mixed> $settings
	 * @param string[]            $phases
	 * @param array<string,mixed> $context
	 * @param array<string,mixed> $report
	 * @param array<string,mixed> $cursor
	 */
	private function advance_rss(
		array $settings,
		bool $write_all,
		array $phases,
		array &$context,
		array &$report,
		array &$cursor
	): string {
		if ( ! $write_all || empty( $settings['enable_rss_sitemap'] ) ) {
			$cursor = array();
			return $this->next_scheduled_phase( self::PHASE_RSS, $phases );
		}

		$problem_count = $this->bridge->runner_get_problem_count( $report );
		$processed     = $this->bridge->runner_publish(
			$report,
			Orchestrator::get_rss_sitemap_base() . '.xml',
			static fn(): string => (string) ( new \Cybermaps\Sitemap\RSSProvider() )->generate()
		);
		if ( ! $processed ) {
			return self::PHASE_RSS;
		}

		$this->capture_problem_delta( $report, $problem_count, $context );
		$cursor = array();
		return $this->next_scheduled_phase( self::PHASE_RSS, $phases );
	}

	/**
	 * @param array<string,mixed> $settings
	 * @param string[]            $phases
	 * @param array<string,mixed> $services
	 * @param array<string,mixed> $context
	 * @param array<string,mixed> $report
	 * @param array<string,mixed> $cursor
	 */
	private function advance_rag_chunks(
		array $settings,
		bool $write_all,
		array $phases,
		array &$services,
		array &$context,
		array &$report,
		array &$cursor
	): string {
		if ( empty( $settings['enable_discovery_hub'] ) || ! $write_all || empty( $settings['enable_rag_chunks'] ) ) {
			$cursor = array();
			return $this->next_scheduled_phase( self::PHASE_RAG_CHUNKS, $phases );
		}

		if ( ! $this->run_rag_phase( $report, $this->run_selector( $services, $settings ), $cursor ) ) {
			return self::PHASE_RAG_CHUNKS;
		}

		$context['rag_failed']   = ! empty( $cursor['failed'] );
		$context['had_problems'] = $context['had_problems'] || $context['rag_failed'];
		$cursor                  = array();
		return $this->next_scheduled_phase( self::PHASE_RAG_CHUNKS, $phases );
	}

	/**
	 * @param array<string,mixed> $settings
	 * @param string[]            $phases
	 * @param array<string,mixed> $services
	 * @param array<string,mixed> $context
	 * @param array<string,mixed> $report
	 * @param array<string,mixed> $cursor
	 */
	private function advance_discovery_leaf(
		array $settings,
		string $mode,
		array $phases,
		array &$services,
		array &$context,
		array &$report,
		array &$cursor
	): string {
		if ( empty( $settings['enable_discovery_hub'] ) ) {
			$cursor = array();
			return $this->next_scheduled_phase( self::PHASE_DISCOVERY_LEAF, $phases );
		}

		if ( ! $this->run_discovery_leaf_phase(
			$report,
			$settings,
			$mode,
			$this->run_generator( $services, $settings ),
			$cursor,
			$context['rag_failed']
		) ) {
			return self::PHASE_DISCOVERY_LEAF;
		}

		$context['discovery_leaf_failed'] = ! empty( $cursor['failed'] );
		$context['had_problems']          = $context['had_problems'] || $context['discovery_leaf_failed'];
		$cursor                           = array();
		return $this->next_scheduled_phase( self::PHASE_DISCOVERY_LEAF, $phases );
	}

	/**
	 * @param array<string,mixed> $settings
	 * @param string[]            $phases
	 * @param array<string,mixed> $context
	 * @param array<string,mixed> $report
	 * @param array<string,mixed> $cursor
	 */
	private function advance_localized(
		array $settings,
		bool $write_all,
		array $phases,
		array &$context,
		array &$report,
		array &$cursor
	): string {
		if ( empty( $settings['enable_discovery_hub'] ) || ! $write_all || empty( $settings['enable_multilingual_hub'] ) ) {
			$cursor = array();
			return $this->next_scheduled_phase( self::PHASE_LOCALIZED, $phases );
		}

		if ( ! $this->run_localized_phase( $report, $settings, $cursor ) ) {
			return self::PHASE_LOCALIZED;
		}

		$context['localized_failed'] = ! empty( $cursor['failed'] );
		$context['had_problems']     = $context['had_problems'] || $context['localized_failed'];
		$cursor                      = array();
		return $this->next_scheduled_phase( self::PHASE_LOCALIZED, $phases );
	}

	/**
	 * @param array<string,mixed> $settings
	 * @param string[]            $phases
	 * @param array<string,mixed> $services
	 * @param array<string,mixed> $context
	 * @param array<string,mixed> $report
	 * @param array<string,mixed> $cursor
	 */
	private function advance_discovery_index(
		array $settings,
		string $mode,
		array $phases,
		array &$services,
		array &$context,
		array &$report,
		array &$cursor
	): string {
		if ( empty( $settings['enable_discovery_hub'] ) ) {
			$cursor = array();
			return $this->next_scheduled_phase( self::PHASE_DISCOVERY_INDEX, $phases );
		}

		$dependencies_failed = $context['rag_failed']
			|| $context['discovery_leaf_failed']
			|| $context['localized_failed'];
		if ( ! $this->run_discovery_index_phase(
			$report,
			$settings,
			$mode,
			$this->run_generator( $services, $settings ),
			$cursor,
			$dependencies_failed
		) ) {
			return self::PHASE_DISCOVERY_INDEX;
		}

		$context['had_problems'] = $context['had_problems'] || ! empty( $cursor['failed'] );
		$cursor                  = array();
		return $this->next_scheduled_phase( self::PHASE_DISCOVERY_INDEX, $phases );
	}

	/**
	 * @param string[]            $phases
	 * @param array<string,mixed> $services
	 * @param array<string,mixed> $context
	 * @param array<string,mixed> $report
	 * @param array<string,mixed> $cursor
	 */
	private function advance_sitemap_index(
		bool $write_all,
		array $phases,
		array &$services,
		array &$context,
		array &$report,
		array &$cursor
	): string {
		if ( ! $write_all ) {
			$cursor = array();
			return $this->next_scheduled_phase( self::PHASE_SITEMAP_INDEX, $phases );
		}

		$orchestrator = $this->run_orchestrator( $services );
		$orchestrator->apply_internal_sitemap_omissions( $context['sitemap_omissions'] );
		if ( ! $this->run_sitemap_index_phase( $report, $orchestrator, $context['sitemap_children_failed'] ) ) {
			return self::PHASE_SITEMAP_INDEX;
		}

		$context['had_problems'] = $context['had_problems'] || 0 !== $this->bridge->runner_get_problem_count( $report );
		$cursor                  = array(
			'shard' => 0,
			'path'  => '',
		);
		return $this->next_scheduled_phase( self::PHASE_SITEMAP_INDEX, $phases );
	}

	/**
	 * @param array<string,mixed> $context
	 * @param array<string,mixed> $report
	 * @param array<string,mixed> $cursor
	 */
	private function advance_stale_reconciliation(
		string $mode,
		array $context,
		array &$report,
		array &$cursor
	): string {
		if ( 'off' !== $mode && ( $context['had_problems'] || 0 !== $this->bridge->runner_get_problem_count( $report ) ) ) {
			$report['skipped']['stale_reconciliation'] = array(
				'status'  => 'skipped',
				'code'    => 'dependency_failed',
				'message' => __( 'Stale owned files were retained because the desired inventory did not publish completely.', 'cybermaps' ),
				'time'    => \time(),
			);
			return self::PHASE_COMPLETE;
		}

		return $this->run_stale_reconciliation_phase( $report, $cursor )
			? self::PHASE_COMPLETE
			: self::PHASE_STALE_RECONCILIATION;
	}

	/** @param array<string,mixed> $services */
	private function run_orchestrator( array &$services ): Orchestrator {
		if ( ! ( $services['orchestrator'] ?? null ) instanceof Orchestrator ) {
			$services['orchestrator'] = new Orchestrator();
		}
		return $services['orchestrator'];
	}

	/**
	 * @param array<string,mixed> $services
	 * @param array<string,mixed> $settings
	 */
	private function run_selector( array &$services, array $settings ): AIContentSelector {
		if ( ! ( $services['selector'] ?? null ) instanceof AIContentSelector ) {
			$services['selector'] = new AIContentSelector( $settings );
		}
		return $services['selector'];
	}

	/**
	 * @param array<string,mixed> $services
	 * @param array<string,mixed> $settings
	 */
	private function run_generator( array &$services, array $settings ): DiscoveryPublicationGenerator {
		if ( ! ( $services['generator'] ?? null ) instanceof DiscoveryPublicationGenerator ) {
			$services['generator'] = new DiscoveryPublicationGenerator( $this->run_selector( $services, $settings ) );
		}
		return $services['generator'];
	}

	/**
	 * Validate every exact static target against all exact and dynamic producers.
	 *
	 * Dynamic sitemap children and RAG chunks are represented by their route
	 * grammars instead of enumerating an unbounded content inventory. Legacy purge
	 * paths are intentionally excluded because they are cleanup inputs, not
	 * publication producers.
	 *
	 * @param array<string,mixed> $settings Stable settings snapshot.
	 * @return array{valid:bool,code:string,message:string,filename:string,producers:string[],claim_count:int,claim_bytes:int}
	 */
	public function validate_publication_plan( array $settings, string $mode ): array {
		$claims      = array();
		$claim_bytes = 0;
		if ( 'off' === $mode ) {
			return $this->valid_publication_plan( 0, 0 );
		}
		if ( ! \in_array( $mode, array( 'well_known', 'all' ), true ) ) {
			return $this->invalid_publication_plan(
				'invalid_static_mode',
				__( 'The static publication plan contains an unsupported engine mode.', 'cybermaps' ),
				'',
				array(),
				0,
				0
			);
		}

		$failure = $this->claim_endpoint_publication_targets( $settings, $mode, $claims, $claim_bytes );
		if ( null !== $failure ) {
			return $failure;
		}
		if ( 'all' !== $mode ) {
			return $this->valid_publication_plan( \count( $claims ), $claim_bytes );
		}

		return $this->validate_all_publication_targets( $settings, $claims, $claim_bytes );
	}

	/**
	 * @param array<string,mixed>  $settings
	 * @param array<string,string> $claims
	 * @return array{valid:bool,code:string,message:string,filename:string,producers:string[],claim_count:int,claim_bytes:int}|null
	 */
	private function claim_endpoint_publication_targets(
		array $settings,
		string $mode,
		array &$claims,
		int &$claim_bytes
	): ?array {
		if ( empty( $settings['enable_discovery_hub'] ) ) {
			return null;
		}

		$registry = EndpointRegistry::get_instance();
		$registry->register_extension_endpoints();
		foreach ( $registry->get_static_targets( $mode, $settings ) as $target ) {
			$failure = $this->claim_publication_target(
				$claims,
				$claim_bytes,
				(string) ( $target['filename'] ?? '' ),
				'endpoint:' . (string) ( $target['id'] ?? '' )
			);
			if ( null !== $failure ) {
				return $failure;
			}
		}

		return null;
	}

	/**
	 * @param array<string,mixed>  $settings
	 * @param array<string,string> $claims
	 * @return array{valid:bool,code:string,message:string,filename:string,producers:string[],claim_count:int,claim_bytes:int}
	 */
	private function validate_all_publication_targets( array $settings, array &$claims, int &$claim_bytes ): array {
		$routes  = PublicationRouteSlugs::resolve( $settings );
		$failure = $this->claim_sitemap_publication_targets( $settings, $routes, $claims, $claim_bytes );
		if ( null !== $failure ) {
			return $failure;
		}

		$failure = $this->claim_localized_publication_targets( $settings, $claims, $claim_bytes );
		if ( null !== $failure ) {
			return $failure;
		}

		return $this->validate_generated_route_claims(
			$claims,
			$claim_bytes,
			(string) $routes[ PublicationRouteSlugs::SITEMAP_KEY ],
			! empty( $settings['enable_discovery_hub'] ) && ! empty( $settings['enable_rag_chunks'] )
		);
	}

	/**
	 * @param array<string,mixed>  $settings
	 * @param array<string,string> $routes
	 * @param array<string,string> $claims
	 * @return array{valid:bool,code:string,message:string,filename:string,producers:string[],claim_count:int,claim_bytes:int}|null
	 */
	private function claim_sitemap_publication_targets(
		array $settings,
		array $routes,
		array &$claims,
		int &$claim_bytes
	): ?array {
		$targets = array(
			array( $routes[ PublicationRouteSlugs::SITEMAP_KEY ] . '.xml', 'sitemap:index' ),
		);
		if ( ! empty( $settings['enable_google_news'] ) ) {
			$targets[] = array( $routes[ PublicationRouteSlugs::NEWS_KEY ] . '.xml', 'sitemap:news' );
		}
		if ( ! empty( $settings['enable_rss_sitemap'] ) ) {
			$targets[] = array( $routes[ PublicationRouteSlugs::RSS_KEY ] . '.xml', 'sitemap:rss' );
		}

		return $this->claim_exact_publication_targets( $targets, $claims, $claim_bytes );
	}

	/**
	 * @param array<int,array{0:string,1:string}> $targets
	 * @param array<string,string>                 $claims
	 * @return array{valid:bool,code:string,message:string,filename:string,producers:string[],claim_count:int,claim_bytes:int}|null
	 */
	private function claim_exact_publication_targets( array $targets, array &$claims, int &$claim_bytes ): ?array {
		foreach ( $targets as $target ) {
			$failure = $this->claim_publication_target( $claims, $claim_bytes, $target[0], $target[1] );
			if ( null !== $failure ) {
				return $failure;
			}
		}

		return null;
	}

	/**
	 * @param array<string,mixed>  $settings
	 * @param array<string,string> $claims
	 * @return array{valid:bool,code:string,message:string,filename:string,producers:string[],claim_count:int,claim_bytes:int}|null
	 */
	private function claim_localized_publication_targets( array $settings, array &$claims, int &$claim_bytes ): ?array {
		if ( empty( $settings['enable_discovery_hub'] ) || empty( $settings['enable_multilingual_hub'] ) ) {
			return null;
		}

		$targets = array( 'llms.txt' );
		if ( ! empty( $settings['enable_llms_full'] ) ) {
			$targets[] = 'llms-full.txt';
		}
		if ( ! empty( $settings['enable_llms_tldr'] ) ) {
			$targets[] = 'llms-tldr.txt';
		}
		foreach ( $this->bridge->runner_get_localized_languages() as $language ) {
			foreach ( $targets as $target ) {
				$failure = $this->claim_publication_target(
					$claims,
					$claim_bytes,
					$language . '/' . $target,
					'localized:' . $language . ':' . $target
				);
				if ( null !== $failure ) {
					return $failure;
				}
			}
		}

		return null;
	}

	/**
	 * @param array<string,string> $claims
	 * @return array{valid:bool,code:string,message:string,filename:string,producers:string[],claim_count:int,claim_bytes:int}
	 */
	private function validate_generated_route_claims(
		array $claims,
		int $claim_bytes,
		string $sitemap_base,
		bool $reserve_rag
	): array {
		foreach ( $claims as $filename => $producer ) {
			$failure = $this->generated_route_claim_failure(
				$filename,
				$producer,
				$sitemap_base,
				$reserve_rag,
				\count( $claims ),
				$claim_bytes
			);
			if ( null !== $failure ) {
				return $failure;
			}
		}

		return $this->valid_publication_plan( \count( $claims ), $claim_bytes );
	}

	/**
	 * @return array{valid:bool,code:string,message:string,filename:string,producers:string[],claim_count:int,claim_bytes:int}|null
	 */
	private function generated_route_claim_failure(
		string $filename,
		string $producer,
		string $sitemap_base,
		bool $reserve_rag,
		int $claim_count,
		int $claim_bytes
	): ?array {
		if ( PublicationRouteSlugs::is_generated_child_filename( $filename, $sitemap_base ) ) {
			return $this->invalid_publication_plan(
				'duplicate_static_target',
				__( 'A static target conflicts with the generated sitemap-child route family.', 'cybermaps' ),
				$filename,
				array( $producer, 'sitemap:children' ),
				$claim_count,
				$claim_bytes
			);
		}
		if ( $reserve_rag && 1 === \preg_match( '#^discovery/chunks/[1-9][0-9]*\.json$#', $filename ) ) {
			return $this->invalid_publication_plan(
				'duplicate_static_target',
				__( 'A static target conflicts with the generated RAG-chunk route family.', 'cybermaps' ),
				$filename,
				array( $producer, 'rag:chunks' ),
				$claim_count,
				$claim_bytes
			);
		}

		return null;
	}

	/**
	 * Add one bounded exact claim or return its reportable plan failure.
	 *
	 * @param array<string,string> $claims Existing filename-to-producer claims.
	 * @return array{valid:bool,code:string,message:string,filename:string,producers:string[],claim_count:int,claim_bytes:int}|null
	 */
	private function claim_publication_target(
		array &$claims,
		int &$claim_bytes,
		string $filename,
		string $producer
	): ?array {
		if ( '' === $filename ) {
			return $this->invalid_publication_plan(
				'static_target_missing',
				__( 'A static publication producer resolved to an empty target filename.', 'cybermaps' ),
				'',
				array( $producer ),
				\count( $claims ),
				$claim_bytes
			);
		}

		if ( isset( $claims[ $filename ] ) ) {
			return $this->invalid_publication_plan(
				'duplicate_static_target',
				__( 'Multiple static publication producers claim the same target filename.', 'cybermaps' ),
				$filename,
				array( $claims[ $filename ], $producer ),
				\count( $claims ),
				$claim_bytes
			);
		}

		$next_bytes = \strlen( $filename ) + \strlen( $producer );
		if (
			\count( $claims ) >= self::MAX_PLAN_CLAIMS
			|| $next_bytes > self::MAX_PLAN_BYTES
			|| $claim_bytes > self::MAX_PLAN_BYTES - $next_bytes
		) {
			return $this->invalid_publication_plan(
				'static_target_plan_too_large',
				__( 'The static publication plan exceeds its bounded target inventory.', 'cybermaps' ),
				$filename,
				array( $producer ),
				\count( $claims ),
				$claim_bytes
			);
		}

		$claims[ $filename ] = $producer;
		$claim_bytes        += $next_bytes;
		return null;
	}

	/**
	 * @return array{valid:bool,code:string,message:string,filename:string,producers:string[],claim_count:int,claim_bytes:int}
	 */
	private function valid_publication_plan( int $claim_count, int $claim_bytes ): array {
		return array(
			'valid'       => true,
			'code'        => '',
			'message'     => '',
			'filename'    => '',
			'producers'   => array(),
			'claim_count' => $claim_count,
			'claim_bytes' => $claim_bytes,
		);
	}

	/**
	 * @param string[] $producers Conflicting or rejected producers.
	 * @return array{valid:bool,code:string,message:string,filename:string,producers:string[],claim_count:int,claim_bytes:int}
	 */
	private function invalid_publication_plan(
		string $code,
		string $message,
		string $filename,
		array $producers,
		int $claim_count,
		int $claim_bytes
	): array {
		return array(
			'valid'       => false,
			'code'        => $code,
			'message'     => $message,
			'filename'    => $filename,
			'producers'   => \array_values( $producers ),
			'claim_count' => $claim_count,
			'claim_bytes' => $claim_bytes,
		);
	}

	/**
	 * @param array<string,mixed> $settings
	 * @return string[]
	 */
	private function ordered_phases( array $settings, bool $write_all, string $mode ): array {
		$phases = array( self::PHASE_LEGACY_PURGE );
		if ( 'off' === $mode ) {
			return array(
				self::PHASE_LEGACY_PURGE,
				self::PHASE_STALE_RECONCILIATION,
				self::PHASE_COMPLETE,
			);
		}
		if ( $write_all ) {
			$phases[] = self::PHASE_SITEMAP_CHILDREN;
			$phases[] = self::PHASE_RSS;
		}
		if ( ! empty( $settings['enable_discovery_hub'] ) ) {
			if ( $write_all && ! empty( $settings['enable_rag_chunks'] ) ) {
				$phases[] = self::PHASE_RAG_CHUNKS;
			}
			$phases[] = self::PHASE_DISCOVERY_LEAF;
			if ( $write_all && ! empty( $settings['enable_multilingual_hub'] ) ) {
				$phases[] = self::PHASE_LOCALIZED;
			}
			$phases[] = self::PHASE_DISCOVERY_INDEX;
		}
		if ( $write_all ) {
			$phases[] = self::PHASE_SITEMAP_INDEX;
		}
		$phases[] = self::PHASE_STALE_RECONCILIATION;
		$phases[] = self::PHASE_COMPLETE;
		return $phases;
	}

	/**
	 * @param string[] $phases
	 */
	private function next_scheduled_phase( string $current, array $phases ): string {
		$index = \array_search( $current, $phases, true );
		if ( false === $index || $index >= \count( $phases ) - 1 ) {
			return self::PHASE_COMPLETE;
		}

		return (string) $phases[ $index + 1 ];
	}

	/**
	 * @param string[] $phases
	 */
	private function next_phase( string $current, array $phases ): string {
		return $this->next_scheduled_phase( $current, $phases );
	}

	/**
	 * @param array<string,mixed> $report
	 * @param array<string,mixed> $cursor
	 */
	private function run_sitemap_children_phase( array &$report, Orchestrator $orchestrator, array &$cursor ): bool {
		$sitemap_cursor = $this->initial_sitemap_cursor( $cursor );
		$provider_index = $sitemap_cursor['provider_index'];
		$page           = $sitemap_cursor['page'];
		$omissions      = $sitemap_cursor['omissions'];
		$provider_ids   = $orchestrator->collect_weighted_provider_ids();

		$manifest       = PageOccupancyManifest::load();
		$routes         = PublicationRouteSlugs::resolve( \Cybermaps\Core\ConfigurationStore::settings() );
		$per_page       = $orchestrator->get_per_page();
		$provider_count = \count( $provider_ids );

		while ( $provider_index < $provider_count ) {
			if ( $this->bridge->runner_budget_exhausted() ) {
				$cursor['provider_index'] = $provider_index;
				$cursor['page']           = $page;
				return false;
			}

			$provider_id = (string) ( $provider_ids[ $provider_index ] ?? '' );
			$provider    = $orchestrator->get_provider( $provider_id );
			if ( '' === $provider_id || ! $provider ) {
				++$provider_index;
				$page = 1;
				continue;
			}

			if ( ! $this->prepare_sitemap_page( $provider_id, $provider, $manifest, $orchestrator, $report, $cursor, $provider_index, $page ) ) {
				continue;
			}
			if ( ! $this->publish_sitemap_child( $report, $orchestrator, $routes, $provider_id, $page, $omissions, $cursor ) ) {
				$this->save_sitemap_cursor( $cursor, $provider_index, $page, $omissions );
				return false;
			}

			++$page;
			if ( $this->bridge->runner_budget_exhausted() ) {
				$this->save_sitemap_cursor( $cursor, $provider_index, $page, $omissions );
				return false;
			}
		}

		$cursor['omissions'] = $omissions;
		return true;
	}

	/**
	 * @param array<string,mixed> $cursor
	 * @return array{provider_index:int,page:int,omissions:array<string,int[]>}
	 */
	private function initial_sitemap_cursor( array $cursor ): array {
		return array(
			'provider_index' => max( 0, (int) ( $cursor['provider_index'] ?? 0 ) ),
			'page'           => max( 1, (int) ( $cursor['page'] ?? 1 ) ),
			'omissions'      => $this->normalize_sitemap_omissions( $cursor['omissions'] ?? array() ),
		);
	}

	/**
	 * Advance a provider/page pair to a publishable child or the next provider.
	 *
	 * @param array<string,mixed>|null $manifest
	 * @param array<string,mixed>      $report
	 * @param array<string,mixed>      $cursor
	 */
	private function prepare_sitemap_page(
		string $provider_id,
		mixed $provider,
		?array $manifest,
		Orchestrator $orchestrator,
		array &$report,
		array &$cursor,
		int &$provider_index,
		int &$page
	): bool {
		$manifest_pages = $this->manifest_provider_pages( $manifest, $provider_id );
		if ( null !== $manifest_pages ) {
			$next_page = $this->next_page_at_or_after( $manifest_pages, $page );
			if ( null !== $next_page ) {
				$page = $next_page;
				return true;
			}
			$this->advance_sitemap_provider( $provider_index, $page );
			return false;
		}

		$page_count = $this->provider_page_count( $provider_id, $provider, $orchestrator );
		if ( $page_count > 50000 ) {
			$report['failed'][ 'sitemap-provider-limit:' . $provider_id ] = array(
				'code'      => 'sitemap_provider_page_limit',
				'message'   => __( 'Static sitemap publication stopped for a provider whose raw page count exceeds the 50,000-page safety ceiling.', 'cybermaps' ),
				'provider'  => $provider_id,
				'raw_pages' => $page_count,
			);
			$cursor['failed'] = true;
			$this->advance_sitemap_provider( $provider_index, $page );
			return false;
		}
		if ( $page > $page_count ) {
			$this->advance_sitemap_provider( $provider_index, $page );
			return false;
		}

		return true;
	}

	private function advance_sitemap_provider( int &$provider_index, int &$page ): void {
		++$provider_index;
		$page = 1;
	}

	/**
	 * @param array<string,mixed> $report
	 * @param array<string,string> $routes
	 * @param array<string,int[]> $omissions
	 * @param array<string,mixed> $cursor
	 */
	private function publish_sitemap_child(
		array &$report,
		Orchestrator $orchestrator,
		array $routes,
		string $provider_id,
		int $page,
		array &$omissions,
		array &$cursor
	): bool {
		$filename = ProviderIdentity::filename( $provider_id, $page, $routes );
		if ( '' === $filename ) {
			return true;
		}

		$problem_count = $this->bridge->runner_get_problem_count( $report );
		$processed     = $this->bridge->runner_publish(
			$report,
			$filename,
			static fn(): ?string => $orchestrator->generate_static_child_xml( $provider_id, $page ),
			true
		);
		if ( ! $processed ) {
			return false;
		}
		if ( \in_array( $filename, (array) ( $report['omitted'] ?? array() ), true ) && ! $this->add_sitemap_omission( $omissions, $provider_id, $page ) ) {
			$report['failed']['sitemap-omission-state'] = array(
				'code'    => 'sitemap_omission_state_limit',
				'message' => __( 'Static sitemap publication stopped because its resumable empty-page inventory exceeded the safety limit.', 'cybermaps' ),
			);
			$cursor['failed']                           = true;
		}
		$cursor['failed'] = ! empty( $cursor['failed'] )
			|| $this->bridge->runner_get_problem_count( $report ) > $problem_count;
		return true;
	}

	/**
	 * @param array<string,mixed> $cursor
	 * @param array<string,int[]> $omissions
	 */
	private function save_sitemap_cursor( array &$cursor, int $provider_index, int $page, array $omissions ): void {
		$cursor['provider_index'] = $provider_index;
		$cursor['page']           = $page;
		$cursor['omissions']      = $omissions;
	}

	/**
	 * @param array<string,mixed> $report
	 * @param array<string,mixed> $cursor
	 */
	private function run_rag_phase( array &$report, AIContentSelector $selector, array &$cursor ): bool {
		$rag_cursor    = $this->initial_rag_cursor( $cursor );
		$pending_ids   = $rag_cursor['pending_ids'];
		$pending_index = $rag_cursor['pending_index'];
		$batch_result  = $this->prepare_rag_batch( $selector, $cursor, $pending_ids, $pending_index, $report );
		if ( null !== $batch_result ) {
			return $batch_result;
		}

		$rag_chunk  = new RAGChunk( $selector, new Chunker( false ) );
		$post_count = \count( $pending_ids );
		while ( $pending_index < $post_count ) {
			if ( $this->bridge->runner_budget_exhausted() ) {
				$cursor['pending_index'] = $pending_index;
				return false;
			}
			$post_id = (int) ( $pending_ids[ $pending_index ] ?? 0 );
			if ( $post_id > 0 ) {
				if ( ! $this->publish_rag_chunk( $report, $rag_chunk, $post_id, $cursor ) ) {
					$cursor['pending_index'] = $pending_index;
					return false;
				}
			}
			++$pending_index;
			$cursor['pending_index'] = $pending_index;
		}

		$cursor['selector']      = \is_array( $cursor['next_selector'] ?? null ) ? $cursor['next_selector'] : array();
		$complete                = ! empty( $cursor['batch_complete'] );
		$cursor['pending_ids']   = array();
		$cursor['pending_index'] = 0;
		unset( $cursor['next_selector'], $cursor['batch_complete'] );
		return $complete;
	}

	/**
	 * @param array<string,mixed> $cursor
	 * @return array{pending_ids:int[],pending_index:int}
	 */
	private function initial_rag_cursor( array $cursor ): array {
		$pending_ids = \is_array( $cursor['pending_ids'] ?? null )
			? \array_slice( \array_map( 'intval', $cursor['pending_ids'] ), 0, 25 )
			: array();
		return array(
			'pending_ids'   => $pending_ids,
			'pending_index' => max( 0, (int) ( $cursor['pending_index'] ?? 0 ) ),
		);
	}

	/**
	 * @param array<string,mixed> $cursor
	 * @param int[]               $pending_ids
	 * @param array<string,mixed> $report
	 */
	private function prepare_rag_batch(
		AIContentSelector $selector,
		array &$cursor,
		array &$pending_ids,
		int &$pending_index,
		array &$report
	): ?bool {
		if ( ! empty( $pending_ids ) ) {
			return null;
		}

		try {
			$batch = $selector->get_id_batch(
				\is_array( $cursor['selector'] ?? null ) ? $cursor['selector'] : array(),
				25
			);
		} catch ( \Throwable $error ) {
			$report['failed']['discovery/chunks/*'] = array(
				'code'    => 'content_selection_failed',
				'message' => $error->getMessage(),
			);
			$cursor['failed']                       = true;
			return true;
		}

		$pending_ids              = $batch['ids'];
		$cursor['pending_ids']    = $pending_ids;
		$cursor['pending_index']  = 0;
		$cursor['next_selector']  = $batch['cursor'];
		$cursor['batch_complete'] = $batch['complete'];
		$pending_index            = 0;
		if ( ! empty( $pending_ids ) ) {
			return null;
		}

		$cursor['selector'] = $batch['cursor'];
		return ! empty( $batch['complete'] );
	}

	/**
	 * @param array<string,mixed> $report
	 * @param array<string,mixed> $cursor
	 */
	private function publish_rag_chunk( array &$report, RAGChunk $rag_chunk, int $post_id, array &$cursor ): bool {
		$problem_count = $this->bridge->runner_get_problem_count( $report );
		$processed     = $this->bridge->runner_publish(
			$report,
			'discovery/chunks/' . $post_id . '.json',
			static function () use ( $rag_chunk, $post_id ): string {
				$content = $rag_chunk->get_content_for_selected_post( $post_id );
				if ( null === $content ) {
					throw new \RuntimeException(
						__( 'The selected post no longer has a publishable RAG chunk payload.', 'cybermaps' ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
					);
				}
				return $content;
			}
		);
		if ( ! $processed ) {
			return false;
		}

		$cursor['failed'] = ! empty( $cursor['failed'] )
			|| $this->bridge->runner_get_problem_count( $report ) > $problem_count;
		return true;
	}

	/**
	 * @param array<string,mixed> $report
	 * @param array<string,mixed> $cursor
	 */
	private function run_discovery_leaf_phase(
		array &$report,
		array $settings,
		string $mode,
		DiscoveryPublicationGenerator $generator,
		array &$cursor,
		bool $chunks_failed
	): bool {
		$registry = EndpointRegistry::get_instance();
		$registry->register_extension_endpoints();
		$targets = array();
		foreach ( $registry->get_static_targets( $mode, $settings ) as $target ) {
			if ( ! \in_array( (string) ( $target['id'] ?? '' ), array( 'manifest', 'discovery_index' ), true ) ) {
				$targets[] = $target;
			}
		}

		$index        = max( 0, (int) ( $cursor['target_index'] ?? 0 ) );
		$target_count = \count( $targets );

		while ( $index < $target_count ) {
			if ( $this->bridge->runner_budget_exhausted() ) {
				$cursor['target_index'] = $index;
				return false;
			}

			$target        = $targets[ $index ];
			$endpoint_id   = (string) ( $target['id'] ?? '' );
			$problem_count = $this->bridge->runner_get_problem_count( $report );
			if ( 'ai_sitemap' === $endpoint_id && $chunks_failed ) {
				$this->bridge->runner_skip(
					$report,
					(string) $target['filename'],
					'dependency_failed',
					__( 'The AI sitemap was not updated because one or more advertised chunk publications failed.', 'cybermaps' )
				);
			} else {
				$processed = $this->bridge->runner_publish(
					$report,
					(string) $target['filename'],
					static fn(): string => $generator->generate( $endpoint_id, $target, $settings )
				);
				if ( ! $processed ) {
					$cursor['target_index'] = $index;
					return false;
				}
			}
			$cursor['failed'] = ! empty( $cursor['failed'] )
				|| $this->bridge->runner_get_problem_count( $report ) > $problem_count;
			++$index;
			$cursor['target_index'] = $index;
		}

		return true;
	}

	/**
	 * Publish one localized target at a time so language work is resumable.
	 *
	 * @param array<string,mixed> $report
	 * @param array<string,mixed> $settings
	 * @param array<string,mixed> $cursor
	 */
	private function run_localized_phase( array &$report, array $settings, array &$cursor ): bool {
		$languages = $this->bridge->runner_get_localized_languages();
		$targets   = array( 'llms' );
		if ( ! empty( $settings['enable_llms_full'] ) ) {
			$targets[] = 'llms_full';
		}
		if ( ! empty( $settings['enable_llms_tldr'] ) ) {
			$targets[] = 'llms_tldr';
		}

		$language_index = max( 0, (int) ( $cursor['language_index'] ?? 0 ) );
		$target_index   = max( 0, (int) ( $cursor['target_index'] ?? 0 ) );
		$language_count = \count( $languages );
		$target_count   = \count( $targets );
		while ( $language_index < $language_count ) {
			while ( $target_index < $target_count ) {
				if ( $this->bridge->runner_budget_exhausted() ) {
					$cursor['language_index'] = $language_index;
					$cursor['target_index']   = $target_index;
					return false;
				}

				$problem_count = $this->bridge->runner_get_problem_count( $report );
				$processed     = $this->bridge->runner_publish_localized_target(
					$report,
					(string) $languages[ $language_index ],
					(string) $targets[ $target_index ]
				);
				if ( ! $processed ) {
					$cursor['language_index'] = $language_index;
					$cursor['target_index']   = $target_index;
					return false;
				}
				$cursor['failed'] = ! empty( $cursor['failed'] )
					|| $this->bridge->runner_get_problem_count( $report ) > $problem_count;
				++$target_index;
				$cursor['language_index'] = $language_index;
				$cursor['target_index']   = $target_index;
			}

			++$language_index;
			$target_index             = 0;
			$cursor['language_index'] = $language_index;
			$cursor['target_index']   = 0;
		}

		return true;
	}

	/**
	 * @param array<string,mixed> $report
	 * @param array<string,mixed> $cursor
	 */
	private function run_discovery_index_phase(
		array &$report,
		array $settings,
		string $mode,
		DiscoveryPublicationGenerator $generator,
		array &$cursor,
		bool $dependencies_failed
	): bool {
		$registry = EndpointRegistry::get_instance();
		$registry->register_extension_endpoints();
		$targets = array();
		foreach ( $registry->get_static_targets( $mode, $settings ) as $target ) {
			if ( \in_array( (string) ( $target['id'] ?? '' ), array( 'manifest', 'discovery_index' ), true ) ) {
				$targets[] = $target;
			}
		}

		$index        = max( 0, (int) ( $cursor['target_index'] ?? 0 ) );
		$target_count = \count( $targets );

		while ( $index < $target_count ) {
			if ( $this->bridge->runner_budget_exhausted() ) {
				$cursor['target_index'] = $index;
				return false;
			}

			$target        = $targets[ $index ];
			$problem_count = $this->bridge->runner_get_problem_count( $report );
			if ( $dependencies_failed ) {
				$this->bridge->runner_skip(
					$report,
					(string) $target['filename'],
					'dependency_failed',
					__( 'The discovery index was not updated because one or more child publications failed.', 'cybermaps' )
				);
			} else {
				$processed = $this->bridge->runner_publish(
					$report,
					(string) $target['filename'],
					static fn(): string => $generator->generate( (string) ( $target['id'] ?? '' ), $target, $settings )
				);
				if ( ! $processed ) {
					$cursor['target_index'] = $index;
					return false;
				}
			}
			$cursor['failed'] = ! empty( $cursor['failed'] )
				|| $this->bridge->runner_get_problem_count( $report ) > $problem_count;
			++$index;
			$cursor['target_index'] = $index;
		}

		return true;
	}

	/**
	 * @param array<string,mixed> $report
	 */
	private function run_sitemap_index_phase( array &$report, Orchestrator $orchestrator, bool $children_failed ): bool {
		$base = Orchestrator::get_sitemap_base();
		if ( $children_failed ) {
			$this->bridge->runner_skip(
				$report,
				$base . '.xml',
				'dependency_failed',
				__( 'The sitemap index was not updated because one or more child sitemaps failed.', 'cybermaps' )
			);
			return true;
		}

		return $this->bridge->runner_publish(
			$report,
			$base . '.xml',
			static fn(): string => (string) $orchestrator->generate_xml( 'index', 1 )
		);
	}

	/**
	 * @param array<string,mixed> $report
	 * @param array<string,mixed> $cursor
	 */
	private function run_stale_reconciliation_phase( array &$report, array &$cursor ): bool {
		return $this->bridge->runner_reconcile_stale_slice( $report, $cursor );
	}

	/**
	 * @param mixed $context Persisted bounded runner context.
	 * @return array<string,mixed>
	 */
	private function normalize_context( mixed $context, string $topology ): array {
		$context = \is_array( $context ) ? $context : array();
		return array(
			'had_problems'            => ! empty( $context['had_problems'] ),
			'sitemap_children_failed' => ! empty( $context['sitemap_children_failed'] ),
			'sitemap_omissions'       => $this->normalize_sitemap_omissions( $context['sitemap_omissions'] ?? array() ),
			'rag_failed'              => ! empty( $context['rag_failed'] ),
			'discovery_leaf_failed'   => ! empty( $context['discovery_leaf_failed'] ),
			'localized_failed'        => ! empty( $context['localized_failed'] ),
			'topology'                => $topology,
			'aggregate'               => $this->normalize_aggregate( $context['aggregate'] ?? array() ),
		);
	}

	/**
	 * Persist whether one operation added a report problem.
	 *
	 * @param array<string,mixed> $report
	 * @param array<string,bool>  $context
	 */
	private function capture_problem_delta( array $report, int $before, array &$context ): void {
		if ( $this->bridge->runner_get_problem_count( $report ) > $before ) {
			$context['had_problems'] = true;
		}
	}

	/**
	 * Fold one bounded request slice into the persisted run summary.
	 *
	 * @param array<string,mixed> $context
	 * @param array<string,mixed> $report
	 */
	private function absorb_report( array &$context, array &$report ): void {
		$aggregate = $this->normalize_aggregate( $context['aggregate'] ?? array() );
		foreach ( array( 'desired', 'written', 'unchanged', 'omitted', 'deleted' ) as $field ) {
			$items                          = \array_values( \array_unique( \array_filter( (array) ( $report[ $field ] ?? array() ), 'is_string' ) ) );
			$aggregate['counts'][ $field ]  = $this->saturating_add(
				$aggregate['counts'][ $field ],
				\count( $items )
			);
			$aggregate['samples'][ $field ] = \array_slice(
				\array_values( \array_unique( \array_merge( $aggregate['samples'][ $field ], $items ) ) ),
				0,
				self::REPORT_SAMPLE_LIMIT
			);
			$report[ $field ]               = array();
		}

		foreach ( array( 'conflicted', 'failed', 'skipped', 'retained' ) as $field ) {
			$raw_items                      = \is_array( $report[ $field ] ?? null )
				? $report[ $field ]
				: array();
			$items                          = $this->sanitize_map_samples( $raw_items );
			$aggregate['counts'][ $field ]  = $this->saturating_add(
				$aggregate['counts'][ $field ],
				\count( $raw_items )
			);
			$aggregate['samples'][ $field ] = \array_slice(
				\array_replace( $aggregate['samples'][ $field ], $items ),
				0,
				self::REPORT_SAMPLE_LIMIT,
				true
			);
			$report[ $field ]               = array();
		}

		$context['aggregate'] = $aggregate;
	}

	/**
	 * Restore bounded samples while preserving truthful cross-slice counters.
	 *
	 * @param array<string,mixed> $report
	 * @param array<string,mixed> $context
	 */
	private function hydrate_report( array &$report, array $context ): void {
		$aggregate = $this->normalize_aggregate( $context['aggregate'] ?? array() );
		foreach ( \array_keys( $aggregate['samples'] ) as $field ) {
			$report[ $field ] = $aggregate['samples'][ $field ];
		}
		$report['_aggregate_counts'] = $aggregate['counts'];
	}

	/**
	 * @return array{counts:array<string,int>,samples:array<string,array<mixed>>}
	 */
	private function normalize_aggregate( mixed $aggregate ): array {
		$aggregate  = \is_array( $aggregate ) ? $aggregate : array();
		$counts     = \is_array( $aggregate['counts'] ?? null ) ? $aggregate['counts'] : array();
		$samples    = \is_array( $aggregate['samples'] ?? null ) ? $aggregate['samples'] : array();
		$normalized = array(
			'counts'  => array(),
			'samples' => array(),
		);
		foreach ( array( 'desired', 'written', 'unchanged', 'omitted', 'conflicted', 'failed', 'skipped', 'deleted', 'retained' ) as $field ) {
			$normalized['counts'][ $field ]  = max( 0, (int) ( $counts[ $field ] ?? 0 ) );
			$normalized['samples'][ $field ] = \array_slice(
				\is_array( $samples[ $field ] ?? null ) ? $samples[ $field ] : array(),
				0,
				self::REPORT_SAMPLE_LIMIT,
				\in_array( $field, array( 'conflicted', 'failed', 'skipped', 'retained' ), true )
			);
		}
		return $normalized;
	}

	private function is_valid_context( mixed $context ): bool {
		if ( ! \is_array( $context ) ) {
			return false;
		}
		$boolean_keys = array(
			'had_problems',
			'sitemap_children_failed',
			'rag_failed',
			'discovery_leaf_failed',
			'localized_failed',
		);
		if ( array() !== \array_diff( \array_keys( $context ), \array_merge( $boolean_keys, array( 'aggregate', 'sitemap_omissions', 'topology' ) ) ) ) {
			return false;
		}
		if ( ! \is_string( $context['topology'] ?? null ) || 1 !== \preg_match( '/^[a-f0-9]{64}$/D', $context['topology'] ) ) {
			return false;
		}
		foreach ( $boolean_keys as $key ) {
			if ( \array_key_exists( $key, $context ) && ! \is_bool( $context[ $key ] ) ) {
				return false;
			}
		}
		if ( \array_key_exists( 'aggregate', $context ) && ! $this->is_valid_aggregate( $context['aggregate'] ) ) {
			return false;
		}

		return ! \array_key_exists( 'sitemap_omissions', $context )
			|| $this->is_valid_sitemap_omissions( $context['sitemap_omissions'] );
	}

	/**
	 * Fence numeric continuation cursors to the exact extension/runtime topology
	 * that established their ordering.
	 *
	 * @param array<string,mixed> $settings Current settings snapshot.
	 * @internal Exposed for deterministic continuation fixtures and diagnostics.
	 */
	public function topology_fingerprint( array $settings, string $mode ): string {
		$orchestrator = new Orchestrator();
		$registry     = EndpointRegistry::get_instance();
		$registry->register_extension_endpoints();
		$targets = array();
		foreach ( $registry->get_static_targets( $mode, $settings ) as $target ) {
			$targets[] = array(
				'id'       => (string) ( $target['id'] ?? '' ),
				'filename' => (string) ( $target['filename'] ?? '' ),
			);
		}

		$encoded = \wp_json_encode(
			array(
				'mode'       => $mode,
				'providers'  => $orchestrator->collect_weighted_provider_ids(),
				'targets'    => $targets,
				'languages'  => $this->bridge->runner_get_localized_languages(),
				'post_types' => \Cybermaps\Core\PublicationPostTypes::names(),
			)
		);

		return \hash( 'sha256', false === $encoded ? '' : $encoded );
	}

	private function is_valid_aggregate( mixed $aggregate ): bool {
		if ( ! \is_array( $aggregate ) || ! $this->has_valid_aggregate_keys( $aggregate ) ) {
			return false;
		}
		if ( ! $this->has_valid_aggregate_groups( $aggregate ) ) {
			return false;
		}

		return $this->has_valid_aggregate_counts( (array) ( $aggregate['counts'] ?? array() ) )
			&& $this->has_valid_aggregate_samples( (array) ( $aggregate['samples'] ?? array() ) );
	}

	/** @param array<string,mixed> $aggregate */
	private function has_valid_aggregate_keys( array $aggregate ): bool {
		return array() === \array_diff( \array_keys( $aggregate ), array( 'counts', 'samples' ) );
	}

	/** @param array<string,mixed> $aggregate */
	private function has_valid_aggregate_groups( array $aggregate ): bool {
		foreach ( array( 'counts', 'samples' ) as $key ) {
			if ( \array_key_exists( $key, $aggregate ) && ! \is_array( $aggregate[ $key ] ) ) {
				return false;
			}
		}

		$fields = $this->aggregate_fields();
		return array() === \array_diff( \array_keys( (array) ( $aggregate['counts'] ?? array() ) ), $fields )
			&& array() === \array_diff( \array_keys( (array) ( $aggregate['samples'] ?? array() ) ), $fields );
	}

	/** @return string[] */
	private function aggregate_fields(): array {
		return array( 'desired', 'written', 'unchanged', 'omitted', 'conflicted', 'failed', 'skipped', 'deleted', 'retained' );
	}

	/** @param array<string,mixed> $counts */
	private function has_valid_aggregate_counts( array $counts ): bool {
		foreach ( $counts as $count ) {
			if ( ! $this->is_non_negative_integer( $count ) ) {
				return false;
			}
		}

		return true;
	}

	/** @param array<string,mixed> $samples */
	private function has_valid_aggregate_samples( array $samples ): bool {
		foreach ( $samples as $field => $sample ) {
			if ( ! $this->is_valid_aggregate_sample( $field, $sample ) ) {
				return false;
			}
		}

		return true;
	}

	private function is_valid_aggregate_sample( string $field, mixed $sample ): bool {
		if ( ! \is_array( $sample ) || \count( $sample ) > self::REPORT_SAMPLE_LIMIT ) {
			return false;
		}

		return $this->is_list_aggregate_field( $field )
			? $this->has_valid_list_sample_values( $sample )
			: $this->has_valid_map_sample_values( $sample );
	}

	private function is_list_aggregate_field( string $field ): bool {
		return \in_array( $field, array( 'desired', 'written', 'unchanged', 'omitted', 'deleted' ), true );
	}

	/** @param array<mixed> $sample */
	private function has_valid_list_sample_values( array $sample ): bool {
		if ( ! \array_is_list( $sample ) ) {
			return false;
		}
		foreach ( $sample as $value ) {
			if ( ! $this->is_bounded_sample_key( $value ) ) {
				return false;
			}
		}

		return true;
	}

	/** @param array<mixed> $sample */
	private function has_valid_map_sample_values( array $sample ): bool {
		foreach ( $sample as $key => $value ) {
			if ( ! $this->is_bounded_sample_key( $key ) || ! $this->is_valid_diagnostic_sample( $value ) ) {
				return false;
			}
		}

		return true;
	}

	private function is_bounded_sample_key( mixed $value ): bool {
		return \is_string( $value )
			&& '' !== $value
			&& \strlen( $value ) <= StaticOwnershipStore::MAX_PATH_LENGTH;
	}

	private function is_valid_started_at( mixed $value ): bool {
		if (
			! \is_string( $value )
			|| \strlen( $value ) > 64
			|| 1 !== \preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $value )
		) {
			return false;
		}
		$timestamp = \strtotime( $value );
		return false !== $timestamp && $timestamp <= \time() + 300;
	}

	private function is_valid_diagnostic_sample( mixed $value ): bool {
		if ( \is_string( $value ) ) {
			return \strlen( $value ) <= self::MAX_STATE_TEXT;
		}
		if ( ! \is_array( $value ) || \count( $value ) > self::MAX_DIAGNOSTIC_KEYS ) {
			return false;
		}
		foreach ( $value as $key => $field_value ) {
			if ( ! \is_string( $key ) || '' === $key || \strlen( $key ) > 64 ) {
				return false;
			}
			if ( \is_string( $field_value ) ) {
				if ( \strlen( $field_value ) > self::MAX_STATE_TEXT ) {
					return false;
				}
				continue;
			}
			if ( null !== $field_value && ! \is_int( $field_value ) && ! \is_bool( $field_value ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Keep only bounded scalar diagnostics before continuation persistence.
	 *
	 * @param array<mixed> $samples
	 * @return array<string,string|array<string,int|string|bool|null>>
	 */
	private function sanitize_map_samples( array $samples ): array {
		$bounded = array();
		foreach ( $samples as $key => $value ) {
			if ( ! $this->is_bounded_sample_key( $key ) || ! $this->is_valid_diagnostic_sample( $value ) ) {
				continue;
			}
			$bounded[ $key ] = $value;
		}

		return $bounded;
	}

	private function saturating_add( int $left, int $right ): int {
		$left  = max( 0, $left );
		$right = max( 0, $right );
		return $right > PHP_INT_MAX - $left ? PHP_INT_MAX : $left + $right;
	}

	private function is_valid_cursor( string $phase, mixed $cursor ): bool {
		if ( ! \is_array( $cursor ) ) {
			return false;
		}
		if ( ! $this->has_allowed_cursor_keys( $phase, $cursor ) ) {
			return false;
		}
		if ( \array_key_exists( 'failed', $cursor ) && ! \is_bool( $cursor['failed'] ) ) {
			return false;
		}

		return $this->is_valid_phase_cursor( $phase, $cursor );
	}

	/** @param array<string,mixed> $cursor */
	private function is_valid_phase_cursor( string $phase, array $cursor ): bool {
		return match ( $phase ) {
			self::PHASE_LEGACY_PURGE => $this->valid_int( $cursor, 'index', 0, 100 ),
			self::PHASE_SITEMAP_CHILDREN => $this->is_valid_sitemap_cursor( $cursor ),
			self::PHASE_RAG_CHUNKS => $this->is_valid_rag_cursor( $cursor ),
			self::PHASE_DISCOVERY_LEAF, self::PHASE_DISCOVERY_INDEX => $this->valid_int( $cursor, 'target_index', 0, 10000 ),
			self::PHASE_LOCALIZED => $this->is_valid_localized_cursor( $cursor ),
			self::PHASE_STALE_RECONCILIATION => $this->is_valid_stale_cursor( $cursor ),
			default => array() === $cursor,
		};
	}

	/** @param array<string,mixed> $cursor */
	private function has_allowed_cursor_keys( string $phase, array $cursor ): bool {
		$allowed = match ( $phase ) {
			self::PHASE_LEGACY_PURGE => array( 'index' ),
			self::PHASE_SITEMAP_CHILDREN => array( 'provider_index', 'page', 'failed', 'omissions' ),
			self::PHASE_RAG_CHUNKS => array( 'selector', 'pending_ids', 'pending_index', 'next_selector', 'batch_complete', 'failed' ),
			self::PHASE_DISCOVERY_LEAF, self::PHASE_DISCOVERY_INDEX => array( 'target_index', 'failed' ),
			self::PHASE_LOCALIZED => array( 'language_index', 'target_index', 'failed' ),
			self::PHASE_STALE_RECONCILIATION => array( 'shard', 'path' ),
			default => array(),
		};
		return array() === \array_diff( \array_keys( $cursor ), $allowed );
	}

	/** @param array<string,mixed> $cursor */
	private function is_valid_sitemap_cursor( array $cursor ): bool {
		return $this->valid_int( $cursor, 'provider_index', 0, 100000 )
			&& $this->valid_int( $cursor, 'page', 1, 50001 )
			&& ( ! \array_key_exists( 'omissions', $cursor ) || $this->is_valid_sitemap_omissions( $cursor['omissions'] ) );
	}

	/** @param array<string,mixed> $cursor */
	private function is_valid_localized_cursor( array $cursor ): bool {
		return $this->valid_int( $cursor, 'language_index', 0, 10000 )
			&& $this->valid_int( $cursor, 'target_index', 0, 3 );
	}

	/** @param array<string,mixed> $cursor */
	private function is_valid_stale_cursor( array $cursor ): bool {
		return $this->valid_int( $cursor, 'shard', 0, StaticOwnershipStore::SHARD_COUNT )
			&& $this->has_valid_stale_path( $cursor );
	}

	/** @param array<string,mixed> $cursor */
	private function has_valid_stale_path( array $cursor ): bool {
		if ( ! \array_key_exists( 'path', $cursor ) ) {
			return true;
		}

		return \is_string( $cursor['path'] ) && \strlen( $cursor['path'] ) <= StaticOwnershipStore::MAX_PATH_LENGTH;
	}

	/** @param array<string,mixed> $cursor */
	private function is_valid_rag_cursor( array $cursor ): bool {
		foreach ( array( 'selector', 'next_selector' ) as $key ) {
			if ( \array_key_exists( $key, $cursor ) && ! $this->is_valid_selector_cursor( $cursor[ $key ] ) ) {
				return false;
			}
		}
		$ids = \array_key_exists( 'pending_ids', $cursor ) ? $cursor['pending_ids'] : array();
		if ( ! \is_array( $ids ) || \count( $ids ) > 25 ) {
			return false;
		}
		$unique = array();
		foreach ( $ids as $id ) {
			if ( ! \is_int( $id ) || $id < 1 || isset( $unique[ $id ] ) ) {
				return false;
			}
			$unique[ $id ] = true;
		}
		if ( \array_key_exists( 'batch_complete', $cursor ) && ! \is_bool( $cursor['batch_complete'] ) ) {
			return false;
		}
		return $this->valid_int( $cursor, 'pending_index', 0, \count( $ids ) );
	}

	private function is_valid_selector_cursor( mixed $cursor ): bool {
		if ( ! \is_array( $cursor ) ) {
			return false;
		}
		$keys = array( 'type_index', 'page', 'position', 'type_selected', 'inspected' );
		if ( array() !== \array_diff( \array_keys( $cursor ), $keys ) ) {
			return false;
		}
		return $this->valid_int( $cursor, 'type_index', 0, 10000 )
			&& $this->valid_int( $cursor, 'page', 1, 1000000 )
			&& $this->valid_int( $cursor, 'position', 0, 250 )
			&& $this->valid_int( $cursor, 'type_selected', 0, 100000 )
			&& $this->valid_int( $cursor, 'inspected', 0, 5000 );
	}

	/** @param array<string,mixed> $values */
	private function valid_int( array $values, string $key, int $minimum, int $maximum ): bool {
		if ( ! \array_key_exists( $key, $values ) ) {
			return true;
		}
		return \is_int( $values[ $key ] )
			&& $values[ $key ] >= $minimum
			&& $values[ $key ] <= $maximum;
	}

	private function provider_page_count(
		string $provider_id,
		\Cybermaps\Sitemap\ProviderInterface $provider,
		Orchestrator $orchestrator
	): int {
		$count = $provider->get_count();
		if ( $count < 1 && ProviderIdentity::NEWS !== $provider_id ) {
			return 0;
		}

		return ProviderIdentity::is_single_page( $provider_id )
			? 1
			: max( 1, (int) \ceil( $count / $orchestrator->get_per_page() ) );
	}

	/**
	 * Return a validated sorted raw-page list from the current manifest.
	 *
	 * A malformed record falls back to the raw provider upper bound so corrupt
	 * internal state cannot make an eligible child disappear.
	 *
	 * @param array<string,mixed>|null $manifest
	 * @return int[]|null Null means the caller must use the raw fallback.
	 */
	private function manifest_provider_pages( ?array $manifest, string $provider_id ): ?array {
		return PageOccupancyManifest::provider_pages( $manifest, $provider_id );
	}

	/** @param array<string,int[]> $omissions */
	private function add_sitemap_omission( array &$omissions, string $provider_id, int $page ): bool {
		$candidate = $omissions;
		$pages     = $candidate[ $provider_id ] ?? array();
		if ( ! \in_array( $page, $pages, true ) ) {
			$pages[] = $page;
			\sort( $pages, SORT_NUMERIC );
		}
		$candidate[ $provider_id ] = $pages;
		\ksort( $candidate, SORT_STRING );
		if ( ! $this->is_valid_sitemap_omissions( $candidate ) ) {
			return false;
		}

		$omissions = $candidate;
		return true;
	}

	/** @return array<string,int[]> */
	private function normalize_sitemap_omissions( mixed $omissions ): array {
		if ( ! \is_array( $omissions ) ) {
			return array();
		}

		$normalized = array();
		foreach ( $omissions as $provider_id => $pages ) {
			if ( ! \is_string( $provider_id ) || ! \is_array( $pages ) ) {
				continue;
			}
			$normalized_pages = \array_values( \array_unique( \array_map( 'intval', $pages ) ) );
			$normalized_pages = \array_values(
				\array_filter(
					$normalized_pages,
					static fn( int $page ): bool => $page >= 1 && $page <= PageOccupancyManifest::MAX_RAW_PAGES
				)
			);
			\sort( $normalized_pages, SORT_NUMERIC );
			if ( ! empty( $normalized_pages ) ) {
				$normalized[ $provider_id ] = $normalized_pages;
			}
		}
		\ksort( $normalized, SORT_STRING );

		return $normalized;
	}

	private function is_valid_sitemap_omissions( mixed $omissions ): bool {
		if ( ! \is_array( $omissions ) ) {
			return false;
		}
		$encoded = \wp_json_encode( $omissions );
		if ( ! \is_string( $encoded ) || \strlen( $encoded ) > self::MAX_OMISSION_BYTES ) {
			return false;
		}

		$total    = 0;
		$previous = '';
		foreach ( $omissions as $provider_id => $pages ) {
			if (
				! \is_string( $provider_id )
				|| '' === $provider_id
				|| \strlen( $provider_id ) > 191
				|| ( '' !== $previous && \strcmp( $previous, $provider_id ) >= 0 )
				|| ! \is_array( $pages )
				|| ! \array_is_list( $pages )
			) {
				return false;
			}
			$previous_page = 0;
			foreach ( $pages as $page ) {
				if (
					! \is_int( $page )
					|| $page < 1
					|| $page > PageOccupancyManifest::MAX_RAW_PAGES
					|| $page <= $previous_page
				) {
					return false;
				}
				$previous_page = $page;
				++$total;
				if ( $total > self::MAX_OMISSION_PAGES ) {
					return false;
				}
			}
			if ( empty( $pages ) ) {
				return false;
			}
			$previous = $provider_id;
		}

		return true;
	}

	/**
	 * Find the first confirmed non-empty raw page at or after the cursor.
	 *
	 * @param int[] $pages Strictly increasing raw page numbers.
	 */
	private function next_page_at_or_after( array $pages, int $cursor ): ?int {
		$low    = 0;
		$high   = \count( $pages ) - 1;
		$result = null;
		while ( $low <= $high ) {
			$middle = (int) \floor( ( $low + $high ) / 2 );
			$page   = $pages[ $middle ];
			if ( $page < $cursor ) {
				$low = $middle + 1;
				continue;
			}
			$result = $page;
			$high   = $middle - 1;
		}

		return $result;
	}
}
