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

    private $post_types = array( 'post', 'page' );

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
        add_filter( 'wp_get_nav_menu_items', array( $this, 'filter_menu_items' ), 20 );
        add_filter( 'render_block', array( $this, 'on_render_block' ), 10, 2 );

        add_action( 'wp_nav_menu_item_custom_fields', array( $this, 'on_render_menu_item' ), 0, 5 );
        add_action( 'wp_update_nav_menu_item', array( $this, 'on_update_menu_item' ), 10, 3 );

        add_action( 'quick_edit_custom_box', array( $this, 'quick_edit_fields'), 10, 2 );
        add_action( 'save_post', array( $this, 'bcc_quick_edit_save' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'bcc_enqueue_filtering_scripts' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'bcc_enqueue_quick_edit_scripts' ) );
    }

    /**
     * Registers the `bcc_login_visibility` meta for posts and pages.
     */
    function on_init() {
        $this->post_types = apply_filters( 'visibility_post_types_filter', $this->post_types );

        foreach ( $this->post_types as $post_type ) {
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

        $post = get_post();
        $level      = $this->_client->get_current_user_level();
        $visibility = $this->_settings->default_visibility;

        // Post may not be defined when the user is visiting the homepage
        if ( !$post ) {
            if ($visibility > $level) {
                if ( is_user_logged_in() ) {
                    return $this->not_allowed_to_view_page();
                } else {
                    wp_redirect( wp_login_url("/") );
                }
            }
            return;
        }

        $post_visibility = (int) get_post_meta( $post->ID, 'bcc_login_visibility', true );
        if ( $post_visibility ) {
            $visibility = $post_visibility;
        }


        if ( $visibility && $visibility > $level ) {
            if ( is_user_logged_in() ) {
                return $this->not_allowed_to_view_page();
            } else {
                wp_redirect( wp_login_url($visited_url) );
            }
        }

        if(!empty($this->_settings->site_groups)){
            $post_groups = get_post_meta( $post->ID, 'bcc_groups', false );
            if (!$post_groups) {
                return;
            }
            $user_groups = $this->get_current_user_groups();
            if (!$user_groups) {
                return $this->not_allowed_to_view_page();
            }
            if(count(array_intersect($post_groups, $user_groups)) == 0) {
                return $this->not_allowed_to_view_page();
            }
        }

    }

    private function not_allowed_to_view_page(){
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
                'var siteGroups = ' . json_encode($this->_coreapi->get_site_groups()),
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

        // Don't filter menu items. They are handled in 'filter_menu_items()'
        if ( array_key_exists('post_type', $query->query) && $query->query['post_type'] === 'nav_menu_item' ) {
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

        if(!empty($this->_settings->site_groups)) {
            $user_groups = $this->get_current_user_groups();
            $group_rules = array();

            if (empty($user_groups)) {
                // If user has no groups - just check that no group filters have been set
                $group_rules = array(
                    'key' => 'bcc_groups',
                    'compare' => 'NOT EXISTS',
                );
            } else {
                // If user has groups - check if no group filters have been set ORE
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
                    ),
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

            if ( in_array( $item->object, $this->post_types, true ) ) {
                $visibility = (int) get_post_meta( $item->object_id, 'bcc_login_visibility', true );
                if (!$visibility) {
                    $visibility = $this->_settings->default_visibility;
                }

                if ( $visibility && $visibility > $level ) {
                    $removed[] = $item->ID;
                    unset( $items[ $key ] );
                }
            }
        }

        return $items;
    }

    private function get_current_user_groups() {
        if (empty($this->_settings->site_groups)) {
            return array();
        }

        $person_uid  = $this->_client->get_current_user_person_uid();
        if(!$person_uid) {
            return array();
        }
        return $this->_coreapi->get_groups_for_user($person_uid);
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
            if(count(array_intersect($block_groups, $user_groups)) == 0) {
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
                <?php foreach ( $this->levels as $key => $level ): ?>
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

        if(empty($this->_settings->site_groups)) {
            return;
        }

        if ($column_name == 'post_groups') {
            $groups = get_post_meta( $id, 'bcc_groups', false );
            if($groups) {
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
            if(!$post_groups) {
                return;
            }

            $group_names = array();

            foreach ($post_groups as $post_group) {
                array_push($group_names, $this->get_group_name($post_group));
            }
            
            $groups_string = join(", ",$group_names );
            echo $groups_string;
        }
    }

    function quick_edit_fields( $column_name, $post_type ) {
        if($column_name == 'post_audience') {
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
                            <span class="title">Groups</span>
                            <span>';
                                foreach ($this->_coreapi->get_site_groups() as $ind => $group) {
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
        foreach ($this->_coreapi->get_site_groups() as $group) {
            if ($group->uid === $group_uid) {
                return $group->name;
            }
        }
        return "";
    }

    /**
     * Deletes all `bcc_login_visibility` values from the database.
     */
    static function on_uninstall() {
        delete_metadata( 'post', 0, 'bcc_login_visibility', '', true );
    }
}
