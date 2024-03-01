<?php

/**
 * Plugin Name: BCC Login
 * Description: Integration to BCC's Login System.
 * Version: 1.1.244
 * Author: BCC IT
 * License: GPL2
 */

define( 'BCC_LOGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'BCC_LOGIN_URL', plugin_dir_url( __FILE__ ) );

require_once( 'includes/class-bcc-login-token-utility.php');
require_once( 'includes/class-bcc-login-settings.php' );
require_once( 'includes/class-bcc-login-client.php' );
require_once( 'includes/class-bcc-login-endpoints.php' );
require_once( 'includes/class-bcc-login-visibility.php' );
require_once( 'includes/class-bcc-login-users.php' );
require_once( 'includes/class-bcc-login-widgets.php' );
require_once( 'includes/class-bcc-login-feed.php' );
require_once( 'includes/class-bcc-login-updater.php');
require_once( 'includes/class-bcc-coreapi-client.php');
require_once( 'includes/class-bcc-storage.php');
require_once( 'includes/class-bcc-notifications.php');
require_once( 'includes/class-exclusive-lock.php');

class BCC_Login {

    /**
     * The plugin instance.
     */
    private static $instance = null;
    private $plugin_version = "1.1.244";
    private $plugin;
    private $plugin_slug;
    private $plugin_name = "BCC Login";
    private $auto_login_referrers = ["brunstad.org", "portal.bcc.no", "bcc"];

    private BCC_Login_Settings $_settings;
    private BCC_Login_Endpoints $_endpoints;
    private BCC_Login_Client $_client;
    private BCC_Login_Users $_users;
    private BCC_Login_Visibility $_visibility;
    private BCC_Login_Widgets $_widgets;
    private BCC_Login_Feed $_feed;
    private BCC_Login_Updater $_updater;
    private BCC_Coreapi_Client $_coreapi;
    private BCC_Notifications $_notifications;
    private BCC_Storage $_storage;
    


    /**
     * Initialize the plugin.
     */
    private function __construct(){
        $settings_provider = new BCC_Login_Settings_Provider();

        $this->plugin = plugin_basename( __FILE__ );
		$this->plugin_slug = plugin_basename( __DIR__ );

        $this->_settings = $settings_provider->get_settings();
        $this->_storage = new BCC_Storage($this->_settings->client_secret );
        $this->_coreapi = new BCC_Coreapi_Client($this->_settings, $this->_storage );

        $this->_endpoints = new BCC_Login_Endpoints( $this->_settings );
        $this->_client = new BCC_Login_Client($this->_settings);
        $this->_users = new BCC_Login_Users($this->_settings);
        $this->_visibility = new BCC_Login_Visibility( $this->_settings, $this->_client, $this->_coreapi );
        $this->_widgets = new BCC_Login_Widgets( $this->_settings, $this->_client );
        $this->_feed = new BCC_Login_Feed( $this->_settings, $this->_client );
        $this->_updater = new BCC_Login_Updater( $this->plugin, $this->plugin_slug, $this->plugin_version, $this->plugin_name );
        $this->_notifications = new BCC_Notifications( $this->_settings, $this->_coreapi );

        if (!empty($this->_settings->site_groups) || !empty($this->_settings->full_content_access_groups)) {
            $this->_coreapi->ensure_subscription_to_person_updates();
        }

        add_action( 'init', array( $this, 'redirect_login' ) );
        add_action( 'wp_authenticate', array( $this, 'end_session' ) );
        add_action( 'wp_logout', array( $this, 'end_session' ) );
        add_action( 'wp_head', array( $this, 'add_auto_login_script' ) );
        add_action( 'wp_head', array( $this, 'hide_admin_bar' ) );

        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'plugin_settings_link'));

        register_activation_hook( __FILE__, array( 'BCC_Login', 'activate_plugin' ) );
        register_deactivation_hook( __FILE__, array( 'BCC_Login', 'deactivate_plugin' ) );
        register_uninstall_hook( __FILE__, array( 'BCC_Login', 'uninstall_plugin' ) );
    }

    function plugin_settings_link( $links ) {
        $links[] = '<a href="' .
            admin_url( 'options-general.php?page=bcc_login' ) .
            '">' . __('Settings') . '</a>';
        return $links;
    }

      

    function redirect_login() {
        global $pagenow;

        $action = isset( $_GET['action'] ) ? $_GET['action'] : '';

        if (
            (
                ($pagenow == 'wp-login.php' || $this->should_auto_login()) &&
                !isset( $_GET['loggedout'] ) &&
                !isset( $_GET['code'] ) &&
                !isset( $_POST['wp-submit'] ) &&
                !isset( $_GET['login-error'] ) &&
                !in_array( $action, array( 'logout', 'lostpassword', 'rp', 'resetpass', 'register' ) )
            )
        ) {
            $this->_client->start_login();
        }        
    }


    function hide_admin_bar() {
        if (!is_user_logged_in()) {
            echo '<style>#wpadminbar { display: none !important; }</style>';
        }
    }
    

    function add_auto_login_script() {
        if ( is_front_page() && !is_user_logged_in() ) {

            echo '<script id="auto-login-redirect">
                const auto_login_referrers=["'.implode('","',$this->auto_login_referrers).'"];
                let should_login = false;
                let already_tried = sessionStorage.getItem("wp_auto_login_attempted");
                if (!already_tried) {

                    sessionStorage.setItem("wp_auto_login_attempted", "true");

                    if (document.cookie.indexOf("wp_has_logged_in") >= 0) {
                        should_login = true;
                    } else {
                        for (let i=0; i<auto_login_referrers.length;i++) {
                            let referrer = auto_login_referrers[i];
                            if (document.referrer && document.referer.indexOf("http") === 0) {
                                let host = document.referrer.split("/")[2];
                                if (host && (host.indexOf(referrer) == 0 || (referrer.indexOf(".") != -1 && host.indexOf(referrer) != -1))) {
                                    should_login = true;
                                }
                            }
                        }
                    }
                }
                if (should_login) {
                    document.location.href = "/login";
                }
            </script>' . PHP_EOL;
        }
    }


    function should_auto_login() {

        // Don't log in user if they are already logged in
        if (is_user_logged_in() && $this->_client->is_session_valid()) {
            return false;
        }

        if (!is_front_page() || $this->_client->is_redirect_url()) {
            return false;
        }

        // Auto-login user if they have logged in previously on this device
        if ($this->_client->has_user_logged_in_previously()){
            return true;
        }

        // Auto-login if user is coming from portal.bcc.no or *.brunstad.org
        if (isset($_SERVER['HTTP_REFERER'])) {
            $referrer = $_SERVER['HTTP_REFERER'];
            $referrer_host = parse_url($referrer, PHP_URL_HOST);
            $found = false;

            // Check if the referrer URL contains any of the search strings
            foreach ($this->auto_login_referrers as $search_string) {
                if (strpos($referrer_host, $search_string) !== false) {
                    return true;
                }
            }            
        }
        return false;
    }


    /**
     * End PHP session (e.g. after logout)
     */
    function end_session() {
        $this->_client->end_login();
        if ( session_id() ) {
            session_destroy();
        }
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

        // Flush rewrite rules to make pretty URLs for endpoints work.
        flush_rewrite_rules();
    }

    /**
     * Deactivate plugin hook
     * Called when plugin is deactivated
     */
    static function deactivate_plugin() {
        flush_rewrite_rules();
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
