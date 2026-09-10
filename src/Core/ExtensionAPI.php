<?php
/**
 * Stable companion-plugin integration surface.
 *
 * @package Cybermaps\Core
 */

declare(strict_types=1);

namespace Cybermaps\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exposes versioned endpoint, eligibility, and read-only audit contracts.
 */
final class ExtensionAPI {

	/**
	 * Increment only when the public extension contract changes incompatibly.
	 */
	public const VERSION      = '2.0.0';
	public const READY_ACTION = 'cybermaps_extension_api_ready';

	/**
	 * Shared API instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Whether Core has announced that the extension API is ready.
	 *
	 * @var bool
	 */
	private bool $ready = false;

	/**
	 * Create the extension API around the canonical endpoint registry.
	 *
	 * @param EndpointRegistry $endpoints Canonical endpoint registry.
	 */
	private function __construct( private EndpointRegistry $endpoints ) {}

	/**
	 * Get the stable public API instance.
	 *
	 * Extensions may call this before or after READY_ACTION. After Core boots at
	 * init priority 0, late consumers receive the same already-ready instance.
	 *
	 * @param EndpointRegistry|null $endpoints Optional endpoint registry for initial construction.
	 */
	public static function get_instance( ?EndpointRegistry $endpoints = null ): self {
		if ( null === self::$instance ) {
			self::$instance = new self( $endpoints ?? EndpointRegistry::get_instance() );
		}

		return self::$instance;
	}

	/**
	 * Get the public extension API version.
	 */
	public function get_version(): string {
		return self::VERSION;
	}

	/**
	 * Check a companion plugin's minimum API requirement.
	 *
	 * @param string $minimum_version Minimum compatible extension API version.
	 */
	public function supports( string $minimum_version ): bool {
		return version_compare( self::VERSION, $minimum_version, '>=' );
	}

	/**
	 * Get the deliberately narrow endpoint registration contract.
	 */
	public function endpoints(): EndpointRegistry {
		return $this->endpoints;
	}

	/**
	 * Get the same final eligibility resolver used by Core publications.
	 */
	public function eligibility(): \Cybermaps\SEO\PublicationEligibility {
		return new \Cybermaps\SEO\PublicationEligibility();
	}

	/**
	 * Read preserved content report runs without exposing mutation methods.
	 */
	public function audits(): \Cybermaps\Audit\AuditReadAPI {
		return new \Cybermaps\Audit\AuditReadAPI();
	}

	/**
	 * Complete endpoint registration and announce that Core is ready.
	 */
	public function boot(): void {
		if ( $this->ready ) {
			return;
		}

		$this->endpoints->register_extension_endpoints();
		$this->ready = true;

		/**
		 * Fires once Core's public extension surface is ready.
		 *
		 * @param ExtensionAPI $api Versioned extension API.
		 */
		do_action( self::READY_ACTION, $this ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The constant value is prefixed with cybermaps_.
	}

	/**
	 * Whether the readiness action has fired.
	 */
	public function is_ready(): bool {
		return $this->ready;
	}
}
