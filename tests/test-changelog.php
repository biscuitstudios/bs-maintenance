<?php
/**
 * Tests the renderer behind the "View version X details" modal.
 *
 * The modal is the only place a client ever reads a changelog, and nothing
 * about it fails loudly: bad markup is swallowed by core's wp_kses() pass and
 * reads as a formatting quirk rather than a bug. So the shape of the output is
 * asserted here rather than eyeballed on a site.
 *
 * The one with real failure potential:
 *
 *   render_markdown()  — release bodies arrive over the network and land in an
 *                        admin screen, so anything that survives as markup
 *                        reaches an authenticated context
 *
 * Plain PHP rather than PHPUnit so this runs with no composer install, matching
 * the other suites. Run:
 *
 *   php tests/test-changelog.php
 */

define( 'ABSPATH', '/fake/' );
define( 'HOUR_IN_SECONDS', 3600 );

// --- WP stubs: only what the code under test actually touches ---------------

function esc_html( $str ) { return htmlspecialchars( (string) $str, ENT_QUOTES, 'UTF-8' ); }

/**
 * Close enough to the real esc_url() for this renderer: it rejects a scheme it
 * does not recognise and encodes ampersands. NOT a reimplementation, so a pass
 * here says nothing about the real function's stricter handling.
 */
function esc_url( $url ) {
    $url = trim( (string) $url );
    return preg_match( '#^https?://#', $url ) ? str_replace( '&', '&#038;', $url ) : '';
}

require_once __DIR__ . '/../includes/class-bsm-updater.php';

// --- harness ---------------------------------------------------------------

$fails = 0;
$ran   = 0;

function it( string $label, bool $ok, string $detail = '' ): void {
    global $fails, $ran;
    $ran++;
    if ( ! $ok ) $fails++;
    // Detail is printed only on a failure. It is there to show what the
    // renderer actually produced, and printing it on a green run buries the
    // one line that matters.
    printf( "%-6s %s%s\n", $ok ? '  ok' : 'FAIL', $label, ( ! $ok && '' !== $detail ) ? "\n       $detail" : '' );
}

function render( string $markdown ): string {
    return Bsm_Updater::render_markdown( $markdown );
}

/**
 * The newest section of the shipped readme.txt changelog, which is exactly what
 * .github/workflows/release.yml publishes as the release body.
 */
function newest_changelog_section(): string {
    $readme  = (string) file_get_contents( __DIR__ . '/../readme.txt' );
    $section = '';
    $copy    = false;

    foreach ( preg_split( '/\R/', $readme ) as $line ) {
        $heading = 1 === preg_match( '/^= [0-9]+\.[0-9]+\.[0-9]+ =\s*$/', $line );

        if ( $heading && $copy ) break;
        if ( $heading ) { $copy = true; continue; }
        if ( $copy ) $section .= $line . "\n";
    }

    return trim( $section );
}

echo "== block structure ==\n";

it( 'bullets become one list item each',
    '<ul><li>First thing</li><li>Second thing</li></ul>' === render( "* First thing\n* Second thing" ),
    render( "* First thing\n* Second thing" ) );

// The changelog wraps at 78 columns, so nearly every real entry runs to several
// lines. Breaking them apart would cut sentences in half.
$wrapped = render( "* Fix: a thing that needed\n  more than one line to say\n* Second thing" );
it( 'indented lines continue their bullet',
    '<ul><li>Fix: a thing that needed more than one line to say</li><li>Second thing</li></ul>' === $wrapped,
    $wrapped );

$spaced = render( "* First thing\n\n* Second thing" );
it( 'a blank line between bullets keeps one list',
    1 === substr_count( $spaced, '<ul>' ) && 2 === substr_count( $spaced, '<li>' ), $spaced );

it( 'prose after a list closes the list',
    '<ul><li>First thing</li></ul><p>A closing note.</p>' === render( "* First thing\nA closing note." ) );

