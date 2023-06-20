<?php

class BCC_Login_User {
    static function get_current_user_claims() {
        if ( isset ( $_COOKIE['oidc_token_id'] ) )
        {
            $token_id = $_COOKIE['oidc_token_id'];
            $token = get_transient( 'oidc_id_token_' . $token_id );
            $user = BCC_Login_Token_Utility::get_token_claims($token);

            $today = date("Y-m-d");
            $user['age'] = date_diff( date_create( $user['birthdate'] ), date_create( $today ) )->format( '%y' );

            return $user;
        }

        return null;
    }
}