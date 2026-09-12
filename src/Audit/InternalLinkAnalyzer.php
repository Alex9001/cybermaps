<?php
/**
 * Local internal-link analysis for saved content reports.
 *
 * @package Cybermaps
 */

declare(strict_types=1);

namespace Cybermaps\Audit;

use Cybermaps\Content\ContentAnalyzer;
use Cybermaps\Core\URLManager;
use Cybermaps\SEO\PublicationEligibility;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds a bounded graph from stored post content and assigned navigation.
 */
final class InternalLinkAnalyzer {
	public const ANALYSIS_VERSION       = 1;
	public const MAX_RESOURCES          = 10000;
	public const MAX_EDGES              = 100000;
	public const DEEP_THRESHOLD         = 3;
	private const MAX_NAVIGATION_BLOCKS = 10000;
	private const MAX_STORED_BLOCKS     = 2000;

	private ContentAnalyzer $content_analyzer;
	private PublicationEligibility $eligibility;
	private PublishedPostSource $post_source;

	public function __construct(
		?ContentAnalyzer $content_analyzer = null,
		?PublicationEligibility $eligibility = null,
		?PublishedPostSource $post_source = null
	) {
		$this->content_analyzer = $content_analyzer ?? new ContentAnalyzer();
		$this->eligibility      = $eligibility ?? new PublicationEligibility();
		$this->post_source      = $post_source ?? new PublishedPostSource();
	}

	/**
	 * Analyze the local graph.
	 *
	 * @param string[]      $post_types Public post types in the report.
	 * @param callable|null $heartbeat  Called between database batches.
	 * @return array{findings:array<int,array<int,array<string,mixed>>>,measurements:array<int,array<string,mixed>>,analysis:array<string,mixed>}
	 */
	public function analyze( array $post_types, ?callable $heartbeat = null ): array {
		$inventory  = $this->build_inventory( $post_types, $heartbeat );
		$graph      = $this->build_graph( $inventory['nodes'], $inventory['url_map'] );
		$navigation = $this->navigation_targets( $inventory['nodes'], $inventory['url_map'] );
		$depths     = $this->calculate_depths( $inventory['nodes'], $graph['outgoing'], $navigation['targets'] );
		$sufficient = ! $inventory['truncated'] && ! $graph['truncated'] && $inventory['complete'] && $navigation['complete'];
		$results    = $this->build_results(
			$inventory['nodes'],
			$graph,
			$navigation,
			$depths,
			$sufficient
		);

		$limitations = array_values(
			array_filter(
				array(
					$inventory['truncated'] ? 'resource_limit_reached' : '',
					$graph['truncated'] ? 'edge_limit_reached' : '',
					! $inventory['complete'] ? 'stored_content_incomplete' : '',
					! $navigation['complete'] ? 'navigation_scan_incomplete' : '',
					! $depths['available'] ? 'homepage_path_unavailable' : '',
				)
			)
		);

		return array(
			'findings'     => $results['findings'],
			'measurements' => $results['measurements'],
			'analysis'     => array(
				'internal_link_version' => self::ANALYSIS_VERSION,
				'method'                => 'stored_content_and_assigned_navigation',
				'resource_count'        => count( $inventory['nodes'] ),
				'edge_count'            => $graph['edge_count'],
				'navigation_references' => $navigation['reference_count'],
				'depth_available'       => $depths['available'],
				'complete'              => $sufficient,
				'limits'                => array(
					'resources' => self::MAX_RESOURCES,
					'edges'     => self::MAX_EDGES,
				),
				'limitations'           => $limitations,
			),
		);
	}

