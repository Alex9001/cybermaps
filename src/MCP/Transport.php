<?php
declare(strict_types=1);

namespace Cybermaps\MCP;

use Cybermaps\Core\ConfigurationStore;
use Cybermaps\MCP\OAuth\OAuthException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Stateless one-request-per-POST Streamable HTTP transport. */
final class Transport {
	private \Closure $mode_resolver;

	public function __construct(
		private readonly Server $server,
		private readonly CallerContextResolverInterface $callers,
		?callable $mode_resolver = null,
		?callable $protected_resource_metadata_url = null
	) {
		$this->mode_resolver                   = \Closure::fromCallable( $mode_resolver ?? array( self::class, 'configured_mode' ) );
		$this->protected_resource_metadata_url = \Closure::fromCallable(
			$protected_resource_metadata_url ?? static fn(): string => home_url( '/.well-known/oauth-protected-resource' )
		);
	}

	private \Closure $protected_resource_metadata_url;

	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		if ( 'off' === $this->mode() ) {
			return;
		}
		register_rest_route(
			'cybermaps/v1',
			Protocol::ROUTE,
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle_post' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'GET,DELETE,PUT,PATCH',
					'callback'            => array( $this, 'method_not_allowed' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/** @return \WP_REST_Response */
	public function handle_post( object $request ) {
		$id = null;
		try {
			if ( 'off' === $this->mode() ) {
				return $this->response( Server::error( null, -32001, 'MCP is disabled.' ), 404 );
			}
			$this->validate_origin( $request );
			$decoded = $this->decode_request( $request, $id );
			$this->validate_request( $decoded );
			$this->validate_headers( $request, $decoded );
			$caller = $this->callers->resolve( $request );
			$result = $this->server->handle( $decoded, $caller, $this->mode() );
			return $this->response( $result, self::response_status( $result ) );
		} catch ( ProtocolException $error ) {
			$result = Server::error( $id, $error->rpc_code, $error->getMessage() );
			return $this->response( $result, self::response_status( $result ) );
		} catch ( OAuthException $error ) {
			$oauth_error = $error->to_error_response();
			$rpc_code    = 401 === $error->status_code() ? -32002 : -32021;
			return $this->response(
				Server::error( $id, $rpc_code, $error->getMessage() ),
				$error->status_code(),
				array( 'WWW-Authenticate' => $this->bearer_challenge( $oauth_error ) )
			);
		} catch ( \Throwable $error ) {
			return $this->response( Server::error( $id, -32000, $error->getMessage() ) );
		}
	}

	/** @return array<string,mixed> */
	private function decode_request( object $request, mixed &$id ): array {
		$body = method_exists( $request, 'get_body' ) ? $request->get_body() : '';
		if ( ! is_string( $body ) || '' === $body || strlen( $body ) > 1048576 ) {
			throw new ProtocolException( -32600, 'Request body must contain one bounded JSON-RPC object.' );
		}
		$decoded = json_decode( $body, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			throw new ProtocolException( -32700, 'Request body is not valid JSON.' );
		}
		if ( ! is_array( $decoded ) || array_is_list( $decoded ) ) {
			throw new ProtocolException( -32600, 'Batches and non-object JSON-RPC requests are not supported.' );
		}
		$id = is_int( $decoded['id'] ?? null ) || is_string( $decoded['id'] ?? null ) ? $decoded['id'] : null;
		return $decoded;
	}

	/** @return \WP_REST_Response */
	public function method_not_allowed() {
		return $this->response( Server::error( null, -32600, 'Only POST is supported by this stateless MCP transport.' ), 405 );
	}

	/** @param array<string, mixed> $request */
	private function validate_request( array $request ): void {
		$this->validate_request_basics( $request );
		$params = $this->validate_request_params( $request );
		$this->validate_request_metadata( $params );
		if ( array() !== array_diff( array_keys( $request ), array( 'jsonrpc', 'id', 'method', 'params' ) ) ) {
			throw new ProtocolException( -32600, 'Unknown JSON-RPC request members are not accepted.' ); }
	}

	private function validate_request_basics( array $request ): void {
		if ( '2.0' !== ( $request['jsonrpc'] ?? null ) || ! array_key_exists( 'id', $request ) ) {
			throw new ProtocolException( -32600, 'A JSON-RPC 2.0 request ID is required; notifications are not accepted.' ); }
		if ( ! is_int( $request['id'] ) && ! is_string( $request['id'] ) ) {
			throw new ProtocolException( -32600, 'The request ID must be a string or integer.' ); }
		if ( ! is_string( $request['method'] ?? null ) || '' === $request['method'] ) {
			throw new ProtocolException( -32600, 'A JSON-RPC method is required.' ); }
	}

	/** @return array<string,mixed> */
	private function validate_request_params( array $request ): array {
		$params = $request['params'] ?? null;
		if ( ! is_array( $params ) || ( array_is_list( $params ) && array() !== $params ) ) {
			throw new ProtocolException( -32600, 'JSON-RPC params must be an object.' ); }
		return $params;
	}

	private function validate_request_metadata( array $params ): void {
		$meta = $params['_meta'] ?? null;
		if ( ! is_array( $meta ) || array_is_list( $meta ) ) {
			throw new ProtocolException( -32600, 'JSON-RPC params must contain the MCP request metadata object.' ); }
		$client_info         = $meta['io.modelcontextprotocol/clientInfo'] ?? null;
		$client_capabilities = $meta['io.modelcontextprotocol/clientCapabilities'] ?? null;
		if ( ! is_array( $client_info ) || array_is_list( $client_info ) || ! is_string( $client_info['name'] ?? null ) || ! is_string( $client_info['version'] ?? null ) ) {
			throw new ProtocolException( -32600, 'MCP clientInfo must contain a name and version.' ); }
		if ( ! is_array( $client_capabilities ) || ( array() !== $client_capabilities && array_is_list( $client_capabilities ) ) ) {
			throw new ProtocolException( -32600, 'MCP clientCapabilities must be an object.' ); }
	}

	/** @param array<string, mixed> $rpc */
	private function validate_headers( object $request, array $rpc ): void {
		$version = $this->validate_protocol_header( $request );
		$method  = (string) $rpc['method'];
		if ( $method !== $this->header( $request, 'Mcp-Method' ) ) {
			throw new ProtocolException( -32020, 'Mcp-Method is missing or does not match the JSON-RPC method.' ); }
		$params = (array) $rpc['params'];
		$this->validate_body_protocol_version( $params, $version );
		$named_parameters = array(
			'tools/call'     => 'name',
			'resources/read' => 'uri',
			'prompts/get'    => 'name',
		);
		$this->validate_named_header( $request, $method, $params, $named_parameters );
	}

	private function validate_protocol_header( object $request ): string {
		$version = $this->header( $request, 'MCP-Protocol-Version' );
		if ( '' === $version ) {
			throw new ProtocolException( -32020, 'MCP-Protocol-Version is required.' ); }
		if ( Protocol::VERSION !== $version ) {
			throw new ProtocolException( -32022, 'The requested MCP protocol version is unsupported.' ); }
		return $version;
	}

	private function validate_body_protocol_version( array $params, string $version ): void {
		$meta         = (array) $params['_meta'];
		$body_version = $meta['io.modelcontextprotocol/protocolVersion'] ?? null;
		if ( Protocol::VERSION === $body_version ) {
			return; }
		if ( is_string( $body_version ) && '' !== $body_version && $version === $body_version ) {
			throw new ProtocolException( -32022, 'The request metadata declares an unsupported MCP protocol version.' ); }
		throw new ProtocolException( -32020, 'The MCP protocol version header and request metadata do not match.' );
	}

	/** @param array<string,mixed> $params @param array<string,string> $named_parameters */
	private function validate_named_header( object $request, string $method, array $params, array $named_parameters ): void {
		$name      = $this->header( $request, 'Mcp-Name' );
		$parameter = $named_parameters[ $method ] ?? null;
		$expected  = is_string( $parameter ) && is_string( $params[ $parameter ] ?? null ) ? $params[ $parameter ] : $method;
		if ( is_string( $parameter ) && '' === $name ) {
			throw new ProtocolException( -32020, 'Mcp-Name is required for this named MCP method.' ); }
		if ( '' !== $name && $expected !== $name ) {
			throw new ProtocolException( -32020, 'Mcp-Name does not match the request method or named parameter.' ); }
	}

	private function validate_origin( object $request ): void {
		$origin = $this->header( $request, 'Origin' );
		if ( '' === $origin ) {
			return;
		}
		$site    = wp_parse_url( home_url( '/' ) );
		$parsed  = wp_parse_url( $origin );
		$allowed = is_array( $site ) && is_array( $parsed )
			&& strtolower( (string) ( $site['scheme'] ?? '' ) ) === strtolower( (string) ( $parsed['scheme'] ?? '' ) )
			&& strtolower( (string) ( $site['host'] ?? '' ) ) === strtolower( (string) ( $parsed['host'] ?? '' ) )
			&& (int) ( $site['port'] ?? 0 ) === (int) ( $parsed['port'] ?? 0 )
			&& ! isset( $parsed['user'], $parsed['pass'], $parsed['query'], $parsed['fragment'] );

		/**
		 * Allow a deployment to approve an additional exact MCP request origin.
		 *
		 * @param bool   $allowed Default same-origin decision.
		 * @param string $origin  Origin header.
		 * @param object $request REST request.
		 */
		$allowed = (bool) apply_filters( 'cybermaps_mcp_origin_allowed', $allowed, $origin, $request );
		if ( ! $allowed ) {
			throw new ProtocolException( -32001, 'Request Origin is not allowed.' );
		}
	}

	private function header( object $request, string $name ): string {
		$value = method_exists( $request, 'get_header' ) ? $request->get_header( $name ) : '';
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/** @param array<string, mixed> $data
	 *  @return \WP_REST_Response
	 */
	private function response( array $data, int $status = 200, array $headers = array() ) {
		$response = new \WP_REST_Response( $data, $status );
		$response->header( 'Cache-Control', 'no-store, private' );
		$response->header( 'MCP-Protocol-Version', Protocol::VERSION );
		foreach ( $headers as $name => $value ) {
			if ( is_string( $name ) && is_string( $value ) ) {
				$response->header( $name, $value );
			}
		}
		return $response;
	}

	/** @param array<string,string> $error */
	private function bearer_challenge( array $error ): string {
		$metadata_url = (string) ( $this->protected_resource_metadata_url )();
		$values       = array(
			'resource_metadata="' . self::header_value( $metadata_url ) . '"',
			'error="' . self::header_value( (string) ( $error['error'] ?? 'invalid_token' ) ) . '"',
			'error_description="' . self::header_value( (string) ( $error['error_description'] ?? '' ) ) . '"',
		);
		return 'Bearer ' . implode( ', ', $values );
	}

	private static function header_value( string $value ): string {
		return str_replace( array( '\\', '"', "\r", "\n" ), array( '\\\\', '\\"', '', '' ), $value );
	}

	private function mode(): string {
		$mode = (string) ( $this->mode_resolver )();
		return in_array( $mode, Protocol::MODES, true ) ? $mode : 'off';
	}

	/** @param array<string, mixed> $result */
	private static function response_status( array $result ): int {
		$code = $result['error']['code'] ?? null;
		if ( ! is_int( $code ) ) {
			return 200;
		}
		return match ( $code ) {
			-32002 => 401,
			-32001, -32021 => 403,
			-32601, -32004 => 404,
			-32700, -32600, -32602, -32020, -32022 => 400,
			default => 500,
		};
	}

	public static function configured_mode(): string {
		$settings = ConfigurationStore::settings();
		return is_string( $settings['mcp_mode'] ?? null ) ? $settings['mcp_mode'] : 'off';
	}
}
