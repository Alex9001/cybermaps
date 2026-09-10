<?php
declare(strict_types=1);

namespace Cybermaps\Admin;

use Cybermaps\Admin\Settings\Sanitizers\DiscoveryCenterSanitizer;
use Cybermaps\Admin\Settings\Sanitizers\RobotsManagerSanitizer;
use Cybermaps\Admin\Settings\Sanitizers\SettingsSanitizer;
use Cybermaps\Core\CrawlerRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles complete current-format configuration backups and merge-only AI
 * templates.
 */
final class MigrationHub {
	private const BACKUP_FORMAT         = 'cybermaps-configuration-backup';
	private const BACKUP_VERSION        = 1;
	private const AI_BRIEF_FORMAT       = 'cybermaps-ai-configuration-changes';
	private const AI_BRIEF_VERSION      = 2;
	private const AI_BRIEF_MARKER       = 'CYBERMAPS-AI-CONFIG-BRIEF: 2';
	private const AI_CHANGES_BEGIN      = '<!-- CYBERMAPS-CHANGES-BEGIN -->';
	private const AI_CHANGES_END        = '<!-- CYBERMAPS-CHANGES-END -->';
	private const MAX_IMPORT_BYTES      = 1048576;
	private const MAX_VALIDATION_ERRORS = 64;

	/**
	 * Configuration groups required in a complete backup.
	 *
	 * @var string[]
	 */
	private const REQUIRED_GROUPS = array(
		'cybermaps_settings',
		'cybermaps_discovery_center',
		'cybermaps_robots_manager',
		'cybermaps_identity_data',
	);

	/**
	 * Complete option boundary owned by configuration exchange.
	 *
	 * @var string[]
	 */
	private const CONFIGURATION_OPTIONS = array(
		'cybermaps_settings',
		'cybermaps_discovery_center',
		'cybermaps_robots_manager',
		'cybermaps_identity_data',
		'cybermaps_indexnow_key',
	);

	private static ?self $instance                = null;
	private static bool $applying_prepared_import = false;
	private static bool $import_in_progress       = false;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Whether MigrationHub is writing values that already passed every canonical
	 * sanitizer. Sanitizers use this only to avoid mutating the prepared value a
	 * second time inside update_option().
	 */
	public static function is_applying_prepared_import(): bool {
		return self::$applying_prepared_import;
	}

	/**
	 * Maximum accepted import size in bytes.
	 *
	 * Kept public so the browser-side guard can use the same authoritative
	 * limit as the importer. The server remains authoritative.
	 */
	public static function get_max_import_bytes(): int {
		return self::MAX_IMPORT_BYTES;
	}

	/**
	 * Generate a complete, versioned JSON configuration backup.
	 */
	public function generate_backup(): string {
		$configuration = $this->current_configuration();
		$payload       = array(
			'format'           => self::BACKUP_FORMAT,
			'format_version'   => self::BACKUP_VERSION,
			'plugin_version'   => defined( 'CYBERMAPS_VERSION' ) ? CYBERMAPS_VERSION : '',
			'generated_gmt'    => gmdate( 'c' ),
			'source_site'      => home_url( '/' ),
			'contains_secrets' => true,
			'configuration'    => $configuration,
			'checksum'         => $this->configuration_checksum( $configuration ),
		);

		$backup = $this->encode_json( $payload, true ) . "\n";
		if ( strlen( $backup ) > self::MAX_IMPORT_BYTES ) {
			throw new \RuntimeException(
				esc_html__( 'The stored Cybermaps configuration exceeds the 1 MB portable-backup limit. Reduce unusually large configuration fields before exporting.', 'cybermaps' )
			);
		}

		return $backup;
	}

	/**
	 * Generate the editable AI configuration template.
	 *
	 * This artifact is deliberately separate from the complete JSON backup. Null
	 * values mean "leave the destination unchanged" during merge import.
	 */
	public function generate_markdown(): string {
		$version   = defined( 'CYBERMAPS_VERSION' ) ? (string) CYBERMAPS_VERSION : '';
		$sections  = AIConfigurationRegistry::get_sections();
		$fields    = AIConfigurationRegistry::get_fields();
		$current   = $this->ai_current_values( $fields );
		$changes   = array();
		$reference = '';

		foreach ( $sections as $section_id => $section ) {
			$changes[ $section_id ] = array();
			$reference             .= '## ' . $section['label'] . "\n\n";
			$reference             .= $section['purpose'] . "\n\n";
			foreach ( $fields as $field_id => $field ) {
				if ( $section_id !== $field['section_id'] ) {
					continue;
				}
				$changes[ $section_id ][ $field_id ] = null;
				$reference                          .= '### `' . $field_id . '` — ' . $field['label'] . "\n\n";
				$reference                          .= '- Purpose: ' . $field['purpose'] . "\n";
				$reference                          .= '- JSON type: `' . $field['json_type'] . "`\n";
				$reference                          .= '- Accepted constraints: `' . $this->encode_json( $field['allowed'] ) . "`\n";
				$reference                          .= '- Effective default: `' . $this->encode_json( $field['effective_default'] ) . "`\n";
				$reference                          .= '- Current effective value: `' . $this->encode_json( $current[ $section_id ][ $field_id ] ?? null ) . "`\n";
				$reference                          .= '- Example: `' . $this->encode_json( $field['example'] ) . "`\n";
				$reference                          .= '- Dependencies: `' . $this->encode_json( $field['dependencies'] ) . "`\n";
				$reference                          .= '- Review risk: `' . $field['risk'] . "`\n\n";
			}
		}

		$envelope  = array(
			'format'         => self::AI_BRIEF_FORMAT,
			'format_version' => self::AI_BRIEF_VERSION,
			'plugin_version' => $version,
			'changes'        => $changes,
		);
		$spec_base = 'https://cybermaps.dev/specs/ai-configuration/' . rawurlencode( $version );
		$output    = "# CYBERMAPS AI CONFIGURATION BRIEF\n\n";
		$output   .= '<!-- ' . self::AI_BRIEF_MARKER . " -->\n\n";
		$output   .= "This self-contained brief gives an AI assistant the real site context, current non-secret Cybermaps configuration, accepted values, and a strictly bounded changes block. It imports with Smart Merge only.\n\n";
		$output   .= "## Important privacy and safety information\n\n";
		$output   .= "- This brief intentionally excludes the private Cybermaps REST API secret, IndexNow key, credential-bearing URL userinfo, analytics logs, report data, post bodies, and media content. User-authored non-secret fields can still contain sensitive information, so review the complete file before sharing it.\n";
		$output   .= "- It does include the site's current non-secret Cybermaps configuration: publication guidance and policies, external URLs, report branding, crawler choices, and configured public identity/contact/catalog information. Review all of it before sharing the file.\n";
		$output   .= "- Keep the separate complete Cybermaps backup private; that exact restoration artifact can contain the site's private Cybermaps REST API secret and user-entered settings.\n";
		$output   .= "- Cybermaps will preview and sanitize every proposed change. The administrator must review the final values before applying them.\n\n";
		$output   .= "## Instructions for the AI assistant\n\n";
		$output   .= "1. Read the site context, current values, field reference, and the user's stated goals before proposing changes.\n";
		$output   .= "2. Ask focused questions when the goals or necessary facts are missing. Do not invent post IDs, taxonomy names, URLs, coordinates, contact details, legal/license declarations, action capabilities, or crawler policy.\n";
		$output   .= "3. Modify only values inside the sentinel-delimited JSON changes block. Keep the format, format_version, plugin_version, section IDs, field IDs, sentinels, and valid JSON intact.\n";
		$output   .= "4. A field set to null or omitted preserves the destination. Use an explicit empty string, empty array, or empty object only where that field type supports clearing. Use native JSON true/false, never quoted boolean strings.\n";
		$output   .= "5. Return either this complete artifact with only the changes block edited, or the pure JSON changes envelope. Do not add unknown properties.\n";
		$output   .= "6. Treat high-risk settings—public routes, crawler rules, public identity, static publication, analytics privacy, and output enablement—as decisions requiring explicit user intent.\n\n";
		$output   .= "## User goal\n\nDescribe the desired site behavior here, or provide the goal in the conversation with the AI assistant.\n\n";
		$output   .= "## Site context (read only)\n\n```json\n" . $this->encode_json( $this->redact_credential_urls( $this->brief_site_context( $current ) ), true ) . "\n```\n\n";
		$output   .= "## Current non-secret configuration (read only)\n\n```json\n" . $this->encode_json( $current, true ) . "\n```\n\n";
		$output   .= "## Editable field reference\n\n" . $reference;
		$output   .= "## Proposed changes (the only editable block)\n\n";
		$output   .= self::AI_CHANGES_BEGIN . "\n```json\n" . $this->encode_json( $envelope, true ) . "\n```\n" . self::AI_CHANGES_END . "\n\n";
		$output   .= "## Canonical specification\n\n";
		$output   .= "- Guide: https://cybermaps.dev/docs/ai-configuration/\n";
		$output   .= '- JSON Schema: ' . $spec_base . "/schema.json\n";
		$output   .= '- Field catalog: ' . $spec_base . "/catalog.json\n";

		if ( strlen( $output ) > self::MAX_IMPORT_BYTES ) {
			throw new \RuntimeException(
				esc_html__( 'The AI Configuration Brief exceeds the 1 MB portable-file limit. Reduce unusually large public identity or configuration values before exporting.', 'cybermaps' )
			);
		}

		return $output;
	}

	/**
	 * Import a complete backup or a v2 AI Configuration Brief.
	 *
	 * @param string $content Uploaded configuration content.
	 * @param string $mode    merge or overwrite.
	 * @return array{format:string,mode:string,imported_count:int,changed_groups:string[],unchanged_groups:string[],warnings:string[]}
	 */
	public function import( string $content, string $mode = 'merge' ): array {
		$prepared = $this->prepare_with_guard( $content, $mode );
		return $this->apply_prepared_import( $prepared );
	}

	/**
	 * Parse, validate, and sanitize an import without writing any option.
	 *
	 * @param string $content Uploaded configuration content.
	 * @param string $mode    merge or overwrite.
	 * @return array<string,mixed>
	 */
	public function preview( string $content, string $mode = 'merge' ): array {
		$prepared = $this->prepare_with_guard( $content, $mode );
		return $this->build_preview_result( $content, $prepared );
	}

	/**
	 * Apply only the exact file and destination state that an administrator
	 * previously previewed.
	 *
	 * @param string $content            Uploaded configuration content.
	 * @param string $mode               merge or overwrite.
	 * @param string $content_hash       Hash returned by preview().
	 * @param string $configuration_hash Destination hash returned by preview().
	 * @return array<string,mixed>
	 */
	public function import_previewed(
		string $content,
		string $mode,
		string $content_hash,
		string $configuration_hash
	): array {
		$actual_content_hash = $this->request_checksum( $content, $mode );
		if ( '' === $content_hash || ! hash_equals( $actual_content_hash, $content_hash ) ) {
			throw new \InvalidArgumentException(
				esc_html__( 'The configuration file changed after preview. Preview it again before importing.', 'cybermaps' )
			);
		}

		$actual_configuration_hash = $this->configuration_checksum( $this->current_configuration() );
		if ( '' === $configuration_hash || ! hash_equals( $actual_configuration_hash, $configuration_hash ) ) {
			throw new \InvalidArgumentException(
				esc_html__( 'The destination configuration changed after preview. Preview the file again before importing.', 'cybermaps' )
			);
		}

		$prepared                    = $this->prepare_with_guard( $content, $mode );
		$prepared_configuration_hash = isset( $prepared['base_configuration_hash'] )
			&& is_string( $prepared['base_configuration_hash'] )
				? $prepared['base_configuration_hash']
				: '';
		if ( '' === $prepared_configuration_hash || ! hash_equals( $configuration_hash, $prepared_configuration_hash ) ) {
			throw new \InvalidArgumentException(
				esc_html__( 'The destination configuration changed while the import was being prepared. Preview the file again before importing.', 'cybermaps' )
			);
		}
		return $this->apply_prepared_import( $prepared );
	}

	/**
	 * @param string $content Uploaded configuration content.
	 * @param string $mode    merge or overwrite.
	 * @return array<string,mixed>
	 */
	private function prepare_with_guard( string $content, string $mode ): array {
		$this->validate_import_request( $content, $mode );
		$base_state = $this->capture_configuration_state();

		self::$import_in_progress = true;
		try {
			$prepared                            = $this->prepare_import_content( $content, $mode, $base_state );
			$prepared['base_configuration']      = $base_state['configuration'];
			$prepared['base_raw_options']        = $base_state['raw_options'];
			$prepared['base_configuration_hash'] = $this->configuration_checksum( $base_state['configuration'] );
			return $prepared;
		} finally {
			self::$import_in_progress = false;
		}
	}

	/** Validate and normalize an incoming import request. */
	private function validate_import_request( string &$content, string $mode ): void {
		if ( strlen( $content ) > self::MAX_IMPORT_BYTES ) {
			throw new \InvalidArgumentException( esc_html__( 'The configuration file exceeds the 1 MB import limit.', 'cybermaps' ) );
		}

		$content = preg_replace( '/^\xEF\xBB\xBF/', '', $content ) ?? $content;
		if ( '' === trim( $content ) ) {
			throw new \InvalidArgumentException( esc_html__( 'The configuration file is empty.', 'cybermaps' ) );
		}

		if ( ! in_array( $mode, array( 'merge', 'overwrite' ), true ) ) {
			throw new \InvalidArgumentException( esc_html__( 'The requested configuration import mode is invalid.', 'cybermaps' ) );
		}
		if ( self::$import_in_progress ) {
			throw new \RuntimeException(
				esc_html__( 'Another Cybermaps configuration import is already running in this request.', 'cybermaps' )
			);
		}
	}

	/** Route validated import content to its format-specific preparer. */
	private function prepare_import_content( string $content, string $mode, array $base_state ): array {
		if ( '{' === substr( ltrim( $content ), 0, 1 ) ) {
			$payload = $this->decode_json_object(
				$content,
				/* translators: %s: JSON parser error message. */
				__( 'The configuration JSON is invalid: %s', 'cybermaps' )
			);
			if ( self::AI_BRIEF_FORMAT !== ( $payload['format'] ?? null ) ) {
				return $this->prepare_backup( $content, $mode, $payload, $base_state );
			}
			if ( 'merge' !== $mode ) {
				throw new \InvalidArgumentException( esc_html__( 'AI Configuration Briefs use Smart Merge only.', 'cybermaps' ) );
			}
			return $this->prepare_ai_changes( $payload, $base_state );
		}
		if ( 'overwrite' === $mode ) {
			throw new \InvalidArgumentException(
				esc_html__( 'Full Replace requires a complete Cybermaps JSON backup. AI Configuration Briefs use Smart Merge only.', 'cybermaps' )
			);
		}
		if ( str_contains( $content, self::AI_BRIEF_MARKER ) ) {
			return $this->prepare_ai_changes( $this->extract_ai_changes( $content ), $base_state );
		}
		throw new \InvalidArgumentException(
			esc_html__( 'Use a complete Cybermaps JSON backup or a version 2 AI Configuration Brief. Earlier Markdown templates are no longer supported.', 'cybermaps' )
		);
	}