	/**
	 * @param string[]      $post_types Post types.
	 * @param callable|null $heartbeat  Batch callback.
	 * @return array{nodes:array<int,array<string,mixed>>,url_map:array<string,int>,truncated:bool,complete:bool}
	 */
	private function build_inventory( array $post_types, ?callable $heartbeat ): array {
		$nodes     = array();
		$url_map   = array();
		$truncated = false;
		$complete  = true;

		foreach ( $this->post_source->batches( $post_types ) as $posts ) {
			foreach ( $posts as $post ) {
				if ( count( $nodes ) >= self::MAX_RESOURCES ) {
					$truncated = true;
					break 2;
				}
				if ( ! is_object( $post ) || (int) ( $post->ID ?? 0 ) < 1 ) {
					continue;
				}
				$node                 = $this->create_node( $post );
				$nodes[ $node['id'] ] = $node;
				$complete             = $complete && $node['complete'];
				foreach ( $node['aliases'] as $alias ) {
					$url_map[ $alias ] = $node['id'];
				}
			}
			if ( null !== $heartbeat ) {
				$heartbeat();
			}
		}

		$this->expand_stored_page_lists( $nodes );

		return compact( 'nodes', 'url_map', 'truncated', 'complete' );
	}

	/** @return array<string,mixed> */
	private function create_node( object $post ): array {
		$post_id    = (int) $post->ID;
		$permalink  = (string) get_permalink( $post_id );
		$public_url = (string) URLManager::rewrite_url( $permalink );
		$analysis   = $this->content_analyzer->analyze_post( $post );
		$blocks     = $this->stored_block_links( (string) ( $post->post_content ?? '' ) );
		$aliases    = array_values(
			array_unique(
				array_filter(
					array(
						$this->normalize_url( $permalink ),
						$this->normalize_url( $public_url ),
					)
				)
			)
		);

		return array(
			'id'        => $post_id,
			'url'       => $public_url,
			'title'     => (string) ( $post->post_title ?? get_the_title( $post_id ) ),
			'post_type' => sanitize_key( (string) ( $post->post_type ?? 'post' ) ),
			'indexable' => $this->eligibility->post( $post, PublicationEligibility::REPORT )->indexable,
			'links'     => array_merge( (array) ( $analysis['links'] ?? array() ), $blocks['links'] ),
			'page_list' => $blocks['page_list'],
			'complete'  => ! empty( $analysis['complete'] ) && $blocks['complete'],
			'aliases'   => $aliases,
		);
	}

	/** @return array{links:array<int,array{url:string,text:string}>,page_list:bool,complete:bool} */
	private function stored_block_links( string $content ): array {
		if ( ! function_exists( 'parse_blocks' ) || '' === $content ) {
			return array(
				'links'     => array(),
				'page_list' => false,
				'complete'  => true,
			);
		}

		$sources = array();
		$visited = array();
		$budget  = array(
			'remaining' => self::MAX_STORED_BLOCKS,
			'complete'  => true,
			'page_list' => false,
		);
		$this->collect_block_urls( $content, 'stored', $sources, $visited, array(), array(), $budget, 0 );
		$links = array_map(
			static fn( string $url ): array => array(
				'url'  => $url,
				'text' => '',
			),
			array_keys( $sources['stored'] ?? array() )
		);

		return array(
			'links'     => $links,
			'page_list' => $budget['page_list'],
			'complete'  => $budget['complete'],
		);
	}

	/** @param array<int,array<string,mixed>> $nodes Inventory nodes. */
	private function expand_stored_page_lists( array &$nodes ): void {
		$page_urls = array();
		foreach ( $nodes as $node ) {
			if ( 'page' === $node['post_type'] ) {
				$page_urls[] = array(
					'url'  => (string) $node['url'],
					'text' => '',
				);
			}
		}
		foreach ( $nodes as &$node ) {
			if ( ! empty( $node['page_list'] ) ) {
				$node['links'] = array_merge( $node['links'], $page_urls );
			}
		}
		unset( $node );
	}

