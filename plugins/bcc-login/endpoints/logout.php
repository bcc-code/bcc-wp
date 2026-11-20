<?php

setcookie('wordpress_nocache', 'true');

/** backchannel logout */
$logout_token = $_POST['logout_token'];
$token_id = '';
$state = '';

if ( $logout_token )
{
    // OIDC backchannel logout. Retrieve session ID from logout_token.
    $logout_token_claims = BCC_Login_Token_Utility::get_token_claims( $logout_token );
    $sid = $logout_token_claims['sid'] ?? '';
    if ( ! empty ( $sid ) ) {
        $token_id = hash_hmac( 'sha256', $sid, wp_salt( 'auth' ) );

        // Sessions created before the handle was keyed still use md5( $sid ) until their tokens expire.
        $legacy_token_id = md5( $sid );
        $legacy_state = get_transient( 'oidc_state_' . $legacy_token_id );
        delete_transient( 'oidc_state_' . $legacy_token_id );
        delete_transient( 'oidc_access_token_' . $legacy_token_id );
        delete_transient( 'oidc_id_token_' . $legacy_token_id );
        if ( $legacy_state ) {
            delete_transient( 'oidc_token_id_' . $legacy_state );
        }
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
