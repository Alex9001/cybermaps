<?php
declare(strict_types=1);
namespace Cybermaps\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HTML Sitemap Shortcode Builder.
 *
 * Renders the admin UI for building [cybermap] shortcodes with
 * post type selectors, display options, and a live preview.
 */
class ShortcodeBuilder {

	/**
	 * Render the shortcode builder admin page.
	 */
	public static function render(): void {
		$options           = \Cybermaps\Core\ConfigurationStore::settings();
		$enabled           = ! empty( $options['enable_shortcode'] );
		$public_post_types = \Cybermaps\Core\PublicationPostTypes::objects();
		$public_taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
		?>
		<div id="cybermaps-shortcode-builder" class="cm-card cm-mb-20 cm-shortcode-workspace">
			<header class="cm-shortcode-intro">
				<span class="cm-shortcode-eyebrow"><?php esc_html_e( 'Human-readable site navigation', 'cybermaps' ); ?></span>
				<h2><?php esc_html_e( 'HTML Sitemap Shortcode Builder', 'cybermaps' ); ?></h2>
				<p class="cm-text-sm cm-text-muted">
					<?php esc_html_e( 'Build a human-readable HTML sitemap using the [cybermap] shortcode. Configure the options below, copy the generated shortcode, and paste it into any page or post.', 'cybermaps' ); ?>
				</p>
			</header>
			<p class="cm-section-prose cm-shortcode-scope-note">
				<?php
				printf(
					/* translators: %s: Content Discovery Strategy administration URL. */
					wp_kses_post( __( 'The generated shortcode is a selection request. Front-end output is also narrowed by global <a href="%s">Content Discovery Strategy</a> Publish choices, public indexability, and sitemap-channel exclusions.', 'cybermaps' ) ),
					esc_url( admin_url( 'admin.php?page=cybermaps-settings&tab=sitemaps#cybermaps-content-discovery-strategy' ) )
				);
				?>
			</p>

			<section class="cm-shortcode-availability<?php echo $enabled ? '' : ' is-disabled'; ?>" aria-labelledby="cm-shortcode-availability-title">
				<div class="cm-shortcode-availability-copy">
					<h3 id="cm-shortcode-availability-title"><?php esc_html_e( 'Front-end availability', 'cybermaps' ); ?></h3>
					<label class="cm-toggle-wrapper">
						<input type="checkbox" name="cybermaps_settings[enable_shortcode]" value="1" class="cm-toggle-input" <?php checked( $enabled, true ); ?>>
						<span class="cm-toggle-switch"></span>
						<span class="cm-toggle-label"><strong><?php esc_html_e( 'Enable [cybermap] HTML Sitemap Shortcode', 'cybermaps' ); ?></strong></span>
					</label>
					<?php if ( ! $enabled ) : ?>
						<p class="cm-shortcode-disabled-note">
							<strong><?php esc_html_e( 'Currently disabled.', 'cybermaps' ); ?></strong>
							<?php esc_html_e( 'Turn on the toggle and save to activate shortcodes already placed on the site.', 'cybermaps' ); ?>
						</p>
					<?php endif; ?>
					<p class="cm-text-xs cm-text-muted">
						<?php esc_html_e( 'Only this availability toggle is saved here. Every builder choice is persisted in the shortcode you copy.', 'cybermaps' ); ?>
					</p>
				</div>
				<button type="submit" class="button button-primary cm-shortcode-save-button">
					<?php esc_html_e( 'Save HTML Sitemap availability', 'cybermaps' ); ?>
				</button>
			</section>

			<div class="cm-grid cm-gap-20 cm-shortcode-builder-grid">

				<div class="cm-shortcode-options-column">
					<h3><?php esc_html_e( 'Content Selection', 'cybermaps' ); ?></h3>

					<div class="cm-shortcode-content-grid">
						<fieldset class="cm-card-sm">
							<legend><strong><?php esc_html_e( 'Post Types', 'cybermaps' ); ?></strong></legend>
							<div class="cm-flex cm-flex-col cm-gap-5">
								<label><input type="checkbox" class="cm-sc-post-type" data-pt="*" data-label="<?php esc_attr_e( 'all publishable public post types', 'cybermaps' ); ?>" checked> <?php esc_html_e( 'All Publishable Public Post Types', 'cybermaps' ); ?></label>
								<?php foreach ( $public_post_types as $pt ) : ?>
									<?php self::render_post_type_option( $pt ); ?>
								<?php endforeach; ?>
							</div>
						</fieldset>

						<fieldset class="cm-card-sm">
							<legend><strong><?php esc_html_e( 'Taxonomy Archives', 'cybermaps' ); ?></strong></legend>
							<div class="cm-flex cm-flex-col cm-gap-5">
								<label><input type="checkbox" class="cm-sc-tax" data-tax="*" data-label="<?php esc_attr_e( 'all public taxonomy archives', 'cybermaps' ); ?>"> <?php esc_html_e( 'All Public Taxonomies', 'cybermaps' ); ?></label>
								<?php foreach ( $public_taxonomies as $tax ) : ?>
									<?php self::render_taxonomy_option( $tax ); ?>
								<?php endforeach; ?>
							</div>
						</fieldset>
					</div>

					<section aria-labelledby="cm-shortcode-display-title">
						<h3 id="cm-shortcode-display-title"><?php esc_html_e( 'Display Options', 'cybermaps' ); ?></h3>
						<div class="cm-card-sm cm-shortcode-display-grid">
							<label class="cm-shortcode-control" id="cm-sc-mode-wrap">
								<strong><?php esc_html_e( 'Display Mode', 'cybermaps' ); ?></strong>
								<select class="cm-sc-mode">
									<option value="hierarchical"><?php esc_html_e( 'Hierarchical Tree (nested pages)', 'cybermaps' ); ?></option>
									<option value="flat"><?php esc_html_e( 'Flat List (all items at same level)', 'cybermaps' ); ?></option>
								</select>
							</label>

							<label class="cm-shortcode-control">
								<strong><?php esc_html_e( 'Item Limit', 'cybermaps' ); ?></strong>
								<input type="text" inputmode="numeric" class="cm-sc-limit" value="50" data-min="1" data-max="500" aria-describedby="cm-sc-limit-help">
								<span id="cm-sc-limit-help" class="cm-text-xs cm-text-muted"><?php esc_html_e( 'Total links across all selected sections (1–500).', 'cybermaps' ); ?></span>
							</label>

							<label class="cm-shortcode-control" id="cm-sc-depth-wrap">
								<strong><?php esc_html_e( 'Max Depth', 'cybermaps' ); ?></strong>
								<input type="text" inputmode="numeric" class="cm-sc-depth" value="0" data-min="0" data-max="10" aria-describedby="cm-sc-depth-help">
								<span id="cm-sc-depth-help" class="cm-text-xs cm-text-muted"><?php esc_html_e( 'List/Columns only: 0 = full hierarchy; 1+ = level cap.', 'cybermaps' ); ?></span>
							</label>

							<label class="cm-shortcode-control">
								<strong><?php esc_html_e( 'Sort Order', 'cybermaps' ); ?></strong>
								<select class="cm-sc-sort">
									<option value="asc"><?php esc_html_e( 'A → Z (Ascending)', 'cybermaps' ); ?></option>
									<option value="desc"><?php esc_html_e( 'Z → A (Descending)', 'cybermaps' ); ?></option>
								</select>
							</label>

							<label class="cm-shortcode-control">
								<strong><?php esc_html_e( 'Layout', 'cybermaps' ); ?></strong>
								<select class="cm-sc-layout">
									<option value="list"><?php esc_html_e( 'List (styled sections)', 'cybermaps' ); ?></option>
									<option value="columns"><?php esc_html_e( 'Columns (side by side)', 'cybermaps' ); ?></option>
									<option value="bare"><?php esc_html_e( 'Bare (plain flat bullets)', 'cybermaps' ); ?></option>
								</select>
							</label>

							<label class="cm-shortcode-control">
								<strong><?php esc_html_e( 'Link Attribute', 'cybermaps' ); ?></strong>
								<select class="cm-sc-nofollow">
									<option value="false"><?php esc_html_e( 'Follow (default)', 'cybermaps' ); ?></option>
									<option value="true"><?php esc_html_e( 'Nofollow', 'cybermaps' ); ?></option>
								</select>
							</label>

							<label class="cm-shortcode-control cm-shortcode-control-wide">
								<strong><?php esc_html_e( 'Exclude', 'cybermaps' ); ?></strong>
								<input type="text" class="cm-sc-exclude" placeholder="<?php esc_attr_e( 'e.g. 12, about*, contact', 'cybermaps' ); ?>" aria-describedby="cm-sc-exclude-help cm-sc-exclude-error">
								<span id="cm-sc-exclude-help" class="cm-text-xs cm-text-muted"><?php esc_html_e( 'Comma-separated IDs, slugs, or wildcards (e.g. old-*).', 'cybermaps' ); ?></span>
								<span id="cm-sc-exclude-error" class="cm-shortcode-field-error" role="status" aria-live="polite" hidden></span>
							</label>

							<div class="cm-shortcode-control cm-shortcode-control-wide cm-shortcode-title-control">
								<label class="cm-toggle-wrapper">
									<input type="checkbox" class="cm-toggle-input cm-sc-display-title" checked>
									<span class="cm-toggle-switch"></span>
									<span class="cm-toggle-label"><strong><?php esc_html_e( 'Show Section Headings', 'cybermaps' ); ?></strong></span>
								</label>
								<span class="cm-text-xs cm-text-muted"><?php esc_html_e( 'Displays a heading and item count above each rendered section.', 'cybermaps' ); ?></span>
							</div>
						</div>
					</section>
				</div>

				<div class="cm-shortcode-preview-column">
					<section class="cm-shortcode-output-card" aria-labelledby="cm-shortcode-output-title">
						<header class="cm-shortcode-output-header">
							<div>
								<span class="cm-shortcode-eyebrow"><?php esc_html_e( 'Ready to paste', 'cybermaps' ); ?></span>
								<h3 id="cm-shortcode-output-title"><?php esc_html_e( 'Generated Shortcode', 'cybermaps' ); ?></h3>
							</div>
							<button type="button" class="button cm-copy-btn cm-shortcode-copy-button" aria-controls="cm-shortcode-preview">
								<?php esc_html_e( 'Copy', 'cybermaps' ); ?>
							</button>
						</header>
						<div class="cm-shortcode-generated" dir="ltr">
							<code id="cm-shortcode-preview" dir="ltr">[cybermap]</code>
						</div>
						<span id="cm-shortcode-copy-status" class="screen-reader-text" role="status" aria-live="polite"></span>
						<p class="cm-shortcode-output-summary" id="cm-shortcode-desc" role="status" aria-live="polite">
							<?php esc_html_e( 'Displays all publishable public post types in a hierarchical tree.', 'cybermaps' ); ?>
						</p>
					</section>

					<section class="cm-shortcode-examples-section" aria-labelledby="cm-shortcode-examples-title">
						<h3 id="cm-shortcode-examples-title"><?php esc_html_e( 'Usage Examples', 'cybermaps' ); ?></h3>
						<div class="cm-shortcode-examples">
							<div class="cm-shortcode-example">
								<code dir="ltr">[cybermap only="post_type:post,post_type:page" limit="30" sort="desc"]</code>
								<span><?php esc_html_e( 'Posts and pages, max 30 items, Z→A, hierarchical.', 'cybermaps' ); ?></span>
							</div>
							<div class="cm-shortcode-example">
								<code dir="ltr">[cybermap depth="-1" only="post_type:post" limit="100" exclude="12, old-*" nofollow="true"]</code>
								<span><?php esc_html_e( 'Flat list of posts, max 100, nofollow, excluding ID 12 and "old-*" slugs.', 'cybermaps' ); ?></span>
							</div>
							<div class="cm-shortcode-example">
								<code dir="ltr">[cybermap only="taxonomy:category,taxonomy:post_tag" limit="200" depth="-1" display_title="false"]</code>
								<span><?php esc_html_e( 'Flat list of taxonomy terms, max 200, no heading.', 'cybermaps' ); ?></span>
							</div>
							<div class="cm-shortcode-example">
								<code dir="ltr">[cybermap only="post_type:post,post_type:page" layout="columns" limit="40"]</code>
								<span><?php esc_html_e( 'Posts and pages in two-column grid, 40 items total.', 'cybermaps' ); ?></span>
							</div>
						</div>
					</section>
				</div>
			</div>

			<section class="cm-shortcode-reference" aria-labelledby="cm-shortcode-reference-title">
				<header class="cm-shortcode-reference-header">
					<div>
						<span class="cm-shortcode-eyebrow"><?php esc_html_e( 'Quick reference', 'cybermaps' ); ?></span>
						<h3 id="cm-shortcode-reference-title"><?php esc_html_e( 'Shortcode Attributes', 'cybermaps' ); ?></h3>
					</div>
					<p><?php esc_html_e( 'Defaults can be omitted. Add only the attributes you want to change.', 'cybermaps' ); ?></p>
				</header>
				<dl class="cm-shortcode-attribute-grid">
					<div class="cm-shortcode-attribute-card is-wide">
						<dt><code dir="ltr">only</code><span><strong><?php esc_html_e( 'Default', 'cybermaps' ); ?></strong> <?php esc_html_e( 'All publishable public post types', 'cybermaps' ); ?></span></dt>
						<dd><?php echo wp_kses_post( __( 'Comma-separated <code>post_type:slug</code> or <code>taxonomy:slug</code> tokens; <code>post_type:*</code> and <code>taxonomy:*</code> are kind wildcards, and legacy unprefixed slugs remain supported', 'cybermaps' ) ); ?></dd>
					</div>
					<div class="cm-shortcode-attribute-card">
						<dt><code dir="ltr">exclude</code><span><strong><?php esc_html_e( 'Default', 'cybermaps' ); ?></strong> <?php esc_html_e( 'None', 'cybermaps' ); ?></span></dt>
						<dd><?php esc_html_e( 'IDs, slugs, or wildcards (e.g. old-*)', 'cybermaps' ); ?></dd>
					</div>
					<div class="cm-shortcode-attribute-card">
						<dt><code dir="ltr">limit</code><span><strong><?php esc_html_e( 'Default', 'cybermaps' ); ?></strong> 50</span></dt>
						<dd><?php esc_html_e( 'Maximum total links across every rendered section (1–500)', 'cybermaps' ); ?></dd>
					</div>
					<div class="cm-shortcode-attribute-card">
						<dt><code dir="ltr">depth</code><span><strong><?php esc_html_e( 'Default', 'cybermaps' ); ?></strong> 0</span></dt>
						<dd><?php esc_html_e( '-1 = flat, 0 = full hierarchy, 1+ = level cap; Bare is always flat', 'cybermaps' ); ?></dd>
					</div>
					<div class="cm-shortcode-attribute-card">
						<dt><code dir="ltr">sort</code><span><strong><?php esc_html_e( 'Default', 'cybermaps' ); ?></strong> asc</span></dt>
						<dd><?php esc_html_e( 'Sort order: asc (A→Z) or desc (Z→A)', 'cybermaps' ); ?></dd>
					</div>
					<div class="cm-shortcode-attribute-card">
						<dt><code dir="ltr">nofollow</code><span><strong><?php esc_html_e( 'Default', 'cybermaps' ); ?></strong> false</span></dt>
						<dd><?php esc_html_e( 'Add rel="nofollow" to all links', 'cybermaps' ); ?></dd>
					</div>
					<div class="cm-shortcode-attribute-card">
						<dt><code dir="ltr">display_title</code><span><strong><?php esc_html_e( 'Default', 'cybermaps' ); ?></strong> true</span></dt>
						<dd><?php esc_html_e( 'Show section headings with item counts', 'cybermaps' ); ?></dd>
					</div>
					<div class="cm-shortcode-attribute-card">
						<dt><code dir="ltr">layout</code><span><strong><?php esc_html_e( 'Default', 'cybermaps' ); ?></strong> list</span></dt>
						<dd><?php esc_html_e( 'list, columns, or bare (plain flat bullets)', 'cybermaps' ); ?></dd>
					</div>
				</dl>
			</section>
		</div>
		<?php
	}

