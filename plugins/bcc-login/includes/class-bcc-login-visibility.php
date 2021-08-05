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

    private $post_types = array( 'post', 'page' );

    function __construct( BCC_Login_Settings $settings, BCC_Login_Client $client ) {
        $this->_settings = $settings;
        $this->_client = $client;

        add_action( 'init', array( $this, 'on_init' ) );
        add_action( 'template_redirect', array( $this, 'on_template_redirect' ), 0 );
        add_action( 'added_post_meta', array( $this, 'on_meta_saved' ), 10, 4 );
        add_action( 'updated_post_meta', array( $this, 'on_meta_saved' ), 10, 4 );
        add_action( 'enqueue_block_editor_assets', array( $this, 'on_block_editor_assets' ) );
        add_filter( 'pre_get_posts', array( $this, 'filter_pre_get_posts' ) );
        add_filter( 'wp_get_nav_menu_items', array( $this, 'filter_menu_items' ), 20 );
        add_filter( 'render_block', array( $this, 'on_render_block' ), 10, 2 );
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
        }
    }

    /**
     * Redirects current user to login if the post requires a higher level.
     *
     * @return void
     */
    function on_template_redirect() {

        $session_is_valid = $this->_client->is_session_valid();
        if ( is_user_logged_in() && !$session_is_valid ) {
            $this->_client->end_login();
            $this->_client->start_login();
            return;
        }

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

        if ( $this->_settings->default_visibility == self::VISIBILITY_PUBLIC ) {
            // If default visibility is public, show posts where visibility matches current user level OR where visibility isn't defined
            $query->set(
                'meta_query',
                array(
                    'relation' => 'OR',
                    array(
                        'key'     => 'bcc_login_visibility',
                        'compare' => '<=',
                        'value'   => $this->get_current_user_level(),
                    ),
                    array(
                        'key'     => 'bcc_login_visibility',
                        'compare' => 'NOT EXISTS',
                    ),
                )
            );
        } else {
            // Don't include posts where visibility isn't specified if default visibility isn't public
            $query->set(
                'meta_query',
                array(
                    array(
                        'key'     => 'bcc_login_visibility',
                        'compare' => '<=',
                        'value'   => $this->get_current_user_level(),
                    )
                )
            );
        }



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
     * Delets all `bcc_login_visibility` values from the database.
     */
    static function on_uninstall() {
        delete_metadata( 'post', 0, 'bcc_login_visibility', '', true );
    }
}
