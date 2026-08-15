<?php
/**
 * Smoke tests for the two gate helpers with real failure potential:
 *
 *   is_excluded_path()  — a wrong answer locks you out of your own dashboard
 *   is_current_url()    — a wrong answer redirects logged-in users forever
 *
 * Plain PHP rather than PHPUnit so this runs with no composer install. See
 * docs/NOTES.md. Run:
 *
 *   php tests/test-gate.php
 */

define( 'ABSPATH', '/fake/' );

// --- WP stubs: only what the code under test actually touches ---------------

function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function untrailingslashit( $v ) { return rtrim( (string) $v, '/\\' ); }
function trailingslashit( $v ) { return untrailingslashit( $v ) . '/'; }
function home_url( $path = '' ) { return 'https://example.com' . $path; }
function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function remove_action( ...$a ) {}

$GLOBALS['nocache_called'] = 0;
function nocache_headers() { $GLOBALS['nocache_called']++; }

$GLOBALS['bsm_options'] = [ 'secret_key' => 'let-me-in', 'enabled_at' => 1700000000 ];
function get_option( $name, $default = false ) { return $GLOBALS['bsm_options']; }
function wp_parse_args( $args, $defaults = [] ) { return array_merge( $defaults, is_array( $args ) ? $args : [] ); }
function wp_hash( $data, $scheme = 'auth' ) { return hash_hmac( 'md5', (string) $data, 'test-salt' ); }
function wp_salt( $scheme = 'auth' ) { return 'test-salt'; }

require_once __DIR__ . '/../includes/class-bsm-plugin.php';
require_once __DIR__ . '/../includes/class-bsm-gate.php';

// --- harness ---------------------------------------------------------------

$fails = 0;
$ran   = 0;

function it( string $label, bool $ok ): void {
    global $fails, $ran;
    $ran++;
    if ( ! $ok ) $fails++;
    printf( "%-6s %s\n", $ok ? '  ok' : 'FAIL', $label );
}

/** Call a private method on Bsm_Gate. */
function call_private( string $method, array $args = [] ) {
    $gate = new Bsm_Gate();
    $ref  = new ReflectionMethod( Bsm_Gate::class, $method );
    $ref->setAccessible( true );
    return $ref->invokeArgs( $gate, $args );
}

function excluded( string $uri ): bool {
    $_SERVER['REQUEST_URI'] = $uri;
    return (bool) call_private( 'is_excluded_path' );
}

// Measured here, reported far below: under the CLI SAPI headers_sent() flips to
// true the moment anything is echoed, and send_no_store_headers() then bails
// out by design. So this has to run before the first line of test output.
$GLOBALS['nocache_called'] = 0;
call_private( 'send_no_store_headers' );
$no_store_sent = 1 === $GLOBALS['nocache_called'];

// --- is_excluded_path ------------------------------------------------------

echo "\n== paths that must NEVER be gated ==\n";
foreach ( [
    '/wp-login.php',
    '/wp-login.php?action=logout&_wpnonce=abc',
    '/wp-admin/',
    '/wp-admin/options-general.php',
    '/WP-ADMIN/index.php',              // case-insensitive
    '/wp-cron.php?doing_wp_cron=1',
    '/wp-admin/async-upload.php',
    '/wp-admin/upgrade.php',
    '/xmlrpc.php',
    '/wp-json/wp/v2/posts',
    '/wp-sitemap.xml',                  // core sitemaps
    '/sitemaps.xml',                    // SEOPress
    '/robots.txt',
    '/feed.xml',
    '/some/path/manifest.json',
    '/wc-api/WC_Gateway_Stripe/',       // gateway callback, pretty permalink
    '/wc-api/wc_authorize_net_cim_credit_card/',
    '/index.php/wc-api/WC_Gateway_Stripe/',
    '/wc-auth/v1/authorize',            // API key authorization
] as $uri ) {
    it( "excluded: $uri", excluded( $uri ) );
}

// --- WooCommerce endpoints signalled by query var, not path -----------------
//
// A gated 503 here can make a payment gateway treat a completed charge as
// failed. WooCommerce also handles these on parse_request:0 and currently
// registers first, but this must not depend on that ordering.

function store_endpoint( array $query_vars, array $get = [] ): bool {
    $_GET = $get;
    $wp = new stdClass();
    $wp->query_vars = $query_vars;
    $gate = new Bsm_Gate();
    $ref = new ReflectionMethod( Bsm_Gate::class, 'is_store_endpoint' );
    $ref->setAccessible( true );
    $result = (bool) $ref->invoke( $gate, $wp );
    $_GET = [];
    return $result;
}

