<?php

class BCC_Login_Feed {

    private BCC_Login_Settings $_settings;
    private BCC_Login_Client $_client;

    function __construct( BCC_Login_Settings $settings, BCC_Login_Client $client ) {
        $this->_settings = $settings;
        $this->_client = $client;
        add_filter('the_excerpt_rss', array( $this, 'add_image_to_rss' ));
        add_filter( 'the_category_rss', array( $this, 'add_internal_category_to_member_posts'), 10, 2 ); 
    }

    function add_image_to_rss($content) {
        global $post;
        if ( has_post_thumbnail( $post->ID ) )
            $content = '<div>' . get_the_post_thumbnail( $post->ID, 'medium') . '</div>' . $content;
        return $content;
    }

    function add_internal_category_to_member_posts($the_list, $type) {
        global $post;
        $visibility = (int) get_post_meta( $post->ID, 'bcc_login_visibility', true );
        if (!$visibility) {
            $visibility = $this->_settings->default_visibility;
        }
        if ($visibility == BCC_Login_Visibility::VISIBILITY_MEMBER) {
            return '<category>internal</category>';
        }
    }
}