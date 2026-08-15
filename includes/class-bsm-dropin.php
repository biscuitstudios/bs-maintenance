<?php
/**
 * Generates wp-content/maintenance.php — WordPress's own update screen.
 *
 * Core requires this file at wp-settings.php:79, which is 57 lines BEFORE
 * require_wp_db() and long before formatting.php / functions.php load. So the
 * generated file cannot call get_option(), esc_html(), __(), or any other
 * WordPress function. Everything is baked in and pre-escaped at write time.
 *
 * This is a deliberate, documented exception to PLUGIN_PRIMER.md §6: the path
 * is fixed by core (no random suffix), the file persists (no unlink after
 * serve), and it lives in wp-content. It holds public, non-sensitive copy, so
 * §6's threat model does not apply. See docs/NOTES.md.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Bsm_Dropin {

    /** Marker proving we wrote a given file — we never touch one we didn't. */
    private const SIGNATURE = 'BSM_MAINTENANCE_DROPIN';

    public static function path(): string {
        return WP_CONTENT_DIR . '/maintenance.php';
    }

    public static function exists(): bool {
        return file_exists( self::path() );
    }

    /** True when a drop-in exists that some other plugin (or a human) wrote. */
    public static function is_foreign(): bool {
        return self::exists() && ! self::is_ours();
    }

    public static function is_ours(): bool {
        if ( ! self::exists() ) return false;
        $head = (string) @file_get_contents( self::path(), false, null, 0, 512 );
        return false !== strpos( $head, self::SIGNATURE );
    }

    /**
     * Bring the drop-in in line with current settings.
     *
     * @return string '' on success, or a human-readable reason it was skipped.
     */
    public static function sync(): string {
        if ( self::is_foreign() ) {
            return 'A wp-content/maintenance.php written by something else is already in place. '
                 . 'It was left untouched — remove it manually if you want this plugin to manage that page.';
        }
        return Bsm_Plugin::get( 'dropin_enabled' ) ? self::write() : self::remove();
    }

    /** @return string '' on success, error message otherwise. */
    public static function write(): string {
        $php = self::render(
            (string) Bsm_Plugin::get( 'dropin_title' ),
            (string) Bsm_Plugin::get( 'dropin_content' )
        );

        if ( false === @file_put_contents( self::path(), $php ) ) {
            error_log( '[BSM] Could not write ' . self::path() );
            return 'Could not write wp-content/maintenance.php — check filesystem permissions.';
        }
        return '';
    }

    /** @return string '' on success, error message otherwise. */
    public static function remove(): string {
        if ( ! self::is_ours() ) return '';  // nothing of ours to remove
        if ( ! @unlink( self::path() ) ) {
            error_log( '[BSM] Could not remove ' . self::path() );
            return 'Could not remove wp-content/maintenance.php — check filesystem permissions.';
        }
        return '';
    }

    /**
     * Build the complete, self-contained drop-in.
     *
     * $title is HTML-escaped here; $content is already wp_kses_post()'d on save.
     * Both then have PHP tag delimiters stripped, so no stored value can ever
     * escape the HTML body and execute as code in the generated file.
     */
    public static function render( string $title, string $content ): string {
        $title   = self::strip_php( $title );
        $content = self::strip_php( $content );

        $heading = htmlspecialchars( $title, ENT_QUOTES, 'UTF-8' );
        if ( '' === trim( $heading ) ) {
            $heading = 'Briefly unavailable for scheduled maintenance.';
        }
        $body = trim( $content );
        if ( '' === $body ) {
            $body = '<p>Check back in a minute.</p>';
        }

        $generated = gmdate( 'Y-m-d H:i:s' );
        $signature = self::SIGNATURE;

        return <<<PHP
<?php
/**
 * {$signature} — GENERATED FILE, DO NOT EDIT.
 *
 * Written by the Maintenance plugin (bs-maintenance) on {$generated} UTC.
 * Regenerated whenever its settings are saved; deleted when the option is
 * switched off or the plugin is deactivated.
 *
 * WordPress requires this file during core/plugin/theme updates, before the
 * database is available. No WordPress function may be called here.
 */

if ( ! headers_sent() ) {
    header( 'HTTP/1.1 503 Service Unavailable' );
    header( 'Status: 503 Service Unavailable' );
    header( 'Content-Type: text/html; charset=utf-8' );
    header( 'Retry-After: 600' );
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{$heading}</title>
<style>
    :root { color-scheme: light dark; }
    * { box-sizing: border-box; }
    body {
        margin: 0;
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 24px;
        background: #f0f0f1;
        color: #3c434a;
        font-family: -apple-system, "system-ui", "Segoe UI", Roboto, Oxygen-Sans,
                     Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
        font-size: 16px;
        line-height: 1.6;
    }
    .card {
        background: #fff;
        border-radius: 4px;
        box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.1);
        padding: 48px 40px;
        max-width: 560px;
        width: 100%;
        text-align: center;
    }
    h1 {
        margin: 0 0 16px;
        font-size: 22px;
        font-weight: 600;
        color: #1e1e1e;
        line-height: 1.3;
    }
    p { margin: 0 0 16px; }
    p:last-child { margin-bottom: 0; }
    a { color: #2271b1; }
    @media (prefers-color-scheme: dark) {
        body { background: #1d2327; color: #c3c4c7; }
        .card { background: #2c3338; box-shadow: 0 0 0 1px rgba(255, 255, 255, 0.1); }
        h1 { color: #f0f0f1; }
        a { color: #72aee6; }
    }
</style>
</head>
<body>
<div class="card">
<h1>{$heading}</h1>
{$body}
</div>
</body>
</html>
PHP;
    }

    /**
     * Remove anything that could open a PHP context.
     *
     * The generated file is .php, so a stray "<?" in stored content would open
     * a PHP context inside the HTML body. wp_kses_post() does not reliably
     * strip it, so this runs unconditionally as the last line of defense.
     *
     * Whole blocks go first — dropping only the delimiters would leave the
     * statements behind as visible text on the page. An unterminated block
     * consumes the rest of the string, which is how PHP itself would read it.
     */
    private static function strip_php( string $s ): string {
        $s = (string) preg_replace( '/<\?(?:php|=)?.*?(?:\?>|$)/is', '', $s );
        return str_replace( [ '<?', '?>' ], '', $s );
    }
}
