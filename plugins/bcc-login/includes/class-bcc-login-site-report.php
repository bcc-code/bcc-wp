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
    const LOCK = 'bcc_login_site_report';

    private BCC_Login_Settings $_settings;
    private $plugin;
    private $plugin_version;

    function __construct( BCC_Login_Settings $settings, $plugin, $plugin_version ) {
        $this->_settings = $settings;
        $this->plugin = $plugin;
        $this->plugin_version = $plugin_version;

        // After the daily cleanup, so the leftover count shows what it couldn't remove yet.
        add_action( BCC_Login_Cleanup::CRON_HOOK, array( $this, 'send' ), 20 );
    }

    /**
     * Failures are logged and never stop the daily task.
     */
    function send() {
        // WP-Cron can run the daily task more often, even twice at once, e.g. when it can't save the next run
        // time. So the report is sent at most once in 12 hours, claimed with WordPress's upgrader lock: an
        // option added atomically, which expires. It's claimed before asking for a token, so a site that can't
        // get one doesn't keep asking either.
        self::load_upgrader();
        if ( ! WP_Upgrader::create_lock( self::LOCK, 12 * HOUR_IN_SECONDS ) ) {
            return;
        }

        if ( empty( $this->_settings->client_id ) || empty( $this->_settings->client_secret ) ) {
            error_log( 'BCC Login: The site report needs the client id and secret of the login client.' );
            return;
        }

        $token = $this->get_token();
        if ( ! $token ) {
            return;
        }

        $response = wp_remote_post( rtrim( $this->_settings->widgets_base_url, '/' ) . '/api/wordpress-sites/report', array(
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ),
            'body'    => wp_json_encode( $this->get_report() ),
        ) );

        if ( is_wp_error( $response ) ) {
            error_log( 'BCC Login: Could not send the site report: ' . $response->get_error_message() );
            return;
        }

        $status = wp_remote_retrieve_response_code( $response );
        if ( $status < 200 || $status >= 300 ) {
            error_log( 'BCC Login: The widgets API refused the site report with HTTP ' . $status . '.' );
        }
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
            error_log( 'BCC Login: Could not get a token for the site report: ' . $response->get_error_message() );
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ) );

        if ( 200 !== wp_remote_retrieve_response_code( $response ) || empty( $body->access_token ) ) {
            // Auth0's error code and description only, e.g. when the login client may not get a token for the widgets API yet.
            $error = isset( $body->error ) ? $body->error . ( isset( $body->error_description ) ? ': ' . $body->error_description : '' ) : 'HTTP ' . wp_remote_retrieve_response_code( $response );
            error_log( 'BCC Login: Could not get a token for the site report: ' . $error );
            return null;
        }

        return $body->access_token;
    }
}
