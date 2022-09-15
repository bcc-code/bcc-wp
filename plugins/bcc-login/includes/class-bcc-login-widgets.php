<?php

class BCC_Login_Widgets {

    private BCC_Login_Settings $settings;
    private BCC_Login_Client $client;

    function __construct( BCC_Login_Settings $settings, BCC_Login_Client $client ) {
        $this->settings = $settings;
        $this->client = $client;

        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
        add_action( 'wp_head', array( $this, 'render_topbar' ) );
        add_filter( 'body_class', array( $this, 'body_class' ) );
        add_action( 'init', array( $this, 'bcc_widgets' ) );
    }

    function enqueue_styles() {
        wp_enqueue_style( 'bcc-login-widgets', 'https://widgets.bcc.no/styles/widgets.css' );
        wp_add_inline_style( 'bcc-login-widgets', '@media screen and (max-width: 600px){.admin-bar .portal-top-bar{position:absolute;}}@media screen and (min-width: 850px){body{margin-top:48px!important;}.portal-top-bar-spacer{display:none;}.admin-bar .portal-top-bar{top:46px;}}' );
    }

    function render_topbar() {
        if ( $this->should_show_topbar() ) {
            echo '<script id="script-bcc-topbar" data-authentication-type="inline-access-token" data-access-token="' . $this->get_access_token() . '" src="https://widgets.bcc.no/widgets/TopbarJs" defer></script>' . PHP_EOL;
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

    function bcc_widgets() {
        add_shortcode( 'bcc-widgets-week-calendar', function ($attributes) {
            $attributes = array_change_key_case((array)$attributes, CASE_LOWER);

            $html =  '<div id="bcc-calendar-week"></div>';
            $html .= '<script id="script-bcc-calendar-week" data-authentication-type="inline-access-token" data-access-token="' . $this->get_access_token() . '" ';
            $html .= 'data-language="' . $attributes['language'] . '" data-maxdays="' .  $attributes['maxdays'] . '" data-maxappointments="' . $attributes['maxappointments'] . '" ';
            $html .= 'data-calendars="' . $attributes['calendars'] .'" data-fullcalendarurl="' .  $attributes['fullcalendarurl'] . '" ';
            $html .= 'src="https://widgets.bcc.no/widgets/CalendarWeekJs" defer></script>';

            return $html . PHP_EOL;
        } );

        add_shortcode( 'bcc-widgets-month-calendar', function ($attributes) {
            $attributes = array_change_key_case((array)$attributes, CASE_LOWER);

            $html =  '<div id="bcc-calendar-month"></div>';
            $html .= '<script id="script-bcc-calendar-month" data-authentication-type="inline-access-token" data-access-token="' . $this->get_access_token() . '" ';
            $html .= 'data-language="' . $attributes['language'] . '" data-calendars="' . $attributes['calendars'] . '" ';
            $html .= 'src="https://widgets.bcc.no/widgets/CalendarMonthJs" defer></script>';
            
            return $html . PHP_EOL;
        } );

        add_shortcode( 'bcc-widgets-birthday', function ($attributes) {
            $attributes = array_change_key_case((array)$attributes, CASE_LOWER);

            $html = '<div id="bcc-birthday"></div>';
            $html .= '<script id="script-bcc-birthday" data-authentication-type="inline-access-token" data-access-token="' . $this->get_access_token() . '" ';
            $html .= 'data-language="' . $attributes['language'] . '" data-churchname="' . $attributes['churchname'] . '" data-maxdays="' . $attributes['maxdays'] . '" ';
            $html .= 'src="https://widgets.bcc.no/widgets/BirthdayJs" defer></script>';

            return $html . PHP_EOL;
        } );
    }

    function get_access_token() {
        $token = $_SESSION['oidc_access_token'];

        if ( empty( $token ) ) {
            $token_id = $_COOKIE['oidc_token_id'];
            $token = get_transient( 'oidc_access_token_' . $token_id );
            $_SESSION['oidc_access_token'] = $token;
        }
        
        return $token;
    }
}