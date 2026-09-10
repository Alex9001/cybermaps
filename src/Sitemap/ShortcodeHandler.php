<?php
declare(strict_types=1);
namespace Cybermaps\Sitemap;

use Cybermaps\SEO\PublicationEligibility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode Handler for [cybermap].
 *
 * Generates a human-readable HTML sitemap with hierarchical or flat display.
 * Supports post types, taxonomies, wildcard exclusions, depth control,
 * item limits, sort order, nofollow links, title toggling, and layout mode.
 */
class ShortcodeHandler {
	private const QUERY_BATCH_SIZE       = 250;
	private const MAX_SCAN_ITEMS         = 5000;
	private const MAX_RENDER_ITEMS       = 500;
	private const MAX_ONLY_BYTES         = 4096;
	private const MAX_EXCLUDE_BYTES      = 8192;
	private const MAX_FILTER_TOKENS      = 200;
	private const MAX_FILTER_TOKEN_BYTES = 200;

	private array $current_wildcards = array();
	private array $current_exacts    = array();
	private int $rendered_count      = 0;
	private bool $add_nofollow       = false;
	private bool $css_enqueued       = false;

	/**
	 * Register the [cybermap] shortcode.
	 */
	public static function register_shortcode(): void {
		add_shortcode( 'cybermap', array( new self(), 'render_shortcode' ) );
	}

	/**
	 * Filter the SQL WHERE clause to handle wildcards and exact slugs.
	 */
	public function filter_wildcards( string $where ): string {
		global $wpdb;
		foreach ( $this->current_wildcards as $wildcard ) {
			$escaped = str_replace(
				array( '\\', '_', '%' ),
				array( '\\\\', '\\_', '\\%' ),
				$wildcard
			);
			$where  .= $wpdb->prepare(
				" AND {$wpdb->posts}.post_name NOT LIKE %s",
				str_replace( '*', '%', $escaped )
			);
		}
		foreach ( $this->current_exacts as $exact ) {
			$where .= $wpdb->prepare( " AND {$wpdb->posts}.post_name != %s", $exact );
		}
		return $where;
	}

	/**
	 * Enqueue minimal frontend stylesheet once per request.
	 */
	private function enqueue_styles(): void {
		if ( $this->css_enqueued ) {
			return;
		}
		wp_enqueue_style(
			'cybermaps-sitemap',
			CYBERMAPS_PLUGIN_URL . 'assets/css/sitemaps/cybermap-shortcode.css',
			array(),
			CYBERMAPS_VERSION
		);
		$this->css_enqueued = true;
	}

