<?php

class BCC_Login_Visibility {

    private BCC_Login_Settings $_settings;
    private BCC_Login_Client $_client;

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

    private $user_groups = array();
    private $custom_target_audience = array();
    private $post_types = array();

    function __construct( BCC_Login_Settings $settings, BCC_Login_Client $client ) {
        $this->_settings = $settings;
        $this->_client = $client;

        add_action( 'init', array( $this, 'on_init' ) );
        add_action( 'init', array( $this, 'register_bcc_target_groups_custom_post_type' ), 0 );
        add_action( 'init', array( $this, 'load_bcc_target_groups' ) );
        add_action( 'pre_get_posts', array( $this, 'order_bcc_target_groups' ) );
        add_action( 'wp_loaded', array( $this, 'register_block_visibility_attribute' ) );
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
        add_action( 'admin_enqueue_scripts', array( $this, 'bcc_enqueue_quick_edit_scripts' ) );
    }

    /**
     * Registers the `bcc_login_visibility` meta for posts and pages.
     */
    function on_init() {
        $this->post_types = apply_filters( 'visibility_post_types_filter', array( 'post', 'page' ) );

        foreach ( $this->post_types as $post_type ) {
            add_filter( "manage_{$post_type}_posts_columns", array( $this, 'add_post_audience_column' ) );
            add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'populate_post_audience_column'), 10, 2 );

            register_post_meta( $post_type, 'bcc_login_visibility', array(
                'show_in_rest' => current_user_can( 'edit_posts' ),
                'single'       => true,
                'type'         => 'number',
                'default'      => self::VISIBILITY_DEFAULT,
            ) );

