<?php

/**
* Add widgets as an audience when the user is logging in
*/
add_filter('openid-connect-generic-auth-url', function( $url ) {
    $url .= '&audience=https%3A%2F%2Fwidgets.brunstad.org';
    return $url;
});

/**
* Add bcc widgets style
*/
add_action( 'wp_enqueue_scripts', 'register_bcc_widgets_styles' );
function register_bcc_widgets_styles() {
    wp_register_style( 'bcc_widgets_stylesheet','https://widgets.bcc.no/styles/widgets.css');
    wp_enqueue_style( 'bcc_widgets_stylesheet' );
}

if (get_option('bcc_topbar') == 1) {
    add_action('wp_head', 'add_topbar');
}



    add_action('wp_head', 'ensure_access_token_clientside');



/** Widgets */

function get_access_token_url(){
    $plugin_dir = constant( 'WP_PLUGIN_DIR' );
    // get_home_path() is only loaded in wp-admin; these URLs are also needed on the front end.
    if ( ! function_exists( 'get_home_path' ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    $root_dir = get_home_path();
    $relative_plugin_dir = substr($plugin_dir, strlen($root_dir));
    return '/' . $relative_plugin_dir . '/bcc-signon/openid-connect-bcc-customizations/access-token.php';
}

function get_refesh_login_url(){
    $plugin_dir = constant( 'WP_PLUGIN_DIR' );
    // get_home_path() is only loaded in wp-admin; these URLs are also needed on the front end.
    if ( ! function_exists( 'get_home_path' ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    $root_dir = get_home_path();
    $relative_plugin_dir = substr($plugin_dir, strlen($root_dir));
    return '/' . $relative_plugin_dir . '/bcc-signon/openid-connect-bcc-customizations/refresh-login.php';
}

function ensure_access_token_clientside() {
  // If user is logged in, add a client side check to make sure access token is still
  // available (i.e. that session variable has not been lost)
  // If access token (stored in session variable) has been lost, refresh user login
  if (is_user_logged_in()) {
    $script = '<script>
    fetch("' . get_access_token_url() . '")
        .then(function(response) {
            response.text().then(function (token) {
              if (!token) {
                  document.location.href = "' . get_refesh_login_url() . '";
              }
            });
        });
</script>';

   echo $script;
  }
}

/** Topbar */
function add_topbar() {
    if (!is_user_logged_in()) return;

    echo '<script id="script-bcc-topbar" data-authentication-type="WebApp" data-authentication-location="'. get_access_token_url() . '" src="https://widgets.bcc.no/widgets/TopbarJs"></script>';
    return;

    /* $access_token = BCC_Signon::get_access_token();

    if (empty( $access_token )) {
        echo '<script type="text/javascript" id="script-bcc-topbar" src="https://widgets.bcc.no/widgets/topbarjs" data-authentication-type="none"></script>';
        return;
    }

    if ( strlen($access_token) < 30) {
        echo "<script type='text/javascript'>window.top.location='https://auth.bcc.no/?message=signout';</script>";
        return;
    }
      
    echo ('<script type="text/javascript" id="script-bcc-topbar" src="https://widgets.bcc.no/widgets/topbarjs" data-authentication-type="inline-access-token" data-access-token=' . $access_token .'></script>');
    
    */
};


/** Week calendar */
add_shortcode( 'bcc-widgets-week-calendar', function ($attributes) {

    // normalize attribute keys, lowercase
    $attributes = array_change_key_case((array)$attributes, CASE_LOWER);

    $html =  '<div id="bcc-calendar-week"></div>';
    $html .= '<script async="true" id="script-bcc-calendar-week" data-authentication-type="WebApp" data-authentication-location="'. get_access_token_url() . '" ';
    $html .= 'data-language="' . esc_attr( $attributes['language'] ) . '" data-maxdays="' .  esc_attr( $attributes['maxdays'] ) . '" data-maxappointments="' . esc_attr( $attributes['maxappointments'] ) . '" ';
    $html .= 'data-calendars="' . esc_attr( $attributes['calendars'] ) .'" data-fullcalendarurl="' .  esc_attr( wp_kses_bad_protocol( $attributes['fullcalendarurl'] ?? '', wp_allowed_protocols() ) ) .'" ';
    $html .= 'src="https://widgets.bcc.no/widgets/CalendarWeekJs"></script>';

    return $html;

} );

/** Month calendar */
add_shortcode( 'bcc-widgets-month-calendar', function ($attributes) {

    // normalize attribute keys, lowercase
    $attributes = array_change_key_case((array)$attributes, CASE_LOWER);

    $html =  '<div id="bcc-calendar-month"></div>';
    $html .= '<script async="true" id="script-bcc-calendar-month" data-authentication-type="WebApp" data-authentication-location="'. get_access_token_url() . '" ';
    $html .= 'data-language="' . esc_attr( $attributes['language'] ) . '"';
    $html .= 'data-calendars="' . esc_attr( $attributes['calendars'] ) .'" ';
    $html .= 'src="https://widgets.bcc.no/widgets/CalendarMonthJs"></script>';
    
    return $html;
} );

/** Search */
add_shortcode( 'bcc-widgets-search', function ($attributes) {
    
    // normalize attribute keys, lowercase
    $attributes = array_change_key_case((array)$attributes, CASE_LOWER);

    $html =  '<div id="bcc-search"></div>';
    $html .= '<script async="true" id="script-bcc-search" data-authentication-type="WebApp" data-authentication-location="'. get_access_token_url() . '" ';
    $html .= 'data-language="' . esc_attr( $attributes['language'] ) . '"';
    $html .= 'data-hidesearchbox="' . esc_attr( $attributes['hidesearchbox'] ) .'" ';
    $html .= 'data-searchquery="' . esc_attr( $attributes['searchquery'] ) .'" ';
    $html .= 'src="https://widgets.bcc.no/widgets/SearchJs"></script>';
    
    return $html;
} );

/** TVGuide */
add_shortcode( 'bcc-widgets-tvguide', function ($attributes) {
    
    // normalize attribute keys, lowercase
    $attributes = array_change_key_case((array)$attributes, CASE_LOWER);

    $html = '<div id="bcc-tvguide"></div>';
    $html .= '<script async="true" id="script-bcc-tvguide" data-authentication-type="WebApp" data-authentication-location="'. get_access_token_url() . '" ';
    $html .= 'data-language="' . esc_attr( $attributes['language'] ) . '" ';
    $html .= 'data-maxdays="' . esc_attr( $attributes['maxdays'] ) . '" src="https://widgets.bcc.no/widgets/TvGuideJs"></script>';
    
    return $html;
} );

/** Birthday */
add_shortcode( 'bcc-widgets-birthday', function ($attributes) {

    // normalize attribute keys, lowercase
    $attributes = array_change_key_case((array)$attributes, CASE_LOWER);

    $html = '<div id="bcc-birthday"></div>';
    $html .= '<script async="true" id="script-bcc-birthday" data-authentication-type="WebApp" data-authentication-location="'. get_access_token_url() . '" ';
    $html .= 'data-language="' . esc_attr( $attributes['language'] ) . '" ';
    $html .= 'data-churchname="' . esc_attr( $attributes['churchname'] ) . '" ';
    $html .= 'data-maxdays="' . esc_attr( $attributes['maxdays'] ) . '" ';
    $html .= 'src="https://widgets.bcc.no/widgets/BirthdayJs"></script>';

    return $html;
} );