	/**
	 * Render the shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public function render_shortcode( array $atts ): string {
		$options = \Cybermaps\Core\ConfigurationStore::settings();
		if ( empty( $options['enable_shortcode'] ) ) {
			return '';
		}

		$this->enqueue_styles();
		$config = $this->get_render_config( $atts );

		$this->add_nofollow = $config['add_nofollow'];

		$exclusions = $this->get_exclusions( $config['exclude'] );

		list( $post_types, $taxonomies ) = $this->get_selected_types( $config['only'] );

		$this->current_wildcards = $exclusions['wildcards'];
		$this->current_exacts    = $exclusions['exacts'];

		$eligibility        = new PublicationEligibility();
		$sections           = array();
		$remaining_capacity = $config['limit'];
		$this->append_post_sections(
			$sections,
			$remaining_capacity,
			$post_types,
			$exclusions['ids'],
			$config,
			$eligibility
		);
		$this->append_taxonomy_sections(
			$sections,
			$remaining_capacity,
			$taxonomies,
			$exclusions['ids'],
			$config,
			$options,
			$eligibility
		);

		if ( empty( $sections ) ) {
			return '<div class="cybermap-shortcode cybermap-empty"><p>' . esc_html__( 'No pages found.', 'cybermaps' ) . '</p></div>';
		}

		return $this->render_sections( $sections, $config );
	}

	/**
	 * Normalize shortcode rendering attributes.
	 *
	 * @param array<string, mixed> $atts Shortcode attributes.
	 * @return array{only:mixed,exclude:mixed,limit:int,depth:int,sort_order:string,add_nofollow:bool,show_titles:bool,layout:string}
	 */
	private function get_render_config( array $atts ): array {

		// phpcs:disable WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude
		$a = shortcode_atts(
			array(
				'only'          => '',
				'exclude'       => '',
				'limit'         => 50,
				'depth'         => 0,
				'sort'          => 'asc',
				'nofollow'      => 'false',
				'display_title' => 'true',
				'layout'        => 'list',
			),
			$atts
		);
		// phpcs:enable

		return array(
			'only'         => $a['only'],
			'exclude'      => $a['exclude'],
			'limit'        => min( self::MAX_RENDER_ITEMS, max( 1, (int) $a['limit'] ) ),
			'depth'        => (int) $a['depth'],
			'sort_order'   => strtoupper( $a['sort'] ) === 'DESC' ? 'DESC' : 'ASC',
			'add_nofollow' => 'true' === strtolower( $a['nofollow'] ),
			'show_titles'  => 'true' === strtolower( $a['display_title'] ),
			'layout'       => in_array( $a['layout'], array( 'list', 'columns', 'bare' ), true ) ? $a['layout'] : 'list',
		);
	}

	/**
	 * Parse exclusions into identifiers and slug patterns.
	 *
	 * @return array{ids:int[],wildcards:string[],exacts:string[]}
	 */
	private function get_exclusions( mixed $exclude ): array {
		$exclude_value     = self::bounded_scalar( $exclude, self::MAX_EXCLUDE_BYTES );
		$exclude_raw       = self::bounded_tokens( $exclude_value );
		$exclude_ids       = array();
		$exclude_wildcards = array();
		$exclude_exacts    = array();

		foreach ( $exclude_raw as $item ) {
			if ( is_numeric( $item ) ) {
				$exclude_ids[] = absint( $item );
			} elseif ( str_contains( $item, '*' ) ) {
				$exclude_wildcards[] = $item;
			} else {
				$exclude_exacts[] = $item;
			}
		}

		return array(
			'ids'       => $exclude_ids,
			'wildcards' => $exclude_wildcards,
			'exacts'    => $exclude_exacts,
		);
	}

	/**
	 * Resolve selected post types and taxonomies.
	 *
	 * @return array{0:string[],1:string[]}
	 */
	private function get_selected_types( mixed $only ): array {
		$only_value             = self::bounded_scalar( $only, self::MAX_ONLY_BYTES );
		$only_raw               = is_scalar( $only )
			? self::bounded_tokens( $only_value )
			: array( '__invalid_only_attribute__' );
		$post_types             = array();
		$taxonomies             = array();
		$publication_post_types = \Cybermaps\Core\PublicationPostTypes::names();
		$public_taxonomies      = array_values(
			array_unique(
				array_filter(
					array_map(
						'sanitize_key',
						(array) get_taxonomies( array( 'public' => true ), 'names' )
					)
				)
			)
		);

		if ( empty( $only_raw ) ) {
			$post_types = $publication_post_types;
		} else {
			foreach ( $only_raw as $raw_type ) {
				$this->apply_selection_token(
					$raw_type,
					$post_types,
					$taxonomies,
					$publication_post_types,
					$public_taxonomies
				);
			}
		}

		$post_types = $this->filter_selected_types( $post_types, 'post_type' );
		$taxonomies = $this->filter_selected_types( $taxonomies, 'taxonomy' );
		return array( $post_types, $taxonomies );
	}

