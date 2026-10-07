<?php
declare(strict_types=1);

namespace Cybermaps\Admin {
	function update_post_meta( $id, $key, $value ) {
		$GLOBALS['translation_mutations'][] = 'pause';
		if ( ! empty( $GLOBALS['translation_fail_write'] ) ) { return false; }
		\update_post_meta( $id, $key, $value );
		return true;
	}
	function delete_post_meta( $id, $key ) {
		$GLOBALS['translation_mutations'][] = 'resume';
		if ( ! empty( $GLOBALS['translation_fail_write'] ) ) { return false; }
		\delete_post_meta( $id, $key );
		return true;
	}
	function get_post_meta( $id, $key, $single = false ) {
		++$GLOBALS['translation_reads'];
		if ( $GLOBALS['translation_reads'] === $GLOBALS['translation_fail_read'] ) {
			$GLOBALS['wpdb']->last_error = 'ordinary simulated metadata read failure';
			return $single ? '' : array();
		}
		return \get_post_meta( $id, $key, $single );
	}
	function wp_die( $message, $title, $args ): never {
		throw new \RuntimeException( $message, $args['response'] );
	}
}

namespace {
	class WP_Error {
		public function __construct( private string $code = '', private string $message = '', private array $data = array() ) {}
		public function get_error_data(): array { return $this->data; }
		public function get_error_message(): string { return $this->message; }
	}
	require dirname( __DIR__, 2 ) . '/bootstrap.php';
	$GLOBALS['wpdb'] = (object) array( 'last_error' => '' );
	$GLOBALS['cybermaps_mock_options'] = array( 'cybermaps_settings' => array( 'enable_translation_integrations' => '1', 'site_language' => 'en-US' ) );
	$GLOBALS['cybermaps_mock_current_user_capabilities'] = array( 'edit_post' );
	$GLOBALS['cybermaps_mock_post_type_objects'] = array( 'post' => (object) array( 'name' => 'post', 'public' => true ) );
	$GLOBALS['cybermaps_mock_posts'] = array( 81 => new \WP_Post( array( 'ID' => 81, 'post_type' => 'post' ) ) );
	$cases = array(
		'unlink_success' => array( 'unlink', '', false, 0, false ),
		'unlink_already_paused' => array( 'unlink', '1', false, 0, false ),
		'resume_success' => array( 'resume', '1', false, 0, false ),
		'resume_already_active' => array( 'resume', '', false, 0, false ),
		'assign_success' => array( 'assign', '1', false, 0, false ),
		'create_success' => array( 'create', '1', false, 0, false ),
		'pause_failure' => array( 'unlink', '', true, 0, false ),
		'resume_failure' => array( 'resume', '1', true, 0, false ),
		'assign_resume_failure' => array( 'assign', '1', true, 0, false ),
		'create_resume_failure' => array( 'create', '1', true, 0, false ),
		'unlink_failure' => array( 'unlink', '', false, 0, true ),
		'initial_read_failure' => array( 'unlink', '', false, 1, false ),
		'verification_read_failure' => array( 'unlink', '', false, 2, false ),
		'classic_failure' => array( 'unlink', '', true, 0, false ),
	);
	$results = array();
	foreach ( $cases as $name => [ $action, $pause, $fail_write, $fail_read, $fail_unlink ] ) {
		$GLOBALS['translation_mutations'] = array();
		$GLOBALS['translation_reads'] = 0;
		$GLOBALS['translation_fail_write'] = $fail_write;
		$GLOBALS['translation_fail_read'] = $fail_read;
		$GLOBALS['cybermaps_mock_post_meta'] = '' === $pause ? array() : array( 81 => array( '_cybermaps_translation_sync_disabled' => $pause ) );
		$registry = new class( $fail_unlink ) extends \Cybermaps\Core\TranslationRegistry {
			public int $group = 0;
			public function __construct( private bool $fail_unlink ) {}
			public function delete_relationship( $site_id, $item_id, $type = 'post' ) {
				$GLOBALS['translation_mutations'][] = 'unlink';
				return ! $this->fail_unlink;
			}
			public function update_relationship( $group_id, $site_id, $item_id, $lang, $type = 'post' ) {
				$GLOBALS['translation_mutations'][] = 'assign';
				return $this->group = 9001;
			}
			public function get_translations( $site_id, $item_id, $type = 'post' ) { return array( array( 'group_id' => $this->group ) ); }
		};
		$panel = new \Cybermaps\Admin\InternationalPanel( $registry );
		$request = new class( $action ) {
			public function __construct( private string $action ) {}
			public function get_param( string $key ): int|string { return match ( $key ) { 'post_id' => 81, 'group_id' => 9001, default => $this->action }; }
		};
		$status = 200;
		$message = '';
		if ( 'classic_failure' === $name ) {
			$_POST = array( 'cybermaps_international_nonce' => wp_create_nonce( 'cybermaps_international_save' ), 'cybermaps_unlink_translation' => '1' );
			try { $panel->save_meta_box_data( 81 ); }
			catch ( \RuntimeException $error ) { $status = $error->getCode(); $message = $error->getMessage(); }
		} else {
			$response = $panel->handle_editor_update( $request );
			$status = is_wp_error( $response ) ? $response->get_error_data()['status'] : $response->get_status();
			$message = is_wp_error( $response ) ? $response->get_error_message() : '';
		}
		$results[$name] = array( 'status' => $status, 'message' => $message, 'pause' => get_post_meta( 81, '_cybermaps_translation_sync_disabled', true ), 'group' => $registry->group, 'mutations' => $GLOBALS['translation_mutations'] );
	}
	echo json_encode( $results );
}
