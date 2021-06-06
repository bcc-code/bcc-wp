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
    public $local_organization_name;
    public $local_organization_claim_type;
    public $topbar;
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
        'local_organization_name'   => 'BCC_WP_LOCAL_ORGANIZATION_NAME'
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
        $settings->local_organization_claim_type = 'https://login.bcc.no/claims/churchName';
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

        // Backwards compatibility with old plugin configuration.
        if ( ! isset( $settings->client_id ) ) {
            $old_settings = (array) get_option( 'openid_connect_generic_settings', array () );
            if ( isset( $old_settings['client_id'] ) ) {
                $settings->client_id = $old_settings['client_id'];
            }
            if ( isset( $old_settings['client_secret'] ) ) {
                $settings->client_secret = $old_settings['client_secret'];
            }
        }
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

        add_settings_section( 'general', '', null, $this->options_page );

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
