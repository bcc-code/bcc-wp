<?php

class BCC_Coreapi_Client
{
    private BCC_Login_Settings $_settings;
    private BCC_Storage $_storage;

    function __construct(BCC_Login_Settings $login_settings, BCC_Storage $storage)
    {
        $this->_settings = $login_settings;
        $this->_storage = $storage;
    }

    function get_groups(): array
    {
        $token = $this->get_coreapi_token();

        $response = wp_remote_get( $this->_settings->coreapi_base_url. "/groups", array(
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
        if(empty( $this->_settings->site_groups)) return array();

        $token = $this->get_coreapi_token();

        $request_url =  $this->_settings->coreapi_base_url . "/v2/persons/". $user_uid . "/checkGroups";
        $request_body = array(
            "groupUids" => $this->_settings->site_groups
        );

        $response = wp_remote_post($request_url, array(
            "body" => wp_json_encode( $request_body ),
            "headers" => array(
                "Authorization" => "Bearer " . $token
            )
        ));


        if ( is_wp_error( $response ) ) {
            wp_die( $response->get_error_message() );
        }

        if ($response['response']['code'] != 200) {
            wp_die("cannot fetch groups for user: " . print_r($response['body'], true));
        }


        $body = json_decode($response['body']);

        return $body->data->groupUids;
    }


    public function get_coreapi_token() : string {
        $cached_token = $this->_storage->get('coreapi_token');
        if ($cached_token !== null) {
            return $cached_token;
        }

        $token_response = $this->request_coreapi_token();

        $this->_storage->set('coreapi_token', $token_response->access_token, $token_response->expires_in * 0.9);
        return $token_response->access_token;
    }

    private function request_coreapi_token() : Token_Response {
        $parsed_url = parse_url( $this->_settings->token_endpoint );
        $host = $parsed_url['host'];

        $request = array(
            'body' => array(
                'client_id'     => $this->_settings->client_id,
                'client_secret' => $this->_settings->client_secret,
                'grant_type'    => 'client_credentials',
                'audience'      => $this->_settings->coreapi_audience,
            )
        );

        $response = wp_remote_post( $this->_settings->token_endpoint, $request );

        if ( is_wp_error( $response ) ) {
            wp_die( $response->get_error_message() );
        }

        $body = json_decode($response['body']);

        $token_response = new Token_Response();
        foreach ($body as $key => $value) $token_response->{$key} = $value;

        return $token_response;
    }
}

class Token_Response {
    public string $access_token;
    public int $expires_in;
}

?>