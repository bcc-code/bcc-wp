<?php

class BCC_Signon {
	protected $bcc_auth_domain;
	protected $private_newsfeed_link;
	protected $private_newsfeeds;
	protected $bcc_topbar;
	protected $bcc_local_church;
	protected $option_name = "bcc-signon-plugin-settings-group";
	protected $options_page_name = "bcc_signon_settings_page";

	public function __construct() {
		$this->bcc_auth_domain = esc_attr( get_option('bcc_auth_domain') );
		if ($this->bcc_auth_domain == "") {
			$this->bcc_auth_domain = "https://auth.bcc.no/";
		 	update_option('bcc_auth_domain', $this->bcc_auth_domain);
		}

		$this->private_newsfeed_link = esc_attr( get_option('private_newsfeed_link') );
		if ($this->private_newsfeed_link == "") {
			$this->private_newsfeed_link = strtolower(str_replace("-","",trim($this->createGUID(), '{}')));
			update_option('private_newsfeed_link', $this->private_newsfeed_link);
		}

		$this->bcc_topbar = get_option('bcc_topbar');
		$this->private_newsfeeds = get_option('private_newsfeeds');
		$this->bcc_local_church = get_option('bcc_local_church');

		$this->load_dependencies();
		add_action('admin_menu', array ($this, 'bcc_signon_plugin_create_menu'));

		add_action('plugins_loaded', function () {
			if ( isset($_GET['login-error']) && $_GET['login-error'] == 'invalid-state' ) {
				wp_redirect($this->get_clear_cookies_url());
                exit;
			}
		});

		add_action('init', array ($this, 'start_session'), 1);
		add_action('wp_authenticate ', array ($this, 'end_session'));
		add_action('wp_logout', array ($this, 'end_session'));
		// add_action('loop_end', array ($this, 'ensure_access_token'));
	}

    function get_clear_cookies_url(){
        $plugin_dir = constant( 'WP_PLUGIN_DIR' );
        $root_dir = get_home_path();
        $relative_plugin_dir = substr($plugin_dir, strlen($root_dir));
        return '/' . $relative_plugin_dir . '/bcc-signon/openid-connect-bcc-customizations/clear-cookies.php';
    }

	
	/**
	 * Start PHP Session if not already started
	 */
	function start_session() {
		if(!session_id()) {
			session_start();
		}
	}

	/**
	 * End PHP session (e.g. after logout)
	 */
	function end_session() {
		session_destroy ();
	}

	/**
	 * Helper to create the GUID
	 */
	private function createGUID() {
		if (function_exists('com_create_guid')) {
			return com_create_guid();
		} else {
			mt_srand((double)microtime()*10000);
			//optional for php 4.2.0 and up.
			$set_charid = strtoupper(md5(uniqid(rand(), true)));
			$set_hyphen = chr(45);
			// "-"
			$set_uuid = chr(123)
				.substr($set_charid, 0, 8).$set_hyphen
				.substr($set_charid, 8, 4).$set_hyphen
				.substr($set_charid,12, 4).$set_hyphen
				.substr($set_charid,16, 4).$set_hyphen
				.substr($set_charid,20,12)
				.chr(125);
				// "}"
			return $set_uuid;
		}
	}

	/**
	 * Load the required dependencies for this plugin.
	 */
	private function load_dependencies() {
		include_once plugin_dir_path( dirname( __FILE__) ) . 'openid-connect-bcc-customizations/includes/single-signout.php';
		include_once plugin_dir_path( dirname( __FILE__) ) . 'openid-connect-bcc-customizations/includes/newsfeed.php';
		include_once plugin_dir_path( dirname( __FILE__) ) . 'openid-connect-bcc-customizations/includes/oidc-configuration.php';
		include_once plugin_dir_path( dirname( __FILE__) ) . 'openid-connect-bcc-customizations/includes/privacy-settings.php';
		include_once plugin_dir_path( dirname( __FILE__) ) . 'openid-connect-bcc-customizations/includes/widgets.php';
		include_once plugin_dir_path( dirname( __FILE__) ) . 'openid-connect-bcc-customizations/includes/snippets.php';
	}

