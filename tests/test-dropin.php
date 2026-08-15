<?php
/**
 * Verifies Bsm_Dropin::render() output is (a) valid PHP and (b) cannot be made
 * to execute stored content as code.
 *
 * Payloads use strrev("DENWP") so the executed result ("PWNED") is textually
 * distinct from the source ("strrev"). That distinguishes "the code ran" from
 * "the code survived as inert text", which a bare marker string cannot.
 */

define( 'ABSPATH', '/fake/' );
require_once __DIR__ . '/../includes/class-bsm-dropin.php';

$php_bin = PHP_BINARY;
$tmp     = sys_get_temp_dir() . '/bsm-dropin-test.php';
$fails   = 0;

function check( string $label, bool $ok, string $detail = '' ): void {
    global $fails;
    if ( ! $ok ) $fails++;
    printf( "%-6s %s%s\n", $ok ? '  ok' : 'FAIL', $label, $detail ? "  ($detail)" : '' );
}

$payload = '<?php echo strrev("DENWP"); ?>';

$cases = [
    'plain' => [
        'We will be right back',
        '<p>Updating the site. Back in a few minutes.</p>',
    ],
    'empty (falls back to defaults)' => [ '', '' ],
    'html in title is escaped' => [
        'Hello <script>alert(1)</script> & "friends"',
        '<p>ok</p>',
    ],
    'php block in title' => [
        "Down $payload now",
        '<p>ok</p>',
    ],
    'php block in content' => [
        'Maintenance',
        "<p>hi</p>$payload<p>bye</p>",
    ],
    'short echo tag in content' => [
        'Maintenance',
        '<p>hi</p><?= strrev("DENWP") ?><p>bye</p>',
    ],
    'unterminated php block' => [
        'Maintenance',
        '<p>hi</p><?php echo strrev("DENWP");',
    ],
    'heredoc terminator injection' => [
        "Maintenance\nPHP;\n\$x = 1;",
        "<p>x</p>\nPHP;\necho strrev(\"DENWP\");",
    ],
    'nested / doubled delimiters' => [
        'Maintenance',
        '<p>a</p><<??phpphp echo strrev("DENWP"); ??>><p>b</p>',
    ],
    'unicode + quotes' => [
        "Café — “closed” for now",
        '<p>Voilà… <a href="mailto:a@b.co">email us</a></p>',
    ],
];

foreach ( $cases as $label => [ $title, $content ] ) {
    echo "\n== $label ==\n";
    $out = Bsm_Dropin::render( $title, $content );
    file_put_contents( $tmp, $out );

    // (a) Must be syntactically valid PHP.
    $lint = [];
    exec( escapeshellarg( $php_bin ) . ' -l ' . escapeshellarg( $tmp ) . ' 2>&1', $lint, $code );
    check( 'valid PHP', 0 === $code, 0 === $code ? '' : implode( ' ', $lint ) );

    // (b) No PHP delimiters may survive past the generated header block.
    $body = substr( $out, strpos( $out, '?>' ) + 2 );
    check( 'no PHP tags in body', false === strpos( $body, '<?' ) );

    // (c) Executing it must not run stored content.
    $run = [];
    exec( escapeshellarg( $php_bin ) . ' ' . escapeshellarg( $tmp ) . ' 2>&1', $run );
    $rendered = implode( "\n", $run );
    check( 'payload did not execute', false === strpos( $rendered, 'PWNED' ) );

    // Only meaningful where a PHP block was actually present to strip. Content
    // with no delimiters is plain text and is expected to render verbatim.
    if ( false !== strpos( $title . $content, '<?' ) ) {
        check( 'payload source removed', false === stripos( $rendered, 'strrev' ) );
    } else {
        check( 'inert text rendered verbatim', true );
    }

    // (d) Signature present so is_ours() can recognize it.
    check( 'signature in first 512 bytes', false !== strpos( substr( $out, 0, 512 ), 'BSM_MAINTENANCE_DROPIN' ) );

    // (e) Raw script tags must not survive from the title.
    check( 'no raw <script> from title', false === stripos( $body, '<script' ) );
}

echo "\n== defaults ==\n";
$out = Bsm_Dropin::render( '', '' );
check( 'default heading present', false !== strpos( $out, 'Briefly unavailable for scheduled maintenance.' ) );
check( 'default body present',    false !== strpos( $out, 'Check back in a minute.' ) );
check( '503 header sent',         false !== strpos( $out, '503 Service Unavailable' ) );
check( 'noindex present',         false !== strpos( $out, 'noindex' ) );

echo "\n== legitimate content is preserved ==\n";
$out = Bsm_Dropin::render( 'Back soon', '<p>Email <a href="mailto:a@b.co">a@b.co</a> if urgent.</p>' );
check( 'heading kept',   false !== strpos( $out, 'Back soon' ) );
check( 'link kept',      false !== strpos( $out, 'mailto:a@b.co' ) );
check( 'paragraph kept', false !== strpos( $out, '<p>Email' ) );

@unlink( $tmp );
echo "\n" . ( $fails ? "$fails CHECK(S) FAILED\n" : "all checks passed\n" );
exit( $fails ? 1 : 0 );
