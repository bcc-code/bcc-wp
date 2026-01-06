<?php

class BCC_Login_Visibility {

    private BCC_Login_Settings $_settings;
    private BCC_Login_Client $_client;
    private BCC_Coreapi_Client $_coreapi;

    public const VISIBILITY_PUBLIC_ONLY = -1;
    public const VISIBILITY_DEFAULT = 0;
    public const VISIBILITY_PUBLIC = 1;
    public const VISIBILITY_SUBSCRIBER = 2;
    public const VISIBILITY_MEMBER = 3;

    // A mapping of role -> level.
    private $levels = array(
        'bcc-login-member' => self::VISIBILITY_MEMBER,
        'subscriber'       => self::VISIBILITY_SUBSCRIBER,
        'public'           => self::VISIBILITY_PUBLIC,
        'public-only'      => self::VISIBILITY_PUBLIC_ONLY
    );

    // A mapping of level -> title.
    private $titles = array(
        self::VISIBILITY_PUBLIC => 'Public',
        self::VISIBILITY_SUBSCRIBER => 'Logged In',
        self::VISIBILITY_MEMBER => 'Members',
        self::VISIBILITY_PUBLIC_ONLY => 'Not Logged In',
    );

    private $visibility_post_types = array( 'post', 'page', 'attachment', 'nav_menu_item' );
    private $post_types_allowing_filtering = array( 'post', 'page' );

    function __construct( BCC_Login_Settings $settings, BCC_Login_Client $client, BCC_Coreapi_Client $groups ) {
        $this->_settings = $settings;
        $this->_client = $client;
        $this->_coreapi = $groups;

        add_action( 'init', array( $this, 'on_init' ) );
        add_action( 'wp_loaded', array( $this, 'register_block_attributes' ) );
        add_action( 'template_redirect', array( $this, 'on_template_redirect' ), 0 );
        add_filter( 'rest_pre_echo_response', array( $this, 'on_rest_pre_echo_response' ), 10, 3 );

        add_action( 'added_post_meta', array( $this, 'on_meta_saved' ), 10, 4 );
        add_action( 'updated_post_meta', array( $this, 'on_meta_saved' ), 10, 4 );
        add_action( 'enqueue_block_editor_assets', array( $this, 'on_block_editor_assets' ) );
        add_action( 'pre_get_posts', array( $this, 'filter_pre_get_posts' ) );
        add_filter( 'wp_get_nav_menu_items', array( $this, 'filter_menu_items' ), 20 );
        add_filter( 'render_block', array( $this, 'on_render_block' ), 10, 2 );

        add_action( 'wp_nav_menu_item_custom_fields', array( $this, 'on_render_menu_item' ), 0, 5 );
        add_action( 'wp_update_nav_menu_item', array( $this, 'on_update_menu_item' ), 10, 3 );

        add_action( 'quick_edit_custom_box', array( $this, 'quick_edit_fields'), 10, 2 );
        add_action( 'save_post', array( $this, 'bcc_quick_edit_save' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'bcc_enqueue_visibility_scripts' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'bcc_enqueue_quick_edit_scripts' ) );

        add_shortcode( 'filtering_groups', array( $this, 'get_filtering_groups_list' ) );
        add_shortcode( 'post_group_tags_widget', array( $this, 'post_group_tags_widget' ) );
        add_shortcode( 'get_bcc_group_name', array( $this, 'get_bcc_group_name_by_id' ) );
        add_shortcode( 'bcc_my_roles', array( $this, 'bcc_my_roles' ) );

        add_action( 'add_meta_boxes', array( $this, 'add_visibility_meta_box_to_attachments' ) );
        add_action( 'attachment_updated', array( $this, 'save_visibility_to_attachments' ), 10, 3 );
    }

    /**
     * Registers the `bcc_login_visibility` meta for posts and pages.
     */
    function on_init() {
        $this->visibility_post_types = apply_filters( 'visibility_post_types_filter', $this->visibility_post_types );
        $this->post_types_allowing_filtering = apply_filters( 'post_types_for_filtering_target_groups', $this->post_types_allowing_filtering );

        foreach ( $this->visibility_post_types as $post_type ) {
            add_filter( "manage_{$post_type}_posts_columns", array( $this, 'add_post_audience_column' ) );
            add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'populate_post_audience_column'), 10, 2 );

            register_post_meta( $post_type, 'bcc_login_visibility', array(
                'show_in_rest' => true,
                'single'       => true,
                'type'         => 'number',
                'default'      => self::VISIBILITY_DEFAULT,
            ) );

            register_post_meta( $post_type, 'bcc_groups', array(
                'show_in_rest' => true,
                'single'       => false,
                'type'         => 'string'
            ) );

            register_post_meta( $post_type, 'bcc_groups_email', array(
                'show_in_rest' => true,
                'single'       => true,
                'type'         => 'boolean',
                'default'      => true,
            ) );

