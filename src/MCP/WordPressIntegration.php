<?php
declare(strict_types=1);

namespace Cybermaps\MCP;

use Cybermaps\Core\ConfigurationStore;
use Cybermaps\Core\EndpointRegistry;
use Cybermaps\Discovery\DiscoveryPublicationGenerator;
use Cybermaps\Discovery\IndexNow;
use Cybermaps\Discovery\Search;
use Cybermaps\Discovery\StaticBridge;
use Cybermaps\MCP\OAuth\ClientRegistrationValidator;
use Cybermaps\MCP\OAuth\OAuthService;
use Cybermaps\MCP\OAuth\OAuthRouteController;
use Cybermaps\MCP\OAuth\WordPressUserAuthorizer;
use Cybermaps\MCP\OAuth\WpdbOAuthRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** WordPress composition root for Cybermaps' optional MCP surface. */
final class WordPressIntegration {
	private ?Transport $transport                   = null;
	private ?OAuthRouteController $oauth_controller = null;

	public function register_hooks(): void {
		add_action( 'update_option_cybermaps_settings', array( $this, 'handle_settings_update' ), 10, 2 );

		if ( 'off' === self::mode() ) {
			WordPressTaskService::clear_scheduled_hooks();
			return;
		}
		$tasks = new WordPressTaskService();
		$tasks->register_hooks();

		$oauth                  = $this->oauth_service();
		$confirmations          = new OneTimeConfirmationService( new WordPressConfirmationStateStore(), wp_salt( 'auth' ) );
		$this->oauth_controller = new OAuthRouteController( $oauth );
		$this->transport        = new Transport(
			new Server(
				new ResourceRegistry(
					EndpointRegistry::get_instance(),
					new CallbackResourceReader( array( $this, 'read_publication' ) )
				),
				new ToolRegistry( $this->executors(), $tasks, $confirmations ),
				$tasks,
				$confirmations
			),
			new CallbackCallerContextResolver( array( $this, 'resolve_caller' ) ),
			array( self::class, 'mode' )
		);
		$this->transport->register_hooks();
		$this->oauth_controller->register_hooks();
	}

	/** Keep disabled MCP routes and jobs unavailable immediately after a settings change. */
	public function handle_settings_update( mixed $old_value, mixed $new_value ): void {
		$old_settings = is_array( $old_value ) ? $old_value : array();
		$new_settings = is_array( $new_value ) ? $new_value : array();
		if ( 'off' === self::mode( $old_settings ) && 'off' !== self::mode( $new_settings ) ) {
			WpdbOAuthRepository::create_tables();
			TaskRepository::create_tables();
		}
		if ( ! is_array( $new_value ) || 'off' === self::mode( $new_value ) ) {
			WordPressTaskService::clear_scheduled_hooks();
		}
	}

	public static function mode( ?array $settings = null ): string {
		$settings = $settings ?? ConfigurationStore::settings();
		if ( empty( $settings['enable_discovery_hub'] ) ) {
			return 'off';
		}
		$mode = is_string( $settings['mcp_mode'] ?? null ) ? $settings['mcp_mode'] : 'off';
		return in_array( $mode, Protocol::MODES, true ) ? $mode : 'off';
	}

	/** @return array{body:string,mime_type:string}|null */
	public function read_publication( string $id, array $definition ): ?array {
		if ( 'path' !== (string) ( $definition['kind'] ?? '' ) ) {
			return null;
		}
		$body = ( new DiscoveryPublicationGenerator() )->generate(
			$id,
			array(
				'id'   => $id,
				'path' => (string) ( $definition['path'] ?? '' ),
			),
			ConfigurationStore::settings()
		);
		if ( '' === $body ) {
			return null;
		}
		return array(
			'body'      => $body,
			'mime_type' => (string) ( $definition['type'] ?? 'text/plain' ),
		);
	}

	public function resolve_caller( object $request ): CallerContext {
		$authorization = method_exists( $request, 'get_header' ) ? (string) $request->get_header( 'authorization' ) : '';
		if ( '' === $authorization ) {
			return new CallerContext( '', '' );
		}
		$token = $this->oauth_service()->validate_bearer_header( $authorization, $this->mcp_url() );
		$user  = (int) ( $token['user_id'] ?? 0 );
		return new CallerContext(
			(string) $user,
			(string) ( $token['client_id'] ?? '' ),
			is_array( $token['scopes'] ?? null ) ? $token['scopes'] : array(),
			static fn( string $capability ): bool => $user > 0 && user_can( $user, $capability )
		);
	}

	/** @return array<string,callable> */
	private function executors(): array {
		$executors = array(
			'cybermaps.search'       => static function ( array $arguments ): array {
				$request = new \WP_REST_Request( 'GET', '/cybermaps/v1/search' );
				$request->set_param( 'q', (string) ( $arguments['q'] ?? '' ) );
				$request->set_param( 'limit', (int) ( $arguments['limit'] ?? 10 ) );
				$response = ( new Search() )->handle_search( $request );
				if ( $response instanceof \WP_REST_Response ) {
					$data = $response->get_data();
					return is_array( $data ) ? $data : array();
				}
				if ( is_wp_error( $response ) ) {
					throw new \RuntimeException( $response->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The response is JSON encoded by the MCP transport, not rendered as HTML.
				}
				return array();
			},
			'cybermaps.static.purge' => static fn(): array => StaticBridge::get_instance()->cancel_and_purge( 'all' ),
		);

		$executors['cybermaps.indexnow.submit'] = static fn( array $arguments ): array => ( new IndexNow() )->submit_urls( (array) ( $arguments['urls'] ?? array() ), true );
		return $executors;
	}

	private function oauth_service(): OAuthService {
		return \Cybermaps\MCP\OAuth\OAuthServiceFactory::create();
	}

	private function mcp_url(): string {
		return rest_url( EndpointRegistry::REST_NAMESPACE . Protocol::ROUTE );
	}
}
