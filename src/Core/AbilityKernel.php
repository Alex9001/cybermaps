<?php
/**
 * WordPress 7.1 ability registration and channel projection.
 *
 * @package Cybermaps\Core
 */

declare(strict_types=1);

namespace Cybermaps\Core;

use Cybermaps\Discovery\IndexNow;
use Cybermaps\Discovery\Search;
use Cybermaps\Discovery\StaticBridge;
use Cybermaps\MCP\CallerContext;
use Cybermaps\MCP\ToolRegistry;
use Cybermaps\MCP\WordPressTaskService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers Cybermaps abilities and projects public WordPress abilities to clients.
 */
final class AbilityKernel {
	private const READ_CATEGORY       = 'cybermaps-discovery';
	private const OPERATIONS_CATEGORY = 'cybermaps-operations';
	private const THIRD_PARTY_SCOPE   = 'cybermaps:abilities:execute';

	/** @var array<string,string> */
	private const CORE_ABILITY_NAMES = array(
		'cybermaps.audit.run'        => 'cybermaps/run-audit',
		'cybermaps.indexnow.submit'  => 'cybermaps/submit-indexnow',
		'cybermaps.search'           => 'cybermaps/search',
		'cybermaps.static.purge'     => 'cybermaps/purge-static-publications',
		'cybermaps.static.reconcile' => 'cybermaps/reconcile-static-publications',
	);

	private static ?self $instance               = null;
	private static ?CallerContext $active_caller = null;
	private bool $hooks_registered               = false;

	public static function get_instance(): self {
		self::$instance ??= new self();
		return self::$instance;
	}

	/** Register before Core initializes the Abilities API registries. */
	public function register_hooks(): void {
		if ( $this->hooks_registered ) {
			return;
		}

		$this->hooks_registered = true;
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_categories' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/** Register the two stable Cybermaps ability categories. */
	public function register_categories(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::READ_CATEGORY,
			array(
				'label'       => __( 'Cybermaps Discovery', 'cybermaps' ),
				'description' => __( 'Read-only public discovery and site-search abilities.', 'cybermaps' ),
			)
		);
		wp_register_ability_category(
			self::OPERATIONS_CATEGORY,
			array(
				'label'       => __( 'Cybermaps Operations', 'cybermaps' ),
				'description' => __( 'Administrator-authorized publication and audit operations.', 'cybermaps' ),
			)
		);
	}

