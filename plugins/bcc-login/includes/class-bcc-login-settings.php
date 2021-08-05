<?php

class BCC_Login_Settings {
    public $authority;
    public $jwks_uri;
    public $token_endpoint;
    public $userinfo_endpoint;
    public $authorization_endpoint;
    public $end_session_endpoint;
    public $client_id;
    public $client_secret;
    public $scope;
    public $redirect_uri;
    public $create_missing_users;
    public $member_organization_name;
    public $member_organization_claim_type;
    public $topbar;
    public $default_visibility;
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
        'userinfo_endpoint'         => 'OIDC_ENDPOINT_USERINFO_URL',
        'token_endpoint'            => 'OIDC_ENDPOINT_TOKEN_URL',
        'end_session_endpoint'      => 'OIDC_ENDPOINT_LOGOUT_URL',
        'authority'                 => 'OIDC_AUTHORITY',
        'scope'                     => 'OIDC_SCOPE',
        'create_missing_users'      => 'OIDC_CREATE_USERS',
        'default_visibility'        => 'OIDC_DEFAULT_VISIBILITY',
        'member_organization_name'   => 'BCC_WP_member_organization_NAME'
    );

    function __construct () {
        // Set default settings.
        $settings = new BCC_Login_Settings();
        $settings->authority = 'https://login.bcc.no';
        $settings->token_endpoint = 'https://login.bcc.no/oauth/token';
        $settings->authorization_endpoint = 'https://login.bcc.no/authorize';
        $settings->userinfo_endpoint = 'https://login.bcc.no/userinfo';
        $settings->jwks_uri = 'https://login.bcc.no/.well-known/jwks.json';
        $settings->scope = 'email openid profile church';
        $settings->redirect_uri = 'oidc-authorize';
        $settings->create_missing_users = false;
        $settings->member_organization_claim_type = 'https://login.bcc.no/claims/churchName';
        $settings->topbar = get_option( 'bcc_topbar', 1 );

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
        $settings->default_visibility = get_option( 'default_visibility', $settings->default_visibility ?? 1 ); // default to public
        $settings->member_organization_name = get_option( 'member_organization_name', $settings->member_organization_name );


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
        $old_signon_settings = (array) get_option( 'bcc-signon-plugin-settings-group' , array () );
        if ( $old_signon_settings ) {
            if ( ! $settings->member_organization_name) {
                if ( isset ($old_signon_settings['bcc_local_church']) ) {
                    $settings->member_organization_name = $old_signon_settings['bcc_local_church'];
                }
            }
        }

        // Set defaults
        $settings->member_organization_name = $settings->member_organization_name ? $settings->member_organization_name :  get_bloginfo( 'blog_name' );

        $this->_settings = $settings;

        add_action( 'admin_menu', array( $this, 'add_options_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
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
        register_setting( $this->option_name, 'default_visibility' );
        register_setting( $this->option_name, 'member_organization_name' );

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
                'description' => 'OIDC variables can be configured using environment variables or constants in wpconfig.php'
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

        add_settings_field(
            'default_visibility',
            __( 'Default Access', 'bcc-login' ),
            array( $this, 'render_select_field' ),
            $this->options_page,
            'general',
            array(
                'name' => 'default_visibility',
                'value' => $this->_settings->default_visibility,
                'values' => array(
                    1 => __( 'Public', 'bcc-login' ),
                    2 => __( 'Authenticated Users', 'bcc-login' ),    
                    3 => __( 'Members', 'bcc-login' ),                   
                )
            )
        );

        add_settings_field(
            'member_organization_name',
            __( 'Member Organization', 'bcc-login' ),
            array( $this, 'render_text_field' ),
            $this->options_page,
            'general',
            array(
                'name' => 'member_organization_name',
                'value' => $this->_settings->member_organization_name
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
        </div>
        <?php
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
     * Get signon settings
     *
     * @return Sign-on settings
     */
    public function get_settings() : BCC_Login_Settings {
        return $this->_settings;
    }
}
