<?php

class BCC_Login_Widgets {

    private BCC_Login_Settings $settings;
    private BCC_Login_Client $client;

    function __construct( BCC_Login_Settings $settings, BCC_Login_Client $client ) {
        $this->settings = $settings;
        $this->client = $client;

        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        add_action( 'wp_head', array( $this, 'render_topbar_and_analytics' ) );
        add_filter( 'body_class', array( $this, 'body_class' ) );
        add_action( 'init', array( $this, 'bcc_widgets_shortcodes' ) );
        add_action( 'rest_api_init', array( $this, 'add_fields_for_topbar_search' ) );
    }

    function enqueue_scripts() {
        $script_handle = 'bcc-login-widgets';
        $script_url = BCC_LOGIN_URL . 'src/custom-widgets.js';

        wp_enqueue_style( $script_handle, $this->settings->widgets_base_url.'/styles/widgets.css' );

        if ( $this->should_show_topbar() ) {
            wp_add_inline_style( $script_handle, '@media screen and (max-width: 600px){.admin-bar .portal-top-bar{position:absolute;}}@media screen and (min-width: 850px){body{margin-top:48px!important;}.portal-top-bar-spacer{display:none;}.admin-bar .portal-top-bar{top:46px;}}' );
        }

        wp_register_script( $script_handle, '' );
        wp_enqueue_script( $script_handle );
        wp_add_inline_script( $script_handle, 'var bccLoginWidgetsVars = {"nonce":"' . wp_create_nonce('wp_rest') . '"};' );
    }

    function render_topbar_and_analytics() {
        if ( $this->should_show_topbar() ) {
            echo '<script id="script-bcc-topbar" '.($this->settings->track_clicks ? "data-click-analytics=true" : "data-click-analytics=false").' '.($this->settings->track_page_interaction ? "data-page-interaction-analytics=true" : "data-page-interaction-analytics=false").' '.($this->settings->track_page_load ? "data-page-load-analytics=true" : "data-page-load-analytics=false").' data-authentication-type="WebApp" data-authentication-location="' . site_url( '?bcc-login=access-token' ) . '" src="'.$this->settings->widgets_base_url.'/widgets/TopbarJs" defer></script>' . PHP_EOL;
        } else if ( $this->should_load_analytics() ) {
            echo '<script id="script-bcc-analytics" '.($this->settings->track_clicks ? "data-click-analytics=true" : "data-click-analytics=false").' '.($this->settings->track_page_interaction ? "data-page-interaction-analytics=true" : "data-page-interaction-analytics=false").' '.($this->settings->track_page_load ? "data-page-load-analytics=true" : "data-page-load-analytics=false").' data-authentication-type="WebApp" data-authentication-location="' . site_url( '?bcc-login=access-token' ) . '" src="'.$this->settings->widgets_base_url.'/widgets/AnalyticsJs" defer></script>' . PHP_EOL;
        }
    }

    function body_class( $body_class ) {
        if ( $this->should_show_topbar() ) {
            $body_class[] = 'bcc-widget-topbar';
        }
        return $body_class;
    }

    function should_show_topbar() {
        return (
            $this->settings->topbar &&
            wp_get_current_user()->exists() &&
            ! wp_is_json_request() &&
            ! is_customize_preview()
        );
    }

   function should_load_analytics() {
        return (
            ($this->settings->track_clicks || $this->settings->track_page_load || $this->settings->track_page_interaction)  &&
            ! wp_is_json_request() &&
            ! is_customize_preview()
        );
    }

