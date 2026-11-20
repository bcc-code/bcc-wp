<?php

// A bare token, not HTML: keep browsers from rendering it.
header( 'Content-Type: text/plain; charset=utf-8' );
header( 'X-Content-Type-Options: nosniff' );
setcookie('wordpress_nocache', 'true');
$token = '';
$token_id = $_COOKIE['oidc_token_id'] ?? '';
if ( ! empty( $token_id ) ) {    
    $token = get_transient( 'oidc_id_token_' . $token_id );
}

echo $token;

?>
