<?php

class BCC_Coreapi_Client
{
    private BCC_Login_Settings $_settings;
    private BCC_Storage $_storage;
    private $_site_groups;
    private $_all_groups;

    function __construct(BCC_Login_Settings $login_settings, BCC_Storage $storage)
    {
        $this->_settings = $login_settings;
        $this->_storage = $storage;
        add_filter( 'http_request_timeout', array( $this, 'extend_http_request_timeout' ) );
    }

    function extend_http_request_timeout( ) {
        // 10 minutes to allow sending notifications
        return 600;
    }

    public function get_all_groups() {
        if (isset($this->_all_groups)) {
            return $this->_all_groups;
        }

        $cache_key = 'coreapi_all_groups';
        $cached_response = get_transient($cache_key);

        if ($cached_response !== false && !empty($cached_response)) {
            return $cached_response;
        }

        $groups = [];

        // Get groups by tag
        $group_tags = $this->_settings->site_group_tags;
        $result = $this->fetch_groups_by_tags($group_tags);
        foreach ($result as $group) {
            $groups[$group->uid] = $group;
        }

        // Get groups already in use on site
        $group_uids = $this->_settings->site_groups;
        $result = $this->fetch_groups($group_uids);
        foreach ($result as $group) {
            $groups[$group->uid] = $group;
        }

        // $group_uids = $this->_settings->filtering_groups;
        // $result = $this->fetch_groups($group_uids);
        // foreach ($result as $group) {
        //     $groups[$group->uid] = $group;
        // }

        // $group_uids = $this->_settings->notification_groups;
        // $result = $this->fetch_groups($group_uids);
        // foreach ($result as $group) {
        //     $groups[$group->uid] = $group;
        // }
    
        $this->_all_groups = array_values($groups);
        
        $expiration_duration = 60 * 60 * 24; // 1 day
        set_transient($cache_key, $this->_all_groups, $expiration_duration);

        return $this->_all_groups;
    }

    function get_site_groups() {
        if (isset($this->_site_groups)) {
            return $this->_site_groups;
        }

        $cache_key = 'coreapi_groups';
        $cached_response = get_transient($cache_key);

        if ($cached_response !== false && !empty($cached_response)) {
            return $cached_response;
        }

        $group_uids = $this->_settings->site_groups;
        $this->_site_groups = $this->fetch_groups($group_uids);

        $expiration_duration = 60 * 60 * 24; // 1 day
        set_transient($cache_key, $this->_site_groups, $expiration_duration);

        return $this->_site_groups;
    }

    function get_translated_site_groups() {
        $site_groups = $this->get_site_groups();

        foreach ($site_groups as $id => $group) {
            $site_groups[$id]->name = __( $group->name, 'bcc-login' );
        }

        return $site_groups;
    }

