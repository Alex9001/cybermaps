<?php
declare(strict_types=1);

namespace Cybermaps\MCP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Deterministic registry and dispatcher for the five allowlisted Core tools. */
final class ToolRegistry {
	/** @var array<string, \Closure> */
	private array $executors = array();

	/**
	 * @param array<string, callable> $executors Synchronous operation adapters.
	 */
	public function __construct(
		array $executors,
		private readonly TaskServiceInterface $tasks,
		private readonly ConfirmationServiceInterface $confirmations,
		private readonly SchemaValidator $validator = new SchemaValidator(),
		private readonly ?\Cybermaps\Core\AbilityKernel $abilities = null
	) {
		foreach ( $executors as $name => $executor ) {
			if ( is_string( $name ) && is_callable( $executor ) ) {
				$this->executors[ $name ] = \Closure::fromCallable( $executor );
			}
		}
	}

	/** @return array<int, array<string, mixed>> */
	public function list( string $mode, CallerContext $caller ): array {
		$tools = array();
		foreach ( $this->definitions( $mode, $caller ) as $name => $definition ) {
			if ( ! $this->is_visible( $name, $definition, $mode, $caller ) ) {
				continue;
			}
			unset( $definition['mode'], $definition['scope'], $definition['capability'], $definition['task_type'], $definition['mutating'], $definition['ability_name'] );
			$tools[] = array_merge( array( 'name' => $name ), $definition );
		}
		return $tools;
	}

