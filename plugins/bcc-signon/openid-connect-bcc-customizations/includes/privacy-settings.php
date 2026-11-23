<?php 
/**
 * This function redirects non-authenticated users to the login page.
 * It is triggered each time a user navigates to a page.
 */

/* Backwards compatibility for versions < 1.1.13 */
add_filter('oidc_unprotected_urls', function($unprotected_urls) {
  return apply_filters('bcc_unprotected_urls', $unprotected_urls);
});

/* DISABLE REST API FOR NON-LOGGED IN USERS */
remove_action('template_redirect', 'rest_output_link_header', 11);
remove_action('wp_head', 'rest_output_link_wp_head', 10);
remove_action('xmlrpc_rsd_apis', 'rest_output_rsd');

if ( version_compare(get_bloginfo('version'), '4.7', '>=') ) {
  add_filter('rest_authentication_errors', 'disable_wp_rest_api');
} else {
  disable_wp_rest_api_legacy();
}

function disable_wp_rest_api($access) {
  // Decide on the REST route WordPress is serving, not on the request path.
  $route = isset( $GLOBALS['wp']->query_vars['rest_route'] ) ? (string) $GLOBALS['wp']->query_vars['rest_route'] : '';

  if ( !is_user_logged_in() && 0 !== strpos($route, '/bcc-wp-proxy/') /*&& !isUnprotectedRestUrl($url)*/ ) {
    $message = apply_filters('disable_wp_rest_api_error', __('REST API restricted to authenticated users.', 'disable-wp-rest-api'));
    return new WP_Error('rest_login_required', $message, array('status' => rest_authorization_required_code()));
  }
  return $access;
}

function disable_wp_rest_api_legacy() {
    add_filter('json_enabled', '__return_false');
    add_filter('json_jsonp_enabled', '__return_false');
    add_filter('rest_enabled', '__return_false');
    add_filter('rest_jsonp_enabled', '__return_false');
}

// function isUnprotectedRestUrl ($url) {
//     $regexes = array (
//       '/contact-form-7.*?$/i'
//     );

//     foreach ($regexes as $regex) {
//         if (preg_match($regex, $url)) {
//             return true;
//         }
//     }

//     return false;
// }
?>