	/**
	 * @param array<int,array<string,mixed>> $nodes   Inventory nodes.
	 * @param array<string,int>              $url_map URL-to-ID map.
	 * @return array{outgoing:array<int,array<int,true>>,incoming:array<int,array<int,true>>,edge_count:int,truncated:bool}
	 */
	private function build_graph( array $nodes, array $url_map ): array {
		$outgoing   = array();
		$incoming   = array();
		$edge_count = 0;
		$truncated  = false;

		foreach ( $nodes as $source_id => $node ) {
			foreach ( (array) $node['links'] as $link ) {
				$url       = is_array( $link ) ? (string) ( $link['url'] ?? '' ) : '';
				$url       = $this->content_analyzer->resolve_link_url( $url, (string) $node['url'] );
				$target_id = $url_map[ $this->normalize_url( $url ) ] ?? 0;
				if ( $target_id < 1 || $target_id === $source_id || isset( $outgoing[ $source_id ][ $target_id ] ) ) {
					continue;
				}
				if ( $edge_count >= self::MAX_EDGES ) {
					$truncated = true;
					break 2;
				}
				$outgoing[ $source_id ][ $target_id ] = true;
				$incoming[ $target_id ][ $source_id ] = true;
				++$edge_count;
			}
		}

		return compact( 'outgoing', 'incoming', 'edge_count', 'truncated' );
	}

	/**
	 * @param array<int,array<string,mixed>> $nodes   Inventory nodes.
	 * @param array<string,int>              $url_map URL-to-ID map.
	 * @return array{targets:array<int,array<string,true>>,reference_count:int,available:bool,complete:bool}
	 */
	private function navigation_targets( array $nodes, array $url_map ): array {
		$sources   = array();
		$available = false;
		$complete  = true;
		$this->collect_classic_menus( $sources, $available );
		$this->collect_block_navigation( $sources, $available, $complete, $nodes );

		$targets = array();
		foreach ( $sources as $source => $urls ) {
			foreach ( array_keys( $urls ) as $url ) {
				$target_id = $url_map[ $this->navigation_url_key( $url ) ] ?? 0;
				if ( $target_id > 0 ) {
					$targets[ $target_id ][ $source ] = true;
				}
			}
		}

		return array(
			'targets'         => $targets,
			'reference_count' => array_sum( array_map( 'count', $targets ) ),
			'available'       => $available,
			'complete'        => $complete,
		);
	}

	/** @param array<string,array<string,true>> $sources */
	private function collect_classic_menus( array &$sources, bool &$available ): void {
		if ( ! function_exists( 'get_nav_menu_locations' ) || ! function_exists( 'wp_get_nav_menu_items' ) ) {
			return;
		}

		$locations = get_nav_menu_locations();
		if ( ! is_array( $locations ) ) {
			return;
		}
		foreach ( $locations as $location => $menu_id ) {
			$items = wp_get_nav_menu_items( (int) $menu_id );
			if ( ! is_array( $items ) ) {
				continue;
			}
			$available = true;
			$source    = 'menu:' . sanitize_key( (string) $location );
			foreach ( $items as $item ) {
				$url = is_object( $item ) ? (string) ( $item->url ?? '' ) : '';
				if ( '' !== $url ) {
					$sources[ $source ][ $url ] = true;
				}
			}
		}
	}

	/**
	 * @param array<string,array<string,true>> $sources Navigation sources.
	 * @param array<int,array<string,mixed>>   $nodes   Inventory nodes.
	 */
	private function collect_block_navigation( array &$sources, bool &$available, bool &$complete, array $nodes ): void {
		if ( ! function_exists( 'get_block_templates' ) || ! function_exists( 'parse_blocks' ) ) {
			return;
		}

		$templates      = (array) get_block_templates( array(), 'wp_template' );
		$template_parts = (array) get_block_templates( array(), 'wp_template_part' );
		$part_map       = $this->template_part_map( array_slice( $template_parts, 0, 500 ) );
		$visited        = array();
		$budget         = array(
			'remaining' => self::MAX_NAVIGATION_BLOCKS,
			'complete'  => true,
			'page_list' => false,
		);
		foreach ( array_slice( $templates, 0, 100 ) as $template ) {
			if ( ! is_object( $template ) || ! isset( $template->content ) ) {
				continue;
			}
			$source = 'template:' . sanitize_key( (string) ( $template->slug ?? $template->id ?? 'theme' ) );
			$this->collect_block_urls( (string) $template->content, $source, $sources, $visited, $nodes, $part_map, $budget, 0 );
			if ( isset( $sources[ $source ] ) ) {
				$available = true;
			}
		}
		if ( count( $templates ) > 100 || count( $template_parts ) > 500 ) {
			$complete = false;
		}
		$complete = $complete && $budget['complete'];
	}

