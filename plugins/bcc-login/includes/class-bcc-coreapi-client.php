<?php

class BCC_Coreapi_Client
{
    private BCC_Login_Settings $_settings;
    private BCC_Storage $_storage;
    private $_site_groups;

    function __construct(BCC_Login_Settings $login_settings, BCC_Storage $storage)
    {
        $this->_settings = $login_settings;
        $this->_storage = $storage;

        add_shortcode('get_bcc_group_name', array($this, 'get_bcc_group_name_by_id'));
        add_shortcode('bcc_groups_filtering', array($this, 'bcc_groups_filtering'));
    }

    function get_bcc_group_name_by_id($atts) {
        $attributes = shortcode_atts(array('uid' => ''), $atts);
        $uid = $attributes['uid'];
        if (!$uid)
            return;

        $site_groups = $this->get_site_groups();
        if (!$site_groups)
            return;

        $bcc_site_group = array_values(array_filter($site_groups, function($bcc_group) use ($uid) {
            return $bcc_group->uid == $uid;
        }));

        if (!count($bcc_site_group))
            return;

        return $bcc_site_group[0]->name;
    }

    function bcc_groups_filtering() {
        $site_groups = $this->get_site_groups();
        if (!$site_groups)
            return;

        // Sort by name
        usort($site_groups, fn($a, $b) => $a->name <=> $b->name);

        $bcc_groups_selected = isset($_GET['target-groups']) ? $_GET['target-groups'] : array();

        $html = '<div class="bcc-filter">' .
            '<a href="javascript:void(0)" id="toggle-bcc-filter"> <svg xmlns="http://www.w3.org/2000/svg" height="1em" viewBox="0 0 448 512"><path d="M0 96C0 78.3 14.3 64 32 64H416c17.7 0 32 14.3 32 32s-14.3 32-32 32H32C14.3 128 0 113.7 0 96zM64 256c0-17.7 14.3-32 32-32H352c17.7 0 32 14.3 32 32s-14.3 32-32 32H96c-17.7 0-32-14.3-32-32zM288 416c0 17.7-14.3 32-32 32H192c-17.7 0-32-14.3-32-32s14.3-32 32-32h64c17.7 0 32 14.3 32 32z" fill="currentColor"/></svg> <span>Filter</span></a>' .
            '<div id="bcc-filter-groups">' .
                '<a href="javascript:void(0)" id="clear-bcc-groups">' . __('Clear all', 'bcc-login') . '</a>'  .
                '<a href="javascript:void(0)" id="close-bcc-groups">Close</a>'  ;
        
        $html .= '<ul>';
        foreach ($site_groups as $group) :
            $html .= '<li>' .
                '<input type="checkbox" id="'. $group->uid .'" value="'. $group->uid .'" name="target-groups[]"' . (in_array($group->uid, $bcc_groups_selected) ? 'checked' : '') . '/>' .
                '<label for="' . $group->uid . '"><div class="bcc-checkbox"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none"><path fill="#fff" d="M6.3 11.767a.498.498 0 0 1-.208-.042.862.862 0 0 1-.192-.125L2.883 8.583a.565.565 0 0 1-.166-.416c0-.167.055-.306.166-.417a.546.546 0 0 1 .4-.167c.156 0 .29.056.4.167L6.3 10.367l6-6a.546.546 0 0 1 .4-.167c.156 0 .295.056.417.167a.555.555 0 0 1 .166.408.555.555 0 0 1-.166.408L6.7 11.6a.862.862 0 0 1-.192.125.498.498 0 0 1-.208.042Z"/></svg></div>' . $group->name . '</label>' .
            '</li>';
        endforeach;
        $html .= '<li class="expandable">' .
            '<a href="javascript:void(0)" id="expand-btn">+ ' . __('see all groups', 'bcc-login') . '</a>' .
            '<a href="javascript:void(0)" id="minimize-btn" style="display: none;">- ' . __('see fewer groups', 'bcc-login') . '</a>' .
        '</li>';
        $html .= '</ul>';
        $html .= '<a href="javascript:void(0)" id="bcc-filter-submit">' . __('Apply', 'bcc-login') . '</a>';
        $html .= '</div></div>';

        return $html;
    }

    function get_site_groups() {
        if (isset($this->_site_groups)) {
            return $this->_site_groups;
        }
        $group_uids = $this->_settings->site_groups;

        $cache_key = 'coreapi_groups_' . implode($group_uids);

        $cached_response = get_transient($cache_key);
        if ($cached_response) {
            return $cached_response;
        }

        $this->_site_groups = $this->fetch_groups($group_uids);

        $expiration_duration = 60 * 60 * 24; // 1 day
        set_transient($cache_key, $this->_site_groups, $expiration_duration);

        return $this->_site_groups;
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

    function get_groups_for_user($user_uid) {
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

    function fetch_groups_for_user($user_uid) {
        if (empty($this->_settings->site_groups)) return array();

        $token = $this->get_coreapi_token();

        $request_url = $this->_settings->coreapi_base_url . "/v2/persons/". $user_uid . "/checkGroupMemberships";
        $request_body = array(
            "groupUids" => $this->_settings->site_groups
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

        return $body->data->groupUids;
    }

    public function ensure_subscription_to_person_updates() {
        if (str_contains(site_url(), "localhost") || str_contains(site_url(), ".local") ) {
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
}

?>