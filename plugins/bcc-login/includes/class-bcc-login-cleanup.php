<?php

/**
 * Daily background task (WP-Cron) that deletes user data left behind by older versions of the plugin:
 * the user meta of users that no longer exist (older versions of "Delete subscribers" only removed the
 * users and their role), and the tokens and claims of the old OpenID Connect Generic plugin.
 *
 * It keeps running rather than running once, so the data is also removed when it comes back,
 * e.g. from a restored backup or a database pushed from another environment.
 */
class BCC_Login_Cleanup {
    const CRON_HOOK = 'bcc_login_cleanup';
    const MISSING_USERS_OPTION = 'bcc_login_missing_users';

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
        if ( false === self::delete_orphaned_user_meta( true ) ) {
            error_log( 'BCC Login: Could not delete the user meta of deleted users, retrying tomorrow.' );
        }

        if ( false === self::delete_legacy_user_meta() ) {
            error_log( 'BCC Login: Could not delete the user meta of the OpenID Connect Generic plugin, retrying tomorrow.' );
        }
    }

    /**
     * Deletes the user meta of users that no longer exist.
     *
     * The users tables are shared by all sites of a multisite network, so these aren't prefixed per site.
     *
     * @param bool $only_missing_before Only delete the meta of users that were already missing at a call at least
     *                                  12 hours earlier (normally the previous day's run). The daily task uses this
     *                                  because a database import loads wp_usermeta before wp_users, so while one is
     *                                  running every user seems to be missing. An import doesn't last that long, and
     *                                  the time check also covers WP-Cron running the task twice at once.
     * @return int|false The number of deleted rows, or false if a query failed (rows of earlier batches stay deleted).
     */
    static function delete_orphaned_user_meta( $only_missing_before = false ) {
        global $wpdb;

        // WordPress never stores meta for user id 0, so rows with it aren't left behind by a deleted user.
        $user_ids = $wpdb->get_col(
            "SELECT DISTINCT m.user_id
            FROM {$wpdb->usermeta} m
            LEFT JOIN {$wpdb->users} u ON u.ID = m.user_id
            WHERE u.ID IS NULL AND m.user_id > 0"
        );

        if ( $wpdb->last_error ) {
            return false;
        }

        $user_ids = array_map( 'intval', $user_ids );

        if ( $only_missing_before ) {
            $before = get_option( self::MISSING_USERS_OPTION );
            $now = array( 'time' => time(), 'user_ids' => $user_ids );
            update_option( self::MISSING_USERS_OPTION, $now, false );

            // Only continue once this list is saved, so the next run doesn't compare against an older one.
            // update_option() also returns false when the value didn't change, so read it back instead.
            if ( get_option( self::MISSING_USERS_OPTION ) !== $now ) {
                return false;
            }

            $missing_before = array();
            if ( isset( $before['time'], $before['user_ids'] ) && $before['time'] <= time() - 12 * HOUR_IN_SECONDS ) {
                $missing_before = array_map( 'intval', (array) $before['user_ids'] );
            }

            $user_ids = array_intersect( $user_ids, $missing_before );
        }

        $deleted = 0;
        foreach ( array_chunk( $user_ids, 500 ) as $chunk ) {
            // Unlike one DELETE joining the whole table, this only locks these users' meta rows (and briefly the index
            // gaps next to them and in wp_users) for one short statement per batch. The join checks again that each
            // user is still missing.
            $result = $wpdb->query(
                "DELETE m
                FROM {$wpdb->usermeta} m
                LEFT JOIN {$wpdb->users} u ON u.ID = m.user_id
                WHERE u.ID IS NULL AND m.user_id IN (" . implode( ',', $chunk ) . ")"
            );

            if ( false === $result ) {
                return false;
            }

            $deleted += $result;

            self::clean_deleted_users_cache( $chunk );
        }

        return $deleted;
    }

    /**
     * Removes deleted users from the object cache. With a persistent object cache, they could otherwise
     * still be loaded from it. Clearing the 'users' group by id is enough for lookups by login, email or
     * slug as well, as those only map to the id and then load the user by it.
     *
     * @param int[] $user_ids
     */
    static function clean_deleted_users_cache( $user_ids ) {
        foreach ( $user_ids as $user_id ) {
            wp_cache_delete( $user_id, 'users' );
            wp_cache_delete( $user_id, 'user_meta' );
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
