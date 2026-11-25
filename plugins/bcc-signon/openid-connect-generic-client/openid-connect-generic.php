<?php
/**
 * OpenID Connect Generic Client
 *
 * This plugin provides the ability to authenticate users with Identity
 * Providers using the OpenID Connect OAuth2 API with Authorization Code Flow.
 *
 * @package   OpenID_Connect_Generic
 * @category  General
 * @author    Jonathan Daggerhart <jonathan@daggerhart.com>
 * @copyright 2015-2020 daggerhart
 * @license   http://www.gnu.org/licenses/gpl-2.0.txt GPL-2.0+
 * @link      https://github.com/daggerhart
 *
 * @wordpress-plugin
 * Plugin Name:       OpenID Connect Generic
 * Plugin URI:        https://github.com/daggerhart/openid-connect-generic
 * Description:       Connect to an OpenID Connect generic client using Authorization Code Flow.
 * Version:           3.8.0
 * Author:            daggerhart
 * Author URI:        http://www.daggerhart.com
 * Text Domain:       daggerhart-openid-connect-generic
 * Domain Path:       /languages
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * GitHub Plugin URI: https://github.com/daggerhart/openid-connect-generic
 */

/*
Notes
  Spec Doc - http://openid.net/specs/openid-connect-basic-1_0-32.html

  Filters
  - openid-connect-generic-alter-request       - 3 args: request array, plugin settings, specific request op
  - openid-connect-generic-settings-fields     - modify the fields provided on the settings page
  - openid-connect-generic-login-button-text   - modify the login button text
  - openid-connect-generic-cookie-redirect-url - modify the redirect url stored as a cookie
  - openid-connect-generic-user-login-test     - (bool) should the user be logged in based on their claim
  - openid-connect-generic-user-creation-test  - (bool) should the user be created based on their claim
  - openid-connect-generic-auth-url            - modify the authentication url
  - openid-connect-generic-alter-user-claim    - modify the user_claim before a new user is created
  - openid-connect-generic-alter-user-data     - modify user data before a new user is created
  - openid-connect-modify-token-response-before-validation - modify the token response before validation
  - openid-connect-modify-id-token-claim-before-validation - modify the token claim before validation

  Actions
  - openid-connect-generic-user-create        - 2 args: fires when a new user is created by this plugin
  - openid-connect-generic-user-update        - 1 arg: user ID, fires when user is updated by this plugin
  - openid-connect-generic-update-user-using-current-claim - 2 args: fires every time an existing user logs
  - openid-connect-generic-redirect-user-back - 2 args: $redirect_url, $user. Allows interruption of redirect during login.
  - openid-connect-generic-user-logged-in     - 1 arg: $user, fires when user is logged in.
  - openid-connect-generic-cron-daily         - daily cron action
  - openid-connect-generic-state-not-found    - the given state does not exist in the database, regardless of its expiration.
  - openid-connect-generic-state-expired      - the given state exists, but expired before this login attempt.

  User Meta
  - openid-connect-generic-subject-identity    - the identity of the user provided by the idp
  - openid-connect-generic-last-id-token-claim - the user's most recent id_token claim, decoded
  - openid-connect-generic-last-user-claim     - the user's most recent user_claim
  - openid-connect-generic-last-token-response - the user's most recent token response

  Options
  - openid_connect_generic_settings     - plugin settings
  - openid-connect-generic-valid-states - locally stored generated states
*/


/**
 * OpenID_Connect_Generic class.
 *
 * Defines plugin initialization functionality.
 *
 * @package OpenID_Connect_Generic
 * @category  General
 */
class OpenID_Connect_Generic {

	/**
	 * Plugin version.
	 *
	 * @var
	 */
	const VERSION = '3.8.0';

	/**
	 * Plugin settings.
	 *
	 * @var OpenID_Connect_Generic_Option_Settings
	 */
	private $settings;

	/**
	 * Plugin logs.
	 *
	 * @var OpenID_Connect_Generic_Option_Logger
	 */
	private $logger;

	/**
	 * Openid Connect Generic client
	 *
	 * @var OpenID_Connect_Generic_Client
	 */
	private $client;