	/**
	 * Apply a fully parsed and sanitized import plan.
	 *
	 * @param array<string,mixed> $prepared Prepared import data.
	 * @return array<string,mixed>
	 */
	private function apply_prepared_import( array $prepared ): array {
		if ( self::$import_in_progress ) {
			throw new \RuntimeException(
				esc_html__( 'Another Cybermaps configuration import is already running in this request.', 'cybermaps' )
			);
		}

		$errors = $this->prepared_string_list( $prepared, 'errors' );
		if ( ! empty( $errors ) ) {
			throw new \InvalidArgumentException( implode( ' ', $errors ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Validation diagnostics are JSON-encoded for the authenticated admin response; React owns the eventual text output boundary.
		}

		$targets                  = $this->prepared_array( $prepared, 'targets' );
		$base_raw_options         = $this->prepared_array( $prepared, 'base_raw_options' );
		self::$import_in_progress = true;
		try {
			$changes = $this->apply_targets( $targets, $base_raw_options );
		} finally {
			self::$import_in_progress = false;
		}
		foreach ( $this->prepared_string_list( $prepared, 'post_unchanged' ) as $option_name ) {
			if ( is_string( $option_name ) && '' !== $option_name ) {
				$changes['unchanged'][] = $option_name;
			}
		}
		return $this->build_applied_import_result( $prepared, $changes );
	}

	/** Return an array-valued prepared-import field. */
	private function prepared_array( array $prepared, string $key ): array {
		return isset( $prepared[ $key ] ) && is_array( $prepared[ $key ] ) ? $prepared[ $key ] : array();
	}

	/** Return a filtered string-list prepared-import field. */
	private function prepared_string_list( array $prepared, string $key ): array {
		return array_values( array_filter( array_map( 'strval', $this->prepared_array( $prepared, $key ) ) ) );
	}

	/** Build the public result for an applied prepared import. */
	private function build_applied_import_result( array $prepared, array $changes ): array {
		return array(
			'format'           => (string) ( $prepared['format'] ?? 'configuration' ),
			'mode'             => (string) ( $prepared['mode'] ?? 'merge' ),
			'imported_count'   => max( 0, (int) ( $prepared['imported_count'] ?? 0 ) ),
			'changed_groups'   => array_values( array_unique( $changes['changed'] ) ),
			'unchanged_groups' => array_values( array_unique( $changes['unchanged'] ) ),
			'warnings'         => $this->prepared_string_list( $prepared, 'warnings' ),
		);
	}

	/**
	 * Build a field-level review without exposing stored credentials.
	 *
	 * @param array<string,mixed> $prepared Prepared import data.
	 * @return array<string,mixed>
	 */
	private function build_preview_result( string $content, array $prepared ): array {
		$current          = $this->preview_base_configuration( $prepared );
		$targets          = $this->preview_prepared_array( $prepared, 'targets' );
		$proposed         = $this->preview_prepared_array( $prepared, 'proposed_values' );
		$touched_fields   = $this->preview_prepared_array( $prepared, 'touched_fields' );
		$lookup           = $this->preview_field_lookup();
		$changes          = array();
		$high_impact      = array();
		$changed_groups   = array();
		$unchanged_groups = array();

		foreach ( $targets as $option_name => $target_value ) {
			$this->append_preview_group_changes(
				(string) $option_name,
				$target_value,
				$current,
				$proposed,
				$touched_fields,
				$lookup,
				$changes,
				$high_impact,
				$changed_groups,
				$unchanged_groups
			);
		}
		$this->append_post_unchanged_groups( $prepared, $unchanged_groups );

		return array(
			'format'              => (string) ( $prepared['format'] ?? 'configuration' ),
			'mode'                => (string) ( $prepared['mode'] ?? 'merge' ),
			'content_hash'        => $this->request_checksum( $content, (string) ( $prepared['mode'] ?? 'merge' ) ),
			'configuration_hash'  => $this->configuration_checksum( $current ),
			'changes'             => $changes,
			'errors'              => $this->preview_prepared_messages( $prepared, 'errors' ),
			'warnings'            => $this->preview_prepared_messages( $prepared, 'warnings' ),
			'high_impact_changes' => $high_impact,
			'changed_groups'      => array_values( array_unique( $changed_groups ) ),
			'unchanged_groups'    => array_values( array_unique( $unchanged_groups ) ),
		);  }
	/**
	 * Resolve the configuration snapshot used by preview.
	 *
	 * @param array<string,mixed> $prepared Prepared import data.
	 * @return array<string,mixed>
	 */
	private function preview_base_configuration( array $prepared ): array {
		if ( isset( $prepared['base_configuration'] ) && is_array( $prepared['base_configuration'] ) ) {
			return $prepared['base_configuration'];
		}

		return $this->current_configuration();
	}

	/**
	 * Read an array from prepared import data.
	 *
	 * @param array<string,mixed> $prepared Prepared import data.
	 * @param string              $key Data key.
	 * @return array<mixed>
	 */
	private function preview_prepared_array( array $prepared, string $key ): array {
		$value = $prepared[ $key ] ?? null;
		return is_array( $value ) ? $value : array();
	}

	/**
	 * Read and normalize a prepared diagnostic list.
	 *
	 * @param array<string,mixed> $prepared Prepared import data.
	 * @param string              $key Data key.
	 * @return list<mixed>
	 */
	private function preview_prepared_messages( array $prepared, string $key ): array {
		return array_values( $this->preview_prepared_array( $prepared, $key ) );
	}

	/**
	 * Append groups explicitly retained after preparation.
	 *
	 * @param array<string,mixed> $prepared Prepared import data.
	 * @param list<string>        $unchanged_groups Mutable unchanged groups.
	 */
	private function append_post_unchanged_groups( array $prepared, array &$unchanged_groups ): void {
		foreach ( (array) ( $prepared['post_unchanged'] ?? array() ) as $option_name ) {
			if ( is_string( $option_name ) ) {
				$unchanged_groups[] = $option_name;
			}
		}
	}

	/** Append preview changes for one owned option group. */
	private function append_preview_group_changes( string $option_name, $target_value, array $current, array $proposed, array $touched_fields, array $lookup, array &$changes, array &$high_impact, array &$changed_groups, array &$unchanged_groups ): void {
		$current_value  = $current[ $option_name ] ?? null;
		$target_compare = $this->preview_target_value( $option_name, $target_value );
		if ( $current_value !== $target_compare ) {
			$changed_groups[] = $option_name;
		} else {
			$unchanged_groups[] = $option_name;
		}
		$fields = $this->preview_group_fields( $option_name, $target_compare, $touched_fields );
		foreach ( $fields as $field ) {
			$change    = $this->build_preview_change( $option_name, $field, $current_value, $target_compare, $proposed, $lookup );
			$changes[] = $change;
			if ( ! empty( $change['high_impact'] ) && 'unchanged' !== $change['status'] ) {
				$high_impact[] = $change;
			}
		}
	}

	/** Normalize a preview target into its comparable shape. */
	private function preview_target_value( string $option_name, $target_value ) {
		if ( 'cybermaps_discovery_center' !== $option_name || ! is_string( $target_value ) ) {
			return $target_value;
		}
		$decoded = json_decode( $target_value, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/** Resolve the fields represented by one preview group. */
	private function preview_group_fields( string $option_name, $target_compare, array $touched_fields ): array {
		if ( isset( $touched_fields[ $option_name ] ) && is_array( $touched_fields[ $option_name ] ) ) {
			return array_values( array_map( 'strval', $touched_fields[ $option_name ] ) );
		}
		return is_array( $target_compare ) ? array_keys( $target_compare ) : array( $option_name );
	}

	/** Build one field-level preview entry. */
	private function build_preview_change( string $option_name, string $field, $current_value, $target_compare, array $proposed, array $lookup ): array {
		$definition = $lookup[ $option_name . ':' . $field ] ?? array();
		$before     = $this->preview_field_value( $current_value, $field );
		$final      = $this->preview_field_value( $target_compare, $field );
		$before     = $this->normalize_preview_definition_value( $definition, $before );
		$final      = $this->normalize_preview_definition_value( $definition, $final );
		$raw        = $this->preview_proposed_value( $proposed, $option_name, $field, $final );
		$raw        = $this->normalize_preview_definition_value( $definition, $raw );
		$sensitive  = $this->is_sensitive_field( $option_name, $field );
		return array(
			'section'     => $this->preview_definition_string( $definition, 'section_label', $this->option_label( $option_name ) ),
			'field'       => $this->preview_definition_string( $definition, 'id', $field ),
			'label'       => $this->preview_definition_string( $definition, 'label', $this->humanize_key( $field ) ),
			'before'      => $this->preview_visible_value( $before, $sensitive ),
			'proposed'    => $this->preview_visible_value( $raw, $sensitive ),
			'final'       => $this->preview_visible_value( $final, $sensitive ),
			'status'      => $this->preview_change_status( $before, $raw, $final ),
			'high_impact' => $this->preview_is_high_impact( $definition, $sensitive ),
		);  }
	/**
	 * Read one preview field from a group or scalar value.
	 *
	 * @param mixed  $value Preview value.
	 * @param string $field Field name.
	 * @return mixed
	 */
	private function preview_field_value( $value, string $field ) {
		return is_array( $value ) ? ( $value[ $field ] ?? null ) : $value;
	}

	/**
	 * Read a string field definition value.
	 *
	 * @param array<string,mixed> $definition Field definition.
	 * @param string              $key Definition key.
	 * @param string              $fallback Fallback value.
	 */
	private function preview_definition_string( array $definition, string $key, string $fallback ): string {
		return (string) ( $definition[ $key ] ?? $fallback );
	}

	/**
	 * Redact a sensitive preview value.
	 *
	 * @param mixed $value Preview value.
	 * @param bool  $sensitive Whether the field is sensitive.
	 * @return mixed
	 */
	private function preview_visible_value( $value, bool $sensitive ) {
		return $sensitive ? '[redacted]' : $value;
	}

	/**
	 * Determine whether a preview row is high impact.
	 *
	 * @param array<string,mixed> $definition Field definition.
	 * @param bool                $sensitive Whether the field is sensitive.
	 */
	private function preview_is_high_impact( array $definition, bool $sensitive ): bool {
		return 'high' === ( $definition['risk'] ?? '' ) || $sensitive;
	}

	/** Normalize a preview value according to its registry definition. */
	private function normalize_preview_definition_value( array $definition, $value ) {
		if ( empty( $definition ) ) {
			return $value;
		}
		return $this->ai_value_from_storage( (string) $definition['id'], $value, (string) $definition['json_type'], $definition['effective_default'] );
	}

	/** Resolve a proposed preview value before sanitizer normalization. */
	private function preview_proposed_value( array $proposed, string $option_name, string $field, $final_value ) {
		if ( isset( $proposed[ $option_name ] ) && is_array( $proposed[ $option_name ] ) && array_key_exists( $field, $proposed[ $option_name ] ) ) {
			return $proposed[ $option_name ][ $field ];
		}
		return $final_value;
	}

	/** Derive the preview status for one field. */
	private function preview_change_status( $before, $raw, $final_value ): string {
		if ( $this->configuration_values_equal( $before, $final_value ) ) {
			return 'unchanged';
		}
		return $this->configuration_values_equal( $raw, $final_value ) ? 'changed' : 'normalized';
	}

	/**
	 * Decode an object-shaped JSON document with a safe, useful error message.
	 *
	 * @return array<string,mixed>
	 */
	private function decode_json_object( string $content, string $error_template ): array {
		try {
			$decoded = json_decode( $content, true, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException $error ) {
			throw new \InvalidArgumentException(
				sprintf( $error_template, esc_html( $error->getMessage() ) ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The template is an internal translated string supplied by the import parser, and the JSON error detail is escaped here.
			);
		}

		if ( ! is_array( $decoded ) || ( ! empty( $decoded ) && array_is_list( $decoded ) ) ) {
			throw new \InvalidArgumentException(
				esc_html__( 'The configuration JSON must be an object.', 'cybermaps' )
			);
		}

		return $decoded;
	}

	/**
	 * Extract the one machine-editable JSON envelope from a v2 Markdown brief.
	 *
	 * @return array<string,mixed>
	 */
	private function extract_ai_changes( string $content ): array {
		$start = strpos( $content, self::AI_CHANGES_BEGIN );
		$end   = false === $start ? false : strpos( $content, self::AI_CHANGES_END, $start + strlen( self::AI_CHANGES_BEGIN ) );
		if ( false === $start || false === $end || $end <= $start ) {
			throw new \InvalidArgumentException(
				esc_html__( 'The AI Configuration Brief is missing its protected JSON changes block.', 'cybermaps' )
			);
		}
		if ( false !== strpos( $content, self::AI_CHANGES_BEGIN, $start + strlen( self::AI_CHANGES_BEGIN ) ) ) {
			throw new \InvalidArgumentException(
				esc_html__( 'The AI Configuration Brief contains more than one changes block.', 'cybermaps' )
			);
		}

		$json = trim(
			substr(
				$content,
				$start + strlen( self::AI_CHANGES_BEGIN ),
				$end - ( $start + strlen( self::AI_CHANGES_BEGIN ) )
			)
		);
		if ( preg_match( '/^```(?:json)?\s*\R([\s\S]*)\R```$/i', $json, $match ) ) {
			$json = trim( $match[1] );
		}

		return $this->decode_json_object(
			$json,
			/* translators: %s: JSON parser error message. */
			__( 'The AI Configuration Brief changes block contains invalid JSON: %s', 'cybermaps' )
		);
	}

	/**
	 * Bind a preview token to the exact file and import mode.
	 */
	private function request_checksum( string $content, string $mode ): string {
		$tuple = array(
			'content' => $content,
			'mode'    => $mode,
		);
		return 'sha256:' . hash( 'sha256', $this->encode_json( $tuple ) );
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private function preview_field_lookup(): array {
		$lookup = array();
		if ( class_exists( AIConfigurationRegistry::class ) ) {
			foreach ( AIConfigurationRegistry::get_fields() as $definition ) {
				if ( ! is_array( $definition ) ) {
					continue;
				}
				$option = isset( $definition['option'] ) ? (string) $definition['option'] : '';
				$field  = isset( $definition['field'] ) ? (string) $definition['field'] : '';
				if ( '' !== $option && '' !== $field ) {
					$lookup[ $option . ':' . $field ] = $definition;
				}
			}
		}
		$lookup['cybermaps_settings:delete_data_on_uninstall'] = array(
			'id'                => 'delete_data_on_uninstall',
			'section_label'     => __( 'Advanced & Maintenance', 'cybermaps' ),
			'label'             => __( 'Uninstall cleanup', 'cybermaps' ),
			'json_type'         => 'boolean',
			'effective_default' => false,
			'risk'              => 'high',
		);
		return $lookup;
	}

	private function is_sensitive_field( string $option_name, string $field ): bool {
		if ( 'cybermaps_indexnow_key' === $option_name || 'api_secret' === $field ) {
			return true;
		}

		return 1 === preg_match( '/(?:^|_)(?:secret|password|credential|private_key|access_token)(?:$|_)/i', $field );
	}

	private function option_label( string $option_name ): string {
		return match ( $option_name ) {
			'cybermaps_settings'         => __( 'Cybermaps Settings', 'cybermaps' ),
			'cybermaps_discovery_center' => __( 'Content Discovery Strategy', 'cybermaps' ),
			'cybermaps_robots_manager'   => __( 'Crawler Policy', 'cybermaps' ),
			'cybermaps_identity_data'    => __( 'Site Identity', 'cybermaps' ),
			'cybermaps_indexnow_key'     => __( 'IndexNow Credential', 'cybermaps' ),
			default                       => $this->humanize_key( $option_name ),
		};
	}

	private function humanize_key( string $key ): string {
		$words = str_replace( array( '-', '_' ), ' ', $key );
		return ucwords( trim( $words ) );
	}

	/**
	 * Compare JSON-facing values semantically so separate empty-object instances
	 * and numerically identical structured values do not create false changes.
	 *
	 * @param mixed $left  First value.
	 * @param mixed $right Second value.
	 */
	private function configuration_values_equal( $left, $right ): bool {
		try {
			return $this->encode_json( $left ) === $this->encode_json( $right );
		} catch ( \RuntimeException $error ) {
			unset( $error );
			return $left === $right;
		}
	}

	/**
	 * Render stored values through the stable, AI-facing field types.
	 *
	 * @param array<string,array<string,mixed>> $fields Registry fields.
	 * @return array<string,array<string,mixed>>
	 */
	private function ai_current_values( array $fields ): array {
		$current = $this->current_configuration();
		$values  = array();
		$routes  = \Cybermaps\Sitemap\PublicationRouteSlugs::resolve( $current['cybermaps_settings'] );
		foreach ( AIConfigurationRegistry::get_sections() as $section_id => $section ) {
			unset( $section );
			$values[ $section_id ] = array();
		}

		foreach ( $fields as $field_id => $field ) {
			$ai_value = $this->ai_current_field_value( (string) $field_id, $field, $current, $routes );
			$values[ (string) $field['section_id'] ][ $field_id ] = $this->redact_credential_urls( $ai_value );
		}

		return $values;
	}

	/** Resolve one current AI-brief field value. */
	private function ai_current_field_value( string $field_id, array $field, array $current, array $routes ) {
		$option_name = (string) $field['option'];
		$field_name  = (string) $field['field'];
		$has_stored  = isset( $current[ $option_name ] ) && is_array( $current[ $option_name ] )
			&& array_key_exists( $field_name, $current[ $option_name ] );
		$stored      = $has_stored ? $current[ $option_name ][ $field_name ] : $field['effective_default'];
		if ( 'static_engine_mode' === $field_id ) {
			$stored = \Cybermaps\Discovery\StaticBridge::get_mode( $current['cybermaps_settings'] );
		}
		if ( isset( $routes[ $field_id ] ) ) {
			$stored = $routes[ $field_id ];
		}
		if ( 'site_language' === $field_id ) {
			$stored = \Cybermaps\Core\TranslationHelper::normalize_hreflang( $stored );
			$stored = '' !== $stored ? $stored : $this->site_hreflang();
		}
		$stored = $this->ai_empty_storage_fallback( $field_id, $stored, $has_stored );
		return $this->ai_value_from_storage( $field_id, $stored, (string) $field['json_type'], $field['effective_default'] );
	}

	/** Apply site-derived defaults to an empty AI-brief storage value. */
	private function ai_empty_storage_fallback( string $field_id, $stored, bool $has_stored ) {
		$needs_fallback = ( ! $has_stored && 'site_language' === $field_id )
			|| ( is_scalar( $stored ) && '' === trim( (string) $stored ) );
		if ( ! $needs_fallback ) {
			return $stored;
		}
		$site_name   = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '';
		$description = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'description' ) : '';
		return match ( $field_id ) {
			'site_language' => $this->site_hreflang(),
			'news_publication_name', 'llms_title_override', 'site_name_override' => $site_name,
			'llms_mission_statement', 'ai_business_description' => $description,
			default => $stored,
		};
	}

	private function site_hreflang(): string {
		$language = function_exists( 'get_bloginfo' ) ? get_bloginfo( 'language' ) : '';
		$language = \Cybermaps\Core\TranslationHelper::normalize_hreflang( $language );
		return '' !== $language ? $language : 'en';
	}

	/**
	 * @param mixed $stored            Stored value.
	 * @param mixed $effective_default Registry default.
	 * @return mixed
	 */
	private function ai_value_from_storage( string $field_id, $stored, string $json_type, $effective_default ) {
		if ( 'boolean' === $json_type ) {
			return $this->ai_boolean_from_storage( $stored );
		}
		if ( 'integer' === $json_type ) {
			return $this->ai_integer_from_storage( $stored, $effective_default );
		}
		if ( 'number' === $json_type ) {
			return $this->ai_number_from_storage( $stored, $effective_default );
		}
		if ( 'array' === $json_type ) {
			return $this->ai_array_value_from_storage( $field_id, $stored, $effective_default );
		}
		if ( 'object' === $json_type ) {
			return $this->ai_object_from_storage( $stored );
		}

		return $this->ai_string_from_storage( $stored, $effective_default );    }
	/**
	 * Normalize a stored AI boolean.
	 *
	 * @param mixed $stored Stored value.
	 */
	private function ai_boolean_from_storage( $stored ): bool {
		if ( is_bool( $stored ) ) {
			return $stored;
		}

		return in_array( strtolower( trim( (string) $stored ) ), array( '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * Normalize a stored AI integer.
	 *
	 * @param mixed $stored Stored value.
	 * @param mixed $effective_default Effective default.
	 */
	private function ai_integer_from_storage( $stored, $effective_default ): int {
		return is_numeric( $stored ) ? (int) $stored : (int) $effective_default;
	}

	/**
	 * Normalize a stored AI number.
	 *
	 * @param mixed $stored Stored value.
	 * @param mixed $effective_default Effective default.
	 * @return mixed
	 */
	private function ai_number_from_storage( $stored, $effective_default ) {
		return is_numeric( $stored ) ? (float) $stored : $effective_default;
	}

	/**
	 * Normalize a stored AI object.
	 *
	 * @param mixed $stored Stored value.
	 * @return array<mixed>|\stdClass
	 */
	private function ai_object_from_storage( $stored ) {
		return is_array( $stored ) && ! empty( $stored ) ? $stored : new \stdClass();
	}

	/**
	 * Normalize a stored AI string.
	 *
	 * @param mixed $stored Stored value.
	 * @param mixed $effective_default Effective default.
	 */
	private function ai_string_from_storage( $stored, $effective_default ): string {
		if ( is_scalar( $stored ) ) {
			return (string) $stored;
		}

		return is_scalar( $effective_default ) ? (string) $effective_default : '';
	}

	/** Normalize one array-valued AI-brief field. */
	private function ai_array_value_from_storage( string $field_id, $stored, $effective_default ): array {
		if ( 'trusted_proxy_cidrs' === $field_id ) {
			return $this->ai_trusted_proxy_values( $stored );
		}
		if ( in_array( $field_id, array( 'exclude_categories', 'ai_sitemap_exclude_terms' ), true ) ) {
			return $this->ai_term_identifier_values( $stored );
		}
		if ( in_array( $field_id, array( 'exclude_post_ids', 'llms_pinned_ids', 'llms_exclude_ids' ), true ) ) {
			return \Cybermaps\Core\PositiveIdList::parse( $stored );
		}
		if ( is_array( $stored ) ) {
			return array_values( $stored );
		}
		if ( 'ai_topics' === $field_id ) {
			return \Cybermaps\Discovery\PublicationConstraints::topics( $stored );
		}
		return is_array( $effective_default ) ? array_values( $effective_default ) : array();
	}

	/** Normalize and bound trusted proxy CIDRs. */
	private function ai_trusted_proxy_values( $stored ): array {
		$items = is_array( $stored ) ? $stored : preg_split( '/[\r\n,]+/', is_scalar( $stored ) ? (string) $stored : '' );
		$cidrs = array();
		foreach ( is_array( $items ) ? $items : array() as $item ) {
			$cidr = is_scalar( $item ) ? trim( (string) $item ) : '';
			if ( '' === $cidr || in_array( $cidr, $cidrs, true ) ) {
				continue;
			}
			$cidrs[] = $cidr;
			if ( 64 === count( $cidrs ) ) {
				break;
			}
		}
		return $cidrs;
	}

	/** Normalize taxonomy term identifiers for AI output. */
	private function ai_term_identifier_values( $stored ): array {
		$items = is_array( $stored ) ? $stored : explode( ',', (string) $stored );
		return array_values(
			array_filter(
				array_map(
					static function ( $item ) {
						$item = is_scalar( $item ) ? trim( (string) $item ) : '';
						return ctype_digit( $item ) && (int) $item > 0 ? (int) $item : $item;
					},
					$items
				),
				static fn ( $item ): bool => '' !== $item
			)
		);
	}

	/**
	 * Remove URL userinfo from the portable AI artifact without mutating storage.
	 *
	 * Directly stored legacy values and filtered options can predate the current
	 * URL sanitizer. Walk strings and nested structures so a URL such as
	 * https://user:password@example.com can never export its credentials. Exact
	 * URL values are omitted; an embedded occurrence is replaced visibly.
	 *
	 * @param mixed $value AI-facing configuration or context value.
	 * @return mixed
	 */
	private function redact_credential_urls( $value ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = $this->redact_credential_urls( $item );
			}
			return $value;
		}
		if ( $value instanceof \stdClass ) {
			$properties = get_object_vars( $value );
			if ( empty( $properties ) ) {
				return $value;
			}
			return (object) $this->redact_credential_urls( $properties );
		}
		if ( ! is_string( $value ) || ! preg_match( '~https?://~i', $value ) ) {
			return $value;
		}

		$trimmed = trim( $value );
		if ( $this->url_contains_userinfo( $trimmed ) ) {
			return '';
		}

		$redacted = preg_replace_callback(
			'~https?://[^\s<>"\'`]+~iu',
			function ( array $url_match ): string {
				return $this->url_contains_userinfo( $url_match[0] )
					? '[credential-bearing URL omitted]'
					: $url_match[0];
			},
			$value
		);

		return is_string( $redacted ) ? $redacted : '';
	}

	private function url_contains_userinfo( string $url ): bool {
		$parts = wp_parse_url( $url );
		return is_array( $parts )
			&& isset( $parts['scheme'], $parts['host'] )
			&& in_array( strtolower( (string) $parts['scheme'] ), array( 'http', 'https' ), true )
			&& ( isset( $parts['user'] ) || isset( $parts['pass'] ) );
	}

	/**
	 * Build bounded, non-secret site facts that help an AI make site-aware
	 * recommendations without receiving posts, logs, reports, or credentials.
	 *
	 * @return array<string,mixed>
	 */
	private function brief_site_context( array $current_values ): array {
		$settings     = get_option( 'cybermaps_settings', array() );
		$settings     = is_array( $settings ) ? $settings : array();
		$routes       = \Cybermaps\Sitemap\PublicationRouteSlugs::resolve( $settings );
		$post_types   = array();
		$taxonomies   = array();
		$public_types = \Cybermaps\Core\PublicationPostTypes::objects();
		$post_types   = $this->brief_post_types( $public_types );

		$public_taxonomies = function_exists( 'get_taxonomies' )
			? (array) get_taxonomies( array( 'public' => true ), 'objects' )
			: array();
		$taxonomies        = $this->brief_taxonomies( $public_taxonomies );
		$crawlers          = $this->brief_crawlers();
		$theme             = $this->brief_theme();

		$public_identity     = isset( $current_values['site_identity'] ) && is_array( $current_values['site_identity'] )
			? $current_values['site_identity']
			: array();
		$has_public_identity = $this->brief_has_public_identity( $public_identity );

		return array(
			'brief'                      => array(
				'format_version'                      => self::AI_BRIEF_VERSION,
				'plugin_version'                      => defined( 'CYBERMAPS_VERSION' ) ? CYBERMAPS_VERSION : '',
				'generated_gmt'                       => gmdate( 'c' ),
				'contains_secrets'                    => false,
				'contains_configured_public_identity' => $has_public_identity,
			),
			'site'                       => $this->brief_site_details( $theme ),
			'content_inventory'          => array(
				'public_post_types' => $post_types,
				'public_taxonomies' => $taxonomies,
			),
			'supported_schema_types'     => \Cybermaps\Core\SchemaRegistry::get_types(),
			'crawler_policy_registry'    => $crawlers,
			'detected_integrations'      => $this->brief_integrations(),
			'publication_state'          => $this->brief_publication_state( $settings, $routes ),
			'credential_state'           => $this->brief_credential_state( $settings ),
			'configured_public_identity' => $public_identity,
		);
	}

	/** Build the bounded public post-type inventory. */
	private function brief_post_types( array $objects ): array {
		$result = array();
		foreach ( array_slice( $objects, 0, 100, true ) as $key => $object ) {
			$name  = is_object( $object ) && isset( $object->name ) ? (string) $object->name : (string) $object;
			$name  = '' !== $name ? $name : (string) $key;
			$label = is_object( $object ) && isset( $object->labels->name ) ? (string) $object->labels->name : $this->humanize_key( $name );
			$count = null;
			if ( function_exists( 'wp_count_posts' ) ) {
				$counts = wp_count_posts( $name );
				$count  = is_object( $counts ) && isset( $counts->publish ) ? (int) $counts->publish : null;
			}
			$result[] = array(
				'id'              => 'post_type:' . $name,
				'slug'            => $name,
				'label'           => $label,
				'published_count' => $count,
				'show_in_rest'    => is_object( $object ) ? ! empty( $object->show_in_rest ) : null,
			);
		}
		return $result;
	}

	/** Build the bounded public taxonomy inventory. */
	private function brief_taxonomies( array $objects ): array {
		$result = array();
		foreach ( array_slice( $objects, 0, 100, true ) as $key => $object ) {
			$name  = is_object( $object ) && isset( $object->name ) ? (string) $object->name : (string) $object;
			$name  = '' !== $name ? $name : (string) $key;
			$label = is_object( $object ) && isset( $object->labels->name ) ? (string) $object->labels->name : $this->humanize_key( $name );
			$count = null;
			if ( function_exists( 'wp_count_terms' ) ) {
				$count_result = wp_count_terms(
					array(
						'taxonomy'   => $name,
						'hide_empty' => false,
					)
				);
				$count        = is_numeric( $count_result ) ? (int) $count_result : null;
			}
			$result[] = array(
				'id'         => 'taxonomy:' . $name,
				'slug'       => $name,
				'label'      => $label,
				'term_count' => $count,
			);
		}
		return $result;
	}

	/** Build the crawler-policy registry context. */
	private function brief_crawlers(): array {
		$result = array();
		foreach ( CrawlerRegistry::get_policy_bots() as $crawler_id => $crawler ) {
			$result[] = array(
				'id'                  => $crawler_id,
				'name'                => $crawler->name,
				'provider'            => $crawler->company,
				'category'            => $crawler->category->value,
				'default_robots'      => ! empty( $crawler->default['robots'] ),
				'default_llms'        => ! empty( $crawler->default['llm'] ),
				'recognizes_requests' => $crawler->recognizes_requests,
			);
		}
		return $result;
	}

	/** Read public theme metadata for the brief context. */
	private function brief_theme(): ?array {
		if ( ! function_exists( 'wp_get_theme' ) ) {
			return null; }
		$theme = wp_get_theme();
		if ( ! is_object( $theme ) || ! method_exists( $theme, 'get' ) ) {
			return null; }
		return array(
			'name'     => (string) $theme->get( 'Name' ),
			'version'  => (string) $theme->get( 'Version' ),
			'template' => method_exists( $theme, 'get_template' ) ? (string) $theme->get_template() : '',
		);
	}

	/** Determine whether the brief includes configured public identity data. */
	private function brief_has_public_identity( array $identity ): bool {
		unset( $identity['identity_type'] );
		foreach ( $identity as $value ) {
			if ( is_string( $value ) && '' !== trim( $value ) ) {
				return true; }
			if ( is_int( $value ) && $value > 0 ) {
				return true; }
			if ( is_array( $value ) && ! empty( $value ) ) {
				return true; }
		}
		return false;
	}

	/** Build public site metadata for the brief context. */
	private function brief_site_details( ?array $theme ): array {
		return array(
			'url'          => home_url( '/' ),
			'name'         => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '',
			'description'  => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'description' ) : '',
			'language'     => function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'language' ) : '',
			'locale'       => function_exists( 'get_locale' ) ? (string) get_locale() : '',
			'timezone'     => (string) get_option( 'timezone_string', '' ),
			'is_multisite' => function_exists( 'is_multisite' ) && is_multisite(),
			'theme'        => $theme,
		);
	}

	/** Detect supported third-party integrations. */
	private function brief_integrations(): array {
		return array(
			'yoast_seo' => defined( 'WPSEO_VERSION' ) || function_exists( 'YoastSEO' ),
			'rank_math' => defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ),
			'aioseo'    => defined( 'AIOSEO_VERSION' ) || function_exists( 'aioseo' ),
			'wpml'      => defined( 'ICL_SITEPRESS_VERSION' ),
			'polylang'  => function_exists( 'pll_languages_list' ) || function_exists( 'pll_get_post_translations' ),
		);
	}

	/** Build current publication-state signals. */
	private function brief_publication_state( array $settings, array $routes ): array {
		return array(
			'discovery_hub_enabled' => ! empty( $settings['enable_discovery_hub'] ),
			'xml_sitemap_base'      => $routes['sitemap_url_base'],
			'rss_sitemap_enabled'   => ! empty( $settings['enable_rss_sitemap'] ),
			'html_sitemap_enabled'  => ! empty( $settings['enable_shortcode'] ),
			'static_engine_mode'    => \Cybermaps\Discovery\StaticBridge::get_mode( $settings ),
			'analytics_enabled'     => ! empty( $settings['enable_analytics'] ),
		);
	}

	/** Build secret-presence signals without exporting credentials. */
	private function brief_credential_state( array $settings ): array {
		return array(
			'api_secret_configured'   => ! empty( $settings['api_secret'] ),
			'indexnow_key_configured' => '' !== (string) get_option( 'cybermaps_indexnow_key', '' ),
		);
	}

	/**
	 * Prepare a strict, merge-only v2 changes envelope.
	 *
	 * @param array<string,mixed> $payload    Decoded envelope.
	 * @param array<string,mixed> $base_state Stable destination snapshot used to build the plan.
	 * @return array<string,mixed>
	 */
	private function prepare_ai_changes( array $payload, array $base_state ): array {
		$envelope         = $this->validate_ai_change_envelope( $payload );
		$errors           = $envelope['errors'];
		$warnings         = $envelope['warnings'];
		$changes          = $envelope['changes'];
		$current          = isset( $base_state['configuration'] ) && is_array( $base_state['configuration'] )
			? $base_state['configuration']
			: array();
		$collected        = $this->collect_ai_change_candidates( $changes, $current, $errors );
		$candidates       = $collected['candidates'];
		$touched_fields   = $collected['touched_fields'];
		$preview_fields   = $collected['preview_fields'];
		$proposed_values  = $collected['proposed_values'];
		$recognized_count = $collected['recognized_count'];

		$this->expand_ai_route_fields( $candidates, $touched_fields, $preview_fields, $proposed_values );
		$this->validate_ai_identity_rules( $candidates, $preview_fields, $errors );
		$this->validate_ai_rag_rules( $candidates, $preview_fields, $errors );

		$targets = $this->prepare_ai_targets( $candidates, $current, $touched_fields, $base_state );

		return array(
			'format'          => 'brief',
			'mode'            => 'merge',
			'imported_count'  => $recognized_count,
			'targets'         => $targets,
			'proposed_values' => $proposed_values,
			'touched_fields'  => array_map( 'array_keys', $preview_fields ),
			'post_unchanged'  => array(),
			'warnings'        => $warnings,
			'errors'          => $errors,
		);
	}

	/**
	 * Include every route in the collision-safe review set when one route changes.
	 *
	 * @param array<string,mixed> $candidates Candidate storage values.
	 * @param array<string,mixed> $touched_fields Touched field map.
	 * @param array<string,mixed> $preview_fields Preview field map.
	 * @param array<string,mixed> $proposed_values Proposed field map.
	 */
	private function expand_ai_route_fields( array $candidates, array &$touched_fields, array &$preview_fields, array &$proposed_values ): void {
		$route_fields = array( 'sitemap_url_base', 'news_sitemap_url_base', 'rss_sitemap_url_base' );
		if ( ! isset( $touched_fields['cybermaps_settings'] ) || empty( array_intersect( $route_fields, array_keys( $touched_fields['cybermaps_settings'] ) ) ) ) {
			return;
		}
		foreach ( $route_fields as $route_field ) {
			$touched_fields['cybermaps_settings'][ $route_field ]  = true;
			$preview_fields['cybermaps_settings'][ $route_field ]  = true;
			$proposed_values['cybermaps_settings'][ $route_field ] = $candidates['cybermaps_settings'][ $route_field ] ?? '';
		}
	}

	/**
	 * Validate identity type compatibility and paired geographic coordinates.
	 *
	 * @param array<string,mixed> $candidates Candidate storage values.
	 * @param array<string,mixed> $preview_fields Preview field map.
	 * @param string[]            $errors Validation errors.
	 */
	private function validate_ai_identity_rules( array $candidates, array $preview_fields, array &$errors ): void {
		$identity_fields = $preview_fields['cybermaps_identity_data'] ?? array();
		if ( isset( $identity_fields['type'] ) || isset( $identity_fields['precise_type'] ) ) {
			$identity_type = (string) ( $candidates['cybermaps_identity_data']['type'] ?? 'Organization' );
			$precise_type  = (string) ( $candidates['cybermaps_identity_data']['precise_type'] ?? '' );
			if ( '' !== $precise_type && \Cybermaps\Core\SchemaRegistry::get_entity_type(
				array(
					'type'         => $identity_type,
					'precise_type' => $precise_type,
				)
			) !== $precise_type ) {
				$errors[] = __( 'The precise identity type is not compatible with the selected primary identity type.', 'cybermaps' );
			}
		}
		if ( ! isset( $identity_fields['latitude'] ) && ! isset( $identity_fields['longitude'] ) ) {
			return;
		}
		$latitude  = trim( (string) ( $candidates['cybermaps_identity_data']['latitude'] ?? '' ) );
		$longitude = trim( (string) ( $candidates['cybermaps_identity_data']['longitude'] ?? '' ) );
		if ( ( '' === $latitude ) !== ( '' === $longitude ) ) {
			$errors[] = __( 'Identity latitude and longitude must either both be configured or both be empty.', 'cybermaps' );
		}
	}

	/**
	 * Validate the paired RAG chunk-size and overlap relationship.
	 *
	 * @param array<string,mixed> $candidates Candidate storage values.
	 * @param array<string,mixed> $preview_fields Preview field map.
	 * @param string[]            $errors Validation errors.
	 */
	private function validate_ai_rag_rules( array $candidates, array $preview_fields, array &$errors ): void {
		$settings_fields = $preview_fields['cybermaps_settings'] ?? array();
		if ( ! isset( $settings_fields['rag_chunk_size'] ) && ! isset( $settings_fields['rag_chunk_overlap'] ) ) {
			return;
		}
		$settings = $candidates['cybermaps_settings'] ?? array();
		$chunk    = isset( $settings['rag_chunk_size'] ) ? (int) $settings['rag_chunk_size'] : null;
		$overlap  = isset( $settings['rag_chunk_overlap'] ) ? (int) $settings['rag_chunk_overlap'] : null;
		if ( null !== $chunk && null !== $overlap && $overlap > intdiv( max( 0, $chunk ), 2 ) ) {
			$errors[] = __( 'RAG chunk overlap cannot exceed half of the selected chunk size.', 'cybermaps' );
		}
	}

	/**
	 * Sanitize only the option groups touched by the AI changes envelope.
	 *
	 * @param array<string,mixed> $candidates Candidate storage values.
	 * @param array<string,mixed> $current Current normalized configuration.
	 * @param array<string,mixed> $touched_fields Touched field map.
	 * @param array<string,mixed> $base_state Raw destination snapshot.
	 * @return array<string,mixed> Prepared targets.
	 */
	private function prepare_ai_targets( array $candidates, array $current, array $touched_fields, array $base_state ): array {
		$targets = array();
		if ( isset( $touched_fields['cybermaps_settings'] ) ) {
			$sanitized                     = SettingsSanitizer::sanitize_import( $candidates['cybermaps_settings'], $current['cybermaps_settings'] );
			$targets['cybermaps_settings'] = $this->merge_touched_fields( $current['cybermaps_settings'], $sanitized, array_keys( $touched_fields['cybermaps_settings'] ) );
		}
		if ( isset( $touched_fields['cybermaps_discovery_center'] ) ) {
			$sanitized                             = DiscoveryCenterSanitizer::sanitize( $this->encode_json( $candidates['cybermaps_discovery_center'] ) );
			$decoded                               = json_decode( $sanitized, true );
			$decoded                               = is_array( $decoded ) ? $decoded : array();
			$merged                                = $this->merge_touched_fields( $current['cybermaps_discovery_center'], $decoded, array_keys( $touched_fields['cybermaps_discovery_center'] ) );
			$raw_state                             = $base_state['raw_options']['cybermaps_discovery_center'] ?? array();
			$raw_value                             = ! empty( $raw_state['exists'] ) ? ( $raw_state['value'] ?? '' ) : '';
			$targets['cybermaps_discovery_center'] = $merged == $current['cybermaps_discovery_center'] && is_string( $raw_value ) // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
				? $raw_value
				: $this->encode_json( $merged );
		}
		if ( isset( $touched_fields['cybermaps_robots_manager'] ) ) {
			$sanitized                           = RobotsManagerSanitizer::sanitize( $candidates['cybermaps_robots_manager'] );
			$targets['cybermaps_robots_manager'] = $this->merge_touched_fields( $current['cybermaps_robots_manager'], $sanitized, array_keys( $touched_fields['cybermaps_robots_manager'] ) );
		}
		if ( isset( $touched_fields['cybermaps_identity_data'] ) ) {
			$sanitized                          = ( new IdentityHub() )->sanitize_identity_data( $candidates['cybermaps_identity_data'] );
			$targets['cybermaps_identity_data'] = $this->merge_touched_fields( $current['cybermaps_identity_data'], $sanitized, array_keys( $touched_fields['cybermaps_identity_data'] ) );
		}
		return $targets;
	}

	/**
	 * Validate the fixed metadata and shape of an AI changes envelope.
	 *
	 * @param array<string,mixed> $payload Decoded changes envelope.
	 * @return array{errors:string[],warnings:string[],changes:array<string,mixed>}
	 */
	private function validate_ai_change_envelope( array $payload ): array {
		$metadata = $this->validate_ai_envelope_metadata( $payload );
		$changes  = $payload['changes'] ?? null;
		if ( ! is_array( $changes ) || ( ! empty( $changes ) && array_is_list( $changes ) ) ) {
			$metadata['errors'][] = __( 'The AI Configuration Brief changes property must be an object.', 'cybermaps' );
			$changes              = array();
		}

		return array(
			'errors'   => $metadata['errors'],
			'warnings' => $metadata['warnings'],
			'changes'  => $changes,
		);
	}

	/**
	 * @param array<string,mixed> $payload Decoded changes envelope.
	 * @return array{errors:string[],warnings:string[]}
	 */
	private function validate_ai_envelope_metadata( array $payload ): array {
		$errors   = array();
		$warnings = array();
		foreach ( array_diff( array_keys( $payload ), array( 'format', 'format_version', 'plugin_version', 'changes' ) ) as $unknown_key ) {
			if ( count( $errors ) >= self::MAX_VALIDATION_ERRORS ) {
				break;
			}
			$errors[] = sprintf(
				/* translators: %s: unknown JSON property. */
				__( 'Unknown AI configuration envelope property: %s.', 'cybermaps' ),
				(string) $unknown_key
			);
		}
		if ( self::AI_BRIEF_FORMAT !== ( $payload['format'] ?? null ) ) {
			$errors[] = __( 'This is not a Cybermaps AI Configuration Brief changes envelope.', 'cybermaps' );
		}
		if ( ! isset( $payload['format_version'] ) || ! is_int( $payload['format_version'] ) || self::AI_BRIEF_VERSION !== $payload['format_version'] ) {
			$errors[] = __( 'This AI Configuration Brief format version is not supported.', 'cybermaps' );
		}
		if ( ! isset( $payload['plugin_version'] ) || ! is_string( $payload['plugin_version'] ) || '' === trim( $payload['plugin_version'] ) ) {
			$errors[] = __( 'The AI Configuration Brief plugin version is missing or invalid.', 'cybermaps' );
		} elseif ( defined( 'CYBERMAPS_VERSION' ) && CYBERMAPS_VERSION !== $payload['plugin_version'] ) {
			$warnings[] = sprintf(
				/* translators: 1: brief plugin version, 2: installed plugin version. */
				__( 'This brief targets Cybermaps %1$s; the destination is running %2$s. Review every normalized value carefully.', 'cybermaps' ),
				$payload['plugin_version'],
				CYBERMAPS_VERSION
			);
		}

		return array(
			'errors'   => $errors,
			'warnings' => $warnings,
		);
	}

	/**
	 * Collect validated field changes and their storage mappings.
	 *
	 * @param array<string,mixed> $changes Changes grouped by section.
	 * @param array<string,mixed> $current Current normalized configuration.
	 * @param string[]            $errors Validation errors.
	 * @return array<string,mixed>
	 */
	private function collect_ai_change_candidates( array $changes, array $current, array &$errors ): array {
		$candidates       = $current;
		$touched_fields   = array();
		$preview_fields   = array();
		$proposed_values  = array();
		$recognized_count = 0;
		$sections         = AIConfigurationRegistry::get_sections();
		$fields           = AIConfigurationRegistry::get_fields();

		foreach ( $changes as $section_id => $section_changes ) {
			if ( count( $errors ) >= self::MAX_VALIDATION_ERRORS ) {
				break;
			}
			$section_id = (string) $section_id;
			if ( ! isset( $sections[ $section_id ] ) ) {
				$errors[] = sprintf(
					/* translators: %s: unknown section ID. */
					__( 'Unknown AI configuration section: %s.', 'cybermaps' ),
					$section_id
				);
				continue;
			}
			if ( ! is_array( $section_changes ) || ( ! empty( $section_changes ) && array_is_list( $section_changes ) ) ) {
				$errors[] = sprintf(
					/* translators: %s: configuration section ID. */
					__( 'AI configuration section %s must be an object.', 'cybermaps' ),
					$section_id
				);
				continue;
			}
			$this->collect_ai_section_fields(
				$section_id,
				$section_changes,
				$fields,
				$candidates,
				$touched_fields,
				$preview_fields,
				$proposed_values,
				$recognized_count,
				$errors
			);
		}

		return array(
			'candidates'       => $candidates,
			'touched_fields'   => $touched_fields,
			'preview_fields'   => $preview_fields,
			'proposed_values'  => $proposed_values,
			'recognized_count' => $recognized_count,
		);
	}

	/**
	 * @param array<string,mixed> $section_changes Section field values.
	 * @param array<string,mixed> $field_registry Registry definitions.
	 * @param array<string,mixed> $candidates Candidate storage values.
	 * @param array<string,mixed> $touched_fields Touched field map.
	 * @param array<string,mixed> $preview_fields Preview field map.
	 * @param array<string,mixed> $proposed_values Proposed field map.
	 * @param int                 $recognized_count Number of accepted fields.
	 * @param string[]            $errors Validation errors.
	 */
	private function collect_ai_section_fields(
		string $section_id,
		array $section_changes,
		array $field_registry,
		array &$candidates,
		array &$touched_fields,
		array &$preview_fields,
		array &$proposed_values,
		int &$recognized_count,
		array &$errors
	): void {
		foreach ( $section_changes as $field_id => $value ) {
			if ( count( $errors ) >= self::MAX_VALIDATION_ERRORS ) {
				break;
			}
			if ( $this->collect_ai_field_candidate( $section_id, (string) $field_id, $value, $field_registry, $candidates, $touched_fields, $preview_fields, $proposed_values, $errors ) ) {
				++$recognized_count;
			}
		}
	}

	/**
	 * @param mixed               $value Candidate field value.
	 * @param array<string,mixed> $field_registry Registry definitions.
	 * @param array<string,mixed> $candidates Candidate storage values.
	 * @param array<string,mixed> $touched_fields Touched field map.
	 * @param array<string,mixed> $preview_fields Preview field map.
	 * @param array<string,mixed> $proposed_values Proposed field map.
	 * @param string[]            $errors Validation errors.
	 */
	private function collect_ai_field_candidate(
		string $section_id,
		string $field_id,
		$value,
		array $field_registry,
		array &$candidates,
		array &$touched_fields,
		array &$preview_fields,
		array &$proposed_values,
		array &$errors
	): bool {
		$definition = $field_registry[ $field_id ] ?? null;
		if ( ! is_array( $definition ) || $section_id !== $definition['section_id'] ) {
			$errors[] = sprintf(
				/* translators: 1: unknown or misplaced field ID, 2: section ID. */
				__( 'Unknown or misplaced AI configuration field %1$s in section %2$s.', 'cybermaps' ),
				$field_id,
				$section_id
			);
			return false;
		}
		if ( null === $value ) {
			return false;
		}

		$field_errors      = array();
		$effective_default = $definition['effective_default'] ?? null;
		$this->validate_ai_value(
			$value,
			array_merge( array( 'type' => $definition['json_type'] ), (array) $definition['allowed'] ),
			(string) $definition['label'],
			$field_errors,
			'' === $effective_default
		);
		if ( in_array( $field_id, array( 'identity_latitude', 'identity_longitude' ), true ) && '' === $value ) {
			$field_errors = array();
		}
		if ( ! empty( $field_errors ) ) {
			$remaining = self::MAX_VALIDATION_ERRORS - count( $errors );
			if ( $remaining > 0 ) {
				array_push( $errors, ...array_slice( $field_errors, 0, $remaining ) );
			}
			return false;
		}

		$option_name = (string) $definition['option'];
		$field_name  = (string) $definition['field'];
		if ( ! isset( $candidates[ $option_name ] ) || ! is_array( $candidates[ $option_name ] ) ) {
			$candidates[ $option_name ] = array();
		}
		$candidates[ $option_name ][ $field_name ]      = $value;
		$touched_fields[ $option_name ][ $field_name ]  = true;
		$preview_fields[ $option_name ][ $field_name ]  = true;
		$proposed_values[ $option_name ][ $field_name ] = $value;
		return true;
	}

	/**
	 * Recursively validate one AI-authored value against the registry schema.
	 *
	 * @param mixed               $value       Candidate value.
	 * @param array<string,mixed> $schema      JSON-Schema-like constraints.
	 * @param string              $path        Human-readable field path.
	 * @param string[]            $errors      Validation errors.
	 */
	private function validate_ai_value( $value, array $schema, string $path, array &$errors, bool $allow_empty_string = false ): void {
		if ( count( $errors ) >= self::MAX_VALIDATION_ERRORS ) {
			return;
		}

		$types = $this->ai_value_types( $schema );
		if ( $allow_empty_string && in_array( 'string', $types, true ) && '' === $value ) {
			return;
		}
		$this->validate_ai_composition( $value, $schema, $path, $errors );
		if ( ! $this->ai_value_has_type( $value, $types ) ) {
			$errors[] = sprintf(
				/* translators: 1: field path, 2: required JSON type. */
				__( '%1$s must be a JSON %2$s.', 'cybermaps' ),
				$path,
				implode( ' or ', $types )
			);
			return;
		}

		$this->validate_ai_scalar_constraints( $value, $schema, $path, $errors );
		$this->validate_ai_string_constraints( $value, $schema, $path, $errors );
		$this->validate_ai_array_constraints( $value, $schema, $types, $path, $errors );
		$this->validate_ai_object_constraints( $value, $schema, $types, $path, $errors );
	}

	/**
	 * @param array<string,mixed> $schema Validation schema.
	 * @return string[]
	 */
	private function ai_value_types( array $schema ): array {
		$type_spec = $schema['type'] ?? '';
		$types     = is_array( $type_spec ) ? array_values( array_map( 'strval', $type_spec ) ) : array( (string) $type_spec );
		return array_values( array_filter( $types, static fn ( string $type_name ): bool => '' !== $type_name ) );
	}

	/**
	 * @param mixed               $value  Candidate value.
	 * @param array<string,mixed> $schema Validation schema.
	 * @param string              $path   Human-readable field path.
	 * @param string[]            $errors Validation errors.
	 */
	private function validate_ai_composition( $value, array $schema, string $path, array &$errors ): void {
		if ( array_key_exists( 'const', $schema ) && $value !== $schema['const'] ) {
			$errors[] = sprintf(
				/* translators: %s: human-readable configuration field path. */
				__( '%s must use the required fixed value.', 'cybermaps' ),
				$path
			);
		}
		if ( isset( $schema['allOf'] ) && is_array( $schema['allOf'] ) ) {
			foreach ( $schema['allOf'] as $branch ) {
				if ( is_array( $branch ) ) {
					$this->validate_ai_value( $value, $branch, $path, $errors );
				}
			}
		}
		foreach ( array( 'anyOf', 'oneOf' ) as $combinator ) {
			$this->validate_ai_combinator( $value, $schema, $path, $errors, $combinator );
		}
	}

	/**
	 * @param mixed               $value       Candidate value.
	 * @param array<string,mixed> $schema      Validation schema.
	 * @param string              $path        Human-readable field path.
	 * @param string[]            $errors      Validation errors.
	 * @param string              $combinator  anyOf or oneOf.
	 */
	private function validate_ai_combinator( $value, array $schema, string $path, array &$errors, string $combinator ): void {
		if ( ! isset( $schema[ $combinator ] ) || ! is_array( $schema[ $combinator ] ) ) {
			return;
		}
		$valid_branches = 0;
		$branch_errors  = array();
		foreach ( $schema[ $combinator ] as $branch ) {
			if ( ! is_array( $branch ) ) {
				continue;
			}
			$candidate_errors = array();
			$this->validate_ai_value( $value, $branch, $path, $candidate_errors );
			if ( empty( $candidate_errors ) ) {
				++$valid_branches;
			} elseif ( empty( $branch_errors ) || count( $candidate_errors ) < count( $branch_errors ) ) {
				$branch_errors = $candidate_errors;
			}
		}
		$valid = 'oneOf' === $combinator ? 1 === $valid_branches : $valid_branches > 0;
		if ( ! $valid ) {
			$errors[] = sprintf(
				/* translators: %s: human-readable configuration field path. */
				__( '%s does not match an accepted value shape.', 'cybermaps' ),
				$path
			);
			if ( ! empty( $branch_errors[0] ) ) {
				$errors[] = $branch_errors[0];
			}
		}
	}

	/**
	 * @param mixed   $value Candidate value.
	 * @param string[] $types Accepted JSON types.
	 */
	private function ai_value_has_type( $value, array $types ): bool {
		if ( empty( $types ) ) {
			return true;
		}
		foreach ( $types as $type ) {
			if ( $this->ai_value_matches_type( $value, (string) $type ) ) {
				return true;
			}
		}
		return false;
	}

	private function ai_value_matches_type( $value, string $type ): bool {
		switch ( $type ) {
			case 'boolean':
				return is_bool( $value );
			case 'integer':
				return is_int( $value );
			case 'number':
				return $this->ai_value_is_finite_number( $value );
			case 'string':
				return is_string( $value );
			case 'array':
				return $this->ai_value_is_list( $value );
			case 'object':
				return $this->ai_value_is_object( $value );
			case 'null':
				return null === $value;
			default:
				return false;
		}
	}

	private function ai_value_is_finite_number( $value ): bool {
		return ( is_int( $value ) || is_float( $value ) ) && is_finite( (float) $value );
	}

	private function ai_value_is_list( $value ): bool {
		return is_array( $value ) && array_is_list( $value );
	}

	private function ai_value_is_object( $value ): bool {
		return is_array( $value ) && ( empty( $value ) || ! array_is_list( $value ) );
	}

	/**
	 * @param mixed               $value  Candidate value.
	 * @param array<string,mixed> $schema Validation schema.
	 * @param string              $path   Human-readable field path.
	 * @param string[]            $errors Validation errors.
	 */
	private function validate_ai_scalar_constraints( $value, array $schema, string $path, array &$errors ): void {
		if ( isset( $schema['enum'] ) && is_array( $schema['enum'] ) && ! in_array( $value, $schema['enum'], true ) ) {
			/* translators: %s: validation field path. */
			$errors[] = sprintf( __( '%s contains a value outside its allowed choices.', 'cybermaps' ), $path );
		}
		$this->validate_ai_bound( $value, $schema, $path, $errors, 'minimum', '<', 'must be at least' );
		$this->validate_ai_bound( $value, $schema, $path, $errors, 'maximum', '>', 'must not exceed' );
	}

	/**
	 * @param mixed               $value       Candidate value.
	 * @param array<string,mixed> $schema      Validation schema.
	 * @param string              $path        Human-readable field path.
	 * @param string[]            $errors      Validation errors.
	 * @param string              $key         Schema bound key.
	 * @param string              $operator    Comparison operator.
	 * @param string              $description Error wording.
	 */
	private function validate_ai_bound( $value, array $schema, string $path, array &$errors, string $key, string $operator, string $description ): void {
		if ( ! ( is_int( $value ) || is_float( $value ) ) || ! isset( $schema[ $key ] ) ) {
			return;
		}
		$violates = '<' === $operator ? $value < $schema[ $key ] : $value > $schema[ $key ];
		if ( $violates ) {
			$errors[] = sprintf(
				/* translators: 1: field path, 2: comparison description, 3: accepted bound. */
				__( '%1$s %2$s %3$s.', 'cybermaps' ),
				$path,
				$description,
				(string) $schema[ $key ]
			);
		}
	}

	/**
	 * @param mixed               $value  Candidate value.
	 * @param array<string,mixed> $schema Validation schema.
	 * @param string              $path   Human-readable field path.
	 * @param string[]            $errors Validation errors.
	 */
	private function validate_ai_string_constraints( $value, array $schema, string $path, array &$errors ): void {
		if ( ! is_string( $value ) ) {
			return;
		}
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
		$this->validate_ai_length( $length, $schema, $path, $errors, 'minLength', '<', 'is shorter than the accepted minimum' );
		$this->validate_ai_length( $length, $schema, $path, $errors, 'maxLength', '>', 'exceeds the accepted length limit' );
		if ( isset( $schema['pattern'] ) && is_string( $schema['pattern'] ) && 1 !== preg_match( '~' . str_replace( '~', '\\~', $schema['pattern'] ) . '~u', $value ) ) {
			/* translators: %s: validation field path. */
			$errors[] = sprintf( __( '%s does not match the accepted format.', 'cybermaps' ), $path );
		}
		if ( 'email' === ( $schema['format'] ?? '' ) && false === filter_var( $value, FILTER_VALIDATE_EMAIL ) ) {
			/* translators: %s: validation field path. */
			$errors[] = sprintf( __( '%s must be a valid email address.', 'cybermaps' ), $path );
		}
		if ( 'uri' === ( $schema['format'] ?? '' ) ) {
			$this->validate_ai_uri( $value, $schema, $path, $errors );
		}
		$this->validate_ai_numeric_extensions( $value, $schema, $path, $errors );
		$this->validate_ai_lines( $value, $schema, $path, $errors );
	}

	/**
	 * @param int                 $length      String length.
	 * @param array<string,mixed> $schema      Validation schema.
	 * @param string              $path        Human-readable field path.
	 * @param string[]            $errors      Validation errors.
	 * @param string              $key         Length constraint key.
	 * @param string              $operator    Comparison operator.
	 * @param string              $description Error wording.
	 */
	private function validate_ai_length( int $length, array $schema, string $path, array &$errors, string $key, string $operator, string $description ): void {
		if ( ! isset( $schema[ $key ] ) ) {
			return;
		}
		$violates = '<' === $operator ? $length < (int) $schema[ $key ] : $length > (int) $schema[ $key ];
		if ( $violates ) {
			/* translators: 1: field path, 2: validation description. */
			$errors[] = sprintf( __( '%1$s %2$s.', 'cybermaps' ), $path, $description );
		}
	}

	/**
	 * @param string              $value  Candidate URL.
	 * @param array<string,mixed> $schema Validation schema.
	 * @param string              $path   Human-readable field path.
	 * @param string[]            $errors Validation errors.
	 */
	private function validate_ai_uri( string $value, array $schema, string $path, array &$errors ): void {
		$url_parts = wp_parse_url( $value );
		$url_valid = false !== filter_var( $value, FILTER_VALIDATE_URL )
			&& is_array( $url_parts )
			&& isset( $url_parts['scheme'], $url_parts['host'] )
			&& in_array( strtolower( (string) $url_parts['scheme'] ), array( 'http', 'https' ), true )
			&& ! isset( $url_parts['user'] )
			&& ! isset( $url_parts['pass'] );
		if ( ! $url_valid ) {
			/* translators: %s: validation field path. */
			$errors[] = sprintf( __( '%s must be a public HTTP or HTTPS URL without embedded credentials.', 'cybermaps' ), $path );
		}
		if ( $url_valid && ! empty( $schema['x-public-url'] ) && function_exists( 'wp_http_validate_url' ) && false === wp_http_validate_url( $value ) ) {
			/* translators: %s: validation field path. */
			$errors[] = sprintf( __( '%s must use a publicly routable HTTP or HTTPS host.', 'cybermaps' ), $path );
		}
		if ( is_array( $url_parts ) && false === ( $schema['x-query-allowed'] ?? true ) && isset( $url_parts['query'] ) ) {
			/* translators: %s: validation field path. */
			$errors[] = sprintf( __( '%s must not contain a query string.', 'cybermaps' ), $path );
		}
		if ( is_array( $url_parts ) && false === ( $schema['x-fragment-allowed'] ?? true ) && isset( $url_parts['fragment'] ) ) {
			/* translators: %s: validation field path. */
			$errors[] = sprintf( __( '%s must not contain a URL fragment.', 'cybermaps' ), $path );
		}
	}

	/**
	 * @param mixed               $value  Candidate value.
	 * @param array<string,mixed> $schema Validation schema.
	 * @param string              $path   Human-readable field path.
	 * @param string[]            $errors Validation errors.
	 */
	private function validate_ai_numeric_extensions( $value, array $schema, string $path, array &$errors ): void {
		if ( ! is_numeric( $value ) ) {
			return;
		}
		$this->validate_ai_bound( (float) $value, $schema, $path, $errors, 'x-numeric-minimum', '<', 'must be at least' );
		$this->validate_ai_bound( (float) $value, $schema, $path, $errors, 'x-numeric-maximum', '>', 'must not exceed' );
	}

	/**
	 * @param string              $value  Candidate multiline value.
	 * @param array<string,mixed> $schema Validation schema.
	 * @param string              $path   Human-readable field path.
	 * @param string[]            $errors Validation errors.
	 */
	private function validate_ai_lines( string $value, array $schema, string $path, array &$errors ): void {
		if ( ! isset( $schema['x-line-schema'] ) || ! is_array( $schema['x-line-schema'] ) ) {
			return;
		}
		$lines = array_values( array_filter( array_map( 'trim', (array) preg_split( '/\R/', $value ) ), static fn ( string $line ): bool => '' !== $line ) );
		if ( isset( $schema['x-max-lines'] ) && count( $lines ) > (int) $schema['x-max-lines'] ) {
			/* translators: %s: validation field path. */
			$errors[] = sprintf( __( '%s contains too many lines.', 'cybermaps' ), $path );
			return;
		}
		foreach ( $lines as $index => $line ) {
			if ( count( $errors ) >= self::MAX_VALIDATION_ERRORS ) {
				break;
			}
			$this->validate_ai_value( $line, $schema['x-line-schema'], $path . '[' . ( $index + 1 ) . ']', $errors );
		}
	}

	/**
	 * @param mixed               $value  Candidate value.
	 * @param array<string,mixed> $schema Validation schema.
	 * @param string[]            $types  Accepted JSON types.
	 * @param string              $path   Human-readable field path.
	 * @param string[]            $errors Validation errors.
	 */
	private function validate_ai_array_constraints( $value, array $schema, array $types, string $path, array &$errors ): void {
		$keywords        = array( 'items', 'minItems', 'maxItems', 'uniqueItems', 'x-max-total-items', 'x-max-joined-bytes' );
		$has_constraints = in_array( 'array', $types, true ) || ! empty( array_intersect( $keywords, array_keys( $schema ) ) );
		if ( ! is_array( $value ) || ! array_is_list( $value ) || ! $has_constraints ) {
			return;
		}
		if ( isset( $schema['minItems'] ) && count( $value ) < (int) $schema['minItems'] ) {
			/* translators: %s: validation field path. */
			$errors[] = sprintf( __( '%s contains too few items.', 'cybermaps' ), $path );
		}
		if ( isset( $schema['maxItems'] ) && count( $value ) > (int) $schema['maxItems'] ) {
			/* translators: %s: validation field path. */
			$errors[] = sprintf( __( '%s contains too many items.', 'cybermaps' ), $path );
			return;
		}
		$this->validate_ai_array_uniqueness( $value, $schema, $path, $errors );
		$this->validate_ai_array_items( $value, $schema, $path, $errors );
		$this->validate_ai_array_aggregates( $value, $schema, $path, $errors );
	}

	/**
	 * @param array<int,mixed>    $value  List value.
	 * @param array<string,mixed> $schema Validation schema.
	 * @param string              $path   Human-readable field path.
	 * @param string[]            $errors Validation errors.
	 */
	private function validate_ai_array_uniqueness( array $value, array $schema, string $path, array &$errors ): void {
		if ( ! empty( $schema['uniqueItems'] ) ) {
			$encoded_items = array_map( fn ( $item ): string => $this->encode_json( $item ), $value );
			if ( count( $encoded_items ) !== count( array_unique( $encoded_items ) ) ) {                    /* translators: %s: validation field path. */
					$errors[] = sprintf( __( '%s must not contain duplicate items.', 'cybermaps' ), $path );
			}
		}
	}

	/**
	 * @param array<int,mixed>    $value  List value.
	 * @param array<string,mixed> $schema Validation schema.
	 * @param string              $path   Human-readable field path.
	 * @param string[]            $errors Validation errors.
	 */
	private function validate_ai_array_items( array $value, array $schema, string $path, array &$errors ): void {
		if ( ! isset( $schema['items'] ) || ! is_array( $schema['items'] ) ) {
			return;
		}
		foreach ( $value as $index => $item ) {
			if ( count( $errors ) >= self::MAX_VALIDATION_ERRORS ) {
				break;
			}
			$this->validate_ai_value( $item, $schema['items'], $path . '[' . $index . ']', $errors );
		}
	}

	/**
	 * @param array<int,mixed>    $value  List value.
	 * @param array<string,mixed> $schema Validation schema.
	 * @param string              $path   Human-readable field path.
	 * @param string[]            $errors Validation errors.
	 */
	private function validate_ai_array_aggregates( array $value, array $schema, string $path, array &$errors ): void {
		if ( isset( $schema['x-max-total-items'] ) ) {
			$total = 0;
			foreach ( $value as $item ) {
				$total += is_array( $item['items'] ?? null ) ? count( $item['items'] ) : 0;
			}
			if ( $total > (int) $schema['x-max-total-items'] ) {                    /* translators: %s: validation field path. */
					$errors[] = sprintf( __( '%s contains too many nested items.', 'cybermaps' ), $path );
			}
		}
		if ( isset( $schema['x-max-joined-bytes'] ) ) {
			$joined = implode( isset( $schema['x-join-separator'] ) ? (string) $schema['x-join-separator'] : '', array_map( static fn ( $item ): string => is_scalar( $item ) ? (string) $item : '', $value ) );
			if ( strlen( $joined ) > (int) $schema['x-max-joined-bytes'] ) {                    /* translators: %s: validation field path. */
					$errors[] = sprintf( __( '%s exceeds the accepted combined size.', 'cybermaps' ), $path );
			}
		}
	}

	/**
	 * @param mixed               $value  Candidate value.
	 * @param array<string,mixed> $schema Validation schema.
	 * @param string[]            $types  Accepted JSON types.
	 * @param string              $path   Human-readable field path.
	 * @param string[]            $errors Validation errors.
	 */
	private function validate_ai_object_constraints( $value, array $schema, array $types, string $path, array &$errors ): void {
		$keywords        = array( 'properties', 'required', 'additionalProperties', 'propertyNames', 'minProperties', 'maxProperties', 'if', 'then', 'else', 'x-fields-not-equal' );
		$has_constraints = in_array( 'object', $types, true ) || ! empty( array_intersect( $keywords, array_keys( $schema ) ) );
		if ( ! is_array( $value ) || ( ! empty( $value ) && array_is_list( $value ) ) || ! $has_constraints ) {
			return;
		}
		if ( isset( $schema['minProperties'] ) && count( $value ) < (int) $schema['minProperties'] ) {
			/* translators: %s: validation field path. */
			$errors[] = sprintf( __( '%s contains too few properties.', 'cybermaps' ), $path );
		}
		if ( isset( $schema['maxProperties'] ) && count( $value ) > (int) $schema['maxProperties'] ) {
			/* translators: %s: validation field path. */
			$errors[] = sprintf( __( '%s contains too many properties.', 'cybermaps' ), $path );
			return;
		}
		$this->validate_ai_required_properties( $value, $schema, $path, $errors );
		$this->validate_ai_object_properties( $value, $schema, $path, $errors );
		$this->validate_ai_distinct_properties( $value, $schema, $path, $errors );
		$this->validate_ai_conditional_properties( $value, $schema, $path, $errors );
	}

	/**
	 * @param array<string,mixed> $value  Object value.
	 * @param array<string,mixed> $schema Validation schema.
	 * @param string              $path   Human-readable field path.
	 * @param string[]            $errors Validation errors.
	 */
	private function validate_ai_required_properties( array $value, array $schema, string $path, array &$errors ): void {
		foreach ( (array) ( $schema['required'] ?? array() ) as $required ) {
			if ( is_string( $required ) && ! array_key_exists( $required, $value ) ) {                  /* translators: 1: field path, 2: required property name. */
					$errors[] = sprintf( __( '%1$s is missing required property %2$s.', 'cybermaps' ), $path, $required );
			}
		}
	}

	/**
	 * @param array<string,mixed> $value  Object value.
	 * @param array<string,mixed> $schema Validation schema.
	 * @param string              $path   Human-readable field path.
	 * @param string[]            $errors Validation errors.
	 */
	private function validate_ai_object_properties( array $value, array $schema, string $path, array &$errors ): void {
		$properties = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();
		foreach ( $value as $property => $item ) {
			if ( count( $errors ) >= self::MAX_VALIDATION_ERRORS ) {
				break;
			}
			$property = (string) $property;
			if ( ! $this->ai_property_name_is_valid( $property, $schema, $path, $errors ) ) {
				continue;
			}
			if ( isset( $properties[ $property ] ) && is_array( $properties[ $property ] ) ) {
				$this->validate_ai_value( $item, $properties[ $property ], $path . '.' . $property, $errors );
			} elseif ( false === ( $schema['additionalProperties'] ?? true ) ) {
				/* translators: 1: field path, 2: unknown property name. */
				$errors[] = sprintf( __( '%1$s contains unknown property %2$s.', 'cybermaps' ), $path, $property );
			} elseif ( isset( $schema['additionalProperties'] ) && is_array( $schema['additionalProperties'] ) ) {
				$this->validate_ai_value( $item, $schema['additionalProperties'], $path . '.' . $property, $errors );
			}
		}
	}

	/**
	 * @param string              $property Property name.
	 * @param array<string,mixed> $schema   Validation schema.
	 * @param string              $path     Human-readable field path.
	 * @param string[]            $errors   Validation errors.
	 */
	private function ai_property_name_is_valid( string $property, array $schema, string $path, array &$errors ): bool {
		if ( ! isset( $schema['propertyNames'] ) || ! is_array( $schema['propertyNames'] ) ) {
			return true;
		}
		$name_errors = array();
		$this->validate_ai_value( $property, $schema['propertyNames'], $path . ' property name', $name_errors );
		if ( empty( $name_errors ) ) {
			return true;
		}
		/* translators: 1: field path, 2: invalid property name. */
		$errors[] = sprintf( __( '%1$s contains an invalid property name: %2$s.', 'cybermaps' ), $path, $property );
		return false;
	}

	/**
	 * @param array<string,mixed> $value  Object value.
	 * @param array<string,mixed> $schema Validation schema.
	 * @param string              $path   Human-readable field path.
	 * @param string[]            $errors Validation errors.
	 */
	private function validate_ai_distinct_properties( array $value, array $schema, string $path, array &$errors ): void {
		$fields = array_values( array_filter( array_map( 'strval', (array) ( $schema['x-fields-not-equal'] ?? array() ) ) ) );
		if ( count( $fields ) < 2 ) {
			return;
		}
		$first = array_shift( $fields );
		foreach ( $fields as $other ) {
			if ( array_key_exists( $first, $value ) && array_key_exists( $other, $value ) && $value[ $first ] === $value[ $other ] ) {
				/* translators: 1: field path, 2: first property name, 3: second property name. */
				$errors[] = sprintf( __( '%1$s requires %2$s and %3$s to differ.', 'cybermaps' ), $path, $first, $other );
			}
		}
	}

	/**
	 * @param array<string,mixed> $value  Object value.
	 * @param array<string,mixed> $schema Validation schema.
	 * @param string              $path   Human-readable field path.
	 * @param string[]            $errors Validation errors.
	 */
	private function validate_ai_conditional_properties( array $value, array $schema, string $path, array &$errors ): void {
		if ( ! isset( $schema['if'] ) || ! is_array( $schema['if'] ) ) {
			return;
		}
		$condition_errors = array();
		$this->validate_ai_value( $value, $schema['if'], $path, $condition_errors );
		$branch = empty( $condition_errors ) ? ( $schema['then'] ?? null ) : ( $schema['else'] ?? null );
		if ( is_array( $branch ) ) {
			$this->validate_ai_value( $value, $branch, $path, $errors );
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	private function current_configuration(): array {
		$state = $this->capture_configuration_state();
		return $state['configuration'];
	}

	/**
	 * Read the complete configuration boundary twice and reject a torn snapshot.
	 *
	 * Dynamic option filters and update hooks can change another Cybermaps root
	 * while a plan is being prepared. A double, exact raw read ensures every
	 * generated target and destination fingerprint has one coherent base.
	 *
	 * @return array{configuration:array<string,mixed>,raw_options:array<string,array{exists:bool,value:mixed}>}
	 */
	private function capture_configuration_state(): array {
		$first  = $this->read_raw_configuration_options();
		$second = $this->read_raw_configuration_options();
		if ( $first !== $second ) {
			throw new \RuntimeException(
				esc_html__( 'The Cybermaps configuration changed while it was being read. Try the operation again.', 'cybermaps' )
			);
		}

		return array(
			'configuration' => $this->normalize_raw_configuration( $first ),
			'raw_options'   => $first,
		);
	}

	/**
	 * @return array<string,array{exists:bool,value:mixed}>
	 */
	private function read_raw_configuration_options(): array {
		$missing = new \stdClass();
		$raw     = array();
		foreach ( self::CONFIGURATION_OPTIONS as $option_name ) {
			$value               = get_option( $option_name, $missing );
			$raw[ $option_name ] = array(
				'exists' => $missing !== $value,
				'value'  => $missing !== $value ? $value : null,
			);
		}

		return $raw;
	}

	/**
	 * @param array<string,array{exists:bool,value:mixed}> $raw Raw option state.
	 * @return array<string,mixed>
	 */
	private function normalize_raw_configuration( array $raw ): array {
		$settings  = $this->raw_configuration_value( $raw, 'cybermaps_settings', array() );
		$discovery = $this->raw_configuration_value( $raw, 'cybermaps_discovery_center', array() );
		$robots    = $this->raw_configuration_value( $raw, 'cybermaps_robots_manager', array() );
		$identity  = $this->raw_configuration_value( $raw, 'cybermaps_identity_data', array() );
		$indexnow  = $this->raw_configuration_value( $raw, 'cybermaps_indexnow_key', '' );

		if ( is_string( $discovery ) ) {
			$decoded   = json_decode( $discovery, true );
			$discovery = is_array( $decoded ) ? $decoded : array();
		}

		return array(
			'cybermaps_settings'         => is_array( $settings ) ? $settings : array(),
			'cybermaps_discovery_center' => is_array( $discovery ) ? $discovery : array(),
			'cybermaps_robots_manager'   => is_array( $robots ) ? $robots : array(),
			'cybermaps_identity_data'    => is_array( $identity ) ? $identity : array(),
			'cybermaps_indexnow_key'     => is_scalar( $indexnow ) ? (string) $indexnow : '',
		);  }
	/**
	 * Read one raw configuration option value.
	 *
	 * @param array<string,array{exists:bool,value:mixed}> $raw Raw option state.
	 * @param string                                       $name Option name.
	 * @param mixed                                        $fallback Default value.
	 * @return mixed
	 */
	private function raw_configuration_value( array $raw, string $name, mixed $fallback ): mixed {
		if ( empty( $raw[ $name ]['exists'] ) ) {
			return $fallback;
		}

		return $raw[ $name ]['value'] ?? $fallback;
	}

	/**
	 * @return array{format:string,mode:string,imported_count:int,changed_groups:string[],unchanged_groups:string[],warnings:string[]}
	 */
	private function prepare_backup( string $content, string $mode, ?array $payload, array $base_state ): array {
		$payload       = $this->resolve_backup_payload( $content, $payload );
		$configuration = $this->validated_backup_configuration( $payload );
		$this->validate_backup_checksum( $payload, $configuration );

		$current = isset( $base_state['configuration'] ) && is_array( $base_state['configuration'] )
			? $base_state['configuration']
			: array();
		$state   = $this->new_backup_preparation_state( $configuration, $current, $mode );
		$this->prepare_backup_group_values( $configuration, $mode, $state );
		$this->sanitize_backup_group_values( $configuration, $mode, $state );
		if ( 'merge' === $mode ) {
			$this->retain_backup_touched_fields( $state );
		}

		$discovery_target = $this->backup_discovery_target( $mode, $state, $base_state );
		$targets          = $this->build_backup_targets( $mode, $state, $discovery_target );
		$preview_fields   = $this->backup_preview_fields( $mode, $current, $state );

		return array(
			'format'          => 'backup',
			'mode'            => $mode,
			'imported_count'  => $this->configuration_value_count( $configuration ),
			'targets'         => $targets,
			'proposed_values' => array(),
			'touched_fields'  => $preview_fields,
			'post_unchanged'  => 'merge' === $mode && '' === $state['source_indexnow_key']
				? array( 'cybermaps_indexnow_key' )
				: array(),
			'warnings'        => $state['warnings'],
			'errors'          => array(),
		);  }
	/**
	 * Resolve a decoded backup payload.
	 *
	 * @param string                   $content Raw import content.
	 * @param array<string,mixed>|null $payload Pre-decoded payload.
	 * @return array<string,mixed>
	 */
	private function resolve_backup_payload( string $content, ?array $payload ): array {
		if ( null !== $payload ) {
			return $payload;
		}

		return $this->decode_json_object(
			$content,
			/* translators: %s: JSON parser error message. */
			__( 'The backup JSON is invalid: %s', 'cybermaps' )
		);
	}

	/**
	 * Validate a backup envelope and return its configuration.
	 *
	 * @param array<string,mixed> $payload Backup payload.
	 * @return array<string,mixed>
	 */
	private function validated_backup_configuration( array $payload ): array {
		$this->validate_backup_envelope( $payload );
		$configuration = $payload['configuration'] ?? null;
		if ( ! is_array( $configuration ) ) {
			throw new \InvalidArgumentException( esc_html__( 'The backup does not contain configuration data.', 'cybermaps' ) );
		}

		$this->validate_required_backup_groups( $configuration );
		$this->validate_backup_configuration_shape( $configuration );
		return $configuration;
	}

	/**
	 * Validate backup format and version fields.
	 *
	 * @param array<string,mixed> $payload Backup payload.
	 */
	private function validate_backup_envelope( array $payload ): void {
		if ( self::BACKUP_FORMAT !== ( $payload['format'] ?? null ) ) {
			throw new \InvalidArgumentException( esc_html__( 'This is not a Cybermaps configuration backup.', 'cybermaps' ) );
		}
		if ( ! isset( $payload['format_version'] ) || ! is_int( $payload['format_version'] ) || self::BACKUP_VERSION !== $payload['format_version'] ) {
			throw new \InvalidArgumentException( esc_html__( 'This Cybermaps backup version is not supported.', 'cybermaps' ) );
		}
	}

	/**
	 * Validate required backup groups in their canonical order.
	 *
	 * @param array<string,mixed> $configuration Backup configuration.
	 */
	private function validate_required_backup_groups( array $configuration ): void {
		foreach ( self::REQUIRED_GROUPS as $group ) {
			if ( ! array_key_exists( $group, $configuration ) || ! is_array( $configuration[ $group ] ) ) {
				throw new \InvalidArgumentException(
					sprintf(
						/* translators: %s: missing configuration group. */
						esc_html__( 'The backup is incomplete: %s is missing.', 'cybermaps' ),
						esc_html( $group )
					)
				);
			}
			if ( ! empty( $configuration[ $group ] ) && array_is_list( $configuration[ $group ] ) ) {
				throw new \InvalidArgumentException(
					sprintf(
						/* translators: %s: malformed configuration group. */
						esc_html__( 'The backup is malformed: %s must be a configuration object.', 'cybermaps' ),
						esc_html( $group )
					)
				);
			}
		}
	}

	/**
	 * Validate optional and scalar backup configuration fields.
	 *
	 * @param array<string,mixed> $configuration Backup configuration.
	 */
	private function validate_backup_configuration_shape( array $configuration ): void {
		if ( ! array_key_exists( 'cybermaps_indexnow_key', $configuration ) || ! is_string( $configuration['cybermaps_indexnow_key'] ) ) {
			throw new \InvalidArgumentException(
				esc_html__( 'The backup is incomplete: cybermaps_indexnow_key is missing or malformed.', 'cybermaps' )
			);
		}
		$allowed_groups = array_merge( self::REQUIRED_GROUPS, array( 'cybermaps_indexnow_key' ) );
		if ( ! empty( array_diff( array_keys( $configuration ), $allowed_groups ) ) ) {
			throw new \InvalidArgumentException(
				esc_html__( 'The backup contains configuration groups that are not supported by this format version.', 'cybermaps' )
			);
		}
		if ( $this->backup_api_secret_is_malformed( $configuration['cybermaps_settings'] ) ) {
			throw new \InvalidArgumentException(
				esc_html__( 'The backup is malformed: api_secret must be a scalar value.', 'cybermaps' )
			);
		}
	}

	/**
	 * Determine whether a backup API secret has an invalid shape.
	 *
	 * @param array<string,mixed> $settings Backup settings.
	 */
	private function backup_api_secret_is_malformed( array $settings ): bool {
		return array_key_exists( 'api_secret', $settings )
			&& ! is_scalar( $settings['api_secret'] )
			&& null !== $settings['api_secret'];
	}

	/**
	 * Validate a backup configuration checksum.
	 *
	 * @param array<string,mixed> $payload Backup payload.
	 * @param array<string,mixed> $configuration Backup configuration.
	 */
	private function validate_backup_checksum( array $payload, array $configuration ): void {
		$checksum = isset( $payload['checksum'] ) && is_string( $payload['checksum'] )
			? $payload['checksum']
			: '';
		try {
			$expected = $this->configuration_checksum( $configuration );
		} catch ( \RuntimeException $error ) {
			unset( $error );
			throw new \InvalidArgumentException(
				esc_html__( 'The backup contains configuration values that cannot be verified safely.', 'cybermaps' )
			);
		}
		if ( '' === $checksum || ! hash_equals( $expected, $checksum ) ) {
			throw new \InvalidArgumentException(
				esc_html__( 'The backup integrity check failed. Download a fresh backup and try again.', 'cybermaps' )
			);
		}
	}

	/**
	 * Initialize backup preparation state.
	 *
	 * @param array<string,mixed> $configuration Backup configuration.
	 * @param array<string,mixed> $current Current normalized configuration.
	 * @param string              $mode Import mode.
	 * @return array<string,mixed>
	 */
	private function new_backup_preparation_state( array $configuration, array $current, string $mode ): array {
		$source_indexnow_key = $this->sanitize_indexnow_key( $configuration['cybermaps_indexnow_key'] );
		return array(
			'current'             => $current,
			'warnings'            => array(),
			'merge_fields'        => $this->backup_merge_fields( $current, $configuration ),
			'source_indexnow_key' => $source_indexnow_key,
			'preserve_indexnow'   => 'merge' === $mode
				&& '' === $source_indexnow_key
				&& '' !== $current['cybermaps_indexnow_key'],
		);
	}

	/**
	 * Calculate differing fields for all structured groups.
	 *
	 * @param array<string,mixed> $current Current configuration.
	 * @param array<string,mixed> $configuration Backup configuration.
	 * @return array<string,list<string>>
	 */
	private function backup_merge_fields( array $current, array $configuration ): array {
		return array(
			'cybermaps_settings'         => $this->differing_fields( $current['cybermaps_settings'], $configuration['cybermaps_settings'] ),
			'cybermaps_discovery_center' => $this->differing_fields( $current['cybermaps_discovery_center'], $configuration['cybermaps_discovery_center'] ),
			'cybermaps_robots_manager'   => $this->differing_fields( $current['cybermaps_robots_manager'], $configuration['cybermaps_robots_manager'] ),
			'cybermaps_identity_data'    => $this->differing_fields( $current['cybermaps_identity_data'], $configuration['cybermaps_identity_data'] ),
		);
	}

	/**
	 * Prepare merged or replacement group values.
	 *
	 * @param array<string,mixed> $configuration Backup configuration.
	 * @param string              $mode Import mode.
	 * @param array<string,mixed> $state Mutable preparation state.
	 */
	private function prepare_backup_group_values( array $configuration, string $mode, array &$state ): void {
		$state['settings'] = $this->backup_group_value(
			'cybermaps_settings',
			$state['current']['cybermaps_settings'],
			$configuration['cybermaps_settings'],
			$mode
		);
		$this->preserve_backup_api_secret( $mode, $state );
		foreach ( array( 'cybermaps_discovery_center', 'cybermaps_robots_manager', 'cybermaps_identity_data' ) as $option_name ) {
			$state[ $option_name ] = $this->backup_group_value(
				$option_name,
				$state['current'][ $option_name ],
				$configuration[ $option_name ],
				$mode
			);
		}
	}

	/**
	 * Prepare one backup group for merge or replacement.
	 *
	 * @param string              $option_name Option name.
	 * @param array<string,mixed> $current Current group.
	 * @param array<string,mixed> $incoming Incoming group.
	 * @param string              $mode Import mode.
	 * @return array<string,mixed>
	 */
	private function backup_group_value( string $option_name, array $current, array $incoming, string $mode ): array {
		if ( 'merge' !== $mode ) {
			return $incoming;
		}

		return $this->merge_configuration_group( $option_name, $current, $incoming );
	}

	/**
	 * Preserve the destination secret during merge when required.
	 *
	 * @param string              $mode Import mode.
	 * @param array<string,mixed> $state Mutable preparation state.
	 */
	private function preserve_backup_api_secret( string $mode, array &$state ): void {
		if ( 'merge' !== $mode || ! empty( $state['settings']['api_secret'] ) || empty( $state['current']['cybermaps_settings']['api_secret'] ) ) {
			return;
		}

		$state['settings']['api_secret']             = $state['current']['cybermaps_settings']['api_secret'];
		$state['warnings'][]                         = __( 'The destination API secret was preserved because the backup did not contain one.', 'cybermaps' );
		$state['merge_fields']['cybermaps_settings'] = array_values(
			array_diff( $state['merge_fields']['cybermaps_settings'], array( 'api_secret' ) )
		);
	}

	/**
	 * Sanitize all prepared backup groups.
	 *
	 * @param array<string,mixed> $configuration Backup configuration.
	 * @param string              $mode Import mode.
	 * @param array<string,mixed> $state Mutable preparation state.
	 */
	private function sanitize_backup_group_values( array $configuration, string $mode, array &$state ): void {
		$state['sanitized_settings']  = $this->sanitize_backup_settings( $configuration, $mode, $state );
		$state['sanitized_discovery'] = $this->sanitize_backup_discovery( $mode, $state );
		$state['sanitized_robots']    = $this->sanitize_backup_robots( $mode, $state );
		$state['sanitized_identity']  = $this->sanitize_backup_identity( $mode, $state );
	}

	/**
	 * Sanitize backup settings and append secret-generation warnings.
	 *
	 * @param array<string,mixed> $configuration Backup configuration.
	 * @param string              $mode Import mode.
	 * @param array<string,mixed> $state Preparation state.
	 * @return array<string,mixed>
	 */
	private function sanitize_backup_settings( array $configuration, string $mode, array &$state ): array {
		$settings_base = 'merge' === $mode ? $state['current']['cybermaps_settings'] : array();
		if ( 'merge' === $mode && empty( $state['merge_fields']['cybermaps_settings'] ) ) {
			$sanitized = $state['current']['cybermaps_settings'];
		} else {
			$sanitized = SettingsSanitizer::sanitize_import( $state['settings'], $settings_base );
		}
		if ( 'overwrite' === $mode && empty( $configuration['cybermaps_settings']['api_secret'] ) && ! empty( $sanitized['api_secret'] ) ) {
			$state['warnings'][] = __( 'The backup did not contain an API secret, so Cybermaps generated a new one during Full Replace.', 'cybermaps' );
		}
		return $sanitized;
	}

	/**
	 * Sanitize a backup Discovery Center group.
	 *
	 * @param string              $mode Import mode.
	 * @param array<string,mixed> $state Preparation state.
	 * @return array<string,mixed>
	 */
	private function sanitize_backup_discovery( string $mode, array $state ): array {
		if ( 'merge' === $mode && empty( $state['merge_fields']['cybermaps_discovery_center'] ) ) {
			return $state['current']['cybermaps_discovery_center'];
		}

		$sanitized = DiscoveryCenterSanitizer::sanitize( $this->encode_json( $state['cybermaps_discovery_center'] ) );
		$decoded   = json_decode( $sanitized, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Sanitize a backup Robots Manager group.
	 *
	 * @param string              $mode Import mode.
	 * @param array<string,mixed> $state Preparation state.
	 * @return array<string,mixed>
	 */
	private function sanitize_backup_robots( string $mode, array $state ): array {
		if ( 'merge' === $mode && empty( $state['merge_fields']['cybermaps_robots_manager'] ) ) {
			return $state['current']['cybermaps_robots_manager'];
		}

		return RobotsManagerSanitizer::sanitize( $state['cybermaps_robots_manager'] );
	}

	/**
	 * Sanitize a backup identity group.
	 *
	 * @param string              $mode Import mode.
	 * @param array<string,mixed> $state Preparation state.
	 * @return array<string,mixed>
	 */
	private function sanitize_backup_identity( string $mode, array $state ): array {
		if ( 'merge' === $mode && empty( $state['merge_fields']['cybermaps_identity_data'] ) ) {
			return $state['current']['cybermaps_identity_data'];
		}

		return ( new IdentityHub() )->sanitize_identity_data( $state['cybermaps_identity_data'] );
	}

	/**
	 * Limit merge results to fields touched by the backup.
	 *
	 * @param array<string,mixed> $state Mutable preparation state.
	 */
	private function retain_backup_touched_fields( array &$state ): void {
		foreach ( $this->backup_sanitized_group_map() as $option_name => $state_key ) {
			$state[ $state_key ] = $this->merge_touched_fields(
				$state['current'][ $option_name ],
				$state[ $state_key ],
				$state['merge_fields'][ $option_name ]
			);
		}
	}

	/**
	 * Map configuration roots to their sanitized state keys.
	 *
	 * @return array<string,string>
	 */
	private function backup_sanitized_group_map(): array {
		return array(
			'cybermaps_settings'         => 'sanitized_settings',
			'cybermaps_discovery_center' => 'sanitized_discovery',
			'cybermaps_robots_manager'   => 'sanitized_robots',
			'cybermaps_identity_data'    => 'sanitized_identity',
		);
	}

	/**
	 * Build the stored Discovery Center target value.
	 *
	 * @param string              $mode Import mode.
	 * @param array<string,mixed> $state Preparation state.
	 * @param array<string,mixed> $base_state Captured destination state.
	 */
	private function backup_discovery_target( string $mode, array $state, array $base_state ): string {
		$current_raw_state = isset( $base_state['raw_options']['cybermaps_discovery_center'] )
			&& is_array( $base_state['raw_options']['cybermaps_discovery_center'] )
				? $base_state['raw_options']['cybermaps_discovery_center']
				: array();
		$current_raw       = ! empty( $current_raw_state['exists'] )
			? ( $current_raw_state['value'] ?? '' )
			: '';
		// JSON decodes a stored 1.0 as integer 1; loose comparison is intentional
		// here so a semantic no-op preserves the original bytes.
		if ( 'merge' === $mode && $state['sanitized_discovery'] == $state['current']['cybermaps_discovery_center'] && is_string( $current_raw ) ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
			return $current_raw;
		}

		return $this->encode_json( $state['sanitized_discovery'] );
	}

	/**
	 * Build prepared backup option targets.
	 *
	 * @param string              $mode Import mode.
	 * @param array<string,mixed> $state Preparation state.
	 * @param string              $discovery_target Stored discovery target.
	 * @return array<string,mixed>
	 */
	private function build_backup_targets( string $mode, array &$state, string $discovery_target ): array {
		$targets = array(
			'cybermaps_settings'         => $state['sanitized_settings'],
			'cybermaps_discovery_center' => $discovery_target,
			'cybermaps_robots_manager'   => $state['sanitized_robots'],
			'cybermaps_identity_data'    => $state['sanitized_identity'],
		);
		if ( 'overwrite' === $mode || '' !== $state['source_indexnow_key'] ) {
			$targets['cybermaps_indexnow_key'] = $state['source_indexnow_key'];
		} elseif ( $state['preserve_indexnow'] ) {
			$state['warnings'][] = __( 'The destination IndexNow key was preserved because the backup did not contain one.', 'cybermaps' );
		}
		return $targets;
	}

	/**
	 * Build backup preview field lists.
	 *
	 * @param string              $mode Import mode.
	 * @param array<string,mixed> $current Current configuration.
	 * @param array<string,mixed> $state Preparation state.
	 * @return array<string,list<string>>
	 */
	private function backup_preview_fields( string $mode, array $current, array $state ): array {
		$fields = array(
			'cybermaps_settings'         => array_keys( $state['sanitized_settings'] ),
			'cybermaps_discovery_center' => array_keys( $state['sanitized_discovery'] ),
			'cybermaps_robots_manager'   => array_keys( $state['sanitized_robots'] ),
			'cybermaps_identity_data'    => array_keys( $state['sanitized_identity'] ),
			'cybermaps_indexnow_key'     => array( 'cybermaps_indexnow_key' ),
		);
		if ( 'overwrite' === $mode ) {
			$this->expand_backup_preview_fields( $fields, $current, $state );
		}
		return $fields;
	}

	/**
	 * Include fields removed by a full replacement in its preview.
	 *
	 * @param array<string,list<string>> $fields Mutable preview fields.
	 * @param array<string,mixed>        $current Current configuration.
	 * @param array<string,mixed>        $state Preparation state.
	 */
	private function expand_backup_preview_fields( array &$fields, array $current, array $state ): void {
		foreach ( $this->backup_sanitized_group_map() as $option_name => $state_key ) {
			$fields[ $option_name ] = array_values(
				array_unique(
					array_merge( array_keys( $current[ $option_name ] ), array_keys( $state[ $state_key ] ) )
				)
			);
		}
	}

	/**
	 * Apply all prepared option targets and roll back if verification fails.
	 *
	 * @param array<string,mixed>                         $targets      Prepared option values.
	 * @param array<string,array{exists:bool,value:mixed}> $expected_raw Exact destination state used to prepare and preview.
	 * @return array{changed:string[],unchanged:string[]}
	 */
	private function apply_targets( array $targets, array $expected_raw = array() ): array {
		$expected_raw = $this->validate_apply_target_guards( $targets, $expected_raw );
		$transaction  = $this->new_apply_transaction();
		$failure      = null;

		self::$applying_prepared_import = true;
		try {
			try {
				$this->execute_apply_transaction( $targets, $expected_raw, $transaction );
			} catch ( \Throwable $error ) {
				$failure = $error;
			}

			if ( null !== $failure ) {
				$this->throw_apply_transaction_failure( $transaction, $failure );
			}
		} finally {
			self::$applying_prepared_import = false;
		}

		// Core hooks were deliberately silent during the transaction. Apply their
		// cache, publication, audit-generation, and rewrite effects once, using the
		// verified before/after states, only after the commit can no longer roll
		// back.
		$this->reconcile_committed_configuration(
			$transaction['previous'],
			$transaction['expected_after'],
			$transaction['changed']
		);

		return array(
			'changed'   => $transaction['changed'],
			'unchanged' => $transaction['unchanged'],
		);  }
	/**
	 * Validate prepared targets and their destination-state guard.
	 *
	 * @param array<string,mixed> $targets Prepared target values.
	 * @param array<string,mixed> $expected_raw Expected raw option state.
	 * @return array<string,mixed>
	 */
	private function validate_apply_target_guards( array $targets, array $expected_raw ): array {
		$unexpected_targets = array_diff( array_keys( $targets ), self::CONFIGURATION_OPTIONS );
		if ( ! empty( $unexpected_targets ) ) {
			throw new \InvalidArgumentException(
				esc_html__( 'The prepared import contains an unsupported configuration target.', 'cybermaps' )
			);
		}

		// The optional fallback exists for internal/reflection compatibility tests;
		// every public import path supplies the snapshot captured during prepare.
		if ( empty( $expected_raw ) ) {
			$state        = $this->capture_configuration_state();
			$expected_raw = $state['raw_options'];
		}
		$this->assert_complete_destination_guard( $expected_raw );
		return $expected_raw;
	}

	/**
	 * Assert that every owned root is represented by the state guard.
	 *
	 * @param array<string,mixed> $expected_raw Expected raw option state.
	 */
	private function assert_complete_destination_guard( array $expected_raw ): void {
		foreach ( self::CONFIGURATION_OPTIONS as $option_name ) {
			$guard = $expected_raw[ $option_name ] ?? null;
			if ( ! is_array( $guard ) || ! array_key_exists( 'exists', $guard ) || ! array_key_exists( 'value', $guard ) ) {
				throw new \InvalidArgumentException(
					esc_html__( 'The prepared import is missing its destination-state guard.', 'cybermaps' )
				);
			}
		}
	}

	/**
	 * Create mutable transaction state.
	 *
	 * @return array<string,mixed>
	 */
	private function new_apply_transaction(): array {
		return array(
			'previous'       => array(),
			'expected_after' => array(),
			'changed'        => array(),
			'unchanged'      => array(),
			'pending'        => array(),
			'writes_started' => false,
		);
	}

	/**
	 * Execute and verify one guarded configuration transaction.
	 *
	 * @param array<string,mixed> $targets Prepared target values.
	 * @param array<string,mixed> $expected_raw Expected raw option state.
	 * @param array<string,mixed> $transaction Mutable transaction state.
	 */
	private function execute_apply_transaction( array $targets, array $expected_raw, array &$transaction ): void {
		// Re-read every owned root immediately before the first write. This closes
		// the gap between destination fingerprint validation and persistence.
		$state                   = $this->capture_configuration_state();
		$transaction['previous'] = $state['raw_options'];
		if ( $expected_raw !== $transaction['previous'] ) {
			throw new \RuntimeException(
				esc_html__( 'The destination configuration changed after preparation. Preview the file again before importing.', 'cybermaps' )
			);
		}

		$this->plan_apply_transaction( $targets, $transaction );
		$this->write_apply_transaction( $transaction );
		$verified = $this->capture_configuration_state();
		if ( $verified['raw_options'] !== $transaction['expected_after'] ) {
			throw new \RuntimeException(
				esc_html__( 'WordPress could not verify the complete imported configuration.', 'cybermaps' )
			);
		}
	}

	/**
	 * Plan changed and unchanged roots for a transaction.
	 *
	 * @param array<string,mixed> $targets Prepared target values.
	 * @param array<string,mixed> $transaction Mutable transaction state.
	 */
	private function plan_apply_transaction( array $targets, array &$transaction ): void {
		$transaction['expected_after'] = $transaction['previous'];
		foreach ( $targets as $option_name => $target ) {
			$current_state = $transaction['previous'][ $option_name ];
			if ( ! empty( $current_state['exists'] ) && $current_state['value'] === $target ) {
				$transaction['unchanged'][] = $option_name;
				continue;
			}

			$transaction['pending'][ $option_name ]        = $target;
			$transaction['expected_after'][ $option_name ] = array(
				'exists' => true,
				'value'  => $target,
			);
		}
	}

	/**
	 * Persist all planned root changes.
	 *
	 * @param array<string,mixed> $transaction Mutable transaction state.
	 */
	private function write_apply_transaction( array &$transaction ): void {
		foreach ( $transaction['pending'] as $option_name => $target ) {
			$transaction['writes_started'] = true;
			update_option( $option_name, $target, false );
			$transaction['changed'][] = $option_name;
		}
	}

	/**
	 * Roll back a failed configuration transaction and throw its diagnostic.
	 *
	 * @param array<string,mixed> $transaction Transaction state.
	 * @param \Throwable          $failure Transaction failure.
	 */
	private function throw_apply_transaction_failure( array $transaction, \Throwable $failure ): never {
		if ( ! $transaction['writes_started'] ) {
			throw new \RuntimeException(
				esc_html__( 'The import failed before any configuration values were written.', 'cybermaps' ),
				0,
				$failure // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Previous exception is not output.
			);
		}

		$rollback_failures = $this->rollback_targets( $transaction['previous'] );
		if ( ! empty( $rollback_failures ) ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %s: comma-separated configuration group names. */
					esc_html__( 'The import failed and Cybermaps could not fully restore these configuration groups: %s. Review the settings before retrying.', 'cybermaps' ),
					esc_html( implode( ', ', $rollback_failures ) )
				),
				0,
				$failure // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Previous exception is not output.
			);
		}

		throw new \RuntimeException(
			esc_html__( 'The import failed. Cybermaps restored the previous configuration.', 'cybermaps' ),
			0,
			$failure // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Previous exception is not output.
		);
	}

	/**
	 * Restore every option touched by a failed import and verify the final state.
	 *
	 * @param array<string,array{exists:bool,value:mixed}> $previous Previous raw option states.
	 * @return string[] Option names that could not be restored.
	 */
	private function rollback_targets( array $previous ): array {
		foreach ( array_reverse( $previous, true ) as $option_name => $state ) {
			try {
				if ( empty( $state['exists'] ) ) {
					delete_option( $option_name );
				} else {
					update_option( $option_name, $state['value'], false );
				}
			} catch ( \Throwable $error ) {
				// Verification below determines whether an after-update hook
				// threw after WordPress had already restored the stored value.
				unset( $error );
			}
		}

		try {
			$restored = $this->capture_configuration_state();
			$failures = array();
			foreach ( $previous as $option_name => $state ) {
				if ( ! isset( $restored['raw_options'][ $option_name ] ) || $restored['raw_options'][ $option_name ] !== $state ) {
					$failures[] = $option_name;
				}
			}
		} catch ( \Throwable $error ) {
			unset( $error );
			$failures = array_keys( $previous );
		}

		return $failures;
	}

	/**
	 * Run Core's ordinary option-change handlers after a verified import commit.
	 *
	 * @param array<string,array{exists:bool,value:mixed}> $before  Previous raw option states.
	 * @param array<string,array{exists:bool,value:mixed}> $after   Verified raw option states.
	 * @param string[]                                     $changed Changed option names.
	 */
	private function reconcile_committed_configuration( array $before, array $after, array $changed ): void {
		$changed  = array_fill_keys( $changed, true );
		$settings = new Settings();

		if ( isset( $changed['cybermaps_settings'] ) ) {
			$settings->on_settings_updated(
				$this->raw_state_value( $before, 'cybermaps_settings', array() ),
				$this->raw_state_value( $after, 'cybermaps_settings', array() )
			);
		}
		if ( isset( $changed['cybermaps_discovery_center'] ) ) {
			$settings->on_discovery_center_updated(
				$this->raw_state_value( $before, 'cybermaps_discovery_center', '' ),
				$this->raw_state_value( $after, 'cybermaps_discovery_center', '' )
			);
		}
		if ( isset( $changed['cybermaps_robots_manager'] ) ) {
			$settings->on_robots_manager_updated(
				$this->raw_state_value( $before, 'cybermaps_robots_manager', array() ),
				$this->raw_state_value( $after, 'cybermaps_robots_manager', array() )
			);
		}
		if ( isset( $changed['cybermaps_identity_data'] ) ) {
			( new IdentityHub() )->on_identity_updated(
				$this->raw_state_value( $before, 'cybermaps_identity_data', array() ),
				$this->raw_state_value( $after, 'cybermaps_identity_data', array() )
			);
		}
	}

	/**
	 * @param array<string,array{exists:bool,value:mixed}> $states  Raw option states.
	 * @param mixed                                        $fallback Missing-option fallback.
	 * @return mixed
	 */
	private function raw_state_value( array $states, string $option_name, $fallback ) {
		return isset( $states[ $option_name ] ) && ! empty( $states[ $option_name ]['exists'] )
			? $states[ $option_name ]['value']
			: $fallback;
	}

	/**
	 * Apply only explicitly selected Smart Merge fields to the raw destination
	 * shape. This prevents unrelated absent/default fields from being
	 * materialized merely because a sanitizer returned its canonical structure.
	 *
	 * @param array<string,mixed> $current   Current option value.
	 * @param array<string,mixed> $sanitized Sanitized complete candidate.
	 * @param string[]            $fields    Explicitly imported field names.
	 * @return array<string,mixed>
	 */
	private function merge_touched_fields( array $current, array $sanitized, array $fields ): array {
		foreach ( $fields as $field ) {
			if ( array_key_exists( $field, $sanitized ) ) {
				$current[ $field ] = $sanitized[ $field ];
			}
		}

		return $current;
	}

	/**
	 * Return source fields whose explicit value differs from the destination.
	 *
	 * @param array<string,mixed> $current Destination option.
	 * @param array<string,mixed> $source  Backed-up option.
	 * @return string[]
	 */
	private function differing_fields( array $current, array $source ): array {
		$fields = array();
		foreach ( $source as $field => $value ) {
			if ( ! array_key_exists( $field, $current ) || $current[ $field ] !== $value ) {
				$fields[] = (string) $field;
			}
		}

		return $fields;
	}

	/**
	 * Merge one complete backup group while retaining destination-only map
	 * entries. Lists and scalar fields remain source-replacing.
	 *
	 * @param array<string,mixed> $current Destination group.
	 * @param array<string,mixed> $source  Backed-up group.
	 * @return array<string,mixed>
	 */
	private function merge_configuration_group( string $group, array $current, array $source ): array {
		$map_fields = array(
			'cybermaps_discovery_center' => array( 'overrides', 'type_intents', 'disabled' ),
			'cybermaps_robots_manager'   => array( 'overrides', 'content_signals' ),
			'cybermaps_identity_data'    => array( 'hours' ),
		);
		$maps       = array_fill_keys( $map_fields[ $group ] ?? array(), true );
		$merged     = $current;

		foreach ( $source as $field => $value ) {
			if (
				isset( $maps[ $field ] )
				&& is_array( $value )
				&& isset( $merged[ $field ] )
				&& is_array( $merged[ $field ] )
			) {
				$merged[ $field ] = array_replace( $merged[ $field ], $value );
			} else {
				$merged[ $field ] = $value;
			}
		}

		return $merged;
	}

	/**
	 * @param array<string,mixed> $configuration Configuration payload.
	 */
	private function configuration_value_count( array $configuration ): int {
		$count = 0;
		foreach ( self::REQUIRED_GROUPS as $group ) {
			$count += count( (array) ( $configuration[ $group ] ?? array() ) );
		}
		if ( array_key_exists( 'cybermaps_indexnow_key', $configuration ) ) {
			++$count;
		}

		return $count;
	}

	/**
	 * @param mixed $value Value to encode.
	 */
	private function encode_json( $value, bool $pretty = false ): string {
		return $this->encode_json_value( $value, $pretty, 0 );
	}

	/**
	 * Encode JSON recursively so float output does not depend on the server's
	 * serialize_precision setting.
	 *
	 * @param mixed $value Value to encode.
	 */
	private function encode_json_value( $value, bool $pretty, int $depth ): string {
		if ( $depth > 512 ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is not HTML output.
			throw new \RuntimeException( __( 'Canonical JSON nesting exceeds the supported depth.', 'cybermaps' ) );
		}
		if ( is_float( $value ) ) {
			return $this->encode_json_float( $value );
		}
		if ( is_object( $value ) ) {
			return $this->encode_json_array( get_object_vars( $value ), true, $pretty, $depth );
		}
		if ( is_array( $value ) ) {
			return $this->encode_json_array( $value, false, $pretty, $depth );
		}

		return $this->encode_json_scalar( $value ); }
	/**
	 * Encode a JSON array or object.
	 *
	 * @param array<mixed> $value Array value.
	 * @param bool         $force_object Force object notation.
	 * @param bool         $pretty Pretty-print output.
	 * @param int          $depth Current depth.
	 */
	private function encode_json_array( array $value, bool $force_object, bool $pretty, int $depth ): string {
		$is_list = ! $force_object && array_is_list( $value );
		if ( array() === $value ) {
			return $is_list ? '[]' : '{}';
		}

		$parts = $this->encode_json_array_parts( $value, $is_list, $pretty, $depth );
		return $this->format_json_container( $parts, $is_list, $pretty, $depth );
	}

	/**
	 * Encode JSON container members.
	 *
	 * @param array<mixed> $value Container value.
	 * @param bool         $is_list Whether the container is a list.
	 * @param bool         $pretty Pretty-print output.
	 * @param int          $depth Current depth.
	 * @return list<string>
	 */
	private function encode_json_array_parts( array $value, bool $is_list, bool $pretty, int $depth ): array {
		$parts = array();
		foreach ( $value as $key => $item ) {
			$encoded = $this->encode_json_value( $item, $pretty, $depth + 1 );
			$parts[] = $is_list ? $encoded : $this->encode_json_member( $key, $encoded, $pretty );
		}

		return $parts;
	}

	/**
	 * Encode one object member.
	 *
	 * @param int|string $key Member key.
	 * @param string     $encoded Encoded member value.
	 * @param bool       $pretty Pretty-print output.
	 */
	private function encode_json_member( int|string $key, string $encoded, bool $pretty ): string {
		$encoded_key = wp_json_encode(
			(string) $key,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		if ( ! is_string( $encoded_key ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is not HTML output.
			throw new \RuntimeException( __( 'Could not encode a canonical JSON object key.', 'cybermaps' ) );
		}

		return $encoded_key . ( $pretty ? ': ' : ':' ) . $encoded;
	}

	/**
	 * Format an encoded JSON container.
	 *
	 * @param list<string> $parts Encoded members.
	 * @param bool         $is_list Whether the container is a list.
	 * @param bool         $pretty Pretty-print output.
	 * @param int          $depth Current depth.
	 */
	private function format_json_container( array $parts, bool $is_list, bool $pretty, int $depth ): string {
		$open  = $is_list ? '[' : '{';
		$close = $is_list ? ']' : '}';
		if ( ! $pretty ) {
			return $open . implode( ',', $parts ) . $close;
		}

		$indent         = str_repeat( '    ', $depth + 1 );
		$closing_indent = str_repeat( '    ', $depth );
		return $open . "\n" . $indent . implode( ",\n" . $indent, $parts ) . "\n" . $closing_indent . $close;
	}

	/**
	 * Encode a scalar JSON value.
	 *
	 * @param mixed $value Scalar value.
	 */
	private function encode_json_scalar( mixed $value ): string {
		$encoded = wp_json_encode(
			$value,
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		if ( ! is_string( $encoded ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception text is not HTML output.
			throw new \RuntimeException( __( 'Could not encode a canonical JSON value.', 'cybermaps' ) );
		}

		return $encoded;
	}

	/**
	 * Return the shortest JSON number that round-trips to the same float.
	 */
	private function encode_json_float( float $value ): string {
		if ( ! is_finite( $value ) ) {
			throw new \RuntimeException( esc_html__( 'Cybermaps cannot back up a non-finite numeric configuration value.', 'cybermaps' ) );
		}

		$locale        = localeconv();
		$decimal_point = isset( $locale['decimal_point'] ) && is_string( $locale['decimal_point'] )
			? $locale['decimal_point']
			: '.';
		$encoded       = '';
		for ( $precision = 1; $precision <= 17; ++$precision ) {
			$candidate = sprintf( '%.' . $precision . 'g', $value );
			if ( '' !== $decimal_point && '.' !== $decimal_point ) {
				$candidate = str_replace( $decimal_point, '.', $candidate );
			}
			if ( pack( 'E', (float) $candidate ) === pack( 'E', $value ) ) {
				$encoded = $candidate;
				break;
			}
		}

		if ( '' === $encoded ) {
			throw new \RuntimeException( esc_html__( 'Cybermaps could not encode a numeric configuration value without losing precision.', 'cybermaps' ) );
		}
		if ( false === strpbrk( $encoded, '.eE' ) ) {
			$encoded .= '.0';
		}

		return $encoded;
	}

	/**
	 * Build a portable checksum independent of JSON whitespace, key order, and
	 * the server's serialize_precision setting.
	 *
	 * @param array<string,mixed> $configuration Configuration payload.
	 */
	private function configuration_checksum( array $configuration ): string {
		$canonical = $this->canonicalize_checksum_value( $configuration );
		return 'sha256:' . hash( 'sha256', $this->encode_json( $canonical ) );
	}

	/**
	 * @param mixed $value Value to canonicalize.
	 * @return mixed
	 */
	private function canonicalize_checksum_value( $value ) {
		if ( is_float( $value ) ) {
			return array(
				'__cybermaps_checksum_type' => 'float64',
				'value'                     => bin2hex( pack( 'E', $value ) ),
			);
		}
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}

		if ( array_is_list( $value ) ) {
			return array(
				'__cybermaps_checksum_type' => 'list',
				'items'                     => array_map( array( $this, 'canonicalize_checksum_value' ), $value ),
			);
		}

		ksort( $value, SORT_STRING );
		$items = array();
		foreach ( $value as $key => $item ) {
			$items[] = array(
				(string) $key,
				$this->canonicalize_checksum_value( $item ),
			);
		}

		return array(
			'__cybermaps_checksum_type' => 'map',
			'items'                     => $items,
		);
	}

	/**
	 * @param mixed $value Candidate IndexNow key.
	 */
	private function sanitize_indexnow_key( $value ): string {
		$key = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $value );
		return substr( is_string( $key ) ? $key : '', 0, 128 );
	}
}
