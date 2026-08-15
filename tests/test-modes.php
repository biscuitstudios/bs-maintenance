<?php
/**
 * Tests for the 0.2.0 additions: response mode, content source, and the
 * standalone HTML/CSS document.
 *
 * The one with real failure potential:
 *
 *   sanitize_css()  — the value is printed inside <style>, so anything that can
 *                     close that element reaches script context
 *
 * Plain PHP rather than PHPUnit so this runs with no composer install, matching
 * the other two suites. See docs/NOTES.md. Run:
 *
 *   php tests/test-modes.php
 */

define( 'ABSPATH', '/fake/' );

// --- WP stubs: only what the code under test actually touches ---------------

$GLOBALS['bsm_options'] = [];
function get_option( $name, $default = false ) { return $GLOBALS['bsm_options']; }
function wp_parse_args( $args, $defaults = [] ) { return array_merge( $defaults, is_array( $args ) ? $args : [] ); }

function absint( $v ) { return abs( (int) $v ); }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function sanitize_title( $v ) { return strtolower( trim( preg_replace( '/[^a-z0-9]+/i', '-', (string) $v ), '-' ) ); }
function esc_url_raw( $v, $protocols = null ) { return (string) $v; }
function get_post( $id ) { return $GLOBALS['bsm_posts'][ $id ] ?? null; }
function trailingslashit( $v ) { return rtrim( (string) $v, '/\\' ) . '/'; }
function home_url( $path = '' ) { return 'https://example.com' . $path; }

/**
 * Stubbed as identity on purpose. This suite tests THIS plugin's sanitizers,
 * not core's; asserting against a hand-rolled kses would only prove the stub.
 */
function wp_kses_post( $v ) { return (string) $v; }

function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $t, $d = null ) { return esc_html( $t ); }
function get_bloginfo( $show = '' ) { return 'language' === $show ? 'en-GB' : 'Example Site'; }

/** Mirrors core: header lookup is case-insensitive, missing headers give ''. */
function wp_remote_retrieve_header( $response, $header ) {
    $headers = array_change_key_case( (array) ( $response['headers'] ?? [] ), CASE_LOWER );
    return $headers[ strtolower( $header ) ] ?? '';
}

require_once __DIR__ . '/../includes/class-bsm-plugin.php';
require_once __DIR__ . '/../includes/class-bsm-custom-page.php';
require_once __DIR__ . '/../includes/class-bsm-host-check.php';

// --- harness ---------------------------------------------------------------

$fails = 0;
$ran   = 0;

function it( string $label, bool $ok ): void {
    global $fails, $ran;
    $ran++;
    if ( ! $ok ) $fails++;
    printf( "%-6s %s\n", $ok ? '  ok' : 'FAIL', $label );
}

/** Call a private static method on Bsm_Plugin. */
function priv( string $method, array $args = [] ) {
    $ref = new ReflectionMethod( Bsm_Plugin::class, $method );
    $ref->setAccessible( true );
    return $ref->invokeArgs( null, $args );
}

function with_settings( array $settings ): void {
    $GLOBALS['bsm_options'] = $settings;
}

// --- custom CSS ------------------------------------------------------------
//
// Printed inside <style>. Removing "<" is the whole defense; ">" must survive
// because it is the child combinator.

function css( string $raw ): string {
    return (string) priv( 'sanitize_css', [ $raw ] );
}

echo "\n== custom CSS cannot leave the <style> element ==\n";
it( 'closing style tag neutralized',
    false === strpos( css( 'body{}</style><script>alert(1)</script>' ), '<' ) );
it( 'bare script tag neutralized',
    false === strpos( css( '<script>alert(1)</script>' ), '<' ) );
it( 'uppercase closing tag neutralized',
    false === strpos( css( '</STYLE><SCRIPT>x</SCRIPT>' ), '<' ) );
it( 'child combinator survives',
    '.a > .b { color: red }' === css( '.a > .b { color: red }' ) );
it( 'media query survives',
    false !== strpos( css( '@media (max-width: 40em) { .a { display: none } }' ), '@media' ) );
it( 'braces and semicolons survive — this is real CSS',
    '.a { color: red; }' === css( '.a { color: red; }' ) );

// --- mode and source resolution -------------------------------------------

echo "\n== response mode ==\n";
with_settings( [ 'mode' => Bsm_Plugin::MODE_MAINTENANCE ] );
it( 'maintenance resolves to itself',      Bsm_Plugin::MODE_MAINTENANCE === Bsm_Plugin::mode() );
it( 'maintenance answers 503',             503 === Bsm_Plugin::status_code() );
it( 'maintenance is not coming soon',      ! Bsm_Plugin::is_coming_soon() );

