<?php

class BCC_Login_Client {

    private BCC_Login_Settings $_settings;
    private $STATE_TIME_LIMIT = 180;

    function __construct( BCC_Login_Settings $settings) {
        $this->_settings = $settings;
        add_action( 'parse_request', array( $this, 'on_parse_request' ) );
    }

    function start_login() {
        $state = $this->create_authentication_state();
        $auth_url = $this->get_authorization_url( $state );

        // WP Engine doesn't cache pages with wordpress_* cookie set
        setcookie('wordpress_nocache', 'true');
        wp_redirect( $auth_url );
        exit;
    }

    function end_login() {
        if ( ! empty( $_COOKIE['oidc_token_id'] ) ) {
            $token_id = $_COOKIE['oidc_token_id'];
            $state = get_transient( 'oidc_state_' . $token_id );
            if ( $state ) {
                delete_transient( 'oidc_token_id_' . $state );
            }
            delete_transient( 'oidc_state_' . $token_id );
            delete_transient( 'oidc_access_token_' . $token_id );
            delete_transient( 'oidc_id_token_' . $token_id );            
        }
        $this->clear_has_user_logged_in_previously();
    }

    /** Determines if the session is still valid (i.e. hasn't been ended via a global backchannel sign-out) */
    function is_session_valid( ) {
        if ( ! empty( $_COOKIE['oidc_token_id'] ) ) {
            $token_id = $_COOKIE['oidc_token_id'];
            $state = get_transient( 'oidc_state_' . $token_id );
            return ! ( ! $state );
        }
        return false;
    }


    private function create_authentication_state() : Auth_State{
        // New state w/ timestamp.
        $obj_state = new Auth_State();
        $obj_state->state = md5( openssl_random_pseudo_bytes(16) . microtime( true ) );
        $obj_state->return_url = $this->get_current_url();
        set_transient( 'oidc_auth_state_' . $obj_state->state, $obj_state, $this->STATE_TIME_LIMIT );

        return $obj_state;
    }

    function on_parse_request( $query ){
        if ( $this->is_redirect_url() ) {
           $this->complete_login();
           exit;
        }
    }

    function is_redirect_url( ){
        $current_url = $this->get_current_url();
        if ( strpos( $current_url, $this->_settings->redirect_uri ) ) {
           return true;
        }
    }

    private function complete_login() {
        $code = $_GET['code'];
        $state = $_GET['state'];

        if ( ! empty( $_GET['error'] ) ) {
            echo $_GET['error'];
            exit;
        }

        $obj_state = get_transient( 'oidc_auth_state_' . $state );

        if ( is_object( $obj_state ) ) {
            $tokens = $this->request_tokens( $code );
            $id_token = $tokens['id_token'];
            $access_token = $tokens['access_token'];

            $id_token_claims = BCC_Login_Token_Utility::get_token_claims( $id_token );
            $this->login_user( $id_token_claims, $access_token, $id_token, $state );
            $this->set_has_user_logged_in_previously();

            wp_redirect( $obj_state->return_url );
        } else {
            wp_redirect( home_url() );
        }
    }

    private function login_user( $id_token_claims, $access_token, $id_token, $state  ) {
        $person_id = $id_token_claims['https://login.bcc.no/claims/personId'];
        $email = $id_token_claims['email'];
        $sid = $id_token_claims['sid'];

        $user = $this->get_user_by_identity( $person_id, $email );

        if ( ! $user ) {
            if ( $id_token_claims['https://login.bcc.no/claims/hasMembership'] == false ) {
                echo 'Invalid user.';
                exit;
            }
            if ( $this->_settings->create_missing_users ) {
                $user = $this->create_new_user( $person_id, $email, $id_token_claims );
            } else {
                $user = $this->get_common_login( $id_token_claims );
            }
        } else {
            // Allow plugins / themes to take action using current claims on existing user (e.g. update role).
            // do_action( 'openid-connect-generic-update-user-using-current-claim', $user, $user_claim );
        }

        if ( ! is_a( $user, 'WP_User' ) || ! $user->exists() ) {
            wp_die( 'User does not exist.' );
        }

        // Login the found / created user.
        $expiration = (int) $id_token_claims['exp'];
        $manager = WP_Session_Tokens::get_instance( $user->ID );
        $token = $manager->create( $expiration );

        // Save access token to session
        $this->save_oidc_session_state( $expiration, $access_token, $id_token, $sid, $state );

        // You did great, have a cookie!
        wp_set_auth_cookie( $user->ID, false, '', $token );
        do_action( 'wp_login', $user->user_login, $user );
    }

