<?php
declare(strict_types=1);

namespace Cybermaps\MCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Stateless MCP JSON-RPC dispatcher. */
final class Server {
	public function __construct(
		private readonly ResourceRegistry $resources,
		private readonly ToolRegistry $tools,
		private readonly TaskServiceInterface $tasks,
		private readonly ConfirmationServiceInterface $confirmations
	) {}

	/**
	 * @param array<string, mixed> $request Validated JSON-RPC request.
	 * @return array<string, mixed>
	 */
	public function handle( array $request, CallerContext $caller, string $mode ): array {
		$id = $request['id'];
		try {
			$result = $this->dispatch( (string) $request['method'], (array) ( $request['params'] ?? array() ), $caller, $mode );
			$result = $this->with_result_metadata( $result );
			return array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => $result,
			);
		} catch ( ProtocolException $error ) {
			return self::error( $id, $error->rpc_code, $error->getMessage() );
		} catch ( \InvalidArgumentException $error ) {
			return self::error( $id, -32602, $error->getMessage() );
		} catch ( \Throwable $error ) {
			return self::error( $id, -32000, $error->getMessage() );
		}
	}

	/**
	 * @param array<string, mixed> $params Method parameters.
	 * @return array<string, mixed>
	 */
	private function dispatch( string $method, array $params, CallerContext $caller, string $mode ): array {
		if ( 'server/discover' === $method ) {
			return $this->discover( $mode );
		}
		if ( in_array( $method, array( 'resources/list', 'resources/templates/list', 'resources/read', 'tools/list' ), true ) ) {
			return $this->dispatch_readonly( $method, $params, $caller, $mode );
		}
		if ( 'tools/call' === $method ) {
			return $this->call_tool( $params, $caller, $mode ); }
		if ( 'tasks/get' === $method ) {
			return $this->get_task( $params, $caller ); }
		if ( 'tasks/cancel' === $method ) {
			return $this->cancel_task( $params, $caller, $mode ); }
		throw new ProtocolException( -32601, 'Method is not supported.' );
	}

	/** @return array<string,mixed> */
	private function dispatch_readonly( string $method, array $params, CallerContext $caller, string $mode ): array {
		if ( 'resources/list' === $method ) {
			return self::cacheable_result( array( 'resources' => $this->resources->list() ) ); }
		if ( 'resources/templates/list' === $method ) {
			return self::cacheable_result( array( 'resourceTemplates' => array() ) ); }
		if ( 'tools/list' === $method ) {
			return self::cacheable_result( array( 'tools' => $this->tools->list( $mode, $caller ) ) ); }
		$uri      = self::bounded_string( $params, 'uri', 4096 );
		$resource = $this->resources->read( $uri );
		if ( null === $resource ) {
			throw new ProtocolException( -32004, 'Resource was not found in the enabled publication registry.' ); }
		return self::cacheable_result( array( 'contents' => array( $resource ) ) );
	}

	/** @return array<string, mixed> */
	private function discover( string $mode ): array {
		return array(
			'resultType'        => 'complete',
			'supportedVersions' => array( Protocol::VERSION ),
			'capabilities'      => array(
				'resources' => array(
					'listChanged' => false,
					'subscribe'   => false,
				),
				'tools'     => array( 'listChanged' => false ),
				'tasks'     => array(
					'polling'      => true,
					'cancellation' => true,
				),
			),
			'instructions'      => 'Use Cybermaps resources for published discovery data and allowlisted tools only for the configured mode.',
			'ttlMs'             => 3600000,
			'cacheScope'        => 'public',
			'mode'              => $mode,
		);
	}

	/** @param array<string, mixed> $params
	 *  @return array<string, mixed>
	 */
	private function call_tool( array $params, CallerContext $caller, string $mode ): array {
		$name      = self::bounded_string( $params, 'name', 128 );
		$arguments = $params['arguments'] ?? array();
		if ( ! is_array( $arguments ) || ( array_is_list( $arguments ) && array() !== $arguments ) ) {
			throw new \InvalidArgumentException( 'Tool arguments must be a JSON object.' );
		}
		$confirmation = $this->confirmation_from_params( $params );
		return $this->tools->call( $name, $arguments, $confirmation, $mode, $caller );
	}

	/** @param array<string, mixed> $params
	 *  @return array<string, mixed>
	 */
	private function get_task( array $params, CallerContext $caller ): array {
		$handle = self::bounded_string( $params, 'taskId', 160 );
		$status = $this->tasks->status( $handle, $caller );
		if ( null === $status ) {
			throw new ProtocolException( -32004, 'Task was not found.' );
		}
		return self::cacheable_result( $status );
	}

	/** @param array<string, mixed> $params
	 *  @return array<string, mixed>
	 */
	private function cancel_task( array $params, CallerContext $caller, string $mode ): array {
		$this->assert_can_cancel( $caller, $mode );
		$handle       = self::bounded_string( $params, 'taskId', 160 );
		$arguments    = array( 'taskId' => $handle );
		$confirmation = $this->confirmation_from_params( $params );
		if ( '' === $confirmation ) {
			return $this->confirmation_prompt( $caller, $arguments );
		}
		if ( ! $this->confirmations->consume( $confirmation, $caller, 'tasks/cancel', $arguments ) ) {
			throw new \InvalidArgumentException( 'Task cancellation confirmation is invalid or expired.' );
		}
		return array(
			'cancelled' => $this->tasks->cancel( $handle, $caller ),
			'taskId'    => $handle,
		);
	}

	private function assert_can_cancel( CallerContext $caller, string $mode ): void {
		if ( 'operations' !== $mode ) {
			throw new ProtocolException( -32001, 'Task cancellation is unavailable in this MCP mode.' ); }
		if ( ! $caller->can( 'manage_options' ) ) {
			throw new ProtocolException( -32001, 'Task cancellation requires the manage_options capability.' ); }
	}

	/** @param array<string,mixed> $arguments @return array<string,mixed> */
	private function confirmation_prompt( CallerContext $caller, array $arguments ): array {
		$confirmation = $this->confirmations->issue( $caller, 'tasks/cancel', $arguments );
		return array(
			'resultType'    => 'input_required',
			'inputRequests' => array( 'confirmation' => self::confirmation_request( 'Cancel this Cybermaps task?' ) ),
			'requestState'  => $confirmation,
			'inputRequired' => array(
				'confirmation' => $confirmation,
				'expiresIn'    => 300,
			),
		);
	}

	/** @param array<string, mixed> $params */
	private function confirmation_from_params( array $params ): string {
		$legacy = $params['confirmation'] ?? null;
		if ( is_string( $legacy ) && '' !== $legacy ) {
			return $legacy;
		}
		$state = $params['requestState'] ?? null;
		if ( ! is_string( $state ) || '' === $state ) {
			return '';
		}
		if ( ! $this->is_affirmative_confirmation( $params ) ) {
			throw new \InvalidArgumentException( 'An affirmative confirmation response is required.' );
		}
		return $state;
	}

	private function is_affirmative_confirmation( array $params ): bool {
		$responses = $params['inputResponses'] ?? null;
		$response  = is_array( $responses ) ? ( $responses['confirmation'] ?? null ) : null;
		$content   = is_array( $response ) ? ( $response['content'] ?? null ) : null;
		return is_array( $response ) && 'accept' === ( $response['action'] ?? null ) && is_array( $content ) && true === ( $content['confirm'] ?? null );
	}

	/** @return array<string, mixed> */
	private static function confirmation_request( string $message ): array {
		return array(
			'method' => 'elicitation/create',
			'params' => array(
				'mode'            => 'form',
				'message'         => $message,
				'requestedSchema' => array(
					'type'       => 'object',
					'required'   => array( 'confirm' ),
					'properties' => array(
						'confirm' => array(
							'type'        => 'boolean',
							'description' => 'Confirm this operation.',
						),
					),
				),
			),
		);
	}

	/** @param array<string, mixed> $result
	 *  @return array<string, mixed>
	 */
	private function with_result_metadata( array $result ): array {
		if ( ! isset( $result['resultType'] ) ) {
			$result['resultType'] = 'complete';
		}
		$meta                                       = is_array( $result['_meta'] ?? null ) ? $result['_meta'] : array();
		$meta['io.modelcontextprotocol/serverInfo'] = array(
			'name'    => 'Cybermaps',
			'version' => CYBERMAPS_VERSION,
		);
		$result['_meta']                            = $meta;
		return $result;
	}

	/** @param array<string, mixed> $result
	 *  @return array<string, mixed>
	 */
	private static function cacheable_result( array $result ): array {
		$result['ttlMs']      = 0;
		$result['cacheScope'] = 'private';
		return $result;
	}

	/** @param array<string, mixed> $params */
	private static function bounded_string( array $params, string $name, int $maximum ): string {
		$value = $params[ $name ] ?? null;
		if ( ! is_string( $value ) || '' === $value || strlen( $value ) > $maximum ) {
			throw new \InvalidArgumentException( sprintf( '%s must be a non-empty bounded string.', $name ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is returned through JSON encoding, not HTML output.
		}
		return $value;
	}

	/** @return array<string, mixed> */
	public static function error( string|int|null $id, int $code, string $message ): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}
}
