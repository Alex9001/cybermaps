<?php
declare(strict_types=1);

namespace Cybermaps\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Public discovery publication health checks.
 *
 * Filesystem state describes what Cybermaps intends to publish. Only an HTTP
 * response can establish whether a public URL is available, correctly typed,
 * parseable, and actually being delivered by PHP or the web server.
 */
class DiscoveryStatus {

	private const CACHE_VERSION                      = 8;
	private const CACHE_TTL                          = 300;
	private const UNVERIFIED_CACHE_TTL               = 60;
	private const MAX_RESPONSE_BYTES                 = 4 * 1024 * 1024;
	private const REQUEST_TIMEOUT                    = 2;
	private const MAX_CONSECUTIVE_TRANSPORT_FAILURES = 2;
	private const API_CATALOG_PROFILE                = 'https://www.rfc-editor.org/info/rfc9727';
	private const MAX_PROTOCOL_PROBES                = 3;

	private int $protocol_probes = 0;

	/**
	 * Get status data for reports and admin pages.
	 *
	 * Successful checks are cached for five minutes. An unreachable loopback or
	 * otherwise unverified result is retried after one minute without being
	 * misreported as an endpoint failure.
	 *
	 * @param bool $force_refresh Bypass the transient cache.
	 * @return array<string, mixed>
	 */
	public function get_status_data( bool $force_refresh = false ): array {
		$settings = \Cybermaps\Core\ConfigurationStore::settings();

		$hub_enabled = ! empty( $settings['enable_discovery_hub'] );
		$static_mode = \Cybermaps\Discovery\StaticBridge::get_mode( $settings );
		$cache_key   = $this->get_cache_key( $settings, $static_mode );

		if ( $hub_enabled && ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if (
				is_array( $cached )
				&& self::CACHE_VERSION === (int) ( $cached['cache_version'] ?? 0 )
			) {
				return $cached;
			}
		}

		$health = $this->calculate_health( $settings, $hub_enabled, $static_mode );

		if ( $hub_enabled ) {
			$ttl = ! empty( $health['unverified_count'] )
				? self::UNVERIFIED_CACHE_TTL
				: self::CACHE_TTL;
			\Cybermaps\Core\CacheManager::set( $cache_key, $health, $ttl, 'discovery' );
		}

		return $health;
	}

	/**
	 * Calculate public and on-disk health for every registered path publication.
	 *
	 * @param array<string, mixed> $settings Settings option.
	 * @param bool                 $hub_enabled Whether the AI Publication Hub is enabled.
	 * @param string               $static_mode Resolved Static File Engine mode.
	 * @return array<string, mixed>
	 */
	private function calculate_health( array $settings, bool $hub_enabled, string $static_mode ): array {
		$registry         = \Cybermaps\Core\EndpointRegistry::get_instance();
		$publications     = $registry->get_path_publications();
		$all_targets      = $this->index_static_targets( $registry->get_static_targets( 'all', $settings, true ) );
		$active_targets   = $this->index_static_targets( $registry->get_static_targets( $static_mode, $settings ) );
		$filesystem       = $this->get_filesystem();
		$bridge           = \Cybermaps\Discovery\StaticBridge::get_instance();
		$header_manifest  = ( new \Cybermaps\Discovery\StaticHeaderManifest() )->get_manifest( $settings );
		$results          = $this->calculate_publication_results(
			$publications,
			$registry,
			$settings,
			$all_targets,
			$active_targets,
			$filesystem,
			$bridge,
			$header_manifest,
			$hub_enabled
		);
		$status_counts    = $this->count_publication_statuses( $results );
		$healthy_count    = $status_counts['healthy'];
		$error_count      = $status_counts['error'];
		$unverified_count = $status_counts['unverified'];

		$last_report    = get_option( 'cybermaps_last_static_sync_report', array() );
		$last_report    = is_array( $last_report ) ? $last_report : array();
		$schedule_error = get_option( 'cybermaps_static_schedule_error', array() );
		$schedule_error = is_array( $schedule_error ) ? $schedule_error : array();
		$sync_status    = $this->get_sync_status(
			$hub_enabled,
			$static_mode,
			$last_report,
			$schedule_error,
			$results
		);

		$overall_status       = $this->overall_status( $hub_enabled, $error_count, $unverified_count );
		$markdown_negotiation = ( new MarkdownNegotiationStatus() )->get_status( $settings );

		return array(
			'cache_version'        => self::CACHE_VERSION,
			'healthy'              => $hub_enabled && 0 === $error_count && 0 === $unverified_count,
			'overall_status'       => $overall_status,
			'hub_enabled'          => $hub_enabled,
			'static_mode'          => $static_mode,
			'active_count'         => $healthy_count,
			'error_count'          => $error_count,
			'unverified_count'     => $unverified_count,
			'endpoints'            => $results,
			'sync_status'          => $hub_enabled ? $sync_status : 'disabled',
			'sync_report'          => $last_report,
			'schedule_error'       => $schedule_error,
			'last_sync'            => get_option( 'cybermaps_last_static_sync', 'Never' ),
			'last_attempt'         => get_option( 'cybermaps_last_static_sync_attempt', 'Never' ),
			'checked_at'           => gmdate( 'c' ),
			'header_manifest'      => $header_manifest,
			'edge_invalidation'    => ( new \Cybermaps\Integration\EdgeCache\Coordinator() )->get_status(),
			'markdown_negotiation' => $markdown_negotiation,
			'well_known_routing'   => \Cybermaps\Discovery\WellKnownRoutingBridge::get_status(),
			'notices'              => $this->get_environment_notices( $settings, $unverified_count ),
		);
	}