with_settings( [ 'mode' => Bsm_Plugin::MODE_COMING_SOON ] );
it( 'coming soon resolves to itself',      Bsm_Plugin::MODE_COMING_SOON === Bsm_Plugin::mode() );
it( 'coming soon answers 200',             200 === Bsm_Plugin::status_code() );
it( 'coming soon reports itself',          Bsm_Plugin::is_coming_soon() );

// A stored value that is neither must never leave the gate in an undefined
// state — it falls back to the safer of the two.
with_settings( [ 'mode' => 'nonsense' ] );
it( 'unknown mode falls back to maintenance', Bsm_Plugin::MODE_MAINTENANCE === Bsm_Plugin::mode() );
with_settings( [] );
it( 'missing mode falls back to maintenance', Bsm_Plugin::MODE_MAINTENANCE === Bsm_Plugin::mode() );

echo "\n== content source ==\n";
with_settings( [ 'source' => Bsm_Plugin::SOURCE_CUSTOM ] );
it( 'custom resolves to itself',           Bsm_Plugin::SOURCE_CUSTOM === Bsm_Plugin::source() );
with_settings( [ 'source' => 'nonsense' ] );
it( 'unknown source falls back to page',   Bsm_Plugin::SOURCE_PAGE === Bsm_Plugin::source() );
with_settings( [] );
it( 'missing source falls back to page',   Bsm_Plugin::SOURCE_PAGE === Bsm_Plugin::source() );

// --- has_content -----------------------------------------------------------
//
// The save handler refuses to switch the gate on when this is false. A wrong
// answer here hides the site behind the plain built-in fallback.

echo "\n== has_content gates the on switch ==\n";
it( 'page source with a page',
    Bsm_Plugin::has_content( [ 'source' => Bsm_Plugin::SOURCE_PAGE, 'page_id' => 12 ] ) );
it( 'page source with no page',
    ! Bsm_Plugin::has_content( [ 'source' => Bsm_Plugin::SOURCE_PAGE, 'page_id' => 0 ] ) );
it( 'custom source with markup',
    Bsm_Plugin::has_content( [ 'source' => Bsm_Plugin::SOURCE_CUSTOM, 'custom_html' => '<p>Hi</p>' ] ) );
it( 'custom source with nothing',
    ! Bsm_Plugin::has_content( [ 'source' => Bsm_Plugin::SOURCE_CUSTOM, 'custom_html' => '' ] ) );
it( 'custom source with only whitespace',
    ! Bsm_Plugin::has_content( [ 'source' => Bsm_Plugin::SOURCE_CUSTOM, 'custom_html' => "  \n\t " ] ) );
it( 'custom source ignores a selected page',
    ! Bsm_Plugin::has_content( [ 'source' => Bsm_Plugin::SOURCE_CUSTOM, 'page_id' => 12, 'custom_html' => '' ] ) );
it( 'page source ignores custom markup',
    ! Bsm_Plugin::has_content( [ 'source' => Bsm_Plugin::SOURCE_PAGE, 'page_id' => 0, 'custom_html' => '<p>Hi</p>' ] ) );

// --- sanitize() round trip -------------------------------------------------

echo "\n== sanitize() ==\n";
$clean = Bsm_Plugin::sanitize( [
    'mode'         => Bsm_Plugin::MODE_COMING_SOON,
    'source'       => Bsm_Plugin::SOURCE_CUSTOM,
    'custom_title' => '  Back soon  ',
    'custom_css'   => 'body{}</style>',
] );

it( 'mode stored',                    Bsm_Plugin::MODE_COMING_SOON === $clean['mode'] );
it( 'source stored',                  Bsm_Plugin::SOURCE_CUSTOM === $clean['source'] );
it( 'title trimmed',                  'Back soon' === $clean['custom_title'] );
it( 'css cannot close its element',   false === strpos( $clean['custom_css'], '<' ) );

$rejected = Bsm_Plugin::sanitize( [ 'mode' => 'evil', 'source' => 'evil' ] );
it( 'unknown mode never stored',      Bsm_Plugin::MODE_MAINTENANCE === $rejected['mode'] );
it( 'unknown source never stored',    Bsm_Plugin::SOURCE_PAGE === $rejected['source'] );

// --- the standalone document ----------------------------------------------

echo "\n== standalone HTML/CSS document ==\n";
$doc = Bsm_Custom_Page::render( 'Back soon', '<p>Hello</p>', '.bsm-custom { color: red }' );

