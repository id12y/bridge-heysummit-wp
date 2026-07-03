<?php
/**
 * PHPUnit bootstrap: loads a lightweight WordPress stub layer and the plugin
 * autoloader. These are unit tests; the same suite also runs inside wp-env
 * where real WordPress wins over the stubs (each stub is function_exists
 * guarded).
 *
 * @package Emailexpert\Events\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/wp/' );
}

if ( ! is_dir( ABSPATH . 'wp-admin/includes' ) ) {
	mkdir( ABSPATH . 'wp-admin/includes', 0777, true );
}
if ( ! file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
	file_put_contents( ABSPATH . 'wp-admin/includes/upgrade.php', "<?php\n" );
}

define( 'EEX_VERSION', '1.0.0-test' );
define( 'EEX_PLUGIN_FILE', dirname( __DIR__ ) . '/emailexpert-events.php' );
define( 'EEX_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'EEX_PLUGIN_URL', 'https://example.test/wp-content/plugins/emailexpert-events/' );

define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );
define( 'MONTH_IN_SECONDS', 2592000 );
define( 'YEAR_IN_SECONDS', 31536000 );

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/src/Autoloader.php';

\Emailexpert\Events\Autoloader::register();
