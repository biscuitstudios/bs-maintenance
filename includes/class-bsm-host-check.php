<?php
/**
 * Ask the host, over HTTP, whether the secret access link is safe here.
 *
 * The failure this exists for is silent and total. The gate is per-browser (a
 * cookie); a host page cache is per-URL. If the host caches HTML and does not
 * skip on any of the companion cookie names, then the real page rendered for
 * one person holding the secret link is stored and served to everyone, and the
 * admin screen still says maintenance is on. Kinsta did exactly this in July
 * 2026.
 *
 * Until now the answer was "the plugin cannot detect this from inside
 * WordPress", with two curl commands in DEPLOY.md as the manual substitute.
 * That is true of a request that never leaves PHP — so this does not stay
 * inside. It makes real HTTP requests to the site's own public URL and reads
 * what comes back, which is the same measurement, run by the plugin instead of
 * by a person who has to remember.
 *
 * What it cannot promise: the loopback request has to traverse the same edge a
 * visitor would. It normally does, because it resolves the public hostname. A
 * host that short-circuits its own outbound requests, or a server hosts-file
 * override, would make a caching host look uncached. So "no cache found" is
 * reported as weaker evidence than the other two verdicts, and the manual curl
 * commands stay in the runbook as the authority.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Bsm_Host_Check {

    /** Where the last result is parked for the settings screen to render. */
    public const TRANSIENT = 'bsm_host_check';

    /**
     * Response headers that report a full-page cache's decision.
     *
     * Value substrings, not exact matches — hosts write 'HIT', 'Hit',
     * 'HIT: 3', 'cached' and so on.
     */
    private const CACHE_HEADERS = [
        'x-kinsta-cache',
        'ki-cache-type',
        'ki-cf-cache-status',
        'cf-cache-status',
        'x-cache',
        'x-cache-status',
        'x-proxy-cache',
        'x-nginx-cache',
        'x-fastcgi-cache',
        'x-litespeed-cache',
        'x-sg-cachehit',
        'x-hs-cf-cache-status',
    ];

    private const HIT_VALUES  = [ 'hit', 'cached' ];
    private const MISS_VALUES = [ 'miss', 'bypass', 'expired', 'dynamic', 'no-cache', 'updating' ];

    /**
     * Run the probe and return a structured verdict.
     *
     * @return array{verdict:string,caches:?bool,skips:?bool,evidence:string[],checked_at:int}
     */
    public static function run(): array {
        $result = [
            'verdict'    => 'unknown',
            'caches'     => null,
            'skips'      => null,
            'evidence'   => [],
            'checked_at' => time(),
        ];

        // A URL nothing else will have warmed, so the first fetch is honestly a
        // miss. Whether it 404s or returns the gated page does not matter — what
        // is being measured is whether the response gets stored.
        $url = home_url( '/bsm-cache-probe-' . wp_generate_password( 12, false, false ) . '/' );

        $first = self::fetch( $url );
        if ( is_wp_error( $first ) ) {
            $result['evidence'][] = sprintf(
                'Could not reach %s — %s',
                $url,
                $first->get_error_message()
            );
            $result['evidence'][] = 'The site could not fetch its own public URL, so nothing was measured. '
                . 'Run the curl commands in DEPLOY.md §2 by hand instead.';
            return $result;
        }

        $second = self::fetch( $url );
        if ( is_wp_error( $second ) ) {
            $result['evidence'][] = 'The second request failed — ' . $second->get_error_message();
            return $result;
        }

        // Does anything cache HTML here? The second fetch of an unchanged URL
        // reporting a hit is the proof.
        [ $cached, $header, $value ] = self::read_cache_state( $second );

        // Some caches report nothing at all and only reveal themselves through
        // Age, which counts the seconds a stored copy has been held.
        $age = (int) wp_remote_retrieve_header( $second, 'age' );

        if ( null === $cached && $age > 0 ) {
            $cached = true;
            $header = 'age';
            $value  = (string) $age;
        }

        if ( true !== $cached ) {
            $result['caches']     = false;
            $result['verdict']    = 'no-cache';
            $result['evidence'][] = $header
                ? sprintf( 'Two requests to a cold URL, and the host still reported %s: %s.', $header, $value )
                : 'Two requests to a cold URL, and no response carried a cache-status header or an Age.';
            $result['evidence'][] = 'No full-page cache was found in front of this site, so the secret link '
                . 'has nothing to leak through. Worth re-running if the host adds caching or a CDN later.';
            return $result;
        }

        $result['caches']     = true;
        $result['evidence'][] = sprintf( 'This host caches HTML — the second request to a cold URL reported %s: %s.', $header, $value );

        // It caches. The only question left is whether our companion cookies
        // make it stand aside.
        $third = self::fetch( $url, self::cookie_header() );
        if ( is_wp_error( $third ) ) {
            $result['evidence'][] = 'The cookie request failed — ' . $third->get_error_message();
            return $result;
        }

        [ $cached_with_cookies, $c_header, $c_value ] = self::read_cache_state( $third );

        if ( false === $cached_with_cookies ) {
            $result['skips']      = true;
            $result['verdict']    = 'safe';
            $result['evidence'][] = sprintf(
                'With the companion cookies attached it reported %s: %s instead, so the request reaches PHP.',
                $c_header,
                $c_value
            );
            $result['evidence'][] = 'The secret access link is safe to use on this host.';
            return $result;
        }

        $result['skips']      = false;
        $result['verdict']    = 'unsafe';
        $result['evidence'][] = null === $cached_with_cookies
            ? 'With the companion cookies attached the host reported nothing conclusive, so it cannot be shown to stand aside.'
            : sprintf( 'With the companion cookies attached it still reported %s: %s.', $c_header, $c_value );
        $result['evidence'][] = 'Do not use the secret access link on this host. A single use would store the real '
            . 'page against that URL and serve it to every visitor until the cache expires. Ask the host to exclude '
            . 'the bsm_bypass cookie from the page cache, then run this again.';

        return $result;
    }

    // ------------------------------------------------------------- internals

    /** @return array|WP_Error */
    private static function fetch( string $url, string $cookies = '' ) {
        $args = [
            'timeout'     => 12,
            'redirection' => 0,      // a redirect would measure a different URL
            'headers'     => [],
            // The probe URL is this site's own public address; a self-signed
            // certificate on a staging box should not fail the whole check.
            'sslverify'   => ! self::is_local_host(),
        ];

        if ( '' !== $cookies ) {
            $args['headers']['Cookie'] = $cookies;
        }

        return wp_remote_get( $url, $args );
    }

    private static function cookie_header(): string {
        $pairs = [];
        foreach ( Bsm_Plugin::COOKIES_NO_CACHE as $name ) {
            $pairs[] = $name . '=1';
        }
        return implode( '; ', $pairs );
    }

    /**
     * Read a cache-status header out of a response.
     *
     * @return array{0:?bool,1:string,2:string} true = stored copy served,
     *         false = went to PHP, null = the host said nothing either way.
     */
    private static function read_cache_state( $response ): array {
        foreach ( self::CACHE_HEADERS as $header ) {
            $value = wp_remote_retrieve_header( $response, $header );
            if ( '' === $value || ! is_string( $value ) ) continue;

            $needle = strtolower( $value );

            // HIT is checked first, and the order matters. Chained caches report
            // both at once ("MISS, HIT" for an edge miss over an origin hit), and
            // of the two ways to be wrong, calling a caching host uncached is the
            // one that hands out a leaking secret link. So any hint of a stored
            // copy is treated as a hit: on the first probe that means the cookie
            // test runs, and on the cookie probe it means the verdict is unsafe.
            foreach ( self::HIT_VALUES as $hit ) {
                if ( false !== strpos( $needle, $hit ) ) return [ true, $header, $value ];
            }
            foreach ( self::MISS_VALUES as $miss ) {
                if ( false !== strpos( $needle, $miss ) ) return [ false, $header, $value ];
            }

            return [ null, $header, $value ];  // present but unrecognized
        }
        return [ null, '', '' ];
    }

    /** Local development hostnames, where certificate checks are noise. */
    private static function is_local_host(): bool {
        $host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
        foreach ( [ '.local', '.test', '.localhost', 'localhost', '127.0.0.1' ] as $suffix ) {
            if ( $host === $suffix || substr( $host, -strlen( $suffix ) ) === $suffix ) return true;
        }
        return false;
    }
}
