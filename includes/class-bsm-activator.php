<?php
/**
 * Activation / deactivation.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Bsm_Activator {

    public static function activate(): void {
        if ( false === get_option( Bsm_Plugin::OPTION, false ) ) {
            Bsm_Plugin::save( Bsm_Plugin::defaults() );
        }
    }

    /**
     * Deactivating stops the gate automatically (the hooks stop firing), but a
     * leftover drop-in would keep overriding WordPress's update screen with a
     * file nothing is maintaining. Remove it — but only if we wrote it.
     */
    public static function deactivate(): void {
        Bsm_Dropin::remove();
    }
}
