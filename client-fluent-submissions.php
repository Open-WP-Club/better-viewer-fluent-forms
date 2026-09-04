<?php
/**
 * Plugin Name: Client Fluent Submissions
 * Description: A focused, client-friendly Fluent Forms submissions viewer.
 * Author: OpenWPClub.com
 * Author URI: https://openwpclub.com
 * Plugin URI: https://openwpclub.com/plugins/client-fluent-submissions/
 * Version: 1.1.0
 * Requires PHP: 7.4
 * Text Domain: client-fluent-submissions
 */

defined( 'ABSPATH' ) || exit;

define( 'CFS_VERSION', '1.1.0' );
define( 'CFS_FILE', __FILE__ );
define( 'CFS_DIR', plugin_dir_path( __FILE__ ) );
define( 'CFS_URL', plugin_dir_url( __FILE__ ) );

require_once CFS_DIR . 'includes/config.php';
require_once CFS_DIR . 'includes/submissions.php';
require_once CFS_DIR . 'includes/admin-page.php';

register_activation_hook( __FILE__, array( 'CFS_Admin_Page', 'activate' ) );

add_action( 'plugins_loaded', static function() {
	load_plugin_textdomain( 'client-fluent-submissions', false, 'languages/' );
	CFS_Admin_Page::init();
} );
