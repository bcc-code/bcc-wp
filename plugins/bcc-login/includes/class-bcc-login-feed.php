<?php

class BCC_Login_Feed {
    private BCC_Login_Settings $_settings;
    private BCC_Login_Client $_client;
    private BCC_Login_Visibility $_visibility;

    function __construct( BCC_Login_Settings $settings, BCC_Login_Client $client, BCC_Login_Visibility $visibility ) {
        $this->_settings = $settings;
        $this->_client = $client;
        add_filter('the_excerpt_rss', array( $this, 'add_image_to_rss' ));
        add_filter( 'the_category_rss', array( $this, 'add_internal_category_to_member_items'), 10, 2 ); 
        add_action('pre_get_posts', array( $this,'include_custom_post_types_in_feed'));
        add_filter( 'rss2_ns', array( $this, 'add_custom_bcc_namespace'));
        add_filter( 'rss2_item', array( $this, 'add_custom_elements_to_items')); 
    }

    function add_custom_bcc_namespace() {
        echo 'xmlns:bcc="https://developer.bcc.no/bcc-widgets/integration/news" ';
    }

    // Allow post_types to be specified via query string
    function include_custom_post_types_in_feed($query) {
        // Check if it's the main query and a feed
        if ($query->is_main_query() && $query->is_feed()) {

            // Check if the 'post_types' query parameter exists in the URL
            if (isset($_GET['post_types'])) {
                // Get the post types from the query string and split them by comma
                $post_types = explode(',', sanitize_text_field($_GET['post_types']));
            } else {
                // Default post types if no query string is provided
                $post_types = array('post');
            }

            // Modify the query to include the specified post types
            $query->set('post_type', $post_types);
        }
    }


    function add_image_to_rss($content) {
        global $post;
        if ( has_post_thumbnail( $post->ID ) )
            $content = '<div>' . get_the_post_thumbnail( $post->ID, 'medium') . '</div>' . $content;
        return $content;
    }

    function add_internal_category_to_member_items($the_list, $type) {
        global $post;
        $result = '';
        $visibility = (int) get_post_meta( $post->ID, 'bcc_login_visibility', true );
        if (!$visibility) {
            $visibility = $this->_settings->default_visibility;
        }
        if ($visibility == BCC_Login_Visibility::VISIBILITY_MEMBER) {
            $result = $result . "<category>internal</category>";
        }
        return $result;
    }

    function add_custom_elements_to_items($the_list){
       $this->add_visibility_to_items($the_list); 
       $this->add_groups_to_items($the_list);
       $this->add_post_type_to_items($the_list);
       $this->add_original_language_to_items($the_list);
    }

    // Include basic visibility settings in feed: 
    // - public (no authentication required)
    // - user (requires authentication)
    // - internal:{district name} (requires affiliation with organization in specified district)
    function add_visibility_to_items ($the_list) {
        global $post;
        $visibility = $this->_settings->default_visibility;
        if ( $bcc_login_visibility = (int) get_post_meta( $post->ID, 'bcc_login_visibility', true ) ) {
            $visibility = $bcc_login_visibility;
        }
        if ($visibility == BCC_Login_Visibility::VISIBILITY_PUBLIC){
            echo "<bcc:visibility>public</bcc:visibility>\n"; 

        } else if ($visibility == BCC_Login_Visibility::VISIBILITY_SUBSCRIBER){
            echo "<bcc:visibility>user</bcc:visibility>\n"; 
        } else if ($visibility == BCC_Login_Visibility::VISIBILITY_MEMBER){
            echo "<bcc:visibility>internal:" . $this->_settings->member_organization_name . "</bcc:visibility>\n"; 
        }
    }

    // Include group uid for each group that the post is visible for or targetted at (notification group)
    // E.g. 
    // <bcc:visiblityGroup>d4c434a7-504a-4246-9a10-def7dbfa982c</bcc:visiblityGroup>
    // <bcc:visiblityGroup>25f5bc4d-48e0-4a6e-bf05-6b2a15d70861</bcc:visiblityGroup>
    // <bcc:notificationGroup>d4c434a7-504a-4246-9a10-def7dbfa982c</bcc:notificationGroup>
    function add_groups_to_items($the_list) {
        global $post;
        $result = '';
        if ( !empty($this->_settings->site_groups) ) {
            $post_groups = get_post_meta($post->ID, 'bcc_groups', false);
            foreach ($post_groups as $group){
                $result = $result . "\t\t<bcc:visibilityGroup>" . $group . "</bcc:visibilityGroup>\n";
            }
            if (in_array($post->post_type, $this->_settings->notification_post_types)){
                $notification_groups = array_intersect($post_groups, $this->_settings->notification_groups);
                foreach ($notification_groups as $group){
                    $result = $result . "\t\t<bcc:notificationGroup>" . $group . "</bcc:notificationGroup>\n";
                }
            }

        }
        echo $result;
    }

    // Include post type element (e.g. <bcc:type>post</bcc:type>)
    function add_post_type_to_items($the_list) {
        global $post;
        echo "\t\t<bcc:type>" . $post->post_type . "</bcc:type>\n";
    }

    // Add meta data relating to the orginal post (language). If this is the orginal,
    // then the values will match the Guid, Url on the current item.
    // Example:
    // <bcc:original isOrignal="false" language="no" guid="https://bcc.no/?p=2343" url="https://bcc.no/en-eller-annen-artikkel" />
    function add_original_language_to_items($the_list) {
        global $post;
        $post_type = get_post_type( $post );
        $post_id = $post->ID;
        
        $wpml_installed = defined('ICL_SITEPRESS_VERSION');

         // 2. Get default language
        $site_language = get_bloginfo('language'); //E.g. "en-US"
        $site_language = substr($site_language, 0, 2);
        if ($site_language == "nb"){
            $site_language = "no";
        }
        $is_multilinguage_post = false;

        // 4. Handle multilingual posts
        if ($wpml_installed) {
                // WPML is installed and active.

                // Get default language for site
                $current_language = ICL_LANGUAGE_CODE;

                // Check if post has been translated
                $has_translations = apply_filters('wpml_element_has_translations', '', $post_id, $post_type);
                if ($has_translations) {

                    $is_multilinguage_post = true;
                    $trid = apply_filters('wpml_element_trid', NULL, $post_id, 'post_' . $post_type);
                    $translations = apply_filters('wpml_get_element_translations', NULL, $trid, 'post_' . $post_type);

                    // Determine if current post is the original
                    $is_orginal = false;
                    foreach ($translations as $lang_code => $details) {
                        if ($details->element_id == $post_id) {
                            $is_orginal = $details->original == "1";
                        }
                        if ($details->original == "1"){
                            $original_post = get_post($details->element_id); // Get post object by ID
                            $original_post_guid = $original_post->guid; // Extract the GUID
                            do_action('wpml_switch_language', $lang_code);
                            $original_post_permalink = get_permalink($details->element_id);
                            do_action('wpml_switch_language', $current_language);

                            echo "\t\t<bcc:original isOriginal=\"" . ($is_orginal ? "true" : "false") . "\" language=\"" . $lang_code . "\" guid=\"" . $original_post_guid . "\" url=\"" . $original_post_permalink . "\" />\n";
                        }
                    }
                } else {

                    echo "\t\t<bcc:original isOriginal=\"true\" language=\"" . $site_language . "\" guid=\"" . $post->guid . "\" url=\"" . get_permalink($post->ID) . "\" />\n";
                }
            }

    }
}
