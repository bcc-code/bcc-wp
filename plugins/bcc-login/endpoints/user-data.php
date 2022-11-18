<?php

$token = $_SESSION['oidc_id_token'];

if ( empty( $token ) ) {
    $token_id = $_COOKIE['oidc_token_id'];
    $token = get_transient( 'oidc_id_token_' . $token_id );
    $_SESSION['oidc_id_token'] = $token;
}

$token_claims = BCC_Login_Token_Utility::get_token_claims( $token );

echo json_encode($token_claims);

?>