            register_post_meta( $post_type, 'bcc_login_target_audience_visibility', array(
                'single'       => true,
                'type'         => 'array',
                'show_in_rest' => array(
                    'schema' => array(
                        'type'  => 'array',
                        'items' => array(
                            'type' => 'number',
                        ),
                    ),
                ),
                'auth_callback' => function() {
                    return current_user_can( 'edit_posts' );
                },
                'default'      => array(),
            ) );
        }
    }

    /**
     * Registers the `bcc-target-groups` custom post type for defining the target groups for visibility.
     */
    function register_bcc_target_groups_custom_post_type() {
        $labels = array(
            'name'                => _x( 'BCC target groups', 'Post Type General Name' ),
            'singular_name'       => _x( 'BCC target group', 'Post Type Singular Name' ),
            'menu_name'           => __( 'BCC target groups' ),
            'parent_item_colon'   => __( 'Parent target group' ),
            'all_items'           => __( 'All target groups' ),
            'view_item'           => __( 'View target group' ),
            'add_new_item'        => __( 'Add new target group' ),
            'add_new'             => __( 'Add new' ),
            'edit_item'           => __( 'Edit target group' ),
            'update_item'         => __( 'Update target group' ),
            'search_items'        => __( 'Search target group' ),
            'not_found'           => __( 'Not found' ),
            'not_found_in_trash'  => __( 'Not found in Trash' ),
        );
        
        $args = array(
            'label'               => __( 'bcc-target-groups' ),
            'description'         => __( 'Target groups' ),
            'labels'              => $labels,
            'supports'            => array( 'title', 'revisions', 'custom-fields' ),
            'hierarchical'        => false,
            'public'              => true,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'show_in_nav_menus'   => true,
            'show_in_admin_bar'   => true,
            'menu_position'       => 80,
            'menu_icon'           => 'dashicons-groups',
            'can_export'          => true,
            'has_archive'         => false,
            'exclude_from_search' => true,
            'publicly_queryable'  => false,
            'capability_type'     => 'post',
            'show_in_rest'        => false
        );

        register_post_type( 'bcc-target-groups', $args );
    }

    /**
     * Returns all `bcc-target-groups` registered in the admin dashboard.
     */
    function load_bcc_target_groups() {
        $user = BCC_Login_User::get_current_user_claims();

        $bcc_target_groups = new WP_Query(array(
            'post_type' => 'bcc-target-groups',
            'post_status' => 'publish',
            'posts_per_page' => '-1',
            'order' => 'ASC'
        ));

        if ($bcc_target_groups->have_posts()) :
            while ($bcc_target_groups->have_posts()) : $bcc_target_groups->the_post();

                if ( BCC_Login_Comparer::match( get_field('conditions', get_the_ID()), $user ) )
                    $this->user_groups[] = get_the_ID();

                $this->custom_target_audience[] = (object) [
                    'value' => get_the_ID(),
                    'label' => get_the_title()
                ];

            endwhile;
            wp_reset_postdata();
        endif;
    }

    function order_bcc_target_groups($query) {
        if ($query->get('post_type') == 'bcc-target-groups') {
            $query->set('orderby', 'title');
            $query->set('order', 'ASC');
        }
    }

    /**
     * Registers the `bccLoginVisibility` and `bccLoginTargetVisibility` attributes server-side to make
     * the `<ServerSideRender />` component render correctly in the Block Editor.
     */
    function register_block_visibility_attribute() {
        $registered_blocks = WP_Block_Type_Registry::get_instance()->get_all_registered();

        foreach( $registered_blocks as $name => $block ) {
            $block->attributes['bccLoginVisibility'] = array(
                'type'    => 'number',
                'default' => self::VISIBILITY_DEFAULT
            );
            $block->attributes['bccLoginTargetVisibility'] = array(
                'type'    => 'array',
                'items'   => [
                    'type' => 'number'
                ],
                'default' => array()
            );
        }
    }

    /**
     * Redirects current user to login if the post requires a higher level.
     *
     * @return void
     */
    function on_template_redirect() {
        if ($this->should_skip_auth()) {
            return;
        }

        $session_is_valid = $this->_client->is_session_valid();

        // Initiate new login if session has expired
        if ( is_user_logged_in() && !$session_is_valid ) {
            $this->_client->end_login();
            $this->_client->start_login();
            return;
        }

        // Show everything to editors
        if ( current_user_can( 'edit_posts' ) ) {
            return;
        }

        $post = get_post();
        $level = $this->get_current_user_level();

        $visibility = $this->_settings->default_visibility;
        $target_audience_visibility = array();

        if ( $post ) {
            $post_visibility = (int) get_post_meta( $post->ID, 'bcc_login_visibility', true );

            if ( $post_visibility ) {
                if ( in_array( $post_visibility, $this->levels ) )
                    $visibility = $post_visibility;
            }

            $target_audience_visibility = get_post_meta( $post->ID, 'bcc_login_target_audience_visibility', true );
        }

        if ( ( $visibility && $visibility > $level )
            || ( $target_audience_visibility && empty( array_intersect( $this->user_groups, $target_audience_visibility ) ) )
            || apply_filters( 'bcc_login_redirect_post_filter', false, $post, $this->user_groups )
        ) {
            if ( is_user_logged_in() ) {
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
            } else {
               $this->_client->start_login();
            }
        }
    }

    /**
     * Determines whether authentication should be skipped for this action
     */
    function should_skip_auth() {
        global $pagenow;

        if (! empty($this->_settings->feed_key) && array_key_exists('id', $_GET) && $this->_settings->feed_key == $_GET['id'] ) {
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

        if ( $key == 'bcc_login_target_audience_visibility' && is_array($value) && count($value) == 0 ) {
            delete_post_meta( $post_id, $key );
        }
    }

    /**
     * Loads the `src/visibility.js` script in Gutenberg.
     */
    function on_block_editor_assets() {
        $script_path   = BCC_LOGIN_PATH . 'build/visibility.asset.php';
        $script_url    = BCC_LOGIN_URL . 'build/visibility.js';
        $style_url     = BCC_LOGIN_URL . 'src/visibility.css';
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

        wp_enqueue_style(
            $script_handle,
            $style_url,
            array(),
            $script_asset['version']
        );

        wp_add_inline_script(
            $script_handle,
            'var bccLoginPostVisibility = ' . json_encode( array(
                'localName'         => $this->_settings->member_organization_name,
                'defaultLevel'      => self::VISIBILITY_DEFAULT,
                'levels'            => $this->levels,
                'targetAudience'    => $this->custom_target_audience
            ) ),
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
        if ( current_user_can( 'edit_posts' ) || $query->is_singular ) {
            return $query;
        }

        // Allow feeds to be accessed using key
        if ( $query->is_feed && ! empty($this->_settings->feed_key) && array_key_exists('id', $_GET) && $this->_settings->feed_key == $_GET['id'] ) {
            return $query;
        }

        // Get original meta query
        $meta_query = $query->get( 'meta_query' );

        if ( ! is_array( $meta_query ) )
            $meta_query = array();

        // Add visibility rules
        $visibility_rules = array(
            'key'     => 'bcc_login_visibility',
            'compare' => '<=',
            'value'   => $this->get_current_user_level()
        );

        // Include also posts where visibility isn't specified based on the Default Content Access
        if ( $this->get_current_user_level() >= $this->_settings->default_visibility ) {
            $visibility_rules = array(
                'relation' => 'OR',
                $visibility_rules,
                array(
                    'key'     => 'bcc_login_visibility',
                    'compare' => 'NOT EXISTS'
                )
            );
        }

        $meta_query[] = $visibility_rules;
        
        // Add target audience visibility rules
        $target_audience_visibility_rules = array(
            'relation' => 'OR'
        );

        foreach ($this->user_groups as $user_group) {
            $target_audience_visibility_rules[] = array(
                'key'          => 'bcc_login_target_audience_visibility',
                'compare'      => 'LIKE',
                'value'        => $user_group
            );
        }

        // Include also posts where visibility isn't specified based on the Default Content Access
        $target_audience_visibility_rules[] = array(
            'key'     => 'bcc_login_target_audience_visibility',
            'compare' => 'NOT EXISTS'
        );

        $meta_query[] = $target_audience_visibility_rules;

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
        if ( current_user_can( 'edit_posts' ) ) {
            return $items;
        }

        $level   = $this->get_current_user_level();
        $removed = array();

        foreach ( $items as $key => $item ) {
            // Don't render children of removed menu items.
            if ( in_array( $item->menu_item_parent, $removed, true ) ) {
                $removed[] = $item->ID;
                unset( $items[ $key ] );
                continue;
            }

            if ( in_array( $item->object, $this->post_types, true ) ) {
                $visibility = $this->_settings->default_visibility;
                $menu_visibility = (int) get_post_meta( $item->object_id, 'bcc_login_visibility', true );

                if ( $menu_visibility ) {
                    if ( in_array( $menu_visibility, $this->levels ) )
                        $visibility = $menu_visibility;
                }

                $target_audience_visibility = get_post_meta( $item->object_id, 'bcc_login_target_audience_visibility', true );

                if ( ( $visibility && $visibility > $level )
                    || ( $target_audience_visibility && empty( array_intersect( $this->user_groups, $target_audience_visibility ) ) )
                ) {
                    $removed[] = $item->ID;
                    unset( $items[ $key ] );
                }
            }
        }

        return $items;
    }

    /**
     * @return int
     */
    private function get_current_user_level() {
        $user  = wp_get_current_user();

        foreach ( $this->levels as $role => $level ) {
            if ( user_can( $user, $role ) ) {
                return $level;
            }
        }

        return self::VISIBILITY_PUBLIC;
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
            $visibility = $this->_settings->default_visibility;
            $block_visibility = (int) $block['attrs']['bccLoginVisibility'];

            if ( $block_visibility ) {
                if ( in_array( $block_visibility, $this->levels ) )
                    $visibility = $block_visibility;
            }

            $level = $this->get_current_user_level();

            if ( $visibility && $visibility > $level ) {
                return '';
            }
        }

        if ( isset( $block['attrs']['bccLoginTargetVisibility'] ) ) {
            $target_audience_visibility = $block['attrs']['bccLoginTargetVisibility'];

            if ( $target_audience_visibility && empty( array_intersect( $this->user_groups, $target_audience_visibility ) ) ) {
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

            if ( is_array($value) && count($value) == 0 ) {
                delete_post_meta( $menu_item_db_id, 'bcc_login_target_audience_visibility' );
            } else {
                update_post_meta( $menu_item_db_id, 'bcc_login_target_audience_visibility', $value );
            }
        }
    }

    // Quick Edit
    function add_post_audience_column( $columns ) {
        $columns['post_audience'] = __( 'Post Audience', 'bcc-login' );
        $columns['post_audience_name'] = __( 'Post Audience', 'bcc-login' );
        $columns['target_audience_visibility'] = __( 'Target Audience', 'bcc-login' );

        return $columns;
    }

    function populate_post_audience_column( $column_name, $id ) {
        switch( $column_name ) :
            case 'post_audience': {
                echo get_post_meta( $id, 'bcc_login_visibility', true );
                break;
            }
            case 'post_audience_name': {
                $visibility = $this->_settings->default_visibility;
                if ( $bcc_login_visibility = (int) get_post_meta( $id, 'bcc_login_visibility', true ) ) {
                    $visibility = $bcc_login_visibility;
                }
                echo $this->titles[ $visibility ];

                $target_audience_visibility = get_post_meta( $id, 'bcc_login_target_audience_visibility', true );
                $role_names = array();

                foreach ($target_audience_visibility as $role_id) {
                    $role_name = array_values(array_filter($this->custom_target_audience, function($role) use ($role_id) {
                        return $role->value == $role_id;
                    }));
                    if (!$role_name) continue;
        
                    $role_names[] = $role_name[0]->label;
                }

                echo count($role_names)
                    ? ' + ' . implode(', ', $role_names)
                    : implode(', ', $role_names);

                break;
            }
            case 'target_audience_visibility': {
                $target_audience_visibility = get_post_meta( $id, 'bcc_login_target_audience_visibility', true );
                echo implode(',', $target_audience_visibility);
                break;
            }
        endswitch;
    }

    function quick_edit_fields( $column_name, $post_type ) {
        switch( $column_name ) :
            case 'post_audience': {
                wp_nonce_field( 'bcc_q_edit_nonce', 'bcc_nonce' );

                echo '<fieldset class="inline-edit-col-right bcc-quick-edit">
                    <div class="inline-edit-col">
                        <div class="inline-edit-group wp-clearfix">
                            <label class="post-audience">
                                <span class="title" style="font-weight: bold; width: 100%;">Post Audience</span>
                                <span>';
                                    foreach ($this->titles as $level => $title) {
                                        echo '<input type="radio" name="bcc_login_visibility" id="option-'. $level .'" value="'. $level .'">
                                            <label for="option-'. $level .'">'. $title .'</label>';
                                    }
                                echo '</span>

                                <br />

                                <span class="title" style="font-weight: bold; width: 100%;">Target Audience</span>
                                <span>';
                                    foreach ($this->custom_target_audience as $role) {
                                        echo '<input type="checkbox" name="bcc_login_target_audience_visibility[]" id="option-'. $role->value .'" value="'. $role->value .'">
                                            <label for="option-'. $role->value .'">'. $role->label .'</label>';
                                    }
                                echo '</span>
                            </label>
                        </div>
                    </div>
                </fieldset>';

                break;
            }
        endswitch;
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

        if ( isset( $_POST['bcc_login_target_audience_visibility'] ) ) {
            update_post_meta( $post_id, 'bcc_login_target_audience_visibility', $_POST['bcc_login_target_audience_visibility'] );
        }
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

    /**
     * Deletes all `bcc_login_visibility` values from the database.
     */
    static function on_uninstall() {
        delete_metadata( 'post', 0, 'bcc_login_visibility', '', true );
        delete_metadata( 'post', 0, 'bcc_login_target_audience_visibility', '', true );
    }
}
