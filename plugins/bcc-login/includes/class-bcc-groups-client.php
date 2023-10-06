<?php

class BCC_Groups_Client
{
    private BCC_Login_Client $_auth_client;
    private BCC_Login_Settings $_settings;
    private string $_api_base_url = "https://api.bcc.no";

    function __construct(BCC_Login_Settings $settings,BCC_Login_Client $client)
    {
        $this->_auth_client = $client;
        $this->_settings = $settings;
    }

    function get_groups(): array
    {
        $token = $this->_auth_client->get_coreapi_token();

        $response = wp_remote_get( $this->_api_base_url. "/groups", array(
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

    function get_groups_for_user(string $user_uid): array {
        $cache_key = 'coreapi_groups_'.$user_uid;

        $cached_response = get_transient($cache_key);
        if($cached_response !== false) {
            return $cached_response;
        }

        $user_groups = $this->fetch_groups_for_user($user_uid);

        $expiration_duration = 60 * 60 * 24; // 1 day
        set_transient($cache_key, $user_groups, $expiration_duration);

        return $user_groups;
    }

    function fetch_groups_for_user(string $user_uid): array
    {
        $token = $this->_auth_client->get_coreapi_token();

        $response = wp_remote_get( $this->_api_base_url . "/v2/persons/". $user_uid . "/groups", array(
            "body" => array(
                "groups" => $this->_settings->groups_allowed
            ),
            "headers" => array(
                "Authorization" => "Bearer ".$token
            )
        ) );


        if ( is_wp_error( $response ) ) {
            wp_die( $response->get_error_message() );
        }

        $body = json_decode($response['body']);

        print_r($body);

        return $body->data->groups;
    }
}

?>