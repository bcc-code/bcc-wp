<?php

require_once( 'includes/class-bcc-login-token-utility.php');

/** backchannel logout */
$logout_token = $_POST['logout_token'];
$token_id = '';

if ( $logout_token )
{
    // OIDC backchannel logout. Retrieve session ID from logout_token.
    $logout_token_claims = BCC_Login_Token_Utility::get_token_claims( $logout_token );
    $sid = $logout_token_claims['sid'];
    if ( $sid && ! empty ( $sid )) {
        $token_id = md5 ( $sid );
    }
    if ( ! empty( $token_id ) ) {
        $state = get_transient( 'oidc_state_' . $token_id );
    }

} else {
    // "Old" backchannel logout, based on state parameter
    $state = $_GET['state'];
    if ( ! $state ) {
        $state = $_POST['state'];
    }

    if ( ! empty( $state ) ) {
        $token_id = get_transient( 'oidc_token_id_' . $state );
    }
}

// Clear login session transients
if ( $token_id ) {
    delete_transient( 'oidc_state_' . $token_id );
    delete_transient( 'oidc_access_token_' . $token_id );
    delete_transient( 'oidc_id_token_' . $token_id ); 
    delete_transient( 'oidc_token_id_' . $state );
}


?>