    function save_oidc_session_state( $expiration, $access_token, $id_token, $sid, $state ) {
        if ( ! empty( $access_token ) ) {
            $length = 16;
            $strong_result = false;
            $token_id = '';
            if ( ! empty ($sid) ) {
                $token_id = md5( $sid );
            } else {
                $token_id = base64_encode( openssl_random_pseudo_bytes($length, $strong_result) );
                if(!$strong_result) error_log('Token_id not random enough! openssl_random_pseudo_bytes($length, $strong_result) was used to generate a cryptographically secure token_id, but the function concluded that it\'s not secure enough. Please read https://www.php.net/manual/en/function.openssl-random-pseudo-bytes.php');
            }
            
            $timeout = ( (int) $expiration ) - time();

            setcookie( 'oidc_token_id', $token_id, $expiration, '/' , '', true, true );
            set_transient( 'oidc_access_token_' . $token_id, $access_token, $timeout );
            set_transient( 'oidc_state_' . $token_id, $state, $timeout );
            set_transient( 'oidc_token_id_' . $state, $token_id, $timeout );

            if ( ! empty( $id_token ) ) {
                set_transient( 'oidc_id_token_' . $token_id, $id_token, $timeout );
            }
        }
    }

    
    /** Set a long-lived cookie indicating that the user has logged in previously */
    function set_has_user_logged_in_previously() {
        $cookieName = 'wp_has_logged_in';
        $cookieValue =  time();
        $expirationTime = time() + (10 * 365 * 24 * 60 * 60); // 10 years from now
        $path = '/'; // The cookie will be available across the entire domain

        setcookie($cookieName, $cookieValue, $expirationTime, $path);
    }

    /** Read cookie to determine whether user has logged in previously */
    function has_user_logged_in_previously() {
        $cookieName = 'wp_has_logged_in';
        if (isset($_COOKIE[$cookieName])) {
            $cookieValue = $_COOKIE[$cookieName];
            if (!empty($cookieValue)) {
                return true;
            }
        } 
        return false;
    }

    function clear_has_user_logged_in_previously() {
        $cookieName = 'wp_has_logged_in';
        $expirationTime = time() + 3600; // An hour in the past
        $path = '/'; // The cookie will be available across the entire domain
        setcookie($cookieName, '', $expirationTime, $path);
    }

    function create_new_user( $person_id, $email, $id_token_claims ) {
        // Default username & email to the subject identity.
        $username = $person_id;
        $email = $email;
        $nickname = $id_token_claims['given_name'];
        $displayname = $id_token_claims['name'];
        $values_missing = false;

        $user_data = array(
            'user_login' => $username,
            'user_pass' => wp_generate_password( 32, true, true ),
            'user_email' => $email,
            'display_name' => $displayname,
            'nickname' => $nickname,
            'first_name' => isset( $user_claim['given_name'] ) ? $user_claim['given_name'] : '',
            'last_name' => isset( $user_claim['family_name'] ) ? $user_claim['family_name'] : '',
        );

        // Create the new user.
        $uid = wp_insert_user( $user_data );

        // Make sure we didn't fail in creating the user.
        if ( is_wp_error( $uid ) ) {
            echo 'User creation failed.';
            exit;
        }

        // Retrieve our new user.
        return get_user_by( 'id', $uid );
    }

