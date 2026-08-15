<?php
/**
 * Settings screen.
 *
 * @var array $settings Current settings, from Bsm_Admin::render_page().
 */

if ( ! defined( 'ABSPATH' ) ) exit;

$is_on      = ! empty( $settings['enabled'] );
$secret_url = Bsm_Plugin::secret_url();
$notices    = isset( $_GET['bsm-notice'] ) ? explode( ',', sanitize_text_field( wp_unslash( $_GET['bsm-notice'] ) ) ) : [];
$updated    = isset( $_GET['bsm-updated'] );
$dropin_err = get_transient( 'bsm_admin_error_' . get_current_user_id() );
if ( $dropin_err ) delete_transient( 'bsm_admin_error_' . get_current_user_id() );

$preview_url = wp_nonce_url(
    add_query_arg( 'action', Bsm_Plugin::PREVIEW_ACTION, admin_url( 'admin-post.php' ) ),
    Bsm_Plugin::PREVIEW_ACTION
);
$custom_preview_url = wp_nonce_url(
    add_query_arg( 'action', Bsm_Plugin::PREVIEW_CUSTOM, admin_url( 'admin-post.php' ) ),
    Bsm_Plugin::PREVIEW_CUSTOM
);

$mode   = Bsm_Plugin::mode();
$source = Bsm_Plugin::source();

$host_check_url = wp_nonce_url(
    add_query_arg( 'action', Bsm_Plugin::HOST_CHECK, admin_url( 'admin-post.php' ) ),
    Bsm_Plugin::HOST_CHECK
);

