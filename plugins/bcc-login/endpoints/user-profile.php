<?php

$token = '';
$token_id = $_COOKIE['oidc_token_id'];

if ( ! empty( $token_id ) ) {    
    $token = get_transient( 'oidc_id_token_' . $token_id );
}

if ( $token ) {
    $parts = explode( '.', $token );

    if ( count( $parts ) > 1 ) {
        $json_str = base64_decode(
            str_replace( // Because token is encoded in base64 URL (and not just base64).
                array( '-', '_' ),
                array( '+', '/' ),
                $parts[ 1 ]
            )
        );
        echo $json_str;
    }

} else {
    echo '{}';
}
?>
