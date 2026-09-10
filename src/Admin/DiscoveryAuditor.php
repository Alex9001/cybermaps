<?php
declare(strict_types=1);
namespace Cybermaps\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Suggest a starting discovery profile from the site's published structure.
 */
class DiscoveryAuditor {
	/** @var string[] */
	private const CATALOG_POST_TYPES = array( 'product', 'download' );

	/** @var string[] */
	private const DOCUMENTATION_POST_TYPES = array( 'docs', 'documentation', 'knowledgebase', 'kb' );

	/** @var string[] */
	private const PORTFOLIO_POST_TYPES = array( 'portfolio', 'project' );

	/** @var string[] */
	private const SERVICE_POST_TYPES = array( 'service', 'services' );

	/** @var string[] */
	private const NEWS_POST_TYPES = array( 'news', 'press', 'press_release', 'press-release' );

	/**
	 * Register hooks.
	 */
	public function register_hooks() {
		add_action( 'wp_ajax_cybermaps_scan_blueprint', array( $this, 'ajax_scan_blueprint' ) );
	}

	/**
	 * AJAX handler for scanning the site blueprint.
	 */
	public function ajax_scan_blueprint() {
		$request_method = isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) )
			: '';
		if ( 'POST' !== $request_method ) {
			wp_send_json_error(
				array( 'message' => __( 'Invalid request method.', 'cybermaps' ) ),
				405
			);
		}
		check_ajax_referer( 'cybermaps_discovery_center', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Insufficient permissions.', 'cybermaps' ) ),
				403
			);
		}

		$analysis = $this->analyze();
		if ( empty( $analysis['success'] ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Cybermaps could not read the site inventory. Your current settings were not changed.', 'cybermaps' ) ),
				500
			);
		}
		$stats     = isset( $analysis['stats'] ) && is_array( $analysis['stats'] ) ? $analysis['stats'] : array();
		$archetype = isset( $analysis['archetype'] ) ? (string) $analysis['archetype'] : 'medium-business';

		wp_send_json_success(
			array(
				'stats'     => $stats,
				'archetype' => $archetype,
				'reason'    => isset( $analysis['reason'] ) ? (string) $analysis['reason'] : '',
				'blueprint' => $this->get_archetype_defaults( $archetype ),
				'intents'   => $this->get_archetype_intents( $archetype ),
			)
		);
	}

	/**
	 * Return the bounded site scan used by both the strategy workspace and
	 * Guided Setup. Keeping this analysis in one place prevents the wizard
	 * from silently recommending a different profile than the normal UI.
	 *
	 * @return array{success:bool,stats:array<string,mixed>,archetype:string,reason:string}
	 */
	public function analyze(): array {
		$stats = $this->perform_scan();
		if ( ! is_array( $stats ) ) {
			return array(
				'success'   => false,
				'stats'     => array(),
				'archetype' => 'medium-business',
				'reason'    => '',
			);
		}

		return array(
			'success'   => true,
			'stats'     => $stats,
			'archetype' => $this->determine_archetype( $stats ),
			'reason'    => $this->recommendation_reason( $stats ),
		);
	}

	/**
	 * Perform the scan to gather site statistics.
	 *
	 * @return array|null
	 */
	private function perform_scan() {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) || ! method_exists( $wpdb, 'prepare' ) || empty( $wpdb->posts ) ) {
			return null;
		}
		$stats = array(
			'post_types'  => array(),
			'total_posts' => 0,
		);

		$public_post_types = \Cybermaps\Core\PublicationPostTypes::names();
		if ( empty( $public_post_types ) ) {
			return $stats;
		}

		$placeholders = implode( ',', array_fill( 0, count( $public_post_types ), '%s' ) );

		// Count published items once, grouped by public post type.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This explicit user-requested scan must reflect the current site inventory; caching would make its recommendation stale.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_type, COUNT(*) as count FROM %i WHERE post_status = 'publish' AND post_type IN ($placeholders) GROUP BY post_type", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- One placeholder is generated for each sanitized public post type.
				array_merge( array( $wpdb->posts ), $public_post_types )
			)
		);

		if ( ! is_array( $results ) || self::database_error( $wpdb ) ) {
			return null;
		}

		foreach ( $results as $row ) {
			$stats['post_types'][ $row->post_type ] = (int) $row->count;
			$stats['total_posts']                  += (int) $row->count;
		}

		return $stats;
	}

	/**
	 * Detect a failed wpdb read without treating a legitimate zero result as an
	 * empty or broken site inventory.
	 */
	private static function database_error( object $wpdb ): bool {
		return isset( $wpdb->last_error )
			&& is_scalar( $wpdb->last_error )
			&& '' !== trim( (string) $wpdb->last_error );
	}

	/**
	 * Determine site archetype based on statistics.
	 *
	 * @param array $stats Site statistics.
	 * @return string
	 */
	private function determine_archetype( $stats ) {
		$catalog_count       = self::post_type_count( $stats, self::CATALOG_POST_TYPES );
		$documentation_count = self::post_type_count( $stats, self::DOCUMENTATION_POST_TYPES );
		$portfolio_count     = self::post_type_count( $stats, self::PORTFOLIO_POST_TYPES );
		$service_count       = self::post_type_count( $stats, self::SERVICE_POST_TYPES );
		$news_count          = self::post_type_count( $stats, self::NEWS_POST_TYPES );
		$post_count          = self::post_type_count( $stats, array( 'post' ) );
		$total_posts         = isset( $stats['total_posts'] ) ? max( 0, (int) $stats['total_posts'] ) : 0;
		$post_led            = self::is_post_led( $post_count, $total_posts );

		// Explicit publication structures are stronger evidence than volume or
		// engagement. They also map directly to the profile controls the user
		// will see after applying a suggestion.
		if ( $catalog_count > 0 ) {
			return 'ecommerce';
		}

		if ( $documentation_count > 0 ) {
			return 'knowledgebase';
		}

		if ( $portfolio_count > 0 ) {
			return 'corporate';
		}

		if ( $service_count > 0 ) {
			return 'small-business';
		}

		if ( $news_count > 0 ) {
			return 'newspaper';
		}

		// High volume alone does not make a site a publication. Require Posts
		// to be the majority of the inventory before suggesting a news profile.
		if ( $post_led && $post_count > 1000 ) {
			return 'newspaper';
		}

		// A post-led site is editorial even when comments are disabled. News
		// remains the high-volume specialization handled immediately above.
		if ( $post_led ) {
			return 'blog';
		}

		if ( $total_posts < 50 ) {
			return 'small-business';
		}

		return 'medium-business';
	}

	/**
	 * Explain the concrete site evidence behind an archetype recommendation.
	 *
	 * The conditions intentionally mirror determine_archetype() so the UI does
	 * not display a generic post count beside a recommendation driven by a
	 * catalog, documentation, service, or post-led publication signal.
	 *
	 * @param array<string,mixed> $stats Site statistics.
	 * @return string
	 */
	private function recommendation_reason( array $stats ): string {
		$catalog_count       = self::post_type_count( $stats, self::CATALOG_POST_TYPES );
		$documentation_count = self::post_type_count( $stats, self::DOCUMENTATION_POST_TYPES );
		$portfolio_count     = self::post_type_count( $stats, self::PORTFOLIO_POST_TYPES );
		$service_count       = self::post_type_count( $stats, self::SERVICE_POST_TYPES );
		$news_count          = self::post_type_count( $stats, self::NEWS_POST_TYPES );
		$post_count          = self::post_type_count( $stats, array( 'post' ) );
		$total_posts         = isset( $stats['total_posts'] ) ? max( 0, (int) $stats['total_posts'] ) : 0;
		$post_led            = self::is_post_led( $post_count, $total_posts );

		if ( $catalog_count > 0 ) {
			return sprintf(
				/* translators: %s: Number of published product or download items. */
				_n(
					'Cybermaps found %s published catalog item in a product or download post type. That explicit catalog is the strongest signal for the Online Store profile.',
					'Cybermaps found %s published catalog items in product or download post types. That explicit catalog is the strongest signal for the Online Store profile.',
					$catalog_count,
					'cybermaps'
				),
				number_format_i18n( $catalog_count )
			);
		}

		if ( $documentation_count > 0 ) {
			return sprintf(
				/* translators: %s: Number of published documentation items. */
				_n(
					'Cybermaps found %s published item in a documentation post type, which directly supports the Documentation / Knowledge Base profile.',
					'Cybermaps found %s published items in documentation post types, which directly support the Documentation / Knowledge Base profile.',
					$documentation_count,
					'cybermaps'
				),
				number_format_i18n( $documentation_count )
			);
		}

		if ( $portfolio_count > 0 ) {
			return sprintf(
				/* translators: %s: Number of published portfolio or project items. */
				_n(
					'Cybermaps found %s published portfolio or project item, which points to a service-led Portfolio / Agency strategy.',
					'Cybermaps found %s published portfolio or project items, which points to a service-led Portfolio / Agency strategy.',
					$portfolio_count,
					'cybermaps'
				),
				number_format_i18n( $portfolio_count )
			);
		}

		if ( $service_count > 0 ) {
			return sprintf(
				/* translators: %s: Number of published service items. */
				_n(
					'Cybermaps found %s published item in a service post type, which directly supports the Local / Service Business profile.',
					'Cybermaps found %s published items in service post types, which directly support the Local / Service Business profile.',
					$service_count,
					'cybermaps'
				),
				number_format_i18n( $service_count )
			);
		}

		if ( $news_count > 0 ) {
			return sprintf(
				/* translators: %s: Number of published news or press items. */
				_n(
					'Cybermaps found %s published item in a news or press post type, which directly supports the News / Magazine profile.',
					'Cybermaps found %s published items in news or press post types, which directly support the News / Magazine profile.',
					$news_count,
					'cybermaps'
				),
				number_format_i18n( $news_count )
			);
		}

		if ( $post_led && $post_count > 1000 ) {
			return sprintf(
				/* translators: 1: Number of published WordPress Posts. 2: Total published items. */
				__( 'Posts lead the inventory (%1$s of %2$s published items), and that high publication volume supports the News / Magazine profile.', 'cybermaps' ),
				number_format_i18n( $post_count ),
				number_format_i18n( $total_posts )
			);
		}

		if ( $post_led ) {
			return sprintf(
				/* translators: 1: Number of published WordPress Posts. 2: Total published items. */
				__( 'Posts lead the inventory (%1$s of %2$s published items), which supports the Blog / Editorial profile.', 'cybermaps' ),
				number_format_i18n( $post_count ),
				number_format_i18n( $total_posts )
			);
		}

		if ( $total_posts < 50 ) {
			return sprintf(
				/* translators: %s: Total number of published items. */
				_n(
					'The site has %s published item, a focused inventory that matches the Local / Service Business profile.',
					'The site has %s published items, a focused inventory that matches the Local / Service Business profile.',
					$total_posts,
					'cybermaps'
				),
				number_format_i18n( $total_posts )
			);
		}

		return sprintf(
			/* translators: %s: Total number of published items. */
			__( 'The site has %s published items across a mixed, non-editorial structure, which best matches the Company / Mixed Content profile.', 'cybermaps' ),
			number_format_i18n( $total_posts )
		);
	}

	/**
	 * Count published items across a known set of post-type slugs.
	 *
	 * @param array<string,mixed> $stats Site statistics.
	 * @param string[]            $types Post-type slugs.
	 */
	private static function post_type_count( array $stats, array $types ): int {
		$post_types = isset( $stats['post_types'] ) && is_array( $stats['post_types'] )
			? $stats['post_types']
			: array();
		$count      = 0;
		foreach ( $types as $type ) {
			$count += isset( $post_types[ $type ] ) ? max( 0, (int) $post_types[ $type ] ) : 0;
		}

		return $count;
	}

	/**
	 * Treat Posts as site-leading only when they are a strict majority.
	 */
	private static function is_post_led( int $post_count, int $total_posts ): bool {
		return $post_count > 0 && $post_count > max( 0, $total_posts - $post_count );
	}

	/**
	 * Get baseline defaults for an archetype.
	 *
	 * @param string $archetype Site archetype.
	 * @return array
	 */
	public function get_archetype_defaults( $archetype ) {
		$defaults = array(
			'newspaper'       => array(
				'post'          => 0.9,
				'news'          => 0.9,
				'press'         => 0.9,
				'press_release' => 0.9,
				'press-release' => 0.9,
				'page'          => 0.4,
				'category'      => 0.8,
				'post_tag'      => 0.5,
				'post_format'   => 0.3,
			),
			'blog'            => array(
				'post'        => 0.9,
				'page'        => 0.5,
				'category'    => 0.7,
				'post_tag'    => 0.4,
				'post_format' => 0.3,
			),
			'ecommerce'       => array(
				'product'           => 1.0,
				'download'          => 1.0,
				'product_cat'       => 0.9,
				'product_tag'       => 0.6,
				'download_category' => 0.9,
				'download_tag'      => 0.6,
				'page'              => 0.7,
				'post'              => 0.5,
				'category'          => 0.4,
				'post_tag'          => 0.3,
				'post_format'       => 0.2,
			),
			'knowledgebase'   => array(
				'docs'          => 0.9,
				'documentation' => 0.9,
				'knowledgebase' => 0.9,
				'kb'            => 0.9,
				'page'          => 0.8,
				'post'          => 0.7,
				'category'      => 0.7,
				'post_tag'      => 0.4,
				'post_format'   => 0.2,
			),
			'corporate'       => array(
				'portfolio'   => 0.9,
				'project'     => 0.9,
				'service'     => 0.9,
				'page'        => 0.8,
				'post'        => 0.4,
				'category'    => 0.3,
				'post_tag'    => 0.2,
				'post_format' => 0.2,
			),
			'small-business'  => array(
				'service'     => 0.9,
				'services'    => 0.9,
				'page'        => 0.9,
				'product'     => 0.7,
				'post'        => 0.4,
				'category'    => 0.3,
				'post_tag'    => 0.2,
				'post_format' => 0.2,
			),
			'medium-business' => array(
				'page'        => 0.8,
				'service'     => 0.8,
				'product'     => 0.8,
				'portfolio'   => 0.7,
				'project'     => 0.7,
				'post'        => 0.6,
				'category'    => 0.5,
				'post_tag'    => 0.3,
				'post_format' => 0.2,
			),
		);

		return isset( $defaults[ $archetype ] ) ? $defaults[ $archetype ] : $defaults['medium-business'];
	}

	/**
	 * Get default intents for an archetype.
	 *
	 * @param string $archetype Site archetype.
	 * @return array
	 */
	public function get_archetype_intents( $archetype ) {
		$business_intents = array(
			'page'              => 'transactional',
			'product'           => 'transactional',
			'download'          => 'transactional',
			'product_cat'       => 'transactional',
			'product_tag'       => 'transactional',
			'download_category' => 'transactional',
			'download_tag'      => 'transactional',
			'service'           => 'transactional',
			'services'          => 'transactional',
			'portfolio'         => 'transactional',
			'project'           => 'transactional',
			'post'              => 'informational',
		);

		$media_intents = array(
			'post'      => 'informational',
			'page'      => 'informational',
			'category'  => 'informational',
			'portfolio' => 'transactional',
			'project'   => 'transactional',
		);

		switch ( $archetype ) {
			case 'newspaper':
			case 'blog':
			case 'knowledgebase':
				return $media_intents;
			case 'ecommerce':
			case 'corporate':
			case 'small-business':
			case 'medium-business':
			default:
				return $business_intents;
		}
	}

	/**
	 * Get all supported archetypes.
	 *
	 * @return array
	 */
	public function get_all_archetypes() {
		return array(
			'newspaper'       => __( 'News / Magazine', 'cybermaps' ),
			'blog'            => __( 'Blog / Editorial', 'cybermaps' ),
			'ecommerce'       => __( 'Online Store', 'cybermaps' ),
			'knowledgebase'   => __( 'Documentation / Knowledge Base', 'cybermaps' ),
			'corporate'       => __( 'Portfolio / Agency', 'cybermaps' ),
			'small-business'  => __( 'Local / Service Business', 'cybermaps' ),
			'medium-business' => __( 'Company / Mixed Content', 'cybermaps' ),
		);
	}

	/**
	 * Get defaults for all archetypes.
	 *
	 * @return array
	 */
	public function get_all_defaults() {
		$defaults = array();
		foreach ( array_keys( $this->get_all_archetypes() ) as $key ) {
			$defaults[ $key ] = array(
				'blueprint' => $this->get_archetype_defaults( $key ),
				'intents'   => $this->get_archetype_intents( $key ),
			);
		}
		return $defaults;
	}
}