	/**
	 * Client wrapper.
	 *
	 * @var OpenID_Connect_Generic_Client_Wrapper
	 */
	private $client_wrapper;

	/**
	 * Setup the plugin
	 *
	 * @param OpenID_Connect_Generic_Option_Settings $settings The settings object.
	 * @param OpenID_Connect_Generic_Option_Logger   $logger   The loggin object.
	 *
	 * @return void
	 */
	function __construct( OpenID_Connect_Generic_Option_Settings $settings, OpenID_Connect_Generic_Option_Logger $logger ) {
		$this->settings = $settings;
		$this->logger = $logger;
	}

	/**
	 * WordPress Hook 'init'.
	 *
	 * @return void
	 */
	function init() {

		$redirect_uri = admin_url( 'admin-ajax.php?action=openid-connect-authorize' );

		if ( $this->settings->alternate_redirect_uri ) {
			$redirect_uri = site_url( '/openid-connect-authorize' );
		}

		$state_time_limit = 180;
		if ( $this->settings->state_time_limit ) {
			$state_time_limit = intval( $this->settings->state_time_limit );
		}

		$this->client = new OpenID_Connect_Generic_Client(
			$this->settings->client_id,
			$this->settings->client_secret,
			$this->settings->scope,
			$this->settings->endpoint_login,
			$this->settings->endpoint_userinfo,
			$this->settings->endpoint_token,
			$redirect_uri,
			$state_time_limit,
			$this->logger
		);

		$this->client_wrapper = OpenID_Connect_Generic_Client_Wrapper::register( $this->client, $this->settings, $this->logger );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		OpenID_Connect_Generic_Login_Form::register( $this->settings, $this->client_wrapper );

		// Add a shortcode to get the auth URL.
		add_shortcode( 'openid_connect_generic_auth_url', array( $this->client_wrapper, 'get_authentication_url' ) );

		// Add actions to our scheduled cron jobs.
		add_action( 'openid-connect-generic-cron-daily', array( $this, 'cron_states_garbage_collection' ) );

		$this->upgrade();

		if ( is_admin() ) {
			OpenID_Connect_Generic_Settings_Page::register( $this->settings, $this->logger );
		}
	}

	/**
	 * Check if privacy enforcement is enabled, and redirect users that aren't
	 * logged in.
	 *
	 * @return void
	 */
	function enforce_privacy_redirect() {
		list($url, $path_url) = $this->get_request_urls();
		// template_redirect doesn't run for wp-login.php.
		if ( $this->settings->enforce_privacy
				&& ! $this->is_unprotected_request($url, $path_url)
				&& ! is_user_logged_in() ) {
			OpenID_Connect_Generic::redirect_to_login_page();
		}
		else if ( ! $this->settings->enforce_privacy
				&& OpenID_Connect_Generic::is_protected_url($url)
				&& ! is_user_logged_in() ) {
			OpenID_Connect_Generic::redirect_to_login_page();
		}
	}

	/**
	 * The requested URL, with and without its query string, as the privacy checks compare them.
	 *
	 * @return string[]
	 */
	function get_request_urls() {
		global $wp;
		return array(
			home_url(add_query_arg(array($_GET), $wp->request)),
			home_url($wp->request),
		);
	}

