<?php
declare(strict_types=1);

// Production memo path with an in-memory SQL adapter, never a native database.
define( 'ABSPATH', __DIR__ . '/' );
define( 'CYBERMAPS_PHPUNIT', false );
require dirname( __DIR__, 2 ) . '/src/Core/ConfigurationStore.php';
require dirname( __DIR__, 2 ) . '/src/Core/RawOptionStore.php';
require dirname( __DIR__, 2 ) . '/src/Core/BuildUnavailableException.php';

$GLOBALS['snapshot_hooks'] = array();
$GLOBALS['snapshot_callbacks'] = array();
function get_option( string $option, mixed $default = false ): mixed {
	return 'cybermaps_settings' === $option ? array( 'exclude_post_ids' => '' ) : '{"disabled":{}}';
}
function maybe_unserialize( mixed $value ): mixed {
	return is_string( $value ) && str_starts_with( $value, 'a:' ) ? unserialize( $value, array( 'allowed_classes' => false ) ) : $value;
}
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
	$GLOBALS['snapshot_hooks'][] = array( $hook, $args );
	foreach ( $GLOBALS['snapshot_callbacks'][ $hook ] ?? array() as $callback ) {
		$value = $callback( $value, ...$args );
	}
	return $value;
}
function esc_html__( string $text, string $domain ): string { return $text; }
final class PublicationSnapshotDatabase {
	public string $options = 'wp_options';
	public string $last_error = '';
	public array $last_result = array();
	public array $stored = array();
	public bool $fail = false;
	public int $reads = 0;
	public function prepare( string $sql, mixed ...$args ): string { return $args[1]; }
	public function get_var( string $option ): mixed {
		++$this->reads;
		$this->last_error = $this->fail ? 'Native-style SELECT failure' : '';
		$raw = $this->fail ? null : ( $this->stored[ $option ] ?? null );
		$this->last_result = null === $raw ? array() : array( (object) array( 'option_value' => $raw ) );
		return $raw;
	}
	public function query( string $sql ): int { return 0; }
}
$GLOBALS['wpdb'] = new PublicationSnapshotDatabase();
$wpdb->stored = array( 'cybermaps_settings' => serialize( array( 'exclude_post_ids' => '7' ) ), 'cybermaps_discovery_center' => '{"disabled":{"post_type:post":true}}' );
use Cybermaps\Core\ConfigurationStore;
$results['old_memo'] = array( ConfigurationStore::settings(), ConfigurationStore::discovery() );
$results['fresh'] = array( ConfigurationStore::publication_settings(), ConfigurationStore::publication_discovery() );
$results['refreshed_memo'] = array( ConfigurationStore::settings(), ConfigurationStore::discovery() );
$results['hooks'] = $GLOBALS['snapshot_hooks'];
$GLOBALS['snapshot_callbacks']['pre_option_cybermaps_settings'][] = static fn( mixed $pre ): array => array( 'exclude_post_ids' => '8' );
$GLOBALS['snapshot_callbacks']['pre_option'][] = static fn( mixed $pre, string $option ): mixed => 'cybermaps_settings' === $option ? array( 'exclude_post_ids' => '9' ) : $pre;
$reads = $wpdb->reads;
$results['pre_short_circuit'] = ConfigurationStore::publication_settings();
$results['pre_short_circuit_reads'] = $wpdb->reads - $reads;
$GLOBALS['snapshot_callbacks'] = array();
unset( $wpdb->stored['cybermaps_settings'] );
$GLOBALS['snapshot_callbacks']['default_option_cybermaps_settings'][] = static fn( mixed $default, string $name, bool $passed ): array => array( 'passed_default' => $passed );
$results['absent_default'] = ConfigurationStore::publication_settings();
$wpdb->fail = true;
try { ConfigurationStore::publication_settings(); $results['sql_failure_unavailable'] = false; }
catch ( \Cybermaps\Core\BuildUnavailableException ) { $results['sql_failure_unavailable'] = true; }
$wpdb->fail = false;
$GLOBALS['snapshot_callbacks'] = array();
$wpdb->stored['cybermaps_settings'] = serialize( array( 'exclude_post_ids' => '7' ) );
$GLOBALS['snapshot_callbacks']['option_cybermaps_settings'][] = static fn( mixed $value, string $name ): array => array( 'filtered' => $value['exclude_post_ids'], 'name' => $name );
$results['present_filter'] = ConfigurationStore::publication_settings();
echo json_encode( $results, JSON_THROW_ON_ERROR ), "\n";
