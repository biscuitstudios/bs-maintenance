<?php
/**
 * Tests the changelog the details modal builds: which release gets offered,
 * which versions are listed, and where each version's notes come from.
 *
 * One call to the releases list answers both questions, so the parsing here is
 * load-bearing for updates as well as for the modal. A mistake that drops the
 * newest release stops every site being offered anything, silently, which is
 * the exact failure this updater exists to end.
 *
 * The one with real failure potential:
 *
 *   fetch_release()  — drafts and prereleases are skipped here now, a job
 *                      releases/latest used to do for us
 *
 * Plain PHP rather than PHPUnit so this runs with no composer install, matching
 * the other suites. Run:
 *
 *   php tests/test-changelog-history.php
 */

define( 'ABSPATH', '/fake/' );
define( 'HOUR_IN_SECONDS', 3600 );

// --- WP stubs: only what the code under test actually touches ---------------

function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( $t, $d = '' ) { return esc_html( $t ); }
function esc_url( $u ) { $u = trim( (string) $u ); return preg_match( '#^https?://#', $u ) ? str_replace( '&', '&#038;', $u ) : ''; }
function plugin_basename( $f ) { return basename( dirname( $f ) ) . '/' . basename( $f ); }
function get_bloginfo( $w = '' ) { return '7.1'; }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function is_wp_error( $t ) { return false; }

// The response body is whatever a check put in $GLOBALS['http']. Nothing here
// reaches the network.
function wp_remote_get( $url, $args = array() ) {
    $GLOBALS['http_url'] = $url;
    return array( 'code' => 200, 'body' => $GLOBALS['http'] ?? '' );
}
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }

// Fixed to UTC so a heading's date does not depend on the machine running the
// suite. The real wp_date() uses the site's timezone.
function wp_date( $format, $ts = null, $tz = null ) { return gmdate( $format, $ts ?? time() ); }

require_once __DIR__ . '/../includes/class-bsm-updater.php';

// --- harness ---------------------------------------------------------------

$fails = 0;
$ran   = 0;

function it( string $label, bool $ok, string $detail = '' ): void {
    global $fails, $ran;
    $ran++;
    if ( ! $ok ) $fails++;
    // Detail is printed only on a failure. Printing it on a green run buries
    // the one line that matters.
    printf( "%-6s %s%s\n", $ok ? '  ok' : 'FAIL', $label, ( ! $ok && '' !== $detail ) ? "\n       $detail" : '' );
}

const MAIN = __DIR__ . '/../bs-maintenance.php';

/** Calls a private method on a real instance. */
function call( $updater, string $method, array $args = array() ) {
    $ref = new ReflectionMethod( Bsm_Updater::class, $method );
    $ref->setAccessible( true );
    return $ref->invokeArgs( $updater, $args );
}

/** One release as the GitHub API returns it. */
function api_release( string $tag, string $published, string $body = '* A thing', array $overrides = array() ): array {
    return array_merge(
        array(
            'tag_name'     => $tag,
            'published_at' => $published,
            'body'         => $body,
            'html_url'     => 'https://github.com/biscuitstudios/bs-maintenance/releases/tag/' . $tag,
            'draft'        => false,
            'prerelease'   => false,
            'assets'       => array(
                array(
                    'name'                 => 'bs-maintenance-v' . ltrim( $tag, 'v' ) . '.zip',
                    'browser_download_url' => 'https://example.test/' . $tag . '.zip',
                ),
            ),
        ),
        $overrides
    );
}

function fetch( array $releases ) {
    $GLOBALS['http'] = (string) json_encode( $releases );
    return call( new Bsm_Updater( MAIN ), 'fetch_release' );
}

function render( array $log, bool $truncated = false ): string {
    return call( new Bsm_Updater( MAIN ), 'render_changelog', array( array(
        'log'          => $log,
        'truncated'    => $truncated,
        'releases_url' => 'https://github.com/biscuitstudios/bs-maintenance/releases',
    ) ) );
}

echo "== which release is offered ==\n";

$r = fetch( array(
    api_release( 'v0.6.0', '2026-09-14T10:00:00Z' ),
    api_release( 'v0.5.0', '2026-09-07T10:00:00Z' ),
) );
it( 'the newest release is offered', '0.6.0' === $r['version'], $r['version'] ?? 'null' );
it( 'its built zip is the package', 'https://example.test/v0.6.0.zip' === $r['package'] );