	/**
	 * @param object[] $parts Theme template parts.
	 * @return array<string,string>
	 */
	private function template_part_map( array $parts ): array {
		$map = array();
		foreach ( $parts as $part ) {
			if ( is_object( $part ) && isset( $part->slug, $part->content ) ) {
				$map[ sanitize_key( (string) $part->slug ) ] = (string) $part->content;
			}
		}
		return $map;
	}

	/**
	 * @param array<string,array<string,true>> $sources Navigation sources.
	 * @param array<string,true>               $visited Visited navigation and template-part IDs.
	 * @param array<int,array<string,mixed>>   $nodes   Inventory nodes.
	 * @param array<string,string>             $part_map Theme template parts by slug.
	 * @param array{remaining:int,complete:bool,page_list:bool} $budget Traversal budget.
	 */
	private function collect_block_urls(
		string $content,
		string $source,
		array &$sources,
		array &$visited,
		array $nodes,
		array $part_map,
		array &$budget,
		int $depth
	): void {
		if ( $depth > 8 ) {
			$budget['complete'] = false;
			return;
		}
		foreach ( (array) parse_blocks( $content ) as $block ) {
			if ( $budget['remaining'] < 1 ) {
				$budget['complete'] = false;
				return;
			}
			--$budget['remaining'];
			if ( ! is_array( $block ) ) {
				continue;
			}
			$this->collect_parsed_block_urls( $block, $source, $sources, $visited, $nodes, $part_map, $budget, $depth );
		}
	}

	/**
	 * @param array<string,mixed>                $block    Parsed block.
	 * @param array<string,array<string,true>>   $sources  Navigation sources.
	 * @param array<string,true>                 $visited  Visited references.
	 * @param array<int,array<string,mixed>>     $nodes    Inventory nodes.
	 * @param array<string,string>               $part_map Theme template parts by slug.
	 * @param array{remaining:int,complete:bool,page_list:bool} $budget Traversal budget.
	 */
	private function collect_parsed_block_urls(
		array $block,
		string $source,
		array &$sources,
		array &$visited,
		array $nodes,
		array $part_map,
		array &$budget,
		int $depth
	): void {
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		foreach ( array( 'url', 'href' ) as $attribute ) {
			if ( isset( $attrs[ $attribute ] ) && is_scalar( $attrs[ $attribute ] ) ) {
				$sources[ $source ][ (string) $attrs[ $attribute ] ] = true;
			}
		}
		if ( 'core/page-list' === (string) ( $block['blockName'] ?? '' ) ) {
			$budget['page_list'] = true;
			$this->append_page_list_urls( $sources[ $source ], $nodes );
		}
		$this->collect_template_part( $block, $attrs, $source, $sources, $visited, $nodes, $part_map, $budget, $depth );
		if ( 'core/navigation' === (string) ( $block['blockName'] ?? '' ) ) {
			$this->collect_referenced_navigation( $attrs, $source, $sources, $visited, $nodes, $part_map, $budget, $depth );
		}
		$this->collect_inner_block_urls( $block, $source, $sources, $visited, $nodes, $part_map, $budget, $depth );
	}

