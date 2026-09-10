<?php
declare(strict_types=1);

namespace Cybermaps\SEO;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the SEO settings emitted by Genesis and used by Mai themes.
 */
final class GenesisMaiAdapter implements SeoCompatibilityAdapter {
	public function get_id(): string {
		return 'genesis';
	}

	public function get_signals( SeoContext $context ): ?SeoSignals {
		if ( ! function_exists( 'genesis_seo_active' ) && ! function_exists( 'genesis_get_custom_field' ) ) {
			return null;
		}

		$seo_active = function_exists( 'genesis_seo_active' ) && (bool) genesis_seo_active();

		if ( SeoContext::POST === $context->type ) {
			return $this->get_post_signals( $context, $seo_active );
		}

		if ( ! $seo_active ) {
			return null;
		}

		return match ( $context->type ) {
			SeoContext::HOME => $this->get_home_signals(),
			SeoContext::TERM => $this->get_term_signals( $context ),
			SeoContext::AUTHOR => $this->get_author_signals( $context ),
			SeoContext::DATE_ARCHIVE => $this->get_date_signals(),
			SeoContext::POST_TYPE_ARCHIVE => $this->get_post_type_archive_signals( $context ),
			default => null,
		};
	}

	private function get_post_signals( SeoContext $context, bool $seo_active ): ?SeoSignals {
		$id       = $context->object_id;
		$redirect = $this->post_field( 'redirect', $id );

		if ( ! $seo_active ) {
			return '' !== $redirect
				? new SeoSignals( 'genesis_redirect', false, false, false, '', $redirect )
				: null;
		}

		return new SeoSignals(
			'genesis',
			$this->truthy( $this->post_field( '_genesis_noindex', $id ) ),
			$this->truthy( $this->post_field( '_genesis_nofollow', $id ) ),
			$this->truthy( $this->post_field( '_genesis_noarchive', $id ) ),
			$this->post_field( '_genesis_canonical_uri', $id ),
			$redirect,
			$this->post_field( '_genesis_title', $id ),
			$this->post_field( '_genesis_description', $id )
		);
	}

	private function get_home_signals(): SeoSignals {
		return new SeoSignals(
			'genesis',
			$this->truthy( $this->seo_option( 'home_noindex' ) ),
			$this->truthy( $this->seo_option( 'home_nofollow' ) ),
			$this->truthy( $this->seo_option( 'home_noarchive' ) ),
			'',
			'',
			$this->seo_option( 'home_doctitle' ),
			$this->seo_option( 'home_description' )
		);
	}

	private function get_term_signals( SeoContext $context ): SeoSignals {
		$id        = $context->object_id;
		$noindex   = $this->truthy( get_term_meta( $id, 'noindex', true ) );
		$nofollow  = $this->truthy( get_term_meta( $id, 'nofollow', true ) );
		$noarchive = $this->truthy( get_term_meta( $id, 'noarchive', true ) );

		if ( 'category' === $context->subtype ) {
			$noindex   = $noindex || $this->truthy( $this->seo_option( 'noindex_cat_archive' ) );
			$noarchive = $noarchive || $this->truthy( $this->seo_option( 'noarchive_cat_archive' ) );
		} elseif ( 'post_tag' === $context->subtype ) {
			$noindex   = $noindex || $this->truthy( $this->seo_option( 'noindex_tag_archive' ) );
			$noarchive = $noarchive || $this->truthy( $this->seo_option( 'noarchive_tag_archive' ) );
		}

		return new SeoSignals(
			'genesis',
			$noindex,
			$nofollow,
			$noarchive,
			'',
			'',
			(string) get_term_meta( $id, 'doctitle', true ),
			(string) get_term_meta( $id, 'description', true )
		);
	}

	private function get_author_signals( SeoContext $context ): SeoSignals {
		$id = $context->object_id;

		return new SeoSignals(
			'genesis',
			$this->truthy( get_user_meta( $id, 'noindex', true ) )
				|| $this->truthy( $this->seo_option( 'noindex_author_archive' ) ),
			$this->truthy( get_user_meta( $id, 'nofollow', true ) ),
			$this->truthy( get_user_meta( $id, 'noarchive', true ) )
				|| $this->truthy( $this->seo_option( 'noarchive_author_archive' ) ),
			'',
			'',
			(string) get_user_meta( $id, 'doctitle', true ),
			(string) get_user_meta( $id, 'meta_description', true )
		);
	}

	private function get_date_signals(): SeoSignals {
		return new SeoSignals(
			'genesis',
			$this->truthy( $this->seo_option( 'noindex_date_archive' ) ),
			false,
			$this->truthy( $this->seo_option( 'noarchive_date_archive' ) )
		);
	}

	private function get_post_type_archive_signals( SeoContext $context ): ?SeoSignals {
		if (
			! function_exists( 'genesis_has_post_type_archive_support' )
			|| ! genesis_has_post_type_archive_support( $context->subtype )
			|| ! function_exists( 'genesis_get_cpt_option' )
		) {
			return null;
		}

		return new SeoSignals(
			'genesis',
			$this->truthy( genesis_get_cpt_option( 'noindex', $context->subtype ) ),
			$this->truthy( genesis_get_cpt_option( 'nofollow', $context->subtype ) ),
			$this->truthy( genesis_get_cpt_option( 'noarchive', $context->subtype ) ),
			'',
			'',
			(string) genesis_get_cpt_option( 'doctitle', $context->subtype ),
			(string) genesis_get_cpt_option( 'description', $context->subtype )
		);
	}

	private function post_field( string $key, int $post_id ): string {
		if ( function_exists( 'genesis_get_custom_field' ) ) {
			$value = genesis_get_custom_field( $key, $post_id );
		} else {
			$value = get_post_meta( $post_id, $key, true );
		}

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	private function seo_option( string $key ): string {
		if ( function_exists( 'genesis_get_seo_option' ) ) {
			$value = genesis_get_seo_option( $key );
		} else {
			$options = get_option( 'genesis-seo-settings', array() );
			$value   = is_array( $options ) ? ( $options[ $key ] ?? '' ) : '';
		}

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	private function truthy( mixed $value ): bool {
		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'true', 'yes', 'on', 'noindex', 'nofollow', 'noarchive' ), true );
	}
}