it( 'is a complete document',         0 === strpos( $doc, '<!doctype html>' ) );
it( 'declares the site language',     false !== strpos( $doc, 'lang="en-GB"' ) );
it( 'carries a noindex meta',         false !== strpos( $doc, 'name="robots" content="noindex, nofollow"' ) );
it( 'uses the supplied title',        false !== strpos( $doc, '<title>Back soon</title>' ) );
it( 'contains the body markup',       false !== strpos( $doc, '<p>Hello</p>' ) );
it( 'wraps content in .bsm-custom',   false !== strpos( $doc, 'class="bsm-custom"' ) );
it( 'carries the maintenance body class', false !== strpos( $doc, 'class="bsm-maintenance"' ) );
it( 'author CSS is present',          false !== strpos( $doc, '.bsm-custom { color: red }' ) );

// Order matters: the base only exists so an unstyled paragraph still reads, and
// it must never win against the author's own rules.
it( 'author CSS comes after the base',
    strpos( $doc, '.bsm-custom { color: red }' ) > strpos( $doc, 'box-sizing: border-box' ) );

it( 'title is escaped',
    false !== strpos(
        Bsm_Custom_Page::render( '</title><script>alert(1)</script>', '<p>x</p>', '' ),
        '&lt;/title&gt;'
    ) );

$empty = Bsm_Custom_Page::render( '', '', '' );
it( 'empty title falls back to the site name', false !== strpos( $empty, '<title>Example Site</title>' ) );
it( 'empty body gets a placeholder',           false !== strpos( $empty, 'Check back shortly.' ) );

// --- host cache detection --------------------------------------------------
//
// This is what decides whether the secret access link is safe on a given host,
// so a wrong reading here either scares you off a fine host or, far worse,
// blesses one that will serve a bypassed page view to the whole world.

function cache_state( array $headers ) {
    $ref = new ReflectionMethod( Bsm_Host_Check::class, 'read_cache_state' );
    $ref->setAccessible( true );
    return $ref->invoke( null, [ 'headers' => $headers ] );
}

echo "\n== host cache detection: a stored copy was served ==\n";
foreach ( [
    [ 'x-kinsta-cache' => 'HIT' ],
    [ 'x-kinsta-cache' => 'hit' ],
    [ 'cf-cache-status' => 'HIT' ],
    [ 'x-cache' => 'HIT: 3' ],
    [ 'x-litespeed-cache' => 'hit' ],
    [ 'x-sg-cachehit' => 'cached' ],
    [ 'X-Cache-Status' => 'HIT' ],           // header lookup is case-insensitive
] as $headers ) {
    [ $cached, $name ] = cache_state( $headers );
    it( 'cached: ' . $name . ' = ' . reset( $headers ), true === $cached );
}

echo "\n== host cache detection: the request reached PHP ==\n";
foreach ( [
    [ 'x-kinsta-cache' => 'MISS' ],
    [ 'x-kinsta-cache' => 'BYPASS' ],
    [ 'cf-cache-status' => 'DYNAMIC' ],
    [ 'x-cache' => 'EXPIRED' ],
    [ 'x-proxy-cache' => 'UPDATING' ],
] as $headers ) {
    [ $cached, $name ] = cache_state( $headers );
    it( 'not cached: ' . $name . ' = ' . reset( $headers ), false === $cached );
}

echo "\n== host cache detection: inconclusive ==\n";
[ $none ] = cache_state( [] );
it( 'no cache header at all', null === $none );
[ $odd, $odd_name ] = cache_state( [ 'x-cache' => 'something-else' ] );
it( 'present but unrecognized value', null === $odd && 'x-cache' === $odd_name );
[ $unrelated ] = cache_state( [ 'content-type' => 'text/html' ] );
it( 'unrelated headers ignored', null === $unrelated );

// A chained edge-plus-origin cache reports both words at once. Reading that as
// "not cached" is the dangerous direction: it would clear a host that is in
// fact storing the bypassed page and serving it to everyone.
echo "\n== chained caches resolve toward the safe answer ==\n";
foreach ( [ 'MISS, HIT', 'HIT, MISS', 'BYPASS, HIT' ] as $value ) {
    [ $cached ] = cache_state( [ 'x-cache' => $value ] );
    it( "\"$value\" is treated as cached", true === $cached );
}

echo "\n== companion cookies are sent as a header ==\n";
$ref = new ReflectionMethod( Bsm_Host_Check::class, 'cookie_header' );
$ref->setAccessible( true );
$cookie_header = (string) $ref->invoke( null );

foreach ( Bsm_Plugin::COOKIES_NO_CACHE as $name ) {
    it( "probe sends $name", false !== strpos( $cookie_header, $name . '=1' ) );
}
it( 'probe never sends the bypass token',
    false === strpos( $cookie_header, Bsm_Plugin::COOKIE ) );

echo "\n" . ( $fails
    ? "$fails of $ran checks FAILED\n"
    : "all $ran checks passed\n" );
exit( $fails ? 1 : 0 );