	/**
	 * Build and probe each unique registered publication path.
	 *
	 * @param array<string, array<string, mixed>> $publications Registered publications.
	 * @param array<string, mixed>                $settings Plugin settings.
	 * @param array<string, array<string, mixed>> $all_targets All static targets.
	 * @param array<string, array<string, mixed>> $active_targets Active static targets.
	 * @param mixed                               $filesystem WordPress filesystem object.
	 * @param array<string, mixed>                $header_manifest Static header manifest.
	 * @return array<int, array<string, mixed>>
	 */
	private function calculate_publication_results(
		array $publications,
		\Cybermaps\Core\EndpointRegistry $registry,
		array $settings,
		array $all_targets,
		array $active_targets,
		mixed $filesystem,
		\Cybermaps\Discovery\StaticBridge $bridge,
		array $header_manifest,
		bool $hub_enabled
	): array {
		$results                        = array();
		$seen_paths                     = array();
		$consecutive_transport_failures = 0;
		$transport_circuit_open         = false;

		foreach ( $publications as $endpoint_id => $definition ) {
			$canonical_path = (string) ( $definition['path'] ?? '' );
			$paths          = array_merge( array( $canonical_path ), (array) ( $definition['aliases'] ?? array() ) );
			foreach ( $paths as $path ) {
				if ( $this->skip_publication_path( $path, $seen_paths ) ) {
					continue;
				}
				$seen_paths[ $path ] = true;
				$publication         = $this->build_publication(
					(string) $endpoint_id,
					$definition,
					$path,
					$canonical_path,
					$registry->is_enabled( (string) $endpoint_id, $settings ),
					$all_targets[ $path ] ?? null,
					$active_targets[ $path ] ?? null,
					$filesystem,
					$bridge,
					$header_manifest
				);
				$results[]           = $this->evaluate_publication(
					$publication,
					$hub_enabled,
					$consecutive_transport_failures,
					$transport_circuit_open
				);
			}
		}

		return $results;
	}

	/**
	 * @param array<string, bool> $seen_paths Previously handled paths.
	 */
	private function skip_publication_path( mixed $path, array $seen_paths ): bool {
		return ! is_string( $path ) || '' === $path || isset( $seen_paths[ $path ] );
	}

	/**
	 * @param array<string, mixed> $definition Publication definition.
	 * @param mixed                $target Static target metadata.
	 * @param mixed                $active_target Active target metadata.
	 * @param mixed                $filesystem WordPress filesystem object.
	 * @param array<string, mixed> $header_manifest Static header manifest.
	 * @return array<string, mixed>
	 */
	private function build_publication(
		string $endpoint_id,
		array $definition,
		string $path,
		string $canonical_path,
		bool $endpoint_enabled,
		mixed $target,
		mixed $active_target,
		mixed $filesystem,
		\Cybermaps\Discovery\StaticBridge $bridge,
		array $header_manifest
	): array {
		$is_canonical     = $canonical_path === $path;
		$label            = (string) ( $definition['label'] ?? $endpoint_id );
		$alternate_suffix = '';
		if ( ! $is_canonical ) {
			$label           .= ' ' . __( '(alternate)', 'cybermaps' );
			$alternate_suffix = '.alternate.' . substr( md5( $path ), 0, 8 );
		}
		$static_filename = $this->static_target_value( $target, 'filename' );

		return array_merge(
			array(
				'id'          => $endpoint_id . $alternate_suffix,
				'endpoint_id' => $endpoint_id,
				'label'       => $label,
				'path'        => $path,
				'url'         => \Cybermaps\Core\URLManager::get_home_url( $path ),
			),
			$this->publication_definition_metadata( $definition ),
			array(
				'enabled'           => $endpoint_enabled,
				'canonical'         => $is_canonical,
				'static_file'       => $static_filename,
				'static_bucket'     => $this->static_target_value( $target, 'bucket' ),
				'intended_delivery' => $this->intended_delivery( $endpoint_enabled, $active_target ),
				'on_disk'           => $this->static_file_exists( $static_filename, $filesystem, $bridge ),
				'header_policy'     => $this->header_policy( $active_target, $header_manifest, $path ),
			)
		);
	}

	/**
	 * @param array<string, mixed> $definition Publication definition.
	 * @return array<string, string>
	 */
	private function publication_definition_metadata( array $definition ): array {
		return array(
			'description'    => (string) ( $definition['description'] ?? '' ),
			'type'           => (string) ( $definition['type'] ?? 'application/octet-stream' ),
			'format'         => (string) ( $definition['format'] ?? 'text' ),
			'spec'           => (string) ( $definition['spec'] ?? '' ),
			'delivery_class' => (string) ( $definition['delivery'] ?? '' ),
			'maturity'       => (string) ( $definition['maturity'] ?? '' ),
			'adoption'       => (string) ( $definition['adoption'] ?? '' ),
			'group'          => (string) ( $definition['group'] ?? 'essential' ),
		);
	}

	private function static_target_value( mixed $target, string $key ): string {
		return is_array( $target ) ? (string) ( $target[ $key ] ?? '' ) : '';
	}

	private function intended_delivery( bool $enabled, mixed $active_target ): string {
		if ( ! $enabled ) {
			return 'disabled';
		}
		return is_array( $active_target ) ? 'static' : 'dynamic';
	}

