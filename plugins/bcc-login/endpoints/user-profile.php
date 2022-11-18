<?php

$token = $_SESSION['oidc_id_token'];

if ( empty( $token ) ) {
    $token_id = $_COOKIE['oidc_token_id'];
    $token = get_transient( 'oidc_id_token_' . $token_id );
    $_SESSION['oidc_id_token'] = $token;
}

if ( $token ) {
    $parts = explode( '.', $id_token );

    if ( count( $parts ) > 1 ) {
        $json_str = base64_decode(
            str_replace( // Because token is encoded in base64 URL (and not just base64).
                array( '-', '_' ),
                array( '+', '/' ),
                $tmp[ 1 ]
            )
        );
        echo $json_str;
    }

}

echo '{}';

?>