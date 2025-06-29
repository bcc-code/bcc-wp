<?php

class BCC_Notifications
{

    private BCC_Login_Settings $settings;
    private BCC_Coreapi_Client $core_api;

    function __construct(BCC_Login_Settings $settings, BCC_Coreapi_Client $core_api)
    {
        $this->settings = $settings;
        $this->core_api = $core_api;

        add_action('transition_post_status', array($this, 'on_post_status_transition'), 10, 3);
        add_action('bcc_send_scheduled_notification', array($this, 'send_notification'), 10, 1);

    }

    // NB: This does not run if a post is published without first being saved as a draft.
    public function on_post_status_transition($new_status, $old_status, $post)
    {
        if ('publish' === $new_status && 'publish' !== $old_status && in_array($post->post_type, $this->settings->notification_post_types)) {
            if ($this->settings->notification_delay > 0) {
                wp_schedule_single_event(time() + $this->settings->notification_delay, 'bcc_send_scheduled_notification', array($post->ID));
            } else {
                $this->send_notification($post->ID);

            }
        }
    }

    public function replace_notification_params($text, $post, $language)
    {
        $text = str_replace('[postTitle]', $post->post_title, $text);
        $text = str_replace('[postExcerpt]', get_the_excerpt($post), $text);
        $text = str_replace('[postUrl]', get_permalink($post) ?? (get_site_url() . '/?p=' . $post->ID . (isset($language) ? '&lang=' . $language : '')), $text);
        $text = str_replace('[postImageUrl]', get_the_post_thumbnail_url($post->ID, 'large'), $text);
        return $text;
    }

