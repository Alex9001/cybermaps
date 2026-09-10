<?php
declare(strict_types=1);

namespace Cybermaps\Sitemap;

use Cybermaps\Core\AtomicOptionSequence;
use Cybermaps\Core\CacheManager;
use Cybermaps\Core\OptionLeaseLock;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Background builder for generation-scoped sitemap page occupancy.
 */
final class PageOccupancyBuilder {
	public const HOOK         = 'cybermaps_bg_build_sitemap_occupancy';
	public const WORK_OPTION  = 'cybermaps_sitemap_occupancy_work';
	public const STATE_OPTION = 'cybermaps_sitemap_occupancy_state';
	public const LOCK_OPTION  = 'cybermaps_sitemap_occupancy_lock';

	private const SLICE_MAX_PAGES     = 10;
	private const SLICE_MAX_SECONDS   = 20;
	private const GLOBAL_PAGE_CEILING = 50000;
	private const LOCK_TTL            = 600;
	private const CONTINUE_DELAY      = 30;
	private const FAILURE_RETRY_DELAY = 300;

	private Orchestrator $orchestrator;
	private OptionLeaseLock $lock;

	public function __construct( Orchestrator $orchestrator ) {
		$this->orchestrator = $orchestrator;
		$this->lock         = new OptionLeaseLock( self::LOCK_OPTION, self::LOCK_TTL, 300 );
	}

	public function register_hooks(): void {
		\add_action( self::HOOK, array( $this, 'process_slice' ) );
		\add_action( 'init', array( $this, 'ensure_scheduled' ), 20 );
	}

	public function invalidate(): void {
		$generation = AtomicOptionSequence::increment( PageOccupancyManifest::GENERATION_OPTION );
		if ( $generation < 1 ) {
			$this->write_state(
				array(
					'status'            => 'failed',
					'generation'        => -1,
					'token'             => '',
					'scanned_raw_pages' => 0,
					'non_empty_pages'   => 0,
					'empty_pages'       => 0,
					'unknown_pages'     => 0,
					'last_error'        => __( 'The sitemap occupancy generation fence could not be advanced.', 'cybermaps' ),
					'updated_at'        => \gmdate( 'c' ),
				)
			);
			$this->schedule_slice( self::FAILURE_RETRY_DELAY );
			return;
		}
		$token = \wp_generate_password( 32, false, false );
		\update_option( PageOccupancyManifest::TOKEN_OPTION, $token, false );
		\delete_option( PageOccupancyManifest::MANIFEST_OPTION );
		\delete_option( self::WORK_OPTION );
		$fence = $this->current_fence( true );
		$this->write_state(
			array(
				'status'            => 'stale',
				'generation'        => $fence['generation'],
				'token'             => $fence['token'],
				'scanned_raw_pages' => 0,
				'non_empty_pages'   => 0,
				'empty_pages'       => 0,
				'unknown_pages'     => 0,
				'last_error'        => '',
				'updated_at'        => \gmdate( 'c' ),
			)
		);
		$this->schedule_slice( 0 );
	}

	public function ensure_scheduled(): void {
		if ( PageOccupancyManifest::is_usable() ) {
			return;
		}
		$state = $this->read_state();
		$fence = $this->current_fence( true );
		if (
			'limited' === (string) ( $state['status'] ?? '' )
			&& (int) ( $state['generation'] ?? -1 ) === $fence['generation']
			&& \is_scalar( $state['token'] ?? null )
			&& \hash_equals( $fence['token'], (string) $state['token'] )
		) {
			return;
		}

		if ( \wp_next_scheduled( self::HOOK ) ) {
			return;
		}

		$this->schedule_slice( self::CONTINUE_DELAY );
	}

	public function process_slice(): void {
		if ( ! $this->acquire_lock() ) {
			$this->ensure_retry_scheduled();
			return;
		}

		$context = $this->prepare_slice_context();
		if ( null === $context ) {
			$this->release_lock();
			return;
		}

		try {
			$work = $this->scan_slice(
				$context['work'],
				$context['generation'],
				$context['token'],
				$context['started_at']
			);
		} catch ( \Throwable $error ) {
			$this->handle_slice_exception( $context['generation'], $context['token'], $error );
			return;
		}

		if ( ! $this->fence_matches( $context['generation'], $context['token'] ) ) {
			$this->handle_fence_loss();
			return;
		}

		$this->finish_slice( $work, $context );
	}