// Result of the last "Test this host" run, if one is still parked.
$host_check = get_transient( Bsm_Host_Check::TRANSIENT . '_' . get_current_user_id() );
?>
<div class="wrap bsm-wrap">

    <div class="bsm-header">
        <h1><?php esc_html_e( 'Maintenance', 'bs-maintenance' ); ?></h1>
        <span class="bsm-pill <?php echo $is_on ? 'is-on' : 'is-off'; ?>">
            <?php
            if ( ! $is_on ) {
                esc_html_e( 'Site live', 'bs-maintenance' );
            } elseif ( Bsm_Plugin::is_coming_soon() ) {
                esc_html_e( 'Coming soon — 200', 'bs-maintenance' );
            } else {
                esc_html_e( 'Site hidden — 503', 'bs-maintenance' );
            }
            ?>
        </span>
    </div>

    <?php if ( $updated ) : ?>
        <div class="bsm-notice is-success"><p><?php esc_html_e( 'Settings saved.', 'bs-maintenance' ); ?></p></div>
    <?php endif; ?>

    <?php if ( in_array( 'nopage', $notices, true ) ) : ?>
        <div class="bsm-notice is-error">
            <p><?php esc_html_e( 'The gate was left off: choose a published page to show visitors first.', 'bs-maintenance' ); ?></p>
        </div>
    <?php endif; ?>

    <?php if ( in_array( 'nocustom', $notices, true ) ) : ?>
        <div class="bsm-notice is-error">
            <p><?php esc_html_e( 'The gate was left off: write the custom page content first, or switch back to using a WordPress page.', 'bs-maintenance' ); ?></p>
        </div>
    <?php endif; ?>

    <?php if ( $dropin_err ) : ?>
        <div class="bsm-notice is-error"><p><?php echo esc_html( $dropin_err ); ?></p></div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <input type="hidden" name="action" value="<?php echo esc_attr( Bsm_Plugin::SAVE_ACTION ); ?>">
        <?php wp_nonce_field( Bsm_Plugin::SAVE_ACTION ); ?>

        <!-- Maintenance mode ------------------------------------------------>
        <div class="bsm-card">
            <div class="bsm-card-header"><h2><?php esc_html_e( 'Maintenance mode', 'bs-maintenance' ); ?></h2></div>
            <div class="bsm-card-body">

                <div class="bsm-field bsm-field-toggle">
                    <label class="bsm-toggle">
                        <input type="checkbox" name="enabled" value="1" <?php checked( $is_on ); ?>>
                        <span class="bsm-toggle-track"><span class="bsm-toggle-thumb"></span></span>
                        <span class="bsm-toggle-label"><?php esc_html_e( 'Hide the site behind the maintenance page', 'bs-maintenance' ); ?></span>
                    </label>
                    <p class="bsm-help">
                        <?php esc_html_e( 'Visitors see your holding page at every front-end URL. The address bar does not change, and the dashboard and login screen stay reachable.', 'bs-maintenance' ); ?>
                    </p>
                </div>

                <div class="bsm-field">
                    <label for="bsm-mode"><?php esc_html_e( 'What to tell search engines', 'bs-maintenance' ); ?></label>
                    <select name="mode" id="bsm-mode">
                        <option value="<?php echo esc_attr( Bsm_Plugin::MODE_MAINTENANCE ); ?>" <?php selected( $mode, Bsm_Plugin::MODE_MAINTENANCE ); ?>>
                            <?php esc_html_e( 'Maintenance — temporarily down (503)', 'bs-maintenance' ); ?>
                        </option>
                        <option value="<?php echo esc_attr( Bsm_Plugin::MODE_COMING_SOON ); ?>" <?php selected( $mode, Bsm_Plugin::MODE_COMING_SOON ); ?>>
                            <?php esc_html_e( 'Coming soon — not launched yet (200)', 'bs-maintenance' ); ?>
                        </option>
                    </select>
                    <p class="bsm-help">
                        <strong><?php esc_html_e( 'Maintenance', 'bs-maintenance' ); ?></strong>
                        <?php esc_html_e( 'answers 503 with a Retry-After header. That is what Google asks for during downtime, and it protects the pages already in the index. Right for hours or days.', 'bs-maintenance' ); ?>
                    </p>
                    <p class="bsm-help">
                        <strong><?php esc_html_e( 'Coming soon', 'bs-maintenance' ); ?></strong>
                        <?php esc_html_e( 'answers a plain 200, for a site that has not launched or is being rebuilt over weeks. A site left on 503 that long starts dropping out of the index. Either way the holding page itself is marked noindex, so it cannot end up ranking for the client instead of the real site.', 'bs-maintenance' ); ?>
                    </p>
                </div>

                <div class="bsm-field">
                    <label for="bsm-source"><?php esc_html_e( 'What visitors see', 'bs-maintenance' ); ?></label>
                    <select name="source" id="bsm-source">
                        <option value="<?php echo esc_attr( Bsm_Plugin::SOURCE_PAGE ); ?>" <?php selected( $source, Bsm_Plugin::SOURCE_PAGE ); ?>>
                            <?php esc_html_e( 'A WordPress page', 'bs-maintenance' ); ?>
                        </option>
                        <option value="<?php echo esc_attr( Bsm_Plugin::SOURCE_CUSTOM ); ?>" <?php selected( $source, Bsm_Plugin::SOURCE_CUSTOM ); ?>>
                            <?php esc_html_e( 'Custom HTML and CSS', 'bs-maintenance' ); ?>
                        </option>
                    </select>
                </div>

                <!-- Source: a WordPress page ---------------------------------->
                <div class="bsm-dependent" data-depends-on="bsm-source" data-depends-value="<?php echo esc_attr( Bsm_Plugin::SOURCE_PAGE ); ?>">

                    <div class="bsm-field">
                        <label for="bsm-page-id"><?php esc_html_e( 'Holding page', 'bs-maintenance' ); ?></label>
                        <?php
                        wp_dropdown_pages( [
                            'name'              => 'page_id',
                            'id'                => 'bsm-page-id',
                            'selected'          => (int) $settings['page_id'],
                            'show_option_none'  => __( '— Select a page —', 'bs-maintenance' ),
                            'option_none_value' => '0',
                            'post_status'       => 'publish',
                        ] );
                        ?>
                        <p class="bsm-help">
                            <?php esc_html_e( 'Any published page. Build it normally: your theme and page builder render it exactly as they would anywhere else. If you want it without the site header and footer, give it a page template that leaves them out.', 'bs-maintenance' ); ?>
                            <?php if ( (int) $settings['page_id'] ) : ?>
                                <a href="<?php echo esc_url( get_edit_post_link( (int) $settings['page_id'] ) ); ?>"><?php esc_html_e( 'Edit page', 'bs-maintenance' ); ?></a>
                            <?php endif; ?>
                        </p>
                    </div>

                </div>

                <!-- Source: custom HTML and CSS ------------------------------->
                <div class="bsm-dependent" data-depends-on="bsm-source" data-depends-value="<?php echo esc_attr( Bsm_Plugin::SOURCE_CUSTOM ); ?>">

                    <div class="bsm-field">
                        <p class="bsm-help">
                            <strong><?php esc_html_e( 'Nothing from your theme loads in this mode.', 'bs-maintenance' ); ?></strong>
                            <?php esc_html_e( 'No stylesheet, no Bootstrap, no page builder. That is the point of it: this keeps working while the theme is half-built or broken. Style it entirely from the CSS box below.', 'bs-maintenance' ); ?>
                        </p>
                    </div>

                    <div class="bsm-field">
                        <label for="bsm-custom-title"><?php esc_html_e( 'Browser tab title', 'bs-maintenance' ); ?></label>
                        <input type="text" id="bsm-custom-title" name="custom_title"
                               value="<?php echo esc_attr( $settings['custom_title'] ); ?>"
                               placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
                        <p class="bsm-help"><?php esc_html_e( 'Leave empty to use the site name.', 'bs-maintenance' ); ?></p>
                    </div>

                    <div class="bsm-field">
                        <label for="bsm-custom-html"><?php esc_html_e( 'HTML', 'bs-maintenance' ); ?></label>
                        <textarea id="bsm-custom-html" name="custom_html" rows="10" class="bsm-code"
                                  spellcheck="false"><?php echo esc_textarea( $settings['custom_html'] ); ?></textarea>
                        <p class="bsm-help">
                            <?php esc_html_e( 'Goes inside the page body. Scripts, iframes and style tags are stripped on save — put styling in the CSS box instead.', 'bs-maintenance' ); ?>
                        </p>
                    </div>

                    <div class="bsm-field">
                        <label for="bsm-custom-css"><?php esc_html_e( 'CSS', 'bs-maintenance' ); ?></label>
                        <textarea id="bsm-custom-css" name="custom_css" rows="10" class="bsm-code"
                                  spellcheck="false"><?php echo esc_textarea( $settings['custom_css'] ); ?></textarea>
                        <p class="bsm-help">
                            <?php esc_html_e( 'Loaded after a small built-in base, so anything here wins. Your content sits inside a wrapper with the class bsm-custom.', 'bs-maintenance' ); ?>
                            <a href="<?php echo esc_url( $custom_preview_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Preview this page', 'bs-maintenance' ); ?></a>
                            <?php esc_html_e( '— opens the saved version in a new tab.', 'bs-maintenance' ); ?>
                        </p>
                    </div>

                </div>

            </div>
        </div>

        <!-- Access ---------------------------------------------------------->
        <div class="bsm-card">
            <div class="bsm-card-header"><h2><?php esc_html_e( 'Access', 'bs-maintenance' ); ?></h2></div>
            <div class="bsm-card-body">

                <div class="bsm-field bsm-field-toggle">
                    <label class="bsm-toggle">
                        <input type="checkbox" id="bsm-show-logged-in" name="show_logged_in" value="1" <?php checked( ! empty( $settings['show_logged_in'] ) ); ?>>
                        <span class="bsm-toggle-track"><span class="bsm-toggle-thumb"></span></span>
                        <span class="bsm-toggle-label"><?php esc_html_e( 'Show the normal site to logged-in users', 'bs-maintenance' ); ?></span>
                    </label>
                    <p class="bsm-help">
                        <?php esc_html_e( 'Anyone signed in, whatever their role, browses the real site. Turn this off and even administrators see the maintenance page on the front end.', 'bs-maintenance' ); ?>
                    </p>
                </div>

                <div class="bsm-field bsm-dependent" data-depends-on="bsm-show-logged-in">
                    <label for="bsm-logged-in-redirect"><?php esc_html_e( 'Redirect logged-in users to', 'bs-maintenance' ); ?></label>
                    <input type="text" id="bsm-logged-in-redirect" name="logged_in_redirect"
                           value="<?php echo esc_attr( $settings['logged_in_redirect'] ); ?>"
                           placeholder="<?php esc_attr_e( 'e.g. /wp-admin/ or https://staging.example.com', 'bs-maintenance' ); ?>">
                    <p class="bsm-help">
                        <?php esc_html_e( 'Optional. Leave empty and logged-in users simply browse the site. Fill it in and they are sent here instead — useful for pointing staff at a dashboard or a staging copy. A full URL or a path starting with a slash both work.', 'bs-maintenance' ); ?>
                    </p>
                </div>

                <div class="bsm-field">
                    <label for="bsm-secret-key"><?php esc_html_e( 'Secret access link', 'bs-maintenance' ); ?></label>
                    <div class="bsm-input-group">
                        <span class="bsm-input-prefix"><?php echo esc_html( trailingslashit( home_url() ) . '?' ); ?></span>
                        <input type="text" id="bsm-secret-key" name="secret_key"
                               value="<?php echo esc_attr( $settings['secret_key'] ); ?>"
                               placeholder="<?php esc_attr_e( 'preview-full-site', 'bs-maintenance' ); ?>"
                               autocomplete="off" spellcheck="false">
                        <button type="button" class="button bsm-generate"><?php esc_html_e( 'Generate', 'bs-maintenance' ); ?></button>
                        <button type="button" class="button bsm-copy" data-url="<?php echo esc_attr( $secret_url ); ?>"
                                <?php disabled( '' === $secret_url ); ?>><?php esc_html_e( 'Copy link', 'bs-maintenance' ); ?></button>
                    </div>

                    <input type="text" class="bsm-secret-preview" id="bsm-secret-url" readonly tabindex="-1"
                           aria-label="<?php esc_attr_e( 'Full secret access link', 'bs-maintenance' ); ?>"
                           value="<?php echo esc_attr( $secret_url ); ?>"
                           <?php echo '' === $secret_url ? 'hidden' : ''; ?>>
                    <p class="bsm-help">
                        <?php esc_html_e( 'Share this with anyone who needs to see the real site without logging in. Opening it sets a cookie that lasts until they close their browser, then the address is cleaned up so the secret is not passed on in referrer headers.', 'bs-maintenance' ); ?>
                        <strong><?php esc_html_e( 'Generate gives you 12 random characters. A short, guessable phrase is worth very little here.', 'bs-maintenance' ); ?></strong>
                    </p>
                    <p class="bsm-help">
                        <?php esc_html_e( 'Two things revoke every link already shared, immediately: changing or clearing the value above, and switching maintenance off and back on again. There is no per-person revocation.', 'bs-maintenance' ); ?>
                    </p>
                </div>

                <!-- Host cache check ------------------------------------------>
                <div class="bsm-field" id="bsm-host-check">
                    <label><?php esc_html_e( 'Is the secret link safe on this host?', 'bs-maintenance' ); ?></label>
                    <p class="bsm-help">
                        <?php esc_html_e( 'The gate works per browser. A host page cache works per URL, and nothing connects the two. If this host caches HTML and does not stand aside for the plugin\'s cookies, then one use of the secret link stores the real page and serves it to everybody, while this screen still says the site is hidden. Run this before you rely on the link on a host you have not used before.', 'bs-maintenance' ); ?>
                    </p>

                    <?php // A link, not a form: this block sits inside the settings form, and nested forms are dropped by the browser. ?>
                    <a class="button" rel="nofollow" href="<?php echo esc_url( $host_check_url ); ?>"><?php esc_html_e( 'Test this host', 'bs-maintenance' ); ?></a>

                    <?php if ( is_array( $host_check ) ) : ?>
                        <?php
                        $verdict_class = [
                            'safe'     => 'is-success',
                            'unsafe'   => 'is-error',
                            'no-cache' => 'is-success',
                        ][ $host_check['verdict'] ] ?? '';

                        $verdict_label = [
                            'safe'     => __( 'Safe — the host stands aside for the bypass', 'bs-maintenance' ),
                            'unsafe'   => __( 'Not safe — do not use the secret link here', 'bs-maintenance' ),
                            'no-cache' => __( 'No page cache found in front of this site', 'bs-maintenance' ),
                        ][ $host_check['verdict'] ] ?? __( 'Could not tell', 'bs-maintenance' );
                        ?>
                        <div class="bsm-result <?php echo esc_attr( $verdict_class ); ?>">
                            <p class="bsm-result-verdict"><?php echo esc_html( $verdict_label ); ?></p>
                            <?php foreach ( (array) $host_check['evidence'] as $line ) : ?>
                                <p><?php echo esc_html( $line ); ?></p>
                            <?php endforeach; ?>
                            <p class="bsm-result-meta">
                                <?php
                                printf(
                                    /* translators: %s: how long ago the check ran, e.g. "2 minutes" */
                                    esc_html__( 'Checked %s ago. This runs from the server, so it is good evidence but not proof — the curl commands in the deploy runbook are the authority.', 'bs-maintenance' ),
                                    esc_html( human_time_diff( (int) $host_check['checked_at'] ) )
                                );
                                ?>
                            </p>
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>

        <!-- WordPress update screen ----------------------------------------->
        <div class="bsm-card">
            <div class="bsm-card-header"><h2><?php esc_html_e( "WordPress's own update screen", 'bs-maintenance' ); ?></h2></div>
            <div class="bsm-card-body">

                <div class="bsm-field bsm-field-toggle">
                    <label class="bsm-toggle">
                        <input type="checkbox" id="bsm-dropin-enabled" name="dropin_enabled" value="1" <?php checked( ! empty( $settings['dropin_enabled'] ) ); ?>>
                        <span class="bsm-toggle-track"><span class="bsm-toggle-thumb"></span></span>
                        <span class="bsm-toggle-label"><?php esc_html_e( 'Replace the built-in maintenance page', 'bs-maintenance' ); ?></span>
                    </label>
                    <p class="bsm-help">
                        <?php esc_html_e( 'While WordPress installs updates to core, plugins, or themes, it shows everyone — visitors and administrators alike — a plain built-in page for a few seconds. WordPress switches that page on and off by itself; this setting only changes how it looks.', 'bs-maintenance' ); ?>
                    </p>
                    <p class="bsm-help">
                        <strong><?php esc_html_e( 'It runs before the database loads,', 'bs-maintenance' ); ?></strong>
                        <?php esc_html_e( 'so the text below is written into a standalone file each time you save. It cannot contain forms, shortcodes, or anything else that needs WordPress running.', 'bs-maintenance' ); ?>
                    </p>
                </div>

                <div class="bsm-dependent" data-depends-on="bsm-dropin-enabled">
                    <div class="bsm-field">
                        <label for="bsm-dropin-title"><?php esc_html_e( 'Heading', 'bs-maintenance' ); ?></label>
                        <input type="text" id="bsm-dropin-title" name="dropin_title"
                               value="<?php echo esc_attr( $settings['dropin_title'] ); ?>"
                               placeholder="<?php esc_attr_e( 'Briefly unavailable for scheduled maintenance.', 'bs-maintenance' ); ?>">
                    </div>

                    <div class="bsm-field">
                        <label for="bsm_dropin_content"><?php esc_html_e( 'Message', 'bs-maintenance' ); ?></label>
                        <?php
                        wp_editor(
                            $settings['dropin_content'],
                            'bsm_dropin_content',
                            [
                                'textarea_name' => 'dropin_content',
                                'textarea_rows' => 6,
                                'media_buttons' => false,
                                'teeny'         => true,
                                'quicktags'     => true,
                            ]
                        );
                        ?>
                        <p class="bsm-help">
                            <a href="<?php echo esc_url( $preview_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Preview this page', 'bs-maintenance' ); ?></a>
                            <?php esc_html_e( '— opens the saved version in a new tab, so you can check it without waiting for a real update.', 'bs-maintenance' ); ?>
                        </p>
                    </div>
                </div>

            </div>
        </div>

        <p class="bsm-actions">
            <button type="submit" class="button button-primary button-large"><?php esc_html_e( 'Save settings', 'bs-maintenance' ); ?></button>
        </p>
    </form>
</div>
