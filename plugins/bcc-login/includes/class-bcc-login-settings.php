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
    public $site_group_tags = array();
    public $site_groups = array();
    public $visibility_groups = array();
    public $notification_languages = array();
    public $notification_templates = array();
    public $notification_post_types = array();
    public $notification_dry_run = 0;
    public $full_content_access_groups = array();
    public $coreapi_audience;
    public $coreapi_base_url;
    public $disable_pubsub;
    public $widgets_base_url;
    public $track_clicks;
    public $track_page_load;
    public $track_page_interaction;

    public function array_union($x, $y)
    { 
        if (empty($x) && empty($y)){
            return [];
        }
        if (empty($x)){
            return $y;
        }
        if (empty($y)){
            return $x;
        }
        // Use array_merge to combine three arrays:
        // 1. Intersection of $x and $y
        // 2. Elements in $x that are not in $y
        // 3. Elements in $y that are not in $x
        $aunion = array_merge(
            array_intersect($x, $y),   // Intersection of $x and $y
            array_diff($x, $y),        // Elements in $x but not in $y
            array_diff($y, $x)         // Elements in $y but not in $x
        );

        // Return the resulting array representing the union
        return $aunion;
    }
}

/**
 * Provides settings
 */
class BCC_Login_Settings_Provider {
    private BCC_Login_Settings $_settings;
    private BCC_Coreapi_Client $_coreapi;
    private BCC_Storage $_storage;

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
        'widgets_base_url'          => 'BCC_WIDGETS_BASE_URL',
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
        $settings->disable_pubsub = false;
        $settings->widgets_base_url = 'https://widgets.bcc.no';
        $settings->track_clicks = false;
        $settings->track_page_load = true;
        $settings->track_page_interaction = true;

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

        $site_group_tags_option = get_option('bcc_site_group_tags');
        if ($site_group_tags_option) {
            $settings->site_group_tags = explode(",", $site_group_tags_option);
        }

        $site_groups_option = get_option('bcc_site_groups');
        if ($site_groups_option) {
            $settings->site_groups = explode(",", $site_groups_option);
        }

        $visibility_groups_option = get_option('bcc_visibility_groups');
        if ($visibility_groups_option) {
            $settings->visibility_groups = explode(",", $visibility_groups_option);
        }

        $settings->disable_pubsub = get_option('bcc_disable_pubsub');

        $full_content_access_groups_option = get_option('bcc_full_content_access_groups');
        if ($full_content_access_groups_option) {
            $settings->full_content_access_groups = explode(",", $full_content_access_groups_option);
        }

        $settings->notification_dry_run = get_option('bcc_notification_dry_run', 0);
        
        $notification_post_types_option = get_option('bcc_notification_post_types');
        if ($notification_post_types_option) {
            $settings->notification_post_types = explode(",", $notification_post_types_option);
        } else {
            $settings->notification_post_types = array('post', 'page');
        }

        $notification_languages_option = get_option('bcc_notification_languages');
        if ($notification_languages_option) {
            $settings->notification_languages = explode(",", $notification_languages_option);
        }

        foreach ( $settings->notification_languages as $key => $language ) {
            $settings->notification_templates[ $language ] = array();
            $settings->notification_templates[ $language ]['language'] = $language;

            $email_body_option = get_option('bcc_notification_' . $language . '_email_body');
            $settings->notification_templates[ $language ]['email_body'] = $email_body_option ? $email_body_option : ''; 
            
            $email_subject_option = get_option('bcc_notification_' . $language . '_email_subject');
            $settings->notification_templates[ $language ]['email_subject'] = $email_subject_option ? $email_subject_option : '';  

            $email_title_option = get_option('bcc_notification_' . $language . '_email_title');
            $settings->notification_templates[ $language ]['email_title'] = $email_title_option ? $email_title_option : ''; 
        }

        $track_clicks_option = get_option('bcc_track_clicks', -1);
        if ($track_clicks_option != -1) {
            $settings->track_clicks = $track_clicks_option;
        }

        $track_page_load_option = get_option('bcc_track_page_load', -1);
        if ($track_page_load_option != -1) {
            $settings->track_page_load = $track_page_load_option;
        }

