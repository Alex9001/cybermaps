<?php
declare(strict_types=1);

namespace Cybermaps\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared standalone admin-page heading.
 */
final class PageHeader {
	/**
	 * @param string                                                 $title       Page title.
	 * @param string                                                 $description One-sentence purpose.
	 * @param array<int,array{label:string,tone?:string}>             $badges      Status badges.
	 * @param array<int,array{label:string,url:string,primary?:bool}> $actions     Page actions.
	 */
	public static function render( string $title, string $description, array $badges = array(), array $actions = array() ): void {
		?>
		<h1 class="screen-reader-text"><?php echo esc_html( $title ); ?></h1>
		<header class="cm-page-header">
			<div class="cm-page-header-main">
				<div class="cm-page-header-copy">
					<div class="cm-page-eyebrow"><?php esc_html_e( 'Cybermaps', 'cybermaps' ); ?></div>
					<h2><?php echo esc_html( $title ); ?></h2>
					<p><?php echo esc_html( $description ); ?></p>
				</div>
				<?php if ( ! empty( $actions ) ) : ?>
					<div class="cm-page-header-actions">
						<?php foreach ( $actions as $action ) : ?>
							<a
								class="button <?php echo ! empty( $action['primary'] ) ? 'button-primary' : 'button-secondary'; ?>"
								href="<?php echo esc_url( (string) ( $action['url'] ?? '' ) ); ?>"
							>
								<?php echo esc_html( (string) ( $action['label'] ?? '' ) ); ?>
							</a>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
			<?php if ( ! empty( $badges ) ) : ?>
				<div class="cm-page-header-meta" aria-label="<?php esc_attr_e( 'Page status', 'cybermaps' ); ?>">
					<?php
					foreach ( $badges as $badge ) :
						$tone = self::badge_tone( $badge['tone'] ?? 'neutral' );
						?>
						<span class="cm-page-badge cm-page-badge-<?php echo esc_attr( $tone ); ?>">
							<?php echo esc_html( (string) ( $badge['label'] ?? '' ) ); ?>
						</span>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</header>
		<?php
	}

	/**
	 * Normalize a badge tone to the finite CSS vocabulary.
	 *
	 * @param mixed $tone Requested tone.
	 */
	private static function badge_tone( $tone ): string {
		$tone = sanitize_key( is_scalar( $tone ) ? (string) $tone : 'neutral' );
		return in_array( $tone, array( 'neutral', 'good', 'warning', 'error', 'info' ), true )
			? $tone
			: 'neutral';
	}
}
