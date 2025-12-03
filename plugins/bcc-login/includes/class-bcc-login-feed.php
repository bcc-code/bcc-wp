<?php

class BCC_Login_Feed {
    private BCC_Login_Settings $_settings;
    private BCC_Login_Client $_client;
    private BCC_Login_Visibility $_visibility;

    function __construct( BCC_Login_Settings $settings, BCC_Login_Client $client, BCC_Login_Visibility $visibility ) {
        $this->_settings = $settings;
        $this->_client = $client;
        add_action( 'pre_get_posts', array( $this, 'add_paging_support') );
        add_filter('the_excerpt_rss', array( $this, 'add_image_to_rss' ));
        add_filter( 'the_category_rss', array( $this, 'add_internal_category_to_member_items'), 10, 2 ); 
        add_action('pre_get_posts', array( $this,'include_custom_post_types_in_feed'));
        add_filter( 'rss2_ns', array( $this, 'add_custom_bcc_namespace'));
        add_filter( 'rss2_item', array( $this, 'add_custom_elements_to_items')); 
    }



    function add_paging_support( $query ) {
        // Only target feed queries
        if ( $query->is_feed()) {

            // Check for the "updated-min" parameter and modify the query
            if ( isset( $_GET['updated-min'] ) ) {
                $updated_min = sanitize_text_field( $_GET['updated-min'] );

                // Parse RFC3339 date-time and convert to GMT
                try {
                    $date = new DateTime( $updated_min );
                    $date->setTimezone( new DateTimeZone('UTC') ); // Convert to UTC (GMT)
                    $updated_min_gmt = $date->format('Y-m-d H:i:s'); // Convert to WordPress-readable format (Y-m-d H:i:s)
                } catch (Exception $e) {
                    // Handle parsing error (invalid date format)
                    $updated_min_gmt = false;
                }

                if ( $updated_min_gmt ) {
                    $query->set('date_query', array(
                        array(
                            'column' => 'post_modified_gmt', // Use post modified date
                            'after'  => $updated_min_gmt, // Date after the 'updated-min' value (in GMT)
                            'inclusive' => true,
                        ),
                    ));
                }
            }

            // Check for the "max-results" parameter and modify the number of posts
            if ( isset( $_GET['max-results'] ) && is_numeric( $_GET['max-results'] ) ) {
                $max_results = (int) $_GET['max-results'];
                $query->set('posts_per_rss', $max_results);
            }

            // Check if the "page" parameter exists in the URL
            if ( isset( $_GET['page'] ) && is_numeric( $_GET['page'] ) ) {
                $page = (int) $_GET['page'];
                
                // Set the 'paged' parameter to the value of the 'page' parameter
                $query->set( 'paged', $page );
            }
        }
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
            $content = '<div>' . get_the_post_thumbnail( $post->ID, 'large') . '</div>' . $content;
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

    function add_custom_elements_to_items($the_list) {
       $this->add_visibility_and_groups_to_items($the_list); 
       $this->add_post_type_to_items($the_list);
       $this->add_original_language_to_items($the_list);
    }

    function add_visibility_and_groups_to_items($the_list) {
        global $post;
        $visibility = $this->_settings->default_visibility;

        if ( $bcc_login_visibility = (int) get_post_meta( $post->ID, 'bcc_login_visibility', true ) ) {
            $visibility = $bcc_login_visibility;
        }

        if ( !empty($this->_settings->site_groups)) {
            // Get groups that are checked on post
            $post_target_groups = get_post_meta($post->ID, 'bcc_groups', false);
            $post_visibility_groups = get_post_meta($post->ID, 'bcc_visibility_groups', false);

            // Make sure posts with a group set don't have public visibility
            if ( !empty($post_target_groups) || !empty($post_visibility_groups) )
            {
                if ($visibility == BCC_Login_Visibility::VISIBILITY_PUBLIC) {
                    $visibility = BCC_Login_Visibility::VISIBILITY_SUBSCRIBER;
                }
            }
        }

        $this->add_visibility_to_items($the_list, $visibility);
        $this->add_groups_to_items($the_list, $visibility);
    }

    // Include basic visibility settings in feed: 
    // - public (no authentication required)
    // - user (requires authentication)
    // - internal:{district name} (requires affiliation with organization in specified district)
    function add_visibility_to_items($the_list, $visibility) {
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
    function add_groups_to_items($the_list, $visibility) {
        global $post;
        $result = '';

        if ( !empty($this->_settings->site_groups) || !empty($this->_settings->full_content_access_groups) ) {
            // Get groups that are checked on post
            $post_target_groups = get_post_meta($post->ID, 'bcc_groups', false);
            $post_visibility_groups = get_post_meta($post->ID, 'bcc_visibility_groups', false);

            // Visibility Groups: groups with access to all posts + target groups + visibility groups that are checked on post
            if ($visibility != BCC_Login_Visibility::VISIBILITY_PUBLIC)
            {
                // Start with groups that have full content access
                $visibility_groups = $this->_settings->full_content_access_groups;

                // Add post target groups
                if (is_array($post_target_groups) && count($post_target_groups)) {
                    $visibility_groups = $this->_settings->array_union($post_target_groups, $visibility_groups);
                }

                // Add post visibility groups
                if (is_array($post_visibility_groups) && count($post_visibility_groups)) {
                    $visibility_groups = $this->_settings->array_union($post_visibility_groups, $visibility_groups);
                }

                foreach ($visibility_groups as $group){
                    $result .= "\t\t<bcc:visibilityGroup>" . $group . "</bcc:visibilityGroup>\n";
                }
            }

            if (in_array($post->post_type, $this->_settings->notification_post_types) && is_array($post_groups) && count($post_groups)) {
                // Notification Groups: Groups that are checked on posts + are eligable for notification
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

    function escape_xml($string) {
        return htmlspecialchars($string, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    // Add meta data relating to the orginal post (language). If this is the orginal,
    // then the values will match the Guid, Url on the current item.
    // Example:
    // <bcc:translatedFrom language="no" guid="https://bcc.no/?p=2343" url="https://bcc.no/en-eller-annen-artikkel" title="Hello verden!" />
    function add_original_language_to_items($the_list) {
        global $post;
        $post_type = get_post_type( $post );
        $post_id = $post->ID;
        
        $wpml_installed = defined('ICL_SITEPRESS_VERSION');

        // 4. Handle multilingual posts (in WMPL)
        if ($wpml_installed) {
            // WPML is installed and active.

            // Get default language for site
            $current_language = apply_filters( 'wpml_current_language', null );

            // Check if post has been translated
            $has_translations = apply_filters('wpml_element_has_translations', '', $post_id, $post_type);
            if ($has_translations) {

                $is_multilinguage_post = true;
                $trid = apply_filters('wpml_element_trid', NULL, $post_id, 'post_' . $post_type);
                $translations = apply_filters('wpml_get_element_translations', NULL, $trid, 'post_' . $post_type);

                foreach ($translations as $lang_code => $details) {
                    if ($details->element_id == $post_id) {
                        break; //Current post is original
                    }
                    if ($details->original == "1") {
                        // Another language is original
                        $original_post = get_post($details->element_id); // Get post object by ID
                        $original_post_guid = $original_post->guid; // Extract the GUID
                        do_action('wpml_switch_language', $lang_code);
                        $original_post_permalink = get_permalink($details->element_id);
                        do_action('wpml_switch_language', $current_language);

                        echo "\t\t<bcc:translatedFrom language=\"" . $lang_code . "\" guid=\"" . $this->escape_xml($original_post_guid) . "\" url=\"" . $this->escape_xml($original_post_permalink) . "\" title=\"" . $this->escape_xml($original_post->post_title) . "\"/>\n";

                        break;
                    }
                }
            }
        }

    }
}