	/**
	 * Create the menu item
	 */
	public function bcc_signon_plugin_create_menu() {
		add_action ( 'admin_init', function() {
			if ( strpos(wp_get_referer(), 'options-general.php?page=bcc_signon_settings_page&delete_subscribers=true') !== false) {
				$this->delete_subscribers();

				add_settings_error(
					'general',
					'subscribers_deleted',
					__( 'All subscribers were successfully deleted.' ),
					'success'
				);
			}
		} );

		register_setting( $this->option_name, 'bcc_auth_domain' );
		register_setting( $this->option_name, 'private_newsfeeds' );
		register_setting( $this->option_name, 'bcc_topbar' );
		register_setting( $this->option_name, 'bcc_local_church' );
		add_options_page( 'BCC Signon', 'BCC Signon', 'manage_options', $this->options_page_name, array($this, $this->options_page_name) );

		add_action( 'admin_init', function() {
			/* Sections */
			add_settings_section( 'oidc', 'OpenId Connect', function(){}, $this->options_page_name );
			add_settings_section( 'newsfeed', 'NewsFeed', function(){}, $this->options_page_name );
			add_settings_section( 'topbar', 'TopBar', function(){}, $this->options_page_name );
			add_settings_section( 'identification', 'Identification', function(){}, $this->options_page_name );

			/* Fields */
			add_settings_field('bcc_auth_domain', "BCC Signon URL", array($this, 'do_text_field'), $this->options_page_name, 'oidc', 
				array('name' => 'bcc_auth_domain', 'value' => $this->bcc_auth_domain, 'readonly' => 1));
			add_settings_field('private_newsfeeds', "Enable Private Newsfeeds", array($this, 'do_checkbox_field'), $this->options_page_name, 'newsfeed', 
				array('name' => 'private_newsfeeds', 'value' => $this->private_newsfeeds, 
				'description' => 'This makes the newsfeed of your site accessible only via the <code>Private newsfeed link</code> from below.'));
			add_settings_field('private_newsfeed_link', "Private newsfeed link", array ($this, 'do_text_field'), $this->options_page_name, 'newsfeed', 
				array('name' => 'private_newsfeed_link', 'value' => ($this->private_newsfeeds ? get_site_url() . get_private_link_feed() : ''), 'readonly' => 1,
				'description' => 'Please share this URL with BCC to integrate your news into the BCC Portal.'));
			add_settings_field('bcc_topbar', "Enable TopBar", array($this, 'do_checkbox_field'), $this->options_page_name, 'topbar', 
				array('name' => 'bcc_topbar', 'value' => $this->bcc_topbar, 
				'description' => 'This shows BCCs topbar on your website.'));
			add_settings_field('bcc_local_church', "Local church", array($this, 'do_text_field'), $this->options_page_name, 'identification', 
				array('name' => 'bcc_local_church', 'value' => $this->bcc_local_church,
				'description' => 'Type in your local church name. Read more on <a href="https://developer.bcc.no/docs/bcc-signon-wordpress/plugin-configuration" target="_blank">developer.bcc.no</a>.'));
		} );
	}

	/**
	 * Creates the settings page
	 */
	public function bcc_signon_settings_page() { ?>
		<div class="wrap">
			<h1>BCC Signon Settings</h1>
			<form method="post" action="options.php">
				<?php settings_fields( $this->option_name); ?>
				<?php do_settings_sections($this->options_page_name ); ?>
				<?php submit_button(); ?>
			</form>

			<form method="post">
				<input type="hidden" name="_wp_http_referer" value="<?php echo add_query_arg( 'delete_subscribers', 'true', wp_get_referer() ) ?>">
				<?php submit_button('Delete all subscribers', 'delete', 'delete_subscribers', false, array(
					'onclick' => 'return confirm("Are you sure you want to delete all the subscribers?");'
				)); ?>
			</form>
		</div>

		<style type="text/css">
			.wp-core-ui .button.delete {
				float: right;
				color: #fff;
				border-color: #d54e21;
				background: #d54e21;
			}
			.wp-core-ui .button.delete:hover,
			.wp-core-ui .button.delete:focus {
				border-color: #c2471e;
				background: #c2471e;
			}
			.wp-core-ui .button.delete:focus {
				box-shadow: 0 0 0 1px #fff, 0 0 0 3px #d54e21;
			}
		</style>
	<?php }

	/**
	 * Generates a text field in settings page
	 */
	public function do_text_field($args) {
		?>
		<input type="text"
			id="<?php echo $args['name']; ?>"
			name="<?php echo $args['name']; ?>"
			class="large-text"
			value="<?php echo $args['value']; ?>" 
			size="65"
			<?php if (isset($args['readonly']) && $args['readonly']) : echo "readonly"; endif; ?>>
		<?php
		$this->do_field_description($args);
	}

	/**
	 * Generates a checkbox field in settings page
	 */
	public function do_checkbox_field($args) {
		?>
		<input type="checkbox"
			id="<?php echo $args['name']; ?>"
			name="<?php echo $args['name']; ?>"
			<?php checked($args['value']); ?>
			value="1"
			<?php if (isset($args['readonly']) && $args['readonly']) : echo "readonly onclick='return false;'"; endif; ?>>
		<?php
		$this->do_field_description($args);
	}

	/**
	 * Generate the description for a field
	 */
	public function do_field_description($args) {
		if (isset( $args['description'])) :
		?>
			<p class="description">
				<?php print $args['description']; ?>
				<?php if ( isset( $args['example'] ) ) : ?>
					<br/><strong><?php _e( 'Example' ); ?>: </strong>
					<code><?php print $args['example']; ?></code>
				<?php endif; ?>
			</p>
		<?php
		endif;
	}

	/**
	 * Get access_token of logged in user.
	 */
	public static function get_access_token() {

		if ( ! is_user_logged_in() ) {
			return '';
		}
		return $_SESSION['oidc_access_token'];	
	}

	/**
     * Delete all subscribers
     */
	public static function delete_subscribers() {
		if ( ! current_user_can('administrator') )
			return;

		$all_subscribers = get_users('role=subscriber');

		foreach ($all_subscribers as $subscriber) {
			$user_meta = get_userdata($subscriber->ID);
			$user_roles = $user_meta->roles;

			// Check if 'subscriber' is the only role the user has
			if ( count($user_roles) == 1 && $user_roles[0] == 'subscriber' ) {
				wp_delete_user($subscriber->ID);
			}
		}
	}
}

$plugin = new BCC_Signon();

?>