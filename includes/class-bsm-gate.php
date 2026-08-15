<?php
/**
 * Front-end gating.
 *
 * Hooks parse_request and rewrites the query vars to the chosen page, so the
 * theme, template hierarchy, and any page builder run exactly as if the
 * visitor had requested that page — while the URL stays where it was.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Bsm_Gate {

    /** Paths that must never be gated, or the site becomes unrecoverable. */
    private const NEVER_GATE = [
        '/wp-login.php',
        '/wp-admin/',
        '/wp-cron.php',
        '/async-upload.php',
        '/upgrade.php',
        '/xmlrpc.php',
        '/wp-json/',
        '/wc-api/',   // WooCommerce gateway callbacks (pretty-permalink form)
        '/wc-auth/',  // WooCommerce API key authorization
    ];

    /** Extensions treated as non-HTML: feeds, sitemaps, robots.txt, API payloads. */
    private const NEVER_GATE_EXT = [ '.xml', '.txt', '.json' ];

    /**
     * Sent alongside nocache_headers() on every response that skips the gate.
     *
     * The gate is per-browser (a cookie); a host-level full-page cache is
     * per-URL. Without protection, the real HTML rendered for the one browser
     * holding the bypass cookie gets stored by the host cache and served to
     * *everybody* until it expires — the whole site quietly goes live.
     *
     * These headers are the second line of defense, not the first. A managed
     * host decides what to cache from the **request**, before PHP has produced
     * a header to read: Kinsta stores and replays a response that says
     * `Cache-Control: no-store` and ignores `X-Accel-Expires` with it. What
     * actually saves the site there is Bsm_Plugin::COOKIES_NO_CACHE, on the
     * request side. These still hold on Varnish, Cloudflare, LiteSpeed and the
     * WordPress caching plugins, so both mechanisms ship.
     */
    private const NO_STORE_HEADERS = [
        'X-Accel-Expires'           => '0',
        'X-LiteSpeed-Cache-Control' => 'no-cache',
    ];


    public function init(): void {
        add_action( 'parse_request', [ $this, 'maybe_gate' ], 0 );
        add_action( 'admin_bar_menu', [ $this, 'admin_bar_node' ], 100 );

        // wp_safe_redirect() refuses off-site hosts. Allow exactly the one an
        // administrator configured — nothing else.
        add_filter( 'allowed_redirect_hosts', [ $this, 'allow_configured_host' ] );
    }

    public function maybe_gate( $wp ): void {
        if ( ! Bsm_Plugin::is_enabled() ) return;
        if ( $this->is_system_request() ) return;
        if ( $this->is_excluded_path() ) return;
        if ( $this->is_store_endpoint( $wp ) ) return;

        // Feeds are resolved into query vars by the time parse_request fires.
        if ( isset( $wp->query_vars['feed'] ) ) return;

        $this->maybe_consume_secret();          // may redirect + exit

        // Every path below this point serves the real site to someone while the
        // public is gated, so every one of them must stay out of shared caches.
        if ( $this->has_bypass_cookie() ) {
            $this->top_up_no_cache_cookies();
            $this->send_no_store_headers();
            return;
        }

        // Logged-in users need no companion cookie: they already carry
        // wordpress_logged_in_<hash>, which is the one exclusion every
        // WordPress-aware page cache implements.
        if ( is_user_logged_in() && Bsm_Plugin::get( 'show_logged_in' ) ) {
            $this->maybe_redirect_logged_in();  // may redirect + exit
            $this->send_no_store_headers();
            return;                             // otherwise: normal site
        }

        $this->serve( $wp );
    }

    // ---------------------------------------------------------------- checks

    private function is_system_request(): bool {
        if ( is_admin() ) return true;
        if ( wp_doing_cron() ) return true;
        if ( wp_doing_ajax() ) return true;
        if ( defined( 'WP_CLI' ) && WP_CLI ) return true;
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return true;
        if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) return true;
        return false;
    }

    private function is_excluded_path(): bool {
        $path = (string) wp_parse_url(
            sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ),
            PHP_URL_PATH
        );
        $path = strtolower( $path );
        if ( '' === $path ) return false;

        foreach ( self::NEVER_GATE as $needle ) {
            if ( false !== strpos( $path, $needle ) ) return true;
        }
        foreach ( self::NEVER_GATE_EXT as $ext ) {
            if ( substr( $path, -strlen( $ext ) ) === $ext ) return true;
        }
        return false;
    }

    /**
     * WooCommerce endpoints that must keep working while the front end is gated.
     *
     * Gateway callbacks (Stripe, Authorize.Net) arrive as `?wc-api=…` or
     * `/wc-api/…`. Serving one a 503 can make a gateway treat a completed
     * charge as failed, or retry it — so these are never gated.
     *
     * WooCommerce handles them on parse_request at priority 0 as well, and
     * today it registers first (it hooks plugins_loaded at -1, this plugin at
     * 10) and calls die() before this gate runs. That ordering is invisible
     * and would break silently if either side changed, so the exclusion is
     * stated here rather than inherited from hook registration order.
     *
     * `wc-ajax` additionally defines DOING_AJAX at init:0, so is_system_request()
     * already covers it; it is listed anyway so the intent survives a refactor.
     */
    private function is_store_endpoint( $wp ): bool {
        foreach ( [ 'wc-api', 'wc-ajax', 'wc-auth' ] as $var ) {
            if ( ! empty( $wp->query_vars[ $var ] ) ) return true;
            // phpcs:ignore WordPress.Security.NonceVerification -- read-only routing check
            if ( ! empty( $_GET[ $var ] ) ) return true;
        }
        return false;
    }

    // ---------------------------------------------------------------- bypass

    /**
     * Grant a bypass cookie when the request carries the secret as a query key,
     * then redirect to the clean URL so the secret doesn't leak through Referer
     * headers or analytics on the next navigation.
     */
    private function maybe_consume_secret(): void {
        $secret = (string) Bsm_Plugin::get( 'secret_key' );
        if ( '' === $secret ) return;

        $matched = false;
        foreach ( array_keys( $_GET ) as $key ) {  // phpcs:ignore WordPress.Security.NonceVerification
            if ( hash_equals( $secret, (string) $key ) ) { $matched = true; break; }
        }
        if ( ! $matched ) return;

        $this->set_bypass_cookie();
        $this->set_no_cache_cookies();   // without these the next request is answered from cache, never reaching PHP
        $this->send_no_store_headers();  // a cached 302 would hand out the redirect without the cookies
        wp_safe_redirect( remove_query_arg( $secret ) );
        exit;
    }

    /**
     * Top up the cache-bypass cookies for a browser that already holds a valid
     * bypass token but is missing one of the companions — a cookie jar cleared
     * selectively, or an upgrade from a version with fewer names.
     *
     * Note what this deliberately does *not* do: re-issue Bsm_Plugin::COOKIE.
     * That token is a shared secret, and a `Set-Cookie` carrying it on an
     * ordinary page view is one careless cache away from being handed to every
     * visitor. It is issued exactly once, on the redirect in
     * maybe_consume_secret(), and never spoken again. 0.1.3 got this wrong and
     * made the leak survive a cache purge.
     */
    private function top_up_no_cache_cookies(): void {
        foreach ( Bsm_Plugin::COOKIES_NO_CACHE as $name ) {
            if ( ! isset( $_COOKIE[ $name ] ) ) {
                $this->set_no_cache_cookies();
                return;
            }
        }
    }

    /**
     * Best-effort only, and the weaker of the two mechanisms.
     *
     * Kinsta stores and replays a response carrying
     * `Cache-Control: no-cache, no-store, private` — measured, not assumed — so
     * on that host these headers do nothing and the companion cookies are what save
     * the site. They still work on Varnish, Cloudflare, LiteSpeed and the
     * WordPress caching plugins, and cost nothing, so they stay.
     *
     * Called from parse_request, long before any output, so headers_sent() is
     * only ever true when something else has already broken.
     */
    private function send_no_store_headers(): void {
        if ( headers_sent() ) return;

        nocache_headers();
        foreach ( self::NO_STORE_HEADERS as $name => $value ) {
            header( $name . ': ' . $value );
        }
    }

    private function set_bypass_cookie(): void {
        $this->issue_cookie( Bsm_Plugin::COOKIE, Bsm_Plugin::bypass_token() );
    }

    /** Values are a constant. Anything that reads one as a credential is a bug. */
    private function set_no_cache_cookies(): void {
        foreach ( Bsm_Plugin::COOKIES_NO_CACHE as $name ) {
            $this->issue_cookie( $name, '1' );
        }
    }

    /**
     * Session cookies — `expires` 0, gone when the browser closes.
     *
     * This is deliberate on both cookies. The token is a shared secret, so the
     * fewer browsers carrying one at any moment the better, and a preview
     * handed out on Monday should not still be live on Wednesday. The
     * companions matter for the opposite reason: they make a browser skip the
     * page cache, and on a fixed expiry they went on doing that for a day after
     * maintenance ended.
     *
     * Caveat worth knowing: Chrome and Firefox restore session cookies when
     * "continue where you left off" is on, so this bounds exposure rather than
     * guaranteeing it. Rotating the secret, or switching the gate off and on,
     * is still the only hard revocation.
     */
    private function issue_cookie( string $name, string $value ): void {
        if ( headers_sent() ) return;

        setcookie( $name, $value, [
            'expires'  => 0,
            'path'     => COOKIEPATH ? COOKIEPATH : '/',
            'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ] );
    }

    /**
     * The cookie carries Bsm_Plugin::bypass_token(), which folds in both the
     * secret and the moment the gate was last switched on. So changing the
     * secret revokes every issued link, and so does switching maintenance off
     * and back on.
     */
    private function has_bypass_cookie(): bool {
        $token = Bsm_Plugin::bypass_token();
        if ( '' === $token ) return false;

        $cookie = $_COOKIE[ Bsm_Plugin::COOKIE ] ?? '';
        return is_string( $cookie ) && '' !== $cookie && hash_equals( $token, $cookie );
    }

    // ------------------------------------------------------------ logged-in

    private function maybe_redirect_logged_in(): void {
        $url = (string) Bsm_Plugin::get( 'logged_in_redirect' );
        if ( '' === $url ) return;

        // Without this, an on-site target redirects to itself forever.
        if ( $this->is_current_url( $url ) ) return;

        $this->send_no_store_headers();  // this 302 is for logged-in users only
        wp_safe_redirect( $url );
        exit;
    }

    private function is_current_url( string $target ): bool {
        $parts = wp_parse_url( $target );
        if ( ! is_array( $parts ) ) return false;

        $home_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
        if ( ! empty( $parts['host'] ) && strtolower( $parts['host'] ) !== $home_host ) {
            return false;  // different host — no loop possible
        }

        $current = (string) wp_parse_url(
            sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ),
            PHP_URL_PATH
        );
        $target_path = $parts['path'] ?? '/';

        return untrailingslashit( $current ) === untrailingslashit( $target_path );
    }

    public function allow_configured_host( $hosts ) {
        $url  = (string) Bsm_Plugin::get( 'logged_in_redirect' );
        $host = $url ? wp_parse_url( $url, PHP_URL_HOST ) : '';
        if ( $host ) $hosts[] = $host;
        return $hosts;
    }

    // ----------------------------------------------------------------- serve

    private function serve( $wp ): void {
        if ( Bsm_Plugin::SOURCE_CUSTOM === Bsm_Plugin::source() ) {
            $this->serve_custom();  // exits
        }

        $page_id = (int) Bsm_Plugin::get( 'page_id' );
        $page    = $page_id ? get_post( $page_id ) : null;

        if ( ! $page || 'page' !== $page->post_type || 'publish' !== $page->post_status ) {
            $this->serve_fallback();  // exits
        }

        // Render the chosen page in place of whatever was requested.
        $wp->query_vars = [ 'page_id' => $page_id ];

        // Canonical redirection would bounce every URL to the page's permalink.
        remove_action( 'template_redirect', 'redirect_canonical' );
        add_filter( 'redirect_canonical', '__return_false' );

        add_action( 'template_redirect', [ $this, 'send_headers' ], 0 );
        add_filter( 'body_class', [ $this, 'body_class' ] );
        add_filter( 'wp_robots', [ $this, 'robots' ] );
    }

    /**
     * The standalone HTML/CSS page. Nothing from the theme runs, so this ends
     * the request itself rather than handing off to the template hierarchy.
     */
    private function serve_custom(): void {
        $html = (string) Bsm_Plugin::get( 'custom_html' );

        if ( '' === trim( $html ) ) {
            $this->serve_fallback();  // exits
        }

        $document = Bsm_Custom_Page::render(
            (string) Bsm_Plugin::get( 'custom_title' ),
            $html,
            (string) Bsm_Plugin::get( 'custom_css' )
        );

        $this->send_headers();
        if ( ! headers_sent() ) {
            header( 'Content-Type: text/html; charset=utf-8' );
        }

        echo $document;  // phpcs:ignore WordPress.Security.EscapingOutput -- built and escaped in Bsm_Custom_Page
        exit;
    }

    /**
     * A cached 503 keeps serving after maintenance is switched off — the worse
     * of the two cache failures, because nobody looks for a cache problem when
     * maintenance is already off. Same no-store treatment as a bypassed page.
     *
     * Coming Soon answers 200 instead, and drops Retry-After with it: there is
     * nothing to retry, the site simply has not launched. The X-Robots-Tag is
     * what keeps that 200 out of the index. It goes out as a header rather than
     * relying on the meta tag alone because an SEO plugin can replace core's
     * robots output, and none of them can touch this.
     */
    public function send_headers(): void {
        status_header( Bsm_Plugin::status_code() );  // guards headers_sent() itself

        if ( ! headers_sent() ) {
            if ( ! Bsm_Plugin::is_coming_soon() ) {
                header( 'Retry-After: ' . Bsm_Plugin::RETRY_AFTER );
            }
            header( 'X-Robots-Tag: noindex, nofollow' );
        }
        $this->send_no_store_headers();
    }

    /**
     * Only ever added on a gated request, never on a bypassed one — a noindex
     * that reached the real site would be a great deal worse than anything
     * this plugin is protecting against.
     */
    public function robots( $robots ) {
        $robots['noindex']  = true;
        $robots['nofollow'] = true;
        unset( $robots['index'], $robots['follow'] );
        return $robots;
    }

    public function body_class( $classes ) {
        $classes[] = 'bsm-maintenance';
        $classes[] = 'bsm-mode-' . Bsm_Plugin::mode();
        return $classes;
    }

    /**
     * Shown when the configured page is missing, unpublished, or never set —
     * or when custom mode is on with nothing written in it. Deliberately
     * reveals nothing about the real site.
     *
     * This stays a 503 in Coming Soon mode too. Reaching here means the
     * configuration is broken rather than the site being unlaunched, and 503 is
     * the honest answer to that. The `[BSM]` log line is how you find out.
     */
    private function serve_fallback(): void {
        error_log( '[BSM] The gate is on but there is nothing to show (source: '
            . Bsm_Plugin::source() . ', page id: ' . (int) Bsm_Plugin::get( 'page_id' )
            . '). Served the fallback notice.' );

        $this->send_no_store_headers();
        if ( ! headers_sent() ) {
            header( 'Retry-After: ' . Bsm_Plugin::RETRY_AFTER );
            header( 'X-Robots-Tag: noindex, nofollow' );
        }
        wp_die(
            '<h1>' . esc_html__( 'Briefly unavailable', 'bs-maintenance' ) . '</h1>'
            . '<p>' . esc_html__( 'This site is undergoing maintenance. Please check back shortly.', 'bs-maintenance' ) . '</p>',
            esc_html__( 'Briefly unavailable', 'bs-maintenance' ),
            [ 'response' => 503 ]
        );
    }

    // ------------------------------------------------------------- admin bar

    public function admin_bar_node( $bar ): void {
        if ( ! Bsm_Plugin::is_enabled() ) return;
        if ( ! current_user_can( Bsm_Plugin::CAPABILITY ) ) return;

        $bar->add_node( [
            'id'    => 'bsm-status',
            'title' => '⚠ ' . ( Bsm_Plugin::is_coming_soon()
                ? __( 'Coming Soon mode is ON', 'bs-maintenance' )
                : __( 'Maintenance mode is ON', 'bs-maintenance' ) ),
            'href'  => admin_url( 'admin.php?page=bsm-maintenance' ),
            'meta'  => [ 'title' => __( 'The public site is hidden behind the holding page.', 'bs-maintenance' ) ],
        ] );
    }
}
