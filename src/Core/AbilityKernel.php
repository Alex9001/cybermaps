<?php
/**
 * Cybermaps-owned read-only WordPress abilities.
 *
 * @package Cybermaps\Core
 */

declare(strict_types=1);

namespace Cybermaps\Core;

use Cybermaps\Discovery\Search;
use Cybermaps\MCP\ResourceAbilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers public search without projecting other plugins’ abilities.
 */
final class AbilityKernel {
	private const READ_CATEGORY = 'cybermaps-discovery';

	private static ?self $instance = null;
	private bool $hooks_registered = false;

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

	/** Register the stable read-only Cybermaps category. */
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
	}

	/** Register only owned read-only handlers; no generic WordPress ability dispatch. */
	public function register_abilities(): void {
		wp_register_ability(
			'cybermaps/search',
			array(
				'label'               => __( 'Search public content', 'cybermaps' ),
				'description'         => __( 'Search the bounded public Cybermaps publication inventory.', 'cybermaps' ),
				'category'            => self::READ_CATEGORY,
				'execute_callback'    => fn( array $input ): mixed => $this->search( $input ),
				'permission_callback' => static fn(): bool => \Cybermaps\Discovery\Integrity::is_hub_enabled(),
				'input_schema'        => array(
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
				'meta'                => array(
					'public'       => true,
					'show_in_rest' => true,
					'mcp'          => array( 'public' => false ),
					'annotations'  => array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);
		ResourceAbilities::register();
	}

	/** Whether the owned public search ability is registered. */
	public function has_public_abilities(): bool {
		return function_exists( 'wp_get_ability' ) && null !== wp_get_ability( 'cybermaps/search' );
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
}
