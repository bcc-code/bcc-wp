<?php

class BCC_Login_Settings {
    public $token_endpoint;
    public $authorization_endpoint;
    public $client_id;
    public $client_secret;
    public $scope;
    public $redirect_uri;
    public $create_missing_users;
    public $member_organization_name;
    public $member_organization_claim_type;
    public $has_membership_claim_type;
    public $topbar;
    public $default_visibility;
    public $feed_key;
    public $show_protected_menu_items;
    public $site_groups = array();
    public $full_content_access_groups = array();
    public $coreapi_audience;
    public $coreapi_base_url;
}

/**
 * Provides settings
 */
class BCC_Login_Settings_Provider {
    private BCC_Login_Settings $_settings;

    protected $option_name = 'bcc_login_settings';
    protected $options_page = 'bcc_login';

    /**
     * List of settings that can be defined by environment variables.
     *
     * @var array<string,string>
     */
    private $environment_variables = array(
        'client_id'                 => 'OIDC_CLIENT_ID',
        'client_secret'             => 'OIDC_CLIENT_SECRET',
        'authorization_endpoint'    => 'OIDC_ENDPOINT_LOGIN_URL',
        'token_endpoint'            => 'OIDC_ENDPOINT_TOKEN_URL',
        'scope'                     => 'OIDC_SCOPE',
        'create_missing_users'      => 'OIDC_CREATE_USERS',
        'default_visibility'        => 'OIDC_DEFAULT_VISIBILITY',
        'redirect_uri'              => 'OIDC_REDIRECT_URL',
        'member_organization_name'  => 'BCC_WP_MEMBER_ORGANIZATION_NAME',
        'feed_key'                  => 'BCC_WP_FEED_KEY',
        'show_protected_menu_items' => 'BCC_WP_SHOW_PROTECTED_MENU_ITEMS',
        'coreapi_audience'          => 'BCC_COREAPI_AUDIENCE',
        'coreapi_base_url'          => 'BCC_COREAPI_BASE_URL',
    );