	/**
	 * Apply one selection token.
	 *
	 * @param string[] $post_types Post types.
	 * @param string[] $taxonomies Taxonomies.
	 * @param string[] $public_post_types Public post types.
	 * @param string[] $public_taxonomies Public taxonomies.
	 */
	private function apply_selection_token(
		string $raw_type,
		array &$post_types,
		array &$taxonomies,
		array $public_post_types,
		array $public_taxonomies
	): void {
		$separator = strpos( $raw_type, ':' );
		if ( false !== $separator ) {
			$kind     = sanitize_key( substr( $raw_type, 0, $separator ) );
			$raw_name = trim( substr( $raw_type, $separator + 1 ) );
			$this->apply_namespaced_selection( $kind, $raw_name, $post_types, $taxonomies, $public_post_types, $public_taxonomies );
			return;
		}

		$type = sanitize_key( $raw_type );
		if ( in_array( $type, $public_post_types, true ) ) {
			$post_types[] = $type;
			return;
		}

		// The builder historically used "tag" as a friendly alias for
		// WordPress's actual post_tag taxonomy slug.
		$taxonomy = 'tag' === $type ? 'post_tag' : $type;
		if ( in_array( $taxonomy, $public_taxonomies, true ) ) {
			$taxonomies[] = $taxonomy;
		}
	}

	/**
	 * Apply one namespaced selection token.
	 *
	 * @param string[] $post_types Post types.
	 * @param string[] $taxonomies Taxonomies.
	 * @param string[] $public_post_types Public post types.
	 * @param string[] $public_taxonomies Public taxonomies.
	 */
	private function apply_namespaced_selection(
		string $kind,
		string $raw_name,
		array &$post_types,
		array &$taxonomies,
		array $public_post_types,
		array $public_taxonomies
	): void {
		if ( '*' === $raw_name ) {
			if ( 'post_type' === $kind ) {
				$post_types = array_merge( $post_types, $public_post_types );
			} elseif ( 'taxonomy' === $kind ) {
				$taxonomies = array_merge( $taxonomies, $public_taxonomies );
			}
			return;
		}

		$name = sanitize_key( $raw_name );
		if ( '' === $name ) {
			return;
		}
		if ( 'post_type' === $kind ) {
			if ( in_array( $name, $public_post_types, true ) ) {
				$post_types[] = $name;
			}
			return;
		}
		if ( 'taxonomy' === $kind && in_array( $name, $public_taxonomies, true ) ) {
			$taxonomies[] = $name;
		}
	}

	/**
	 * Sanitize, deduplicate, and priority-filter selected object types.
	 *
	 * @param string[] $types Object type names.
	 * @return string[]
	 */
	private function filter_selected_types( array $types, string $kind ): array {
		$types = array_values( array_unique( array_map( 'sanitize_key', $types ) ) );
		return array_values(
			array_filter(
				$types,
				static function ( string $type ) use ( $kind ): bool {
					$identity = 'post_type' === $kind
						? ProviderIdentity::post_type( $type )
						: ProviderIdentity::taxonomy( $type );
					return PriorityEngine::calculate( $identity ) > 0;
				}
			)
		);
	}

	/**
	 * Collect and append post type sections.
	 *
	 * @param array<int, array{label:string,nodes:array}> $sections Sections.
	 * @param string[]                                  $post_types Post types.
	 * @param int[]                                     $exclude_ids Excluded IDs.
	 * @param array<string, mixed>                      $config Render configuration.
	 */
	private function append_post_sections(
		array &$sections,
		int &$remaining_capacity,
		array $post_types,
		array $exclude_ids,
		array $config,
		PublicationEligibility $eligibility
	): void {
		if ( empty( $post_types ) ) {
			return;
		}

		$grouped_posts = $this->get_grouped_posts(
			$post_types,
			$exclude_ids,
			$config['sort_order'],
			$config['limit'],
			$eligibility
		);
		foreach ( $grouped_posts as $post_type => $posts ) {
			if ( $remaining_capacity < 1 ) {
				break;
			}
			$nodes = $this->get_post_nodes( $posts );
			if ( empty( $nodes ) ) {
				continue;
			}
			$sections[]          = array(
				'label' => $this->get_post_type_label( (string) $post_type ),
				'nodes' => $nodes,
			);
			$remaining_capacity -= $this->renderable_node_count(
				$nodes,
				$config['depth'],
				$config['layout'],
				$remaining_capacity
			);
		}
	}

