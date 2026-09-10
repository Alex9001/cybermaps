<?php
declare(strict_types=1);

namespace Cybermaps\SEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalizes active Yoast, Rank Math, and AIOSEO singular directives.
 */
final class PluginSeoAdapter implements SeoCompatibilityAdapter {
	public function get_id(): string {
		return 'seo_plugins';
	}

	public function get_signals( SeoContext $context ): ?SeoSignals {
		if ( SeoContext::POST !== $context->type || $context->object_id < 1 ) {
			return null;
		}

		$noindex   = false;
		$canonical = '';
		$sources   = array();
		$id        = $context->object_id;

		if ( $this->yoast_active() ) {
			$yoast = $this->yoast_signals( $id );
			if ( $yoast['noindex'] ) {
				$noindex   = true;
				$sources[] = 'yoast';
			}
			$canonical = $this->first_url( $canonical, $yoast['canonical'] );
		}

		if ( $this->rank_math_active() ) {
			$rank_math = $this->rank_math_signals( $context );
			if ( $rank_math['noindex'] ) {
				$noindex   = true;
				$sources[] = 'rank_math';
			}
			$canonical = $this->first_url( $canonical, $rank_math['canonical'] );
		}

		if ( $this->aioseo_active() ) {
			$aioseo = $this->aioseo_signals( $context );
			if ( $aioseo['noindex'] ) {
				$noindex   = true;
				$sources[] = 'aioseo';
			}
			$canonical = $this->first_url( $canonical, $aioseo['canonical'] );
		}

		if ( ! $noindex && '' === $canonical ) {
			return null;
		}

		return new SeoSignals(
			'seo_plugins',
			$noindex,
			false,
			false,
			$canonical,
			'',
			'',
			'',
			array( 'noindex_sources' => $sources )
		);
	}

	private function yoast_active(): bool {
		return defined( 'WPSEO_VERSION' )
			|| function_exists( 'YoastSEO' )
			|| class_exists( 'WPSEO_Meta', false )
			|| class_exists( 'WPSEO_Options', false );
	}

	private function rank_math_active(): bool {
		return defined( 'RANK_MATH_VERSION' )
			|| class_exists( 'RankMath', false )
			|| class_exists( '\RankMath\Post' );
	}

	private function aioseo_active(): bool {
		return defined( 'AIOSEO_VERSION' )
			|| function_exists( 'aioseo' )
			|| class_exists( '\AIOSEO\Plugin\Common\Models\Post' );
	}

	/**
	 * Read Yoast's effective indexable surface when available, with a legacy
	 * post-meta fallback for installations whose indexables are not built yet.
	 *
	 * @return array{noindex:bool,canonical:string}
	 */
	private function yoast_signals( int $post_id ): array {
		if ( function_exists( 'YoastSEO' ) ) {
			$signals = $this->yoast_indexable_signals( $post_id );
			if ( null !== $signals ) {
				return $signals;
			}
		}
		return $this->yoast_meta_signals( $post_id );
	}

	/** @return array{noindex:bool,canonical:string}|null */
	private function yoast_indexable_signals( int $post_id ): ?array {
		try {
			$yoast = \YoastSEO();
			if ( ! is_object( $yoast ) || ! isset( $yoast->meta ) || ! is_object( $yoast->meta ) || ! method_exists( $yoast->meta, 'for_post' ) ) {
				return null;
			}
			$meta = $yoast->meta->for_post( $post_id );
			if ( ! is_object( $meta ) ) {
				return null;
			}
			$robots = isset( $meta->robots ) && is_array( $meta->robots ) ? $meta->robots : array();
			return array(
				'noindex'   => 'noindex' === strtolower( (string) ( $robots['index'] ?? '' ) ),
				'canonical' => is_scalar( $meta->canonical ?? null ) ? trim( (string) $meta->canonical ) : '',
			);
		} catch ( \Throwable $error ) {
			unset( $error );
			return null;
		}
	}

	/** @return array{noindex:bool,canonical:string} */
	private function yoast_meta_signals( int $post_id ): array {

		$noindex   = get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true );
		$canonical = get_post_meta( $post_id, '_yoast_wpseo_canonical', true );
		if ( class_exists( 'WPSEO_Meta', false ) && method_exists( 'WPSEO_Meta', 'get_value' ) ) {
			try {
				$noindex   = \WPSEO_Meta::get_value( 'meta-robots-noindex', $post_id );
				$canonical = \WPSEO_Meta::get_value( 'canonical', $post_id );
			} catch ( \Throwable $error ) {
				unset( $error );
			}
		}

