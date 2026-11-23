<?php

// A bare token, not HTML: keep browsers from rendering it.
header( 'Content-Type: text/plain; charset=utf-8' );
header( 'X-Content-Type-Options: nosniff' );

$token = '';
$token_id = $_COOKIE['oidc_token_id'] ?? '';

if ( ! empty( $token_id ) ) {
    setcookie('wordpress_nocache', 'true');
    $token = get_transient( 'oidc_access_token_' . $token_id );
}

echo $token;

?>