    function bcc_widgets_shortcodes() {
        add_shortcode( 'bcc-widgets-week-calendar', function ($attributes) {
            $attributes = array_change_key_case((array)$attributes, CASE_LOWER);

            $html =  '<div id="bcc-calendar-week"></div>';
            $html .= '<script id="script-bcc-calendar-week" data-authentication-type="WebApp" data-authentication-location="' . site_url( '?bcc-login=access-token' ) . '" ';
            $html .= 'data-language="' . $attributes['language'] . '" data-maxdays="' .  $attributes['maxdays'] . '" data-maxappointments="' . $attributes['maxappointments'] . '" ';
            $html .= 'data-calendars="' . $attributes['calendars'] .'" data-fullcalendarurl="' .  $attributes['fullcalendarurl'] . '" ';
            $html .= 'src="'.$this->settings->widgets_base_url.'/widgets/CalendarWeekJs" defer></script>';

            return $html . PHP_EOL;
        } );

        add_shortcode( 'bcc-widgets-month-calendar', function ($attributes) {
            $attributes = array_change_key_case((array)$attributes, CASE_LOWER);

            $html =  '<div id="bcc-calendar-month"></div>';
            $html .= '<script id="script-bcc-calendar-month" data-authentication-type="WebApp" data-authentication-location="' . site_url( '?bcc-login=access-token' ) . '" ';
            $html .= 'data-language="' . $attributes['language'] . '" data-calendars="' . $attributes['calendars'] . '" ';
            $html .= 'src="'.$this->settings->widgets_base_url.'/widgets/CalendarMonthJs" defer></script>';
            
            return $html . PHP_EOL;
        } );

        add_shortcode( 'bcc-widgets-birthday', function ($attributes) {
            $attributes = array_change_key_case((array)$attributes, CASE_LOWER);

            $html = '<div id="bcc-birthday"></div>';
            $html .= '<script id="script-bcc-birthday" data-authentication-type="WebApp" data-authentication-location="' . site_url( '?bcc-login=access-token' ) . '" ';
            $html .= 'data-language="' . $attributes['language'] . '" data-churchname="' . $attributes['churchname'] . '" data-maxdays="' . $attributes['maxdays'] . '" ';
            $html .= 'src="'.$this->settings->widgets_base_url.'/widgets/BirthdayJs" defer></script>';

            return $html . PHP_EOL;
        } );

        add_shortcode( 'bcc-widgets-map', function ($attributes) {
            $attributes = array_change_key_case((array)$attributes, CASE_LOWER);

            $html = '<div id="bcc-map" style="height: 650px; background-color: rgb(229, 227, 223); position: relative;">';
                $html .= '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200" style="width: 75px; position: absolute; margin: auto; left: 0; right: 0; top: 0; bottom: 0;">';
                    $html .= '<circle fill="#30715E" stroke="#30715E" stroke-width="15" r="15" cx="40" cy="100">';
                        $html .= '<animate attributeName="opacity" calcMode="spline" dur="2" values="1;0;1;" keySplines=".5 0 .5 1;.5 0 .5 1" repeatCount="indefinite" begin="-.4"></animate>';
                    $html .= '</circle>';
                    $html .= '<circle fill="#30715E" stroke="#30715E" stroke-width="15" r="15" cx="100" cy="100">';
                        $html .= '<animate attributeName="opacity" calcMode="spline" dur="2" values="1;0;1;" keySplines=".5 0 .5 1;.5 0 .5 1" repeatCount="indefinite" begin="-.2"></animate></circle>';
                    $html .= '<circle fill="#30715E" stroke="#30715E" stroke-width="15" r="15" cx="160" cy="100">';
                        $html .= '<animate attributeName="opacity" calcMode="spline" dur="2" values="1;0;1;" keySplines=".5 0 .5 1;.5 0 .5 1" repeatCount="indefinite" begin="0"></animate>';
                    $html .= '</circle>';
                $html .= '</svg>';
            $html .= '</div>';
            $html .= '<script id="script-bcc-map" data-authentication-type="WebApp" data-authentication-location="' . site_url( '?bcc-login=access-token' ) . '" ';
            $html .= 'data-district="' . ($attributes['district'] ?? '') . '" data-zoom="' . ($attributes['zoom'] ?? '') . '" data-lat="' . ($attributes['lat'] ?? '') . '" data-lng="' . ($attributes['lng'] ?? '') . '" ';
            $html .= 'src="'.$this->settings->widgets_base_url.'/widgets/MapJs" defer></script>';

            return $html . PHP_EOL;
        } );

        add_shortcode( 'bcc-newsfeed-new', function () {
            $html = '<bcc-newsfeed-new authentication-type="WebApp" authentication-location="' . site_url( '?bcc-login=access-token' ) .'" ';
            $html .= 'language="' . get_culture() . '"></bcc-newsfeed-new>';

            return $html . PHP_EOL;
        } );

        add_shortcode( 'bcc-newsfeed-public', function () {
            $html = '<bcc-newsfeed-public language="' . get_culture() . '"></bcc-newsfeed-public>';
            $html .= '<script src="https://widgets.bcc.no/scripts/main.js" type="module"></script>';
        
            return $html . PHP_EOL;
        } );
    }

    function add_fields_for_topbar_search() {
        register_rest_field( 'search-result', 'link', array (
            'get_callback' => function ($post_arr) {
                return get_permalink( $post_arr['id']) ;
            }
        ));
    
        register_rest_field( 'search-result', 'excerpt', array (
            'get_callback' => function ($post_arr) {
                return get_the_excerpt( $post_arr['id'] );
            }
        ));
    
        register_rest_field( 'search-result', 'image', array (
            'get_callback' => function ($post_arr) {
                return get_the_post_thumbnail_url( $post_arr['id'], 'full' );
            }
        ));
    
        register_rest_field( 'search-result', 'post_date', array (
            'get_callback' => function ($post_arr) {
                return get_the_date( 'j. F Y', $post_arr['id'] );
            }
        ));
    }
}