// This is what releases/latest did for us, and moving to the list endpoint
// moved the job here. Getting it wrong offers every site something never meant
// to ship.
$r = fetch( array(
    api_release( 'v0.9.0', '2026-09-16T10:00:00Z', '* A thing', array( 'draft' => true ) ),
    api_release( 'v0.8.0', '2026-09-15T10:00:00Z', '* A thing', array( 'prerelease' => true ) ),
    api_release( 'v0.6.0', '2026-09-14T10:00:00Z' ),
) );
it( 'drafts and prereleases are skipped', '0.6.0' === $r['version'] && 1 === count( $r['log'] ) );

$r = fetch( array(
    api_release( 'nightly', '2026-09-16T10:00:00Z' ),
    api_release( 'v0.6.0', '2026-09-14T10:00:00Z' ),
) );
it( 'a tag that is not a version is skipped', '0.6.0' === $r['version'] );

// GitHub's own "Source code (zip)" is not an asset, so a release with no built
// zip is not installable.
$r = fetch( array( api_release( 'v0.6.0', '2026-09-14T10:00:00Z', '* A thing', array( 'assets' => array() ) ) ) );
it( 'a newest release with no zip offers nothing', null === $r );

it( 'an empty list offers nothing', null === fetch( array() ) );

echo "\n== the log list ==\n";

$r = fetch( array(
    api_release( 'v0.6.0', '2026-09-14T10:00:00Z' ),
    api_release( 'v0.5.0', '2026-09-07T10:00:00Z' ),
    api_release( 'v0.4.0', '2026-09-03T10:00:00Z' ),
) );
it( 'the log is newest first', array( '0.6.0', '0.5.0', '0.4.0' ) === array_column( $r['log'], 'version' ) );
it( 'each entry carries its publication date', '2026-09-14T10:00:00Z' === $r['log'][0]['published'] );

// The log goes into a site transient, and every asset of every release is a
// large amount of JSON nothing reads back.
it( 'assets are not kept in the cached log', ! array_key_exists( 'assets', $r['log'][0] ) );

// A capped list that says nothing reads as the whole history.
$many = array();
for ( $i = 20; $i > 0; $i-- ) { $many[] = api_release( 'v1.0.' . $i, '2026-09-14T10:00:00Z' ); }
$r = fetch( $many );
it( 'more releases than the cap are reported as truncated', true === $r['truncated'] && 10 === count( $r['log'] ) );

$r = fetch( array( api_release( 'v0.6.0', '2026-09-14T10:00:00Z' ) ) );
it( 'a short history is not truncated', false === $r['truncated'] );

// 60 unauthenticated calls an hour per IP, shared by client sites on the same
// host. Asking releases/latest as well would double that.
it( 'one request serves both jobs', false !== strpos( $GLOBALS['http_url'], '/releases?per_page=11' ), $GLOBALS['http_url'] );

echo "\n== the compare link ==\n";

$r = fetch( array( api_release(
    'v0.6.0',
    '2026-09-14T10:00:00Z',
    "* A thing\n\n**Full Changelog**: https://github.com/biscuitstudios/bs-maintenance/compare/v0.5.0...v0.6.0"
) ) );
it( 'a trailing compare link is stripped', '* A thing' === $r['log'][0]['notes'], $r['log'][0]['notes'] );

// Only a trailing one is GitHub's. One in the middle was written by a person.
$body = "* See **Full Changelog**: https://example.com/x for detail\n* A second thing";
$r = fetch( array( api_release( 'v0.6.0', '2026-09-14T10:00:00Z', $body ) ) );
it( 'a compare link written into the notes survives', $body === $r['log'][0]['notes'] );

echo "\n== the markup ==\n";

$html = render( array(
    array( 'version' => '0.6.0', 'published' => '2026-09-14T10:00:00Z', 'notes' => '* A thing' ),
    array( 'version' => '0.5.0', 'published' => '2026-09-07T10:00:00Z', 'notes' => '* Another thing' ),
) );
it( 'every version gets a heading with its date',
    false !== strpos( $html, '<h3>0.6.0 | September 14, 2026</h3>' )
    && false !== strpos( $html, '<h3>0.5.0 | September 7, 2026</h3>' ), $html );