    function get_common_login( $user_claim ) {
        if ( $user_claim[$this->_settings->member_organization_claim_type] == $this->_settings->member_organization_name ) {
            $birthdate = date_create($user_claim['birthdate']);
            $current_date = date_create();
            $diff = date_diff($birthdate, $current_date);
            if ( $diff->y <= 36 ) {
                return BCC_Login_Users::get_youth_member();
            }

            return BCC_Login_Users::get_member();
        }

        return BCC_Login_Users::get_subscriber();
    }

    function get_user_by_identity( $person_id, $email ){
        // 1. Lookup by person_id in user login field
        if ( ! empty( $person_id ) ) {
            $user_query = new WP_User_Query(
                array(
                    'search' => $person_id,
                    'search_columns' => array( 'user_login' )
                )
            );

            // If we found an existing users, grab the first one returned.
            if ( $user_query->get_total() > 0 ) {
                $users = $user_query->get_results();
                return $users[0];
            }
        }

        // 2. Lookup by email
        if ( ! empty( $email ) ) {
            $user_query = new WP_User_Query(
                array(
                    'search' => $email,
                    'search_columns' => array( 'user_email', 'user_login' )
                )
            );

            // If we found an existing users, grab the first one returned.
            if ( $user_query->get_total() > 0 ) {
                $users = $user_query->get_results();
                return $users[0];
            }
        }

        return false;
    }

    private function get_full_redirect_url() {
        return trim( home_url(), '/' ) . '/' . ltrim( $this->_settings->redirect_uri, '/' );
    }

    private function get_current_url() {
        global $wp;

        // If the Permalink Structure is set to Plain we use the old solution with $_SERVER
        // We replace 'wp-login.php' to 'wp-admin' to avoid the redirect loop when logging through SSO directly to the admin dashboard
        return get_option('permalink_structure') == ""
            ? $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . str_replace('wp-login.php', 'wp-admin', $_SERVER['REQUEST_URI'])
            : add_query_arg( $_SERVER['QUERY_STRING'], '', home_url( $wp->request ) );
    }

    private function get_authorization_url( Auth_State $state ) {
        return sprintf(
            '%1$s%2$sresponse_type=code&scope=%3$s&client_id=%4$s&state=%5$s&redirect_uri=%6$s&audience=%7$s',
            $this->_settings->authorization_endpoint,
            '?',
            rawurlencode( $this->_settings->scope ),
            rawurlencode( $this->_settings->client_id ),
            $state->state,
            rawurlencode( $this->get_full_redirect_url() ),
            rawurlencode( 'https://widgets.brunstad.org' )
        );
    }

    private function request_tokens( $code ) {
        $parsed_url = parse_url( $this->_settings->token_endpoint );
        $host = $parsed_url['host'];

        $request = array(
            'body' => array(
                'code'          => $code,
                'client_id'     => $this->_settings->client_id,
                'client_secret' => $this->_settings->client_secret,
                'redirect_uri'  => $this->get_full_redirect_url(),
                'grant_type'    => 'authorization_code',
                'scope'         => $this->_settings->scope,
            ),
            'headers' => array( 'Host' => $host ),
        );

        $response = wp_remote_post( $this->_settings->token_endpoint, $request );

        if ( is_wp_error( $response ) ) {
            wp_die( $response->get_error_message() );
        }

        if ( ! isset( $response['body'] ) ) {
            wp_die( 'Token body is missing' );
        }

        // Extract the token response from token.
        $result = json_decode( $response['body'], true );

        // Check that the token response body was able to be parsed.
        if ( is_null( $result ) ) {
            wp_die( 'Invalid token' );
        }

        if ( isset( $result['error'] ) ) {
            $error = $result['error'];
            $error_description = $error;

            if ( isset( $result['error_description'] ) ) {
                $error_description = $result['error_description'];
            }

            echo $error . ': ' . $error_description;

            exit;
        }

        return $result;
    }

    

}

class Auth_State {
    public string $state;
    public string $return_url = '';
}
