<?php

class BCC_Groups_Client
{
    private BCC_Login_Client $_auth_client;
    private string $_api_endpoint = "https://api.bcc.no/groups";

    function __construct(BCC_Login_Client $client)
    {
        $this->_auth_client = $client;
    }

    function get_groups(): array
    {
        $token = $this->_auth_client->get_coreapi_token();

        $response = wp_remote_get( $this->_api_endpoint, array(
            "headers" => array(
                "Authorization" => "Bearer ".$token
            )
        ) );


        if ( is_wp_error( $response ) ) {
            wp_die( $response->get_error_message() );
        }

        $body = json_decode($response['body']);

        return $body->data;
    }
}

?>