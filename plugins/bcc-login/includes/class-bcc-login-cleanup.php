<?php

/**
 * Daily background task (WP-Cron) that deletes data left behind by the old OpenID Connect Generic plugin.
 *
 * It keeps running rather than running once, so the data is also removed when it comes back,
 * e.g. from a restored backup or a database pushed from another environment.
 */
class BCC_Login_Cleanup {
    const CRON_HOOK = 'bcc_login_cleanup';

    function __construct() {
        add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );

        // WordPress doesn't run activation hooks on plugin updates,
        // so the task is scheduled by the first request that loads this code.
        add_action( 'init', array( $this, 'schedule' ) );
    }

    function schedule() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'daily', self::CRON_HOOK );
        }
    }

    static function unschedule() {
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    static function run() {
        if ( false === self::delete_legacy_user_meta() ) {
            error_log( 'BCC Login: Could not delete the user meta of the OpenID Connect Generic plugin, retrying tomorrow.' );
        }
    }

    /**
     * Deletes the tokens and claims that the old OpenID Connect Generic plugin stored as user meta.
     *
     * @return int|false The number of deleted rows, or false if not all of them could be deleted.
     */
    static function delete_legacy_user_meta() {
        global $wpdb;

        $legacy_meta_keys = $wpdb->get_results(
            "SELECT meta_key, COUNT(*) AS count
            FROM {$wpdb->usermeta}
            WHERE meta_key LIKE 'openid-connect-generic%'
            GROUP BY meta_key"
        );

        $deleted = 0;
        foreach ( (array) $legacy_meta_keys as $legacy_meta_key ) {
            // Unlike a plain DELETE query, this also clears the cached meta of the users.
            delete_metadata( 'user', 0, $legacy_meta_key->meta_key, '', true );
            $deleted += (int) $legacy_meta_key->count;
        }

        $remaining = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key LIKE 'openid-connect-generic%'"
        );

        // Null means the query failed.
        if ( null === $remaining || (int) $remaining > 0 ) {
            return false;
        }

        return $deleted;
    }
}
