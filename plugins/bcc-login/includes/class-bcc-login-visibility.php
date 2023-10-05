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

    private $post_types = array( 'post', 'page' );

    function __construct( BCC_Login_Settings $settings, BCC_Login_Client $client ) {
        $this->_settings = $settings;
        $this->_client = $client;

        add_action( 'init', array( $this, 'on_init' ) );
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

        foreach ( $this->post_types as $post_type ) {
            add_filter( "manage_{$post_type}_posts_columns", array( $this, 'add_post_audience_column' ) );
            add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'populate_post_audience_column'), 10, 2 );
        }

        add_action( 'quick_edit_custom_box', array( $this, 'quick_edit_fields'), 10, 2 );
        add_action( 'save_post', array( $this, 'bcc_quick_edit_save' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'bcc_enqueue_quick_edit_scripts' ) );
    }

    /**
     * Registers the `bcc_login_visibility` meta for posts and pages.
     */
    function on_init() {
        foreach ( $this->post_types as $post_type ) {
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
    function register_block_visibility_attribute() {
        $registered_blocks = WP_Block_Type_Registry::get_instance()->get_all_registered();

        foreach( $registered_blocks as $name => $block ) {
            $block->attributes['bccLoginVisibility'] = array(
                'type'    => 'number',
                'default' => self::VISIBILITY_DEFAULT,
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

        $level      = $this->get_current_user_level();
        $visibility = $this->_settings->default_visibility;
        if ( $post ) {
            $post_visibility = (int) get_post_meta( $post->ID, 'bcc_login_visibility', true );
            if ( $post_visibility ) {
                $visibility = $post_visibility;
            }
        }

        if ( $visibility && $visibility > $level ) {
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
        $scrcipt_handle = 'bcc-login-visibility';

        if ( ! file_exists( $script_path ) ) {
            return;
        }

        $script_asset = require $script_path;

        wp_enqueue_script(
            $scrcipt_handle,
            $script_url,
            $script_asset['dependencies'],
            $script_asset['version'],
            true
        );

        wp_add_inline_script(
            $scrcipt_handle,
            'var bccLoginPostVisibility = ' . json_encode( array(
                'localName'    => $this->_settings->member_organization_name,
                'defaultLevel' => self::VISIBILITY_DEFAULT,
                'levels'       => $this->levels,
            ) ),
            'before'
        );

        wp_add_inline_script(
            $scrcipt_handle,
            'var allowedGroups = ' . json_encode($this->_settings->groups_allowed),
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

        // Don't filter menu items. They are handled in 'filter_menu_items()'
        if ( $query->query['post_type'] === 'nav_menu_item' ) {
            return $query;
        }

        // Allow feeds to be accessed using key
        if ( $query->is_feed && ! empty($this->_settings->feed_key) && array_key_exists('id',$_GET) && $this->_settings->feed_key == $_GET['id'] ) {
            return $query;
        }

        // Get original meta query
        $meta_query = (array)$query->get('meta_query');

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
        if ( is_admin() || is_super_admin() ) {
            return $block_content;
        }

        if ( isset( $block['attrs']['bccLoginVisibility'] ) ) {
            $visibility = (int) $block['attrs']['bccLoginVisibility'];
            if (!$visibility) {
                $visibility = $this->_settings->default_visibility;
            }

            $level      = $this->get_current_user_level();

            if ( $visibility && $visibility > $level ) {
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

        $headingGroups = __( 'Groups', 'bcc-login' );

        $columns['post_groups'] = $headingGroups;

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
                break;
            }
            case 'post_groups': {
                $groups = get_post_meta( $id, 'bcc_groups', false );
                if($groups) {
                    error_log("Print post groups");
                    error_log(print_r($groups, true));
    
                    $groups_string = join(",",$groups );
                    error_log(print_r($groups_string, true));
                    echo $groups_string;

                }
                break;
            }
        endswitch;
    }

    function quick_edit_fields( $column_name, $post_type ) {
        error_log(print_r($column_name, true));
        switch( $column_name ) :
            case 'post_audience': {
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

                break;
            }
            case 'post_groups': {
                wp_nonce_field( 'bcc_q_edit_nonce', 'bcc_nonce' );
                echo '<fieldset class="inline-edit-col-right bcc-quick-edit">
                    <div class="inline-edit-col">
                        <div class="inline-edit-group wp-clearfix">
                            <label class="post-audience">
                                <span class="title">Groups</span>
                                <span>';
                                    foreach ($this->_settings->groups_allowed as $ind => $group) {
                                        echo '<br><input type="checkbox" name="bcc_groups[]" id="option-'. $group .'" value="'. $group .'">
                                            <label for="option-'. $group .'">'. $group .'</label>';
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

        if ( isset( $_POST['bcc_groups'] ) ) {
            foreach ($this->_settings->groups_allowed as $group) {
                delete_post_meta( $post_id, 'bcc_groups', $group );
            }
            foreach($_POST['bcc_groups'] as $group) {
                add_post_meta( $post_id, 'bcc_groups', $group );
            }
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
    }
}
