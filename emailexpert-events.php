<?php
/**
 * Plugin Name:       emailexpert Events
 * Plugin URI:        https://emailexpert.com/
 * Description:       HeySummit connector: live event display and registration in Lite mode, a full synced mirror with Schema.org markup and webhooks in Full mode, plus WooCommerce, forms and account bridges.
 * Version:           1.16.1
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            emailexpert UK Ltd
 * License:           Proprietary - all rights reserved (private BETA; no warranty, no liability)
 * Text Domain:       emailexpert-events
 *
 * @package Emailexpert\Events
 */

defined( 'ABSPATH' ) || exit;

define( 'EEX_VERSION', '1.16.1' );
define( 'EEX_PLUGIN_FILE', __FILE__ );
define( 'EEX_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EEX_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once EEX_PLUGIN_DIR . 'src/Autoloader.php';
\Emailexpert\Events\Autoloader::register();

register_activation_hook( __FILE__, [ \Emailexpert\Events\Install\Activator::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \Emailexpert\Events\Install\Activator::class, 'deactivate' ] );

add_action( 'plugins_loaded', [ \Emailexpert\Events\Plugin::class, 'boot' ] );