		return array(
			'noindex'   => '1' === (string) $noindex,
			'canonical' => is_scalar( $canonical ) ? trim( (string) $canonical ) : '',
		);
	}

	/**
	 * Read Rank Math's post overrides and content-type defaults.
	 *
	 * @return array{noindex:bool,canonical:string}
	 */
	private function rank_math_signals( SeoContext $context ): array {
		$post_id    = $context->object_id;
		$robots     = get_post_meta( $post_id, 'rank_math_robots', true );
		$canonical  = get_post_meta( $post_id, 'rank_math_canonical_url', true );
		$post_class = '\RankMath\Post';

		if ( class_exists( $post_class ) && method_exists( $post_class, 'get_meta' ) ) {
			try {
				$robots    = $post_class::get_meta( 'robots', $post_id );
				$canonical = $post_class::get_meta( 'canonical_url', $post_id );
			} catch ( \Throwable $error ) {
				unset( $error );
			}
		}

		$robots = $this->normalize_robots( $robots );
		$helper = '\RankMath\Helper';
		if (
			empty( $robots )
			&& '' !== $context->subtype
			&& class_exists( $helper )
			&& method_exists( $helper, 'get_settings' )
		) {
			try {
				if ( $helper::get_settings( 'titles.pt_' . $context->subtype . '_custom_robots' ) ) {
					$robots = $this->normalize_robots(
						$helper::get_settings( 'titles.pt_' . $context->subtype . '_robots' )
					);
				}
			} catch ( \Throwable $error ) {
				unset( $error );
			}
		}

		return array(
			'noindex'   => in_array( 'noindex', $robots, true ),
			'canonical' => is_scalar( $canonical ) ? trim( (string) $canonical ) : '',
		);
	}

	/**
	 * Read AIOSEO 4's current model rather than obsolete post-meta guesses.
	 *
	 * @return array{noindex:bool,canonical:string}
	 */
	private function aioseo_signals( SeoContext $context ): array {
		$model_class = '\AIOSEO\Plugin\Common\Models\Post';
		if ( ! class_exists( $model_class ) || ! method_exists( $model_class, 'getPost' ) ) {
			return array(
				'noindex'   => false,
				'canonical' => '',
			);
		}

		try {
			$model = $model_class::getPost( $context->object_id );
			if ( ! is_object( $model ) ) {
				return array(
					'noindex'   => false,
					'canonical' => '',
				);
			}

			$uses_default = ! empty( $model->robots_default );
			$noindex      = ! $uses_default && ! empty( $model->robots_noindex );
			if ( $uses_default && function_exists( 'aioseo' ) ) {
				$app = \aioseo();
				if (
					is_object( $app )
					&& isset( $app->helpers )
					&& is_object( $app->helpers )
					&& method_exists( $app->helpers, 'isPostTypeNoindexed' )
				) {
					$noindex = (bool) $app->helpers->isPostTypeNoindexed( $context->subtype );
				}
			}

			return array(
				'noindex'   => $noindex,
				'canonical' => is_scalar( $model->canonical_url ?? null )
					? trim( (string) $model->canonical_url )
					: '',
			);
		} catch ( \Throwable $error ) {
			unset( $error );
			return array(
				'noindex'   => false,
				'canonical' => '',
			);
		}
	}

	/**
	 * Normalize serialized, array, or comma-separated robots values.
	 *
	 * @return string[]
	 */
	private function normalize_robots( mixed $robots ): array {
		if ( is_string( $robots ) ) {
			$robots = preg_split( '/[\s,]+/', $robots );
		}
		if ( ! is_array( $robots ) ) {
			return array();
		}

		return array_values(
			array_unique(
				array_filter(
					array_map(
						static fn( $value ): string => is_scalar( $value )
							? sanitize_key( (string) $value )
							: '',
						$robots
					)
				)
			)
		);
	}

	private function first_url( string $current, mixed $candidate ): string {
		if ( '' !== $current || ! is_scalar( $candidate ) ) {
			return $current;
		}

		return trim( (string) $candidate );
	}
}
