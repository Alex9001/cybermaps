<?php
declare(strict_types=1);
namespace Cybermaps\Admin\Settings\Fields;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class FieldRenderer {

	public static function render_textarea_field( $args ) {
		$options             = \Cybermaps\Core\ConfigurationStore::settings();
		$id                  = $args['label_for'];
		$value               = self::scalar_setting( $options, $id );
		$placeholder         = isset( $args['placeholder'] ) ? $args['placeholder'] : '';
		$maxlength           = isset( $args['maxlength'] ) ? max( 0, absint( $args['maxlength'] ) ) : 0;
		$maxlength_attribute = $maxlength > 0 ? ' maxlength="' . esc_attr( (string) $maxlength ) . '"' : '';
		echo '<textarea id="' . esc_attr( $id ) . '" name="cybermaps_settings[' . esc_attr( $id ) . ']" rows="5" cols="50" class="large-text code cybermaps-settings-input" placeholder="' . esc_attr( $placeholder ) . '"' . $maxlength_attribute . '>' . esc_textarea( $value ) . '</textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attribute name is fixed and value escaped.
		if ( isset( $args['description'] ) ) {
			echo '<p class="cybermaps-desc">' . wp_kses_post( $args['description'] ) . '</p>';
		}
	}
	public static function render_text_field( $args ) {
		$options               = \Cybermaps\Core\ConfigurationStore::settings();
		$id                    = $args['label_for'];
		$value                 = self::scalar_setting( $options, $id, $args['default'] ?? '' );
		$placeholder           = isset( $args['placeholder'] ) ? $args['placeholder'] : '';
		$type                  = isset( $args['type'] ) && in_array( $args['type'], array( 'number', 'url' ), true )
			? $args['type']
			: 'text';
		$class                 = 'number' === $type ? 'small-text' : 'regular-text';
		$constraint_attributes = '';
		if ( 'number' === $type ) {
			foreach ( array( 'min', 'max', 'step' ) as $attribute ) {
				if ( isset( $args[ $attribute ] ) && is_numeric( $args[ $attribute ] ) ) {
					$constraint_attributes .= ' ' . $attribute . '="' . esc_attr( (string) $args[ $attribute ] ) . '"';
				}
			}
		}
		if ( isset( $args['maxlength'] ) && absint( $args['maxlength'] ) > 0 ) {
			$constraint_attributes .= ' maxlength="' . esc_attr( (string) absint( $args['maxlength'] ) ) . '"';
		}
		echo '<input type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="cybermaps_settings[' . esc_attr( $id ) . ']" value="' . esc_attr( $value ) . '" class="' . esc_attr( $class ) . ' cybermaps-settings-input" placeholder="' . esc_attr( $placeholder ) . '"' . $constraint_attributes . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Constraint names are allowlisted and values are escaped.
		if ( isset( $args['description'] ) ) {
			echo '<p class="cybermaps-desc">' . wp_kses_post( $args['description'] ) . '</p>';
		}
	}

