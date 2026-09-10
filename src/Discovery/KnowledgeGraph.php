<?php
declare(strict_types=1);
namespace Cybermaps\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Knowledge Graph Handler
 *
 * Handles requests for /knowledge-graph.json.
 */
class KnowledgeGraph {
	/**
	 * Handle requests for /knowledge-graph.json.
	 *
	 * @return void
	 */
	public function handle() {
		$path = \Cybermaps\Core\URLManager::get_request_path();
		if ( '/knowledge-graph.json' !== $path ) {
			return;
		}

		$settings = \Cybermaps\Core\ConfigurationStore::settings();
		if ( empty( $settings['enable_discovery_hub'] ) ) {
			return;
		}

		$output = $this->get_json_content();

		Integrity::send_headers( $output );
		header( 'Content-Type: application/ld+json' );
		if ( ! \Cybermaps\Core\ReadOnlyRequest::is_head() ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo $output;
		}
		exit;
	}

	/**
	 * Build knowledge graph JSON (for HTTP and static file sync).
	 *
	 * @return string
	 */
	public function get_json_content(): string {
		$settings = \Cybermaps\Core\ConfigurationStore::settings();

		$expose_admin = ! empty( $settings['ai_kg_expose_admin'] );
		$link_org     = ! empty( $settings['ai_kg_link_org'] );
		$identity     = self::identity_graph();
		$graph        = $identity['graph'];
		$silos        = self::empty_silos();

		self::append_post_type_items( $silos );
		self::append_taxonomy_items( $silos );
		self::append_populated_silos( $graph, $silos );
		$graph[] = self::website_entity( $link_org, $identity['entity_id'] );

		if ( $expose_admin ) {
			$author = self::admin_author_entity();
			if ( null !== $author ) {
				$graph[] = $author;
			}
		}

		$data = array(
			'@context' => 'https://schema.org',
			'@graph'   => $graph,
		);

		$data = apply_filters( 'cybermaps_knowledge_graph_data', $data );

		return (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * Build the optional primary identity graph prefix.
	 *
	 * @return array{entity_id:string,graph:array<int, array<string, mixed>>}
	 */
	private static function identity_graph(): array {
		$identity_data = \Cybermaps\Core\ConfigurationStore::identity();
		$identity_name = self::text_value( $identity_data['name'] ?? '' );
		if ( '' === $identity_name ) {
			return array(
				'entity_id' => '',
				'graph'     => array(),
			);
		}

		$main_entity = \Cybermaps\Core\IdentityEntityBuilder::build( $identity_data );
		return array(
			'entity_id' => (string) $main_entity['@id'],
			'graph'     => array( $main_entity ),
		);
	}

	/**
	 * Create empty intent inventories in publication order.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function empty_silos(): array {
		return array(
			'informational' => array(
				'@type'           => 'ItemList',
				'@id'             => \Cybermaps\Core\URLManager::get_home_url( '/#informational-silo' ),
				'name'            => __( 'Informational Content Inventory', 'cybermaps' ),
				'description'     => __( 'Public content types and taxonomies classified as informational by Cybermaps.', 'cybermaps' ),
				'itemListElement' => array(),
			),
			'transactional' => array(
				'@type'           => 'ItemList',
				'@id'             => \Cybermaps\Core\URLManager::get_home_url( '/#transactional-silo' ),
				'name'            => __( 'Transactional Content Inventory', 'cybermaps' ),
				'description'     => __( 'Public content types and taxonomies classified as transactional by Cybermaps.', 'cybermaps' ),
				'itemListElement' => array(),
			),
		);
	}

	/**
	 * Append eligible public post types to their intent inventories.
	 *
	 * @param array<string, array<string, mixed>> $silos Intent inventories.
	 */
	private static function append_post_type_items( array &$silos ): void {
		$all_public_types = \Cybermaps\Core\PublicationPostTypes::names();
		foreach ( $all_public_types as $pt ) {
			$item = self::post_type_silo_item( $pt, $silos );
			if ( null === $item ) {
				continue;
			}
			self::append_silo_item( $silos[ $item['intent'] ], $item['item'] );
		}
	}

	/**
	 * Build one post-type inventory item.
	 *
	 * @param array<string, array<string, mixed>> $silos Intent inventories.
	 * @return array{intent:string,item:array<string,mixed>}|null
	 */
	private static function post_type_silo_item( mixed $value, array $silos ): ?array {
		$post_type = is_scalar( $value ) ? sanitize_key( (string) $value ) : '';
		if ( '' === $post_type ) {
			return null;
		}

		$provider_id = \Cybermaps\Sitemap\ProviderIdentity::post_type( $post_type );
		$intent      = self::eligible_intent( $provider_id, $silos );
		$object      = get_post_type_object( $post_type );
		if ( '' === $intent || ! self::is_publicly_queryable_object( $object ) ) {
			return null;
		}

		$label = self::object_label( $object );
		if ( '' === $label ) {
			return null;
		}

		$item        = array(
			'@type'       => 'ListItem',
			'name'        => $label,
			/* translators: %s: public post type label. */
			'description' => sprintf( __( 'Public %s content type.', 'cybermaps' ), $label ),
		);
		$archive_url = self::post_type_archive_url( $post_type );
		if ( '' !== $archive_url ) {
			$item['url'] = $archive_url;
		}

		return array(
			'intent' => $intent,
			'item'   => $item,
		);
	}

	/**
	 * Return an eligible intent silo for one sitemap provider.
	 *
	 * @param array<string, array<string, mixed>> $silos Intent inventories.
	 */
	private static function eligible_intent( string $provider_id, array $silos ): string {
		if ( \Cybermaps\Sitemap\PriorityEngine::calculate( $provider_id ) <= 0 ) {
			return '';
		}

		$intent = (string) \Cybermaps\Discovery\IntentEngine::calculate( $provider_id, 'type' );
		return isset( $silos[ $intent ] ) ? $intent : '';
	}

	/**
	 * Return the public archive URL for one post type.
	 */
	private static function post_type_archive_url( string $post_type ): string {
		$archive_url = function_exists( 'get_post_type_archive_link' )
			? get_post_type_archive_link( $post_type )
			: false;

		return self::public_url(
			\Cybermaps\Core\URLManager::rewrite_url(
				is_string( $archive_url ) ? $archive_url : ''
			)
		);
	}

	/**
	 * Append eligible public taxonomies to their intent inventories.
	 *
	 * @param array<string, array<string, mixed>> $silos Intent inventories.
	 */
	private static function append_taxonomy_items( array &$silos ): void {
		$all_public_tax = (array) get_taxonomies( array( 'public' => true ) );
		foreach ( $all_public_tax as $tax ) {
			$item = self::taxonomy_silo_item( $tax, $silos );
			if ( null === $item ) {
				continue;
			}
			self::append_silo_item( $silos[ $item['intent'] ], $item['item'] );
		}
	}

	/**
	 * Build one taxonomy inventory item.
	 *
	 * @param array<string, array<string, mixed>> $silos Intent inventories.
	 * @return array{intent:string,item:array<string,mixed>}|null
	 */
	private static function taxonomy_silo_item( mixed $value, array $silos ): ?array {
		$taxonomy = is_scalar( $value ) ? sanitize_key( (string) $value ) : '';
		if ( '' === $taxonomy ) {
			return null;
		}

		$provider_id = \Cybermaps\Sitemap\ProviderIdentity::taxonomy( $taxonomy );
		$intent      = self::eligible_intent( $provider_id, $silos );
		$object      = get_taxonomy( $taxonomy );
		if ( '' === $intent || ! self::is_publicly_queryable_object( $object ) ) {
			return null;
		}

		$label = self::object_label( $object );
		if ( '' === $label ) {
			return null;
		}

		// WordPress exposes term archives, not one truthful archive URL
		// for a taxonomy itself. Keep the classification without inventing
		// a homepage or rewrite-base URL that may not resolve.
		return array(
			'intent' => $intent,
			'item'   => array(
				'@type'       => 'ListItem',
				'name'        => $label,
				/* translators: %s: public taxonomy label. */
				'description' => sprintf( __( 'Public %s taxonomy.', 'cybermaps' ), $label ),
			),
		);
	}

	/**
	 * Read a bounded object label with the original fallback order.
	 */
	private static function object_label( object $entity_object ): string {
		$fallback = is_object( $entity_object->labels ?? null ) ? $entity_object->labels->name ?? '' : '';
		return self::text_value( $entity_object->label ?? $fallback );
	}

	/**
	 * Append nonempty silos to the graph in inventory order.
	 *
	 * @param array<int, array<string, mixed>>    $graph Graph entities.
	 * @param array<string, array<string, mixed>> $silos Intent inventories.
	 */
	private static function append_populated_silos( array &$graph, array $silos ): void {
		foreach ( $silos as $silo ) {
			if ( empty( $silo['itemListElement'] ) ) {
				continue;
			}
			$silo['numberOfItems'] = count( $silo['itemListElement'] );
			$graph[]               = $silo;
		}
	}

	/**
	 * Build the WebSite entity.
	 *
	 * @return array<string, mixed>
	 */
	private static function website_entity( bool $link_org, string $entity_id ): array {
		$website      = array(
			'@type' => 'WebSite',
			'@id'   => \Cybermaps\Core\URLManager::get_home_url( '/#website' ),
			'url'   => \Cybermaps\Core\URLManager::get_home_url( '/' ),
		);
		$website_name = self::text_value( get_bloginfo( 'name' ) );
		if ( '' !== $website_name ) {
			$website['name'] = $website_name;
		}

		$search_url = self::public_url(
			\Cybermaps\Core\EndpointRegistry::get_instance()->get_url( 'rest_search' )
		);
		if ( '' !== $search_url ) {
			$website['potentialAction'] = array(
				'@type'       => 'SearchAction',
				'target'      => array(
					'@type'       => 'EntryPoint',
					'urlTemplate' => \Cybermaps\Core\URLManager::append_query_template(
						$search_url,
						'q={search_term_string}'
					),
				),
				'query-input' => 'required name=search_term_string',
			);
		}

		if ( $link_org && '' !== $entity_id ) {
			$website['publisher'] = array(
				'@id' => $entity_id,
			);
		}

		return $website;
	}

	/**
	 * Build the optional administrator author entity.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function admin_author_entity(): ?array {
		$admin_email  = self::admin_email();
		$user         = '' !== $admin_email ? get_user_by( 'email', $admin_email ) : false;
		$raw_user_id  = is_object( $user ) ? $user->ID ?? null : null;
		$user_id      = self::positive_user_id( $raw_user_id );
		$display_name = is_object( $user ) ? self::text_value( $user->display_name ?? '' ) : '';
		if ( $user_id <= 0 || '' === $display_name ) {
			return null;
		}

		$author     = array(
			'@type' => 'Person',
			'@id'   => \Cybermaps\Core\URLManager::get_home_url( '/#author' ),
			'name'  => $display_name,
		);
		$author_url = self::public_url(
			\Cybermaps\Core\URLManager::rewrite_url( get_author_posts_url( $user_id ) )
		);
		if ( '' !== $author_url ) {
			$author['url'] = $author_url;
		}

		return $author;
	}

	/**
	 * Read the bounded administrator email option.
	 */
	private static function admin_email(): string {
		$stored_admin_email = get_option( 'admin_email' );
		return is_scalar( $stored_admin_email )
			? sanitize_email(
				\Cybermaps\Discovery\PublicationConstraints::bounded_text(
					$stored_admin_email,
					\Cybermaps\Core\IdentityEntityBuilder::MAX_EMAIL_LENGTH
				)
			)
			: '';
	}

	/**
	 * Normalize a strict positive WordPress user ID.
	 */
	private static function positive_user_id( mixed $raw_user_id ): int {
		return ( is_int( $raw_user_id ) || is_string( $raw_user_id ) )
			&& 1 === preg_match( '/^[1-9][0-9]*$/', trim( (string) $raw_user_id ) )
			&& trim( (string) $raw_user_id ) === (string) (int) $raw_user_id
			? (int) $raw_user_id
			: 0;
	}

	/**
	 * Add a positioned item to one intent inventory.
	 *
	 * @param array<string, mixed> $silo Intent inventory.
	 * @param array<string, mixed> $item Item to append.
	 */
	private static function append_silo_item( array &$silo, array $item ): void {
		$item['position']          = count( $silo['itemListElement'] ) + 1;
		$silo['itemListElement'][] = $item;
	}

	private static function text_value( mixed $value ): string {
		return \Cybermaps\Discovery\PublicationConstraints::bounded_text(
			is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '',
			\Cybermaps\Core\IdentityEntityBuilder::MAX_TEXT_LENGTH
		);
	}

	/**
	 * Public registration alone does not guarantee a front-end query surface.
	 *
	 * Older test doubles and third-party registration objects may omit the
	 * property, so absence retains WordPress's public-registration result.
	 */
	private static function is_publicly_queryable_object( mixed $query_object ): bool {
		return is_object( $query_object )
			&& (
				! property_exists( $query_object, 'publicly_queryable' )
				|| true === (bool) $query_object->publicly_queryable
			);
	}

	private static function public_url( mixed $value ): string {
		return \Cybermaps\Core\URLManager::sanitize_http_url(
			\Cybermaps\Discovery\PublicationConstraints::bounded_text(
				$value,
				\Cybermaps\Core\IdentityEntityBuilder::MAX_URL_LENGTH
			)
		);
	}
}