	/**
	 * Query eligible posts and group them by post type.
	 *
	 * @param string[] $post_types Post types.
	 * @param int[]    $exclude_ids Excluded IDs.
	 * @return array<string, object[]>
	 */
	private function get_grouped_posts(
		array $post_types,
		array $exclude_ids,
		string $sort_order,
		int $limit,
		PublicationEligibility $eligibility
	): array {
		$grouped_posts  = array();
		$eligible_count = 0;
		$inspected      = 0;
		$page           = 1;
		add_filter( 'posts_where', array( $this, 'filter_wildcards' ) );

		do {
			$batch_size  = min( self::QUERY_BATCH_SIZE, self::MAX_SCAN_ITEMS - $inspected );
			$args        = array(
				'post_type'      => $post_types,
				'posts_per_page' => $batch_size,
				'paged'          => $page,
				'post_status'    => 'publish',
				// phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in
				'post__not_in'   => $exclude_ids,
				'orderby'        => 'title',
				'order'          => $sort_order,
				'fields'         => 'all',
				'no_found_rows'  => true,
			);
			$query       = new \WP_Query( $args );
			$batch       = (array) $query->posts;
			$batch_count = count( $batch );
			$inspected  += $batch_count;

			foreach ( $batch as $post ) {
				if ( ! $this->is_eligible_post( $post, $exclude_ids, $eligibility ) ) {
					continue;
				}
				$grouped_posts[ (string) $post->post_type ][] = $post;
				++$eligible_count;
				if ( $eligible_count >= $limit ) {
					break;
				}
			}
			++$page;
		} while (
			$eligible_count < $limit
			&& $inspected < self::MAX_SCAN_ITEMS
			&& $batch_count === $batch_size
		);
		remove_filter( 'posts_where', array( $this, 'filter_wildcards' ) );
		return $grouped_posts;
	}

	/**
	 * Whether a queried post belongs in the shortcode.
	 *
	 * @param int[] $exclude_ids Excluded IDs.
	 */
	private function is_eligible_post( mixed $post, array $exclude_ids, PublicationEligibility $eligibility ): bool {
		return is_object( $post )
			&& isset( $post->ID, $post->post_type )
			&& ! in_array( (int) $post->ID, $exclude_ids, true )
			&& ! $this->is_excluded_slug( (string) ( $post->post_name ?? '' ) )
			&& $eligibility->post( $post, PublicationEligibility::SITEMAP )->indexable;
	}

	/**
	 * Build nodes from a post group.
	 *
	 * @param object[] $posts Posts.
	 * @return array<int, array{id:int,parent:int,title:string,url:string}>
	 */
	private function get_post_nodes( array $posts ): array {
		$nodes = array();
		foreach ( $posts as $post ) {
			$url = get_permalink( (int) $post->ID );
			if ( ! is_string( $url ) || '' === $url ) {
				continue;
			}
			$nodes[] = array(
				'id'     => (int) $post->ID,
				'parent' => (int) ( $post->post_parent ?? 0 ),
				'title'  => (string) get_the_title( (int) $post->ID ),
				'url'    => \Cybermaps\Core\URLManager::rewrite_url( $url ),
			);
		}
		return $nodes;
	}

	/**
	 * Resolve a post type section label.
	 */
	private function get_post_type_label( string $post_type ): string {
		$post_type_object = get_post_type_object( $post_type );
		if ( is_object( $post_type_object ) && isset( $post_type_object->labels, $post_type_object->labels->name ) ) {
			return (string) $post_type_object->labels->name;
		}
		return $post_type;
	}

