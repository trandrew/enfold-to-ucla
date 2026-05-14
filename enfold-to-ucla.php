<?php
/**
 * Plugin Name: Enfold to UCLA
 * Description: Convert Enfold shortcodes into UCLA-ready WordPress blocks.
 * Version: 0.1.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: UCLA Web Team
 * Text Domain: enfold-to-ucla
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ETU_PLUGIN_FILE', __FILE__ );
define( 'ETU_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ETU_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ETU_PLUGIN_VERSION', '0.1.0' );

require_once ETU_PLUGIN_DIR . 'includes/class-etu-layout-parser.php';
require_once ETU_PLUGIN_DIR . 'includes/class-etu-layout-converter.php';
require_once ETU_PLUGIN_DIR . 'includes/class-etu-plugin.php';

ETU_Plugin::init();