	private function static_file_exists(
		string $filename,
		mixed $filesystem,
		\Cybermaps\Discovery\StaticBridge $bridge
	): ?bool {
		if ( '' === $filename || ! is_object( $filesystem ) ) {
			return null;
		}
		$resolved_path = $bridge->get_file_path( $filename );
		return '' === $resolved_path ? null : (bool) $filesystem->exists( $resolved_path );
	}

	/**
	 * @param array<string, mixed> $manifest Static header manifest.
	 * @return array<string, mixed>
	 */
	private function header_policy( mixed $active_target, array $manifest, string $path ): array {
		return is_array( $active_target ) ? (array) ( $manifest['policies'][ $path ] ?? array() ) : array();
	}

	/**
	 * @param array<string, mixed> $publication Publication metadata.
	 * @return array<string, mixed>
	 */
	private function evaluate_publication(
		array $publication,
		bool $hub_enabled,
		int &$consecutive_transport_failures,
		bool &$transport_circuit_open
	): array {
		if ( ! $hub_enabled || ! $publication['enabled'] ) {
			return array_merge( $publication, $this->disabled_probe( $hub_enabled ) );
		}
		if ( $transport_circuit_open ) {
			return array_merge( $publication, $this->skipped_transport_probe() );
		}

		$probe = $this->probe_endpoint( $publication );
		$this->update_transport_circuit( $probe, $consecutive_transport_failures, $transport_circuit_open );
		return array_merge( $publication, $probe );
	}

	/** @return array<string, mixed> */
	private function disabled_probe( bool $hub_enabled ): array {
		$message = $hub_enabled
			? __( 'This optional publication is disabled.', 'cybermaps' )
			: __( 'AI Publication Hub is disabled.', 'cybermaps' );
		return array(
			'status'             => 'disabled',
			'code'               => 0,
			'content_type'       => '',
			'content_type_valid' => null,
			'body_valid'         => null,
			'header_status'      => 'disabled',
			'header_message'     => '',
			'delivery'           => 'disabled',
			'message'            => $message,
		);
	}

	/** @return array<string, mixed> */
	private function skipped_transport_probe(): array {
		return array(
			'status'             => 'unverified',
			'code'               => 0,
			'content_type'       => '',
			'content_type_valid' => null,
			'body_valid'         => null,
			'header_status'      => 'unverified',
			'header_message'     => __( 'Header conformance could not be checked because the public request was skipped.', 'cybermaps' ),
			'delivery'           => 'unverified',
			'message'            => __( 'This public check was skipped after repeated same-origin loopback failures. Delivery remains unverified.', 'cybermaps' ),
		);
	}

	/** @param array<string, mixed> $probe Probe result. */
	private function update_transport_circuit( array $probe, int &$failures, bool &$open ): void {
		$is_failure = 'unverified' === (string) ( $probe['status'] ?? '' )
			&& 0 === (int) ( $probe['code'] ?? 0 );
		if ( ! $is_failure ) {
			$failures = 0;
			return;
		}
		++$failures;
		$open = $failures >= self::MAX_CONSECUTIVE_TRANSPORT_FAILURES;
	}

	/**
	 * @param array<int, array<string, mixed>> $results Publication results.
	 * @return array{healthy:int,error:int,unverified:int}
	 */
	private function count_publication_statuses( array $results ): array {
		$counts = array(
			'healthy'    => 0,
			'error'      => 0,
			'unverified' => 0,
		);
		foreach ( $results as $result ) {
			$status = (string) ( $result['status'] ?? '' );
			if ( isset( $counts[ $status ] ) ) {
				++$counts[ $status ];
			}
		}
		return $counts;
	}

	private function overall_status( bool $hub_enabled, int $errors, int $unverified ): string {
		if ( ! $hub_enabled ) {
			return 'disabled';
		}
		if ( $errors > 0 ) {
			return 'error';
		}
		return $unverified > 0 ? 'unverified' : 'healthy';
	}

	/**
	 * Resolve publication reconciliation state from the structured sync report.
	 *
	 * @param array<string, mixed>              $report Last bounded sync report.
	 * @param array<string, mixed>              $schedule_error WP-Cron scheduling failure.
	 * @param array<int, array<string, mixed>>  $results Publication status rows.
	 */
	private function get_sync_status(
		bool $hub_enabled,
		string $static_mode,
		array $report,
		array $schedule_error,
		array $results
	): string {
		if ( ! $hub_enabled ) {
			return 'disabled';
		}
		if ( 'off' === $static_mode ) {
			return 'dynamic';
		}
		if ( ! empty( $schedule_error ) ) {
			return 'schedule_error';
		}

		if ( $this->has_missing_static_file( $results ) ) {
			return 'missing';
		}

		if ( empty( $report ) ) {
			return 'pending';
		}
		if ( $this->sync_report_is_stale( $report, $static_mode ) ) {
			return 'stale';
		}

		$status = (string) ( $report['status'] ?? '' );
		if ( in_array( $status, array( 'complete', 'partial', 'failed', 'busy', 'skipped' ), true ) ) {
			return $status;
		}

		return 'pending';
	}

	/** @param array<int, array<string, mixed>> $results Publication results. */
	private function has_missing_static_file( array $results ): bool {
		foreach ( $results as $result ) {
			if ( 'static' === ( $result['intended_delivery'] ?? '' ) && false === ( $result['on_disk'] ?? null ) ) {
				return true;
			}
		}
		return false;
	}

	/** @param array<string, mixed> $report Last bounded sync report. */
	private function sync_report_is_stale( array $report, string $static_mode ): bool {
		if ( ! array_key_exists( 'generation', $report ) ) {
			return true;
		}
		$current_generation = max( 0, (int) get_option( 'cybermaps_static_generation', 0 ) );
		if ( max( 0, (int) $report['generation'] ) !== $current_generation ) {
			return true;
		}
		return (string) ( $report['mode'] ?? '' ) !== $static_mode;
	}