        $track_page_interaction_option = get_option('bcc_track_page_interaction', -1);
        if ($track_page_interaction_option != -1) {
            $settings->track_page_interaction = $track_page_interaction_option;
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

        $this->_storage = new BCC_Storage($this->_settings->client_secret );
        $this->_coreapi = new BCC_Coreapi_Client($this->_settings, $this->_storage );

        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_bcc_login_config_script' ) );
        add_action( 'admin_menu', array( $this, 'add_options_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    function enqueue_bcc_login_config_script() {

        wp_enqueue_style( 'primereact-css', BCC_LOGIN_URL . 'src/primereact/fluent-light/theme.css' );

        $script_path    = BCC_LOGIN_PATH . 'build/settings.asset.php';
        $script_url     = BCC_LOGIN_URL . 'build/settings.js';
        $script_handle = 'bcc-login-settings';

        if ( ! file_exists( $script_path ) ) {
            return;
        }

        $script_asset = require $script_path;

        wp_enqueue_script(
            $script_handle,
            $script_url,
            $script_asset['dependencies'],
            $script_asset['version'],
            true
        );
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
        register_setting( $this->option_name, 'bcc_site_group_tags' );
        register_setting( $this->option_name, 'bcc_site_groups' );
        register_setting( $this->option_name, 'bcc_visibility_groups' );
        register_setting( $this->option_name, 'bcc_disable_pubsub' );
        register_setting( $this->option_name, 'bcc_notification_languages' );
        register_setting( $this->option_name, 'bcc_notification_post_types' );
        register_setting( $this->option_name, 'bcc_notification_dry_run' );
        register_setting( $this->option_name, 'bcc_full_content_access_groups' );
        register_setting( $this->option_name, 'show_protected_menu_items' );
        register_setting( $this->option_name, 'bcc_track_clicks' );
        register_setting( $this->option_name, 'bcc_track_page_load' );
        register_setting( $this->option_name, 'bcc_track_page_interaction' );

        $use_groups_settings = !empty($this->_settings->site_groups) || BCC_Coreapi_Client::check_groups_access(
            $this->_settings->token_endpoint,
            $this->_settings->client_id,
            $this->_settings->client_secret,
            $this->_settings->coreapi_audience
        );
        $use_notification_settings = $use_groups_settings; // Tie notification settings to groupsettings for now

        add_settings_section( 'general', '', null, $this->options_page );
        if ( $use_groups_settings )
        {
            add_settings_section( 'groups', __( 'Groups', 'bcc-login' ), null, $this->options_page );
        }
        if ( $use_notification_settings )
        {
            add_settings_section( 'notifications', __( 'Notifications', 'bcc-login' ), null, $this->options_page );
        }
        add_settings_section( 'analytics', __( 'Analytics', 'bcc-login' ), null, $this->options_page );

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
                    2 => __( 'Logged In', 'bcc-login' ),    
                    3 => __( 'Members', 'bcc-login' ),   
                    -1 => __( 'Not Logged In', 'bcc-login' ),                
                )
            )
        );