	/**
	 * @param array<string,mixed>                $block    Parsed block.
	 * @param array<string,mixed>                $attrs    Block attributes.
	 * @param array<string,array<string,true>>   $sources  Navigation sources.
	 * @param array<string,true>                 $visited  Visited references.
	 * @param array<int,array<string,mixed>>     $nodes    Inventory nodes.
	 * @param array<string,string>               $part_map Theme template parts by slug.
	 * @param array{remaining:int,complete:bool,page_list:bool} $budget Traversal budget.
	 */
	private function collect_template_part(
		array $block,
		array $attrs,
		string $source,
		array &$sources,
		array &$visited,
		array $nodes,
		array $part_map,
		array &$budget,
		int $depth
	): void {
		if ( 'core/template-part' !== (string) ( $block['blockName'] ?? '' ) ) {
			return;
		}
		$slug = sanitize_key( (string) ( $attrs['slug'] ?? '' ) );
		$key  = 'template-part:' . $slug;
		if ( '' === $slug || isset( $visited[ $key ] ) || ! isset( $part_map[ $slug ] ) ) {
			return;
		}

		$visited[ $key ] = true;
		$this->collect_block_urls( $part_map[ $slug ], $source, $sources, $visited, $nodes, $part_map, $budget, $depth + 1 );
	}

	/**
	 * @param array<string,mixed>              $attrs   Block attributes.
	 * @param array<string,array<string,true>> $sources Navigation sources.
	 * @param array<string,true>               $visited Visited navigation and template-part IDs.
	 * @param array<int,array<string,mixed>>   $nodes   Inventory nodes.
	 * @param array<string,string>             $part_map Theme template parts by slug.
	 * @param array{remaining:int,complete:bool,page_list:bool} $budget Traversal budget.
	 */
	private function collect_referenced_navigation(
		array $attrs,
		string $source,
		array &$sources,
		array &$visited,
		array $nodes,
		array $part_map,
		array &$budget,
		int $depth
	): void {
		$reference = isset( $attrs['ref'] ) && is_numeric( $attrs['ref'] ) ? (int) $attrs['ref'] : 0;
		$key       = 'navigation:' . $reference;
		if ( $reference < 1 || isset( $visited[ $key ] ) ) {
			return;
		}

		$navigation = get_post( $reference );
		if ( ! is_object( $navigation ) || 'wp_navigation' !== (string) ( $navigation->post_type ?? '' ) ) {
			return;
		}
		$visited[ $key ] = true;
		$this->collect_block_urls(
			(string) ( $navigation->post_content ?? '' ),
			$source,
			$sources,
			$visited,
			$nodes,
			$part_map,
			$budget,
			$depth + 1
		);
	}

	/**
	 * @param array<string,mixed>              $block   Parsed block.
	 * @param array<string,array<string,true>> $sources Navigation sources.
	 * @param array<string,true>               $visited Visited navigation and template-part IDs.
	 * @param array<int,array<string,mixed>>   $nodes   Inventory nodes.
	 * @param array<string,string>             $part_map Theme template parts by slug.
	 * @param array{remaining:int,complete:bool,page_list:bool} $budget Traversal budget.
	 */
	private function collect_inner_block_urls(
		array $block,
		string $source,
		array &$sources,
		array &$visited,
		array $nodes,
		array $part_map,
		array &$budget,
		int $depth
	): void {
		$inner_blocks = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array();
		foreach ( $inner_blocks as $inner_block ) {
			$this->collect_block_urls(
				serialize_block( $inner_block ),
				$source,
				$sources,
				$visited,
				$nodes,
				$part_map,
				$budget,
				$depth + 1
			);
		}
	}

	/**
	 * @param array<string,true>              $urls  Target URLs.
	 * @param array<int,array<string,mixed>> $nodes Inventory nodes.
	 */
	private function append_page_list_urls( array &$urls, array $nodes ): void {
		foreach ( $nodes as $node ) {
			if ( 'page' === $node['post_type'] && '' !== (string) $node['url'] ) {
				$urls[ (string) $node['url'] ] = true;
			}
		}
	}