    public function send_notification($post_id)
    {
        error_log('DEBUG: ' . __METHOD__ . ' - Sending notification for post ID: ' . $post_id);

        // Fetch the post object since only the ID is passed through scheduling.
        $post = get_post($post_id);
        $wpml_installed = defined('ICL_SITEPRESS_VERSION');
        if (!$post) {
            error_log('DEBUG: ' . __METHOD__ . ' - Could not get post: ' . $post_id);
            return; // Exit if the post doesn't exist.
        }
        $post_type = $post->post_type;

        // 1. Get groups for post
        $post_groups = get_post_meta($post->ID, 'bcc_groups', false);

        // Notification logic goes here.
        if (isset($post_groups) && !empty($post_groups)) {

            $notification_groups = array_intersect($post_groups, $this->settings->notification_groups);
            if (empty($notification_groups)) {
                error_log('DEBUG: ' . __METHOD__ . ' - No notification groups found for post: ' . $post_id);
                return;
            }

            // 2. Get default language and url for site
            $site_language = get_bloginfo('language'); //E.g. "en-US"
            $site_url = get_site_url();

            // 3. Define array of content to send
            $payload = [];

            $is_multilinguage_post = false;

            // 4. Handle multilingual posts
            if ($wpml_installed) {
                // WPML is installed and active.
                error_log('DEBUG: ' . __METHOD__ . ' - WPML is installed. Post ID: ' . $post_id);

                // Check if post has been translated
                $has_translations = apply_filters('wpml_element_has_translations', '', $post_id, $post_type);
                if ($has_translations) {

                    error_log('DEBUG: ' . __METHOD__ . ' - Post has translations. Post ID: ' . $post_id);

                    $trid = apply_filters('wpml_element_trid', NULL, $post_id, 'post_' . $post_type);
                    $translations = apply_filters('wpml_get_element_translations', NULL, $trid, 'post_' . $post_type);

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

                        error_log('DEBUG: ' . __METHOD__ . ' - Post is original. Post ID: ' . $post_id);

                        foreach ($translations as $lang => $details) {
                            $default_local = apply_filters('wpml_current_language', null);
                            $translation = get_post($details->element_id);
                            $language_details = apply_filters('wpml_post_language_details', NULL, $translation->ID);
                            $locale = $language_details["locale"];
                            $language = str_replace('_', '-', $locale);
                            $language_code = $language_details["language_code"];
                            $excerpt = get_the_excerpt($translation);
                            do_action('wpml_switch_language', $language_code);
                            if ($translation->post_status == 'publish') {
                                $payload[] = [
                                    'post' => $translation,
                                    'title' => $translation->post_title,
                                    'language' => $language,
                                    'language_code' => $language_code,
                                    'excerpt' => $excerpt,
                                    'url' => get_permalink($translation) ?? ($site_url . '/?p=' . $translation->ID . '&lang=' . $language),
                                    'image_url' => get_the_post_thumbnail_url($translation->ID, 'large'),
                                    'date' => str_replace(' ', 'T', $translation->post_date_gmt) . 'Z'
                                ];
                            }
                            do_action('wpml_switch_language', $default_local);
                        }
                    } else {

                        error_log('DEBUG: ' . __METHOD__ . ' - Post is NOT original. Post ID: ' . $post_id);

                        // Don't process non-default languages of posts that have translations
                        // This is to avoid sending duplicate notifications
                        return;
                    }

                }
            }

            if (!$is_multilinguage_post) {

                error_log('DEBUG: ' . __METHOD__ . ' - Post is NOT multilingual. Post ID: ' . $post_id);

                $excerpt = get_the_excerpt($post);
                $payload[] = [
                    'post' => $post,
                    'title' => $post->post_title,
                    'language' => $site_language,
                    'excerpt' => $excerpt,
                    'url' => get_permalink($post) ?? ($site_url . '/?p=' . $post->ID),
                    'image_url' => get_the_post_thumbnail_url($post->ID, 'large'),
                    'date' => str_replace(' ', 'T', $post->post_date_gmt) . 'Z'
                ];
            }

            if (!empty($payload)) {

                $inapp_payload = [];
                $email_payload = [];
                foreach ($payload as $item) {
                    $default_local = apply_filters('wpml_current_language', null);
                    $wp_lang = str_replace('-', '_', $item["language"]);
                    switch_to_locale($wp_lang);
                    if ($wpml_installed) {
                        do_action('wpml_switch_language', $item["language_code"]);
                    }

                    $templates = array_key_exists($wp_lang, $this->settings->notification_templates)
                        ? $this->settings->notification_templates[$wp_lang]
                        : (array_key_exists($site_language, $this->_settings->notification_templates)
                            ? $this->settings->notification_templates[$site_language]
                            : null);

                    if ($templates) {

                        $payload_lang = str_replace('nb-NO', 'no-NO', $item["language"]);

                        $inapp_payload[] = [
                            "language" => $payload_lang,
                            "title" => $item["title"],
                            "content" => $item["excerpt"] . '<br> [cta text="' . __('Read more', 'bcc-login') . '" link="' . $item["url"] . '"]',
                            "notification" => $item["excerpt"] . '<br> [cta text="' . __('Read more', 'bcc-login') . '" link="' . $item["url"] . '"]' //obsolete
                        ];

                        $email_subject = $this->replace_notification_params($templates["email_subject"] ?? "[postTitle]", $item["post"], $wp_lang);
                        $email_title = $this->replace_notification_params($templates["email_title"] ?? "", $item["post"], $wp_lang);
                        $email_body = $this->replace_notification_params($templates["email_body"] ?? "", $item["post"], $wp_lang);

                        $email_payload[] = [
                            "language" => $payload_lang,
                            "subject" => $email_subject,
                            "banner" => $item["image_url"] !== false ? $item["image_url"] : null,
                            "title" => $email_title,
                            "content" => $email_body,
                            "body" => $email_body, //obsolete
                        ];
                    }
                    if ($wpml_installed) {
                        do_action('wpml_switch_language', $default_local);
                    }
                    restore_previous_locale();
                }

                error_log('DEBUG: ' . __METHOD__ . ' - Sending notifications for ' . count($email_payload) . ' languages.  Post ID: ' . $post_id);

                $this->core_api->send_notification($notification_groups, 'email', 'simpleemail', $email_payload);
                $this->core_api->send_notification($notification_groups, 'inapp', 'simpleinapp', $inapp_payload);

                error_log('DEBUG: ' . __METHOD__ . ' - Sent notifications for ' . count($email_payload) . ' languages.  Post ID: ' . $post_id);

            } else {
                error_log('DEBUG: ' . __METHOD__ . ' - Notification payload is EMPTY. Post ID: ' . $post_id);
            }
        }
    }
}
