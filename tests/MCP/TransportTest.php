<?php
declare(strict_types=1);

namespace Cybermaps\Tests\MCP;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Request stub is local to this focused transport test.

use Cybermaps\Core\EndpointRegistry;
use Cybermaps\MCP\CallbackCallerContextResolver;
use Cybermaps\MCP\CallbackResourceReader;
use Cybermaps\MCP\CallerContext;
use Cybermaps\MCP\InMemoryConfirmationStateStore;
use Cybermaps\MCP\OneTimeConfirmationService;
use Cybermaps\MCP\OAuth\OAuthException;
use Cybermaps\MCP\ResourceRegistry;
use Cybermaps\MCP\Server;
use Cybermaps\MCP\ToolRegistry;
use Cybermaps\MCP\Transport;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TransportTest extends \WP_UnitTestCase {
	public function test_accepts_one_current_version_discovery_request(): void {
		$request  = new MCPRequestStub(
			array(
				'MCP-Protocol-Version' => '2026-07-28',
				'Mcp-Method'           => 'server/discover',
				'Origin'               => 'https://example.com',
			),
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'server/discover',
				'params'  => $this->params(),
			)
		);
		$response = $this->transport()->handle_post( $request );
		$data     = $response->get_data();

		$this->assertSame( 'complete', $data['result']['resultType'] );
		$this->assertSame( array( '2026-07-28' ), $data['result']['supportedVersions'] );
		$this->assertSame( 'Cybermaps', $data['result']['_meta']['io.modelcontextprotocol/serverInfo']['name'] );
		$this->assertSame( 'public', $data['result']['cacheScope'] );
		$this->assertSame( 'read_only', $data['result']['mode'] );
		$this->assertSame( '2026-07-28', $response->get_headers()['MCP-Protocol-Version'] );
	}

	public function test_rejects_header_body_method_mismatch(): void {
		$request  = new MCPRequestStub(
			array(
				'MCP-Protocol-Version' => '2026-07-28',
				'Mcp-Method'           => 'tools/list',
				'Mcp-Name'             => 'server/discover',
			),
			array(
				'jsonrpc' => '2.0',
				'id'      => 'a',
				'method'  => 'server/discover',
				'params'  => $this->params(),
			)
		);
		$response = $this->transport()->handle_post( $request );
		$data     = $response->get_data();

		$this->assertSame( -32020, $data['error']['code'] );
		$this->assertSame( 400, $response->get_status() );
		$this->assertStringContainsString( 'Mcp-Method', $data['error']['message'] );
	}

	public function test_rejects_json_rpc_batch(): void {
		$request = new MCPRequestStub(
			array(
				'MCP-Protocol-Version' => '2026-07-28',
				'Mcp-Method'           => 'server/discover',
				'Mcp-Name'             => 'server/discover',
			),
			array(
				array(
					'jsonrpc' => '2.0',
					'id'      => 1,
					'method'  => 'server/discover',
					'params'  => $this->params(),
				),
			)
		);
		$data    = $this->transport()->handle_post( $request )->get_data();

		$this->assertSame( -32600, $data['error']['code'] );
	}

	public function test_requires_name_only_for_named_methods(): void {
		$params         = $this->params();
		$params['name'] = 'cybermaps.search';
		$request        = new MCPRequestStub(
			array(
				'MCP-Protocol-Version' => '2026-07-28',
				'Mcp-Method'           => 'tools/call',
			),
			array(
				'jsonrpc' => '2.0',
				'id'      => 2,
				'method'  => 'tools/call',
				'params'  => $params,
			)
		);
		$response       = $this->transport()->handle_post( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( -32020, $response->get_data()['error']['code'] );
	}

	public function test_rejects_unsupported_protocol_version_with_http_400(): void {
		$params = $this->params();
		$params['_meta']['io.modelcontextprotocol/protocolVersion'] = '2025-11-25';
		$request  = new MCPRequestStub(
			array(
				'MCP-Protocol-Version' => '2025-11-25',
				'Mcp-Method'           => 'server/discover',
			),
			array(
				'jsonrpc' => '2.0',
				'id'      => 3,
				'method'  => 'server/discover',
				'params'  => $params,
			)
		);
		$response = $this->transport()->handle_post( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( -32022, $response->get_data()['error']['code'] );
	}

	public function test_unknown_method_returns_http_404(): void {
		$request  = new MCPRequestStub(
			array(
				'MCP-Protocol-Version' => '2026-07-28',
				'Mcp-Method'           => 'unknown/method',
			),
			array(
				'jsonrpc' => '2.0',
				'id'      => 4,
				'method'  => 'unknown/method',
				'params'  => $this->params(),
			)
		);
		$response = $this->transport()->handle_post( $request );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( -32601, $response->get_data()['error']['code'] );
	}

	public function test_bearer_validation_failure_returns_401_with_resource_metadata_challenge(): void {
		$response = $this->transport(
			static function (): CallerContext {
				throw new OAuthException( 'invalid_token', 'The access token is invalid.', 401 );
			}
		)->handle_post( $this->discovery_request( 5 ) );

		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( -32002, $response->get_data()['error']['code'] );
		$this->assertStringStartsWith( 'Bearer ', $response->get_headers()['WWW-Authenticate'] );
		$this->assertStringContainsString( 'resource_metadata="https://example.com/.well-known/oauth-protected-resource"', $response->get_headers()['WWW-Authenticate'] );
	}

	public function test_bearer_scope_failure_returns_403_with_bearer_challenge(): void {
		$response = $this->transport(
			static function (): CallerContext {
				throw new OAuthException( 'insufficient_scope', 'The access token does not grant the required scope.', 403 );
			}
		)->handle_post( $this->discovery_request( 6 ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertSame( -32021, $response->get_data()['error']['code'] );
		$this->assertStringContainsString( 'error="insufficient_scope"', $response->get_headers()['WWW-Authenticate'] );
	}

	private function discovery_request( int $id ): MCPRequestStub {
		return new MCPRequestStub(
			array(
				'MCP-Protocol-Version' => '2026-07-28',
				'Mcp-Method'           => 'server/discover',
			),
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'method'  => 'server/discover',
				'params'  => $this->params(),
			)
		);
	}

	/** @return array<string, mixed> */
	private function params(): array {
		return array(
			'_meta' => array(
				'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
				'io.modelcontextprotocol/clientInfo'      => array(
					'name'    => 'test-client',
					'version' => '1.0',
				),
				'io.modelcontextprotocol/clientCapabilities' => array(),
			),
		);
	}

	private function transport( ?callable $caller = null ): Transport {
		$confirmations = new OneTimeConfirmationService( new InMemoryConfirmationStateStore(), 'test-secret' );
		$tasks         = new MCPTaskServiceStub();
		$resources     = new ResourceRegistry(
			EndpointRegistry::get_instance(),
			new CallbackResourceReader(
				static fn(): array => array(
					'body'      => 'test',
					'mime_type' => 'text/plain',
				)
			)
		);
		$tools         = new ToolRegistry( array( 'cybermaps.search' => static fn(): array => array() ), $tasks, $confirmations );
		$server        = new Server( $resources, $tools, $tasks, $confirmations );
		$callers       = new CallbackCallerContextResolver( $caller ?? static fn(): CallerContext => new CallerContext( '', '' ) );
		return new Transport( $server, $callers, static fn(): string => 'read_only' );
	}
}

final class MCPRequestStub {
	/** @param array<string, string> $headers
	 *  @param array<mixed>         $body
	 */
	public function __construct( private readonly array $headers, private readonly array $body ) {}

	public function get_header( string $name ): string {
		return $this->headers[ $name ] ?? '';
	}

	public function get_body(): string {
		$body = wp_json_encode( $this->body );
		return is_string( $body ) ? $body : '';
	}
}