    function fetch_groups($group_uids)
    {
        $token = $this->get_coreapi_token();

        $qry = array(
            "uid" => array(
                "_in" => $group_uids,
            )
        );

        $qry = json_encode($qry);

        $response = wp_remote_get( str_replace("https://", "https://core.", $this->_settings->coreapi_base_url) . "/groups?limit=1000&fields=uid,name,tags&filter=$qry", array(
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

    function fetch_groups_by_tags($tags) {
        if (is_string($tags)) {
            $tags = explode(',', $tags);
        }

        $all_groups = [];

        foreach ($tags as $tag) {
            $groups = $this->fetch_groups_by_tag(trim($tag));
            foreach ($groups as $group) {
                $all_groups[$group->uid] = $group;
            }
        }
        return array_values($all_groups);
    }

    function fetch_groups_by_tag($tag)
    {
        $token = $this->get_coreapi_token();

        $qry = array(
            "tags" => array(
                "_contains" => array ( $tag ),
            )
        );

        $qry = json_encode($qry);

        $response = wp_remote_get( str_replace("https://", "https://core.", $this->_settings->coreapi_base_url) . "/groups?limit=1000&fields=uid,name,tags&filter=$qry", array(
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
    

    function get_groups_for_user($user_uid) {
        $cache_key = 'coreapi_user_groups_'.$user_uid;

        $cached_response = get_transient($cache_key);
        if ($cached_response !== false) {
            return $cached_response;
        }

        $user_groups = $this->fetch_groups_for_user($user_uid);

        $expiration_duration = 60 * 60 * 24; // 1 day
        set_transient($cache_key, $user_groups, $expiration_duration);

        return $user_groups;
    }

    function fetch_groups_for_user($user_uid) {
        if (empty($this->_settings->site_groups)) return array();

        $token = $this->get_coreapi_token();

        $request_url = str_replace("https://", "https://core.", $this->_settings->coreapi_base_url) . "/v2/persons/". $user_uid . "/checkGroupMemberships";
        $batch_size = 50; //Max supported by core API
        $total_groups = count($this->_settings->site_groups);
        $user_groups = [];

        for ($i = 0; $i < $total_groups; $i += $batch_size) {

            $batch = array_slice($this->_settings->site_groups, $i, $batch_size);
            $request_body = array(
                "groupUids" => $batch
            );

            $response = wp_remote_post($request_url, array(
                "body" => wp_json_encode( $request_body ),
                "headers" => array(
                    "Authorization" => "Bearer " . $token
                )
            ));

            if (is_wp_error($response)) {
                wp_die($response->get_error_message());
            }

            if ($response['response']['code'] != 200) {
                wp_die("cannot fetch groups for user: " . print_r($response['body'], true));
            }

            $body = json_decode($response['body']);

            $user_groups = array_merge($user_groups, $body->data->groupUids);
        }
        return $user_groups;
    }



    public function ensure_subscription_to_person_updates() {
        if ($this->_settings->disable_pubsub) {
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

    public function unsubscribe_to_person_updates() {
        if (str_contains(site_url(), "localhost") || str_contains(site_url(), ".local")) {
            return;
        }

        $token = $this->get_coreapi_token();

        $pubsub_base_url = str_replace("https://", "https://pubsub.", $this->_settings->coreapi_base_url);
        $request_url =  $pubsub_base_url . "/pubsub/subscriptions/no.bcc.api.person.updated?subscriptionId=".parse_url(site_url())['host'];

        $response = wp_remote_request($request_url, array(
            "method" => "DELETE",
            "headers" => array(
                "Authorization" => "Bearer " . $token
            )
        ));

        if ( is_wp_error( $response ) ) {
            wp_die( $response->get_error_message() );
        }

        $response_code = $response['response']['code'];
        if ($response_code != 200 && $response_code != 404 ) {
            wp_die("cannot unsubscribe to person updates: " . print_r($response, true));
        }
    }

    public function subscribe_to_person_updates() {
        if (str_contains(site_url(), "localhost") || str_contains(site_url(), ".local")) {
            return;
        }

        $token = $this->get_coreapi_token();

        $request_url =  str_replace("https://", "https://pubsub.", $this->_settings->coreapi_base_url) . "/pubsub/subscriptions";
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
            wp_die("cannot subscribe to person updates: " . print_r($response, true));
        }
    } 

    // {
    //     "workflowId": "simpleinapp",
    //     "personUid": "fcfc4132-1aaa-464f-9080-f49a06287f80",
    //     "notificationPayload": [
    //       {
    //         "language": "en-US",
    //         "notification": "You need a new app for live broadcasts [cta text=\"Download here\" link=\"https://portal.bcc.no/en/bcc-connect-en/important-new-app-for-viewing-live-brunstad-transmissions/\"]"
    //       },
    //       {
    //         "language": "no-NO",
    //         "notification": "Du trenger en ny app for livesendinger [cta text=\"Last ned her\" link=\"https://portal.bcc.no/bcc-connect/viktig-informasjon-ny-app-vil-sende-live-fra-brunstad/\"]"
    //       }
    //     ]
    //   }




    // Type = email, sms, inapp
    public function send_notification($group_uids, $type, $workflow, $payload) {
        $token = $this->get_coreapi_token();


        //$request_url =  $this->_settings->coreapi_base_url . "/notifications/notification?createSubscribers=false&pushNotifications=true";
        $request_url =  str_replace("https://", "https://notifications.", $this->_settings->coreapi_base_url) . "/notifications/notification/". $type ."?createSubscribers=true&pushNotifications=" . ($this->_settings->notification_dry_run ? "false" : "true");
        $request_body = array(
            "workflowId" => $workflow,
            "groupUids" => array_values($group_uids), 
            "notificationPayload" => $payload
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
            wp_die("Could not send notification: " . print_r($response, true));
        }
    } 

    public function get_coreapi_token() {
        $cached_token = $this->_storage->get('coreapi_token');
        if ($cached_token !== null) {
            return $cached_token;
        }

        $token_response = $this->fetch_coreapi_token(
            $this->_settings->token_endpoint,
            $this->_settings->client_id,
            $this->_settings->client_secret,
            $this->_settings->coreapi_audience
        );

        if($token_response == false) {
            return '';
        }

        $this->_storage->set('coreapi_token', $token_response->access_token, $token_response->expires_in * 0.9);
        return $token_response->access_token;
    }

    private static function fetch_coreapi_token($token_endpoint, $client_id, $client_secret, $audience) {
        $request = array(
            'body' => array(
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'grant_type'    => 'client_credentials',
                'audience'      => $audience,
            )
        );

        $response = wp_remote_post( $token_endpoint, $request );

        if ( is_wp_error( $response ) ) {
            wp_die( $response->get_error_message() );
        }

        if ($response['response']['code'] != 200) {
            return false;
        }

        $body = json_decode($response['body']);

        $token_response = new Token_Response();
        foreach ($body as $key => $value) $token_response->{$key} = $value;

        return $token_response;
    }

    public static function check_groups_access($token_endpoint, $client_id, $client_secret, $audience) {
        $token_response = BCC_Coreapi_Client::fetch_coreapi_token($token_endpoint, $client_id, $client_secret, $audience);

        if($token_response == false) return false;
        if(!isset($token_response->scope)) return false;

        if(!str_contains($token_response->scope, "groups#read")) return false;
        if(!str_contains($token_response->scope, "pubsub#subscribe")) return false;

        return true;
    }
}

class Token_Response {
    public $access_token;
    public $scope;
    public $expires_in;
    public $token_type;
}

?>
