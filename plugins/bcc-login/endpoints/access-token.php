<?php

$token = '';
$token_id = $_COOKIE['oidc_token_id'] ?? '';

if ( ! empty( $token_id ) ) {
    setcookie('wordpress_nocache', 'true');
    $token = get_transient( 'oidc_access_token_' . $token_id );
}

echo $token;

?>
