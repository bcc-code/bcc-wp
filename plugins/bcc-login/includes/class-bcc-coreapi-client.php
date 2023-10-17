<?php

class BCC_Coreapi_Client
{
    private BCC_Login_Settings $_settings;
    private BCC_Storage $_storage;
    private array $_site_groups;

    function __construct(BCC_Login_Settings $login_settings, BCC_Storage $storage)
    {
        $this->_settings = $login_settings;
        $this->_storage = $storage;
    }

    function get_site_groups(): array {
        if(isset($this->_site_groups)) {
            return $this->_site_groups;
        }
        $group_uids = $this->_settings->site_groups;

        $cache_key = 'coreapi_groups_' . implode($group_uids);

        $cached_response = get_transient($cache_key);
        if($cached_response) {
            return $cached_response;
        }

        $this->_site_groups = $this->fetch_groups($group_uids);

        $expiration_duration = 60 * 60 * 24; // 1 day
        set_transient($cache_key, $this->_site_groups, $expiration_duration);

        return $this->_site_groups;
    }

    function fetch_groups(array $group_uids): array
    {
        $token = $this->get_coreapi_token();

        $qry = array(
            "uid" => array(
                "_in" => $group_uids,
            )
        );

        $qry = json_encode($qry);

        $response = wp_remote_get( $this->_settings->coreapi_base_url. "/groups?fields=uid,name&filter=$qry", array(
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
        $cache_key = 'coreapi_user_groups_'.$user_uid;

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

        $request_url =  $this->_settings->coreapi_base_url . "/v2/persons/". $user_uid . "/checkGroupMemberships";
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

    public function ensure_subscription_to_person_updates() {
        if(str_contains(site_url(), "localhost")){
            return;
        }

        $subscribed = get_transient("subscribed_to_person_updates");
        if ($subscribed) {
            return;
        }

        $lock = new ExclusiveLock( "subscribe_person_updates" );

        if( $lock->lock( ) == FALSE ){
            return;
        }

        try {
            $this->subscribe_to_person_updates();
            $expiry = 60 * 60 * 12; // 12 hours
            set_transient("subscribed_to_person_updates", true, $expiry);
        } catch (Exception $e) {
            error_log("cannot subscribe to person updates");
        } finally {
            $lock->unlock();
        }
    }

    private function subscribe_to_person_updates() {
        $token = $this->get_coreapi_token();

        $request_url =  $this->_settings->coreapi_base_url . "/pubsub/subscriptions";
        $request_body = array(
            "type" => "no.bcc.api.person.updated",
            "endPoint" => site_url() . "?bcc-login=invalidate-person-cache",
            "subscriptionId" => parse_url(site_url())['host']
        );

        $response = wp_remote_post($request_url, array(
            "body" => wp_json_encode( $request_body ),
            "headers" => array(
                "Authorization" => "Bearer " . $token,
                "Content-Type" => "application/json"
            )
        ));

        if ( is_wp_error( $response ) ) {
            wp_die( $response->get_error_message() );
        }

        if ($response['response']['code'] != 200) {
            wp_die("cannot ensure subscription to person updates: " . print_r($response, true));
        }
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