	/**
	 * Render one post-type selection row.
	 *
	 * @param object $post_type Public post-type object.
	 */
	private static function render_post_type_option( object $post_type ): void {
		$available = \Cybermaps\Sitemap\PriorityEngine::calculate(
			\Cybermaps\Sitemap\ProviderIdentity::post_type( (string) $post_type->name )
		) > 0;
		?>
		<label class="<?php echo $available ? '' : 'cm-shortcode-option-unavailable'; ?>"><input type="checkbox" class="cm-sc-post-type" data-pt="<?php echo esc_attr( 'post_type:' . $post_type->name ); ?>" data-label="<?php echo esc_attr( $post_type->labels->name ); ?>"<?php echo $available ? '' : ' data-unavailable="1" disabled'; ?>> <?php echo esc_html( $post_type->labels->name ); ?>
		<?php if ( ! $available ) : ?>
			<span><?php esc_html_e( '(Publish is off globally)', 'cybermaps' ); ?></span>
		<?php endif; ?></label>
		<?php
	}

	/**
	 * Render one taxonomy selection row.
	 *
	 * @param object $taxonomy Public taxonomy object.
	 */
	private static function render_taxonomy_option( object $taxonomy ): void {
		$label     = 'post_format' === $taxonomy->name ? __( 'Post Formats', 'cybermaps' ) : $taxonomy->labels->name;
		$available = \Cybermaps\Sitemap\PriorityEngine::calculate(
			\Cybermaps\Sitemap\ProviderIdentity::taxonomy( (string) $taxonomy->name )
		) > 0;
		?>
		<label class="<?php echo $available ? '' : 'cm-shortcode-option-unavailable'; ?>"><input type="checkbox" class="cm-sc-tax" data-tax="<?php echo esc_attr( 'taxonomy:' . $taxonomy->name ); ?>" data-label="<?php echo esc_attr( $label ); ?>"<?php echo $available ? '' : ' data-unavailable="1" disabled'; ?>> <?php echo esc_html( $label ); ?>
		<?php if ( ! $available ) : ?>
			<span><?php esc_html_e( '(Publish is off globally)', 'cybermaps' ); ?></span>
		<?php endif; ?></label>
		<?php
	}
}
