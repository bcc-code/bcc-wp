<?php

/**
 * Sends a daily report about this site to the widgets API: the plugin, WordPress and PHP versions and the
 * number of users and subscribers, so BCC can see which sites need updating or cleaning up. Versions and
 * counts only, no personal data.
 *
 * The report is signed with a token for the site's own login client (Auth0 client credentials), which is
 * how the widgets API knows which site sent it.
 */
class BCC_Login_Site_Report {
    const LOCK_OPTION = 'bcc_login_site_report.lock';

    private BCC_Login_Settings $_settings;
    private $plugin;
    private $plugin_version;

    function __construct( BCC_Login_Settings $settings, $plugin, $plugin_version ) {
        $this->_settings = $settings;
        $this->plugin = $plugin;
        $this->plugin_version = $plugin_version;

        // After the daily cleanup, so the leftover count shows what it couldn't remove yet.
        add_action( BCC_Login_Cleanup::CRON_HOOK, array( $this, 'send' ), 20 );
        add_action( 'bcc_login_settings_maintenance', array( $this, 'render_send_now_button' ) );
    }

    /**
     * The daily report. Failures are logged and never stop the daily task.
     */
    function send() {
        // WP-Cron can run the daily task more often, even twice at once, e.g. when it can't save the next run
        // time. So the report is sent at most once in 12 hours. The time is claimed before asking for a token,
        // so a site that can't get one doesn't keep asking either.
        if ( ! self::claim() ) {
            return;
        }

        $sent = $this->deliver();
        if ( is_wp_error( $sent ) ) {
            error_log( 'BCC Login: ' . $sent->get_error_message() );
        }
    }

    /**
     * Sends the report right away, however recently it was sent, e.g. to check the site's setup in Auth0.
     * It clears the last claim rather than claiming the next 12 hours, so the next daily report is still sent
     * at its usual time.
     *
     * @return array|WP_Error The report that was sent, or why it couldn't be sent.
     */
    function send_now() {
        global $wpdb;

        $wpdb->delete( $wpdb->options, array( 'option_name' => self::LOCK_OPTION ) );

        return $this->deliver();
    }