	public static function render_email_field( $args ) {
		$options     = \Cybermaps\Core\ConfigurationStore::settings();
		$id          = $args['label_for'];
		$value       = self::scalar_setting( $options, $id );
		$placeholder = isset( $args['placeholder'] ) ? $args['placeholder'] : '';
		echo '<input type="email" id="' . esc_attr( $id ) . '" name="cybermaps_settings[' . esc_attr( $id ) . ']" value="' . esc_attr( $value ) . '" class="regular-text cybermaps-settings-input" placeholder="' . esc_attr( $placeholder ) . '" autocomplete="off">';
		if ( isset( $args['description'] ) ) {
			echo '<p class="cybermaps-desc">' . wp_kses_post( $args['description'] ) . '</p>';
		}
	}
	public static function render_usage_select( $args ) {
		$options = \Cybermaps\Core\ConfigurationStore::settings();
		$id      = $args['label_for'];
		$current = self::scalar_setting( $options, $id, $args['default'] ?? 'allow' );
		$choices = isset( $args['options'] ) ? $args['options'] : array();

		echo '<select id="' . esc_attr( $id ) . '" name="cybermaps_settings[' . esc_attr( $id ) . ']" style="width: 100%; max-width: 400px;">';
		foreach ( $choices as $val => $label ) {
			echo '<option value="' . esc_attr( $val ) . '" ' . selected( $current, $val, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		if ( isset( $args['description'] ) ) {
			echo '<p class="description">' . esc_html( $args['description'] ) . '</p>';
		}
	}
	public static function render_media_upload_field( $args ) {
		$options = \Cybermaps\Core\ConfigurationStore::settings();
		$id      = $args['label_for'];
		$value   = self::scalar_setting( $options, $id );
		?>
		<div class="cm-media-upload-wrapper">
			<input type="url" id="<?php echo esc_attr( $id ); ?>" name="cybermaps_settings[<?php echo esc_attr( $id ); ?>]" value="<?php echo esc_attr( $value ); ?>" class="regular-text cm-media-url" style="width: 100%; max-width: 400px; margin-bottom: 10px;">
			<div class="cybermaps-media-button-root" data-target="#<?php echo esc_attr( $id ); ?>" data-value="<?php echo esc_attr( $value ); ?>"></div>
			<?php if ( isset( $args['description'] ) ) : ?>
				<p class="description"><?php echo esc_html( $args['description'] ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}
	public static function render_toggle( $args ) {
		$options = \Cybermaps\Core\ConfigurationStore::settings();
		$id      = $args['label_for'];
		$default = isset( $args['default'] ) ? (string) $args['default'] : '0';
		$value   = self::scalar_setting( $options, $id, $default );
		$checked = '1' === $value;

		$checked_attr = $checked ? 'checked' : '';

		echo '<label class="cm-toggle-wrapper">';
		echo '<input type="checkbox" id="' . esc_attr( $id ) . '" name="cybermaps_settings[' . esc_attr( $id ) . ']" value="1" class="cm-toggle-input" ' . checked( $checked_attr, 'checked', false ) . self::maturity_attribute( $args ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<span class="cm-toggle-switch"></span>';
		echo '<span class="cm-toggle-label">' . ( isset( $args['label'] ) ? esc_html( $args['label'] ) : '' ) . '</span>';
		echo '</label>';
		if ( isset( $args['description'] ) ) {
			echo '<p class="description">' . wp_kses_post( $args['description'] ) . '</p>';
		}
		self::render_maturity( $args );
	}
	public static function render_checkbox_field( $args ) {
		$options = \Cybermaps\Core\ConfigurationStore::settings();
		$id      = $args['label_for'];
		$checked = '1' === self::scalar_setting( $options, $id ) ? 'checked' : '';
		echo '<label class="cm-toggle-wrapper">';
		echo '<input type="checkbox" id="' . esc_attr( $id ) . '" name="cybermaps_settings[' . esc_attr( $id ) . ']" value="1" class="cm-toggle-input" ' . checked( $checked, 'checked', false ) . self::maturity_attribute( $args ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<span class="cm-toggle-switch"></span>';
		echo '<span class="cm-toggle-label">' . ( isset( $args['label'] ) ? esc_html( $args['label'] ) : '' ) . '</span>';
		echo '</label>';
		if ( isset( $args['description'] ) ) {
			echo '<p class="description">' . wp_kses_post( $args['description'] ) . '</p>';
		}
		self::render_maturity( $args );
	}
	public static function render_multi_checkbox_field( $args ) {
		$options          = \Cybermaps\Core\ConfigurationStore::settings();
		$id               = $args['label_for'];
		$selected         = isset( $options[ $id ] ) && is_array( $options[ $id ] )
			? $options[ $id ]
			: (array) ( $args['default'] ?? array() );
		$checkbox_options = isset( $args['options'] ) ? $args['options'] : array();

		echo '<div style="display: flex; flex-direction: column; gap: 5px;">';
		foreach ( $checkbox_options as $key => $label ) {
			$checked = in_array( $key, $selected, true ) ? 'checked' : '';
			echo '<label class="cm-checkbox-wrapper">';
			echo '<input type="checkbox" name="cybermaps_settings[' . esc_attr( $id ) . '][]" value="' . esc_attr( $key ) . '" ' . checked( $checked, 'checked', false ) . '> ' . esc_html( $label ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</label>';
		}
		echo '</div>';
		if ( isset( $args['description'] ) ) {
			echo '<p class="description">' . wp_kses_post( $args['description'] ) . '</p>';
		}
	}
	public static function render_api_secret_field() {
		$options = \Cybermaps\Core\ConfigurationStore::settings();
		$value   = isset( $options['api_secret'] ) && is_scalar( $options['api_secret'] )
			? (string) $options['api_secret']
			: '';
		echo '<input type="password" id="api_secret" name="cybermaps_settings[api_secret]" value="' . esc_attr( $value ) . '" class="regular-text code" autocomplete="off">';
		echo '<p class="description">' . wp_kses_post( __( 'Secret key for authenticating restricted REST API routes. Pass this in the <code>X-Cybermaps-Secret</code> header.', 'cybermaps' ) ) . '</p>';
	}
	public static function render_video_schema_toggle() {
		$options = \Cybermaps\Core\ConfigurationStore::settings();
		$checked = '1' === self::scalar_setting( $options, 'enable_video_schema', '1' ) ? 'checked' : '';
		echo '<label class="cm-toggle-wrapper">';
		echo '<input type="checkbox" name="cybermaps_settings[enable_video_schema]" value="1" class="cm-toggle-input" ' . checked( $checked, 'checked', false ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<span class="cm-toggle-switch"></span>';
		echo '<span class="cm-toggle-label">' . esc_html__( 'Enable On-page Video Schema', 'cybermaps' ) . '</span>';
		echo '</label>';

		echo '<div class="cybermaps-benefit">';
		echo '<span class="dashicons dashicons-video-alt3"></span>';
		echo '<div><strong>' . esc_html__( 'Stored-video markup:', 'cybermaps' ) . '</strong> ' . esc_html__( 'Cybermaps detects supported video markup in stored content and emits VideoObject JSON-LD on qualifying singular pages. Output should be validated; a search feature is never guaranteed.', 'cybermaps' ) . '</div>';
		echo '</div>';
	}
	public static function render_static_engine_mode( $args ) {
		$current   = \Cybermaps\Discovery\StaticBridge::get_mode();
		$multisite = function_exists( 'is_multisite' ) && is_multisite();
		$choices   = array(
			'well_known' => __( 'Core compatibility files (recommended)', 'cybermaps' ),
			'all'        => __( 'Full publication cache (advanced)', 'cybermaps' ),
			'off'        => __( 'Dynamic only — publish no files', 'cybermaps' ),
		);
		echo '<select id="static_engine_mode" name="cybermaps_settings[static_engine_mode]" style="min-width:320px;"' . ( $multisite ? ' disabled' : '' ) . '>';
		foreach ( $choices as $val => $label ) {
			echo '<option value="' . esc_attr( $val ) . '" ' . selected( $current, $val, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
		if ( $multisite ) {
			echo '<input type="hidden" name="cybermaps_settings[static_engine_mode]" value="off">';
			echo '<p class="cybermaps-desc"><strong>' . esc_html__( 'Multisite safety:', 'cybermaps' ) . '</strong> ' . esc_html__( 'Physical origin-root files are disabled because sites share a web root. WordPress native routing remains active for the network origin.', 'cybermaps' ) . '</p>';
		}
		if ( isset( $args['description'] ) ) {
			echo '<p class="cybermaps-desc">' . wp_kses_post( $args['description'] ) . '</p>';
		}
	}
	public static function render_select_field( $args ) {
		$options = \Cybermaps\Core\ConfigurationStore::settings();
		$id      = $args['label_for'];
		$value   = self::scalar_setting( $options, $id, $args['default'] ?? '' );
		$choices = $args['options'] ?? array();
		echo '<select id="' . esc_attr( $id ) . '" name="cybermaps_settings[' . esc_attr( $id ) . ']" style="min-width:180px;"' . self::maturity_attribute( $args ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		foreach ( $choices as $opt_val => $opt_label ) {
			echo '<option value="' . esc_attr( $opt_val ) . '" ' . selected( $value, $opt_val, false ) . '>' . esc_html( $opt_label ) . '</option>';
		}
		echo '</select>';
		if ( ! empty( $args['description'] ) ) {
			echo '<p class="cybermaps-desc">' . wp_kses_post( $args['description'] ) . '</p>';
		}
		self::render_maturity( $args );
	}

	/** Return an aria-describedby attribute for maturity guidance. */
	private static function maturity_attribute( array $args ): string {
		if ( empty( $args['maturity'] ) || empty( $args['label_for'] ) ) {
			return '';
		}

		return ' aria-describedby="' . esc_attr( \Cybermaps\Admin\MaturityGuidance::description_id( (string) $args['label_for'] ) ) . '"';
	}

	/** Render optional visible maturity guidance. */
	private static function render_maturity( array $args ): void {
		if ( empty( $args['maturity'] ) || empty( $args['label_for'] ) ) {
			return;
		}

		\Cybermaps\Admin\MaturityGuidance::render( (string) $args['label_for'], (string) $args['maturity'] );
	}

	/**
	 * Resolve a scalar setting without allowing a malformed nested value to
	 * leak into an HTML escaping or selection helper.
	 *
	 * @param array<string, mixed> $settings General plugin settings.
	 * @param string               $key Setting key.
	 * @param mixed                $fallback Fallback value.
	 */
	private static function scalar_setting( array $settings, string $key, $fallback = '' ): string {
		$fallback = is_scalar( $fallback ) ? (string) $fallback : '';
		if ( ! array_key_exists( $key, $settings ) || ! is_scalar( $settings[ $key ] ) ) {
			return $fallback;
		}

		return (string) $settings[ $key ];
	}
}
