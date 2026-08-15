<?php
/**
 * Renders the standalone HTML/CSS maintenance page.
 *
 * This is the alternative to picking a WordPress page. Nothing from the theme
 * loads: no stylesheet, no Bootstrap, no page builder, no wp_head(). That is
 * the point of the mode — it keeps working while the theme is mid-rebuild, or
 * broken, which is exactly when a holding page is most needed.
 *
 * Unlike Bsm_Dropin, this runs with WordPress fully loaded, so ordinary
 * escaping functions are available and the output is a plain .html response
 * rather than a generated .php file. There is no PHP context to escape from
 * here, so no strip_php() equivalent is needed.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Bsm_Custom_Page {

    /**
     * A deliberately small base, printed before the author's CSS so anything
     * they write overrides it. Enough that an unstyled paragraph still reads
     * properly; not so much that it fights a real design.
     */
    private const BASE_CSS = <<<'CSS'
:root { color-scheme: light dark; }
*, *::before, *::after { box-sizing: border-box; }
body {
    margin: 0;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background: #fff;
    color: #3c434a;
    font-family: -apple-system, "system-ui", "Segoe UI", Roboto, Oxygen-Sans,
                 Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
    font-size: 16px;
    line-height: 1.6;
}
.bsm-custom { width: 100%; max-width: 640px; }
h1, h2, h3 { color: #1e1e1e; line-height: 1.25; margin: 0 0 16px; }
p { margin: 0 0 16px; }
p:last-child { margin-bottom: 0; }
img { max-width: 100%; height: auto; }
a { color: #2271b1; }
@media (prefers-color-scheme: dark) {
    body { background: #1d2327; color: #c3c4c7; }
    h1, h2, h3 { color: #f0f0f1; }
    a { color: #72aee6; }
}
CSS;

    /**
     * Build the complete document.
     *
     * $html is already wp_kses_post()'d on save and $css has had "<" stripped,
     * so neither can leave the element it is printed into. $title is escaped
     * here because it lands in <title> as plain text.
     */
    public static function render( string $title, string $html, string $css ): string {
        $title = trim( $title );
        if ( '' === $title ) {
            $title = (string) get_bloginfo( 'name' );
        }

        $body = trim( $html );
        if ( '' === $body ) {
            $body = '<p>' . esc_html__( 'Check back shortly.', 'bs-maintenance' ) . '</p>';
        }

        $lang     = esc_attr( get_bloginfo( 'language' ) );
        $heading  = esc_html( $title );
        $base_css = self::BASE_CSS;
        $extra    = trim( $css );

        return <<<HTML
<!doctype html>
<html lang="{$lang}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{$heading}</title>
<style>
{$base_css}
{$extra}
</style>
</head>
<body class="bsm-maintenance">
<div class="bsm-custom">
{$body}
</div>
</body>
</html>

HTML;
    }
}