	/** Register the canonical Cybermaps abilities from the MCP tool contract. */
	public function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		foreach ( ToolRegistry::core_definitions() as $tool_name => $definition ) {
			$ability_name = self::CORE_ABILITY_NAMES[ $tool_name ] ?? '';
			if ( '' === $ability_name ) {
				continue;
			}
			wp_register_ability( $ability_name, $this->registration_args( $tool_name, $definition ) );
		}
	}

	/**
	 * Return public abilities after applying the WordPress 7.1 metadata filter.
	 *
	 * @return array<string,object>
	 */
	public function public_abilities( string $channel = '' ): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array();
		}

		$abilities = wp_get_abilities( array( 'meta' => array( 'public' => true ) ) );
		if ( '' === $channel ) {
			return $abilities;
		}

		return array_filter(
			$abilities,
			fn( object $ability ): bool => $this->is_public_in_channel( $ability, $channel )
		);
	}

	/** Whether at least one public ability is available. */
	public function has_public_abilities(): bool {
		return array() !== $this->public_abilities();
	}

	/**
	 * Project non-Cybermaps public abilities into MCP tool definitions.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public function mcp_tool_definitions( string $mode, CallerContext $caller ): array {
		unset( $caller );
		if ( ! in_array( $mode, array( 'read_only', 'operations' ), true ) ) {
			return array();
		}

		$definitions = array();
		foreach ( $this->public_abilities( 'mcp' ) as $ability ) {
			$name = $this->ability_name( $ability );
			if ( str_starts_with( $name, 'cybermaps/' ) ) {
				continue;
			}
			$definition = $this->tool_definition( $ability );
			if ( 'operations' !== $mode && 'operations' === $definition['mode'] ) {
				continue;
			}
			$definitions[ $this->external_name( $name ) ] = $definition;
		}

		return $definitions;
	}

	/**
	 * Return browser-safe, read-only public ability descriptors.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function webmcp_catalog(): array {
		$catalog = array();
		foreach ( $this->public_abilities( 'webmcp' ) as $ability ) {
			$name        = $this->ability_name( $ability );
			$annotations = $this->annotations( $ability );
			if ( str_starts_with( $name, 'cybermaps/' ) || empty( $annotations['readonly'] ) ) {
				continue;
			}
			$catalog[] = array(
				'name'        => $this->external_name( $name ),
				'description' => $this->ability_description( $ability ),
				'inputSchema' => $this->client_schema( $this->ability_schema( $ability, 'input' ) ),
				'runUrl'      => rest_url( 'wp-abilities/v1/abilities/' . $name . '/run' ),
			);
		}

		return $catalog;
	}

	/** Execute one public ability selected by its projected MCP name. */
	public function execute_tool( string $external_name, array $arguments, CallerContext $caller ): mixed {
		foreach ( $this->public_abilities( 'mcp' ) as $ability ) {
			if ( $external_name !== $this->external_name( $this->ability_name( $ability ) ) ) {
				continue;
			}

			return $this->execute_as_caller( $ability, $arguments, $caller );
		}

		throw new \RuntimeException( 'The public WordPress ability is no longer available.' );
	}

	/** @param array<string,mixed> $definition */
	private function registration_args( string $tool_name, array $definition ): array {
		$readonly = ! empty( $definition['annotations']['readOnlyHint'] );
		return array(
			'label'               => $this->core_label( $tool_name ),
			'description'         => (string) $definition['description'],
			'category'            => $readonly ? self::READ_CATEGORY : self::OPERATIONS_CATEGORY,
			'execute_callback'    => fn( mixed $input ): mixed => $this->execute_core( $tool_name, $input ),
			'permission_callback' => static fn(): bool => $readonly || current_user_can( 'manage_options' ),
			'input_schema'        => $this->wordpress_schema( (array) $definition['inputSchema'] ),
			'output_schema'       => $this->wordpress_schema( (array) $definition['outputSchema'] ),
			'meta'                => array(
				'public'       => true,
				'show_in_rest' => true,
				'mcp'          => array( 'public' => true ),
				'webmcp'       => array( 'public' => $readonly ),
				'annotations'  => array(
					'readonly'    => $readonly,
					'destructive' => ! empty( $definition['annotations']['destructiveHint'] ),
					'idempotent'  => ! empty( $definition['annotations']['idempotentHint'] ),
				),
				'cybermaps'    => array(
					'tool_name' => $tool_name,
					'mode'      => (string) $definition['mode'],
					'scope'     => (string) $definition['scope'],
				),
			),
		);
	}

	private function execute_core( string $tool_name, mixed $input ): mixed {
		$arguments = is_array( $input ) ? $input : array();
		try {
			return match ( $tool_name ) {
				'cybermaps.search'           => $this->search( $arguments ),
				'cybermaps.indexnow.submit'  => ( new IndexNow() )->submit_urls( (array) ( $arguments['urls'] ?? array() ), true ),
				'cybermaps.static.purge'     => StaticBridge::get_instance()->cancel_and_purge( 'all' ),
				'cybermaps.audit.run'        => $this->start_task( 'audit', $arguments ),
				'cybermaps.static.reconcile' => $this->start_task( 'static_reconcile', $arguments ),
				default                       => new \WP_Error( 'cybermaps_unknown_ability', __( 'Unknown Cybermaps ability.', 'cybermaps' ) ),
			};
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'cybermaps_ability_failed', $error->getMessage() );
		}
	}

	/** @param array<string,mixed> $arguments */
	private function search( array $arguments ): array|\WP_Error {
		$request = new \WP_REST_Request( 'GET', '/cybermaps/v1/search' );
		$request->set_param( 'q', (string) ( $arguments['q'] ?? '' ) );
		$request->set_param( 'limit', (int) ( $arguments['limit'] ?? 10 ) );
		$response = ( new Search() )->handle_search( $request );
		if ( $response instanceof \WP_REST_Response ) {
			$data = $response->get_data();
			return is_array( $data ) ? $data : array();
		}
		return is_wp_error( $response ) ? $response : array();
	}

	/** @param array<string,mixed> $arguments */
	private function start_task( string $type, array $arguments ): array {
		$caller = self::$active_caller ?? $this->wordpress_caller();
		return ( new WordPressTaskService() )->start( $type, $arguments, $caller );
	}

	private function wordpress_caller(): CallerContext {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		return new CallerContext(
			(string) $user_id,
			'wp-abilities',
			array( 'cybermaps:read', 'cybermaps:audit', 'cybermaps:publish', 'cybermaps:purge', self::THIRD_PARTY_SCOPE ),
			static fn( string $capability ): bool => '' === $capability || current_user_can( $capability )
		);
	}

	private function execute_as_caller( object $ability, array $arguments, CallerContext $caller ): mixed {
		$previous_caller     = self::$active_caller;
		$previous_user       = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$user_id             = ctype_digit( $caller->subject ) ? (int) $caller->subject : 0;
		self::$active_caller = $caller;
		if ( function_exists( 'wp_set_current_user' ) ) {
			wp_set_current_user( $user_id );
		}

		try {
			$result = $ability->execute( $arguments );
		} finally {
			self::$active_caller = $previous_caller;
			if ( function_exists( 'wp_set_current_user' ) ) {
				wp_set_current_user( $previous_user );
			}
		}

		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException( $result->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- JSON-RPC transport encodes this message; it is never rendered as HTML.
		}
		if ( $result instanceof \WP_REST_Response ) {
			return $result->get_data();
		}
		return $result;
	}

	/** @return array<string,mixed> */
	private function tool_definition( object $ability ): array {
		$annotations = $this->annotations( $ability );
		$readonly    = ! empty( $annotations['readonly'] );
		return array(
			'description'  => $this->ability_description( $ability ),
			'inputSchema'  => $this->client_schema( $this->ability_schema( $ability, 'input' ) ),
			'outputSchema' => $this->client_schema( $this->ability_schema( $ability, 'output' ) ),
			'annotations'  => array(
				'readOnlyHint'    => $readonly,
				'destructiveHint' => ! empty( $annotations['destructive'] ),
				'idempotentHint'  => ! empty( $annotations['idempotent'] ),
			),
			'mode'         => $readonly ? 'read_only' : 'operations',
			'scope'        => $readonly ? '' : self::THIRD_PARTY_SCOPE,
			'capability'   => '',
			'mutating'     => ! $readonly,
			'ability_name' => $this->ability_name( $ability ),
		);
	}

	private function is_public_in_channel( object $ability, string $channel ): bool {
		$meta = method_exists( $ability, 'get_meta' ) ? (array) $ability->get_meta() : array();
		if ( array_key_exists( $channel, $meta ) ) {
			$value = $meta[ $channel ];
			if ( is_bool( $value ) ) {
				return $value;
			}
			if ( is_array( $value ) && array_key_exists( 'public', $value ) ) {
				return true === $value['public'];
			}
		}
		return true === ( $meta['public'] ?? false );
	}

	/** @return array<string,mixed> */
	private function annotations( object $ability ): array {
		$meta = method_exists( $ability, 'get_meta' ) ? (array) $ability->get_meta() : array();
		return is_array( $meta['annotations'] ?? null ) ? $meta['annotations'] : array();
	}

	/** @return array<string,mixed> */
	private function ability_schema( object $ability, string $direction ): array {
		$method = 'input' === $direction ? 'get_input_schema' : 'get_output_schema';
		$schema = method_exists( $ability, $method ) ? $ability->{$method}() : array();
		return is_array( $schema ) && array() !== $schema
			? $schema
			: array(
				'type'       => 'object',
				'properties' => array(),
			);
	}

	/** @param array<string,mixed> $schema
	 *  @return array<string,mixed>
	 */
	private function client_schema( array $schema ): array {
		return function_exists( 'wp_prepare_json_schema_for_client' )
			? wp_prepare_json_schema_for_client( $schema, 'draft-04' )
			: $schema;
	}

	/** @param array<string,mixed> $schema
	 *  @return array<string,mixed>
	 */
	private function wordpress_schema( array $schema ): array {
		unset( $schema['$schema'] );
		return $schema;
	}

	private function ability_name( object $ability ): string {
		return method_exists( $ability, 'get_name' ) ? (string) $ability->get_name() : '';
	}

	private function ability_description( object $ability ): string {
		return method_exists( $ability, 'get_description' ) ? (string) $ability->get_description() : '';
	}

	private function external_name( string $ability_name ): string {
		$name = 'wp.' . str_replace( '/', '.', strtolower( $ability_name ) );
		return (string) preg_replace( '/[^a-z0-9_.-]/', '-', $name );
	}

	private function core_label( string $tool_name ): string {
		return match ( $tool_name ) {
			'cybermaps.audit.run'        => __( 'Run Cybermaps Audit', 'cybermaps' ),
			'cybermaps.indexnow.submit'  => __( 'Submit URLs to IndexNow', 'cybermaps' ),
			'cybermaps.search'           => __( 'Search Cybermaps Publications', 'cybermaps' ),
			'cybermaps.static.purge'     => __( 'Purge Static Publications', 'cybermaps' ),
			'cybermaps.static.reconcile' => __( 'Reconcile Static Publications', 'cybermaps' ),
			default                       => __( 'Cybermaps Ability', 'cybermaps' ),
		};
	}
}
