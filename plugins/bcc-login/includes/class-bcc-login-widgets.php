<?php

class BCC_Login_Widgets {

    private BCC_Login_Settings $settings;
    private BCC_Login_Client $client;

    function __construct( BCC_Login_Settings $settings, BCC_Login_Client $client ) {
        $this->settings = $settings;
        $this->client = $client;

        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
        add_action( 'wp_head', array( $this, 'render_topbar' ) );
    }

    function enqueue_styles() {
        if ( is_user_logged_in() && $this->settings->topbar ) {
            wp_enqueue_style( 'bcc-login-widgets', 'https://widgets.bcc.no/styles/widgets.css' );
            wp_add_inline_style( 'bcc-login-widgets', 'body{margin-top:48px!important;}.portal-top-bar-spacer{display:none;}.admin-bar .portal-top-bar{top:32px;}@media screen and (max-width: 600px){.admin-bar .portal-top-bar{position:absolute;top:46px;}}@media screen and (max-width: 782px){.admin-bar .portal-top-bar{top:46px;}}' );
        }
    }

    function render_topbar() {
        if ( is_user_logged_in() && $this->settings->topbar ) {
            echo '<script id="script-bcc-topbar" data-authentication-type="WebApp" data-authentication-location="'. site_url( 'bcc-login/access-token/' ) . '" src="https://widgets.bcc.no/widgets/TopbarJs" defer></script>' . PHP_EOL;
        }
    }
}