	/**
	 * @param array<int,array<string,mixed>> $nodes      Inventory nodes.
	 * @param array<int,array<int,true>>     $outgoing   Graph adjacency.
	 * @param array<int,array<string,true>>  $navigation Navigation targets.
	 * @return array{depth:array<int,int>,parent:array<int,int>,available:bool,homepage_id:int}
	 */
	private function calculate_depths( array $nodes, array $outgoing, array $navigation ): array {
		$homepage_id = 'page' === (string) get_option( 'show_on_front', 'posts' )
			? absint( get_option( 'page_on_front', 0 ) )
			: 0;
		$depth       = array();
		$parent      = array();
		$queue       = array();

		if ( $homepage_id > 0 && isset( $nodes[ $homepage_id ] ) ) {
			$depth[ $homepage_id ] = 0;
			$queue[]               = $homepage_id;
		}
		foreach ( array_keys( $navigation ) as $target_id ) {
			if ( $target_id !== $homepage_id && ! isset( $depth[ $target_id ] ) ) {
				$depth[ $target_id ] = 1;
				$queue[]             = $target_id;
			}
		}

		$position = 0;
		while ( isset( $queue[ $position ] ) ) {
			$source_id = $queue[ $position ];
			foreach ( array_keys( $outgoing[ $source_id ] ?? array() ) as $target_id ) {
				if ( isset( $depth[ $target_id ] ) ) {
					continue;
				}
				$depth[ $target_id ]  = $depth[ $source_id ] + 1;
				$parent[ $target_id ] = $source_id;
				$queue[]              = $target_id;
			}
			++$position;
		}

		return array(
			'depth'       => $depth,
			'parent'      => $parent,
			'available'   => isset( $nodes[ $homepage_id ] ) || ! empty( $navigation ),
			'homepage_id' => $homepage_id,
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $nodes      Inventory nodes.
	 * @param array<string,mixed>            $graph      Link graph.
	 * @param array<string,mixed>            $navigation Navigation data.
	 * @param array<string,mixed>            $depths     Depth data.
	 * @return array{findings:array<int,array<int,array<string,mixed>>>,measurements:array<int,array<string,mixed>>}
	 */
	private function build_results( array $nodes, array $graph, array $navigation, array $depths, bool $sufficient ): array {
		$findings     = array();
		$measurements = array();
		foreach ( $nodes as $post_id => $node ) {
			$incoming                 = array_keys( $graph['incoming'][ $post_id ] ?? array() );
			$nav_sources              = array_keys( $navigation['targets'][ $post_id ] ?? array() );
			$depth                    = $depths['depth'][ $post_id ] ?? null;
			$measurements[ $post_id ] = array(
				'incoming_content_pages' => count( $incoming ),
				'incoming_navigation'    => count( $nav_sources ),
				'outgoing_internal'      => count( $graph['outgoing'][ $post_id ] ?? array() ),
				'homepage_click_depth'   => $depth,
				'analysis_complete'      => $sufficient,
			);

			if ( ! $sufficient || ! $node['indexable'] || $post_id === $depths['homepage_id'] ) {
				continue;
			}
			$evidence = $this->evidence( $incoming, $nav_sources, $depth, $nodes, $depths['parent'], $post_id );
			if ( empty( $incoming ) && empty( $nav_sources ) ) {
				$findings[ $post_id ][] = $this->orphan_finding( $evidence );
			} elseif ( $depths['available'] && null === $depth ) {
				$findings[ $post_id ][] = $this->unreachable_finding( $evidence );
			} elseif ( is_int( $depth ) && $depth >= self::DEEP_THRESHOLD ) {
				$findings[ $post_id ][] = $this->deep_finding( $evidence, $depth );
			}
		}

		return compact( 'findings', 'measurements' );
	}

	/**
	 * @param int[]                          $incoming    Referring post IDs.
	 * @param string[]                       $nav_sources Navigation sources.
	 * @param array<int,array<string,mixed>> $nodes       Inventory nodes.
	 * @param array<int,int>                 $parents     Shortest-path parents.
	 * @return array<string,mixed>
	 */
	private function evidence( array $incoming, array $nav_sources, ?int $depth, array $nodes, array $parents, int $post_id ): array {
		$examples = array();
		foreach ( array_slice( $incoming, 0, 5 ) as $source_id ) {
			$examples[] = array(
				'title' => (string) ( $nodes[ $source_id ]['title'] ?? '' ),
				'url'   => (string) ( $nodes[ $source_id ]['url'] ?? '' ),
			);
		}

		return array(
			'incoming_content_pages' => count( $incoming ),
			'incoming_navigation'    => count( $nav_sources ),
			'referring_examples'     => $examples,
			'navigation_sources'     => array_slice( $nav_sources, 0, 5 ),
			'homepage_click_depth'   => $depth,
			'shortest_path'          => $this->shortest_path( $post_id, $parents, $nodes ),
			'measurement'            => 'stored_content_and_assigned_navigation',
		);
	}

	/**
	 * @param array<int,int>                 $parents Shortest-path parents.
	 * @param array<int,array<string,mixed>> $nodes   Inventory nodes.
	 * @return array<int,array{title:string,url:string}>
	 */
	private function shortest_path( int $post_id, array $parents, array $nodes ): array {
		$path  = array();
		$steps = 0;
		while ( isset( $nodes[ $post_id ] ) && $steps <= self::DEEP_THRESHOLD + 5 ) {
			array_unshift(
				$path,
				array(
					'title' => (string) $nodes[ $post_id ]['title'],
					'url'   => (string) $nodes[ $post_id ]['url'],
				)
			);
			if ( ! isset( $parents[ $post_id ] ) ) {
				break;
			}
			$post_id = $parents[ $post_id ];
			++$steps;
		}
		return $path;
	}

	/** @param array<string,mixed> $evidence */
	private function orphan_finding( array $evidence ): array {
		return array(
			'key'            => 'potential_orphan',
			'severity'       => 'warning',
			'summary'        => __( 'No stored page or assigned navigation item links to this resource.', 'cybermaps' ),
			'evidence'       => $evidence,
			'recommendation' => __( 'Add a useful contextual link from a related page or navigation area, or intentionally exclude the resource if it should not be discovered.', 'cybermaps' ),
		);
	}

	/** @param array<string,mixed> $evidence */
	private function unreachable_finding( array $evidence ): array {
		return array(
			'key'            => 'no_homepage_path',
			'severity'       => 'review',
			'summary'        => __( 'Internal links exist, but no stored path from the homepage or assigned navigation reaches this resource.', 'cybermaps' ),
			'evidence'       => $evidence,
			'recommendation' => __( 'Connect this resource to a section that visitors and crawlers can reach from the homepage.', 'cybermaps' ),
		);
	}

	/** @param array<string,mixed> $evidence */
	private function deep_finding( array $evidence, int $depth ): array {
		return array(
			'key'            => 'deeply_linked',
			'severity'       => 'review',
			'summary'        => sprintf(
				/* translators: %d: shortest internal-link click depth from the homepage. */
				__( 'The shortest stored path is %d clicks from the homepage.', 'cybermaps' ),
				$depth
			),
			'evidence'       => $evidence,
			'recommendation' => __( 'Consider a direct contextual or navigation link when this resource is important to visitors.', 'cybermaps' ),
		);
	}

	private function normalize_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}
		$scheme = strtolower( (string) ( $parts['scheme'] ?? 'https' ) );
		$host   = strtolower( (string) $parts['host'] );
		$port   = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path   = '/' . ltrim( (string) ( $parts['path'] ?? '/' ), '/' );
		$path   = '/' === $path ? '/' : untrailingslashit( $path );

		return $scheme . '://' . $host . $port . $path;
	}

	private function navigation_url_key( string $url ): string {
		$url = $this->content_analyzer->resolve_link_url( $url, (string) URLManager::rewrite_url( home_url( '/' ) ) );
		return $this->normalize_url( $url );
	}
}