	/**
	 * Collect and append taxonomy sections.
	 *
	 * @param array<int, array{label:string,nodes:array}> $sections Sections.
	 * @param string[]                                  $taxonomies Taxonomies.
	 * @param int[]                                     $exclude_ids Excluded IDs.
	 * @param array<string, mixed>                      $config Render configuration.
	 * @param array<string, mixed>                      $options Plugin settings.
	 */
	private function append_taxonomy_sections(
		array &$sections,
		int &$remaining_capacity,
		array $taxonomies,
		array $exclude_ids,
		array $config,
		array $options,
		PublicationEligibility $eligibility
	): void {
		foreach ( $taxonomies as $taxonomy ) {
			if ( $remaining_capacity < 1 ) {
				break;
			}
			$nodes = $this->get_taxonomy_nodes(
				$taxonomy,
				$remaining_capacity,
				$exclude_ids,
				$config['sort_order'],
				$options,
				$eligibility
			);
			if ( empty( $nodes ) ) {
				continue;
			}

			$sections[]          = array(
				'label' => $this->get_taxonomy_label( $taxonomy ),
				'nodes' => $nodes,
			);
			$remaining_capacity -= $this->renderable_node_count(
				$nodes,
				$config['depth'],
				$config['layout'],
				$remaining_capacity
			);
		}
	}