	/**
	 * GET a public endpoint and validate status, media type, and body.
	 *
	 * @param array<string, mixed> $publication Publication metadata.
	 * @return array<string, mixed>
	 */
	private function probe_endpoint( array $publication ): array {
		$url           = (string) $publication['url'];
		$expected_type = (string) $publication['type'];
		$accept        = $this->probe_accept_header( $publication, $expected_type );

		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => $this->response_timeout( (string) $publication['endpoint_id'] ),
				'redirection'         => 3,
				'limit_response_size' => $this->response_size_limit( (string) $publication['endpoint_id'] ),
				'headers'             => array(
					'Accept'                 => $accept,
					'X-Cybermaps-Diagnostic' => '1',
				),
				'user-agent'          => 'Cybermaps/' . CYBERMAPS_VERSION . ' public-endpoint-health',
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->with_header_conformance(
				array(
					'status'             => 'unverified',
					'code'               => 0,
					'content_type'       => '',
					'content_type_valid' => null,
					'body_valid'         => null,
					'delivery'           => 'unverified',
					'message'            => __( 'The server could not complete its own public HTTP check. Delivery is unverified, not proven broken.', 'cybermaps' ),
				),
				$publication,
				$response,
				''
			);
		}

		$code         = (int) wp_remote_retrieve_response_code( $response );
		$content_type = trim( $this->get_response_header( $response, 'content-type' ) );
		$body         = (string) wp_remote_retrieve_body( $response );
		$marker       = trim( $this->get_response_header( $response, 'x-cybermaps-version' ) );
		$delivery     = $this->detect_delivery( $publication, $body, $marker );

		if ( $code < 200 || $code >= 300 ) {
			return $this->with_header_conformance(
				array(
					'status'             => 'error',
					'code'               => $code,
					'content_type'       => $content_type,
					'content_type_valid' => null,
					'body_valid'         => null,
					'delivery'           => $delivery,
					'message'            => $this->response_error_message( $code, (string) $publication['endpoint_id'] ),
				),
				$publication,
				$response,
				$body
			);
		}

		$type_valid      = $this->content_type_matches( $expected_type, $content_type );
		$body_validation = $this->validate_body(
			$body,
			(string) $publication['format'],
			(string) $publication['endpoint_id']
		);
		if ( ! $type_valid ) {
			return $this->with_header_conformance(
				array(
					'status'             => 'error',
					'code'               => $code,
					'content_type'       => $content_type,
					'content_type_valid' => false,
					'body_valid'         => $body_validation['valid'],
					'delivery'           => $delivery,
					'message'            => sprintf(
					/* translators: 1: expected media type, 2: observed media type. */
						__( 'Expected %1$s but the server returned %2$s.', 'cybermaps' ),
						$expected_type,
						'' !== $content_type ? $content_type : __( 'no Content-Type', 'cybermaps' )
					),
				),
				$publication,
				$response,
				$body
			);
		}

		if ( $this->api_catalog_profile_missing( $publication, $content_type ) ) {
			return $this->with_header_conformance(
				array(
					'status'             => 'error',
					'code'               => $code,
					'content_type'       => $content_type,
					'content_type_valid' => false,
					'body_valid'         => $body_validation['valid'],
					'delivery'           => $delivery,
					'message'            => __( 'The API catalog Content-Type is missing the RFC 9727 profile parameter.', 'cybermaps' ),
				),
				$publication,
				$response,
				$body
			);
		}

		if ( null === $body_validation['valid'] ) {
			return $this->with_header_conformance(
				array(
					'status'             => 'unverified',
					'code'               => $code,
					'content_type'       => $content_type,
					'content_type_valid' => true,
					'body_valid'         => null,
					'delivery'           => $delivery,
					'message'            => $body_validation['message'],
				),
				$publication,
				$response,
				$body
			);
		}
		if ( ! $body_validation['valid'] ) {
			return $this->with_header_conformance(
				array(
					'status'             => 'error',
					'code'               => $code,
					'content_type'       => $content_type,
					'content_type_valid' => true,
					'body_valid'         => false,
					'delivery'           => $delivery,
					'message'            => $body_validation['message'],
				),
				$publication,
				$response,
				$body
			);
		}

		if ( '' !== $marker && CYBERMAPS_VERSION !== $marker ) {
			return $this->with_header_conformance(
				array(
					'status'             => 'error',
					'code'               => $code,
					'content_type'       => $content_type,
					'content_type_valid' => true,
					'body_valid'         => true,
					'delivery'           => 'dynamic',
					'message'            => __( 'The dynamic response reports a different Cybermaps version, which usually indicates a stale proxy cache.', 'cybermaps' ),
				),
				$publication,
				$response,
				$body
			);
		}

		$head_error = $this->api_catalog_head_error( $publication, $url, $accept, $code, $content_type, $delivery );
		if ( null !== $head_error ) {
			return $this->with_status_axes( $head_error );
		}

