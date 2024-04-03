<?php

class BCC_Login_Visibility {

    private BCC_Login_Settings $_settings;
    private BCC_Login_Client $_client;
    private BCC_Coreapi_Client $_coreapi;

    public const VISIBILITY_DEFAULT = 0;
    public const VISIBILITY_PUBLIC = 1;
    public const VISIBILITY_SUBSCRIBER = 2;
    public const VISIBILITY_MEMBER = 3;

    // A mapping of role -> level.
    private $levels = array(
        'bcc-login-member' => self::VISIBILITY_MEMBER,
        'subscriber'       => self::VISIBILITY_SUBSCRIBER,
        'public'           => self::VISIBILITY_PUBLIC
    );

    // A mapping of level -> title.
    private $titles = array(
        self::VISIBILITY_PUBLIC => 'Public',
        self::VISIBILITY_SUBSCRIBER => 'Authenticated Users',
        self::VISIBILITY_MEMBER => 'Members'
    );

    private $visibility_post_types = array( 'post', 'page', 'nav_menu_item' );
    private $post_types_allowing_filtering = array( 'post', 'page' );

    function __construct( BCC_Login_Settings $settings, BCC_Login_Client $client, BCC_Coreapi_Client $groups ) {
        $this->_settings = $settings;
        $this->_client = $client;
        $this->_coreapi = $groups;

        add_action( 'init', array( $this, 'on_init' ) );
        add_action( 'wp_loaded', array( $this, 'register_block_attributes' ) );
        add_action( 'template_redirect', array( $this, 'on_template_redirect' ), 0 );
        add_action( 'added_post_meta', array( $this, 'on_meta_saved' ), 10, 4 );
        add_action( 'updated_post_meta', array( $this, 'on_meta_saved' ), 10, 4 );
        add_action( 'enqueue_block_editor_assets', array( $this, 'on_block_editor_assets' ) );
        add_filter( 'pre_get_posts', array( $this, 'filter_pre_get_posts' ) );
        add_filter( 'pre_get_posts', array( $this, 'filter_by_queried_target_groups' ) );
        add_filter( 'wp_get_nav_menu_items', array( $this, 'filter_menu_items' ), 20 );
        add_filter( 'render_block', array( $this, 'on_render_block' ), 10, 2 );

        add_action( 'wp_nav_menu_item_custom_fields', array( $this, 'on_render_menu_item' ), 0, 5 );
        add_action( 'wp_update_nav_menu_item', array( $this, 'on_update_menu_item' ), 10, 3 );

        add_action( 'quick_edit_custom_box', array( $this, 'quick_edit_fields'), 10, 2 );
        add_action( 'save_post', array( $this, 'bcc_quick_edit_save' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'bcc_enqueue_filtering_scripts' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'bcc_enqueue_visibility_scripts' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'bcc_enqueue_quick_edit_scripts' ) );

        add_shortcode('target_groups_filter_widget', array($this, 'target_groups_filter_widget'));
        add_shortcode('tags_for_queried_target_groups', array($this, 'tags_for_queried_target_groups'));
        add_shortcode('get_bcc_group_name', array($this, 'get_bcc_group_name_by_id'));
        add_shortcode('get_number_of_user_groups', array($this, 'get_number_of_user_groups'));
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
                'show_in_rest' => current_user_can( 'edit_posts' ),
                'single'       => true,
                'type'         => 'number',
                'default'      => self::VISIBILITY_DEFAULT,
            ) );
            register_post_meta( $post_type, 'bcc_groups', array(
                'show_in_rest' => current_user_can( 'edit_posts' ),
                'single'       => false,
                'type'         => 'string'
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
            wp_redirect( wp_login_url($visited_url) );
            return;
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
                return $this->not_allowed_to_view_page();
            } else {
                wp_redirect( wp_login_url($visited_url) );
                return;
            }
        }

        if (!$post) {

            return;
        }

        if ( !empty($this->_settings->site_groups) ) {
            $post_groups = get_post_meta($post->ID, 'bcc_groups', false);
            if (!$post_groups) {
                return;
            }



            if ( !is_user_logged_in() ) {
                wp_redirect( wp_login_url($visited_url) );
                return;
            }

            $user_groups = $this->get_current_user_groups();
            if (!$user_groups) {
                return $this->not_allowed_to_view_page();
            }

            if (count(array_intersect($post_groups, $user_groups)) == 0 &&
                count(array_intersect($this->_settings->full_content_access_groups, $user_groups)) == 0)
            {
                return $this->not_allowed_to_view_page();
            }
        }


    }

    private function not_allowed_to_view_page() {
        wp_die(
            sprintf(
                '%s<br><a href="%s">%s</a>',
                __( 'Sorry, you are not allowed to view this page.', 'bcc-login' ),
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

    }

    /**
     * Filters out posts that the current user shouldn't see. This filter
     * applies to category lists and REST API results.
     *
     * @param WP_Query $query
     * @return WP_Query
     */
    function filter_pre_get_posts( $query ) {
        if ( current_user_can( 'edit_posts' ) || $query->is_singular ) {
            return $query;
        }

        // Don't filter visibility for not supported post types
        // Menu items are e.g. handled in 'filter_menu_items()'
        if ( !$this->supports_visibility_filter($query))  {
            return $query;
        }

        // Allow feeds to be accessed using key
        if ( $query->is_feed && ! empty($this->_settings->feed_key) && array_key_exists('id', $_GET) && $this->_settings->feed_key == $_GET['id'] ) {
            return $query;
        }

        // Get original meta query
        $meta_query = (array)$query->get('meta_query');

        // Check if $meta_query already has visibility filters
        foreach ($meta_query as $meta_query_item) {
            if (is_array($meta_query_item) && array_key_exists('bcc-login-visibility', $meta_query_item)) {
                // Visibility filter has already been added - return
                return $query;
            }
        }

        // Add visibility rules 
        $rules = array(
            'key'     => 'bcc_login_visibility',
            'compare' => '<=',
            'value'   => $this->_client->get_current_user_level()
        );

        // Include also posts where visibility isn't specified based on the Default Content Access
        if ( $this->_client->get_current_user_level() >= $this->_settings->default_visibility ) {
            $rules = array(
                'relation' => 'OR',
                $rules,
                array(
                    'key'     => 'bcc_login_visibility',
                    'compare' => 'NOT EXISTS'
                )
            );
        }
       
        $user_groups = $this->get_current_user_groups();

        // Filter posts which user should have access to - except when user has full content access
        if (empty($user_groups) || count(array_intersect($this->_settings->full_content_access_groups, $user_groups)) == 0) {
            $group_rules = array();

            if (empty($user_groups)) {
                // If user has no groups - just check that no group filters have been set
                $group_rules = array(
                    'key' => 'bcc_groups',
                    'compare' => 'NOT EXISTS',
                );
            } else {
                // If user has groups - check if no group filters have been set OR if user has access to the groups
                $group_rules = array(
                    'relation' => 'OR',
                    array(
                        'key' => 'bcc_groups',
                        'compare' => 'NOT EXISTS',
                    ),
                    array(
                        'key' => 'bcc_groups',
                        'compare' => 'IN',
                        'value' => $user_groups
                    )
                );
            }    

            $rules = array(
                'relation' => 'AND',
                $rules,
                $group_rules
            );
        }

        // Indicate that this set of rules is for the visibility filter
        $rules['bcc-login-visibility'] = true;
        $meta_query[] = $rules;

        // Set the meta query to the complete, altered query
        $query->set('meta_query', $meta_query);

        return $query;
    }

    /**
     * Filters out posts that do not belong to the selected target groups.
     * This filter applies to category lists and REST API results.
     *
     * @param WP_Query $query
     * @return WP_Query
     */
    function filter_by_queried_target_groups($query) {
        $target_groups = wp_doing_ajax()
            ? (isset($_POST['target_groups']) ? $_POST['target_groups'] : null)
            : (isset($_GET['target-groups']) ? $_GET['target-groups'] : null);

        if (!$target_groups)
            return;

        if (wp_doing_ajax()) {
            // For Ajax requests
            if (!isset($_POST['post_type']) && in_array($_POST['post_type'], $this->post_types_allowing_filtering))
                return;
        }
        else {
            // Normal requests
            if (is_admin() || !$this->supports_target_groups_filtering($query))
                return;
        }

        // Get original meta query
        $meta_query = (array) $query->get('meta_query');
        $meta_query[] = array(
            'key'     => 'bcc_groups',
            'value'   => $target_groups,
            'compare' => 'IN'
        );

        // Filter by selected target groups
        $query->set('meta_query', $meta_query);
    }

    /**
     * Filters out menu items that the current users shouldn't see.
     *
     * @param WP_Post[] $items
     * @return WP_Post[]
     */
    function filter_menu_items( $items ) {
        if ( current_user_can( 'edit_posts' ) || $this->_settings->show_protected_menu_items ) {
            return $items;
        }

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

    private function get_user_bcc_filtering_groups_list() {
        $site_groups = $this->_coreapi->get_translated_site_groups();
        $filtering_groups = array();

        // Take only filtering groups from site groups 
        if (!empty($this->_settings->filtering_groups)) {
            foreach ($site_groups as $site_group) {
                if (in_array($site_group->uid, $this->_settings->filtering_groups)) {
                    $filtering_groups[] = $site_group;
                }
            }
        }

        $user_site_groups = array();

        if ( current_user_can( 'edit_posts' ) ) {
            // Show all site groups for admins
            $user_site_groups = $filtering_groups;
        }
        else {
            $user_groups = $this->get_current_user_groups();
            if (!$user_groups) {
                return array();
            }
            
            foreach ($filtering_groups as $site_group) {
                if (in_array($site_group->uid, $user_groups)) {
                    $user_site_groups[] = $site_group;
                }
            }
        }

        if (!is_array($user_site_groups))
            return array();

        // Sort by name
        usort($user_site_groups, fn($a, $b) => $a->name <=> $b->name);

        return $user_site_groups;
    }

    public function get_number_of_user_groups() {
        return count($this->get_user_bcc_filtering_groups_list());
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
        if ( current_user_can( 'edit_posts' ) ) {
            return $block_content;
        }

        if ( isset( $block['attrs']['bccLoginVisibility'] ) ) {
            $visibility = (int) $block['attrs']['bccLoginVisibility'];
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

            // Filter blocks which user should have access to - except when user has full content access
            if (count(array_intersect($block_groups, $user_groups)) == 0 &&
                count(array_intersect($this->_settings->full_content_access_groups, $user_groups)) == 0
            ) {
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
        $headingAudience = __( 'Post Audience', 'bcc-login' );

        $columns['post_audience'] = $headingAudience;
        $columns['post_audience_name'] = $headingAudience;

        if (!empty($this->_settings->site_groups)) {
            $headingGroups = __( 'Groups', 'bcc-login' );

            $columns['post_groups'] = $headingGroups;
            $columns['post_groups_name'] = $headingGroups;
        }

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
            $groups = get_post_meta( $id, 'bcc_groups', false );
            if ($groups) {
                $groups_string = join(",",$groups );
                echo $groups_string;
            }
            return;
        }

        if ($column_name == 'post_groups_name') {
            if (empty($this->_settings->site_groups)) {
                return;
            }

            $post_groups = get_post_meta( $id, 'bcc_groups', false );
            if (!$post_groups) {
                return;
            }

            $group_names = array();

            foreach ($post_groups as $post_group) {
                array_push($group_names, $this->get_group_name($post_group));
            }
            
            $groups_string = join(", ", $group_names);
            echo $groups_string;
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
        else if ($this->_settings->site_groups && $column_name == 'post_groups') {
            wp_nonce_field( 'bcc_q_edit_nonce', 'bcc_nonce' );
            echo '<fieldset class="inline-edit-col-right bcc-quick-edit">
                <div class="inline-edit-col">
                    <div class="inline-edit-group wp-clearfix">
                        <label class="post-audience">
                            <span class="title">' . __( 'Groups', 'bcc-login' ) . '</span>
                            <span>';
                                foreach ($this->_coreapi->get_translated_site_groups() as $ind => $group) {
                                    echo '<br><input type="checkbox" name="bcc_groups[]" id="option-'. $group->uid .'" value="'. $group->uid .'">
                                        <label for="option-'. $group->uid .'">'. $group->name .'</label>';
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

        if ( isset( $_POST['bcc_groups'] ) ) {
            foreach ($this->_settings->site_groups as $group) {
                delete_post_meta( $post_id, 'bcc_groups', $group );
            }
            foreach($_POST['bcc_groups'] as $group) {
                add_post_meta( $post_id, 'bcc_groups', $group );
            }
        }
    }

    function bcc_enqueue_filtering_scripts() {
        wp_enqueue_style( 'filtering-css', BCC_LOGIN_URL . 'src/filtering.css' );
        wp_enqueue_script( 'filtering-js', BCC_LOGIN_URL . 'src/filtering.js', array( 'jquery' ) );
    }

    function bcc_enqueue_visibility_scripts() {
        wp_enqueue_style( 'visibility-css', BCC_LOGIN_URL . 'src/visibility.css' );
    }

    function bcc_enqueue_quick_edit_scripts( $pagehook ) {
        // do nothing if we are not on the target pages
        if ( 'edit.php' != $pagehook ) {
            return;
        }

        wp_enqueue_style( 'quick-edit-css', BCC_LOGIN_URL . 'src/quick-edit.css' );
        wp_enqueue_script( 'quick-edit-js', BCC_LOGIN_URL . 'src/quick-edit.js', array( 'jquery' ) );
    }
    // end Quick Edit

    function get_group_name($group_uid) {
        foreach ($this->_coreapi->get_translated_site_groups() as $group) {
            if ($group->uid === $group_uid) {
                return $group->name;
            }
        }
        
        return "";
    }

    function target_groups_filter_widget() {
        $user_site_groups = $this->get_user_bcc_filtering_groups_list();
        $queried_target_groups = isset($_GET['target-groups']) ? $_GET['target-groups'] : array();

        $html = '<div class="bcc-filter">' .
            '<a href="javascript:void(0)" id="toggle-bcc-filter"> <svg xmlns="http://www.w3.org/2000/svg" height="1em" viewBox="0 0 448 512"><path d="M0 96C0 78.3 14.3 64 32 64H416c17.7 0 32 14.3 32 32s-14.3 32-32 32H32C14.3 128 0 113.7 0 96zM64 256c0-17.7 14.3-32 32-32H352c17.7 0 32 14.3 32 32s-14.3 32-32 32H96c-17.7 0-32-14.3-32-32zM288 416c0 17.7-14.3 32-32 32H192c-17.7 0-32-14.3-32-32s14.3-32 32-32h64c17.7 0 32 14.3 32 32z" fill="currentColor"/></svg> <span>' . __('Filters', 'bcc-login') . '</span></a>' .
            '<div id="bcc-filter-groups">' .
                '<a href="javascript:void(0)" id="close-bcc-groups">' . __('Close', 'bcc-login') . '</a>';
        
        $html .= '<ul>';
        foreach ($user_site_groups as $group) :
            $html .= '<li>' .
                '<input type="checkbox" id="'. $group->uid .'" value="'. $group->uid .'" name="target-groups[]"' . (in_array($group->uid, $queried_target_groups) ? 'checked' : '') . '/>' .
                '<label for="' . $group->uid . '"><div class="bcc-checkbox"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none"><path fill="#fff" d="M6.3 11.767a.498.498 0 0 1-.208-.042.862.862 0 0 1-.192-.125L2.883 8.583a.565.565 0 0 1-.166-.416c0-.167.055-.306.166-.417a.546.546 0 0 1 .4-.167c.156 0 .29.056.4.167L6.3 10.367l6-6a.546.546 0 0 1 .4-.167c.156 0 .295.056.417.167a.555.555 0 0 1 .166.408.555.555 0 0 1-.166.408L6.7 11.6a.862.862 0 0 1-.192.125.498.498 0 0 1-.208.042Z"/></svg></div>' . __( $group->name, 'bcc-login' )  . '</label>' .
            '</li>';
        endforeach;
        $html .= '</ul>';
        
        $html .= '</div>' .
        '</div>';

        return $html;
    }

    function tags_for_queried_target_groups() {
        $html = '';

        if (isset($_GET['target-groups'])) {
            $html .= '<div class="bcc-target-groups__filtered">';
                $html .= '<a href="javascript:void(0)" id="clear-bcc-groups">' . __('Clear all', 'bcc-login') . '</a>';

                foreach ($_GET['target-groups'] as $target_group) {
                    $html .= '<div class="bcc-target-groups__item">' .
                        '<span>' . $this->get_group_name($target_group) . '</span>' .
                        '<a href="javascript:void(0)" class="remove-bcc-group" data-group-id="' . $target_group . '">X</a>' .
                    '</div>';
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
     * Deletes all `bcc_login_visibility` values from the database.
     */
    static function on_uninstall() {
        delete_metadata( 'post', 0, 'bcc_login_visibility', '', true );
    }
}