        add_settings_field(
            'bcc_member_organization_name',
            __( 'Member Organization', 'bcc-login' ),
            array( $this, 'render_tag_input_field' ),
            $this->options_page,
            'general',
            array(
                'name' => 'bcc_member_organization_name',
                'value' => $this->_settings->member_organization_name,
                'description' => 'List of locations'
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

        if ($use_groups_settings) {
            $all_groups = $this->_coreapi->get_all_groups();

            add_settings_field(
                'bcc_site_group_tags',
                'Group Tags',
                array( $this, 'render_tag_input_field' ),
                $this->options_page,
                'groups',
                array(
                    'name' => 'bcc_site_group_tags',
                    'value' => join(",", $this->_settings->site_group_tags),
                    'description' => 'List of tags to retrieve groups for. When adding tags, remember to first save before being able to select the new groups under Site Groups.'
                )
            );

            add_settings_field(
                'bcc_site_groups',
                'Site Groups',
                array( $this, 'render_group_selector_field' ),
                $this->options_page,
                'groups',
                array(
                    'targetGroupsName' => 'bcc_site_groups',
                    'targetGroupsValue' => join(",", $this->_settings->site_groups),
                    'visibilityGroupsName' => 'bcc_visibility_groups',
                    'visibilityGroupsValue' => join(",", $this->_settings->visibility_groups),
                    'description' => 'Provide group uids for groups you\'re going to use.',
                    'options' => $all_groups,
                    'tags' => $this->_settings->site_group_tags
                )
            );
            add_settings_field(
                'bcc_disable_pubsub',
                'Disable pubsub',
                array( $this, 'render_checkbox_field' ),
                $this->options_page,
                'groups',
                array(
                    'name' => 'bcc_disable_pubsub',
                    'value' => $this->_settings->disable_pubsub,
                    'label' => 'This will make the post group access settings only update once a day. Only use in very resource limited environments (staging)'
                )
            );
            add_settings_field(
                'bcc_full_content_access_groups',
                'Full Content Access Groups',
                array( $this, 'render_group_selector_field' ),
                $this->options_page,
                'groups',
                array(
                    'targetGroupsName' => 'bcc_full_content_access_groups',
                    'targetGroupsValue' => join(",", $this->_settings->full_content_access_groups),
                    'description' => 'Groups that always can see published content regardless of group settings on content.',
                    'options' => $all_groups,
                    'tags' => $this->_settings->site_group_tags
                )
            );
        }

        if ($use_notification_settings) {

            add_settings_field(
                'bcc_notification_post_types',
                'Notification Post Types',
                array( $this, 'render_tag_input_field' ),
                $this->options_page,
                'notifications',
                array(
                    'name' => 'bcc_notification_post_types',
                    'value' => join(",", $this->_settings->notification_post_types),
                    'description' => 'Post types that may receive notifications.'
                )
            );

            add_settings_field(
                'bcc_notification_dry_run',
                'Notification Dry Run',
                array( $this, 'render_checkbox_field' ),
                $this->options_page,
                'notifications',
                array(
                    'name' => 'bcc_notification_dry_run',
                    'value' => $this->_settings->notification_dry_run,
                    'label' => 'Don\'t actually send notifications, just simulate them.'
                )
            );

            add_settings_field(
                'bcc_notification_languages',
                'Notification Languages',
                array( $this, 'render_tag_input_field' ),
                $this->options_page,
                'notifications',
                array(
                    'name' => 'bcc_notification_languages',
                    'value' => join(",", $this->_settings->notification_languages),
                    'description' => 'List of languages that are supported for notification templates. E.g. nb_NO, en_US, de_DE, nl_NL, fr_FR etc.'
                )
            );

            foreach ($this->_settings->notification_languages as $language) {
                register_setting( $this->option_name, 'bcc_notification_' . $language . '_email_subject');
                register_setting( $this->option_name, 'bcc_notification_' . $language . '_email_title');
                register_setting( $this->option_name, 'bcc_notification_' . $language . '_email_body');


                add_settings_field(
                    'bcc_notification_' . $language . '_email_subject',
                    'Email Subject (' . $language . ')',
                    array( $this, 'render_text_field' ),
                    $this->options_page,
                    'notifications',
                    array(
                        'name' => 'bcc_notification_' . $language . '_email_subject',
                        'value' => array_key_exists($language, $this->_settings->notification_templates) ? $this->_settings->notification_templates[$language]["email_subject"] : '',
                        'description' => 'Email subject template for ' . $language . '. Use parameters like [firstName], [lastName], [postTitle], [postExcerpt]'
                    )
                );

                add_settings_field(
                    'bcc_notification_' . $language . '_email_title',
                    'Email Title (' . $language . ')',
                    array( $this, 'render_text_field' ),
                    $this->options_page,
                    'notifications',
                    array(
                        'name' => 'bcc_notification_' . $language . '_email_title',
                        'value' => array_key_exists($language, $this->_settings->notification_templates) ? $this->_settings->notification_templates[$language]["email_title"] : '',
                        'description' => 'Email subject template for ' . $language . '. Use parameters like [firstName], [lastName], [postTitle], [postExcerpt]'
                    )
                );

                add_settings_field(
                    'bcc_notification_' . $language . '_email_body',
                    'Email Body (' . $language . ')',
                    array( $this, 'render_textarea_field' ),
                    $this->options_page,
                    'notifications',
                    array(
                        'name' => 'bcc_notification_' . $language . '_email_body',
                        'value' => array_key_exists($language, $this->_settings->notification_templates) ? $this->_settings->notification_templates[$language]["email_body"] : '',
                        'description' => 'Email body template for ' . $language . '. Use parameters like [firstName], [lastName], [cta link="" text=""], [postTitle], [postExcerpt], [postUrl], [postImageUrl]'
                    )
                );
            }


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

        add_settings_field(
            'bcc_track_clicks',
            __( 'Track Clicks', 'bcc-login' ),
            array( $this, 'render_checkbox_field' ),
            $this->options_page,
            'analytics',
            array(
                'name' => 'bcc_track_clicks',
                'value' => $this->_settings->track_clicks,
                'label' => __( 'Track analytics for buttons and links', 'bcc-login' )
            )
        );

        add_settings_field(
            'bcc_track_page_load',
            __( 'Track Page Load', 'bcc-login' ),
            array( $this, 'render_checkbox_field' ),
            $this->options_page,
            'analytics',
            array(
                'name' => 'bcc_track_page_load',
                'value' => $this->_settings->track_page_load,
                'label' => __( 'Track analytics for page url, referrer etc.', 'bcc-login' )
            )
        );

        add_settings_field(
            'bcc_track_page_interaction',
            __( 'Track Page Interaction', 'bcc-login' ),
            array( $this, 'render_checkbox_field' ),
            $this->options_page,
            'analytics',
            array(
                'name' => 'bcc_track_page_interaction',
                'value' => $this->_settings->track_page_interaction,
                'label' => __( 'Track analytics for time spent on page', 'bcc-login' )
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

    function render_group_selector_field( $args ) { ?>
        <div id="<?php echo $args['targetGroupsName']; ?>-container"></div>
        <script type="text/javascript">
            document.addEventListener('DOMContentLoaded', function() {
                window.renderGroupSelector('<?php echo $args['targetGroupsName']; ?>-container', {
                    tags: <?php echo json_encode($args['tags']); ?>,
                    options: <?php echo json_encode($args['options']); ?>,
                    label: '<?php echo isset($args['label']) ? $args['label'] : ''; ?>',
                    targetGroupsName: '<?php echo $args['targetGroupsName']; ?>',
                    targetGroupsValue: <?php echo json_encode($args['targetGroupsValue']); ?>,
                    visibilityGroupsName: '<?php echo isset($args['visibilityGroupsName']) ? $args['visibilityGroupsName'] : ''; ?>',
                    visibilityGroupsValue: <?php echo isset($args['visibilityGroupsValue']) ? json_encode($args['visibilityGroupsValue']) : "''"; ?>,
                    readonly: <?php echo isset($args['readonly']) && $args['readonly'] ? 'true' : 'false'; ?>,
                });
            });
        </script>
        <?php
        $this->render_field_description($args);
    }

    function render_tag_input_field( $args ) { ?>
        <div id="<?php echo $args['name']; ?>-container"></div>
        <script type="text/javascript">
            document.addEventListener('DOMContentLoaded', function() {
                window.renderTagInput('<?php echo $args['name']; ?>-container', {
                    name: '<?php echo $args['name']; ?>',
                    label: '<?php echo isset($args['label']) ? $args['label'] : ''; ?>',
                    value: <?php echo json_encode($args['value']); ?>,
                    readonly: <?php echo isset($args['readonly']) && $args['readonly'] ? 'true' : 'false'; ?>
                });
            });
        </script>
        <?php
        $this->render_field_description($args);
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
            type="<?php echo isset( $args['numberOnly'] ) && $args['numberOnly'] ? 'number' : 'text'; ?>"
            id="<?php echo $args['name']; ?>"
            name="<?php echo $args['name']; ?>"   
            class="large-text"             
            value="<?php echo htmlspecialchars($args['value']); ?>"
            <?php echo isset( $args['readonly'] ) && $args['readonly'] ? 'readonly onclick="return false;"' : ''; ?>
            <?php echo isset( $args['numberOnly'] ) && $args['numberOnly'] ? 'min="0"' : ''; ?>
        >
        <?php
        $this->render_field_description( $args );
    }

        /**
     * Renders a text box in settings page.
     */
    function render_textarea_field( $args ) { ?>
        <textarea
            id="<?php echo $args['name']; ?>"
            name="<?php echo $args['name']; ?>"   
            class="large-text"
            <?php echo isset( $args['readonly'] ) && $args['readonly'] ? 'readonly onclick="return false;"' : ''; ?>
            rows="10"
         ><?php echo htmlspecialchars($args['value']); ?></textarea>
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