    /**
     * Claims the next 12 hours for sending the daily report: true when the last claim is older than that. Of
     * two runs at once, only one can claim them.
     *
     * The time is kept in an option, but read and written only with SQL. WordPress's own lock
     * (WP_Upgrader::create_lock) goes through the option cache, and re-creating an expired lock leaves the
     * option cached as missing. With a persistent object cache that stopped the daily report after two days.
     */
    private static function claim() {
        global $wpdb;

        $now = time();

        // The first claim adds the option. Of two runs at once, only one can add it.
        $added = $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %d, 'off')",
            self::LOCK_OPTION,
            $now
        ) );
        if ( $added > 0 ) {
            return true;
        }

        // Later claims move the time forward once it's 12 hours old. Of two runs at once, the second waits for
        // the first and then finds the new time.
        $moved = $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->options} SET option_value = %d WHERE option_name = %s AND CAST(option_value AS UNSIGNED) <= %d",
            $now,
            self::LOCK_OPTION,
            $now - 12 * HOUR_IN_SECONDS
        ) );

        // Both statements count a row only when they claim it, also on connections that count matched rows
        // instead of changed ones (MYSQLI_CLIENT_FOUND_ROWS).
        return $moved > 0;
    }

    /**
     * The "Send site report now" button under Maintenance on the settings page, and what happened when it was
     * pressed. Like the rest of Maintenance, only administrators see it.
     */
    function render_send_now_button() {
        if ( ! current_user_can( 'administrator' ) ) {
            return;
        }
        ?>
            <h3><?php _e( 'Site report', 'bcc-login' ); ?></h3>
            <p><?php _e( 'Once a day, this site sends BCC its bcc-login, WordPress and PHP versions and its number of users and subscribers. Send it now to check that this works, for example after allowing the site\'s login client to get a token for the widgets API.', 'bcc-login' ); ?></p>
            <form method="post">
                <?php wp_nonce_field( 'send_site_report', 'send_site_report_nonce' ); ?>
                <?php submit_button( __( 'Send site report now', 'bcc-login' ), 'secondary', 'send_site_report', false ); ?>
            </form>
        <?php
        $this->send_now_handler();
        settings_errors( 'site_report_sent' );
    }

    /**
     * Sends the report when the button was pressed, and shows what was sent or why it couldn't be sent.
     */
    function send_now_handler() {
        if (
            ! isset( $_POST['send_site_report'], $_POST['send_site_report_nonce'] ) ||
            ! wp_verify_nonce( $_POST['send_site_report_nonce'], 'send_site_report' ) ||
            ! current_user_can( 'administrator' )
        ) {
            return;
        }

        $report = $this->send_now();

        // settings_errors() prints messages as HTML, and Auth0's error description comes from outside.
        if ( is_wp_error( $report ) ) {
            add_settings_error( 'site_report_sent', 'site_report_not_sent', esc_html( $report->get_error_message() ), 'error' );
            return;
        }

        add_settings_error(
            'site_report_sent',
            'site_report_sent',
            esc_html( sprintf(
                __( 'The site report was sent: %1$s, bcc-login %2$s, WordPress %3$s, PHP %4$s, users: %5$s, subscribers: %6$s.', 'bcc-login' ),
                $report['siteUrl'],
                $report['pluginVersion'],
                $report['wordpressVersion'],
                $report['phpVersion'],
                null === $report['users'] ? '?' : $report['users'],
                null === $report['subscribers'] ? '?' : $report['subscribers']
            ) ),
            'success'
        );
    }

    function get_report() {
        global $wpdb;

        // The users of this site and, of those, the ones "Delete subscribers" removes: those whose only role
        // is subscriber. The plugin's common users are left out, as in the users list, since it recreates them.
        $common_users = wp_list_pluck( BCC_Login_Users::get_logins(), 'login' );
        $counts = $wpdb->get_row( $wpdb->prepare(
            "SELECT COUNT(*) AS users, COALESCE(SUM(m.meta_value = %s), 0) AS subscribers
            FROM {$wpdb->usermeta} m
            INNER JOIN {$wpdb->users} u ON u.ID = m.user_id
            WHERE m.meta_key = %s AND u.user_login NOT IN (" . implode( ', ', array_fill( 0, count( $common_users ), '%s' ) ) . ")",
            array_merge( array( 'a:1:{s:10:"subscriber";b:1;}', $wpdb->get_blog_prefix() . 'capabilities' ), $common_users )
        ) );

        // What the daily cleanup still has to remove: meta of users that no longer exist (it waits a day
        // before deleting those) and meta of the old OpenID Connect Generic plugin.
        $leftover_user_meta_rows = $wpdb->get_var(
            "SELECT COUNT(*)
            FROM {$wpdb->usermeta} m
            LEFT JOIN {$wpdb->users} u ON u.ID = m.user_id
            WHERE (u.ID IS NULL AND m.user_id > 0) OR m.meta_key LIKE 'openid-connect-generic%'"
        );

        return array(
            'siteUrl'              => home_url(),
            'environment'          => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : null, // WordPress 5.5+
            'pluginVersion'        => $this->plugin_version,
            'pluginAutoUpdates'    => $this->get_plugin_auto_updates(),
            'wordpressVersion'     => get_bloginfo( 'version' ),
            'phpVersion'           => PHP_VERSION,
            'multisite'            => is_multisite(),
            // A count that couldn't be read is sent as null, so it isn't mistaken for a clean site.
            'users'                => isset( $counts->users ) ? (int) $counts->users : null,
            'subscribers'          => isset( $counts->subscribers ) ? (int) $counts->subscribers : null,
            'leftoverUserMetaRows' => null !== $leftover_user_meta_rows ? (int) $leftover_user_meta_rows : null,
        );
    }

    /**
     * Gets a token for the site's login client and posts the report to the widgets API.
     *
     * @return array|WP_Error The report that was sent, or why it couldn't be sent.
     */
    private function deliver() {
        if ( empty( $this->_settings->client_id ) || empty( $this->_settings->client_secret ) ) {
            return new WP_Error( 'bcc_login_site_report', 'The site report needs the client id and secret of the login client.' );
        }

        $token = $this->get_token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $report = $this->get_report();
        $response = wp_remote_post( rtrim( $this->_settings->widgets_base_url, '/' ) . '/api/wordpress-sites/report', array(
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $report ),
        ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'bcc_login_site_report', 'Could not send the site report: ' . $response->get_error_message() );
        }

        $status = wp_remote_retrieve_response_code( $response );
        if ( $status < 200 || $status >= 300 ) {
            return new WP_Error( 'bcc_login_site_report', 'The widgets API refused the site report with HTTP ' . $status . '.' );
        }

        return $report;
    }

    /**
     * Whether WordPress updates this plugin by itself, as its background updater decides: automatic updates
     * aren't turned off (e.g. by DISALLOW_FILE_MODS), it can write the files without asking for credentials,
     * the site isn't a git or other version control checkout, and auto-updates are on for the plugin or
     * forced by a filter.
     */
    private function get_plugin_auto_updates() {
        self::load_upgrader();
        $updater = new WP_Automatic_Updater();

        // The same kind of item the plugins screen passes when it asks whether a filter forces auto-updates.
        return (bool) $updater->should_update( 'plugin', (object) array(
            'id'          => $this->plugin,
            'slug'        => dirname( $this->plugin ),
            'plugin'      => $this->plugin,
            'new_version' => '',
        ), WP_PLUGIN_DIR );
    }

    /**
     * WordPress's updater classes, loaded as WordPress loads them for its own automatic updates.
     */
    private static function load_upgrader() {
        require_once ABSPATH . 'wp-admin/includes/admin.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    }

    /**
     * A token for the widgets API, for the site's own login client.
     *
     * @return string|WP_Error
     */
    private function get_token() {
        $response = wp_remote_post( $this->_settings->token_endpoint, array(
            'timeout' => 15,
            'body'    => array(
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->_settings->client_id,
                'client_secret' => $this->_settings->client_secret,
                'audience'      => $this->_settings->widgets_audience,
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'bcc_login_site_report', 'Could not get a token for the site report: ' . $response->get_error_message() );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ) );

        if ( 200 !== wp_remote_retrieve_response_code( $response ) || empty( $body->access_token ) ) {
            // Auth0's error code and description only, e.g. when the login client may not get a token for the widgets API yet.
            $error = isset( $body->error ) ? $body->error . ( isset( $body->error_description ) ? ': ' . $body->error_description : '' ) : 'HTTP ' . wp_remote_retrieve_response_code( $response );
            return new WP_Error( 'bcc_login_site_report', 'Could not get a token for the site report: ' . $error );
        }

        return $body->access_token;
    }
}