    function __construct () {
        // Set default settings.
        $settings = new BCC_Login_Settings();
        $settings->token_endpoint = 'https://login.bcc.no/oauth/token';
        $settings->authorization_endpoint = 'https://login.bcc.no/authorize';
        $settings->scope = 'email openid profile church';
        $settings->redirect_uri = 'oidc-authorize';
        $settings->create_missing_users = false;
        $settings->member_organization_claim_type = 'https://login.bcc.no/claims/churchName';
        $settings->has_membership_claim_type = 'https://login.bcc.no/claims/hasMembership';
        $settings->topbar = get_option( 'bcc_topbar', 1 );
        $settings->show_protected_menu_items = get_option( 'show_protected_menu_items', 0);
        $settings->feed_key = get_option('bcc_feed_key', get_option('private_newsfeed_link', '') );
        $settings->coreapi_audience = 'api.bcc.no';
        $settings->coreapi_base_url = 'https://api.bcc.no';

        // Set settings from environment variables.
        foreach ( $this->environment_variables as $key => $constant ) {
            if ( defined( $constant ) && constant( $constant ) != '' ) {
                $settings->$key = constant( $constant );
            } else {
                $env = getenv( $constant );
                if ( isset( $env ) && ! is_null( $env ) && $env != '') {
                    $settings->$key = $env;
                }
            }
        }

        // Set settings from options
        $settings->default_visibility = get_option( 'bcc_default_visibility', $settings->default_visibility ?? 2 ); // default to authenticated users
        $settings->member_organization_name = get_option( 'bcc_member_organization_name', $settings->member_organization_name );

        $site_groups_option = get_option('bcc_site_groups');
        if ($site_groups_option) {
            $settings->site_groups = explode(",", $site_groups_option);
        }

        $full_content_access_groups_option = get_option('bcc_full_content_access_groups');
        if ($full_content_access_groups_option) {
            $settings->full_content_access_groups = explode(",", $full_content_access_groups_option);
        }

        // Backwards compatibility with old plugin configuration.
        if ( ! isset( $settings->client_id ) ) {
            $old_oidc_settings = (array) get_option( 'openid_connect_generic_settings', array () );
            if ( isset( $old_oidc_settings['client_id'] ) ) {
                $settings->client_id = $old_oidc_settings['client_id'];
            }
            if ( isset( $old_oidc_settings['client_secret'] ) ) {
                $settings->client_secret = $old_oidc_settings['client_secret'];
            }
        }       
        if ( empty( $settings->member_organization_name )) {
            $settings->member_organization_name = get_option('bcc_local_church'); // Replaced by bcc_member_organization_name
        }

        // Set defaults
        $settings->member_organization_name = $settings->member_organization_name ? $settings->member_organization_name :  get_bloginfo( 'blog_name' );

        // Generate feed key if not assigned
        if ( empty($settings->feed_key) ) {
            $settings->feed_key = strtolower(str_replace("-","",trim($this->createGUID(), '{}')));
            update_option('bcc_feed_key', $settings->feed_key);   
        }

        $this->_settings = $settings;

        add_action( 'admin_menu', array( $this, 'add_options_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'update_option_bcc_site_groups', array( $this, 'on_site_groups_option_update' ), 10, 3 );
    }

    /**
     * Registers the settings page under the «Settings» section.
     */
    function add_options_page() {
        add_options_page(
            __( 'BCC Login Settings', 'bcc-login' ),
            'BCC Login',
            'manage_options',
            $this->options_page,
            array( $this, 'render_options_page' )
        );
    }

    /**
     * Registers settings for the settings page.
     */
    function register_settings() {
        register_setting( $this->option_name, 'bcc_topbar' );
        register_setting( $this->option_name, 'bcc_default_visibility' );
        register_setting( $this->option_name, 'bcc_member_organization_name' );
        register_setting( $this->option_name, 'bcc_feed_key' );
        register_setting( $this->option_name, 'bcc_site_groups' );
        register_setting( $this->option_name, 'bcc_full_content_access_groups' );
        register_setting( $this->option_name, 'show_protected_menu_items' );

        add_settings_section( 'general', '', null, $this->options_page );

        add_settings_field(
            'client_id',
            __( 'ClientID', 'bcc-login' ),
            array( $this, 'render_text_field' ),
            $this->options_page,
            'general',
            array(
                'name' => 'client_id',
                'value' => $this->_settings->client_id,
                'label' => __( 'ClientID', 'client_id' ),
                'readonly' => 1,
                'description' => 'OIDC variables can be configured using environment variables or constants in wp-config.php. Commonly used variables: <i>OIDC_CLIENT_ID, OIDC_CLIENT_SECRET, OIDC_SCOPE</i>'
            )
        );
        
        add_settings_field(
            'bcc_default_visibility',
            __( 'Default Content Access', 'bcc-login' ),
            array( $this, 'render_select_field' ),
            $this->options_page,
            'general',
            array(
                'name' => 'bcc_default_visibility',
                'value' => $this->_settings->default_visibility,
                'values' => array(
                    1 => __( 'Public', 'bcc-login' ),
                    2 => __( 'Authenticated Users', 'bcc-login' ),    
                    3 => __( 'Members', 'bcc-login' ),                   
                )
            )
        );

        add_settings_field(
            'bcc_member_organization_name',
            __( 'Member Organization', 'bcc-login' ),
            array( $this, 'render_text_field' ),
            $this->options_page,
            'general',
            array(
                'name' => 'bcc_member_organization_name',
                'value' => $this->_settings->member_organization_name,
                'description' => 'Comma delimeted list of locations'
            )
        );

        add_settings_field(
            'bcc_feed_key',
            __( 'Feed Key', 'bcc-login' ),
            array( $this, 'render_text_field' ),
            $this->options_page,
            'general',
            array(
                'name' => 'bcc_feed_key',
                'value' => $this->_settings->feed_key,
                'label' => __( 'Feed', 'feed_key' ),
                'readonly' => 0,
                'description' => 'The following link can be used to retrieve protected content: <a target="_blank" href="' . get_site_url(null,'/feed') . '?id=' . $this->_settings->feed_key . '">' . get_site_url(null,'/feed') . '?id=' . $this->_settings->feed_key . '</a>'
            )
        );

        add_settings_field(
            'bcc_topbar',
            __( 'Topbar', 'bcc-login' ),
            array( $this, 'render_checkbox_field' ),
            $this->options_page,
            'general',
            array(
                'name' => 'bcc_topbar',
                'value' => $this->_settings->topbar,
                'label' => __( 'Show the BCC topbar', 'bcc-login' )
            )
        );

        if (!empty($this->_settings->site_groups) || BCC_Coreapi_Client::check_groups_access(
            $this->_settings->token_endpoint,
            $this->_settings->client_id,
            $this->_settings->client_secret,
            $this->_settings->coreapi_audience
        )) {
            add_settings_field(
                'bcc_site_groups',
                'Site Groups',
                array( $this, 'render_text_field' ),
                $this->options_page,
                'general',
                array(
                    'name' => 'bcc_site_groups',
                    'value' => join(",", $this->_settings->site_groups),
                    'description' => 'Provide group uids for groups you\'re going to use (comma delimited).'
                )
            );
        }

        if (!empty($this->_settings->full_content_access_groups) || BCC_Coreapi_Client::check_groups_access(
            $this->_settings->token_endpoint,
            $this->_settings->client_id,
            $this->_settings->client_secret,
            $this->_settings->coreapi_audience
        )) {
            add_settings_field(
                'bcc_full_content_access_groups',
                'Full Content Access Groups',
                array( $this, 'render_text_field' ),
                $this->options_page,
                'general',
                array(
                    'name' => 'bcc_full_content_access_groups',
                    'value' => join(",", $this->_settings->full_content_access_groups),
                    'description' => 'Groups that always can see published content regardless of group settings on content.'
                )
            );
        }

        add_settings_field(
            'show_protected_menu_items',
            __( 'Show Protected Menu Items', 'bcc-login' ),
            array( $this, 'render_checkbox_field' ),
            $this->options_page,
            'general',
            array(
                'name' => 'show_protected_menu_items',
                'value' => $this->_settings->show_protected_menu_items,
                'label' => __( 'Show protected menu items to all/public users.', 'bcc-login' )
            )
        );
    }

    /**
     * Renders the options page.
     */
    function render_options_page() { ?>
        <div class="wrap">
            <h1><?php _e( 'BCC Login Settings', 'bcc-login' ); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields( $this->option_name ); ?>
                <?php do_settings_sections( $this->options_page ); ?>
                <?php submit_button(); ?>
            </form>
            <?php $this->render_delete_subscribers_button( ); ?>
            <?php settings_errors('subscribers_deleted'); ?>
        </div>
        <?php
    }

    /**
     * Renders a button for deleting subscribers
     */
    function render_delete_subscribers_button () {
        if ( ! current_user_can('administrator') )
            return;        
        ?> 	
            <hr>
            <h2><?php _e( 'Maintenance', 'bcc-login' ); ?></h2>		
            <p><?php _e( 'We recommend deleting subscribers that were automatically generated by earlier versions of the login plugin.', 'bcc-login') ?></p>
            <form method="post">
				<input type="hidden" name="_wp_http_referer" value="<?php echo add_query_arg( 'delete_subscribers', 'true', wp_get_referer() ) ?>">
				<?php submit_button('Delete subscribers', 'delete', 'delete_subscribers', false, array(
					'onclick' => 'return confirm("Are you sure you want to delete all the subscribers?");'
				)); ?>
			</form> 
        <?php
        $this->delete_subscribers_handler();
    }

    /**
     * Handles deletion of subscribers
     */
    function delete_subscribers_handler() {
        if ( strpos(wp_get_referer(), 'delete_subscribers=true') !== false) {
            global $wpdb;

            $sql = "DELETE {$wpdb->prefix}users, {$wpdb->prefix}usermeta
                    FROM {$wpdb->prefix}users
                    INNER JOIN {$wpdb->prefix}usermeta ON {$wpdb->prefix}users.ID = {$wpdb->prefix}usermeta.user_id
                    WHERE
                        meta_key = '{$wpdb->prefix}capabilities' AND
                        meta_value LIKE 'a:1:{s:10:\"subscriber\";b:1;}'";

            $result = $wpdb->get_results($sql);

            add_settings_error(
                'subscribers_deleted',
                'subscribers_deleted',
                __( 'All subscribers were successfully deleted.' )  . ' – ' . implode(', ', $result),
                'success'
            );
        }
    }

    /**
     * Renders a checkbox field in settings page.
     */
    function render_checkbox_field( $args ) { ?>
        <label>
            <input
                type="checkbox"
                id="<?php echo $args['name']; ?>"
                name="<?php echo $args['name']; ?>"
                <?php checked($args['value']); ?>
                value="1"
                <?php echo isset( $args['readonly'] ) && $args['readonly'] ? 'readonly onclick="return false;"' : ''; ?>
            >
            <?php echo isset( $args['label'] ) ? $args['label'] : ''; ?>
        <label>
        <?php
        $this->render_field_description( $args );
    }

    /**
     * Renders a text box in settings page.
     */
    function render_text_field( $args ) { ?>
        <input
            type="text"
            id="<?php echo $args['name']; ?>"
            name="<?php echo $args['name']; ?>"   
            class="large-text"             
            value="<?php echo htmlspecialchars($args['value']); ?>"
            <?php echo isset( $args['readonly'] ) && $args['readonly'] ? 'readonly onclick="return false;"' : ''; ?>
        >
        <?php
        $this->render_field_description( $args );
    }

    /**
     * Renders a select field in settings page.
     */
    function render_select_field( $args ) { ?>
        <label for="<?php echo $args['name']; ?>">
            <select
                id="<?php echo $args['name']; ?>"
                name="<?php echo $args['name']; ?>"
                <?php echo isset( $args['readonly'] ) && $args['readonly'] ? 'readonly onclick="return false;"' : ''; ?>
            >
            <?php
                foreach($args['values'] as $value => $label) {
                    if ( $value == $args['value'] ) {
                        echo '<option selected value="' . $value . '">' . htmlspecialchars($label) . '</option>';
                    } else {
                        echo '<option value="' . $value . '">' . htmlspecialchars($label) . '</option>';
                    }
                }
            ?>
            </select>
        <label>
        <?php
        $this->render_field_description( $args );
    }

    /**
     * Renders the description for a field.
     */
    function render_field_description( $args ) {
        if ( isset( $args['description'] ) ) : ?>
            <p class="description">
                <?php echo $args['description']; ?>
                <?php if ( isset( $args['example'] ) ) : ?>
                    <br/><strong><?php _e( 'Example', 'bcc-login' ); ?>: </strong>
                    <code><?php echo $args['example']; ?></code>
                <?php endif; ?>
            </p>
        <?php endif;
    }

    /**
     * Action hook for bcc_site_groups option update.
     */
    function on_site_groups_option_update($old_value, $value, $option) {
        delete_transient('coreapi_groups');
    }

    /**
     * Get signon settings
     *
     * @return Sign-on settings
     */
    public function get_settings() : BCC_Login_Settings {
        return $this->_settings;
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
}
