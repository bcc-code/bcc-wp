<?php

/*
Plugin Name: BCC Wordpress Proxy
Description: Add support for serving pages via the BCC Wordpress Proxy
Version: 1.1
Author: BCC IT
License: GPL2
*/


class BCC_WP_Proxy_Plugin {

    /**
     * Initialize plugin
     */
    static function init_plugin(){
        $plugin = new self();
        register_activation_hook( __FILE__, array( 'BCC_WP_Proxy_Plugin', 'activate_plugin' ) );
        register_deactivation_hook( __FILE__, array( 'BCC_WP_Proxy_Plugin', 'deactivate_plugin' ) );
    }

    /**
     * Activate plugin hook
     * Called when plugin is activated
     */
    static function activate_plugin() {

    }

    /**
     * Deactivate plugin hook
     * Called when plugin is deactivated
     */
    static function deactivate_plugin() {

    }   

    function __construct() {
        // remove_filter('template_redirect','redirect_canonical');
        // Add init handler
        add_action( 'plugins_loaded', array( $this, 'authorize_user' ) );
        add_action( 'save_post', array ( $this, 'on_post_saved' ), 10, 3 );
        add_action( 'siteground_optimizer_flush_cache', array ( $this, 'on_sg_cache_purged' ));

        add_action( 'rest_api_init', function () {
            register_rest_route( 'bcc-wp-proxy/v1', '/users', array(
              'methods' => 'GET',
              'callback' => array($this, 'get_user_info'),
              'permission_callback' => function( WP_REST_Request $request ) {
                    return true;
                },
            ) );

            register_rest_route( 'bcc-wp-proxy/v1', '/last-updated', array(
                'methods' => 'GET',
                'callback' => array($this, 'get_last_updated'),
                'permission_callback' => function( WP_REST_Request $request ) {
                      return true;
                  },
              ) );
          } );
    }

    function on_post_saved($post_id, $post, $update ) {
        update_option('bcc_wp_proxy_content_timestamp', time());
    }

    function on_sg_cache_purged($url) {
        update_option('bcc_wp_proxy_content_timestamp', time());
    }

    function authorize_user() {
        $userId = $this->authorize_proxy_request();
        if ( $userId > 0 ) {
            if ( is_user_logged_in() ) {
                if ( get_current_user_id() == $userId ) {
                    return;
                } else {
                    wp_clear_auth_cookie();
                }                
            }
            wp_set_current_user( $userId );
            wp_set_auth_cookie( $userId );
            // update_user_caches($user); // unsure if necessary
        }
    }


     /**
     * Returns a summary of users
     */
    function get_user_info( $data ) {

        $this->authorize_api_request();        

        return array_map(function( $user) {
           return array (
             "ID" => $user->ID,
             "Login" => $user->user_login,
             "Email" => $user->user_email,
             "Status" => $user->user_status,
             "Roles" => $user->roles
           );
        }, get_users());
    }

     /**
     * Gets last content update date (used for cache invalidation)
     */
    function get_last_updated( $data ) {

        $this->authorize_api_request();        

        $timestamp = get_option('bcc_wp_proxy_content_timestamp');
        if ( $timestamp ) {
            return (int)$timestamp;
        }
        return 0;   
    }
    

    /**
     * Returns configuration value from contants or environment variables
     */
    private function get_config_value( $key ) {
        if ( defined( $key ) && constant( $key ) != '' ) {
            return constant( $key );
        } else {
            $env = getenv($key);                
            if ( isset($env) && !is_null($env) && $env != '') {
                return $env;
            }                
        }
    }

    /**
     * Returns key defined for plugin
     */
    private function get_auth_key() {
        return $this->get_config_value('BCC_WP_PROXY_KEY');
    }

    /**
     * Checks the key sent by the proxy. Requests are rejected when BCC_WP_PROXY_KEY isn't set.
     */
    private function is_valid_proxy_key( $provided ) {
        $key = (string) $this->get_auth_key();
        return $key !== '' && is_string( $provided ) && hash_equals( $key, $provided );
    }


    /**
     * Checks if auth key has been provided in authorization header and returns the userID set by the proxy (if specified)
     */
    function authorize_proxy_request() {
        $header = apache_request_headers();
        if ( isset( $header['X-Wp-Proxy-Key'] )) {
            if ( $this->is_valid_proxy_key( $header['X-Wp-Proxy-Key'] ) ) {

                /* Don't redirect to cannonical address if request is coming from proxy */
                remove_filter('template_redirect','redirect_canonical');

                if ( isset( $header['X-Wp-Proxy-User-Id'] )) {
                    $userId = $header['X-Wp-Proxy-User-Id'];
                    if ($userId != '' && $userId != '0'){
                        return (int)$userId;
                    }
                    return 0;
                }
                else
                {
                    return 0;
                }
            };
         }
         return 0;
    }

    /**
     * Checks if API key has been provided in authorization header and returns 403 response if not found
     */
    function authorize_api_request() {
        $header = apache_request_headers();
         if ( isset( $header['X-Wp-Proxy-Key'] )) {
            if ( $this->is_valid_proxy_key( $header['X-Wp-Proxy-Key'] ) ) {
                return true;
            }
         }
         header('HTTP/1.0 403 Forbidden');
        die('You are not allowed to access this file.');
    }

   
}

// Initialize plugin
BCC_WP_Proxy_Plugin::init_plugin();