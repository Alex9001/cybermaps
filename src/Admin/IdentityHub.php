<?php
declare(strict_types=1);
namespace Cybermaps\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Identity Hub for Managing Business Metadata
 */
class IdentityHub {

	/**
	 * Register hooks for the Identity Hub.
	 */
	public function register_hooks() {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'update_option_cybermaps_identity_data', array( $this, 'on_identity_updated' ), 10, 2 );
		add_action( 'add_option_cybermaps_identity_data', array( $this, 'on_identity_added' ), 10, 2 );
	}

	/**
	 * Handle first creation of the structured identity option.
	 *
	 * WordPress's dynamic add-option hook passes the option name first and the
	 * newly inserted value second, unlike the old/new pair passed on updates.
	 *
	 * @param string $option    Option name supplied by WordPress.
	 * @param mixed  $new_value Newly inserted option value.
	 */
	public function on_identity_added( $option, $new_value ): void {
		unset( $option );
		$this->on_identity_updated( array(), $new_value );
	}

	/**
	 * Invalidate dynamic output and republish static identity documents.
	 *
	 * @param mixed $old_value Previous identity option.
	 * @param mixed $new_value Updated identity option.
	 */
	public function on_identity_updated( $old_value, $new_value ): void {
		if ( MigrationHub::is_applying_prepared_import() ) {
			return;
		}

		if ( $old_value === $new_value ) {
			return;
		}

		\Cybermaps\Core\CacheManager::clear_family( 'discovery' );
		if ( 'all' === \Cybermaps\Discovery\StaticBridge::get_mode() ) {
			$bridge = \Cybermaps\Discovery\StaticBridge::get_instance();
			$bridge->invalidate();
			$bridge->request_sync();
		}
	}

	/**
	 * Enqueue scripts for the Media Picker.
	 */
	public function enqueue_scripts( $hook ) {
		if ( 'toplevel_page_cybermaps-settings' !== $hook ) {
			return;
		}

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only admin workspace selection.
		$active_tab = isset( $_GET['tab'] ) && is_scalar( $_GET['tab'] )
			? sanitize_key( wp_unslash( (string) $_GET['tab'] ) )
			: 'dashboard';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( 'schema' !== $active_tab ) {
			return;
		}

		wp_enqueue_media();
	}

	/**
	 * Register the identity data setting.
	 */
	public function register_settings() {
		register_setting( 'cybermaps_options_group', 'cybermaps_identity_data', array( $this, 'sanitize_identity_data' ) );
	}

	/**
	 * Render the Identity Hub UI.
	 */
	public function render_identity_builder() {
		$data         = $this->normalize_identity_data(
			get_option( 'cybermaps_identity_data', array() )
		);
		$type         = $data['type'];
		$precise_type = $data['precise_type'];
		$name         = $data['name'];
		$desc         = $data['description'];
		$image_id     = (int) $data['image_id'];

		$schema_types               = \Cybermaps\Core\SchemaRegistry::get_types();
		$resolved_type              = \Cybermaps\Core\SchemaRegistry::get_entity_type(
			array(
				'type'         => $type,
				'precise_type' => $precise_type,
			)
		);
		$show_local_business_fields = \Cybermaps\Core\SchemaRegistry::is_local_business_type( $resolved_type );
		$local_business_aria        = $show_local_business_fields ? 'false' : 'true';
		$local_business_grid_style  = $show_local_business_fields ? 'grid' : 'none';

		?>
		<div class="cybermaps-identity-builder cybermaps-identity-builder-single-column">
				<div class="cybermaps-benefit" style="margin-bottom: 30px; border-left-color: var(--cm-accent);">
					<span class="dashicons dashicons-rest-api" style="color: var(--cm-accent);"></span>
					<div>
						<strong><?php esc_html_e( 'Structured Data Engine:', 'cybermaps' ); ?></strong>
						<?php esc_html_e( 'The Identity Hub generates', 'cybermaps' ); ?> <strong><?php esc_html_e( 'JSON-LD Schema', 'cybermaps' ); ?></strong> <?php esc_html_e( 'from the information you enter and also publishes that identity in the Cybermaps graph. Search engines decide whether any rich result or knowledge feature is shown.', 'cybermaps' ); ?>
						<br><a href="https://schema.org" target="_blank" rel="noopener noreferrer" style="text-decoration: underline; margin-top: 5px; display: inline-block;"><?php esc_html_e( 'Learn more about Schema.org standards', 'cybermaps' ); ?> &rarr;</a>
					</div>
				</div>

				<div style="margin-bottom: 40px; padding-bottom: 20px; border-bottom: 1px solid #f0f0f1;">
					<h3><span class="dashicons dashicons-id"></span> <?php esc_html_e( 'Basic Identity', 'cybermaps' ); ?></h3>
					<div class="cybermaps-desc"><?php esc_html_e( 'Primary entity data published as Schema.org JSON-LD and in the Cybermaps Knowledge Graph.', 'cybermaps' ); ?></div>

					<div style="max-width: 800px; margin-top: 20px;">
						<div style="margin-bottom: 20px;">
							<label for="cybermaps-identity-type" style="display: block; font-weight: 600; margin-bottom: 5px;"><?php esc_html_e( 'Primary Entity Type:', 'cybermaps' ); ?> <?php echo AccessibleTooltip::get( __( 'Determines the root @type for your JSON-LD Schema. Organization is for brands/groups; LocalBusiness is for physical stores/offices.', 'cybermaps' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- AccessibleTooltip returns escaped trusted markup. ?></label>
							<select id="cybermaps-identity-type" name="cybermaps_identity_data[type]" style="width: 100%;">
								<option value="Organization" <?php selected( $type, 'Organization' ); ?>><?php esc_html_e( 'Organization (General Brand)', 'cybermaps' ); ?></option>
								<option value="LocalBusiness" <?php selected( $type, 'LocalBusiness' ); ?>><?php esc_html_e( 'LocalBusiness (Physical Location)', 'cybermaps' ); ?></option>
								<option value="Person" <?php selected( $type, 'Person' ); ?>><?php esc_html_e( 'Person (Personal Blog/Portfolio)', 'cybermaps' ); ?></option>
							</select>
						</div>

						<div style="margin-bottom: 20px;">
							<label for="cybermaps-identity-precise-type" style="display: block; font-weight: 600; margin-bottom: 5px;"><?php esc_html_e( 'Supported Schema Subtype:', 'cybermaps' ); ?> <?php echo AccessibleTooltip::get( __( 'Narrows the primary entity to one of the common Schema.org subtypes that Cybermaps explicitly supports.', 'cybermaps' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- AccessibleTooltip returns escaped trusted markup. ?></label>
							<select id="cybermaps-identity-precise-type" name="cybermaps_identity_data[precise_type]" style="width: 100%;">
								<option value=""><?php esc_html_e( 'Use the primary entity type', 'cybermaps' ); ?></option>
								<?php foreach ( $schema_types as $t ) : ?>
									<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $precise_type, $t ); ?>><?php echo esc_html( $t ); ?></option>
								<?php endforeach; ?>
							</select>
							<div class="cybermaps-desc"><?php esc_html_e( 'Only compatible supported subtypes are shown. Leave this at the primary type when no narrower choice is accurate.', 'cybermaps' ); ?></div>
						</div>

						<div style="margin-bottom: 20px;">
							<label for="cybermaps-identity-name" style="display: block; font-weight: 600; margin-bottom: 5px;"><?php esc_html_e( 'Official Entity Name (required to publish):', 'cybermaps' ); ?></label>
							<input type="text" id="cybermaps-identity-name" name="cybermaps_identity_data[name]" value="<?php echo esc_attr( $name ); ?>" maxlength="<?php echo esc_attr( (string) \Cybermaps\Core\IdentityEntityBuilder::MAX_TEXT_LENGTH ); ?>" style="width: 100%;">
						</div>

						<div style="margin-bottom: 20px;">
							<label for="cybermaps-identity-description" style="display: block; font-weight: 600; margin-bottom: 5px;"><?php esc_html_e( 'Published Entity Description:', 'cybermaps' ); ?> <?php echo AccessibleTooltip::get( __( 'This operator-authored description is included in configured JSON-LD identity and Knowledge Graph output.', 'cybermaps' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- AccessibleTooltip returns escaped trusted markup. ?></label>
							<textarea id="cybermaps-identity-description" name="cybermaps_identity_data[description]" rows="5" maxlength="<?php echo esc_attr( (string) \Cybermaps\Core\IdentityEntityBuilder::MAX_DESCRIPTION_LENGTH ); ?>" style="width: 100%;" placeholder="<?php esc_attr_e( 'Describe the organization using factual language.', 'cybermaps' ); ?>"><?php echo esc_textarea( $desc ); ?></textarea>
							<div class="cybermaps-desc"><?php esc_html_e( 'Cybermaps publishes this text but cannot guarantee that any crawler reads or uses it.', 'cybermaps' ); ?></div>
						</div>
					</div>
				</div>

				<div style="margin-bottom: 40px; padding-bottom: 20px; border-bottom: 1px solid #f0f0f1;">
					<h3><span class="dashicons dashicons-location"></span> <?php esc_html_e( 'Contact & Location', 'cybermaps' ); ?></h3>
					<div class="cybermaps-desc"><?php esc_html_e( 'Geographic and contact information published in configured Schema.org output. Supports international addresses; search features are not guaranteed.', 'cybermaps' ); ?></div>

					<div style="max-width: 800px; margin-top: 20px;">
						<div style="margin-bottom: 15px;">
							<label for="cybermaps-identity-country" style="display: block; font-weight: 600; margin-bottom: 5px;"><?php esc_html_e( 'Country:', 'cybermaps' ); ?></label>
							<input type="text" id="cybermaps-identity-country" name="cybermaps_identity_data[address_country]" value="<?php echo esc_attr( $data['address_country'] ); ?>" maxlength="2" pattern="[A-Za-z]{2}" placeholder="<?php esc_attr_e( 'US', 'cybermaps' ); ?>" style="width: 100%; text-transform: uppercase;">
							<p class="description"><?php esc_html_e( 'Enter any ISO 3166-1 alpha-2 country code, such as US, GB, KE, or NZ.', 'cybermaps' ); ?></p>
						</div>

						<div style="margin-bottom: 15px;">
							<label for="cybermaps-identity-address" style="display: block; font-weight: 600; margin-bottom: 5px;"><?php esc_html_e( 'Street Address:', 'cybermaps' ); ?></label>
							<input type="text" id="cybermaps-identity-address" name="cybermaps_identity_data[address]" value="<?php echo esc_attr( $data['address'] ); ?>" maxlength="<?php echo esc_attr( (string) \Cybermaps\Core\IdentityEntityBuilder::MAX_TEXT_LENGTH ); ?>" style="width: 100%;">
						</div>

						<div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px; margin-bottom: 15px;">
							<div>
								<label for="cybermaps-identity-city" style="display: block; font-weight: 600; margin-bottom: 5px;"><?php esc_html_e( 'City:', 'cybermaps' ); ?></label>
								<input type="text" id="cybermaps-identity-city" name="cybermaps_identity_data[city]" value="<?php echo esc_attr( $data['city'] ); ?>" maxlength="<?php echo esc_attr( (string) \Cybermaps\Core\IdentityEntityBuilder::MAX_TEXT_LENGTH ); ?>" style="width: 100%;">
							</div>
							<div>
								<label for="cybermaps-identity-region" style="display: block; font-weight: 600; margin-bottom: 5px;"><?php esc_html_e( 'State / Province / Region:', 'cybermaps' ); ?></label>
								<input type="text" id="cybermaps-identity-region" name="cybermaps_identity_data[address_region]" value="<?php echo esc_attr( $data['address_region'] ); ?>" maxlength="<?php echo esc_attr( (string) \Cybermaps\Core\IdentityEntityBuilder::MAX_TEXT_LENGTH ); ?>" style="width: 100%;" placeholder="<?php esc_attr_e( 'e.g. CA, NSW, Bavaria', 'cybermaps' ); ?>">
							</div>
							<div>
								<label for="cybermaps-identity-postal-code" style="display: block; font-weight: 600; margin-bottom: 5px;"><?php esc_html_e( 'Postal Code:', 'cybermaps' ); ?></label>
								<input type="text" id="cybermaps-identity-postal-code" name="cybermaps_identity_data[postal_code]" value="<?php echo esc_attr( $data['postal_code'] ); ?>" maxlength="<?php echo esc_attr( (string) \Cybermaps\Core\IdentityEntityBuilder::MAX_TEXT_LENGTH ); ?>" style="width: 100%;">
							</div>
						</div>

						<div class="cybermaps-local-business-only" aria-hidden="<?php echo esc_attr( $local_business_aria ); ?>" style="display: <?php echo esc_attr( $local_business_grid_style ); ?>; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px; background: #fffbeb; padding: 15px; border-radius: 6px; border: 1px solid #fef3c7;">
							<div>
								<label for="cybermaps-identity-latitude" style="display: block; font-weight: 600; margin-bottom: 5px;"><?php esc_html_e( 'Latitude:', 'cybermaps' ); ?></label>
								<input type="text" id="cybermaps-identity-latitude" name="cybermaps_identity_data[latitude]" value="<?php echo esc_attr( $data['latitude'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. 40.7128', 'cybermaps' ); ?>" style="width: 100%;">
							</div>
							<div>
								<label for="cybermaps-identity-longitude" style="display: block; font-weight: 600; margin-bottom: 5px;"><?php esc_html_e( 'Longitude:', 'cybermaps' ); ?></label>
								<input type="text" id="cybermaps-identity-longitude" name="cybermaps_identity_data[longitude]" value="<?php echo esc_attr( $data['longitude'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. -74.0060', 'cybermaps' ); ?>" style="width: 100%;">
							</div>
						</div>

						<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
							<div>
								<label for="cybermaps-identity-phone" style="display: block; font-weight: 600; margin-bottom: 5px;"><?php esc_html_e( 'Phone Number:', 'cybermaps' ); ?></label>
								<input type="tel" id="cybermaps-identity-phone" name="cybermaps_identity_data[phone]" value="<?php echo esc_attr( $data['phone'] ); ?>" maxlength="64" style="width: 100%;">
							</div>
							<div>
								<label for="cybermaps-identity-email" style="display: block; font-weight: 600; margin-bottom: 5px;"><?php esc_html_e( 'Official Email:', 'cybermaps' ); ?></label>
								<input type="email" id="cybermaps-identity-email" name="cybermaps_identity_data[email]" value="<?php echo esc_attr( $data['email'] ); ?>" maxlength="254" style="width: 100%;">
							</div>
						</div>
						<?php $this->render_identity_profiles( $data, $schema_types ); ?>
						<?php
	}

	/**
	 * Render social and department-contact repeaters, then the remaining builder.
	 *
	 * @param array<string, mixed> $data Normalized identity data.
	 * @param array<int, string>   $schema_types Supported schema types.
	 */
	private function render_identity_profiles( array $data, array $schema_types ): void {
		?>

						<div style="margin-top: 25px; border-top: 1px solid #eee; padding-top: 20px;">
							<h4 style="margin-top: 0;"><?php esc_html_e( 'Social Profiles (sameAs)', 'cybermaps' ); ?></h4>
							<p class="cybermaps-desc">
							<?php
							printf(
								/* translators: %d: maximum social-profile URLs. */
								esc_html__( 'Add up to %d public profile URLs.', 'cybermaps' ),
								(int) \Cybermaps\Core\IdentityEntityBuilder::MAX_SOCIAL_PROFILES
							);
							?>
							</p>
							<div id="cybermaps-social-container">
								<?php
								$socials = $data['social_profiles'];
								foreach ( $socials as $index => $url ) :
									?>
									<div class="social-row" style="display: flex; gap: 10px; margin-bottom: 10px;">
										<input type="url" id="cybermaps-social-<?php echo esc_attr( (string) $index ); ?>" name="cybermaps_identity_data[social_profiles][<?php echo esc_attr( (string) $index ); ?>]" value="<?php echo esc_url( $url ); ?>" maxlength="<?php echo esc_attr( (string) \Cybermaps\Core\IdentityEntityBuilder::MAX_URL_LENGTH ); ?>" aria-label="<?php esc_attr_e( 'Social profile URL', 'cybermaps' ); ?>" style="flex: 1;" placeholder="<?php esc_attr_e( 'https://facebook.com/...', 'cybermaps' ); ?>">
										<button type="button" class="remove-social button-link-delete" aria-label="<?php esc_attr_e( 'Remove social profile', 'cybermaps' ); ?>" style="color: #d63638; cursor: pointer; font-weight: bold; font-size: 20px; border: 0; background: transparent;">&times;</button>
									</div>
								<?php endforeach; ?>
							</div>
							<button type="button" id="cybermaps-add-social" class="button"><?php esc_html_e( '+ Add Social Profile', 'cybermaps' ); ?></button>
						</div>

						<div style="margin-top: 25px; border-top: 1px solid #eee; padding-top: 20px;">
							<h4 style="margin-top: 0;"><?php esc_html_e( 'Department Contact Points', 'cybermaps' ); ?></h4>
							<p class="cybermaps-desc">
							<?php
							printf(
								/* translators: %d: maximum contact points. */
								esc_html__( 'Add up to %d department contacts.', 'cybermaps' ),
								(int) \Cybermaps\Core\IdentityEntityBuilder::MAX_CONTACT_POINTS
							);
							?>
							</p>
							<div id="cybermaps-contact-points-container">
								<?php
								$points = $data['contact_points'];
								foreach ( $points as $index => $point ) :
									$contact_id = 'cybermaps-contact-' . absint( $index );
									?>
									<div class="contact-point-row" style="background: #f8fafc; border: 1px solid #e2e8f0; padding: 15px; border-radius: 6px; margin-bottom: 15px; position: relative;">
										<button type="button" class="remove-contact-point button-link-delete" aria-label="<?php esc_attr_e( 'Remove contact point', 'cybermaps' ); ?>" style="position: absolute; top: 10px; right: 10px; color: #d63638; cursor: pointer; font-weight: bold; font-size: 18px; border: 0; background: transparent;">&times;</button>
										<div style="margin-bottom: 10px;">
											<label class="screen-reader-text" for="<?php echo esc_attr( $contact_id . '-type' ); ?>"><?php esc_html_e( 'Contact type', 'cybermaps' ); ?></label>
											<select id="<?php echo esc_attr( $contact_id . '-type' ); ?>" name="cybermaps_identity_data[contact_points][<?php echo esc_attr( $index ); ?>][type]" style="width: 100%;">
												<option value="Customer Support" <?php selected( $point['type'], 'Customer Support' ); ?>><?php esc_html_e( 'Customer Support', 'cybermaps' ); ?></option>
												<option value="Technical Support" <?php selected( $point['type'], 'Technical Support' ); ?>><?php esc_html_e( 'Technical Support', 'cybermaps' ); ?></option>
												<option value="Sales" <?php selected( $point['type'], 'Sales' ); ?>><?php esc_html_e( 'Sales', 'cybermaps' ); ?></option>
											</select>
										</div>
										<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
											<label class="screen-reader-text" for="<?php echo esc_attr( $contact_id . '-phone' ); ?>"><?php esc_html_e( 'Contact phone', 'cybermaps' ); ?></label>
											<input type="tel" id="<?php echo esc_attr( $contact_id . '-phone' ); ?>" name="cybermaps_identity_data[contact_points][<?php echo esc_attr( $index ); ?>][phone]" value="<?php echo esc_attr( $point['phone'] ); ?>" maxlength="64" placeholder="<?php esc_attr_e( 'Phone', 'cybermaps' ); ?>" style="width: 100%;">
											<label class="screen-reader-text" for="<?php echo esc_attr( $contact_id . '-email' ); ?>"><?php esc_html_e( 'Contact email', 'cybermaps' ); ?></label>
											<input type="email" id="<?php echo esc_attr( $contact_id . '-email' ); ?>" name="cybermaps_identity_data[contact_points][<?php echo esc_attr( $index ); ?>][email]" value="<?php echo esc_attr( $point['email'] ); ?>" maxlength="254" placeholder="<?php esc_attr_e( 'Email', 'cybermaps' ); ?>" style="width: 100%;">
										</div>
									</div>
								<?php endforeach; ?>
							</div>
							<button type="button" id="cybermaps-add-contact-point" class="button"><?php esc_html_e( '+ Add Contact Point', 'cybermaps' ); ?></button>
						</div>
					</div>
				</div>
				<?php $this->render_identity_media_and_hours( $data, $schema_types ); ?>
				<?php
	}

	/**
	 * Render identity media and business hours, then the catalog builder.
	 *
	 * @param array<string, mixed> $data Normalized identity data.
	 * @param array<int, string>   $schema_types Supported schema types.
	 */
	private function render_identity_media_and_hours( array $data, array $schema_types ): void {
		$image_id                 = (int) $data['image_id'];
		$remove_image_style       = $image_id ? '' : 'display:none;';
		$resolved_type            = \Cybermaps\Core\SchemaRegistry::get_entity_type( $data );
		$show_local_business      = \Cybermaps\Core\SchemaRegistry::is_local_business_type( $resolved_type );
		$local_business_aria      = $show_local_business ? 'false' : 'true';
		$local_business_box_style = $show_local_business ? '' : 'display: none; ';
		?>

				<div style="margin-bottom: 40px; padding-bottom: 20px; border-bottom: 1px solid #f0f0f1;">
					<h3><span class="dashicons dashicons-format-image"></span> <?php esc_html_e( 'Identity Image & Logo', 'cybermaps' ); ?></h3>
					<div class="cybermaps-benefit">
						<span class="dashicons dashicons-share-alt"></span>
						<div><strong><?php esc_html_e( 'Identity publication:', 'cybermaps' ); ?></strong> <?php esc_html_e( 'The configured name, image, and social profiles are encoded as JSON-LD and included in Cybermaps discovery output. Organization and business identities also publish the image as their logo; Person identities publish it only as an image.', 'cybermaps' ); ?></div>
					</div>
					<div style="max-width: 800px;">
						<div style="margin-bottom: 15px;">
							<div style="font-weight: 600; margin-bottom: 5px;"><?php esc_html_e( 'Featured Brand Image:', 'cybermaps' ); ?></div>
							<div style="display: flex; align-items: center; gap: 20px; background: #f8fafc; padding: 20px; border: 1px dashed #cbd5e0; border-radius: 6px;">
								<div id="cybermaps-brand-image-preview" style="width: 80px; height: 80px; background: #e2e8f0; border-radius: 6px; display: flex; align-items: center; justify-content: center; overflow: hidden;">
									<?php if ( $image_id ) : ?>
										<?php echo wp_get_attachment_image( $image_id, 'thumbnail', false, array( 'style' => 'width: 100%; height: auto;' ) ); ?>
									<?php else : ?>
										<span style="font-size: 11px; color: #64748b;"><?php esc_html_e( 'No Image', 'cybermaps' ); ?></span>
									<?php endif; ?>
								</div>
								<div>
									<input type="hidden" id="cybermaps-brand-image-id" name="cybermaps_identity_data[image_id]" value="<?php echo esc_attr( $image_id ); ?>">
									<button type="button" id="cybermaps-select-brand-image" class="button button-secondary"><?php esc_html_e( 'Select Image', 'cybermaps' ); ?></button>
									<button type="button" id="cybermaps-remove-brand-image" class="button button-link-delete" style="<?php echo esc_attr( $remove_image_style ); ?>"><?php esc_html_e( 'Remove', 'cybermaps' ); ?></button>
								</div>
							</div>
						</div>
					</div>
				</div>

				<div class="cybermaps-local-business-only" aria-hidden="<?php echo esc_attr( $local_business_aria ); ?>" style="<?php echo esc_attr( $local_business_box_style ); ?>margin-bottom: 40px; padding-bottom: 20px; border-bottom: 1px solid #f0f0f1;">
					<h3><span class="dashicons dashicons-clock"></span> <?php esc_html_e( 'Business Hours', 'cybermaps' ); ?></h3>
					<div class="cybermaps-desc">
					<?php
					printf(
						/* translators: %d: maximum opening-hour ranges per day. */
						esc_html__( 'Published only for LocalBusiness and its supported business subtypes. Add up to %d time ranges per day.', 'cybermaps' ),
						(int) \Cybermaps\Core\IdentityEntityBuilder::MAX_HOURS_SLOTS_PER_DAY
					);
					?>
					</div>
					<div style="max-width: 800px;">
						<div class="cybermaps-hours-repeater" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 15px;">
							<?php
							$days        = array(
								'monday'    => __( 'Monday', 'cybermaps' ),
								'tuesday'   => __( 'Tuesday', 'cybermaps' ),
								'wednesday' => __( 'Wednesday', 'cybermaps' ),
								'thursday'  => __( 'Thursday', 'cybermaps' ),
								'friday'    => __( 'Friday', 'cybermaps' ),
								'saturday'  => __( 'Saturday', 'cybermaps' ),
								'sunday'    => __( 'Sunday', 'cybermaps' ),
							);
							$saved_hours = $data['hours'];
							foreach ( $days as $day_key => $day ) :
								$slots = $saved_hours[ $day_key ] ?? array();
								?>
								<div class="day-box" style="background: #f9f9f9; padding: 15px; border: 1px solid #e5e5e5; border-radius: 6px;">
									<strong style="font-size: 13px; display: block; margin-bottom: 10px;"><?php echo esc_html( $day ); ?></strong>
									<div class="slots-container-<?php echo esc_attr( $day_key ); ?>">
										<?php if ( ! empty( $slots ) ) : ?>
											<?php
											foreach ( $slots as $index => $slot ) :
												$slot_id = 'cybermaps-hours-' . $day_key . '-' . absint( $index );
												/* translators: %s: weekday name. */
												$opening_label = sprintf( __( '%s opening time', 'cybermaps' ), $day );
												/* translators: %s: weekday name. */
												$closing_label = sprintf( __( '%s closing time', 'cybermaps' ), $day );
												?>
												<div class="time-slot" style="display: flex; align-items: center; gap: 5px; margin-bottom: 8px;">
													<label class="screen-reader-text" for="<?php echo esc_attr( $slot_id . '-open' ); ?>"><?php echo esc_html( $opening_label ); ?></label>
													<input type="time" id="<?php echo esc_attr( $slot_id . '-open' ); ?>" name="cybermaps_identity_data[hours][<?php echo esc_attr( $day_key ); ?>][<?php echo esc_attr( $index ); ?>][open]" value="<?php echo esc_attr( $slot['open'] ); ?>" style="padding: 4px; font-size: 12px; width: 85px;">
													<span style="font-size: 11px;"><?php esc_html_e( 'to', 'cybermaps' ); ?></span>
													<label class="screen-reader-text" for="<?php echo esc_attr( $slot_id . '-close' ); ?>"><?php echo esc_html( $closing_label ); ?></label>
													<input type="time" id="<?php echo esc_attr( $slot_id . '-close' ); ?>" name="cybermaps_identity_data[hours][<?php echo esc_attr( $day_key ); ?>][<?php echo esc_attr( $index ); ?>][close]" value="<?php echo esc_attr( $slot['close'] ); ?>" style="padding: 4px; font-size: 12px; width: 85px;">
													<button type="button" class="remove-slot button-link-delete" aria-label="<?php esc_attr_e( 'Remove business-hours slot', 'cybermaps' ); ?>" style="color: #d63638; cursor: pointer; font-weight: bold; margin-left: 5px; font-size: 18px; border: 0; background: transparent;">&times;</button>
												</div>
											<?php endforeach; ?>
										<?php endif; ?>
									</div>
									<button type="button" class="button button-small add-slot" data-day="<?php echo esc_attr( $day_key ); ?>" data-day-label="<?php echo esc_attr( $day ); ?>" style="font-size: 11px;"><?php esc_html_e( '+ Add Slot', 'cybermaps' ); ?></button>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				</div>
				<?php $this->render_identity_catalogs_and_scripts( $data, $schema_types ); ?>
				<?php
	}

	/**
	 * Render catalog controls and the existing identity-builder script.
	 *
	 * @param array<string, mixed> $data Normalized identity data.
	 * @param array<int, string>   $schema_types Supported schema types.
	 */
	private function render_identity_catalogs_and_scripts( array $data, array $schema_types ): void {
		?>

				<div style="margin-bottom: 40px;">
					<h3><span class="dashicons dashicons-archive"></span> <?php esc_html_e( 'Product & Offer Catalogs', 'cybermaps' ); ?></h3>
					<span class="cybermaps-desc"><strong><?php esc_html_e( 'Manual:', 'cybermaps' ); ?></strong> <?php esc_html_e( 'List items explicitly.', 'cybermaps' ); ?> <strong><?php esc_html_e( 'Automated:', 'cybermaps' ); ?></strong> <?php esc_html_e( 'Automatically generates a catalog based on child pages of the selected parent page.', 'cybermaps' ); ?></span>
					<p class="cybermaps-desc">
					<?php
					printf(
						/* translators: 1: maximum catalogs, 2: maximum items per catalog, 3: maximum items overall. */
						esc_html__( 'Up to %1$d catalogs, %2$d items per catalog, and %3$d manual items overall.', 'cybermaps' ),
						(int) \Cybermaps\Core\IdentityEntityBuilder::MAX_CATALOGS,
						(int) \Cybermaps\Core\IdentityEntityBuilder::MAX_OFFERS_PER_CATALOG,
						(int) \Cybermaps\Core\IdentityEntityBuilder::MAX_OFFERS_TOTAL
					);
					?>
					</p>
					<div style="max-width: 900px;">
						<div id="cybermaps-catalogs-container">
							<?php
							$catalogs = $data['catalogs'];
							foreach ( $catalogs as $c_index => $catalog ) :
								$catalog_id = 'cybermaps-catalog-' . absint( $c_index );
								$mode       = $catalog['mode'];
								$item_type  = $catalog['item_type'];
								?>
								<div class="catalog-box" style="background: #f8fafc; border: 1px solid #e2e8f0; padding: 25px; border-radius: 8px; margin-bottom: 25px; position: relative;">
									<button type="button" class="remove-catalog button-link-delete" aria-label="<?php esc_attr_e( 'Remove catalog', 'cybermaps' ); ?>" style="position: absolute; top: 15px; right: 15px; color: #d63638; cursor: pointer; font-weight: bold; font-size: 22px; border: 0; background: transparent;">&times;</button>

									<div style="margin-bottom: 20px;">
										<label class="catalog-mode-label" for="<?php echo esc_attr( $catalog_id . '-mode' ); ?>" style="font-weight: 600; display: block; margin-bottom: 5px;"><?php esc_html_e( 'Catalog Mode:', 'cybermaps' ); ?></label>
										<select id="<?php echo esc_attr( $catalog_id . '-mode' ); ?>" name="cybermaps_identity_data[catalogs][<?php echo esc_attr( $c_index ); ?>][mode]" class="catalog-mode-select" style="width: 100%; max-width: 300px;">
											<option value="manual" <?php selected( $mode, 'manual' ); ?>><?php esc_html_e( 'Manual Entry', 'cybermaps' ); ?></option>
											<option value="auto" <?php selected( $mode, 'auto' ); ?>><?php esc_html_e( 'Automated (Page Tree)', 'cybermaps' ); ?></option>
										</select>
									</div>

									<div style="margin-bottom: 20px;">
										<label class="catalog-item-type-label" for="<?php echo esc_attr( $catalog_id . '-item-type' ); ?>" style="font-weight: 600; display: block; margin-bottom: 5px;"><?php esc_html_e( 'Items Represent:', 'cybermaps' ); ?></label>
										<select id="<?php echo esc_attr( $catalog_id . '-item-type' ); ?>" name="cybermaps_identity_data[catalogs][<?php echo esc_attr( $c_index ); ?>][item_type]" class="catalog-item-type-select" style="width: 100%; max-width: 300px;">
											<option value="Service" <?php selected( $item_type, 'Service' ); ?>><?php esc_html_e( 'Services', 'cybermaps' ); ?></option>
											<option value="Product" <?php selected( $item_type, 'Product' ); ?>><?php esc_html_e( 'Products', 'cybermaps' ); ?></option>
										</select>
										<span class="cybermaps-desc"><?php esc_html_e( 'Choose the Schema.org type that truthfully describes every item in this catalog.', 'cybermaps' ); ?></span>
									</div>

								<div class="manual-fields" style="<?php echo 'manual' === $mode ? '' : 'display:none;'; ?>">
										<div style="margin-bottom: 20px;">
											<label class="catalog-name-label" for="<?php echo esc_attr( $catalog_id . '-name' ); ?>" style="font-weight: 600; display: block; margin-bottom: 5px;"><?php esc_html_e( 'Catalog Name:', 'cybermaps' ); ?></label>
										<input type="text" id="<?php echo esc_attr( $catalog_id . '-name' ); ?>" class="catalog-name-input" name="cybermaps_identity_data[catalogs][<?php echo esc_attr( $c_index ); ?>][name]" value="<?php echo esc_attr( $catalog['name'] ); ?>" maxlength="<?php echo esc_attr( (string) \Cybermaps\Core\IdentityEntityBuilder::MAX_TEXT_LENGTH ); ?>" style="width: 100%;" placeholder="<?php esc_attr_e( 'e.g. Services or Products', 'cybermaps' ); ?>">
											<span class="cybermaps-desc"><?php esc_html_e( 'The primary identifier for this catalog grouping.', 'cybermaps' ); ?> <span class="cybermaps-example"><?php esc_html_e( 'Used for Knowledge Graph tagging.', 'cybermaps' ); ?></span></span>
										</div>
										<div class="manual-items">
											<div style="font-weight: 600;"><?php esc_html_e( 'Items Offered:', 'cybermaps' ); ?></div>
											<div class="items-list">
												<?php
												$items = $catalog['items'];
												foreach ( $items as $i_index => $item ) :
													?>
													<div class="item-row" style="display: flex; gap: 10px; margin-top: 5px;">
														<input type="text" id="<?php echo esc_attr( $catalog_id . '-item-' . absint( $i_index ) ); ?>" class="catalog-item-input" name="cybermaps_identity_data[catalogs][<?php echo esc_attr( $c_index ); ?>][items][<?php echo esc_attr( $i_index ); ?>]" value="<?php echo esc_attr( $item ); ?>" maxlength="<?php echo esc_attr( (string) \Cybermaps\Core\IdentityEntityBuilder::MAX_TEXT_LENGTH ); ?>" aria-label="<?php esc_attr_e( 'Catalog item', 'cybermaps' ); ?>" style="flex: 1;">
														<button type="button" class="remove-item button-link-delete" aria-label="<?php esc_attr_e( 'Remove catalog item', 'cybermaps' ); ?>" style="color: #d63638; cursor: pointer; font-weight: bold; border: 0; background: transparent;">&times;</button>
													</div>
												<?php endforeach; ?>
											</div>
											<button type="button" class="button button-small add-item" style="margin-top: 10px;"><?php esc_html_e( '+ Add Item', 'cybermaps' ); ?></button>
										</div>
									</div>

								<div class="auto-fields" style="<?php echo 'auto' === $mode ? '' : 'display:none;'; ?>">
										<label class="catalog-parent-label" for="<?php echo esc_attr( $catalog_id . '-parent' ); ?>" style="font-weight: 600;"><?php esc_html_e( 'Parent Page (The Catalog):', 'cybermaps' ); ?></label>
										<?php
										wp_dropdown_pages(
											array(
												'name'     => 'cybermaps_identity_data[catalogs][' . esc_attr( $c_index ) . '][parent_id]',
												'id'       => esc_attr( $catalog_id . '-parent' ),
												'selected' => absint( $catalog['parent_id'] ),
												'show_option_none' => esc_html__( 'Select a parent page...', 'cybermaps' ),
												'option_none_value' => 0,
												'class'    => 'catalog-parent-select',
											)
										);
										?>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
						<button type="button" id="cybermaps-add-catalog" class="button button-secondary"><?php esc_html_e( '+ Create New Catalog', 'cybermaps' ); ?></button>
					</div>
				</div>

				<div id="cybermaps-page-dropdown-template" style="display:none;">
					<label class="screen-reader-text" for="cybermaps-catalog-__INDEX__-parent"><?php esc_html_e( 'Parent Page (The Catalog)', 'cybermaps' ); ?></label>
					<?php
					wp_dropdown_pages(
						array(
							'name'              => 'cybermaps_identity_data[catalogs][__INDEX__][parent_id]',
							'id'                => 'cybermaps-catalog-__INDEX__-parent',
							'show_option_none'  => esc_html__( 'Select a parent page...', 'cybermaps' ),
							'option_none_value' => 0,
							'class'             => 'catalog-parent-select',
						)
					);
					?>
				</div>
		</div>

		<?php
		$compatible_schema_types = array();
		foreach ( array( 'Organization', 'LocalBusiness', 'Person' ) as $primary_type ) {
			$compatible_schema_types[ $primary_type ] = array_values(
				array_filter(
					$schema_types,
					static function ( string $candidate ) use ( $primary_type ): bool {
						return \Cybermaps\Core\SchemaRegistry::get_entity_type(
							array(
								'type'         => $primary_type,
								'precise_type' => $candidate,
							)
						) === $candidate;
					}
				)
			);
		}
		$local_business_schema_types = array_values(
			array_filter(
				$schema_types,
				static fn( string $candidate ): bool => \Cybermaps\Core\SchemaRegistry::is_local_business_type( $candidate )
			)
		);

		$ih_i18n = wp_json_encode(
			array(
				'to'                 => __( 'to', 'cybermaps' ),
				'urlPh'              => __( 'https://...', 'cybermaps' ),
				'phonePh'            => __( 'Phone', 'cybermaps' ),
				'emailPh'            => __( 'Email', 'cybermaps' ),
				'socialProfile'      => __( 'Social profile URL', 'cybermaps' ),
				'contactType'        => __( 'Contact type', 'cybermaps' ),
				'contactPhone'       => __( 'Contact phone', 'cybermaps' ),
				'contactEmail'       => __( 'Contact email', 'cybermaps' ),
				'openTime'           => __( 'opening time', 'cybermaps' ),
				'closeTime'          => __( 'closing time', 'cybermaps' ),
				/* translators: %s: weekday name. */
				'openingTime'        => __( '%s opening time', 'cybermaps' ),
				/* translators: %s: weekday name. */
				'closingTime'        => __( '%s closing time', 'cybermaps' ),
				'catalogItem'        => __( 'Catalog item', 'cybermaps' ),
				'custSupport'        => __( 'Customer Support', 'cybermaps' ),
				'techSupport'        => __( 'Technical Support', 'cybermaps' ),
				'sales'              => __( 'Sales', 'cybermaps' ),
				'catalogMode'        => __( 'Catalog Mode:', 'cybermaps' ),
				'manualEntry'        => __( 'Manual Entry', 'cybermaps' ),
				'automated'          => __( 'Automated (Page Tree)', 'cybermaps' ),
				'itemType'           => __( 'Items Represent:', 'cybermaps' ),
				'services'           => __( 'Services', 'cybermaps' ),
				'products'           => __( 'Products', 'cybermaps' ),
				'itemTypeHelp'       => __( 'Choose the Schema.org type that truthfully describes every item in this catalog.', 'cybermaps' ),
				'catalogName'        => __( 'Catalog Name:', 'cybermaps' ),
				'egServices'         => __( 'e.g. Services or Products', 'cybermaps' ),
				'primaryId'          => __( 'The primary identifier for this catalog grouping.', 'cybermaps' ),
				'kgTagging'          => __( 'Used for Knowledge Graph tagging.', 'cybermaps' ),
				'itemsOffered'       => __( 'Items Offered:', 'cybermaps' ),
				'addItem'            => __( '+ Add Item', 'cybermaps' ),
				'parentPage'         => __( 'Parent Page (The Catalog):', 'cybermaps' ),
				'removeSlot'         => __( 'Remove business-hours slot', 'cybermaps' ),
				'removeSocial'       => __( 'Remove social profile', 'cybermaps' ),
				'removeContact'      => __( 'Remove contact point', 'cybermaps' ),
				'removeCatalog'      => __( 'Remove catalog', 'cybermaps' ),
				'removeItem'         => __( 'Remove catalog item', 'cybermaps' ),
				'selectImageTitle'   => __( 'Select Brand Image', 'cybermaps' ),
				'useImage'           => __( 'Use Image', 'cybermaps' ),
				'noImage'            => __( 'No Image', 'cybermaps' ),
				'compatibleTypes'    => $compatible_schema_types,
				'localBusinessTypes' => $local_business_schema_types,
				'limits'             => array(
					'hoursPerDay'      => \Cybermaps\Core\IdentityEntityBuilder::MAX_HOURS_SLOTS_PER_DAY,
					'socialProfiles'   => \Cybermaps\Core\IdentityEntityBuilder::MAX_SOCIAL_PROFILES,
					'contactPoints'    => \Cybermaps\Core\IdentityEntityBuilder::MAX_CONTACT_POINTS,
					'catalogs'         => \Cybermaps\Core\IdentityEntityBuilder::MAX_CATALOGS,
					'offersPerCatalog' => \Cybermaps\Core\IdentityEntityBuilder::MAX_OFFERS_PER_CATALOG,
					'offersTotal'      => \Cybermaps\Core\IdentityEntityBuilder::MAX_OFFERS_TOTAL,
					'textLength'       => \Cybermaps\Core\IdentityEntityBuilder::MAX_TEXT_LENGTH,
					'urlLength'        => \Cybermaps\Core\IdentityEntityBuilder::MAX_URL_LENGTH,
				),
			)
		);
		wp_add_inline_script( 'cybermaps-command-center', 'var cybermapsIH = ' . $ih_i18n . ';', 'before' );
		wp_add_inline_script(
			'cybermaps-command-center',
			'jQuery(document).ready(function($) {
    var frame;

    function makeElement(tagName, attributes, text) {
        var element = $(document.createElement(tagName));
        if (attributes) {
            element.attr(attributes);
        }
        if (typeof text !== "undefined") {
            element.text(text);
        }
        return element;
    }

    function safeMediaUrl(value) {
        if (typeof value !== "string" || value === "") {
            return "";
        }

        try {
            var parsed = new window.URL(value, window.location.href);
            return parsed.protocol === "http:" || parsed.protocol === "https:" ? parsed.href : "";
        } catch (error) {
            return "";
        }
    }

    function reindexSocialProfiles() {
        $("#cybermaps-social-container .social-row").each(function(index) {
            $(this).find("input[type=\"url\"]").first().attr({
                id: "cybermaps-social-" + index,
                name: "cybermaps_identity_data[social_profiles][" + index + "]",
                "aria-label": cybermapsIH.socialProfile
            });
        });
    }

    function reindexSlots(container, day) {
        var dayLabel = container.closest(".day-box").find(".add-slot").data("day-label") || day;
        container.children(".time-slot").each(function(index) {
            var prefix = "cybermaps-hours-" + day + "-" + index;
            var inputs = $(this).find("input[type=\"time\"]");
            var labels = $(this).find("label.screen-reader-text");
            inputs.eq(0).attr({
                name: "cybermaps_identity_data[hours][" + day + "][" + index + "][open]",
                id: prefix + "-open",
                "aria-label": cybermapsIH.openingTime.replace("%s", dayLabel)
            });
            inputs.eq(1).attr({
                name: "cybermaps_identity_data[hours][" + day + "][" + index + "][close]",
                id: prefix + "-close",
                "aria-label": cybermapsIH.closingTime.replace("%s", dayLabel)
            });
            labels.eq(0).attr("for", prefix + "-open");
            labels.eq(1).attr("for", prefix + "-close");
        });
    }

    function reindexContactPoints() {
        $("#cybermaps-contact-points-container .contact-point-row").each(function(index) {
            var prefix = "cybermaps-contact-" + index;
            var labels = $(this).find("label.screen-reader-text");
            $(this).find("select").first().attr({
                name: "cybermaps_identity_data[contact_points][" + index + "][type]",
                id: prefix + "-type",
                "aria-label": cybermapsIH.contactType
            });
            $(this).find("input[type=\"tel\"]").first().attr({
                name: "cybermaps_identity_data[contact_points][" + index + "][phone]",
                id: prefix + "-phone",
                "aria-label": cybermapsIH.contactPhone
            });
            $(this).find("input[type=\"email\"]").first().attr({
                name: "cybermaps_identity_data[contact_points][" + index + "][email]",
                id: prefix + "-email",
                "aria-label": cybermapsIH.contactEmail
            });
            labels.eq(0).attr("for", prefix + "-type");
            labels.eq(1).attr("for", prefix + "-phone");
            labels.eq(2).attr("for", prefix + "-email");
        });
    }

    function reindexCatalogs() {
        $("#cybermaps-catalogs-container .catalog-box").each(function(catalogIndex) {
            var box = $(this);
            var prefix = "cybermaps-catalog-" + catalogIndex;
            box.attr("data-catalog-index", catalogIndex);
            box.find(".catalog-mode-select").first().attr({
                name: "cybermaps_identity_data[catalogs][" + catalogIndex + "][mode]",
                id: prefix + "-mode"
            });
            box.find(".catalog-mode-label").first().attr("for", prefix + "-mode");
            box.find(".catalog-item-type-select").first().attr({
                name: "cybermaps_identity_data[catalogs][" + catalogIndex + "][item_type]",
                id: prefix + "-item-type"
            });
            box.find(".catalog-item-type-label").first().attr("for", prefix + "-item-type");
            box.find(".catalog-name-input").first().attr({
                name: "cybermaps_identity_data[catalogs][" + catalogIndex + "][name]",
                id: prefix + "-name"
            });
            box.find(".catalog-name-label").first().attr("for", prefix + "-name");
            box.find(".catalog-parent-select").first().attr({
                name: "cybermaps_identity_data[catalogs][" + catalogIndex + "][parent_id]",
                id: prefix + "-parent"
            });
            box.find(".catalog-parent-label").first().attr("for", prefix + "-parent");
            box.find(".item-row").each(function(itemIndex) {
                $(this).find(".catalog-item-input").first().attr({
                    name: "cybermaps_identity_data[catalogs][" + catalogIndex + "][items][" + itemIndex + "]",
                    id: prefix + "-item-" + itemIndex,
                    "aria-label": cybermapsIH.catalogItem
                });
            });
        });
    }

    function updateLocalBusinessFields() {
        var primary = $("#cybermaps-identity-type").val();
        var precise = $("#cybermaps-identity-precise-type").val();
        var compatible = cybermapsIH.compatibleTypes[primary] || [];
        var resolved = precise && compatible.indexOf(precise) !== -1 ? precise : primary;
        var show = cybermapsIH.localBusinessTypes.indexOf(resolved) !== -1;
        $(".cybermaps-local-business-only")
            .toggle(show)
            .attr("aria-hidden", show ? "false" : "true");
    }

    function refreshPreciseTypeOptions() {
        var primary = $("#cybermaps-identity-type").val();
        var precise = $("#cybermaps-identity-precise-type");
        var compatible = cybermapsIH.compatibleTypes[primary] || [];
        precise.find("option").each(function() {
            var value = $(this).val();
            var allowed = !value || compatible.indexOf(value) !== -1;
            $(this).prop("disabled", !allowed).prop("hidden", !allowed);
        });
        if ( precise.val() && compatible.indexOf(precise.val()) === -1 ) {
            precise.val("");
        }
    }

    function updateRepeaterLimits() {
        $(".add-slot").each(function() {
            var day = $(this).data("day");
            $(this).prop(
                "disabled",
                $(".slots-container-" + day).children(".time-slot").length >= cybermapsIH.limits.hoursPerDay
            );
        });
        $("#cybermaps-add-social").prop(
            "disabled",
            $("#cybermaps-social-container .social-row").length >= cybermapsIH.limits.socialProfiles
        );
        $("#cybermaps-add-contact-point").prop(
            "disabled",
            $("#cybermaps-contact-points-container .contact-point-row").length >= cybermapsIH.limits.contactPoints
        );
        $("#cybermaps-add-catalog").prop(
            "disabled",
            $("#cybermaps-catalogs-container .catalog-box").length >= cybermapsIH.limits.catalogs
        );
        var totalItems = $("#cybermaps-catalogs-container .item-row").length;
        $("#cybermaps-catalogs-container .add-item").each(function() {
            var perCatalog = $(this).siblings(".items-list").children(".item-row").length;
            $(this).prop(
                "disabled",
                perCatalog >= cybermapsIH.limits.offersPerCatalog
                    || totalItems >= cybermapsIH.limits.offersTotal
            );
        });
    }

    reindexSocialProfiles();
    $(".add-slot").each(function() {
        var day = $(this).data("day");
        reindexSlots($(".slots-container-" + day), day);
    });
    reindexContactPoints();
    reindexCatalogs();
    refreshPreciseTypeOptions();
    updateLocalBusinessFields();
    updateRepeaterLimits();

    $("#cybermaps-identity-type").on("change", function() {
        refreshPreciseTypeOptions();
        updateLocalBusinessFields();
    });
    $("#cybermaps-identity-precise-type").on("input change", updateLocalBusinessFields);

    $("#cybermaps-select-brand-image").on("click", function(e) {
        e.preventDefault();
        if (frame) { frame.open(); return; }
        frame = wp.media({
            title: cybermapsIH.selectImageTitle,
            button: { text: cybermapsIH.useImage },
            library: { type: "image" },
            multiple: false
        });
        frame.on("select", function() {
            var attachment = frame.state().get("selection").first().toJSON();
            var attachmentUrl = safeMediaUrl(attachment.url);
            if (!attachmentUrl) {
                return;
            }
            $("#cybermaps-brand-image-id").val(attachment.id);
            $("#cybermaps-brand-image-preview")
                .empty()
                .append(
                    makeElement("img", {
                        src: attachmentUrl,
                        alt: "",
                        style: "width: 100%; height: auto;"
                    })
                );
            $("#cybermaps-remove-brand-image").show();
        });
        frame.open();
    });
    $("#cybermaps-remove-brand-image").on("click", function() {
        $("#cybermaps-brand-image-id").val(0);
        $("#cybermaps-brand-image-preview")
            .empty()
            .append(
                makeElement(
                    "span",
                    { style: "font-size: 10px; color: #64748b;" },
                    cybermapsIH.noImage
                )
            );
        $(this).hide();
    });

    $(".add-slot").on("click", function() {
        var day = $(this).data("day");
        var dayLabel = $(this).data("day-label");
        var container = $(".slots-container-" + day);
        if ( container.children(".time-slot").length >= cybermapsIH.limits.hoursPerDay ) {
            return;
        }
        reindexSlots(container, day);
        var index = container.children().length;
        var slotPrefix = "cybermaps-hours-" + day + "-" + index;
        var slot = makeElement("div", {
            "class": "time-slot",
            style: "display: flex; align-items: center; gap: 5px; margin-bottom: 5px;"
        });
        slot.append(
            makeElement("input", {
                type: "time",
                id: slotPrefix + "-open",
                name: "cybermaps_identity_data[hours][" + day + "][" + index + "][open]",
                value: "09:00",
                "aria-label": cybermapsIH.openingTime.replace("%s", dayLabel)
            }),
            makeElement("span", null, cybermapsIH.to),
            makeElement("input", {
                type: "time",
                id: slotPrefix + "-close",
                name: "cybermaps_identity_data[hours][" + day + "][" + index + "][close]",
                value: "18:00",
                "aria-label": cybermapsIH.closingTime.replace("%s", dayLabel)
            }),
            makeElement(
                "button",
                {
                    type: "button",
                    "class": "remove-slot button-link-delete",
                    "aria-label": cybermapsIH.removeSlot,
                    style: "color: #d63638; cursor: pointer; font-weight: bold; margin-left: 5px; border: 0; background: transparent;"
                },
                "\u00d7"
            )
        );
        container.append(slot);
        reindexSlots(container, day);
        updateRepeaterLimits();
    });
    $(document).on("click", ".remove-slot", function() {
        var slot = $(this).closest(".time-slot");
        var container = slot.parent();
        var addButton = container.closest(".day-box").find(".add-slot");
        var day = addButton.data("day");
        slot.remove();
        reindexSlots(container, day);
        updateRepeaterLimits();
    });

    $("#cybermaps-add-social").on("click", function() {
        if ( $("#cybermaps-social-container .social-row").length >= cybermapsIH.limits.socialProfiles ) {
            return;
        }
        var socialRow = makeElement("div", {
            "class": "social-row",
            style: "display: flex; gap: 10px; margin-bottom: 5px;"
        });
        socialRow.append(
            makeElement("input", {
                type: "url",
                name: "cybermaps_identity_data[social_profiles][]",
                value: "",
                maxlength: cybermapsIH.limits.urlLength,
                "aria-label": cybermapsIH.socialProfile,
                style: "flex: 1;",
                placeholder: cybermapsIH.urlPh
            }),
            makeElement(
                "button",
                {
                    type: "button",
                    "class": "remove-social button-link-delete",
                    "aria-label": cybermapsIH.removeSocial,
                    style: "color: #d63638; cursor: pointer; font-weight: bold; border: 0; background: transparent;"
                },
                "\u00d7"
            )
        );
        $("#cybermaps-social-container").append(socialRow);
        reindexSocialProfiles();
        updateRepeaterLimits();
    });
    $(document).on("click", ".remove-social", function() {
        $(this).closest(".social-row").remove();
        reindexSocialProfiles();
        updateRepeaterLimits();
    });

    $("#cybermaps-add-contact-point").on("click", function() {
        var container = $("#cybermaps-contact-points-container");
        if ( container.children(".contact-point-row").length >= cybermapsIH.limits.contactPoints ) {
            return;
        }
        reindexContactPoints();
        var index = container.children(".contact-point-row").length;
        var contactPrefix = "cybermaps-contact-" + index;
        var contactRow = makeElement("div", {
            "class": "contact-point-row",
            style: "background: #f8fafc; border: 1px solid #e2e8f0; padding: 10px; border-radius: 4px; margin-bottom: 10px; position: relative;"
        });
        var contactType = makeElement("select", {
            id: contactPrefix + "-type",
            name: "cybermaps_identity_data[contact_points][" + index + "][type]",
            "aria-label": cybermapsIH.contactType,
            style: "width: 100%;"
        });
        contactType.append(
            makeElement("option", { value: "Customer Support" }, cybermapsIH.custSupport),
            makeElement("option", { value: "Technical Support" }, cybermapsIH.techSupport),
            makeElement("option", { value: "Sales" }, cybermapsIH.sales)
        );
        contactRow.append(
            makeElement(
                "button",
                {
                    type: "button",
                    "class": "remove-contact-point button-link-delete",
                    "aria-label": cybermapsIH.removeContact,
                    style: "position: absolute; top: 5px; right: 5px; color: #d63638; cursor: pointer; font-weight: bold; border: 0; background: transparent;"
                },
                "\u00d7"
            ),
            makeElement("div", { style: "margin-bottom: 5px;" }).append(contactType),
            makeElement(
                "div",
                { style: "display: grid; grid-template-columns: 1fr 1fr; gap: 10px;" }
            ).append(
                makeElement("input", {
                    type: "tel",
                    id: contactPrefix + "-phone",
                    name: "cybermaps_identity_data[contact_points][" + index + "][phone]",
                    value: "",
                    maxlength: 64,
                    "aria-label": cybermapsIH.contactPhone,
                    placeholder: cybermapsIH.phonePh
                }),
                makeElement("input", {
                    type: "email",
                    id: contactPrefix + "-email",
                    name: "cybermaps_identity_data[contact_points][" + index + "][email]",
                    value: "",
                    maxlength: 254,
                    "aria-label": cybermapsIH.contactEmail,
                    placeholder: cybermapsIH.emailPh
                })
            )
        );
        container.append(contactRow);
        reindexContactPoints();
        updateRepeaterLimits();
    });
    $(document).on("click", ".remove-contact-point", function() {
        $(this).closest(".contact-point-row").remove();
        reindexContactPoints();
        updateRepeaterLimits();
    });

    $("#cybermaps-add-catalog").on("click", function() {
        var container = $("#cybermaps-catalogs-container");
        if ( container.children(".catalog-box").length >= cybermapsIH.limits.catalogs ) {
            return;
        }
        reindexCatalogs();
        var index = container.children(".catalog-box").length;
        var catalogPrefix = "cybermaps-catalog-" + index;
        var dropdownFields = $("#cybermaps-page-dropdown-template").children().clone();
        dropdownFields
            .filter("[id], [name], [for]")
            .add(dropdownFields.find("[id], [name], [for]"))
            .each(function() {
                var field = $(this);
                ["id", "name", "for"].forEach(function(attribute) {
                    var value = field.attr(attribute);
                    if (typeof value === "string") {
                        field.attr(attribute, value.replace(/__INDEX__/g, index));
                    }
                });
            });

        var catalogBox = makeElement("div", {
            "class": "catalog-box",
            style: "background: #f8fafc; border: 1px solid #e2e8f0; padding: 25px; border-radius: 8px; margin-bottom: 25px; position: relative;"
        });
        var modeSelect = makeElement("select", {
            id: catalogPrefix + "-mode",
            name: "cybermaps_identity_data[catalogs][" + index + "][mode]",
            "class": "catalog-mode-select",
            style: "width: 100%; max-width: 300px;"
        }).append(
            makeElement("option", { value: "manual" }, cybermapsIH.manualEntry),
            makeElement("option", { value: "auto" }, cybermapsIH.automated)
        );
        var itemTypeSelect = makeElement("select", {
            id: catalogPrefix + "-item-type",
            name: "cybermaps_identity_data[catalogs][" + index + "][item_type]",
            "class": "catalog-item-type-select",
            style: "width: 100%; max-width: 300px;"
        }).append(
            makeElement("option", { value: "Service" }, cybermapsIH.services),
            makeElement("option", { value: "Product" }, cybermapsIH.products)
        );
        var nameDescription = makeElement("span", { "class": "cybermaps-desc" });
        nameDescription.append(
            document.createTextNode(String(cybermapsIH.primaryId) + " "),
            makeElement("span", { "class": "cybermaps-example" }, cybermapsIH.kgTagging)
        );
        var manualFields = makeElement("div", { "class": "manual-fields" }).append(
            makeElement("div", { style: "margin-bottom: 20px;" }).append(
                makeElement(
                    "label",
                    {
                        "class": "catalog-name-label",
                        "for": catalogPrefix + "-name",
                        style: "font-weight: 600; display: block; margin-bottom: 5px;"
                    },
                    cybermapsIH.catalogName
                ),
                makeElement("input", {
                    type: "text",
                    id: catalogPrefix + "-name",
                    "class": "catalog-name-input",
                    name: "cybermaps_identity_data[catalogs][" + index + "][name]",
                    maxlength: cybermapsIH.limits.textLength,
                    style: "width: 100%;",
                    placeholder: cybermapsIH.egServices
                }),
                nameDescription
            ),
            makeElement("div", { "class": "manual-items" }).append(
                makeElement("div", { style: "font-weight: 600;" }, cybermapsIH.itemsOffered),
                makeElement("div", { "class": "items-list" }),
                makeElement(
                    "button",
                    {
                        type: "button",
                        "class": "button button-small add-item",
                        style: "margin-top: 10px;"
                    },
                    cybermapsIH.addItem
                )
            )
        );
        var autoFields = makeElement("div", {
            "class": "auto-fields",
            style: "display:none;"
        }).append(
            makeElement(
                "label",
                {
                    "class": "catalog-parent-label",
                    "for": catalogPrefix + "-parent",
                    style: "font-weight: 600;"
                },
                cybermapsIH.parentPage
            ),
            dropdownFields
        );

        catalogBox.append(
            makeElement(
                "button",
                {
                    type: "button",
                    "class": "remove-catalog button-link-delete",
                    "aria-label": cybermapsIH.removeCatalog,
                    style: "position: absolute; top: 15px; right: 15px; color: #d63638; cursor: pointer; font-weight: bold; font-size: 22px; border: 0; background: transparent;"
                },
                "\u00d7"
            ),
            makeElement("div", { style: "margin-bottom: 20px;" }).append(
                makeElement(
                    "label",
                    {
                        "class": "catalog-mode-label",
                        "for": catalogPrefix + "-mode",
                        style: "font-weight: 600; display: block; margin-bottom: 5px;"
                    },
                    cybermapsIH.catalogMode
                ),
                modeSelect
            ),
            makeElement("div", { style: "margin-bottom: 20px;" }).append(
                makeElement(
                    "label",
                    {
                        "class": "catalog-item-type-label",
                        "for": catalogPrefix + "-item-type",
                        style: "font-weight: 600; display: block; margin-bottom: 5px;"
                    },
                    cybermapsIH.itemType
                ),
                itemTypeSelect,
                makeElement("span", { "class": "cybermaps-desc" }, cybermapsIH.itemTypeHelp)
            ),
            manualFields,
            autoFields
        );
        container.append(catalogBox);
        reindexCatalogs();
        updateRepeaterLimits();
    });

    $(document).on("change", ".catalog-mode-select", function() {
        var box = $(this).closest(".catalog-box");
        if ($(this).val() === "auto") {
            box.find(".manual-fields").hide();
            box.find(".auto-fields").show();
        } else {
            box.find(".manual-fields").show();
            box.find(".auto-fields").hide();
        }
    });

    $(document).on("click", ".add-item", function() {
        var list = $(this).siblings(".items-list");
        if (
            list.children(".item-row").length >= cybermapsIH.limits.offersPerCatalog
            || $("#cybermaps-catalogs-container .item-row").length >= cybermapsIH.limits.offersTotal
        ) {
            return;
        }
        var catBox = $(this).closest(".catalog-box");
        reindexCatalogs();
        var catIndex = catBox.data("catalog-index");
        var itemIndex = list.children(".item-row").length;
        var itemRow = makeElement("div", {
            "class": "item-row",
            style: "display: flex; gap: 10px; margin-top: 5px;"
        });
        itemRow.append(
            makeElement("input", {
                type: "text",
                id: "cybermaps-catalog-" + catIndex + "-item-" + itemIndex,
                "class": "catalog-item-input",
                name: "cybermaps_identity_data[catalogs][" + catIndex + "][items][" + itemIndex + "]",
                maxlength: cybermapsIH.limits.textLength,
                "aria-label": cybermapsIH.catalogItem,
                style: "flex: 1;"
            }),
            makeElement(
                "button",
                {
                    type: "button",
                    "class": "remove-item button-link-delete",
                    "aria-label": cybermapsIH.removeItem,
                    style: "color: #d63638; cursor: pointer; font-weight: bold; border: 0; background: transparent;"
                },
                "\u00d7"
            )
        );
        list.append(itemRow);
        reindexCatalogs();
        updateRepeaterLimits();
    });

    $(document).on("click", ".remove-item", function() {
        $(this).closest(".item-row").remove();
        reindexCatalogs();
        updateRepeaterLimits();
    });
    $(document).on("click", ".remove-catalog", function() {
        $(this).closest(".catalog-box").remove();
        reindexCatalogs();
        updateRepeaterLimits();
    });
});'
		);
		?>
		<?php
	}

	/**
	 * Sanitize the identity data before saving.
	 *
	 * @param array $input Raw input data.
	 * @return array Sanitized data.
	 */
	public function sanitize_identity_data( $input ) {
		if ( MigrationHub::is_applying_prepared_import() ) {
			return is_array( $input ) ? $input : array();
		}

		if ( \Cybermaps\Admin\Settings\Sanitizers\SettingsSanitizer::is_incomplete_main_submission() ) {
			$current = get_option( 'cybermaps_identity_data', array() );
			return is_array( $current ) ? $current : array();
		}

		if ( is_string( $input ) ) {
			$decoded = json_decode( $input, true );
			if ( ! is_array( $decoded ) ) {
				$current = get_option( 'cybermaps_identity_data', array() );
				return is_array( $current ) ? $current : array();
			}
			$input = $decoded;
		}

		/*
		 * WordPress passes null for registered options omitted from an
		 * options.php submission. The main settings form submits only the
		 * active tab so large AI inventories stay below max_input_vars; an
		 * absent Identity Hub payload must therefore retain the saved entity.
		 * The active builder submits either its ordinary nested-array fallback
		 * or one browser-compacted JSON object.
		 */
		if ( ! is_array( $input ) ) {
			$current = get_option( 'cybermaps_identity_data', array() );
			return is_array( $current ) ? $current : array();
		}

		return $this->normalize_identity_data( $input );
	}

	/**
	 * Normalize saved or submitted identity data to the supported shape.
	 *
	 * This is also used on read so legacy options cannot break the editor
	 * before the administrator has an opportunity to save them again.
	 *
	 * @param mixed $input Raw or previously saved identity data.
	 * @return array<string, mixed>
	 */
	private function normalize_identity_data( $input ): array {
		if ( ! is_array( $input ) ) {
			return array();
		}

		$sanitized                    = array_merge(
			self::normalize_entity_type_data( $input ),
			self::normalize_identity_details( $input ),
			self::normalize_address_data( $input ),
			self::normalize_contact_data( $input )
		);
		$sanitized['hours']           = self::normalize_hours( $input['hours'] ?? array() );
		$sanitized['catalogs']        = self::normalize_catalogs( $input['catalogs'] ?? array() );
		$sanitized['social_profiles'] = self::normalize_social_profiles( $input['social_profiles'] ?? array() );
		$sanitized['contact_points']  = self::normalize_contact_points( $input['contact_points'] ?? array() );

		return $sanitized;
	}

	/** @param array<string, mixed> $input Raw identity data. @return array{type:string,precise_type:string} */
	private static function normalize_entity_type_data( array $input ): array {
		$type = isset( $input['type'] ) ? self::sanitize_text_value( $input['type'] ) : 'Organization';
		if ( ! in_array( $type, array( 'Organization', 'LocalBusiness', 'Person' ), true ) ) {
			$type = 'Organization';
		}
		$precise_type = isset( $input['precise_type'] ) ? self::sanitize_text_value( $input['precise_type'] ) : '';
		if ( '' !== $precise_type && \Cybermaps\Core\SchemaRegistry::get_entity_type(
			array(
				'type'         => $type,
				'precise_type' => $precise_type,
			)
		) !== $precise_type ) {
			$precise_type = '';
		}
		return array(
			'type'         => $type,
			'precise_type' => $precise_type,
		);
	}

	/** @param array<string, mixed> $input Raw identity data. @return array{name:string,description:string,image_id:int} */
	private static function normalize_identity_details( array $input ): array {
		$description = isset( $input['description'] ) && is_scalar( $input['description'] )
			? \Cybermaps\Discovery\PublicationConstraints::bounded_text(
				sanitize_textarea_field( (string) $input['description'] ),
				\Cybermaps\Core\IdentityEntityBuilder::MAX_DESCRIPTION_LENGTH
			)
			: '';
		return array(
			'name'        => isset( $input['name'] ) ? self::sanitize_text_value( $input['name'] ) : '',
			'description' => $description,
			'image_id'    => self::positive_id( $input['image_id'] ?? 0 ),
		);
	}

	/** @param array<string, mixed> $input Raw identity data. @return array<string, string> */
	private static function normalize_address_data( array $input ): array {
		$country = isset( $input['address_country'] ) && is_scalar( $input['address_country'] )
			? strtoupper( sanitize_text_field( (string) $input['address_country'] ) )
			: '';
		return array(
			'address_country' => preg_match( '/^[A-Z]{2}$/', $country ) ? $country : '',
			'address'         => isset( $input['address'] ) ? self::sanitize_text_value( $input['address'] ) : '',
			'city'            => isset( $input['city'] ) ? self::sanitize_text_value( $input['city'] ) : '',
			'address_region'  => isset( $input['address_region'] ) ? self::sanitize_text_value( $input['address_region'] ) : '',
			'postal_code'     => isset( $input['postal_code'] ) ? self::sanitize_text_value( $input['postal_code'] ) : '',
		);
	}

	/** @param array<string, mixed> $input Raw identity data. @return array<string, string> */
	private static function normalize_contact_data( array $input ): array {
		$phone = isset( $input['phone'] )
			? self::sanitize_text_value( $input['phone'], \Cybermaps\Core\IdentityEntityBuilder::MAX_PHONE_LENGTH )
			: '';
		$email = isset( $input['email'] ) && is_scalar( $input['email'] )
			? sanitize_email(
				\Cybermaps\Discovery\PublicationConstraints::bounded_text(
					$input['email'],
					\Cybermaps\Core\IdentityEntityBuilder::MAX_EMAIL_LENGTH
				)
			)
			: '';
		return array(
			'phone'     => $phone,
			'email'     => $email,
			'latitude'  => self::normalize_coordinate( $input['latitude'] ?? '', -90.0, 90.0 ),
			'longitude' => self::normalize_coordinate( $input['longitude'] ?? '', -180.0, 180.0 ),
		);
	}

	/** @return array<string, array<int, array{open:string,close:string}>> */
	private static function normalize_hours( mixed $hours ): array {
		if ( ! is_array( $hours ) ) {
			return array();
		}
		$normalized   = array();
		$allowed_days = array_fill_keys( array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' ), true );
		foreach ( $hours as $day => $slots ) {
			$day = sanitize_key( $day );
			if ( ! isset( $allowed_days[ $day ] ) || ! is_array( $slots ) ) {
				continue;
			}
			$day_slots = self::normalize_day_slots( $slots );
			if ( ! empty( $day_slots ) ) {
				$normalized[ $day ] = $day_slots;
			}
		}
		return $normalized;
	}

	/** @param array<int, mixed> $slots Raw time slots. @return array<int, array{open:string,close:string}> */
	private static function normalize_day_slots( array $slots ): array {
		$normalized = array();
		foreach ( array_slice( $slots, 0, \Cybermaps\Core\IdentityEntityBuilder::MAX_HOURS_SLOTS_PER_DAY ) as $slot ) {
			if ( ! is_array( $slot ) ) {
				continue;
			}
			$open  = isset( $slot['open'] ) ? self::sanitize_text_value( $slot['open'] ) : '';
			$close = isset( $slot['close'] ) ? self::sanitize_text_value( $slot['close'] ) : '';
			if ( self::is_time_value( $open ) && self::is_time_value( $close ) && $open !== $close ) {
				$normalized[] = array(
					'open'  => $open,
					'close' => $close,
				);
			}
		}
		return $normalized;
	}

	/** @return array<int, array<string, mixed>> */
	private static function normalize_catalogs( mixed $catalogs ): array {
		if ( ! is_array( $catalogs ) ) {
			return array();
		}
		$normalized      = array();
		$remaining_items = \Cybermaps\Core\IdentityEntityBuilder::MAX_OFFERS_TOTAL;
		foreach ( array_slice( $catalogs, 0, \Cybermaps\Core\IdentityEntityBuilder::MAX_CATALOGS ) as $catalog ) {
			if ( ! is_array( $catalog ) ) {
				continue;
			}
			$candidate = self::normalize_catalog( $catalog, $remaining_items );
			if ( null === $candidate ) {
				continue;
			}
			$normalized[] = $candidate;
			if ( 'manual' === $candidate['mode'] ) {
				$remaining_items -= count( $candidate['items'] );
			}
		}
		return $normalized;
	}

	/** @param array<string, mixed> $catalog Raw catalog. @return array<string, mixed>|null */
	private static function normalize_catalog( array $catalog, int $remaining_items ): ?array {
		$items      = self::normalize_catalog_items( $catalog['items'] ?? array(), $remaining_items );
		$attributes = self::normalize_catalog_attributes( $catalog );
		if ( ! self::catalog_is_publishable( $attributes['mode'], $attributes['parent_id'], $items ) ) {
			return null;
		}
		return array(
			'mode'      => $attributes['mode'],
			'item_type' => $attributes['item_type'],
			'name'      => $attributes['name'],
			'items'     => 'manual' === $attributes['mode'] ? $items : array(),
			'parent_id' => 'auto' === $attributes['mode'] ? $attributes['parent_id'] : 0,
		);
	}

	/** @return array<int, string> */
	private static function normalize_catalog_items( mixed $items, int $remaining_items ): array {
		if ( ! is_array( $items ) ) {
			return array();
		}
		$normalized = array();
		$item_limit = min( \Cybermaps\Core\IdentityEntityBuilder::MAX_OFFERS_PER_CATALOG, $remaining_items );
		foreach ( array_slice( $items, 0, $item_limit ) as $item ) {
			$trimmed = self::sanitize_text_value( $item );
			if ( ! empty( $trimmed ) ) {
				$normalized[] = $trimmed;
			}
		}
		return $normalized;
	}

	/** @param array<string, mixed> $catalog Raw catalog. @return array<string, mixed> */
	private static function normalize_catalog_attributes( array $catalog ): array {
		$mode = isset( $catalog['mode'] ) && is_scalar( $catalog['mode'] )
			? sanitize_key( (string) $catalog['mode'] )
			: 'manual';
		if ( ! in_array( $mode, array( 'manual', 'auto' ), true ) ) {
			$mode = 'manual';
		}
		$item_type = isset( $catalog['item_type'] ) && is_scalar( $catalog['item_type'] )
			? sanitize_text_field( (string) $catalog['item_type'] )
			: 'Service';
		if ( ! in_array( $item_type, array( 'Service', 'Product' ), true ) ) {
			$item_type = 'Service';
		}
		return array(
			'mode'      => $mode,
			'item_type' => $item_type,
			'name'      => isset( $catalog['name'] ) ? self::sanitize_text_value( $catalog['name'] ) : '',
			'parent_id' => self::positive_id( $catalog['parent_id'] ?? 0 ),
		);
	}

	/** @param array<int, string> $items Sanitized manual items. */
	private static function catalog_is_publishable( string $mode, int $parent_id, array $items ): bool {
		return ( 'auto' === $mode && $parent_id > 0 ) || ( 'manual' === $mode && ! empty( $items ) );
	}

	/** @return array<int, string> */
	private static function normalize_social_profiles( mixed $profiles ): array {
		if ( ! is_array( $profiles ) ) {
			return array();
		}
		$normalized = array();
		foreach ( array_slice( $profiles, 0, \Cybermaps\Core\IdentityEntityBuilder::MAX_SOCIAL_PROFILES ) as $profile ) {
			$url = self::normalize_public_url( $profile );
			if ( '' !== $url ) {
				$normalized[] = $url;
			}
		}
		return array_values( array_unique( $normalized ) );
	}

	/** @return array<int, array{type:string,phone:string,email:string}> */
	private static function normalize_contact_points( mixed $points ): array {
		if ( ! is_array( $points ) ) {
			return array();
		}
		$normalized = array();
		foreach ( array_slice( $points, 0, \Cybermaps\Core\IdentityEntityBuilder::MAX_CONTACT_POINTS ) as $point ) {
			if ( ! is_array( $point ) ) {
				continue;
			}
			$candidate = self::normalize_contact_point( $point );
			if ( null !== $candidate ) {
				$normalized[] = $candidate;
			}
		}
		return $normalized;
	}

	/** @param array<string, mixed> $point Raw contact point. @return array{type:string,phone:string,email:string}|null */
	private static function normalize_contact_point( array $point ): ?array {
		$type  = isset( $point['type'] ) ? self::sanitize_text_value( $point['type'] ) : '';
		$phone = isset( $point['phone'] )
			? self::sanitize_text_value( $point['phone'], \Cybermaps\Core\IdentityEntityBuilder::MAX_PHONE_LENGTH )
			: '';
		$email = isset( $point['email'] ) && is_scalar( $point['email'] )
			? sanitize_email(
				\Cybermaps\Discovery\PublicationConstraints::bounded_text(
					$point['email'],
					\Cybermaps\Core\IdentityEntityBuilder::MAX_EMAIL_LENGTH
				)
			)
			: '';
		if ( ! self::contact_point_is_valid( $type, $phone, $email ) ) {
			return null;
		}
		return array(
			'type'  => $type,
			'phone' => $phone,
			'email' => $email,
		);
	}

	private static function contact_point_is_valid( string $type, string $phone, string $email ): bool {
		return in_array( $type, array( 'Customer Support', 'Technical Support', 'Sales' ), true )
			&& ( '' !== $phone || '' !== $email );
	}

	private static function normalize_coordinate( mixed $value, float $minimum, float $maximum ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$value = trim( sanitize_text_field( (string) $value ) );
		if ( '' === $value || ! is_numeric( $value ) ) {
			return '';
		}

		$number = (float) $value;
		if ( ! is_finite( $number ) || $number < $minimum || $number > $maximum ) {
			return '';
		}

		$normalized = rtrim( rtrim( number_format( $number, 8, '.', '' ), '0' ), '.' );
		return '-0' === $normalized ? '0' : $normalized;
	}

	private static function is_time_value( string $value ): bool {
		return 1 === preg_match( '/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $value );
	}

	private static function sanitize_text_value(
		mixed $value,
		int $maximum = \Cybermaps\Core\IdentityEntityBuilder::MAX_TEXT_LENGTH
	): string {
		return \Cybermaps\Discovery\PublicationConstraints::bounded_text(
			is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '',
			$maximum
		);
	}

	private static function positive_id( mixed $value ): int {
		if ( ! is_int( $value ) && ! is_string( $value ) ) {
			return 0;
		}

		$value = trim( (string) $value );
		if ( 1 !== preg_match( '/^[1-9][0-9]*$/', $value ) ) {
			return 0;
		}

		$id = (int) $value;
		return $id > 0 && (string) $id === $value ? $id : 0;
	}

	private static function normalize_public_url( mixed $value ): string {
		return \Cybermaps\Core\URLManager::sanitize_http_url(
			\Cybermaps\Discovery\PublicationConstraints::bounded_text(
				$value,
				\Cybermaps\Core\IdentityEntityBuilder::MAX_URL_LENGTH
			)
		);
	}
}
