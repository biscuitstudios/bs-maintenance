<?php
/**
 * Uninstall — remove settings and any drop-in we wrote.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

delete_option( 'bsm_settings' );

// Same signature check the plugin uses at runtime: never delete a drop-in
// written by something else.
$dropin = WP_CONTENT_DIR . '/maintenance.php';
if ( file_exists( $dropin ) ) {
    $head = (string) @file_get_contents( $dropin, false, null, 0, 512 );
    if ( false !== strpos( $head, 'BSM_MAINTENANCE_DROPIN' ) ) {
        @unlink( $dropin );
    }
}