	/**
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @return array<string, mixed>
	 */
	public function call( string $name, array $arguments, string $confirmation, string $mode, CallerContext $caller ): array {
		$definitions = $this->definitions( $mode, $caller );
		$definition  = $definitions[ $name ] ?? null;
		if ( ! is_array( $definition ) ) {
			throw new ProtocolException( -32004, 'Tool is not registered.' );
		}
		if ( ! $this->is_visible( $name, $definition, $mode, $caller ) ) {
			throw new ProtocolException( -32001, 'Tool is unavailable in this mode or to this caller.' );
		}

		$errors = $this->validator->validate( $arguments, $definition['inputSchema'] );
		if ( array() !== $errors ) {
			throw new \InvalidArgumentException( implode( ' ', $errors ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message is returned through JSON encoding, not HTML output.
		}

		if ( ! empty( $definition['mutating'] ) ) {
			if ( '' === $confirmation ) {
				return $this->confirmation_required( $caller, $name, $arguments );
			}
			if ( ! $this->confirmations->consume( $confirmation, $caller, $name, $arguments ) ) {
				throw new \InvalidArgumentException( 'Confirmation is invalid, expired, mismatched, or already used.' );
			}
		}

		$result = $this->dispatch( $name, $arguments, $definition, $caller );
		return $this->tool_result( $result );
	}

	/** @param array<string,mixed> $arguments
	 *  @param array<string,mixed> $definition
	 */
	private function dispatch( string $name, array $arguments, array $definition, CallerContext $caller ): mixed {
		if ( isset( $definition['task_type'] ) ) {
			return $this->tasks->start( (string) $definition['task_type'], $arguments, $caller );
		}
		if ( isset( $this->executors[ $name ] ) ) {
			return ( $this->executors[ $name ] )( $arguments, $caller );
		}
		if ( isset( $definition['ability_name'] ) ) {
			return $this->ability_kernel()->execute_tool( $name, $arguments, $caller );
		}
		throw new ProtocolException( -32004, 'Tool executor is not registered.' );
	}

	/** @return array<string, array<string, mixed>> */
	public static function core_definitions(): array {
		$schema = 'https://json-schema.org/draft/2020-12/schema';
		return array(
			'cybermaps.audit.run'        => array(
				'description'  => 'Start one bounded Cybermaps audit task.',
				'inputSchema'  => array(
					'$schema'              => $schema,
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
				),
				'outputSchema' => self::output_schema( $schema ),
				'annotations'  => array(
					'readOnlyHint'    => false,
					'destructiveHint' => false,
					'idempotentHint'  => false,
				),
				'mode'         => 'operations',
				'scope'        => 'cybermaps:audit',
				'capability'   => 'manage_options',
				'mutating'     => true,
				'task_type'    => 'audit',
			),
			'cybermaps.indexnow.submit'  => array(
				'description'  => 'Submit same-host canonical URLs to the bounded IndexNow queue.',
				'inputSchema'  => array(
					'$schema'              => $schema,
					'type'                 => 'object',
					'required'             => array( 'urls' ),
					'properties'           => array(
						'urls' => array(
							'type'     => 'array',
							'minItems' => 1,
							'maxItems' => 10000,
							'items'    => array(
								'type'      => 'string',
								'minLength' => 1,
								'maxLength' => 2048,
							),
						),
					),
					'additionalProperties' => false,
				),
				'outputSchema' => self::output_schema( $schema ),
				'annotations'  => array(
					'readOnlyHint'    => false,
					'destructiveHint' => false,
					'idempotentHint'  => true,
				),
				'mode'         => 'operations',
				'scope'        => 'cybermaps:publish',
				'capability'   => 'manage_options',
				'mutating'     => true,
			),
			'cybermaps.search'           => array(
				'description'  => 'Search the public Cybermaps URL index.',
				'inputSchema'  => array(
					'$schema'              => $schema,
					'type'                 => 'object',
					'required'             => array( 'q' ),
					'properties'           => array(
						'q'     => array(
							'type'      => 'string',
							'minLength' => 1,
							'maxLength' => 200,
						),
						'limit' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
						),
					),
					'additionalProperties' => false,
				),
				'outputSchema' => self::output_schema( $schema ),
				'annotations'  => array(
					'readOnlyHint'    => true,
					'destructiveHint' => false,
					'idempotentHint'  => true,
				),
				'mode'         => 'read_only',
				'scope'        => '',
				'capability'   => '',
				'mutating'     => false,
			),
			'cybermaps.static.purge'     => array(
				'description'  => 'Purge only Cybermaps-owned static publications.',
				'inputSchema'  => array(
					'$schema'              => $schema,
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
				),
				'outputSchema' => self::output_schema( $schema ),
				'annotations'  => array(
					'readOnlyHint'    => false,
					'destructiveHint' => true,
					'idempotentHint'  => true,
				),
				'mode'         => 'operations',
				'scope'        => 'cybermaps:purge',
				'capability'   => 'manage_options',
				'mutating'     => true,
			),
			'cybermaps.static.reconcile' => array(
				'description'  => 'Start one ownership-safe static reconciliation task.',
				'inputSchema'  => array(
					'$schema'              => $schema,
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
				),
				'outputSchema' => self::output_schema( $schema ),
				'annotations'  => array(
					'readOnlyHint'    => false,
					'destructiveHint' => false,
					'idempotentHint'  => true,
				),
				'mode'         => 'operations',
				'scope'        => 'cybermaps:publish',
				'capability'   => 'manage_options',
				'mutating'     => true,
				'task_type'    => 'static_reconcile',
			),
		);
	}

	/** @return array<string, mixed> */
	private static function output_schema( string $schema ): array {
		return array(
			'$schema' => $schema,
			'type'    => 'object',
		);
	}

	/** @param array<string, mixed> $definition */
	private function is_visible( string $name, array $definition, string $mode, CallerContext $caller ): bool {
		$levels = array(
			'off'        => 0,
			'discovery'  => 1,
			'read_only'  => 2,
			'operations' => 3,
		);
		if ( ( $levels[ $mode ] ?? 0 ) < ( $levels[ (string) $definition['mode'] ] ?? 4 ) ) {
			return false;
		}
		if ( ! $caller->has_scope( (string) $definition['scope'] ) || ! $caller->can( (string) $definition['capability'] ) ) {
			return false;
		}
		if ( isset( $definition['task_type'] ) ) {
			return $this->tasks->supports( (string) $definition['task_type'] );
		}
		if ( isset( $definition['ability_name'] ) ) {
			return true;
		}
		return isset( $this->executors[ $name ] );
	}

	/** @return array<string,array<string,mixed>> */
	private function definitions( string $mode, CallerContext $caller ): array {
		return array_merge( self::core_definitions(), $this->ability_kernel()->mcp_tool_definitions( $mode, $caller ) );
	}

	private function ability_kernel(): \Cybermaps\Core\AbilityKernel {
		return $this->abilities ?? \Cybermaps\Core\AbilityKernel::get_instance();
	}

	/** @param array<string, mixed> $arguments
	 *  @return array<string, mixed>
	 */
	private function confirmation_required( CallerContext $caller, string $name, array $arguments ): array {
		$confirmation = $this->confirmations->issue( $caller, $name, $arguments );
		return array(
			'resultType'    => 'input_required',
			'inputRequests' => array(
				'confirmation' => array(
					'method' => 'elicitation/create',
					'params' => array(
						'mode'            => 'form',
						'message'         => 'Confirm this Cybermaps operation.',
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
				),
			),
			'requestState'  => $confirmation,
			'content'       => array(
				array(
					'type' => 'text',
					'text' => 'Explicit confirmation is required before this mutation.',
				),
			),
			'isError'       => false,
			'inputRequired' => array(
				'confirmation' => $confirmation,
				'expiresIn'    => 300,
			),
		);
	}

	/** @return array<string, mixed> */
	private function tool_result( mixed $result ): array {
		$structured = is_array( $result ) ? $result : array( 'result' => $result );
		$json       = wp_json_encode( $structured );
		if ( ! is_string( $json ) || strlen( $json ) > 524288 ) {
			throw new \RuntimeException( 'Tool output exceeded the bounded response size.' );
		}
		return array(
			'resultType'        => 'complete',
			'content'           => array(
				array(
					'type' => 'text',
					'text' => $json,
				),
			),
			'structuredContent' => $structured,
			'isError'           => false,
		);
	}
}
