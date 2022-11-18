<?php

class BCC_Login_Token_Utility {

    public static function get_token_claims( $id_token ) {
        // Check if id token exists
        if ( ! isset( $id_token ) ) {
            return array();
        }

        // Break apart the id_token in the response for decoding.
        $tmp = explode( '.', $id_token );

        if ( ! isset( $tmp[ 1 ] ) ) {
            return array();
        }

        // Extract the id_token's claims from the token.
        return json_decode(
            base64_decode(
                str_replace( // Because token is encoded in base64 URL (and not just base64).
                    array( '-', '_' ),
                    array( '+', '/' ),
                    $tmp[ 1 ]
                )
            ),
            true
        );
    }

}