<?php
declare(strict_types=1);

namespace Cybermaps\SEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Produces one effective indexability decision for every publication surface.
 */
final class IndexabilityResolver {
	/**
	 * @var SeoCompatibilityAdapter[]
	 */
	private array $adapters;

	/**
	 * @param SeoCompatibilityAdapter[]|null $adapters Compatibility adapters.
	 */
	public function __construct( ?array $adapters = null ) {
		$defaults = $adapters ?? array(
			new GenesisMaiAdapter(),
			new PluginSeoAdapter(),
		);

		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'cybermaps_seo_compatibility_adapters', $defaults );
			if ( is_array( $filtered ) ) {
				$defaults = $filtered;
			}
		}

		$this->adapters = array_values(
			array_filter(
				$defaults,
				static fn( $adapter ): bool => $adapter instanceof SeoCompatibilityAdapter
			)
		);
	}

	public function resolve( SeoContext $context ): IndexabilityDecision {
		$reasons   = $this->base_reasons( $context );
		$aggregate = $this->adapter_signals( $context, $reasons );
		$reasons   = $this->conflict_reasons( $reasons, $aggregate['canonicals'], $aggregate['redirects'] );

		$reasons  = array_values( array_unique( array_filter( $reasons ) ) );
		$decision = new IndexabilityDecision( empty( $reasons ), $reasons, $aggregate['canonical'], $aggregate['redirect'], $aggregate['signals'] );

		if ( function_exists( 'apply_filters' ) ) {
			$filtered = apply_filters( 'cybermaps_indexability_decision', $decision, $context, $aggregate['signals'] );
			if ( $filtered instanceof IndexabilityDecision ) {
				$decision = $filtered;
			}
		}

		return $decision;
	}

	/** @param string[] $reasons @return array<string,mixed> */
	private function adapter_signals( SeoContext $context, array &$reasons ): array {
		$signals    = array();
		$canonical  = '';
		$redirect   = '';
		$canonicals = array();
		$redirects  = array();
		foreach ( $this->adapters as $adapter ) {
			$current = $adapter->get_signals( $context );
			if ( ! $current instanceof SeoSignals ) {
				continue;
			}
			$signals[] = $current;
			$this->add_signal_reasons( $context, $current, $reasons, $canonicals, $redirects, $canonical, $redirect );
		}
		return compact( 'signals', 'canonical', 'redirect', 'canonicals', 'redirects' );
	}

	/** @param string[] $reasons @param string[] $canonicals @param string[] $redirects */
	private function add_signal_reasons( SeoContext $context, SeoSignals $signal, array &$reasons, array &$canonicals, array &$redirects, string &$canonical, string &$redirect ): void {
		if ( $signal->noindex ) {
			$reasons[] = 'noindex:' . $signal->source;
		}
		if ( '' !== $signal->redirect ) {
			$redirects[] = $signal->redirect;
			$redirect    = $signal->redirect;
			$reasons[]   = 'redirect:' . $signal->source;
		}
		if ( '' === $signal->canonical ) {
			return;
		}
		$canonicals[] = $signal->canonical;
		$canonical    = $signal->canonical;
		if ( ! $this->same_url( $context->url, $canonical ) ) {
			$reasons[] = 'canonical_other:' . $signal->source;
		}
	}

	/** @param string[] $reasons @param string[] $canonicals @param string[] $redirects @return string[] */
	private function conflict_reasons( array $reasons, array $canonicals, array $redirects ): array {
		if ( count( array_unique( array_filter( $canonicals, static fn( string $url ): bool => '' !== trim( $url ) ) ) ) > 1 ) {
			$reasons[] = 'canonical_conflict';
		}
		if ( count( array_unique( array_filter( $redirects, static fn( string $url ): bool => '' !== trim( $url ) ) ) ) > 1 ) {
			$reasons[] = 'redirect_conflict';
		}
		return $reasons;
	}

	/**
	 * @return string[]
	 */
	private function base_reasons( SeoContext $context ): array {
		$reasons = array();

		if ( '0' === (string) get_option( 'blog_public', '1' ) ) {
			$reasons[] = 'site_discourages_search';
		}

		if ( '' === trim( $context->url ) ) {
			$reasons[] = 'missing_public_url';
		}

		if ( SeoContext::POST === $context->type ) {
			$reasons = array_merge( $reasons, $this->post_reasons( $context ) );
		} elseif ( SeoContext::TERM === $context->type ) {
			$reasons = array_merge( $reasons, $this->term_reasons( $context ) );
		}

		return $reasons;
	}

	/** @return string[] */
	private function post_reasons( SeoContext $context ): array {
		$post = is_object( $context->object ) ? $context->object : get_post( $context->object_id );
		if ( ! is_object( $post ) ) {
			return array( 'missing_post' );
		}
		$reasons = array();
		if ( ! isset( $post->post_status ) || 'publish' !== (string) $post->post_status ) {
			$reasons[] = 'post_status';
		}
		if ( isset( $post->post_password ) && '' !== (string) $post->post_password ) {
			$reasons[] = 'password_protected';
		}
		if ( isset( $post->post_type ) ) {
			$type = get_post_type_object( (string) $post->post_type );
			if ( is_object( $type ) && isset( $type->public ) && ! $type->public ) {
				$reasons[] = 'post_type_not_public';
			}
		}
		return $reasons;
	}

	/** @return string[] */
	private function term_reasons( SeoContext $context ): array {
		$taxonomy = get_taxonomy( $context->subtype );
		return is_object( $taxonomy ) && isset( $taxonomy->public ) && ! $taxonomy->public ? array( 'taxonomy_not_public' ) : array();
	}

	private function same_url( string $left, string $right ): bool {
		if ( '' === trim( $left ) || '' === trim( $right ) ) {
			return false;
		}

		return $this->normalize_url( $left ) === $this->normalize_url( $right );
	}

	private function normalize_url( string $url ): string {
			$parts = wp_parse_url( trim( $url ) );
		if ( ! is_array( $parts ) ) {
			return rtrim( trim( $url ), '/' );
		}

		$scheme = strtolower( (string) ( $parts['scheme'] ?? 'https' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		$port   = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path   = '/' . ltrim( (string) ( $parts['path'] ?? '/' ), '/' );
		$path   = '/' === $path ? '/' : rtrim( $path, '/' );
		$query  = isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '';

		if ( ( 'https' === $scheme && ':443' === $port ) || ( 'http' === $scheme && ':80' === $port ) ) {
			$port = '';
		}

		return $scheme . '://' . $host . $port . $path . $query;
	}
}