echo "\n== WooCommerce endpoints must never be gated ==\n";
it( 'query var: wc-api (gateway callback)',  store_endpoint( [ 'wc-api' => 'WC_Gateway_Stripe' ] ) );
it( 'query var: wc-ajax',                    store_endpoint( [ 'wc-ajax' => 'checkout' ] ) );
it( 'query var: wc-auth',                    store_endpoint( [ 'wc-auth' => 'v1' ] ) );
it( '$_GET fallback: ?wc-api=',              store_endpoint( [], [ 'wc-api' => 'wc_stripe' ] ) );
it( '$_GET fallback: ?wc-ajax=',             store_endpoint( [], [ 'wc-ajax' => 'get_refreshed_fragments' ] ) );

echo "\n== ordinary requests are not mistaken for store endpoints ==\n";
it( 'no query vars',                  ! store_endpoint( [] ) );
it( 'empty wc-api value',             ! store_endpoint( [ 'wc-api' => '' ] ) );
it( 'unrelated query var',            ! store_endpoint( [ 'page_id' => 12 ] ) );
it( 'unrelated $_GET',                ! store_endpoint( [], [ 'utm_source' => 'news' ] ) );

echo "\n== paths that MUST be gated ==\n";
foreach ( [
    '/',
    '/about/',
    '/shop/product/some-thing/',
    '/donate',
    '/?p=123',
    '/wp-admin-is-not-a-real-page/',    // similar name, not the real dashboard
    '/blog/xmlrpc-explained/',          // contains "xmlrpc" but as an article slug
] as $uri ) {
    it( "gated: $uri", ! excluded( $uri ) );
}

// --- is_current_url (redirect loop guard) ----------------------------------

function same( string $target, string $current ): bool {
    $_SERVER['REQUEST_URI'] = $current;
    return (bool) call_private( 'is_current_url', [ $target ] );
}

echo "\n== loop guard: must report SAME (redirect suppressed) ==\n";
it( 'relative path, exact',            same( '/dashboard/', '/dashboard/' ) );
it( 'relative path, trailing slash',   same( '/dashboard',  '/dashboard/' ) );
it( 'absolute same-host URL',          same( 'https://example.com/dashboard/', '/dashboard/' ) );
it( 'absolute same-host, no slash',    same( 'https://example.com/dashboard',  '/dashboard/' ) );
it( 'root path',                       same( '/', '/' ) );
it( 'current has query string',        same( '/dashboard/', '/dashboard/?ref=x' ) );

echo "\n== loop guard: must report DIFFERENT (redirect proceeds) ==\n";
it( 'different path',                  ! same( '/dashboard/', '/about/' ) );
it( 'external host',                   ! same( 'https://staging.example.net/', '/' ) );
it( 'external host, same path',        ! same( 'https://staging.example.net/about/', '/about/' ) );
it( 'wp-admin target from front page', ! same( '/wp-admin/', '/' ) );
it( 'sub-path is not the same page',   ! same( '/dashboard/', '/dashboard/reports/' ) );

// --- no-store headers on bypassed responses --------------------------------
//
// The failure this guards against is silent and total: without these, a host
// page cache stores the real HTML rendered for the one browser holding the
// bypass cookie and serves it to everyone, so the site is simply live again.
// Confirmed on Kinsta's server cache. See docs/NOTES.md.

$no_store = ( new ReflectionClass( Bsm_Gate::class ) )->getConstant( 'NO_STORE_HEADERS' );

echo "\n== headers that keep a bypassed response out of shared caches ==\n";
it( 'X-Accel-Expires: 0 (nginx; outranks Cache-Control)',
    ( $no_store['X-Accel-Expires'] ?? null ) === '0' );
it( 'X-LiteSpeed-Cache-Control: no-cache',
    ( $no_store['X-LiteSpeed-Cache-Control'] ?? null ) === 'no-cache' );
it( 'no header carries a value that would permit storage',
    ! in_array( '', array_map( 'strval', $no_store ), true ) );

it( 'send_no_store_headers() also calls nocache_headers()', $no_store_sent );

// --- the cache-bypass companion cookies ------------------------------------
//
// Kinsta ignores response headers entirely — it decides from the request, by
// matching cookie NAMES against a fixed list. `bsm_bypass` is not on it, so a
// bypassed page view was answered from cache and served to everyone. The
// companions carry names that are. Two things must stay true: each keeps a
// prefix hosts skip cache on, and none is ever treated as a credential — their
// values are a constant that anybody can send.

echo "\n== cache-bypass companion cookies ==\n";

$companion_prefixes = [ 'wp-postpass_', 'comment_author_', 'wordpress_logged_in_' ];