	/**
	 * Collect eligible term nodes within the bounded scan window.
	 *
	 * @param int[]                $exclude_ids Excluded IDs.
	 * @param array<string, mixed> $options Plugin settings.
	 * @return array<int, array{id:int,parent:int,title:string,url:string}>
	 */
	private function get_taxonomy_nodes(
		string $taxonomy,
		int $capacity,
		array $exclude_ids,
		string $sort_order,
		array $options,
		PublicationEligibility $eligibility
	): array {
		$nodes      = array();
		$node_count = 0;
		$offset     = 0;
		while ( $node_count < $capacity && $offset < self::MAX_SCAN_ITEMS ) {
			$batch_size = min( self::QUERY_BATCH_SIZE, self::MAX_SCAN_ITEMS - $offset );
			$terms      = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => empty( $options['include_empty_terms'] ),
					'orderby'    => 'name',
					'order'      => $sort_order,
					'number'     => $batch_size,
					'offset'     => $offset,
				)
			);
			if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
				break;
			}

			// Do not allow a terms filter to make one request exceed the
			// bounded scan window advertised by the shortcode.
			$batch   = array_slice( $terms, 0, $batch_size );
			$offset += $batch_size;
			if ( empty( $batch ) ) {
				break;
			}
			$this->append_term_nodes( $nodes, $batch, $taxonomy, $capacity, $exclude_ids, $eligibility );
			$node_count = count( $nodes );
			if ( $node_count >= $capacity || count( $batch ) < $batch_size ) {
				break;
			}
		}
		return $nodes;
	}

	/**
	 * Append eligible nodes from one term batch.
	 *
	 * @param array<int, array{id:int,parent:int,title:string,url:string}> $nodes Nodes.
	 * @param mixed[]                                                    $batch Terms.
	 * @param int[]                                                      $exclude_ids Excluded IDs.
	 */
	private function append_term_nodes(
		array &$nodes,
		array $batch,
		string $taxonomy,
		int $capacity,
		array $exclude_ids,
		PublicationEligibility $eligibility
	): void {
		foreach ( $batch as $term ) {
			if ( ! $this->is_eligible_term_node( $term, $taxonomy, $exclude_ids, $eligibility ) ) {
				continue;
			}
			$url = $this->get_term_url( $term, $taxonomy );
			if ( '' === $url ) {
				continue;
			}

			$nodes[] = array(
				'id'     => (int) $term->term_id,
				'parent' => (int) ( $term->parent ?? 0 ),
				'title'  => (string) ( $term->name ?? $term->slug ?? '' ),
				'url'    => $url,
			);
			if ( count( $nodes ) >= $capacity ) {
				break;
			}
		}
	}

	/**
	 * Whether a queried term belongs in the shortcode.
	 *
	 * @param int[] $exclude_ids Excluded IDs.
	 */
	private function is_eligible_term_node( mixed $term, string $taxonomy, array $exclude_ids, PublicationEligibility $eligibility ): bool {
		return is_object( $term )
			&& isset( $term->term_id )
			&& ! in_array( (int) $term->term_id, $exclude_ids, true )
			&& ! $this->is_excluded_slug( (string) ( $term->slug ?? '' ) )
			&& $eligibility->term( $term, $taxonomy, PublicationEligibility::SITEMAP )->indexable;
	}

	/**
	 * Resolve and rewrite one term URL.
	 */
	private function get_term_url( object $term, string $taxonomy ): string {
		$url = get_term_link( $term, $taxonomy );
		if ( is_wp_error( $url ) || ! is_string( $url ) || '' === $url ) {
			return '';
		}
		return \Cybermaps\Core\URLManager::rewrite_url( $url );
	}

	/**
	 * Resolve a taxonomy section label.
	 */
	private function get_taxonomy_label( string $taxonomy ): string {
		if ( 'post_format' === $taxonomy ) {
			return __( 'Post Formats', 'cybermaps' );
		}
		$taxonomy_object = get_taxonomy( $taxonomy );
		if ( is_object( $taxonomy_object ) && isset( $taxonomy_object->labels, $taxonomy_object->labels->name ) ) {
			return (string) $taxonomy_object->labels->name;
		}
		if ( is_object( $taxonomy_object ) && isset( $taxonomy_object->label ) ) {
			return (string) $taxonomy_object->label;
		}
		return $taxonomy;
	}

	/**
	 * Render all collected sections.
	 *
	 * @param array<int, array{label:string,nodes:array}> $sections Sections.
	 * @param array<string, mixed>                      $config Render configuration.
	 */
	private function render_sections( array $sections, array $config ): string {
		$this->rendered_count = 0;
		$rel_attr             = $this->add_nofollow ? ' rel="nofollow"' : '';
		$is_bare              = 'bare' === $config['layout'];
		$layout_class         = ! $is_bare && 'columns' === $config['layout'] ? ' cybermap-layout-columns' : '';
		$output               = '<div class="cybermap-shortcode' . ( $is_bare ? ' cybermap-bare' : '' ) . $layout_class . '">';

		foreach ( $sections as $section ) {
			if ( $this->rendered_count >= $config['limit'] ) {
				break;
			}
			$output .= $is_bare
				? $this->render_bare_section( $section, $config, $rel_attr )
				: $this->render_standard_section( $section, $config, $rel_attr );
		}

		return $output . '</div>';
	}

	/**
	 * Render one bare-layout section.
	 *
	 * @param array{label:string,nodes:array} $section Section.
	 * @param array<string, mixed>           $config Render configuration.
	 */
	private function render_bare_section( array $section, array $config, string $rel_attr ): string {
		$label         = (string) $section['label'];
		$nodes         = (array) $section['nodes'];
		$section_count = min( count( $nodes ), max( 0, $config['limit'] - $this->rendered_count ) );
		$output        = '';
		if ( $config['show_titles'] ) {
			$output .= '<h4 class="cybermap-bare-section-head">';
			$output .= '<span class="cybermap-section-label">' . esc_html( $label ) . '</span>';
			$output .= '<span class="cybermap-section-count">' . (int) $section_count . '</span>';
			$output .= '</h4>';
		}
		$output .= '<ul>';
		foreach ( $nodes as $node ) {
			if ( $this->rendered_count >= $config['limit'] ) {
				break;
			}
			$output .= '<li><a href="' . esc_url( (string) $node['url'] ) . '"' . $rel_attr . '>'
				. esc_html( (string) $node['title'] ) . '</a></li>';
			++$this->rendered_count;
		}
		return $output . '</ul>';
	}

	/**
	 * Render one list or columns section.
	 *
	 * @param array{label:string,nodes:array} $section Section.
	 * @param array<string, mixed>           $config Render configuration.
	 */
	private function render_standard_section( array $section, array $config, string $rel_attr ): string {
		$label           = (string) $section['label'];
		$nodes           = (array) $section['nodes'];
		$rendered_before = $this->rendered_count;
		if ( -1 === $config['depth'] ) {
			$section_body = $this->render_flat_nodes( $nodes, $config['limit'], $rel_attr );
		} else {
			$visited      = array();
			$section_body = $this->render_level(
				0,
				$this->build_tree( $nodes ),
				$config['depth'],
				1,
				$config['limit'],
				$rel_attr,
				$visited
			);
		}

		$section_count = $this->rendered_count - $rendered_before;
		if ( $section_count < 1 ) {
			return '';
		}
		$output = '<section class="cybermap-section">';
		if ( $config['show_titles'] ) {
			$output .= '<h3 class="cybermap-section-head">';
			$output .= '<span class="cybermap-section-label">' . esc_html( $label ) . '</span>';
			$output .= '<span class="cybermap-section-count">' . (int) $section_count . '</span>';
			$output .= '</h3>';
		}
		return $output . $section_body . '</section>';
	}

	/**
	 * Render flat nodes for one section.
	 *
	 * @param array<int, array{id:int,parent:int,title:string,url:string}> $nodes Nodes.
	 */
	private function render_flat_nodes( array $nodes, int $limit, string $rel_attr ): string {
		$output = '<ul class="cybermap-list cybermap-flat">';
		foreach ( $nodes as $node ) {
			if ( $this->rendered_count >= $limit ) {
				break;
			}
			$output .= '<li class="cybermap-item"><a href="' . esc_url( (string) $node['url'] ) . '"' . $rel_attr . '>'
				. esc_html( (string) $node['title'] ) . '</a></li>';
			++$this->rendered_count;
		}
		return $output . '</ul>';
	}

	/**
	 * Normalize a shortcode attribute without allowing arrays or unbounded input.
	 *
	 * @param mixed $value Attribute value.
	 */
	private static function bounded_scalar( $value, int $max_bytes ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = trim( (string) $value );
		return substr( $value, 0, max( 0, $max_bytes ) );
	}

	/**
	 * Split a bounded comma-separated attribute into bounded non-empty tokens.
	 *
	 * @return string[]
	 */
	private static function bounded_tokens( string $value ): array {
		$tokens = array_slice(
			array_map( 'trim', explode( ',', $value ) ),
			0,
			self::MAX_FILTER_TOKENS
		);
		$tokens = array_map(
			static fn( string $token ): string => substr( $token, 0, self::MAX_FILTER_TOKEN_BYTES ),
			$tokens
		);

		return array_values(
			array_filter(
				$tokens,
				static fn( string $token ): bool => '' !== $token
			)
		);
	}

	/**
	 * Whether a post or term slug matches the configured exact/wildcard list.
	 */
	private function is_excluded_slug( string $slug ): bool {
		if ( '' === $slug ) {
			return false;
		}
		if ( in_array( $slug, $this->current_exacts, true ) ) {
			return true;
		}

		foreach ( $this->current_wildcards as $wildcard ) {
			$pattern = '/^' . str_replace( '\*', '.*', preg_quote( $wildcard, '/' ) ) . '$/i';
			if ( 1 === preg_match( $pattern, $slug ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Build a hierarchy while promoting items whose parent is not in the result.
	 *
	 * @param array<int, array{id:int,parent:int,title:string,url:string}> $nodes Nodes.
	 * @return array<int, array<int, array{id:int,parent:int,title:string,url:string}>>
	 */
	private function build_tree( array $nodes ): array {
		$known_ids = array();
		foreach ( $nodes as $node ) {
			$known_ids[ (int) $node['id'] ] = true;
		}

		$tree = array();
		foreach ( $nodes as $node ) {
			$id     = (int) $node['id'];
			$parent = (int) $node['parent'];
			if ( $parent < 1 || $parent === $id || ! isset( $known_ids[ $parent ] ) ) {
				$parent = 0;
			}
			$tree[ $parent ][] = $node;
		}

		return $tree;
	}

	/**
	 * Count links this section can actually render under the selected hierarchy.
	 *
	 * @param array<int, array{id:int,parent:int,title:string,url:string}> $nodes Nodes.
	 */
	private function renderable_node_count(
		array $nodes,
		int $depth,
		string $layout,
		int $capacity
	): int {
		if ( $capacity < 1 || empty( $nodes ) ) {
			return 0;
		}
		if ( 'bare' === $layout || -1 === $depth ) {
			return min( $capacity, count( $nodes ) );
		}

		$visited = array();
		return $this->count_tree_level(
			0,
			$this->build_tree( $nodes ),
			$depth,
			1,
			$capacity,
			$visited
		);
	}

	/**
	 * Mirror render_level() without building HTML so collection can stop early.
	 *
	 * @param array<int, array<int, array{id:int,parent:int,title:string,url:string}>> $tree Tree.
	 * @param array<int, bool> $visited IDs already counted in this section.
	 */
	private function count_tree_level(
		int $parent_id,
		array $tree,
		int $max_depth,
		int $current_depth,
		int $capacity,
		array &$visited
	): int {
		if (
			$capacity < 1
			|| ! isset( $tree[ $parent_id ] )
			|| ( $max_depth > 0 && $current_depth > $max_depth )
		) {
			return 0;
		}

		$count = 0;
		foreach ( $tree[ $parent_id ] as $node ) {
			if ( $count >= $capacity ) {
				break;
			}
			$id = (int) $node['id'];
			if ( isset( $visited[ $id ] ) ) {
				continue;
			}
			$visited[ $id ] = true;
			++$count;
			$count += $this->count_tree_level(
				$id,
				$tree,
				$max_depth,
				$current_depth + 1,
				$capacity - $count,
				$visited
			);
		}

		return $count;
	}

	/**
	 * Recursive level renderer with CSS classes and nofollow support.
	 *
	 * @param array<int, array<int, array{id:int,parent:int,title:string,url:string}>> $tree Tree.
	 * @param array<int, bool> $visited IDs already rendered in this section.
	 */
	private function render_level(
		int $parent_id,
		array $tree,
		int $max_depth,
		int $current_depth,
		int $limit,
		string $rel_attr,
		array &$visited
	): string {
		if ( ! isset( $tree[ $parent_id ] ) || ( $max_depth > 0 && $current_depth > $max_depth ) ) {
			return '';
		}

		$level_class = 1 === $current_depth ? 'cybermap-list cybermap-tree' : 'cybermap-sublist';
		$output      = '<ul class="' . $level_class . '">';

		foreach ( $tree[ $parent_id ] as $node ) {
			if ( $this->rendered_count >= $limit ) {
				break;
			}
			$id = (int) $node['id'];
			if ( isset( $visited[ $id ] ) ) {
				continue;
			}
			$visited[ $id ] = true;

			$output .= '<li class="cybermap-item"><a href="' . esc_url( (string) $node['url'] ) . '"' . $rel_attr . '>'
				. esc_html( (string) $node['title'] ) . '</a>';
			++$this->rendered_count;
			$output .= $this->render_level( $id, $tree, $max_depth, $current_depth + 1, $limit, $rel_attr, $visited );
			$output .= '</li>';
		}

		$output .= '</ul>';
		return $output;
	}
}
