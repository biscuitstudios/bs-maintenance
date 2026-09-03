<?php
/**
 * Serves plugin updates from the repo's GitHub Releases.
 *
 * WordPress only checks wordpress.org for plugins whose `Update URI` header
 * points there. This plugin's header points at GitHub, so core hands it to the
 * `update_plugins_github.com` filter instead and offers nothing at all unless
 * something answers. That filter defaults to false and core `continue`s on a
 * falsy return, so an unhooked filter is silent: no notice, no error, no log.
 * This class is what makes the update appear on the plugins screen.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Bsm_Updater {

    const REPO = 'biscuitstudios/bs-maintenance';

    const CACHE_KEY = 'bsm_updater_release';

    /**
     * GitHub allows 60 unauthenticated API calls an hour per IP, and client
     * sites on the same host share one. Cache hard, and back off separately
     * after a failure so a rate-limited or offline lookup is not retried on
     * every admin page load.
     */
    const CACHE_TTL = 12 * HOUR_IN_SECONDS;

    const CACHE_TTL_FAILED = 1 * HOUR_IN_SECONDS;

    private $file;
    private $basename;
    private $slug;

    public function __construct( $file ) {
        $this->file     = $file;
        $this->basename = plugin_basename( $file );
        $this->slug     = dirname( $this->basename );
    }

    public function init() {
        add_filter( 'update_plugins_github.com', [ $this, 'check_for_update' ], 10, 3 );
        add_filter( 'plugins_api', [ $this, 'plugin_information' ], 10, 3 );

        // Core clears its own update_plugins transient on "Check Again" and
        // after an install. Ours has a 12 hour life of its own, so without this
        // a release tagged minutes ago stays invisible however many times
        // someone presses the button.
        add_action( 'delete_site_transient_update_plugins', [ $this, 'flush_cache' ] );
    }

    /**
     * Answers core's per-hostname update filter.
     *
     * @param array|false $update      Update data set by a previous filter, or false.
     * @param array       $plugin_data Plugin headers.
     * @param string      $plugin_file Plugin basename being asked about.
     *
     * @return array|false
     */
    public function check_for_update( $update, $plugin_data, $plugin_file ) {
        // This filter fires for EVERY active plugin whose Update URI host is
        // github.com, which on a Biscuit site means all of them. Answer only
        // for this one, and pass anything else straight through: returning
        // false here would discard a sibling plugin's answer and silently
        // break its updates.
        if ( $plugin_file !== $this->basename ) {
            return $update;
        }

        $release = $this->get_release();

        if ( null === $release ) {
            return $update;
        }

        // No version comparison here on purpose. Core compares new_version
        // against the installed header and files the result under response or
        // no_update itself, and it needs the no_update entry to render "You
        // have the latest version" and the auto-update controls.
        return [
            'slug'         => $this->slug,
            'version'      => $release['version'],
            'url'          => $release['url'],
            'package'      => $release['package'],
            'requires'     => isset( $plugin_data['RequiresWP'] ) ? $plugin_data['RequiresWP'] : '',
            'requires_php' => isset( $plugin_data['RequiresPHP'] ) ? $plugin_data['RequiresPHP'] : '',
        ];
    }

    /**
     * Fills the "View version X details" modal.
     *
     * Core forces TB_iframe onto that link, so pointing it at a GitHub release
     * page would open an empty box: GitHub refuses to be framed. Serving the
     * details locally is what makes the link work.
     *
     * @param object|array|false $result The result object or array. Default false.
     * @param string             $action The API action being performed.
     * @param object             $args   Arguments for the API call.
     *
     * @return object|array|false
     */
    public function plugin_information( $result, $action, $args ) {
        if ( 'plugin_information' !== $action ) {
            return $result;
        }

        // Guard both ways: without the slug check this would answer for every
        // plugin on the site, including ones that legitimately come from
        // wordpress.org.
        if ( ! isset( $args->slug ) || $args->slug !== $this->slug ) {
            return $result;
        }

        $release = $this->get_release();

        if ( null === $release ) {
            return $result;
        }

        if ( ! function_exists( 'get_plugin_data' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $data = get_plugin_data( $this->file, false, false );

        $info                = new stdClass();
        $info->name          = $data['Name'];
        $info->slug          = $this->slug;
        $info->version       = $release['version'];
        $info->author        = $data['Author'];
        $info->homepage      = $data['PluginURI'];
        $info->requires      = $data['RequiresWP'];
        $info->requires_php  = $data['RequiresPHP'];
        $info->download_link = $release['package'];
        $info->last_updated  = $release['published'];
        $info->sections      = [
            'description' => wpautop( esc_html( $data['Description'] ) ),
            'changelog'   => $this->render_changelog( $release ),
        ];

        return $info;
    }

    public function flush_cache() {
        delete_site_transient( self::CACHE_KEY );
    }

    /**
     * Returns the latest release as an array, or null when it cannot be
     * established.
     */
    private function get_release() {
        $cached = get_site_transient( self::CACHE_KEY );

        // A failed lookup is cached as a scalar sentinel so it is telling apart
        // from both a hit (array) and a cold cache (false).
        if ( 'failed' === $cached ) {
            return null;
        }

        if ( is_array( $cached ) ) {
            return $cached;
        }

        $release = $this->fetch_release();

        if ( null === $release ) {
            set_site_transient( self::CACHE_KEY, 'failed', self::CACHE_TTL_FAILED );

            return null;
        }

        set_site_transient( self::CACHE_KEY, $release, self::CACHE_TTL );

        return $release;
    }

    private function fetch_release() {
        $response = wp_remote_get(
            'https://api.github.com/repos/' . self::REPO . '/releases/latest',
            [
                'timeout' => 10,
                'headers' => [
                    'Accept'     => 'application/vnd.github+json',
                    'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url( '/' ),
                ],
            ]
        );

        if ( is_wp_error( $response ) ) {
            error_log( '[BSM] Update check failed: ' . $response->get_error_message() );

            return null;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );

        if ( 200 !== $code ) {
            // 403 here is almost always the unauthenticated rate limit rather
            // than a permissions problem, and it clears on its own.
            error_log( '[BSM] Update check failed: HTTP ' . $code . ' from ' . self::REPO );

            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
            error_log( '[BSM] Update check failed: no tag_name in the response for ' . self::REPO );

            return null;
        }

        $version = ltrim( (string) $body['tag_name'], 'vV' );

        if ( ! preg_match( '/^\d+\.\d+\.\d+/', $version ) ) {
            error_log( '[BSM] Update check failed: unusable tag "' . $body['tag_name'] . '"' );

            return null;
        }

        $assets  = ( isset( $body['assets'] ) && is_array( $body['assets'] ) ) ? $body['assets'] : [];
        $package = $this->find_zip_asset( $assets, $version );

        // A release with no built zip attached is not installable. GitHub's own
        // "Source code (zip)" is not an asset, which is the outcome we want:
        // that archive is the repo, not the built plugin.
        if ( '' === $package ) {
            error_log( '[BSM] Update check failed: release ' . $body['tag_name'] . ' has no zip asset.' );

            return null;
        }

        return [
            'version'   => $version,
            'package'   => $package,
            'url'       => isset( $body['html_url'] ) ? (string) $body['html_url'] : '',
            'changelog' => isset( $body['body'] ) ? (string) $body['body'] : '',
            'published' => isset( $body['published_at'] ) ? (string) $body['published_at'] : '',
        ];
    }

    /**
     * Picks the built zip out of a release's attached assets.
     *
     * Prefers the name bin/build.sh produces and falls back to any other zip,
     * so a hand-uploaded asset still works.
     */
    private function find_zip_asset( $assets, $version ) {
        $preferred = $this->slug . '-v' . $version . '.zip';
        $fallback  = '';

        foreach ( $assets as $asset ) {
            if ( ! is_array( $asset ) ) {
                continue;
            }

            $name = isset( $asset['name'] ) ? (string) $asset['name'] : '';
            $url  = isset( $asset['browser_download_url'] ) ? (string) $asset['browser_download_url'] : '';

            if ( '' === $url || ! str_ends_with( strtolower( $name ), '.zip' ) ) {
                continue;
            }

            if ( $name === $preferred ) {
                return $url;
            }

            if ( '' === $fallback ) {
                $fallback = $url;
            }
        }

        return $fallback;
    }

    private function render_changelog( $release ) {
        $body = trim( $release['changelog'] );
        $out  = '';

        if ( '' === $body ) {
            $out .= '<p>' . esc_html__( 'No release notes were published for this version.', 'bs-maintenance' ) . '</p>';
        } else {
            // Release notes are Markdown. Shipping a parser to render one modal
            // is not worth it, so they are escaped and shown as written.
            $out .= '<pre style="white-space:pre-wrap;font-family:inherit;">' . esc_html( $body ) . '</pre>';
        }

        if ( '' !== $release['url'] ) {
            $out .= sprintf(
                '<p><a href="%s" target="_blank" rel="noopener">%s</a></p>',
                esc_url( $release['url'] ),
                esc_html__( 'View this release on GitHub', 'bs-maintenance' )
            );
        }

        return $out;
    }
}
