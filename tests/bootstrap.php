<?php
/**
 * PHPUnit bootstrap for Cybermaps (mock WordPress environment).
 */

$test_root = dirname( __DIR__ );
$tmp_abspath = sys_get_temp_dir() . '/cybermaps-phpunit-' . getmypid() . '/';

if ( ! is_dir( $tmp_abspath ) ) {
	mkdir( $tmp_abspath, 0755, true );
}

$wp_admin_includes = $tmp_abspath . 'wp-admin/includes/';
if ( ! is_dir( $wp_admin_includes ) ) {
	mkdir( $wp_admin_includes, 0755, true );
}
if ( ! file_exists( $wp_admin_includes . 'file.php' ) ) {
	file_put_contents( $wp_admin_includes . 'file.php', "<?php\n" );
}
if ( ! file_exists( $wp_admin_includes . 'upgrade.php' ) ) {
	file_put_contents( $wp_admin_includes . 'upgrade.php', "<?php\n" );
}

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', $tmp_abspath );
}

if ( ! defined( 'CYBERMAPS_VERSION' ) ) {
	define( 'CYBERMAPS_VERSION', '6.0.0' );
}

if ( ! defined( 'CYBERMAPS_PLUGIN_DIR' ) ) {
	define( 'CYBERMAPS_PLUGIN_DIR', $test_root . '/' );
}

if ( ! defined( 'CYBERMAPS_PLUGIN_URL' ) ) {
	define( 'CYBERMAPS_PLUGIN_URL', 'https://example.com/wp-content/plugins/cybermaps/' );
}

if ( ! defined( 'CYBERMAPS_PLUGIN_BASENAME' ) ) {
	define( 'CYBERMAPS_PLUGIN_BASENAME', 'cybermaps/cybermaps.php' );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! defined( 'CYBERMAPS_PHPUNIT' ) ) {
	define( 'CYBERMAPS_PHPUNIT', true );
}

if ( file_exists( $test_root . '/vendor/autoload.php' ) ) {
	require_once $test_root . '/vendor/autoload.php';
} else {
	require_once $test_root . '/src/Autoloader.php';
	\Cybermaps\Autoloader::register();
}

require_once $test_root . '/tests/mocks/mock-wp.php';

if ( ! class_exists( 'WP_UnitTestCase', false ) ) {
	/**
	 * Minimal stub when WordPress test suite is not loaded.
	 */
	class WP_UnitTestCase extends \PHPUnit\Framework\TestCase {
		protected function setUp(): void {
			parent::setUp();
			if ( class_exists( \Cybermaps\Core\ConfigurationStore::class, false ) ) {
				\Cybermaps\Core\ConfigurationStore::reset_memo();
			}
		}
	}
}

if ( ! function_exists( 'get_home_path' ) ) {
	function get_home_path() {
		return ABSPATH;
	}
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook ) {
		global $cybermaps_mock_scheduled;
		return isset( $cybermaps_mock_scheduled[ $hook ] ) ? $cybermaps_mock_scheduled[ $hook ] : false;
	}
}

if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( $timestamp, $hook, $args = array(), $wp_error = false ) {
		unset( $args );
		if ( $wp_error && array_key_exists( 'cybermaps_mock_schedule_failure', $GLOBALS ) ) {
			$failure = $GLOBALS['cybermaps_mock_schedule_failure'];
			if ( $failure instanceof \WP_Error ) {
				return $failure;
			}
			if ( $failure ) {
				return false;
			}
		}
		global $cybermaps_mock_scheduled;
		$cybermaps_mock_scheduled[ $hook ] = $timestamp;
		return true;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value, ...$args ) {
		foreach ( (array) ( $GLOBALS['cybermaps_mock_filter_callbacks'][ $tag ] ?? array() ) as $callback ) {
			$value = $callback( $value, ...$args );
		}

		return $value;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {
	}
}

if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( $args = array() ) {
		return array();
	}
}

if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite() {
		global $cybermaps_mock_is_multisite;
		return (bool) $cybermaps_mock_is_multisite;
	}
}