	/**
	 * @return array{started_at:int,fence:array{generation:int,token:string},generation:int,token:string,work:array<string,mixed>}|null
	 */
	private function prepare_slice_context(): ?array {
		$started_at = \time();
		$fence      = $this->current_fence( true );
		$generation = $fence['generation'];
		$token      = $fence['token'];
		$work       = $this->read_work();

		if (
			! \is_array( $work )
			|| (int) ( $work['generation'] ?? 0 ) !== $generation
			|| ! \is_scalar( $work['token'] ?? null )
			|| ! \hash_equals( $token, (string) $work['token'] )
		) {
			$work = $this->begin_work( $generation, $token );
		}
		if ( ! \is_array( $work ) ) {
			return null;
		}

		$this->write_state(
			array_merge(
				$this->read_state(),
				array(
					'status'     => 'building',
					'generation' => $generation,
					'token'      => $token,
					'updated_at' => \gmdate( 'c' ),
				)
			)
		);

		return array(
			'started_at' => $started_at,
			'fence'      => $fence,
			'generation' => $generation,
			'token'      => $token,
			'work'       => $work,
		);
	}

	private function handle_slice_exception( int $generation, string $token, \Throwable $error ): void {
		$fence_matches = $this->fence_matches( $generation, $token );
		$lock_lost     = $this->lock->is_lost();
		if ( $fence_matches ) {
			$this->record_failure( $generation, $token, $error->getMessage() );
		} elseif ( ! $lock_lost ) {
			$this->record_stale_and_retry();
		} else {
			$this->ensure_retry_scheduled();
		}
		$this->release_lock();
	}

	private function handle_fence_loss(): void {
		$lock_lost = $this->lock->is_lost();
		$this->release_lock();
		if ( ! $lock_lost ) {
			$this->record_stale_and_retry();
		} else {
			$this->ensure_retry_scheduled();
		}
	}

	/**
	 * @param array<string,mixed> $work Completed slice work.
	 * @param array{fence:array{generation:int,token:string},generation:int,token:string} $context Slice context.
	 */
	private function finish_slice( array $work, array $context ): void {
		$generation = $context['generation'];
		$token      = $context['token'];
		if ( ! empty( $work['ceiling_reached'] ) ) {
			$this->record_limit( $work, $generation, $token );
			\delete_option( self::WORK_OPTION );
			$this->release_lock();
			return;
		}
		if ( empty( $work['complete'] ) ) {
			\update_option( self::WORK_OPTION, $work, false );
			$this->schedule_slice( self::CONTINUE_DELAY );
			$this->release_lock();
			return;
		}
		if ( $this->publish_manifest( $work, $generation, $token ) ) {
			\delete_option( self::WORK_OPTION );
			CacheManager::clear_family( 'sitemap' );
			$this->release_lock();
			return;
		}

		$this->handle_manifest_publish_failure( $context['fence'], $generation, $token );
	}

	/**
	 * @param array{generation:int,token:string} $fence Slice fence.
	 */
	private function handle_manifest_publish_failure( array $fence, int $generation, string $token ): void {
		if ( $this->lock->is_lost() ) {
			$this->release_lock();
			$this->ensure_retry_scheduled();
			return;
		}
		if ( $this->current_fence() === $fence ) {
			$this->record_failure(
				$generation,
				$token,
				__( 'The sitemap occupancy manifest could not be persisted.', 'cybermaps' )
			);
			$this->release_lock();
			return;
		}

		$this->release_lock();
		$this->record_stale_and_retry();
	}

