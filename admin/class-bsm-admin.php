<?php
/**
 * Admin menu, settings screen, and form handlers.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Bsm_Admin {

    use Bsm_Guards;

    public const SLUG = 'bsm-maintenance';

    public function init(): void {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
        add_action( 'admin_post_' . Bsm_Plugin::SAVE_ACTION, [ $this, 'handle_save' ] );
        add_action( 'admin_post_' . Bsm_Plugin::PREVIEW_ACTION, [ $this, 'handle_preview' ] );
        add_action( 'admin_post_' . Bsm_Plugin::PREVIEW_CUSTOM, [ $this, 'handle_preview_custom' ] );
        add_action( 'admin_post_' . Bsm_Plugin::HOST_CHECK, [ $this, 'handle_host_check' ] );
        add_action( 'admin_notices', [ $this, 'foreign_dropin_notice' ] );
    }

    public function register_menu(): void {
        add_menu_page(
            __( 'Maintenance', 'bs-maintenance' ),
            __( 'Maintenance', 'bs-maintenance' ),
            Bsm_Plugin::CAPABILITY,
            self::SLUG,
            [ $this, 'render_page' ],
            'dashicons-hammer',
            80
        );
    }

    public function enqueue( string $hook ): void {
        if ( 'toplevel_page_' . self::SLUG !== $hook ) return;

        wp_enqueue_style( 'bsm-admin', BSM_URL . 'assets/css/bsm-admin.css', [], BSM_VERSION );

        // Match the admin color scheme the user picked in their profile.
        wp_add_inline_style(
            'bsm-admin',
            '.bsm-wrap{--bsm-accent:' . $this->admin_accent_color() . ';}'
        );
        wp_enqueue_script( 'bsm-admin', BSM_URL . 'assets/js/bsm-admin.js', [ 'jquery' ], BSM_VERSION, true );
        wp_localize_script( 'bsm-admin', 'bsmAdmin', [
            'homeUrl'    => trailingslashit( home_url() ),
            'copied'     => __( 'Copied', 'bs-maintenance' ),
            'copyLink'   => __( 'Copy link', 'bs-maintenance' ),
            'copyFailed' => __( 'Press ⌘C / Ctrl-C', 'bs-maintenance' ),
        ] );
    }

    /**
     * The accent color from the current user's admin color scheme.
     *
     * Core registers each scheme with a four-color palette; index 2 is the
     * accent in every bundled scheme (#2271b1 in the default "fresh"). Falls
     * back through the palette, then to the WP admin blue, so an unregistered
     * or malformed custom scheme can never break the stylesheet.
     */
    private function admin_accent_color(): string {
        $fallback = '#2271b1';

        $scheme = get_user_option( 'admin_color' );
        if ( ! $scheme ) return $fallback;

        $schemes = $GLOBALS['_wp_admin_css_colors'] ?? [];
        $colors  = $schemes[ $scheme ]->colors ?? null;
        if ( ! is_array( $colors ) ) return $fallback;

        foreach ( [ 2, 3, 1, 0 ] as $i ) {
            $candidate = $colors[ $i ] ?? '';
            // Validate before it reaches a stylesheet — this ends up in CSS.
            if ( is_string( $candidate ) && preg_match( '/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $candidate ) ) {
                return $candidate;
            }
        }
        return $fallback;
    }

    public function render_page(): void {
        $settings = Bsm_Plugin::settings();
        require BSM_DIR . 'admin/views/page-settings.php';
    }

    // -------------------------------------------------------------- handlers

    public function handle_save(): void {
        $this->guard_post( Bsm_Plugin::SAVE_ACTION, 'save', 2 );

        $previous = Bsm_Plugin::settings();

        $raw = wp_unslash( $_POST );  // phpcs:ignore WordPress.Security.NonceVerification -- guard_post above
        $settings = Bsm_Plugin::sanitize( is_array( $raw ) ? $raw : [] );

        $notices = [];

        // Refuse to switch on with nothing to show — that would hide the site
        // behind the plain built-in fallback.
        if ( $settings['enabled'] && ! Bsm_Plugin::has_content( $settings ) ) {
            $settings['enabled'] = false;
            $notices[] = Bsm_Plugin::SOURCE_CUSTOM === $settings['source'] ? 'nocustom' : 'nopage';
        }

        // Stamp the moment the gate goes on, and carry the stamp unchanged while
        // it stays on. It is folded into the bypass token, so this is what makes
        // switching maintenance off and on revoke every link already shared —
        // sanitize() would otherwise reset it to the default on every save.
        $settings['enabled_at'] = ( $settings['enabled'] && empty( $previous['enabled'] ) )
            ? time()
            : (int) ( $previous['enabled_at'] ?? 0 );

        Bsm_Plugin::save( $settings );

        $error = Bsm_Dropin::sync();
        if ( '' !== $error ) {
            set_transient( 'bsm_admin_error_' . get_current_user_id(), $error, 60 );
            $notices[] = 'dropin';
        }

        $args = [ 'page' => self::SLUG, 'bsm-updated' => '1' ];
        if ( $notices ) $args['bsm-notice'] = implode( ',', $notices );

        wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
        exit;
    }

    /**
     * Render the generated drop-in so it can be checked without waiting for
     * WordPress to run an actual update.
     */
    public function handle_preview(): void {
        $this->guard_post( Bsm_Plugin::PREVIEW_ACTION );

        $html = Bsm_Dropin::render(
            (string) Bsm_Plugin::get( 'dropin_title' ),
            (string) Bsm_Plugin::get( 'dropin_content' )
        );

        // Strip the leading PHP block — it only sets headers we don't want here.
        $html = preg_replace( '/^.*?\?>\s*/s', '', $html );

        nocache_headers();
        header( 'Content-Type: text/html; charset=utf-8' );
        header( 'X-Robots-Tag: noindex, nofollow' );
        echo $html;  // phpcs:ignore WordPress.Security.EscapingOutput -- generated + escaped in Bsm_Dropin
        exit;
    }

    /**
     * Render the standalone HTML/CSS page as visitors would see it, without
     * having to switch the gate on to look at it.
     */
    public function handle_preview_custom(): void {
        $this->guard_post( Bsm_Plugin::PREVIEW_CUSTOM );

        $html = Bsm_Custom_Page::render(
            (string) Bsm_Plugin::get( 'custom_title' ),
            (string) Bsm_Plugin::get( 'custom_html' ),
            (string) Bsm_Plugin::get( 'custom_css' )
        );

        nocache_headers();
        header( 'Content-Type: text/html; charset=utf-8' );
        header( 'X-Robots-Tag: noindex, nofollow' );
        echo $html;  // phpcs:ignore WordPress.Security.EscapingOutput -- built and escaped in Bsm_Custom_Page
        exit;
    }

    /**
     * Measure whether this host's page cache would leak a bypassed page view.
     *
     * Throttled harder than the other handlers because it makes three outbound
     * HTTP requests to the site's own public URL.
     */
    public function handle_host_check(): void {
        $this->guard_post( Bsm_Plugin::HOST_CHECK, 'hostcheck', 15 );

        set_transient(
            Bsm_Host_Check::TRANSIENT . '_' . get_current_user_id(),
            Bsm_Host_Check::run(),
            15 * MINUTE_IN_SECONDS
        );

        wp_safe_redirect( add_query_arg(
            [ 'page' => self::SLUG, 'bsm-checked' => '1' ],
            admin_url( 'admin.php' )
        ) . '#bsm-host-check' );
        exit;
    }

    public function foreign_dropin_notice(): void {
        if ( ! current_user_can( Bsm_Plugin::CAPABILITY ) ) return;
        if ( ! Bsm_Dropin::is_foreign() ) return;

        echo '<div class="notice notice-warning"><p><strong>Maintenance:</strong> '
            . esc_html__( 'A wp-content/maintenance.php file written by something else is already in place. This plugin will not modify or delete it.', 'bs-maintenance' )
            . '</p></div>';
    }
}