if ( ! function_exists( 'WP_Filesystem' ) ) {
	function WP_Filesystem( $file = '' ) {
	global $wp_filesystem;
	$wp_filesystem = new class() {
		public function is_dir( $path ) {
			return is_dir( $path );
		}

		public function is_writable( $path ) {
			return is_writable( $path );
		}

		public function put_contents( $file, $content, $chmod = 0644 ) {
			$callback = $GLOBALS['cybermaps_mock_wp_filesystem_put_contents_callback'] ?? null;
			if ( is_callable( $callback ) ) {
				$result = $callback( $file, $content, $chmod );
				if ( null !== $result ) {
					return (bool) $result;
				}
			}
			return file_put_contents( $file, $content ) !== false;
		}

		public function move( $source, $dest, $overwrite = false ) {
			if ( $overwrite && file_exists( $dest ) ) {
				wp_delete_file( $dest );
			}
			$moved    = rename( $source, $dest );
			$observer = $GLOBALS['cybermaps_mock_wp_filesystem_move_observer'] ?? null;
			if ( $moved && is_callable( $observer ) ) {
				$observer( $source, $dest, $overwrite );
			}
			return $moved;
		}

		public function exists( $path ) {
			return file_exists( $path );
		}

		public function is_readable( $path ) {
			return is_readable( $path );
		}

		public function get_contents( $path ) {
			return file_get_contents( $path );
		}

		public function size( $path ) {
			return file_exists( $path ) ? filesize( $path ) : false;
		}

		public function delete( $path, $recursive = false, $type = 'f' ) {
			if ( 'd' === $type && is_dir( $path ) ) {
				if ( ! $recursive ) {
					return @rmdir( $path );
				}

				$items = scandir( $path );
				if ( is_array( $items ) ) {
					foreach ( $items as $item ) {
						if ( '.' === $item || '..' === $item ) {
							continue;
						}
						$child = $path . DIRECTORY_SEPARATOR . $item;
						$this->delete( $child, true, is_dir( $child ) ? 'd' : 'f' );
					}
				}
				return rmdir( $path );
			}
			return file_exists( $path ) ? unlink( $path ) : true;
		}
	};
		return true;
	}
}

if ( ! function_exists( 'wp_tempnam' ) ) {
	function wp_tempnam( $filename = '', $dir = '' ) {
		$directory = '' !== $dir ? $dir : sys_get_temp_dir();
		return tempnam( $directory, 'cybermaps-' );
	}
}

if ( ! class_exists( 'WP_REST_Response', false ) ) {
	/**
	 * Minimal REST response wrapper for tests.
	 */
	class WP_REST_Response {
	private $data;
	private $headers = array();
	private $status;

	public function __construct( $data, $status = 200, $headers = array() ) {
		$this->data = $data;
		$this->status = (int) $status;
		$this->headers = $headers;
	}

	public function get_data() {
		return $this->data;
	}

	public function header( $key, $value ) {
		$this->headers[ $key ] = $value;
	}

	public function get_headers() {
		return $this->headers;
	}

	public function get_status() {
		return $this->status;
	}

	public function set_status( $status ) {
		$this->status = (int) $status;
	}

	public function set_data( $data ) {
		$this->data = $data;
	}
	}
}

if ( ! function_exists( 'rest_ensure_response' ) ) {
	function rest_ensure_response( $response ) {
		return new WP_REST_Response( $response, 200 );
	}
}

if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( $path = '' ) {
		$path = ltrim( (string) $path, '/' );
		if ( isset( $GLOBALS['cybermaps_mock_rest_url_callback'] )
			&& is_callable( $GLOBALS['cybermaps_mock_rest_url_callback'] ) ) {
			return (string) call_user_func( $GLOBALS['cybermaps_mock_rest_url_callback'], $path );
		}
		return 'https://example.com/wp-json/' . $path;
	}
}

$GLOBALS['cybermaps_mock_scheduled'] = array();
$GLOBALS['cybermaps_mock_is_multisite'] = false;
$GLOBALS['cybermaps_mock_is_main_site'] = true;
$GLOBALS['cybermaps_mock_current_blog_id'] = 1;
$GLOBALS['cybermaps_mock_blog_stack'] = array();
$GLOBALS['cybermaps_mock_switched_blogs'] = array();
$GLOBALS['cybermaps_mock_site_ids'] = array( 1 );
$GLOBALS['cybermaps_mock_site_ids_by_network'] = array();
$GLOBALS['cybermaps_mock_network_ids'] = array( 1 );
$GLOBALS['cybermaps_mock_current_network_id'] = 1;
$GLOBALS['cybermaps_mock_get_sites_args'] = array();
$GLOBALS['cybermaps_mock_network_option_calls'] = array();
$GLOBALS['cybermaps_mock_network_options_by_network'] = array();
$GLOBALS['cybermaps_mock_site_options'] = array();
$GLOBALS['cybermaps_mock_admin_pages'] = array();
$GLOBALS['cybermaps_mock_options_by_blog'] = array();
$GLOBALS['cybermaps_mock_deleted_network_options'] = array();
$GLOBALS['cybermaps_mock_deleted_post_meta_keys'] = array();
$GLOBALS['cybermaps_mock_deleted_user_meta_keys'] = array();
$GLOBALS['cybermaps_mock_flush_count'] = 0;
$GLOBALS['cybermaps_mock_flushes'] = array();
$GLOBALS['cybermaps_mock_filter_callbacks'] = array();
$GLOBALS['cybermaps_mock_options']    = array(
	'cybermaps_settings' => array(
		'enable_discovery_hub'  => '1',
		'static_engine_mode'     => 'well_known',
	),
);