// GitHub's generated notes carry their own "What's Changed" heading. At the
// same level it reads as another version rather than part of one.
$html = render( array( array( 'version' => '0.3.0', 'published' => '2026-09-02T10:00:00Z', 'notes' => "## What's Changed\n* A thing" ) ) );
it( 'a version heading outranks a heading inside release notes',
    false !== strpos( $html, '<h3>0.3.0 | September 2, 2026</h3>' )
    && false !== strpos( $html, '<h4>What&#039;s Changed</h4>' ), $html );

$html = render( array( array( 'version' => '0.6.0', 'published' => '', 'notes' => '* A thing' ) ) );
it( 'a version with no date still gets a heading', false !== strpos( $html, '<h3>0.6.0</h3>' ), $html );

// Everything tagged before the changelog went into the release body has an
// empty body once the compare link is stripped. This is what stops the modal
// reading as a column of blanks.
$html = render( array( array( 'version' => '0.5.0', 'published' => '2026-09-07T10:00:00Z', 'notes' => '' ) ) );
it( 'notes fall back to the shipped readme',
    false === strpos( $html, 'No release notes were published' ) && false !== strpos( $html, '<li>' ), $html );

$html = render( array( array( 'version' => '9.9.9', 'published' => '2026-09-07T10:00:00Z', 'notes' => '' ) ) );
it( 'a version missing from both sources says so', false !== strpos( $html, 'No release notes were published' ) );

$full = render( array( array( 'version' => '0.6.0', 'published' => '', 'notes' => '* A thing' ) ) );
$cut  = render( array( array( 'version' => '0.6.0', 'published' => '', 'notes' => '* A thing' ) ), true );
it( 'the footer link names what was left out',
    false !== strpos( $full, 'View all releases on GitHub' ) && false !== strpos( $cut, 'Earlier releases are on GitHub' ) );

// The regression guard for the horizontal scroll. Core's wp_kses() allows <pre>
// but strips every attribute, so a <pre> here cannot be made to wrap.
$html = render( array( array( 'version' => '0.6.0', 'published' => '2026-09-14T10:00:00Z',
    'notes' => '* A very long line that would otherwise run off the side of the modal and force the reader to scroll' ) ) );
it( 'nothing renders a pre tag', false === strpos( $html, '<pre' ) );

echo "\n== the readme read ==\n";

// Positive control. Every check above uses input written for the test; this one
// reads the file the plugin actually ships.
$sections = call( new Bsm_Updater( MAIN ), 'readme_changelog' );
it( 'the shipped readme parses', ! empty( $sections ) && isset( $sections['0.5.0'] ) );
it( 'a shipped section starts with a bullet', isset( $sections['0.5.0'] ) && 0 === strpos( $sections['0.5.0'], '*' ) );

// Changelog is last in all three readmes today, so nothing real exercises the
// terminator. An "== Upgrade Notice ==" section repeats the same "= 1.0.0 ="
// headings, and those would otherwise overwrite the real entry.
$dir = sys_get_temp_dir() . '/bsm-readme-test-' . uniqid();
mkdir( $dir );
file_put_contents( $dir . '/readme.txt',
    "== Changelog ==\n\n= 1.0.0 =\n* A thing\n\n== Upgrade Notice ==\n\n= 1.0.0 =\nDo not swallow this.\n" );
$sections = call( new Bsm_Updater( $dir . '/plugin.php' ), 'readme_changelog' );
unlink( $dir . '/readme.txt' );
rmdir( $dir );
it( 'the changelog read stops at the next readme section', '* A thing' === ( $sections['1.0.0'] ?? '' ), $sections['1.0.0'] ?? 'missing' );

$sections = call( new Bsm_Updater( sys_get_temp_dir() . '/definitely-not-here/plugin.php' ), 'readme_changelog' );
it( 'a missing readme is not fatal', array() === $sections );

echo "\n" . ( $fails
    ? "$fails of $ran checks FAILED\n"
    : "all $ran checks passed\n" );
exit( $fails ? 1 : 0 );