		return $this->with_header_conformance(
			array(
				'status'             => 'healthy',
				'code'               => $code,
				'content_type'       => $content_type,
				'content_type_valid' => true,
				'body_valid'         => true,
				'delivery'           => $delivery,
				'message'            => __( 'Public response, media type, and body validated.', 'cybermaps' ),
			),
			$publication,
			$response,
			$body
		);
	}

	/** @param array<string, mixed> $publication Publication metadata. */
	private function probe_accept_header( array $publication, string $expected_type ): string {
		return 'api_catalog' === $publication['endpoint_id']
			? $expected_type . '; profile="' . self::API_CATALOG_PROFILE . '"'
			: $expected_type;
	}

	/** @param array<string, mixed> $publication Publication metadata. */
	private function api_catalog_profile_missing( array $publication, string $content_type ): bool {
		return 'api_catalog' === $publication['endpoint_id']
			&& ! $this->has_api_catalog_profile( $content_type );
	}

	/**
	 * @param array<string, mixed> $publication Publication metadata.
	 * @return array<string, mixed>|null
	 */
	private function api_catalog_head_error(
		array $publication,
		string $url,
		string $accept,
		int $code,
		string $content_type,
		string $delivery
	): ?array {
		if ( 'api_catalog' !== $publication['endpoint_id'] ) {
			return null;
		}
		$head_check = $this->probe_api_catalog_head( $url, $accept );
		if ( 'healthy' === $head_check['status'] ) {
			return null;
		}
		return array(
			'status'             => $head_check['status'],
			'code'               => $code,
			'content_type'       => $content_type,
			'content_type_valid' => true,
			'body_valid'         => true,
			'header_status'      => $head_check['status'],
			'header_message'     => $head_check['message'],
			'delivery'           => $delivery,
			'message'            => $head_check['message'],
		);
	}

	/**
	 * Keep response-body validity independent from static-server headers.
	 *
	 * @param array<string, mixed> $result Existing body/delivery result.
	 * @param array<string, mixed> $publication Publication metadata.
	 * @return array<string, mixed>
	 */
	private function with_header_conformance( array $result, array $publication, mixed $response, string $body ): array {
		$edge_diagnostics = $this->edge_diagnostics( $publication, $response );
		$policy           = $publication['header_policy'] ?? array();
		if ( ! is_array( $policy ) || array() === $policy ) {
			$result = array_merge(
				$result,
				array(
					'header_status'    => 'not_applicable',
					'header_message'   => __( 'This path is dynamically delivered; static-server headers are not required.', 'cybermaps' ),
					'edge_diagnostics' => $edge_diagnostics,
				)
			);
			return $this->with_status_axes( $result );
		}
		if ( is_wp_error( $response ) ) {
			$result = array_merge(
				$result,
				array(
					'header_status'    => 'unverified',
					'header_message'   => __( 'Static header conformance could not be checked because the public request was unavailable.', 'cybermaps' ),
					'edge_diagnostics' => $edge_diagnostics,
				)
			);
			return $this->with_status_axes( $result );
		}

		$headers = array();
		foreach ( array( 'content-type', 'cache-control', 'access-control-allow-origin', 'access-control-expose-headers', 'repr-digest', 'content-digest', 'content-usage', 'etag', 'last-modified', 'surrogate-key', 'cache-tag', 'x-litespeed-tag' ) as $name ) {
			$headers[ $name ] = $this->get_response_header( $response, $name );
		}
		$header_result = \Cybermaps\Discovery\StaticHeaderManifest::validate_headers( $policy, $headers, $body );

		$result = array_merge(
			$result,
			array(
				'header_status'          => $header_result['status'],
				'header_message'         => $header_result['message'],
				'header_missing'         => $header_result['missing'],
				'protocol_header_errors' => $header_result['protocol_missing'],
				'edge_diagnostics'       => $edge_diagnostics,
			)
		);
		return $this->with_status_axes( $result );
	}

	/** Keep reachability, body validity, and protocol conformance independent. */
	private function with_status_axes( array $result ): array {
		$availability = $this->availability_status( (int) ( $result['code'] ?? 0 ) );
		$body_status  = $this->truth_status( $result['body_valid'] ?? null );
		$conformance  = $this->conformance_status( $result, $availability, $body_status );

		return array_merge(
			$result,
			array(
				'availability_status' => $availability,
				'body_status'         => $body_status,
				'conformance_status'  => $conformance,
			)
		);
	}

	private function availability_status( int $code ): string {
		if ( 0 === $code ) {
			return 'unverified';
		}
		return $code >= 200 && $code < 300 ? 'pass' : 'error';
	}

	private function truth_status( mixed $value ): string {
		if ( true === $value ) {
			return 'pass';
		}
		return false === $value ? 'error' : 'unverified';
	}

	/** @param array<string,mixed> $result Probe result. */
	private function conformance_status( array $result, string $availability, string $body_status ): string {
		$headers = (string) ( $result['header_status'] ?? 'not_applicable' );
		if ( in_array( 'unverified', array( $availability, $body_status, $headers ), true ) ) {
			return 'unverified';
		}
		$valid_headers = in_array( $headers, array( 'healthy', 'not_applicable' ), true )
			|| $this->only_delivery_policy_mismatches( $result );
		return 'pass' === $availability && 'pass' === $body_status && true === ( $result['content_type_valid'] ?? null ) && $valid_headers
			? 'pass'
			: 'error';
	}

	/** Separate cache, CORS, and validator deployment policy from format validity. */
	private function only_delivery_policy_mismatches( array $result ): bool {
		if ( array_key_exists( 'protocol_header_errors', $result ) ) {
			return array() === $result['protocol_header_errors'];
		}
		$missing = (array) ( $result['header_missing'] ?? array() );
		return array() !== $missing && array() === array_diff(
			$missing,
			array( 'Cache-Control', 'Access-Control-Allow-Origin', 'Access-Control-Expose-Headers', 'ETag', 'Last-Modified' )
		);
	}

	/**
	 * Capture cache-chain evidence and perform a deliberately bounded request
	 * semantics sample. No header is trusted for application behavior; these
	 * fields are diagnostic evidence for an administrator only.
	 *
	 * @param array<string,mixed> $publication Publication metadata.
	 * @return array<string,mixed>
	 */
	private function edge_diagnostics( array $publication, mixed $response ): array {
		$headers     = $this->edge_response_headers( $response );
		$response_ok = ! is_wp_error( $response );
		$diagnostics = array(
			'get'          => $response_ok ? (int) wp_remote_retrieve_response_code( $response ) : 0,
			'headers'      => $headers,
			'head'         => 'not_run',
			'options'      => 'not_run',
			'not_modified' => 'not_run',
		);
		if ( ! $response_ok || $this->protocol_probes >= self::MAX_PROTOCOL_PROBES ) {
			return $diagnostics;
		}
		++$this->protocol_probes;

		$url                 = (string) ( $publication['url'] ?? '' );
		$accept              = (string) ( $publication['type'] ?? '*/*' );
		$head                = wp_safe_remote_head(
			$url,
			array(
				'timeout'     => self::REQUEST_TIMEOUT,
				'redirection' => 3,
				'headers'     => array(
					'Accept' => $accept,
				),
			)
		);
		$diagnostics['head'] = is_wp_error( $head ) ? 'unverified' : (int) wp_remote_retrieve_response_code( $head );

		$diagnostics['options'] = $this->probe_options_status( $url );

		$etag                        = $headers['etag'];
		$diagnostics['not_modified'] = $this->probe_not_modified_status( $url, $accept, $etag );

		return $diagnostics;
	}

	/** @return array<string, string> */
	private function edge_response_headers( mixed $response ): array {
		$headers = array();
		foreach ( array( 'content-encoding', 'vary', 'age', 'via', 'x-cache', 'x-cache-hits', 'cf-cache-status', 'x-litespeed-cache', 'surrogate-key', 'cache-tag', 'x-litespeed-tag', 'etag', 'repr-digest', 'content-digest' ) as $name ) {
			$headers[ $name ] = is_wp_error( $response ) ? '' : $this->get_response_header( $response, $name );
		}
		return $headers;
	}

	private function probe_options_status( string $url ): int|string {
		if ( ! function_exists( 'wp_remote_request' ) ) {
			return 'unverified';
		}
		$options = wp_remote_request(
			$url,
			array(
				'method'      => 'OPTIONS',
				'timeout'     => self::REQUEST_TIMEOUT,
				'redirection' => 3,
				'headers'     => array(
					'Origin'                        => home_url(),
					'Access-Control-Request-Method' => 'GET',
				),
			)
		);
		return is_wp_error( $options ) ? 'unverified' : (int) wp_remote_retrieve_response_code( $options );
	}

	private function probe_not_modified_status( string $url, string $accept, string $etag ): int|string {
		if ( '' === $etag ) {
			return 'not_run';
		}
		$conditional = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => self::REQUEST_TIMEOUT,
				'redirection' => 3,
				'headers'     => array(
					'If-None-Match' => $etag,
					'Accept'        => $accept,
				),
			)
		);
		return is_wp_error( $conditional ) ? 'unverified' : (int) wp_remote_retrieve_response_code( $conditional );
	}

	/**
	 * Verify the RFC 9727 Link relation on an API Catalog HEAD response.
	 *
	 * @return array{status: string, message: string}
	 */
	private function probe_api_catalog_head( string $url, string $accept ): array {
		$response = wp_safe_remote_head(
			$url,
			array(
				'timeout'     => self::REQUEST_TIMEOUT,
				'redirection' => 3,
				'headers'     => array(
					'Accept'                 => $accept,
					'X-Cybermaps-Diagnostic' => '1',
				),
				'user-agent'  => 'Cybermaps/' . CYBERMAPS_VERSION . ' api-catalog-health',
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'status'  => 'unverified',
				'message' => __( 'GET validated, but the server could not complete the RFC 9727 HEAD check.', 'cybermaps' ),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return array(
				'status'  => 'error',
				'message' => sprintf(
					/* translators: %d: HTTP response status code. */
					__( 'API Catalog HEAD returned HTTP %d.', 'cybermaps' ),
					$code
				),
			);
		}

		$link = $this->get_response_header( $response, 'link' );
		if ( ! $this->has_link_relation( $link, 'api-catalog' ) ) {
			return array(
				'status'  => 'error',
				'message' => __( 'API Catalog HEAD is missing a Link header with rel="api-catalog".', 'cybermaps' ),
			);
		}

		return array(
			'status'  => 'healthy',
			'message' => '',
		);
	}

	/**
	 * Normalize WordPress HTTP headers, including repeated Link fields.
	 *
	 * @param array<string, mixed>|\WP_Error $response HTTP API response.
	 */
	private function get_response_header( mixed $response, string $name ): string {
		$value = wp_remote_retrieve_header( $response, $name );
		if ( is_array( $value ) ) {
			$values = array();
			array_walk_recursive(
				$value,
				static function ( mixed $item ) use ( &$values ): void {
					if ( is_scalar( $item ) ) {
						$values[] = (string) $item;
					}
				}
			);
			return implode( ', ', $values );
		}

		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		return '';
	}

	/**
	 * Validate an observed media type, allowing equivalent XML types and any
	 * charset parameter.
	 */
	private function content_type_matches( string $expected, string $observed ): bool {
		$expected_base = strtolower( trim( explode( ';', $expected, 2 )[0] ) );
		$observed_base = strtolower( trim( explode( ';', $observed, 2 )[0] ) );

		if ( '' === $observed_base ) {
			return false;
		}
		if ( $expected_base === $observed_base ) {
			return true;
		}

		if ( 'application/xml' === $expected_base ) {
			return 'text/xml' === $observed_base || str_ends_with( $observed_base, '+xml' );
		}

		return false;
	}

	/**
	 * Validate a response body according to registry format metadata.
	 *
	 * @return array{valid: bool|null, message: string}
	 */
	private function validate_body( string $body, string $format, string $endpoint_id ): array {
		if ( '' === trim( $body ) ) {
			return array(
				'valid'   => false,
				'message' => __( 'The public response body is empty.', 'cybermaps' ),
			);
		}

		if ( strlen( $body ) >= $this->response_size_limit( $endpoint_id ) ) {
			return array(
				'valid'   => null,
				'message' => __( 'The response reached the safe probe-size limit, so complete parsing could not be verified.', 'cybermaps' ),
			);
		}

		if ( 'json' === $format ) {
			return $this->validate_json_body( $body, $endpoint_id );
		}

		if ( 'jsonl' === $format ) {
			return $this->validate_jsonl_body( $body );
		}

		if ( 'xml' === $format ) {
			return $this->validate_xml_body( $body );
		}

		return array(
			'valid'   => true,
			'message' => '',
		);
	}

	/** Keep full-corpus probes aligned with the bounded publisher. */
	private function response_timeout( string $endpoint_id ): int {
		return 'llms_full' === $endpoint_id ? 8 : self::REQUEST_TIMEOUT;
	}

	/** Explain full-corpus safety failures without implying exhausted disk storage. */
	private function response_error_message( int $code, string $endpoint_id ): string {
		if ( 507 === $code && 'llms_full' === $endpoint_id ) {
			return __( 'Public request returned HTTP 507. LLMS Full exceeded its output or memory safety budget; no partial corpus was published. This does not by itself indicate a full disk.', 'cybermaps' );
		}
		return sprintf(
			/* translators: %d: HTTP response status code. */
			__( 'Public request returned HTTP %d.', 'cybermaps' ),
			$code
		);
	}

	/** Keep full-corpus probes aligned with the bounded publisher. */
	private function response_size_limit( string $endpoint_id ): int {
		return 'llms_full' === $endpoint_id
			? \Cybermaps\Discovery\LLMS::FULL_OUTPUT_MAX_BYTES + 1
			: self::MAX_RESPONSE_BYTES;
	}

	/** @return array{valid:?bool,message:string} */
	private function validate_json_body( string $body, string $endpoint_id ): array {
		$decoded = json_decode( $body, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return array(
				'valid'   => false,
				'message' => __( 'The public response is not valid JSON.', 'cybermaps' ),
			);
		}
		if ( 'api_catalog' === $endpoint_id && ! $this->is_valid_linkset( $decoded ) ) {
			return array(
				'valid'   => false,
				'message' => __( 'The API catalog body is not a valid nonempty Linkset.', 'cybermaps' ),
			);
		}
		return array(
			'valid'   => true,
			'message' => '',
		);
	}

	/** @return array{valid:?bool,message:string} */
	private function validate_jsonl_body( string $body ): array {
		$lines = preg_split( '/\R/', trim( $body ) );
		$lines = is_array( $lines ) ? $lines : array();
		foreach ( $lines as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$record = json_decode( $line );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_object( $record ) ) {
				return array(
					'valid'   => false,
					'message' => __( 'Each JSONL record must be a valid JSON object.', 'cybermaps' ),
				);
			}
		}
		return array(
			'valid'   => true,
			'message' => '',
		);
	}

	/** @return array{valid:?bool,message:string} */
	private function validate_xml_body( string $body ): array {
		if ( ! function_exists( 'simplexml_load_string' ) ) {
			return array(
				'valid'   => null,
				'message' => __( 'The PHP XML extension is unavailable, so the XML body could not be parsed locally.', 'cybermaps' ),
			);
		}
		$previous = libxml_use_internal_errors( true );
		$xml      = simplexml_load_string( $body );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( false === $xml ) {
			return array(
				'valid'   => false,
				'message' => __( 'The public response is not parseable XML.', 'cybermaps' ),
			);
		}
		return array(
			'valid'   => true,
			'message' => '',
		);
	}

	/**
	 * Ensure a decoded RFC 9264 JSON Linkset has a context and at least one link.
	 *
	 * @param mixed $decoded Decoded JSON.
	 */
	private function is_valid_linkset( mixed $decoded ): bool {
		if ( ! is_array( $decoded ) || empty( $decoded['linkset'] ) || ! is_array( $decoded['linkset'] ) ) {
			return false;
		}

		foreach ( $decoded['linkset'] as $context ) {
			if ( ! is_array( $context ) || empty( $context['anchor'] ) ) {
				continue;
			}

			foreach ( $context as $relation => $links ) {
				if ( 'anchor' === $relation || ! is_array( $links ) ) {
					continue;
				}
				foreach ( $links as $link ) {
					if ( is_array( $link ) && ! empty( $link['href'] ) ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/**
	 * Determine public delivery from the dynamic marker or an exact owned-file
	 * body hash. Anything else remains explicitly unverified.
	 *
	 * @param array<string, mixed> $publication Publication metadata.
	 */
	private function detect_delivery( array $publication, string $body, string $marker ): string {
		if ( '' !== $marker ) {
			return 'dynamic';
		}

		$filename = (string) ( $publication['static_file'] ?? '' );
		if ( '' === $filename || true !== ( $publication['on_disk'] ?? null ) ) {
			return 'unverified';
		}

		$hash = ( new \Cybermaps\Discovery\StaticOwnershipStore() )->get_hash( $filename ) ?? '';
		if (
			'' !== $hash
			&& 1 === preg_match( '/^[a-f0-9]{32}$/i', $hash )
			&& hash_equals( strtolower( $hash ), md5( $body ) )
		) {
			return 'static';
		}

		return 'unverified';
	}

	/**
	 * Check a media type for the RFC 9727 API Catalog profile.
	 */
	private function has_api_catalog_profile( string $content_type ): bool {
		return 1 === preg_match(
			'/;\s*profile\s*=\s*(?:"|\')?' . preg_quote( self::API_CATALOG_PROFILE, '/' ) . '(?:"|\')?(?:\s*;|\s*$)/i',
			$content_type
		);
	}

	/**
	 * Check a Link header for a relation token.
	 */
	private function has_link_relation( string $header, string $relation ): bool {
		return 1 === preg_match(
			'/(?:^|[;,]\s*)rel\s*=\s*(?:"[^"]*\b' . preg_quote( $relation, '/' ) . '\b[^"]*"|' . preg_quote( $relation, '/' ) . '(?:\s*;|\s*,|\s*$))/i',
			$header
		);
	}

	/**
	 * Index registry static targets by their public path.
	 *
	 * @param array<int, array<string, mixed>> $targets Static target rows.
	 * @return array<string, array<string, mixed>>
	 */
	private function index_static_targets( array $targets ): array {
		$indexed = array();
		foreach ( $targets as $target ) {
			if ( is_array( $target ) && ! empty( $target['path'] ) ) {
				$indexed[ (string) $target['path'] ] = $target;
			}
		}
		return $indexed;
	}

	/**
	 * Initialize and return WordPress's filesystem abstraction when available.
	 *
	 * @return object|null
	 */
	private function get_filesystem(): ?object {
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! function_exists( 'WP_Filesystem' ) || ! WP_Filesystem() ) {
			return null;
		}

		global $wp_filesystem;
		return is_object( $wp_filesystem ) ? $wp_filesystem : null;
	}

	/**
	 * Return concise environmental caveats for the status UI.
	 *
	 * @param array<string, mixed> $settings Settings option.
	 * @return array<int, array{level: string, message: string}>
	 */
	private function get_environment_notices( array $settings, int $unverified_count ): array {
		$notices     = array();
		$public_base = \Cybermaps\Core\URLManager::get_home_url( '/' );
		$public_path = (string) wp_parse_url( $public_base, PHP_URL_PATH );

		if ( '' !== trim( $public_path, '/' ) ) {
			$notices[] = array(
				'level'   => 'warning',
				'message' => __( 'The public base URL contains a path. RFC well-known URIs belong at the origin root, so the server must map origin-root /.well-known/ requests to these publications.', 'cybermaps' ),
			);
		}

		if ( ! empty( $settings['frontend_base_url'] ) ) {
			$notices[] = array(
				'level'   => 'warning',
				'message' => __( 'Headless mode is configured. These checks target the public frontend, but files written on the WordPress host are not automatically deployed to that frontend.', 'cybermaps' ),
			);
		}

		if ( $unverified_count > 0 ) {
			$notices[] = array(
				'level'   => 'warning',
				'message' => __( 'One or more public checks were unverified because this server could not complete or fully parse its own request. This does not prove the endpoint is unavailable to external clients.', 'cybermaps' ),
			);
		}

		$routing = \Cybermaps\Discovery\WellKnownRoutingBridge::get_status();
		if ( in_array( (string) ( $routing['status'] ?? '' ), array( 'conflict', 'write_failed', 'blocked_before_wordpress' ), true ) ) {
			$notices[] = array(
				'level'   => 'warning',
				'message' => (string) ( $routing['message'] ?? __( 'The automatic well-known routing bridge needs attention.', 'cybermaps' ) ),
			);
		}

		$notices[] = array(
			'level'   => 'info',
			'message' => __( 'Cybermaps materializes enabled canonical discovery fallback bodies in compatibility mode. Dynamic delivery remains preferred, while Debugging and optional edge rules report or repair headers when a server serves physical files before WordPress.', 'cybermaps' ),
		);

		return $notices;
	}

	/**
	 * Cache key varies with public origin, registry inventory, mode, and owned
	 * static inventory so configuration changes do not reuse obsolete probes.
	 *
	 * @param array<string, mixed> $settings Settings option.
	 */
	private function get_cache_key( array $settings, string $static_mode ): string {
		$registry = \Cybermaps\Core\EndpointRegistry::get_instance();
		$context  = array(
			'version'      => CYBERMAPS_VERSION,
			'public_base'  => \Cybermaps\Core\URLManager::get_home_url( '/' ),
			'hub'          => ! empty( $settings['enable_discovery_hub'] ),
			'mode'         => $static_mode,
			'publications' => $registry->get_path_publications(),
			'ownership'    => max( 0, (int) get_option( 'cybermaps_static_ownership_revision', 0 ) ),
			'sync_report'  => get_option( 'cybermaps_last_static_sync_report', array() ),
			'schedule'     => get_option( 'cybermaps_static_schedule_error', array() ),
			'robots'       => \Cybermaps\Core\ConfigurationStore::robots(),
			'generation'   => max( 0, (int) get_option( 'cybermaps_static_generation', 0 ) ),
		);

		return 'cybermaps_discovery_health_' . md5( (string) wp_json_encode( $context ) );
	}
}
