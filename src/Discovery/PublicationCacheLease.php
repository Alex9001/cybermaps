<?php
/** Batch-scoped runtime cache ownership for complete inventory scans. @package Cybermaps */
declare(strict_types=1);

namespace Cybermaps\Discovery;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Preserves caller entries and never deletes from a persistent backend. */
final class PublicationCacheLease {
	private ?object $cache   = null;
	private array $protected = array();
	private array $owned     = array();
	private bool $external;
	private bool $suspended = false;

	public function __construct() {
		$this->external = (bool) wp_using_ext_object_cache();
		global $wp_object_cache;
		if ( ! $this->external && is_object( $wp_object_cache ) && 'WP_Object_Cache' === get_class( $wp_object_cache ) && isset( $wp_object_cache->cache ) ) {
			$this->cache = $wp_object_cache;
			$this->protect_additions();
		}
	}

	/** Capture only new values produced during the worker's current operation. */
	public function capture(): void {
		if ( $this->suspended ) {
			return;
		}
		foreach ( $this->groups() as $group => $values ) {
			foreach ( $values as $key => $value ) {
				if ( ! isset( $this->protected[ $group ][ $key ] ) && ! array_key_exists( $key, $this->owned[ $group ] ?? array() ) ) {
					$this->owned[ $group ][ $key ] = is_object( $value ) ? clone $value : $value;
				}
			}
		}
	}

	/** Capture worker-owned values before returning control to a generator caller. */
	public function suspend(): void {
		$this->capture();
		$this->suspended = true;
	}

	/** Protect caller additions before the worker resumes. */
	public function resume(): void {
		$this->protect_additions();
		$this->suspended = false;
	}

	/** Values added by a caller while a generator is suspended remain caller-owned. */
	public function protect_additions(): void {
		foreach ( $this->groups() as $group => $values ) {
			foreach ( $values as $key => $value ) {
				if ( ! array_key_exists( $key, $this->owned[ $group ] ?? array() ) ) {
					$this->protected[ $group ][ $key ] = true;
				}
			}
		}
	}

	/** Always called in finally, including generator abandonment and failed filters. */
	public function release(): void {
		if ( $this->external ) {
			// This intentionally evicts the broad request cache, never persistent data.
			if ( function_exists( 'wp_cache_flush_runtime' ) && wp_cache_supports( 'flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
			return;
		}
		if ( empty( $this->owned ) ) {
			return;
		}
		// Native Core's magic getter returns its private cache by value. Acquire
		// one snapshot, mutate it, then write back once through the magic setter.
		// Copy-on-write duplicates array structure for affected groups, not each
		// entry's payload; caller values and raw multisite keys remain untouched.
		$entries = $this->cache->cache;
		foreach ( $this->owned as $group => $values ) {
			foreach ( $values as $key => $value ) {
				$current = $entries[ $group ][ $key ] ?? null;
				if ( $current === $value || ( is_object( $current ) && is_object( $value ) && get_object_vars( $current ) === get_object_vars( $value ) ) ) {
					// Runtime keys already include WordPress's multisite prefix.
					unset( $entries[ $group ][ $key ] );
				}
			}
		}
		$this->cache->cache = $entries;
		$this->owned        = array();
	}

	/** @return array<string,array> Only WordPress publication-related runtime groups. */
	private function groups(): array {
		$groups = array();
		foreach ( $this->cache->cache ?? array() as $group => $values ) {
			if ( is_array( $values ) && ( in_array( $group, array( 'posts', 'post_meta', 'terms', 'term_meta', 'term-queries' ), true ) || str_ends_with( $group, '_relationships' ) ) ) {
				$groups[ $group ] = $values;
			}
		}
		return $groups;
	}
}