            register_post_meta( $post_type, 'bcc_visibility_groups', array(
                'show_in_rest' => true,
                'single'       => false,
                'type'         => 'string'
            ) );

            register_post_meta( $post_type, 'bcc_visibility_groups_email', array(
                'show_in_rest' => true,
                'single'       => true,
                'type'         => 'boolean',
                'default'      => false,
            ) );

            register_post_meta( $post_type, 'sent_notifications', array(
                'show_in_rest' => [
                    'schema' => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'date' => [
                                    'type'   => 'string',
                                    'format' => 'date-time', // ISO-8601 like 2025-12-12T09:15:00Z
                                ],
                                'notification_groups' => [
                                    'type'  => 'array',
                                    'items' => [
                                        'type' => 'string',
                                    ],
                                ],
                            ],
                            'required' => [ 'date', 'notification_groups' ],
                        ],
                    ],
                ],
                'single'            => true,
                'type'              => 'array',
                'default'           => [],
                'sanitize_callback' => array( $this, 'sanitize_sent_notifications_meta' ),
            ) );
        }
    }

    /**
     * Registers the `bccLoginVisibility` attribute server-side to make
     * the `<ServerSideRender />` component render correctly in the Block Editor.
     */
    function register_block_attributes() {
        $registered_blocks = WP_Block_Type_Registry::get_instance()->get_all_registered();

        foreach( $registered_blocks as $name => $block ) {
            $block->attributes['bccLoginVisibility'] = array(
                'type'    => 'number',
                'default' => self::VISIBILITY_DEFAULT,
            );

            $block->attributes['bccGroups'] = array(
                'type'    => 'array',
                'default' => array(),
            );
        }
    }

    /**
     * Redirects current user to login if the post requires a higher level.
     *
     * @return void
     */
    function on_template_redirect() {
        global $wp;

        if ($this->should_skip_auth()) {
            return;
        }

        $visited_url = add_query_arg( $wp->query_vars, home_url( $wp->request ) );

        $session_is_valid = $this->_client->is_session_valid();

        // Initiate new login if session has expired
        if ( is_user_logged_in() && !$session_is_valid ) {
            $this->_client->end_login();
            wp_safe_redirect( wp_login_url($visited_url) );
            exit;
        }

        // Show everything to editors
        if ( current_user_can( 'edit_posts' ) ) {
            return;
        }

        $post_id = 0;
        if ( is_home () ) {
            $post_id =  get_option('page_for_posts');
        } else if ( is_front_page() ) {
            $post_id =  get_option('page_on_front');
        }

        $post = $post_id ? get_post($post_id) : get_post();
        $level      = $this->_client->get_current_user_level();
        $visibility = (int)$this->_settings->default_visibility;        

        // Get visibility from current post
        if ($post) {
            $post_visibility = (int) get_post_meta( $post->ID, 'bcc_login_visibility', true );
            if ( $post_visibility ) {
                $visibility = $post_visibility;
            }
        }        

        if ( $visibility && $visibility > $level ) {                
            if ( is_user_logged_in() ) {
                return $this->not_allowed_to_view_page($visited_url);
            } else {
                wp_safe_redirect( wp_login_url($visited_url) );
                exit;
            }
        }

        if (!$post) {
            return;
        }

        if ( !empty($this->_settings->site_groups) ) {
            $post_target_groups = get_post_meta($post->ID, 'bcc_groups', false);
            $post_visibility_groups = get_post_meta($post->ID, 'bcc_visibility_groups', false);

            if (!$post_target_groups && !$post_visibility_groups) {
                return;
            }

            if ( !is_user_logged_in() ) {
                wp_safe_redirect( wp_login_url($visited_url) );
                exit;
            }

            $user_groups = $this->get_current_user_groups();
            if (!$user_groups) {
                return $this->not_allowed_to_view_page($visited_url);
            }

            $visibility_groups = $this->_settings->array_union($post_target_groups, $post_visibility_groups);
            $visibility_groups = $this->_settings->array_union($visibility_groups, $this->_settings->full_content_access_groups);

            if (count(array_intersect($visibility_groups, $user_groups)) == 0)
            {
                return $this->not_allowed_to_view_page($visited_url);
            }
        }
    }

    /**
     * Fail json request if the post requires a higher level.
     *
     * @return array
     */
    function on_rest_pre_echo_response( $response, $object, $request ) {
        $route = $request->get_route();
        $session_is_valid = $this->_client->is_session_valid();
        $user_level = (int) $this->_client->get_user_level_based_on_claims();
        $user_groups = $this->get_current_user_groups();

        if ( is_array($response) && array_key_exists('code', $response) && $response['code'] == 'rest_cookie_invalid_nonce' )
            return $response;

        if ( $route == '/wp/v2/search' ) {
            $response_arr = [];

            foreach ( $response as $item ) {
                $visibility = (int) $this->_settings->default_visibility;

                $post_visibility = (int) get_post_meta( $item['id'], 'bcc_login_visibility', true );
                if ( $post_visibility ) {
                    $visibility = $post_visibility;
                }

                if ( $session_is_valid ) {
                    // Simply add the post if the post visibility is default or public
                    if ( $visibility == self::VISIBILITY_DEFAULT || $visibility == self::VISIBILITY_PUBLIC ) {
                        $response_arr[] = $item;
                        continue;
                    }

                    // Check login visibility
                    if ( $visibility && $visibility > $user_level ) {
                        continue;
                    }

                    // Check user groups
                    if ( !empty($this->_settings->site_groups) && !current_user_can( 'edit_posts' ) ) {
                        $post_target_groups = get_post_meta( $item['id'] , 'bcc_groups', false );
                        $post_visibility_groups = get_post_meta( $item['id'] , 'bcc_visibility_groups', false );

                        $visibility_groups = $this->_settings->array_union($post_target_groups, $post_visibility_groups);

                        if ($visibility_groups && !$user_groups) {
                            continue;
                        }

                        $visibility_groups = $this->_settings->array_union($visibility_groups, $this->_settings->full_content_access_groups);

                        if ( count(array_intersect($visibility_groups, $user_groups)) == 0 ) {
                            continue;
                        }
                    }

                    $response_arr[] = $item;
                }
                else if ( $visibility <= self::VISIBILITY_PUBLIC ) {
                    // If the post visibility is public-only, default or public
                    $response_arr[] = $item;
                }
            }

            return $response_arr;
        }

        else if ( preg_match('#^/wp/v2/(' . implode('|', $this->visibility_post_types) . ')/(\d+)$#', $route, $matches) ) {
            $visibility = (int) $this->_settings->default_visibility;

            $post_visibility = (int) $response['meta']['bcc_login_visibility'];
            if ( $post_visibility ) {
                $visibility = $post_visibility;
            }

            if ( $session_is_valid ) {
                // Check login visibility
                if ( $visibility > $user_level ) {
                    return $this->not_allowed_to_view_page();
                }
    
                // Check user groups
                if ( !empty($this->_settings->site_groups) && !current_user_can( 'edit_posts' ) ) {
                    $post_target_groups = $response['meta']['bcc_groups'];
                    $post_visibility_groups = $response['meta']['bcc_visibility_groups'];

                    $visibility_groups = $this->_settings->array_union($post_target_groups, $post_visibility_groups);

                    if ( $visibility_groups && !$user_groups ) {
                        return $this->not_allowed_to_view_page();
                    }

                    $visibility_groups = $this->_settings->array_union($visibility_groups, $this->_settings->full_content_access_groups);

                    if ( count(array_intersect($visibility_groups, $user_groups)) == 0 ) {
                        return $this->not_allowed_to_view_page();
                    }
                }
            }
            else {
                if ( $visibility > self::VISIBILITY_PUBLIC ) {
                    return $this->not_allowed_to_view_page();
                }
            }
        }

        return $response;
    }

    private function not_allowed_to_view_page($visited_url = "") {
        wp_die(
            sprintf(
                '%s<br><br>%s<br><a href="%s">%s</a><br><br><a href="%s">%s</a>',
                __( 'Sorry, you are not allowed to view this page.', 'bcc-login' ),
                __( 'Are you logged in with the correct user?', 'bcc-login' ),
                wp_login_url($visited_url, true),
                __( 'Login with your user', 'bcc-login' ),
                site_url(),
                __( 'Go to the front page', 'bcc-login' )
            ),
            __( 'Unauthorized' ),
            array(
                'response' => 401,
            )
        );
    }

    /**
     * Determines whether authentication should be skipped for this action
     */
    function should_skip_auth() {
        global $pagenow;

        if (! empty($this->_settings->feed_key) && array_key_exists('id',$_GET) && $this->_settings->feed_key == $_GET['id'] ) {
            return true;
        }

        $login_action = get_query_var( 'bcc-login' );

        if (
            $login_action == 'logout'
        ) {
            return true;
        }

        return false;
    }

    /**
     * Removes the default level from the database.
     *
     * @param int    $mid
     * @param int    $post_id
     * @param string $key
     * @param int    $value
     * @return void
     */
    function on_meta_saved( $mid, $post_id, $key, $value ) {
        if ( $key == 'bcc_login_visibility' && (int) $value == self::VISIBILITY_DEFAULT ) {
            delete_post_meta( $post_id, $key );
        }
    }

    /**
     * Loads the `src/visibility.js` script in Gutenberg.
     */
    function on_block_editor_assets() {
        $script_path    = BCC_LOGIN_PATH . 'build/visibility.asset.php';
        $script_url     = BCC_LOGIN_URL . 'build/visibility.js';
        $script_handle = 'bcc-login-visibility';

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

        wp_add_inline_script(
            $script_handle,
            'var bccLoginPostVisibility = ' . json_encode( array(
                'localName'    => $this->_settings->member_organization_name,
                'defaultLevel' => self::VISIBILITY_DEFAULT,
                'levels'       => $this->levels,
            ) ),
            'before'
        );

        if (!empty($this->_settings->site_groups) ) {
            wp_add_inline_script(
                $script_handle,
                'var siteGroups = ' . json_encode($this->_coreapi->get_translated_site_groups()),
                'before'
            );
        } else {
            wp_add_inline_script(
                $script_handle,
                'var siteGroups = []',
                'before'
            );
        }

        if (!empty($this->_settings->site_group_tags) ) {
            wp_add_inline_script(
                $script_handle,
                'var siteGroupTags = ' . json_encode($this->_settings->site_group_tags),
                'before'
            );
        } else {
            wp_add_inline_script(
                $script_handle,
                'var siteGroupTags = []',
                'before'
            );
        }

        wp_add_inline_script(
            $script_handle,
            'var bccLoginNotificationDryRun = ' . ($this->_settings->notification_dry_run ? 'true' : 'false'),
            'before'
        );

        wp_add_inline_script(
            $script_handle,
            'var bccLoginNotificationPostTypes = ' . json_encode($this->_settings->notification_post_types),
            'before'
        );
    }

    /**
     * Filters out posts that the current user shouldn't see. This filter
     * applies to category lists and REST API results.
     *
     * @param WP_Query $query
     * @return WP_Query
     */
    function filter_pre_get_posts( $query ) {
        // Don't filter posts for Phrase
        // Check if Phrase (Memsource) is installed
        if ( class_exists('\Memsource\Utils\AuthUtils') ) {
            // Check if there's any token in the request
            if ( \Memsource\Utils\AuthUtils::getTokenFromRequest() != false ) {
                // Validate the token in the request
                if ( \Memsource\Utils\AuthUtils::validateTokenInRequest() ) {
                    return;
                }
            }
        }

        if ( current_user_can( 'edit_posts' ) || $query->is_singular ) {
            return;
        }

        // Don't filter posts for not supported post types
        // Menu items are e.g. handled in 'filter_menu_items()'
        if ( !$this->supports_visibility_filter($query) ) {
            return;
        }

        // Allow feeds to be accessed using key
        if ( $query->is_feed && ! empty($this->_settings->feed_key) && array_key_exists('id', $_GET) && $this->_settings->feed_key == $_GET['id'] ) {
            return;
        }

        // Get original meta query
        $meta_query = (array)$query->get('meta_query');

        // Check if visibility filters have already been added
        if ($query->get('bcc_login_visibility_filter_added')) {
            // Visibility filter has already been added - return
            return;
        }

        // Add visibility rules 
        $user_level = $this->_client->get_current_user_level();
        $visibility_clause = array();

        if ( is_user_logged_in() ) {
            // If user is logged in, they shouldn't see the public-only posts (-1)
            $visibility_clause = array(
                'key'   => 'bcc_login_visibility',
                'value' => array( 0, $user_level ),
                'type'  => 'NUMERIC',
                'compare' => 'BETWEEN',
            );
        } else {
            $visibility_clause = array(
                'key'     => 'bcc_login_visibility',
                'value'   => $user_level,
                'type'    => 'NUMERIC',
                'compare' => '<=',
            );
        }

        // Default: just the visibility clause
        $visibility_rules = $visibility_clause;

        // Include also posts where visibility isn't specified based on the Default Content Access
        if ( $user_level >= $this->_settings->default_visibility ) {
            $visibility_rules = array(
                'relation' => 'OR',
                array(
                    'key'     => 'bcc_login_visibility',
                    'compare' => 'NOT EXISTS'
                ),
                $visibility_clause
            );
        }

        // Default: only visibility rules
        $rules = $visibility_rules;

        $user_groups = $this->get_current_user_groups();

        // Filter posts which user should have access to - except when user has full content access
        if (empty($user_groups) || count(array_intersect($this->_settings->full_content_access_groups, $user_groups)) == 0) {
            $group_rules = array();

            // Use case when no group filters have been set
            $no_post_groups_rule = array(
                'relation' => 'AND',
                array(
                    'key' => 'bcc_groups',
                    'compare' => 'NOT EXISTS',
                ),
                array(
                    'key' => 'bcc_visibility_groups',
                    'compare' => 'NOT EXISTS'
                )
            );

            if (empty($user_groups)) {
                // If user has no groups - just check that no group filters have been set
                $group_rules = $no_post_groups_rule;
            } else {
                // If user has groups - check if no group filters have been set OR if user has access to the groups
                $group_rules = array(
                    'relation' => 'OR',
                    $no_post_groups_rule,
                    // Use case when user_groups is either in bcc_groups or bcc_visibility_groups
                    array(
                        'relation' => 'OR',
                        array(
                            'key' => 'bcc_groups',
                            'compare' => 'IN',
                            'value' => $user_groups
                        ),
                        array(
                            'key' => 'bcc_visibility_groups',
                            'compare' => 'IN',
                            'value' => $user_groups
                        )
                    )
                );
            }

            $rules = array(
                'relation' => 'AND',
                $visibility_rules,
                $group_rules,
            );
        }

        // Indicate that this set of rules is for the visibility filter
        $query->set('bcc_login_visibility_filter_added', 1);

        // Add all the rules to the meta query
        $meta_query = array(
            'relation' => 'AND',
            $meta_query,
            $rules,
        );

        // Set the meta query to the complete, altered query
        $query->set('meta_query', $meta_query);
    }

    /**
     * Filters out menu items that the current users shouldn't see.
     *
     * @param WP_Post[] $items
     * @return WP_Post[]
     */
    function filter_menu_items( $items ) {
        $level   = $this->_client->get_current_user_level();
        $removed = array();

        foreach ( $items as $key => $item ) {
            // Don't render children of removed menu items.
            if ( $item->menu_item_parent && in_array( $item->menu_item_parent, $removed, true ) ) {
                $removed[] = $item->ID;
                unset( $items[ $key ] );
                continue;
            }

            if ( $item->object == 'custom' || in_array( $item->object, $this->visibility_post_types, true ) ) {
                $visibility = (int) get_post_meta( $item->object_id, 'bcc_login_visibility', true );
                if (!$visibility) {
                    $visibility = $this->_settings->default_visibility;
                }

                // Hide public-only menu items for users who are logged in (including editors), but not in the Admin Dashboard
                if ( $visibility == self::VISIBILITY_PUBLIC_ONLY && is_user_logged_in() && !is_admin() ) {
                    $removed[] = $item->ID;
                    unset( $items[ $key ] );
                    continue;
                }

                // Otherwise, show everything to editors
                if ( current_user_can( 'edit_posts' ) || $this->_settings->show_protected_menu_items ) {
                    continue;
                }

                if ( $visibility && $visibility > $level ) {
                    $removed[] = $item->ID;
                    unset( $items[ $key ] );
                }

                // TODO: Check group-based visibility
            }
        }

        return $items;
    }

    private function get_current_user_groups() {
        if (empty($this->_settings->site_groups)) {
            return array();
        }

        $person_uid = $this->_client->get_current_user_person_uid();
        if (!$person_uid) {
            return array();
        }
        
        return $this->_coreapi->get_groups_for_user($person_uid);
    }

    // Get only Central Key Roles
    private function get_groups_of_the_roles_tag() {
        $site_groups = $this->_coreapi->get_translated_site_groups();
        $roles_tag_groups = array();

        // Take site groups belonging to "Roles" group tag
        foreach ($site_groups as $site_group) {
            if (in_array('Roles', $site_group->tags)) {
                $roles_tag_groups[] = $site_group;
            }
        }

        // Sort by name
        usort($roles_tag_groups, fn($a, $b) => $a->name <=> $b->name);

        return $roles_tag_groups;
    }

    public function get_filtering_groups_list() {
        $roles_tag_groups = $this->get_groups_of_the_roles_tag();

        if (current_user_can('edit_posts')) {
            // Show all site groups for admins
            return json_encode($roles_tag_groups);
        }

        $user_groups = $this->get_current_user_groups();

        if (!$user_groups) {
            return json_encode(array());
        }

        $user_site_groups = array();

        foreach ($roles_tag_groups as $group) {
            if (in_array($group->uid, $user_groups)) {
                $user_site_groups[] = $group;
            }
        }

        return json_encode($user_site_groups);
    }

    private function get_user_groups_list() {
        $roles_tag_groups = $this->get_groups_of_the_roles_tag();
        $user_groups = $this->get_current_user_groups();

        if (!$user_groups) {
            return array();
        }

        $user_site_groups = array();

        foreach ($roles_tag_groups as $group) {
            if (in_array($group->uid, $user_groups)) {
                $user_site_groups[] = $group;
            }
        }

        return $user_site_groups;
    }

    public function bcc_my_roles() {
        return json_encode($this->get_user_groups_list());
    }

    /**
     * Checks the `bccLoginVisibility` attribute and hides the block if
     * the current users shouldn't be allowed to see it.
     *
     * @param string $block_content
     * @param array $block
     * @return string
     */
    function on_render_block( $block_content, $block ) {

        $visibility_set = false;
        if ( isset( $block['attrs']['bccLoginVisibility'] ) ) {
            $visibility = (int) $block['attrs']['bccLoginVisibility'];
            $visibility_set = true;
        }

        // Hide public-only blocks for users who are logged in (including editors)
        if ( $visibility_set && $visibility == self::VISIBILITY_PUBLIC_ONLY && is_user_logged_in() ) {
            return '';
        }

        // Editors can see all other blocks
        if ( current_user_can( 'edit_posts' ) ) {
            return $block_content;
        }

        if ( $visibility_set ) {
            if (!$visibility) {
                $visibility = $this->_settings->default_visibility;
            }

            $level = $this->_client->get_current_user_level();

            if ( $visibility && $visibility > $level ) {
                return '';
            }
        }

        if ( isset( $block['attrs']['bccGroups'] ) ) {
            $block_groups = $block['attrs']['bccGroups'];
            if (empty($block_groups)) {
                return $block_content;
            }

            $user_groups = $this->get_current_user_groups();

            if (!$user_groups) {
                return '';
            }

            // Filter blocks which user should have access to 
            //- users with "full access" will still not be able to see blocks they are not in a group for (even if they can see the post)
            if (count(array_intersect($block_groups, $user_groups)) == 0) //&& count(array_intersect($this->_settings->full_content_access_groups, $user_groups)) == 0
            {
                return '';
            }
        }

        return $block_content;
    }

    /**
     * Shows "Menu Item Audience" options for custom menu items.
     *
     * @param int      $item_id Menu item ID.
     * @param WP_Post  $item    Menu item data object.
     * @param int      $depth   Depth of menu item. Used for padding.
     * @param stdClass $args    An object of menu item arguments.
     * @param int      $id      Nav menu ID.
     */
    function on_render_menu_item( $item_id, $item, $depth, $args, $id ) {
        if ( $item->type != 'custom' ) {
            // This only applies to custom menu items because items for posts
            // and pages are controlled by the post meta for the particular post.
            return;
        }
        $visibility = (int) get_post_meta( $item_id, 'bcc_login_visibility', true );
        if ( empty( $visibility ) ) {
            $visibility = self::VISIBILITY_DEFAULT;
        }
        ?>
            <p class="description description-wide">
                <strong><?php _e( 'Menu Item Audience', 'bcc-login' ) ?></strong>
                <?php foreach ( $this->levels as $key => $level ) : ?>
                <label class="description description-wide">
                    <input type="radio" name="creo-menu-item-visibility[<?php echo $item_id; ?>]" value="<?php echo esc_attr( $level ); ?>"<?php checked( $level == $visibility ); ?>>
                    <?php echo $this->titles[ $level ]; ?>
                </label>
                <?php endforeach; ?>
            <p>
        <?php
    }

    /**
     * @param int   $menu_id         ID of the updated menu.
     * @param int   $menu_item_db_id ID of the updated menu item.
     * @param array $args            An array of arguments used to update a menu item.
     */
    function on_update_menu_item( $menu_id, $menu_item_db_id, $args ) {
        $key = 'creo-menu-item-visibility';

        if ( isset( $_POST[ $key ][ $menu_item_db_id ] ) ) {
            $value = (int) $_POST[ $key ][ $menu_item_db_id ];
            if ( $value == self::VISIBILITY_DEFAULT ) {
                delete_post_meta( $menu_item_db_id, 'bcc_login_visibility' );
            } else {
                update_post_meta( $menu_item_db_id, 'bcc_login_visibility', $value );
            }
        }
    }

    // Quick Edit
    function add_post_audience_column( $columns ) {
        $columns['post_audience'] = __( 'Post Audience', 'bcc-login' );
        $columns['post_audience_name'] = __( 'Post Audience', 'bcc-login' );

        if (empty($this->_settings->site_groups)) {
            return $columns;
        }

        $columns['post_groups'] = __( 'Action required', 'bcc-login' );
        $columns['post_groups_name'] = __( 'Action required', 'bcc-login' );

        $columns['post_visibility_groups'] = __( 'For information', 'bcc-login' );
        $columns['post_visibility_groups_name'] = __( 'For information', 'bcc-login' );

        return $columns;
    }

    function populate_post_audience_column( $column_name, $id ) {
        if ($column_name == 'post_audience') {
            echo get_post_meta( $id, 'bcc_login_visibility', true );
            return;
        }

        if ($column_name == 'post_audience_name') {
            $visibility = $this->_settings->default_visibility;
            if ( $bcc_login_visibility = (int) get_post_meta( $id, 'bcc_login_visibility', true ) ) {
                $visibility = $bcc_login_visibility;
            }
            echo $this->titles[ $visibility ];
            return;
        }

        if (empty($this->_settings->site_groups)) {
            return;
        }

        if ($column_name == 'post_groups') {
            $post_target_groups = get_post_meta( $id, 'bcc_groups', false );
            $active_target_groups = array_intersect($post_target_groups, $this->_settings->site_groups);

            if (!$active_target_groups) {
                return;
            }

            echo join(",", $active_target_groups);
        }

        if ($column_name == 'post_visibility_groups') {
            $post_visibility_groups = get_post_meta( $id, 'bcc_visibility_groups', false );

            if ($post_visibility_groups) {
                $groups_string = join(",", $post_visibility_groups);
                echo $groups_string;
            }

            return;
        }

        if ($column_name == 'post_groups_name') {
            $post_target_groups = get_post_meta( $id, 'bcc_groups', false );
            $active_target_groups = array_intersect($post_target_groups, $this->_settings->site_groups);

            if (!$active_target_groups) {
                return;
            }

            $group_names = array();

            foreach ($active_target_groups as $post_group) {
                array_push($group_names, '<span class="group-name">' . $this->get_group_name($post_group) . '</span>');
            }

            echo join(", ", $group_names);
        }

        if ($column_name == 'post_visibility_groups_name') {
            $post_visibility_groups = get_post_meta( $id, 'bcc_visibility_groups', false );
            $active_visibility_groups = array_intersect($post_visibility_groups, $this->_settings->site_groups);

            if (!$active_visibility_groups) {
                return;
            }

            $group_names = array();

            foreach ($active_visibility_groups as $post_group) {
                array_push($group_names, '<span class="group-name">' . $this->get_group_name($post_group) . '</span>');
            }

            echo join(", ", $group_names);
        }
    }

    function quick_edit_fields( $column_name, $post_type ) {
        if ($column_name == 'post_audience') {
            wp_nonce_field( 'bcc_q_edit_nonce', 'bcc_nonce' );

            echo '<fieldset class="inline-edit-col-right bcc-quick-edit">
                <div class="inline-edit-col">
                    <div class="inline-edit-group wp-clearfix">
                        <label class="post-audience">
                            <span class="title">Post Audience</span>
                            <span>';
                                foreach ($this->titles as $level => $title) {
                                    echo '<input type="radio" name="bcc_login_visibility" id="option-'. $level .'" value="'. $level .'">
                                        <label for="option-'. $level .'">'. $title .'</label>';
                                }
                            echo '</span>
                        </label>
                    </div>
                </div>
            </fieldset>';
        }
    }

    function bcc_quick_edit_save( $post_id ){
        if ( !current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        if ( !isset( $_POST['bcc_nonce'] ) || !wp_verify_nonce( $_POST['bcc_nonce'], 'bcc_q_edit_nonce' ) ) {
            return;
        }

        if ( isset( $_POST['bcc_login_visibility'] ) ) {
            update_post_meta( $post_id, 'bcc_login_visibility', $_POST['bcc_login_visibility'] );
        }
    }

    function bcc_enqueue_visibility_scripts() {
        wp_enqueue_style( 'visibility-css', BCC_LOGIN_URL . 'src/visibility.css', false, date("ymd-Gis", filemtime(__DIR__ . '/../src/visibility.css')) );
    }

    function bcc_enqueue_quick_edit_scripts( $pagehook ) {
        // do nothing if we are not on the target pages
        if ( 'edit.php' != $pagehook ) {
            return;
        }

        wp_enqueue_style( 'quick-edit-css', BCC_LOGIN_URL . 'src/quick-edit.css', false, date("ymd-Gis", filemtime(__DIR__ . '/../src/quick-edit.css')) );
        wp_enqueue_script( 'quick-edit-js', BCC_LOGIN_URL . 'src/quick-edit.js', array( 'jquery' ) );
    }
    // end Quick Edit

    function get_group_name($group_uid) {
        if ($group_uid == 'all-members') {
            return __('All members', 'bcc-login');
        }

        foreach ($this->_coreapi->get_translated_site_groups() as $group) {
            if ($group->uid === $group_uid) {
                return $group->name;
            }
        }
        
        return "";
    }

    function post_group_tags_widget($atts) {
        $post_id = get_the_ID();
        if (!$post_id) return false;

        $attributes = shortcode_atts(
            array(
                'limit' => 100,
                'link' => '',
                'only_user_groups' => false
            ),
            $atts
        );

        // Convert to actual boolean
        $only_user_groups = filter_var($attributes['only_user_groups'], FILTER_VALIDATE_BOOLEAN);

        $post_target_groups_uids = get_post_meta($post_id, 'bcc_groups', false);
        $post_visibility_groups_uids = get_post_meta($post_id, 'bcc_visibility_groups', false);

        $central_key_roles = $this->get_groups_of_the_roles_tag();

        // If only_user_groups is set, filter out groups which the current user is not in
        if ($only_user_groups) {
            $user_groups = $this->get_current_user_groups();

            foreach ($central_key_roles as $key => $group) {
                if (!in_array($group->uid, $user_groups)) {
                    unset($central_key_roles[$key]);
                }
            }
        }

        $post_target_groups = array();
        $post_visibility_groups = array();

        // Get only post groups which are in Central Key Roles
        foreach ($central_key_roles as $role) {
            if (in_array($role->uid, $post_target_groups_uids)) {
                $post_target_groups[] = $role;
            }
            if (in_array($role->uid, $post_visibility_groups_uids)) {
                $post_visibility_groups[] = $role;
            }
        }

        // If limit is set, slice the arrays
        $post_target_groups = array_slice($post_target_groups, 0, $attributes['limit']);
        $post_visibility_groups = array_slice($post_visibility_groups, 0, $attributes['limit']);

        $html = '';

        if (count($post_target_groups)) {
            $html .= '<div class="bcc-target-groups">';
                $html .= '<strong>' . __('Action required', 'bcc-login') . ':</strong>';
                foreach ($post_target_groups as $role) {
                    $link = $attributes['link'] . '?target-groups[]=' . $role->uid;
                    $html .= '<a href="'. $link . '"><span class="member-overview__role-badge">' . $role->name . '</span></a>';
                }
            $html .= '</div>';
        }

        if (count($post_visibility_groups)) {
            $html .= '<div class="bcc-visibility-groups">';
                $html .= '<strong>' . __('For information', 'bcc-login') . ':</strong>';
                foreach ($post_visibility_groups as $role) {
                    $link = $attributes['link'] . '?target-groups[]=' . $role->uid;
                    $html .= '<a href="'. $link . '"><span class="member-overview__role-badge">' . $role->name . '</span></a>';
                }
            $html .= '</div>';
        }

        return $html;
    }

    function get_bcc_group_name_by_id($atts) {
        $attributes = shortcode_atts(array('uid' => ''), $atts);
        $uid = $attributes['uid'];
        if (!$uid)
            return;

        return $this->get_group_name($uid);
    }

    function supports_visibility_filter($query) {
        if (!array_key_exists('post_type', $query->query)){
            return true;
        }
        return in_array($query->query['post_type'], $this->visibility_post_types) && $query->query['post_type'] != 'nav_menu_item';
    }

    function supports_target_groups_filtering($query) {
        return array_key_exists('post_type', $query->query) && in_array($query->query['post_type'], $this->post_types_allowing_filtering);
    }

    /**
     * Registers visibility meta box for attachment.
     */
    function add_visibility_meta_box_to_attachments() {
        add_meta_box(
            'meta_box-bcc_login_visibility',
            __( 'Attachment Content Access', 'bcc-login' ),
            array( $this, 'render_visibility_meta_box_to_attachments' ),
            'attachment',
            'side'
        );
    }

    function render_visibility_meta_box_to_attachments( $post ) {
        $visibility = (int) get_post_meta( $post->ID, 'bcc_login_visibility', true );

        echo '<fieldset class="inline-edit-col-right bcc-quick-edit">
            <div class="inline-edit-col">
                <div class="inline-edit-group wp-clearfix">
                    <label class="post-audience">';
                        foreach ($this->titles as $level => $title) {
                            echo '<p class="bcc-login-visibility__choice">
                                <input type="radio" name="bcc_login_visibility" id="option-'. $level .'" value="'. $level .'"' . ($level == $visibility ? ' checked' : '') . '>
                                <label for="option-'. $level .'">'. $title .'</label>
                            </p>';
                        }
                    echo '</label>
                </div>
            </div>
        </fieldset>';

        wp_nonce_field( 'bcc_q_edit_nonce', 'bcc_nonce' );
    }

    /**
     * Save visibility value to attachments.
     */
    function save_visibility_to_attachments( $attach_id ) {
        if ( !current_user_can( 'edit_post', $attach_id ) ) {
            return;
        }

        if ( !isset( $_POST['bcc_nonce'] ) || !wp_verify_nonce( $_POST['bcc_nonce'], 'bcc_q_edit_nonce' ) ) {
            return;
        }

        if ( isset( $_POST['bcc_login_visibility'] ) ) {
            update_post_meta( $attach_id, 'bcc_login_visibility', $_POST['bcc_login_visibility'] );
        }
    }

    /**
     * Deletes all `bcc_login_visibility` values from the database.
     */
    static function on_uninstall() {
        delete_metadata( 'post', 0, 'bcc_login_visibility', '', true );
    }

    public static function sanitize_sent_notifications_meta( $value, $meta_key, $object_type ) {
        // Normalize to array
        if ( ! is_array( $value ) ) {
            return [];
        }

        $out = [];
        foreach ( $value as $item ) {
            if ( ! is_array( $item ) ) {
                continue;
            }

            $date = isset( $item['date'] ) && is_string( $item['date'] ) ? $item['date'] : null;
            $groups = isset( $item['notification_groups'] ) && is_array( $item['notification_groups'] )
                ? array_values( array_filter( $item['notification_groups'], fn( $uid ) => is_string( $uid ) && $uid !== '' ) )
                : [];

            if ( $date ) {
                $out[] = array(
                    'date' => $date,
                    'notification_groups' => $groups,
                );
            }
        }

        // Reindex
        return array_values( $out );
    }
}
