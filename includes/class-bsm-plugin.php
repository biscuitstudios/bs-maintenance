<?php
/**
 * Shared constants and settings access.
 *
 * Every capability check, nonce action, and option read in this plugin
 * routes through here, so there is exactly one place to change any of them.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class Bsm_Plugin {

    public const CAPABILITY      = 'manage_options';
    public const OPTION          = 'bsm_settings';
    public const SAVE_ACTION     = 'bsm_save_settings';
    public const PREVIEW_ACTION  = 'bsm_preview_dropin';
    public const PREVIEW_CUSTOM  = 'bsm_preview_custom';
    public const HOST_CHECK      = 'bsm_host_check';
    public const COOKIE          = 'bsm_bypass';

    /**
     * What the gated response says about itself.
     *
     * MAINTENANCE is 503 + Retry-After: temporary, come back later, keep the
     * existing pages in the index. COMING_SOON is a plain 200, for a site that
     * has nothing to come back to yet. The distinction is not cosmetic — a site
     * left on 503 for weeks starts losing pages from the index, which is
     * exactly when you want the other one (see docs/DEPLOY.md §8).
     */
    public const MODE_MAINTENANCE = 'maintenance';
    public const MODE_COMING_SOON = 'coming-soon';

    /** Where the gated page's markup comes from. */
    public const SOURCE_PAGE   = 'page';
    public const SOURCE_CUSTOM = 'custom';

    /**
     * Companion cookies whose only job is to make a host's page cache skip the
     * request. They carry the literal value `1`, grant nothing, and are never
     * consulted by the gate — `COOKIE` above is the only thing it ever trusts.
     *
     * The names are the entire mechanism. Managed hosts decide what to cache
     * from the *request*, matching cookie names against a fixed list; response
     * headers are ignored entirely (measured on Kinsta — see docs/NOTES.md).
     * That list is not extensible from PHP, so a cookie has to carry a name
     * already on it.
     *
     * Three are sent rather than one, because the list differs per host and we
     * only get to guess:
     *
     *   wp-postpass_bsm          measured `BYPASS` on Kinsta, and in the
     *                            canonical nginx WordPress skip-cache regex
     *   comment_author_bsm       the other half of that same regex, for hosts
     *                            that carry it but not wp-postpass
     *   wordpress_logged_in_bsm  the one exclusion every WordPress-aware cache
     *                            implements, because it is how "do not cache
     *                            for signed-in users" is expressed
     *
     * All three are matched by prefix. Core reads only the exact
     * `wp-postpass_` / `comment_author_` / `wordpress_logged_in_` . COOKIEHASH
     * names, so a `_bsm` suffix collides with nothing and is invisible to
     * WordPress auth. Worst case a host misreads one and declines to cache the
     * request, which fails toward the maintenance page.
     */
    public const COOKIES_NO_CACHE = [
        'wp-postpass_bsm',
        'comment_author_bsm',
        'wordpress_logged_in_bsm',
    ];

    public const RETRY_AFTER = 3600;  // advertised to crawlers on the 503

    public static function defaults(): array {
        return [
            'enabled'            => false,
            'enabled_at'         => 0,
            'mode'               => self::MODE_MAINTENANCE,
            'source'             => self::SOURCE_PAGE,
            'page_id'            => 0,
            'custom_title'       => '',
            'custom_html'        => '',
            'custom_css'         => '',
            'show_logged_in'     => true,
            'logged_in_redirect' => '',
            'secret_key'         => '',
            'dropin_enabled'     => false,
            'dropin_title'       => '',
            'dropin_content'     => '',
        ];
    }

    public static function settings(): array {
        $stored = get_option( self::OPTION, [] );
        return wp_parse_args( is_array( $stored ) ? $stored : [], self::defaults() );
    }

    /** @return mixed */
    public static function get( string $key ) {
        $settings = self::settings();
        return $settings[ $key ] ?? null;
    }

    public static function save( array $settings ): void {
        update_option( self::OPTION, $settings, false );  // no autoload: read on demand
    }

    public static function is_enabled(): bool {
        return (bool) self::get( 'enabled' );
    }

    public static function mode(): string {
        return self::MODE_COMING_SOON === self::get( 'mode' )
            ? self::MODE_COMING_SOON
            : self::MODE_MAINTENANCE;
    }

    public static function is_coming_soon(): bool {
        return self::MODE_COMING_SOON === self::mode();
    }

    public static function source(): string {
        return self::SOURCE_CUSTOM === self::get( 'source' )
            ? self::SOURCE_CUSTOM
            : self::SOURCE_PAGE;
    }

    /** 503 while maintaining, 200 for a site that has not launched yet. */
    public static function status_code(): int {
        return self::is_coming_soon() ? 200 : 503;
    }

    /**
     * Is there actually something to show visitors?
     *
     * Switching the gate on without this would hide the site behind the plain
     * built-in fallback, so the save handler refuses it.
     */
    public static function has_content( ?array $settings = null ): bool {
        $settings = $settings ?? self::settings();

        if ( self::SOURCE_CUSTOM === ( $settings['source'] ?? self::SOURCE_PAGE ) ) {
            return '' !== trim( (string) ( $settings['custom_html'] ?? '' ) );
        }
        return (bool) ( $settings['page_id'] ?? 0 );
    }

    /**
     * The value the bypass cookie has to carry.
     *
     * Two things are folded in, so two different actions revoke every link
     * already shared:
     *
     *   secret_key   changing it revokes, as it always has
     *   enabled_at   the moment the gate was last switched on, so switching it
     *                off and on again revokes as well
     *
     * The second is what stops a link handed out during one maintenance window
     * still working during the next one, months later. Signed with wp_salt(),
     * so the value cannot be derived from the secret alone by anyone who has
     * seen it in a URL.
     */
    public static function bypass_token(): string {
        $secret = (string) self::get( 'secret_key' );
        if ( '' === $secret ) return '';

        return hash_hmac(
            'sha256',
            $secret . '|' . (int) self::get( 'enabled_at' ),
            wp_salt( 'auth' )
        );
    }

    /**
     * The shareable bypass URL, or '' when no secret is set.
     *
     * Shape matches the reference plugin: https://example.com/?my-secret
     */
    public static function secret_url(): string {
        $secret = (string) self::get( 'secret_key' );
        return '' === $secret ? '' : trailingslashit( home_url() ) . '?' . rawurlencode( $secret );
    }

    /**
     * Validate and normalize a raw settings POST.
     *
     * Sanitizes by input type; escaping happens at each output site.
     */
    public static function sanitize( array $raw ): array {
        $out = self::defaults();

        $out['enabled']        = ! empty( $raw['enabled'] );
        $out['show_logged_in'] = ! empty( $raw['show_logged_in'] );
        $out['dropin_enabled'] = ! empty( $raw['dropin_enabled'] );

        // Unknown values fall back to the default rather than being stored.
        $mode = (string) ( $raw['mode'] ?? '' );
        if ( in_array( $mode, [ self::MODE_MAINTENANCE, self::MODE_COMING_SOON ], true ) ) {
            $out['mode'] = $mode;
        }

        $source = (string) ( $raw['source'] ?? '' );
        if ( in_array( $source, [ self::SOURCE_PAGE, self::SOURCE_CUSTOM ], true ) ) {
            $out['source'] = $source;
        }

        $out['custom_title'] = sanitize_text_field( (string) ( $raw['custom_title'] ?? '' ) );
        $out['custom_html']  = wp_kses_post( (string) ( $raw['custom_html'] ?? '' ) );
        $out['custom_css']   = self::sanitize_css( (string) ( $raw['custom_css'] ?? '' ) );

        // Only accept a published page — anything else would 503 the whole site.
        $page_id = absint( $raw['page_id'] ?? 0 );
        if ( $page_id ) {
            $page = get_post( $page_id );
            if ( $page && 'page' === $page->post_type && 'publish' === $page->post_status ) {
                $out['page_id'] = $page_id;
            }
        }

        $out['logged_in_redirect'] = self::sanitize_redirect( (string) ( $raw['logged_in_redirect'] ?? '' ) );

        // sanitize_title gives lowercase alphanumerics + hyphens — safe as a query key.
        $out['secret_key'] = sanitize_title( (string) ( $raw['secret_key'] ?? '' ) );

        $out['dropin_title']   = sanitize_text_field( (string) ( $raw['dropin_title'] ?? '' ) );
        $out['dropin_content'] = wp_kses_post( (string) ( $raw['dropin_content'] ?? '' ) );

        return $out;
    }

    /**
     * Author-supplied CSS, printed inside a <style> element.
     *
     * Stripping "<" is the whole defense: without it neither "</style>" nor a
     * following "<script>" can be written, so the value cannot leave the style
     * element. ">" is deliberately kept — it is the CSS child combinator, and
     * on its own it cannot terminate anything.
     *
     * What survives is arbitrary CSS, including @import. That is the same trust
     * level as core's own Additional CSS box, and the field is behind
     * manage_options, which already implies the ability to install plugins.
     */
    private static function sanitize_css( string $css ): string {
        return trim( str_replace( '<', '', $css ) );
    }

    /**
     * Accepts either an absolute URL or a site-relative path (e.g. "/wp-admin/").
     */
    private static function sanitize_redirect( string $url ): string {
        $url = trim( $url );
        if ( '' === $url ) return '';

        if ( 0 === strpos( $url, '/' ) ) {
            return esc_url_raw( $url, [ 'http', 'https' ] );
        }
        $clean = esc_url_raw( $url, [ 'http', 'https' ] );
        return $clean ? $clean : '';
    }
}
