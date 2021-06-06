<?php

/**
 * Plugin Name: BCC Login
 * Description: Integration to BCC's Login System.
 * Version: $_PluginVersion_$
 * Author: BCC IT
 * License: GPL2
 */

define( 'BCC_LOGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'BCC_LOGIN_URL', plugin_dir_url( __FILE__ ) );

require_once( 'includes/class-bcc-login-settings.php' );
require_once( 'includes/class-bcc-login-client.php' );
require_once( 'includes/class-bcc-login-endpoints.php' );
require_once( 'includes/class-bcc-login-visibility.php' );
require_once( 'includes/class-bcc-login-users.php' );
require_once( 'includes/class-bcc-login-widgets.php' );

class BCC_Login {

    /**
     * The plugin instance.
     *
     * @var BCC_Login
     */
    private static $instance = null;

    private BCC_Login_Settings $_settings;
    private BCC_Login_Endpoints $_endpoints;
    private BCC_Login_Client $_client;
    private BCC_Login_Users $_users;
    private BCC_Login_Visibility $_visibility;
    private BCC_Login_Widgets $_widgets;

    /**
     * Initialize the plugin.
     */
    private function __construct(){
        $settings_provider = new BCC_Login_Settings_Provider();

        $this->_settings = $settings_provider->get_settings();
        $this->_endpoints = new BCC_Login_Endpoints( $this->_settings );
        $this->_client = new BCC_Login_Client($this->_settings);
        $this->_users = new BCC_Login_Users($this->_settings);
        $this->_visibility = new BCC_Login_Visibility( $this->_settings, $this->_client );
        $this->_widgets = new BCC_Login_Widgets( $this->_settings, $this->_client );

        add_action( 'init', array( $this, 'redirect_login' ) );
        add_action( 'init', array( $this, 'start_session' ), 1 );
        add_action( 'wp_authenticate', array( $this, 'end_session' ) );
        add_action( 'wp_logout', array( $this, 'end_session' ) );

        register_activation_hook( __FILE__, array( 'BCC_Login', 'activate_plugin' ) );
        register_deactivation_hook( __FILE__, array( 'BCC_Login', 'deactivate_plugin' ) );
        register_uninstall_hook( __FILE__, array( 'BCC_Login', 'uninstall_plugin' ) );
    }

    function redirect_login() {
        global $pagenow;

        $action = isset( $_GET['action'] ) ? $_GET['action'] : '';

        if (
            $pagenow != 'wp-login.php' ||
            isset( $_GET['loggedout'] ) ||
            isset( $_POST['wp-submit'] ) ||
            isset( $_GET['login-error'] ) ||
            in_array( $action, array( 'logout', 'lostpassword', 'rp', 'resetpass', 'register' ) )
        ) {
            return;
        }

        $this->_client->start_login();
    }

    /**
     * Start PHP Session if not already started
     */
    function start_session() {
        if ( ! session_id() ) {
            session_start();
        }
    }

    /**
     * End PHP session (e.g. after logout)
     */
    function end_session() {
        $this->_client->end_login();
        session_destroy();
    }

    /**
     * Return to homepage after logging out.
     *
     * @return string
     */
    function get_logout_url() {
        return home_url();
    }

    /**
     * Activate plugin hook
     * Called when plugin is activated
     */
    static function activate_plugin() {
        if ( ! get_role( 'bcc-login-member' ) ) {
            add_role( 'bcc-login-member', __( 'Member' ), array( 'read' => true ) );
        }
        BCC_Login_Users::create_users();
    }

    /**
     * Deactivate plugin hook
     * Called when plugin is deactivated
     */
    static function deactivate_plugin() {

    }

    /**
     * Uninstall plugin hook
     * Called when plugin is uninstalled
     */
    static function uninstall_plugin() {
        BCC_Login_Users::remove_users();
        BCC_Login_Visibility::on_uninstall();
        remove_role( 'bcc-login-member' );
    }

    /**
     * Creates and returns a single instance of this class.
     *
     * @return BCC_Login
     */
    static function get_instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new BCC_Login();
        }
        return self::$instance;
    }
}

function bcc_login() {
    return BCC_Login::get_instance();
}

bcc_login();