foreach ( Bsm_Plugin::COOKIES_NO_CACHE as $name ) {
    $matched = false;
    foreach ( $companion_prefixes as $prefix ) {
        if ( 0 === strpos( $name, $prefix ) ) { $matched = true; break; }
    }
    it( "$name keeps a prefix page caches skip on", $matched );

    // Core reads the exact prefix . COOKIEHASH names. Colliding with one would
    // put a value WordPress itself parses into an auth or comment cookie.
    it( "$name cannot collide with a core cookie",
        ! preg_match( '/^(wp-postpass|comment_author|wordpress_logged_in)_[a-f0-9]{32}$/', $name ) );

    it( "$name is not the bypass token itself", $name !== Bsm_Plugin::COOKIE );
}

it( 'more than one name is sent, since host skip-lists differ',
    count( Bsm_Plugin::COOKIES_NO_CACHE ) > 1 );
it( 'the proven Kinsta name is still among them',
    in_array( 'wp-postpass_bsm', Bsm_Plugin::COOKIES_NO_CACHE, true ) );

function bypasses( array $cookies ): bool {
    $_COOKIE = $cookies;
    $ok = (bool) call_private( 'has_bypass_cookie' );
    $_COOKIE = [];
    return $ok;
}

$valid = Bsm_Plugin::bypass_token();

foreach ( Bsm_Plugin::COOKIES_NO_CACHE as $name ) {
    it( "companion $name ALONE grants nothing", ! bypasses( [ $name => '1' ] ) );
}
it( 'all companions together still grant nothing',
    ! bypasses( array_fill_keys( Bsm_Plugin::COOKIES_NO_CACHE, '1' ) ) );

it( 'valid token grants bypass',
    bypasses( [ Bsm_Plugin::COOKIE => $valid ] ) );
it( 'wrong token denied',
    ! bypasses( [ Bsm_Plugin::COOKIE => 'guessed' ] ) );
it( 'the raw secret is not itself the cookie value',
    ! bypasses( [ Bsm_Plugin::COOKIE => 'let-me-in' ] ) );
it( 'no cookies at all denied',
    ! bypasses( [] ) );

// --- token revocation ------------------------------------------------------
//
// Two levers revoke every link already shared. Both must actually change the
// token, or a preview handed out months ago still works.

echo "\n== the bypass token, and what revokes it ==\n";

it( 'no secret set means no token at all',
    '' === ( function () {
        $GLOBALS['bsm_options'] = [ 'secret_key' => '', 'enabled_at' => 1700000000 ];
        $t = Bsm_Plugin::bypass_token();
        $GLOBALS['bsm_options'] = [ 'secret_key' => 'let-me-in', 'enabled_at' => 1700000000 ];
        return $t;
    } )() );

$GLOBALS['bsm_options'] = [ 'secret_key' => 'different-secret', 'enabled_at' => 1700000000 ];
$after_secret_change = Bsm_Plugin::bypass_token();
it( 'changing the secret changes the token', $valid !== $after_secret_change );

$GLOBALS['bsm_options'] = [ 'secret_key' => 'let-me-in', 'enabled_at' => 1800000000 ];
$after_retoggle = Bsm_Plugin::bypass_token();
it( 'switching the gate off and on changes the token', $valid !== $after_retoggle );

it( 'an old cookie no longer validates after a re-toggle', ! bypasses( [ Bsm_Plugin::COOKIE => $valid ] ) );

$GLOBALS['bsm_options'] = [ 'secret_key' => 'let-me-in', 'enabled_at' => 1700000000 ];
it( 'the same inputs give the same token back', $valid === Bsm_Plugin::bypass_token() );
it( 'the token does not leak the secret',
    false === strpos( $valid, 'let-me-in' ) );

// A session cookie is one with no expiry. A fixed TTL was how a browser went on
// skipping the page cache for a day after maintenance ended.
echo "\n== cookies are session-scoped ==\n";
$issue = new ReflectionMethod( Bsm_Gate::class, 'issue_cookie' );
$issue->setAccessible( true );
$body = file_get_contents( __DIR__ . '/../includes/class-bsm-gate.php' );
it( "issue_cookie() sets 'expires' => 0",
    (bool) preg_match( "/'expires'\s*=>\s*0\b/", $body ) );
it( 'no fixed bypass TTL constant survives',
    ! defined( 'Bsm_Plugin::BYPASS_TTL' ) && ! preg_match( '/BYPASS_TTL/', $body ) );

echo "\n" . ( $fails
    ? "$fails of $ran checks FAILED\n"
    : "all $ran checks passed\n" );
exit( $fails ? 1 : 0 );
