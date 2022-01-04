<?php

class BCC_Login_Users {

    private BCC_Login_Settings $_settings;

    function __construct( BCC_Login_Settings $settings ) {
        $this->_settings = $settings;

        add_action( 'init', array( $this, 'on_init' ) );
        add_action( 'admin_init', array( $this, 'on_admin_init' ) );
        add_action( 'pre_user_query', array( $this, 'modify_user_query' ) );
        add_action( 'profile_update', array( $this, 'on_profile_update' ), 10, 2 );
    }

    /**
     * Hides the admin bar for common users.
     *
     * @return void
     */
    function on_init() {
        $user = wp_get_current_user();
        if ( $this->is_common_user( $user ) || !$user->exists() ) {
            show_admin_bar(false);
            echo "<style>#wpadminbar {display: none !important;}</style>";        
        }
    }

    /**
     * Disallows admin access for common users.
     */
    function on_admin_init() {
        if ( $this->is_common_user( wp_get_current_user() ) ) {
            wp_die( __( 'Unauthorized' ) );
        }
    }

    /**
     * Hides common users from the users list.
     *
     * @param WP_User_Query $query
     * @return void
     */
    function modify_user_query( $query ) {
        global $wpdb;

        foreach ( self::get_logins() as $info ) {
            $query->query_where = str_replace(
                'WHERE 1=1',
                "WHERE 1=1 AND {$wpdb->users}.user_login != '" . $info['login'] . "'",
                $query->query_where
            );
        }
    }


    /**
     * Disallows updating a common user.
     *
     * @param int     $user_id
     * @param WP_User $user_data
     * @return void
     */
    function on_profile_update( $user_id, $user_data ) {
        if ( $this->is_common_user( $user_data ) ) {
            wp_die( __( 'Unauthorized' ) );
        }
    }

    /**
     * Checks whether the given user is a common user.
     *
     * @param WP_User $user
     * @return boolean
     */
    function is_common_user( $user ) {
        foreach ( self::get_logins() as $info ) {
            if ( $user->user_login === $info['login'] ) {
                return true;
            }
        }
        return false;
    }

    static function get_logins() {
        return array(
            array(
                'login' => 'bcc-login-member',
                'desc'  => __( 'Member' ),
                'role'  => 'bcc-login-member',
            ),
            array(
                'login' => 'bcc-login-subscriber',
                'desc'  => __( 'Subscriber' ),
                'role'  => 'subscriber',
            ),
        );
    }

    static function get_member() {
        $logins = self::get_logins();
        return get_user_by( 'login', $logins[0]['login'] );
    }

    static function get_subscriber() {
        $logins = self::get_logins();
        return get_user_by( 'login', $logins[1]['login'] );
    }

    static function create_users() {
        foreach ( self::get_logins() as $info ) {
            if ( ! get_user_by( 'login', $info['login'] ) ) {
                $uid = wp_insert_user(
                    array(
                        'user_login'           => $info['login'],
                        'user_pass'            => wp_generate_password( 32, true, true ),
                        'user_email'           => $info['login'] . '@bcc.no',
                        'display_name'         => $info['desc'],
                        'role'                 => $info['role'],
                        'show_admin_bar_front' => false
                    )
                );

                if ( is_wp_error( $uid ) ) {
                    wp_die( 'Common user creation failed.' );
                }
            }
        }
    }

    static function remove_users() {
        foreach ( self::get_logins() as $info ) {
            if ( $user = get_user_by( 'login', $info['login'] ) ) {
                wp_delete_user( $user->ID );
            }
        }
    }
}
