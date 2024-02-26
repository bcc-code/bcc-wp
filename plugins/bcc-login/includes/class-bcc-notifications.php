<?php

class BCC_Notifications {

    private BCC_Login_Settings $settings;
    private BCC_Coreapi_Client $core_api;

    function __construct( BCC_Login_Settings $settings, BCC_Coreapi_Client $core_api) {
        $this->settings = $settings;
        $this->core_api = $core_api;

        add_action( 'transition_post_status', array( $this, 'on_post_status_transition' ), 10, 3 );
        add_action( 'send_scheduled_notification', array( $this, 'send_notification' ), 10, 1 );

    }

    public function on_post_status_transition(  $new_status, $old_status, $post ) {
       if ('publish' === $new_status && 'publish' !== $old_status) {
            wp_schedule_single_event( time() + 180, 'send_scheduled_notification', array( $post->ID ) );
            // $this->send_notification($post->ID);
       }
    }

    public function send_notification( $post_id ) {
        // Fetch the post object since only the ID is passed through scheduling.

        $post = get_post( $post_id );
        if ( ! $post ) {
            return; // Exit if the post doesn't exist.
        }
        $post_type = $post->post_type;

        // 1. Get groups for post
        $post_groups = get_post_meta( $post->ID, 'bcc_groups', false );

        // 2. Get default language and url for site
        $site_language = get_bloginfo('language'); //E.g. "en-US"
        $site_url = get_site_url();

        // 3. Define array of content to send
        $payload = [];

        $is_multilinguage_post = false;

        // 4. Handle multilingual posts
        if (defined('ICL_SITEPRESS_VERSION')) {
            // WPML is installed and active.

            // Check if post has been translated
            $has_translations = apply_filters( 'wpml_element_has_translations', '', $post_id, $post_type );
            if ($has_translations) {
                $trid = apply_filters( 'wpml_element_trid', NULL, $post_id, 'post_' . $post_type);
                $translations = apply_filters( 'wpml_get_element_translations', NULL, $trid, 'post_' . $post_type );

                // Determine if current post is the original
                $is_orginal = false;
                foreach ($translations as $lang_code => $details) {
                    if ($details->element_id == $post_id) {
                        $is_orginal = $details->original == "1";
                        break; 
                    }
                }

                $is_multilinguage_post = true;

                if ($is_orginal) {
                    foreach ( $translations as $lang => $details ) {
                        $translation = get_post($details->element_id);
                        $language_details = apply_filters( 'wpml_post_language_details', NULL, $translation->ID );
                        $language_code = str_replace('_', '-', $language_details["locale"]);
                        $excerpt = get_the_excerpt($translation);
                        if ($translation->post_status == 'publish') {
                            $payload[] = [
                                'title' => $translation->post_title,
                                'language' => $language_code,
                                'excerpt' => $excerpt,
                                'url' => get_permalink( $translation ) ?? ($site_url . '/?p=' . $translation->ID . '&lang=' . $language_code),
                                'image_url' => get_the_post_thumbnail_url($translation->ID,'thumbnail'),
                                'date' => str_replace(' ','T',$translation->post_date_gmt) . 'Z'
                            ];
                        }                        
                    }
                } else {
                    // Don't process non-default languages of posts that have translations
                    // This is to avoid sending duplicate notifications
                    return;
                }

            } 
        }

        if (!$is_multilinguage_post){
            $excerpt = get_the_excerpt($post);
            $payload[] = [
                'title' => $post->post_title,   
                'language' => $site_language,
                'excerpt' => $excerpt,
                'url' => get_permalink( $post ) ?? ($site_url . '/?p=' . $post->ID),
                'image_url' => get_the_post_thumbnail_url($post->ID,'thumbnail'),
                'date' => str_replace(' ','T',$post->post_date_gmt) . 'Z'
            ];
        }

        // Notification logic goes here.
        if (isset($post_groups) && !empty($post_groups) && !empty($payload)) {

            $inapp_payload = [];
            $email_payload = [];
            foreach ($payload as $item) {
                $inapp_payload[] = [
                    "language" => $item["language"],
                    "notification" => $item["title"] . '<br><small>' . $item["excerpt"] . '</small> [cta text="' . $this->get_read_more_text($item["language"]) . '" link="' . $item["url"] . '"]'
                ];
                $email_payload[] = [
                    "language" => $item["language"],
                    "subject" =>  $item["title"],
                    "banner" => $item["image_url"] !== false ? $item["image_url"] : null,
                    "title" =>  $item["title"],
                    "body" =>  $item["excerpt"] . '<br> [cta text="' . $this->get_read_more_text($item["language"]) . '" link="' . $item["url"] . '"]'
                ];
            }
            // // Set subtitle to title from other language (should probably be fixed in template...)
            // foreach ($email_payload as $email) {
            //     foreach ($email_payload as $other_email) {
            //         if ($email["language"] != $other_email["language"]) {
            //             $email["sub_title"] = $other_email["title"];
            //         }
            //     }
            // }
            $this->core_api->send_notification($post_groups, 'simpleinapp', $inapp_payload);
            $this->core_api->send_notification($post_groups, 'simpleemail', $email_payload);
        }

    }


    function get_read_more_text($lang) {
        if ($lang && strlen($lang) >= 2) {
            $lang_code = substr($lang, 0, 2);
            switch ($lang_code) {
                case "no":
                    return 'Les mer';
                    break;
                case "nb":
                    return 'Les mer';
                    break;                    
                case "en":
                    return 'Read more';
                    break;
                default:
                    return 'Read more';
                    break;
            }
        }
        return 'Read more';
        
    }

}