	private function ensure_retry_scheduled(): void {
		if ( ! \wp_next_scheduled( self::HOOK ) ) {
			$this->schedule_slice( self::CONTINUE_DELAY );
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function begin_work( int $generation, string $token ): array {
		$provider_ids = $this->orchestrator->collect_weighted_provider_ids();
		$work         = array(
			'generation'        => $generation,
			'token'             => $token,
			'provider_ids'      => $provider_ids,
			'provider_index'    => 0,
			'next_page'         => 1,
			'providers'         => array(),
			'scanned_raw_pages' => 0,
			'non_empty_pages'   => 0,
			'empty_pages'       => 0,
			'complete'          => false,
		);
		\update_option( self::WORK_OPTION, $work, false );
		return $work;
	}

	/**
	 * @param array<string, mixed> $work
	 * @return array<string, mixed>
	 */
	private function scan_slice( array $work, int $generation, string $token, int $started_at ): array {
		$provider_ids   = \is_array( $work['provider_ids'] ?? null ) ? $work['provider_ids'] : array();
		$provider_count = \count( $provider_ids );
		$pages_scanned  = 0;

		while ( (int) ( $work['provider_index'] ?? 0 ) < $provider_count ) {
			if ( $this->slice_budget_reached( $pages_scanned, $started_at ) ) {
				break;
			}
			if ( ! $this->fence_matches( $generation, $token ) ) {
				break;
			}
			if ( ! $this->scan_current_provider_page( $work, $provider_ids, $generation, $token, $pages_scanned ) ) {
				break;
			}
		}

		if ( (int) ( $work['provider_index'] ?? 0 ) >= $provider_count ) {
			$work['complete'] = true;
		}

		return $work;
	}

	private function slice_budget_reached( int $pages_scanned, int $started_at ): bool {
		return $pages_scanned >= self::SLICE_MAX_PAGES
			|| ( \time() - $started_at ) >= self::SLICE_MAX_SECONDS;
	}

	/**
	 * @param array<string,mixed> $work         Current work checkpoint.
	 * @param string[]            $provider_ids Ordered provider IDs.
	 */
	private function scan_current_provider_page(
		array &$work,
		array $provider_ids,
		int $generation,
		string $token,
		int &$pages_scanned
	): bool {
		$context = $this->current_provider_context( $work, $provider_ids );
		if ( null === $context ) {
			return true;
		}

		$provider_id = $context['provider_id'];
		$provider    = $context['provider'];
		$record      = $this->provider_record( $work, $provider_id, $provider );
		$page        = max( 1, (int) ( $work['next_page'] ?? 1 ) );
		if ( $page > (int) ( $record['raw_page_count'] ?? 0 ) ) {
			$work['providers'][ $provider_id ] = $record;
			$this->advance_provider( $work, $context['provider_index'] );
			return true;
		}
		if ( $this->global_page_ceiling_reached( $work, $provider_ids ) ) {
			return false;
		}

		$urls = $provider->get_urls( $page );
		if ( ! $this->fence_matches( $generation, $token ) ) {
			return false;
		}
		++$work['scanned_raw_pages'];
		++$pages_scanned;
		$this->record_page_result( $work, $record, $provider_id, $provider, $page, $urls );
		$work['providers'][ $provider_id ] = $record;
		$work['next_page']                 = $page + 1;
		if ( ! $this->fence_matches( $generation, $token ) ) {
			return false;
		}

		$this->write_building_state( $work, $generation, $token );
		return true;
	}

	/**
	 * @param array<string,mixed> $work Current work checkpoint.
	 * @return array<string,mixed>
	 */
	private function provider_record(
		array $work,
		string $provider_id,
		ProviderInterface $provider
	): array {
		return \is_array( $work['providers'][ $provider_id ] ?? null )
			? $work['providers'][ $provider_id ]
			: $this->new_provider_record( $provider_id, $provider );
	}

	/**
	 * @param array<string,mixed> $work         Current work checkpoint.
	 * @param string[]            $provider_ids Ordered provider IDs.
	 */
	private function global_page_ceiling_reached( array &$work, array $provider_ids ): bool {
		if ( (int) ( $work['scanned_raw_pages'] ?? 0 ) < self::GLOBAL_PAGE_CEILING ) {
			return false;
		}
		$work['ceiling_reached'] = true;
		$work['unknown_pages']   = $this->count_remaining_pages( $work, $provider_ids );
		return true;
	}

	/**
	 * @param array<string,mixed> $work         Current work checkpoint.
	 * @param string[]            $provider_ids Ordered provider IDs.
	 * @return array{provider_index:int,provider_id:string,provider:ProviderInterface}|null
	 */
	private function current_provider_context( array &$work, array $provider_ids ): ?array {
		$provider_index = (int) ( $work['provider_index'] ?? 0 );
		$provider_id    = (string) ( $provider_ids[ $provider_index ] ?? '' );
		$provider       = $this->orchestrator->get_provider( $provider_id );
		if ( '' === $provider_id || ! $provider ) {
			$this->advance_provider( $work, $provider_index );
			return null;
		}

		return array(
			'provider_index' => $provider_index,
			'provider_id'    => $provider_id,
			'provider'       => $provider,
		);
	}

	/**
	 * @param array<string,mixed> $work Current work checkpoint.
	 */
	private function advance_provider( array &$work, int $provider_index ): void {
		$work['provider_index'] = $provider_index + 1;
		$work['next_page']      = 1;
	}

	/**
	 * @param array<string,mixed>            $work   Current work checkpoint.
	 * @param array<string,mixed>            $record Provider occupancy record.
	 * @param array<int,array<string,mixed>> $urls   Raw provider URLs.
	 */
	private function record_page_result(
		array &$work,
		array &$record,
		string $provider_id,
		ProviderInterface $provider,
		int $page,
		array $urls
	): void {
		if ( ! empty( $urls ) ) {
			$record['non_empty_pages'][]     = $page;
			$record['page_lastmod'][ $page ] = $this->page_lastmod_from_urls( $urls, $provider->get_lastmod() );
			++$work['non_empty_pages'];
		} else {
			++$work['empty_pages'];
		}

		if (
			ProviderIdentity::NEWS === $provider_id
			&& empty( $record['non_empty_pages'] )
			&& $page >= (int) ( $record['raw_page_count'] ?? 0 )
		) {
			$record['non_empty_pages'] = array( 1 );
			if ( ! isset( $record['page_lastmod'][1] ) ) {
				$record['page_lastmod'][1] = $provider->get_lastmod();
			}
		}

		$record['non_empty_pages'] = \array_values(
			\array_unique( \array_map( 'intval', (array) $record['non_empty_pages'] ) )
		);
		\sort( $record['non_empty_pages'], SORT_NUMERIC );
	}

	/**
	 * @param array<string,mixed> $work Current work checkpoint.
	 */
	private function write_building_state( array $work, int $generation, string $token ): void {
		$this->write_state(
			array_merge(
				$this->read_state(),
				array(
					'status'            => 'building',
					'generation'        => $generation,
					'token'             => $token,
					'scanned_raw_pages' => (int) $work['scanned_raw_pages'],
					'non_empty_pages'   => (int) $work['non_empty_pages'],
					'empty_pages'       => (int) $work['empty_pages'],
					'unknown_pages'     => 0,
					'updated_at'        => \gmdate( 'c' ),
				)
			)
		);
	}

	/**
	 * Count the raw pages left uninspected after the global safety ceiling.
	 *
	 * @param array<string,mixed> $work
	 * @param string[]            $provider_ids
	 */
	private function count_remaining_pages( array &$work, array $provider_ids ): int {
		$remaining      = 0;
		$provider_index = max( 0, (int) ( $work['provider_index'] ?? 0 ) );
		$provider_count = \count( $provider_ids );
		for ( $index = $provider_index; $index < $provider_count; ++$index ) {
			$provider_id = (string) ( $provider_ids[ $index ] ?? '' );
			$provider    = $this->orchestrator->get_provider( $provider_id );
			if ( '' === $provider_id || ! $provider ) {
				continue;
			}

			$record                            = \is_array( $work['providers'][ $provider_id ] ?? null )
				? $work['providers'][ $provider_id ]
				: $this->new_provider_record( $provider_id, $provider );
			$work['providers'][ $provider_id ] = $record;
			$first_page                        = $index === $provider_index
				? max( 1, (int) ( $work['next_page'] ?? 1 ) )
				: 1;
			$remaining                        += max( 0, (int) ( $record['raw_page_count'] ?? 0 ) - $first_page + 1 );
		}

		return $remaining;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function new_provider_record( string $provider_id, ProviderInterface $provider ): array {
		$count = $provider->get_count();
		if ( $count < 1 && ProviderIdentity::NEWS !== $provider_id ) {
			return array(
				'raw_count'       => 0,
				'raw_page_count'  => 0,
				'non_empty_pages' => array(),
				'page_lastmod'    => array(),
			);
		}

		$raw_page_count = ProviderIdentity::is_single_page( $provider_id )
			? 1
			: (int) \ceil( $count / $this->orchestrator->get_per_page() );

		return array(
			'raw_count'       => $count,
			'raw_page_count'  => max( 1, $raw_page_count ),
			'non_empty_pages' => array(),
			'page_lastmod'    => array(),
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $urls
	 */
	private function page_lastmod_from_urls( array $urls, string $fallback ): string {
		$latest = 0;
		foreach ( $urls as $url ) {
			if ( ! \is_array( $url ) ) {
				continue;
			}
			$lastmod = \is_scalar( $url['lastmod'] ?? null ) ? (string) $url['lastmod'] : '';
			if ( '' === $lastmod ) {
				continue;
			}
			$timestamp = \strtotime( $lastmod );
			if ( false !== $timestamp ) {
				$latest = max( $latest, $timestamp );
			}
		}

		return $latest > 0 ? \gmdate( 'c', $latest ) : $fallback;
	}

	/**
	 * @param array<string, mixed> $work
	 */
	private function publish_manifest( array $work, int $generation, string $token ): bool {
		if ( ! $this->fence_matches( $generation, $token ) ) {
			return false;
		}

		$manifest = array(
			'generation' => $generation,
			'token'      => $token,
			'complete'   => true,
			'providers'  => \is_array( $work['providers'] ?? null ) ? $work['providers'] : array(),
		);
		\update_option( PageOccupancyManifest::MANIFEST_OPTION, $manifest, false );
		$stored = PageOccupancyManifest::load();
		if ( $stored !== $manifest || ! $this->fence_matches( $generation, $token ) ) {
			return false;
		}

		$this->write_state(
			array(
				'status'            => 'ready',
				'generation'        => $generation,
				'token'             => $token,
				'scanned_raw_pages' => (int) ( $work['scanned_raw_pages'] ?? 0 ),
				'non_empty_pages'   => (int) ( $work['non_empty_pages'] ?? 0 ),
				'empty_pages'       => (int) ( $work['empty_pages'] ?? 0 ),
				'unknown_pages'     => 0,
				'last_error'        => '',
				'updated_at'        => \gmdate( 'c' ),
			)
		);
		return $this->fence_matches( $generation, $token );
	}

	private function record_failure( int $generation, string $token, string $message ): void {
		$this->write_state(
			array(
				'status'            => 'failed',
				'generation'        => $generation,
				'token'             => $token,
				'scanned_raw_pages' => (int) ( $this->read_state()['scanned_raw_pages'] ?? 0 ),
				'non_empty_pages'   => (int) ( $this->read_state()['non_empty_pages'] ?? 0 ),
				'empty_pages'       => (int) ( $this->read_state()['empty_pages'] ?? 0 ),
				'unknown_pages'     => 0,
				'last_error'        => \sanitize_text_field( $message ),
				'updated_at'        => \gmdate( 'c' ),
			)
		);
		$this->schedule_slice( self::FAILURE_RETRY_DELAY );
	}

	/**
	 * Persist a terminal, truthful result when exact occupancy exceeds the
	 * bounded background scanner's documented safety ceiling.
	 *
	 * @param array<string,mixed> $work
	 */
	private function record_limit( array $work, int $generation, string $token ): void {
		$this->write_state(
			array(
				'status'            => 'limited',
				'generation'        => $generation,
				'token'             => $token,
				'scanned_raw_pages' => (int) ( $work['scanned_raw_pages'] ?? 0 ),
				'non_empty_pages'   => (int) ( $work['non_empty_pages'] ?? 0 ),
				'empty_pages'       => (int) ( $work['empty_pages'] ?? 0 ),
				'unknown_pages'     => max( 1, (int) ( $work['unknown_pages'] ?? 0 ) ),
				'last_error'        => __( 'Exact sitemap occupancy exceeded the 50,000-page scan ceiling. Raw provider page counts remain in use until the inventory changes.', 'cybermaps' ),
				'updated_at'        => \gmdate( 'c' ),
			)
		);
	}

	private function schedule_slice( int $delay ): bool {
		if ( \wp_next_scheduled( self::HOOK ) ) {
			return true;
		}

		$result = \wp_schedule_single_event( \time() + max( 0, $delay ), self::HOOK, array(), true );
		if ( false === $result || \is_wp_error( $result ) ) {
			$this->record_schedule_failure( $result );
			return false;
		}

		return true;
	}

	/**
	 * Persist a scheduling failure without recursively attempting another event.
	 *
	 * @param mixed $result Scheduler return value.
	 */
	private function record_schedule_failure( $result ): void {
		$state   = $this->read_state();
		$fence   = $this->current_fence();
		$message = $this->schedule_failure_message( $result );

		$this->write_state(
			array(
				'status'            => 'failed',
				'generation'        => (int) ( $state['generation'] ?? $fence['generation'] ),
				'token'             => \is_scalar( $state['token'] ?? null ) ? (string) $state['token'] : $fence['token'],
				'scanned_raw_pages' => (int) ( $state['scanned_raw_pages'] ?? 0 ),
				'non_empty_pages'   => (int) ( $state['non_empty_pages'] ?? 0 ),
				'empty_pages'       => (int) ( $state['empty_pages'] ?? 0 ),
				'unknown_pages'     => (int) ( $state['unknown_pages'] ?? 0 ),
				'last_error'        => \substr( \sanitize_text_field( $message ), 0, 255 ),
				'updated_at'        => \gmdate( 'c' ),
			)
		);
	}

	/**
	 * @param mixed $result Scheduler return value.
	 */
	private function schedule_failure_message( $result ): string {
		$message = false === $result
			? __( 'The sitemap occupancy background task could not be scheduled.', 'cybermaps' )
			: __( 'The sitemap occupancy background task returned an invalid scheduling error.', 'cybermaps' );
		if ( \is_wp_error( $result ) && \method_exists( $result, 'get_error_message' ) ) {
			$error_message = $result->get_error_message();
			if ( \is_scalar( $error_message ) && '' !== trim( (string) $error_message ) ) {
				$message = (string) $error_message;
			}
		}

		return $message;
	}

	private function fence_matches( int $generation, string $token ): bool {
		return '' !== $token
			&& $this->lock->maintain()
			&& PageOccupancyManifest::current_generation() === $generation
			&& \hash_equals( PageOccupancyManifest::current_token(), $token );
	}

	/**
	 * @return array{generation:int,token:string}
	 */
	private function current_fence( bool $initialize_token = false ): array {
		$token = PageOccupancyManifest::current_token();
		if ( $initialize_token && '' === $token ) {
			$candidate = \wp_generate_password( 32, false, false );
			\add_option( PageOccupancyManifest::TOKEN_OPTION, $candidate, '', false );
			$token = PageOccupancyManifest::current_token();
			if ( '' === $token ) {
				\update_option( PageOccupancyManifest::TOKEN_OPTION, $candidate, false );
				$token = PageOccupancyManifest::current_token();
			}
		}

		return array(
			'generation' => PageOccupancyManifest::current_generation(),
			'token'      => $token,
		);
	}

	private function record_stale_and_retry(): void {
		$fence = $this->current_fence( true );
		$this->write_state(
			array(
				'status'            => 'stale',
				'generation'        => $fence['generation'],
				'token'             => $fence['token'],
				'scanned_raw_pages' => 0,
				'non_empty_pages'   => 0,
				'empty_pages'       => 0,
				'unknown_pages'     => 0,
				'last_error'        => '',
				'updated_at'        => \gmdate( 'c' ),
			)
		);
		$this->schedule_slice( 0 );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function read_work(): ?array {
		$work = \get_option( self::WORK_OPTION, null );
		return \is_array( $work ) ? $work : null;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function read_state(): array {
		$state = \get_option( self::STATE_OPTION, array() );
		return \is_array( $state ) ? $state : array();
	}

	/**
	 * @param array<string, mixed> $state
	 */
	private function write_state( array $state ): void {
		\update_option( self::STATE_OPTION, $state, false );
	}

	private function acquire_lock(): bool {
		return $this->lock->acquire();
	}

	private function release_lock(): void {
		$this->lock->release();
	}
}