// GitHub appends its own "## What's Changed" under our notes.
it( 'headings become h4',
    0 === strpos( render( "## What's Changed\n* A thing" ), '<h4>What&#039;s Changed</h4>' ) );

it( 'empty input renders nothing', '' === render( '' ) );

echo "\n== escaping ==\n";

// A release body is text we wrote, but it reaches the site over the network and
// lands in an admin screen. It is never trusted.
$hostile = render( '* <script>alert(1)</script> and <b>bold</b>' );
it( 'markup in the body is escaped, not rendered',
    false === strpos( $hostile, '<script>' )
    && false === strpos( $hostile, '<b>' )
    && false !== strpos( $hostile, '&lt;script&gt;' ),
    $hostile );

echo "\n== inline formatting ==\n";

it( 'code spans and bold',
    '<ul><li>The <code>bsm_thing</code> filter is <strong>new</strong></li></ul>'
    === render( '* The `bsm_thing` filter is **new**' ) );

// Bold run before code spans would eat these.
$stars = render( '* Pass `**` to match everything' );
it( 'asterisks inside a code span survive',
    false !== strpos( $stars, '<code>**</code>' ) && false === strpos( $stars, '<strong>' ), $stars );

it( 'markdown links',
    false !== strpos( render( '* See [the notes](https://example.com/notes)' ),
        '<a href="https://example.com/notes">the notes</a>' ) );

it( 'a bare URL is linked without its trailing stop',
    false !== strpos( render( '* See https://example.com/notes.' ),
        '<a href="https://example.com/notes">https://example.com/notes</a>.' ) );

// The link pass runs once over text holding no <a> yet, so a URL written as a
// Markdown link must not also match the bare-URL branch.
it( 'a URL is linked once, not twice',
    1 === substr_count( render( '* See [notes](https://example.com/notes)' ), '<a href=' ) );

echo "\n== what core will actually keep ==\n";

// Core filters every section through wp_kses() against $plugins_allowedtags in
// wp-admin/includes/plugin-install.php, which permits no attribute except a
// href and silently drops anything else. A style attribute on a <pre> was
// exactly how the previous renderer failed.
$allowed = [ 'p', 'ul', 'li', 'strong', 'em', 'code', 'h4', 'a' ];
$real    = newest_changelog_section();
$html    = render( $real );

preg_match_all( '/<([a-z0-9]+)([^>]*)>/i', $html, $tags, PREG_SET_ORDER );

it( 'the fixture rendered some markup', ! empty( $tags ) );

$bad_tag  = '';
$bad_attr = '';

foreach ( $tags as $tag ) {
    if ( ! in_array( strtolower( $tag[1] ), $allowed, true ) && '' === $bad_tag ) {
        $bad_tag = $tag[1];
    }
    $attrs = trim( $tag[2] );
    if ( '' !== $attrs && ! preg_match( '/^href="[^"]*"$/', $attrs ) && '' === $bad_attr ) {
        $bad_attr = $attrs;
    }
}

it( 'every tag is on core\'s allowlist', '' === $bad_tag, $bad_tag ? "<$bad_tag> is not" : '' );
it( 'no attribute other than href', '' === $bad_attr, $bad_attr ?: '' );

echo "\n== the plugin's own changelog ==\n";

// A positive control. Every check above uses input written for the test, which
// only proves the renderer handles what the test imagines. This one runs the
// shipped changelog through it.
it( 'readme.txt has a newest section to read', '' !== $real );
it( 'every bullet in it became a list item',
    preg_match_all( '/^\* /m', $real ) === substr_count( $html, '<li>' ) );
it( 'no wrapped line was read as prose', false === strpos( $html, '<p>' ) );

echo "\n" . ( $fails
    ? "$fails of $ran checks FAILED\n"
    : "all $ran checks passed\n" );
exit( $fails ? 1 : 0 );
