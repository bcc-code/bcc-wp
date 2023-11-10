<?php

setcookie('wordpress_nocache', 'true');

wp_clear_auth_cookie();
wp_logout();

if ( isset( $_SERVER['HTTP_COOKIE'] ) ) {
    $cookies = explode( ';', $_SERVER['HTTP_COOKIE'] );
    foreach( $cookies as $cookie ) {
        $parts = explode( '=', $cookie );
        $name = trim( $parts[0] );
        setcookie( $name, '', time() - 1000 );
        setcookie( $name, '', time() - 1000, '/' );
    }
}

wp_redirect( get_home_url() );

exit;

?>
