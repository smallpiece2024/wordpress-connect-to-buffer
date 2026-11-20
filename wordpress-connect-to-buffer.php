<?php
/**
 * Plugin Name:       Connect to Buffer
 * Plugin URI:        https://github.com/smallpiece2024/wordpress-connect-to-buffer
 * Description:       Sends a post to Buffer with its title, URL and featured image when it is published, as a draft or straight into your queue.
 * Version:           0.4.2
 * Requires at least: 5.6
 * Requires PHP:      7.4
 * Author:            Small Piece
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wordpress-connect-to-buffer
 *
 * @package WCTB
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WCTB_VERSION', '0.4.2' );
define( 'WCTB_PLUGIN_FILE', __FILE__ );
define( 'WCTB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once WCTB_PLUGIN_DIR . 'includes/class-wctb-options.php';
require_once WCTB_PLUGIN_DIR . 'includes/class-wctb-api.php';
require_once WCTB_PLUGIN_DIR . 'includes/class-wctb-image.php';
require_once WCTB_PLUGIN_DIR . 'includes/class-wctb-settings.php';
require_once WCTB_PLUGIN_DIR . 'includes/class-wctb-publisher.php';

add_action(
	'plugins_loaded',
	static function () {
		( new WCTB_Publisher() )->register();
		WCTB_Image::register();

		if ( is_admin() ) {
			( new WCTB_Settings() )->register();
		}
	}
);