	/**
	 * Check if the request is for an unprotected URL.
	 *
	 * An entry matches if it is exactly the requested URL, query string included (like the private
	 * feed link), or if it matches both the whole URL and the URL without its query string. So text in
	 * the query string can't make a protected page match, and an entry for a page doesn't cover other
	 * content its URL's query string selects (like a search on the home page).
	 *
	 * @param string $url      The requested URL, with its query string.
	 * @param string $path_url The requested URL without its query string.
	 */
	function is_unprotected_request($url, $path_url) {
		$unprotected = array_map('trim',
			apply_filters(
				'oidc_unprotected_urls',
				explode("\n", $this->settings->unprotected_urls)
			)
		);

		foreach ($unprotected as $entry) {
			if ($entry === '') {
				continue;
			}
			if ($entry === $url) {
				return true;
			}
			if (OpenID_Connect_Generic::match_regexes($url, array($entry))
					&& OpenID_Connect_Generic::match_regexes($path_url, array($entry))) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if URL is on the list of unprotected URLs
	 */
	function is_unprotected_url($url) {

		$unprotected = array_map('trim',
			apply_filters(
				'oidc_unprotected_urls',
				explode("\n", $this->settings->unprotected_urls)
			)
		);

		return OpenID_Connect_Generic::match_regexes($url, $unprotected);
	}

	/**
	 * Check if URL is on the list of protected URLs
	 */
	function is_protected_url($url) {

		$protected = array_map('trim',
			apply_filters(
				'oidc_protected_urls',
				explode("\n", $this->settings->protected_urls)
			)
		);

		return OpenID_Connect_Generic::match_regexes($url, $protected);
	}

	function match_regexes($url, $regexes) {
		// Entries that aren't valid regexes (plain URLs) would make preg_match() warn. Error reporting is
		// switched off while matching and always switched back on afterwards.
		$default_error_reporting = error_reporting(0);
		$matched = false;

		foreach ($regexes as $regex) {
			if ($regex == $url || preg_match($regex, $url)) {
				$matched = true;
				break;
			}
		}

		error_reporting($default_error_reporting);
		return $matched;
	}

	/**
	 * Redirect to the authentication page
	 */
	function redirect_to_login_page() {
		// The client endpoint relies on the wp admind ajax endpoint.
		if ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX || ! isset( $_GET['action'] ) || 'openid-connect-authorize' != $_GET['action'] ) {
			auth_redirect();
		}
	}

	/**
	 * Enforce privacy settings for rss feeds.
	 *
	 * @param string $content The content.
	 *
	 * @return mixed
	 */
	function enforce_privacy_feeds( $content ) {
		if ( $this->settings->enforce_privacy
			&& ! is_user_logged_in()
			&& ! $this->is_unprotected_request(...$this->get_request_urls()) ) {
			$content = __( 'Private site', 'daggerhart-openid-connect-generic' );
		}
		return $content;
	}

	/**
	 * Handle plugin upgrades
	 *
	 * @return void
	 */
	function upgrade() {
		$last_version = get_option( 'openid-connect-generic-plugin-version', 0 );

		// We keep BCC defined settings
		$settings = new OpenID_Connect_Generic_Option_Settings('openid_connect_generic_settings', bcc_settings(), false);
		$settings->save();

		if ( version_compare( self::VERSION, $last_version, '>' ) ) {
			// An upgrade is required.
			self::setup_cron_jobs();

			// Update the stored version number.
			update_option( 'openid-connect-generic-plugin-version', self::VERSION );
		}
    }

	/**
	 * Expire state transients by attempting to access them and allowing the
	 * transient's own mechanisms to delete any that have expired.
	 *
	 * @return void
	 */
	function cron_states_garbage_collection() {
		global $wpdb;
		$states = $wpdb->get_col( "SELECT `option_name` FROM {$wpdb->options} WHERE `option_name` LIKE '_transient_openid-connect-generic-state--%'" );

		if ( ! empty( $states ) ) {
			foreach ( $states as $state ) {
				$transient = str_replace( '_transient_', '', $state );
				get_transient( $transient );
			}
		}
	}

	/**
	 * Ensure cron jobs are added to the schedule.
	 *
	 * @return void
	 */
	static public function setup_cron_jobs() {
		if ( ! wp_next_scheduled( 'openid-connect-generic-cron-daily' ) ) {
			wp_schedule_event( time(), 'daily', 'openid-connect-generic-cron-daily' );
		}
	}

	/**
	 * Activation hook.
	 *
	 * @return void
	 */
	static public function activation() {
		self::setup_cron_jobs();
	}

	/**
	 * Deactivation hook.
	 *
	 * @return void
	 */
	static public function deactivation() {
		wp_clear_scheduled_hook( 'openid-connect-generic-cron-daily' );
	}

	/**
	 * Simple autoloader.
	 *
	 * @param string $class The class name.
	 *
	 * @return void
	 */
	static public function autoload( $class ) {
		$prefix = 'OpenID_Connect_Generic_';

		if ( stripos( $class, $prefix ) !== 0 ) {
			return;
		}

		$filename = $class . '.php';

		// Internal files are all lowercase and use dashes in filenames.
		if ( false === strpos( $filename, '\\' ) ) {
			$filename = strtolower( str_replace( '_', '-', $filename ) );
		} else {
			$filename  = str_replace( '\\', DIRECTORY_SEPARATOR, $filename );
		}

		$filepath = dirname( __FILE__ ) . '/includes/' . $filename;

		if ( file_exists( $filepath ) ) {
			require_once $filepath;
		}
	}

	/**
	 * Instantiate the plugin and hook into WordPress.
	 *
	 * @return void
	 */
	static public function bootstrap() {
		/**
		 * This is a documented valid call for spl_autoload_register.
		 *
		 * @link https://www.php.net/manual/en/function.spl-autoload-register.php#71155
		 */
		spl_autoload_register( array( 'OpenID_Connect_Generic', 'autoload' ) );

		$settings = new OpenID_Connect_Generic_Option_Settings(
			'openid_connect_generic_settings',
			// Default settings values.
			array(
				// OAuth client settings.
				'login_type'           => 'button',
				'client_id'            => defined( 'OIDC_CLIENT_ID' ) ? OIDC_CLIENT_ID : '',
				'client_secret'        => defined( 'OIDC_CLIENT_SECRET' ) ? OIDC_CLIENT_SECRET : '',
				'scope'                => '',
				'endpoint_login'       => defined( 'OIDC_ENDPOINT_LOGIN_URL' ) ? OIDC_ENDPOINT_LOGIN_URL : '',
				'endpoint_userinfo'    => defined( 'OIDC_ENDPOINT_USERINFO_URL' ) ? OIDC_ENDPOINT_USERINFO_URL : '',
				'endpoint_token'       => defined( 'OIDC_ENDPOINT_TOKEN_URL' ) ? OIDC_ENDPOINT_TOKEN_URL : '',
				'endpoint_end_session' => defined( 'OIDC_ENDPOINT_LOGOUT_URL' ) ? OIDC_ENDPOINT_LOGOUT_URL : '',

				// Non-standard settings.
				'no_sslverify'    => 0,
				'http_request_timeout' => 5,
				'identity_key'    => 'preferred_username',
				'nickname_key'    => 'preferred_username',
				'email_format'       => '{email}',
				'displayname_format' => '',
				'identify_with_username' => false,

				'enforce_privacy' => 1,
				'unprotected_urls' => '',
				'protected_urls' => '',
				// Plugin settings.
				'alternate_redirect_uri' => 0,
				'token_refresh_enable' => 1,
				'link_existing_users' => 0,
				'create_if_does_not_exist' => 0,
				'redirect_user_back' => 0,
				'redirect_on_logout' => 1,
				'enable_logging'  => 0,
				'log_limit'       => 1000,
			)
		);

		/* Customize default settings */
		$settings = apply_filters( 'openid-connect-generic-default-settings', $settings);

		$logger = new OpenID_Connect_Generic_Option_Logger( 'openid-connect-generic-logs', 'error', $settings->enable_logging, $settings->log_limit );

		$plugin = new self( $settings, $logger );

		add_action( 'init', array( $plugin, 'init' ) );

		// Privacy hooks.
		add_action( 'template_redirect', array( $plugin, 'enforce_privacy_redirect' ), 0 );
		add_filter( 'the_content_feed', array( $plugin, 'enforce_privacy_feeds' ), 999 );
		add_filter( 'the_excerpt_rss', array( $plugin, 'enforce_privacy_feeds' ), 999 );
		add_filter( 'comment_text_rss', array( $plugin, 'enforce_privacy_feeds' ), 999 );
	}
}

OpenID_Connect_Generic::bootstrap();

register_activation_hook( __FILE__, array( 'OpenID_Connect_Generic', 'activation' ) );
register_deactivation_hook( __FILE__, array( 'OpenID_Connect_Generic', 'deactivation' ) );
