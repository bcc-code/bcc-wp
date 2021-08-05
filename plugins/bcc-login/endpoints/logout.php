<?php

/** backchannel logout */
$state = $_GET['state'];
if ( ! $state || empty ( $state ) ) {
    $state = $_POST['state'];
}

if ( ! empty( $state ) ) {
    $token_id = get_transient( 'oidc_token_id_' . $state );

    if ( $token_id ) {
        delete_transient( 'oidc_state_' . $token_id );
        delete_transient( 'oidc_access_token_' . $token_id );
        delete_transient( 'oidc_id_token_' . $token_id ); 
        delete_transient( 'oidc_token_id_' . $state );
    }
}

?>
