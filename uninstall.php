<?php
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/src/Autoloader.php';
\Cybermaps\Autoloader::register();

\Cybermaps\Core\Uninstaller::run();
