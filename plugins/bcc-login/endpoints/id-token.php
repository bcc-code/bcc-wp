<?php

setcookie('wordpress_nocache', 'true');
$token = '';
$token_id = $_COOKIE['oidc_token_id'];
if ( ! empty( $token_id ) ) {    
    $token = get_transient( 'oidc_id_token_' . $token_id );
}

echo $token;

?>
