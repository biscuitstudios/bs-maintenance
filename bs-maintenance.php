<?php
/**
 * Plugin Name:       Maintenance
 * Plugin URI:        https://github.com/biscuitstudios/bs-maintenance
 * Description:       Hides the site behind a WordPress page you choose, or a standalone HTML page. Maintenance (503) or Coming Soon (200), logged-in bypass, secret access link, and a custom page for WordPress's own update screen.
 * Version:           0.2.0
 * Requires at least: 6.3
 * Requires PHP:      8.2
 * Author:            Biscuit Studios
 * Author URI:        https://biscuitstudios.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bs-maintenance
 * Update URI:        https://github.com/biscuitstudios/bs-maintenance
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'BSM_VERSION',  '0.2.0' );
define( 'BSM_FILE',     __FILE__ );
define( 'BSM_DIR',      plugin_dir_path( __FILE__ ) );
define( 'BSM_URL',      plugin_dir_url( __FILE__ ) );
define( 'BSM_BASENAME', plugin_basename( __FILE__ ) );

spl_autoload_register( function ( $class ) {
    if ( 0 !== strpos( $class, 'Bsm_' ) ) return;
    $base = strtolower( str_replace( '_', '-', $class ) );
    foreach ( [ 'includes/', 'admin/' ] as $dir ) {
        foreach ( [ "class-$base.php", "trait-$base.php" ] as $file ) {
            $path = BSM_DIR . $dir . $file;
            if ( file_exists( $path ) ) { require_once $path; return; }
        }
    }
} );

register_activation_hook( __FILE__, [ 'Bsm_Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'Bsm_Activator', 'deactivate' ] );

add_action( 'plugins_loaded', function () {
    // The gate must init outside is_admin() — it runs on front-end requests.
    ( new Bsm_Gate() )->init();

    if ( is_admin() ) {
        ( new Bsm_Admin() )->init();
    }
} );
