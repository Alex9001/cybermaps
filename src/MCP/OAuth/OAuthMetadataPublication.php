<?php
declare(strict_types=1);

namespace Cybermaps\MCP\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Canonical OAuth discovery bodies shared by HTTP and static publication. */
final class OAuthMetadataPublication {
	public const AUTHORIZATION_SERVER_PATH = '/.well-known/oauth-authorization-server';
	public const PROTECTED_RESOURCE_PATH   = '/.well-known/oauth-protected-resource';

	/** @var callable():bool */
	private $device_mode;

	public function __construct( private readonly ?OAuthService $service = null, ?callable $device_mode = null ) {
		$this->device_mode = $device_mode ?? static fn(): bool => AgentRegistrationMode::is_user_claimed();
	}

	/** Serve a matching canonical metadata request. */
	public function handle(): void {
		$metadata = $this->metadata_for_request();
		if ( null === $metadata ) {
			return;
		}

		\status_header( 200 );
		\nocache_headers();
		\header( 'Content-Type: application/json; charset=utf-8' );
		\header( 'Cache-Control: no-store' );
		\header( 'Access-Control-Allow-Origin: *' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Protocol JSON is encoded immediately before output.
		echo \wp_json_encode( $metadata );
		exit;
	}

	/** @return array<string,mixed> */
	public function authorization_server_metadata(): array {
		$metadata = $this->oauth_service()->authorization_server_metadata();
		if ( (bool) \call_user_func( $this->device_mode ) ) {
			$metadata['device_authorization_endpoint'] = OAuthRouteController::device_authorization_endpoint_url();
			if ( ! \in_array( OAuthService::DEVICE_GRANT_TYPE, $metadata['grant_types_supported'], true ) ) {
				$metadata['grant_types_supported'][] = OAuthService::DEVICE_GRANT_TYPE;
			}
		}
		return $metadata;
	}

	/** @return array<string,mixed> */
	public function protected_resource_metadata(): array {
		return $this->oauth_service()->protected_resource_metadata();
	}

	/** @return array<string,mixed>|null */
	private function metadata_for_request(): ?array {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) && \is_string( $_SERVER['REQUEST_URI'] ) ? \sanitize_text_field( \wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path        = \wp_parse_url( $request_uri, PHP_URL_PATH );
		$path        = \is_string( $path ) ? $path : '';
		$base_path   = \wp_parse_url( \home_url( '/' ), PHP_URL_PATH );
		$base_path   = \is_string( $base_path ) ? \rtrim( $base_path, '/' ) : '';
		if ( '' !== $base_path && ! \str_starts_with( $path, $base_path . '/' ) ) {
			return null;
		}
		$relative = '' === $base_path ? $path : \substr( $path, \strlen( $base_path ) );

		return match ( $relative ) {
			self::AUTHORIZATION_SERVER_PATH => $this->authorization_server_metadata(),
			self::PROTECTED_RESOURCE_PATH   => $this->protected_resource_metadata(),
			default                         => null,
		};
	}

	private function oauth_service(): OAuthService {
		return $this->service ?? OAuthServiceFactory::create();
	}
}
