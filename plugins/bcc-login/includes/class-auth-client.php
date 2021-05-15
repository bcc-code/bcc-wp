<?php

class Auth_Client {

    private Auth_Settings $_settings;
    private $STATE_TIME_LIMIT = 180;

    function __construct( Auth_Settings $settings) {
        $this->_settings = $settings;
        add_action( 'parse_request', array( $this, 'on_parse_request' ) );
    }

    function start_login() {
        $state = $this->create_authentication_state();
        $auth_url = $this->get_authorization_url($state);
        wp_redirect( $auth_url );
		exit;
    }

    private function create_authentication_state() : Auth_State{
        
        // New state w/ timestamp.
        $obj_state = new Auth_State();
        $obj_state->state = md5( mt_rand() . microtime( true ) );
        $obj_state->return_url = $this->get_current_url();
        set_transient( 'auth-state--' . $obj_state->state, $obj_state, $this->STATE_TIME_LIMIT );

        return $obj_state;
        
    }

    function on_parse_request( $query ){
        $current_url = $this->get_current_url();
        if (strpos($current_url, $this->_settings->redirect_uri)) {
           $this->complete_login();
           exit;
        }
    }

    private function complete_login() {
        $code = $_GET['code'];
        $state = $_GET['state'];
        if (!empty($_GET['error'])) {
            echo $_GET['error'];
            exit;
        }

        $obj_state = get_transient( 'auth-state--' . $state );

        if (is_object($obj_state)) {
            $tokens = $this->request_tokens( $code );
            $id_token = $tokens['id_token'];
            $access_token = $tokens['access_token'];

            $user_claims = $this->get_user_claims( $id_token );
            $this->login_user($user_claims, $access_token, $id_token);

            wp_redirect($obj_state->return_url);

        } else {
            wp_redirect(home_url());
        }
    }

    private function login_user( $user_claims, $access_token, $id_token  ) {

        $person_id = $user_claims["https://login.bcc.no/claims/personId"];
        $email = $user_claims['email'];

		$user = $this->get_user_by_identity( $person_id, $email);		
		if ( ! $user ) {
			if ( $user_claims['https://login.bcc.no/claims/hasMembership'] == false ) {
                echo 'Invalid user.';
                exit;
			}
			if ( $this->_settings->create_missing_users ) {
				$user = $this->create_new_user( $person_id, $email, $user_claims );
			 } else {
				$user = $this->get_common_login($user_claims);
			}
		} else {
			//// Allow plugins / themes to take action using current claims on existing user (e.g. update role).
			//// do_action( 'openid-connect-generic-update-user-using-current-claim', $user, $user_claim );
        }
        
        if ( ! is_a( $user, 'WP_User' ) || ! $user->exists() ) {
            echo 'User does not exist.';
            exit;
        }

		// Login the found / created user.
        $expiration = (int)$user_claims['exp'];      
		$manager = WP_Session_Tokens::get_instance( $user->ID );
		$token = $manager->create( $expiration );

		// Save access token to session
        $this->save_tokens( $expiration, $access_token, $id_token );

		// you did great, have a cookie!
		wp_set_auth_cookie( $user->ID, false, '', $token );
		do_action( 'wp_login', $user->user_login, $user );

    }
    
    function save_tokens( $expiration, $access_token, $id_token ) {
        if (!empty($access_token)){

			$token_id = uniqid ('',true);
			setcookie ( 'oidc_token_id', $token_id, $expiration, '/' , '', true, true);

            if (!empty($person_id)) {
                $timeout = ((int)$expiration) - time();
                set_transient('oidc_access_token_' . $token_id, $access_token, $timeout);
                $_SESSION['oidc_access_token'] = $access_token;
                if (!empty($id_token)) {
                  set_transient('oidc_id_token' . $token_id, $id_token, $timeout);
                  $_SESSION['oidc_id_token'] = $id_token;
                }
          } else {
			  delete_transient('oidc_access_token_' . $token_id);
              $_SESSION['oidc_access_token'] = '';
              $_SESSION['oidc_id_token'] = '';
          }
       }
        
    }

    function create_new_user( $person_id, $email, $user_claims ) {

		// Default username & email to the subject identity.
		$username       = $person_id;
		$email          = $email;
		$nickname       = $user_claims['given_name'];
		$displayname    = $user_claims['name'];
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
		$user = get_user_by( 'id', $uid );
		return $user;
    }
    
    function get_common_login($user_claim) {
			if ( $user_claim[$this->_settings->local_organization_claim_type] == $this->_settings->local_organization_name ) {
				return BCC_Login_Users::get_member();
			}

			return BCC_Login_Users::get_subscriber();
    }


    function get_user_by_identity( $person_id, $email ){


		// 1. Lookup by person_id in user login field
		if (!empty($person_id)) {
			$user_query = new WP_User_Query( array(
				'search' => $person_id,
				'search_columns' => array( 'user_login' )
			) );

			// If we found an existing users, grab the first one returned.
			if ( $user_query->get_total() > 0 ) {
				$users = $user_query->get_results();
				return $users[0];
			}
		}

		// 2. Lookup by email
		if (!empty($email)) {
			$user_query = new WP_User_Query( array(
				'search' => $email,
				'search_columns' => array( 'user_email', 'user_login' )
			) );

			// If we found an existing users, grab the first one returned.
			if ( $user_query->get_total() > 0 ) {
				$users = $user_query->get_results();
				return $users[0];
			}
		}

		return false;
	}

    private function get_full_redirect_url() {
        $base_url = home_url();
        $redirect_uri = trim(home_url(), '/') . '/' . ltrim($this->_settings->redirect_uri,'/');
        return $redirect_uri;
    }

    private function get_current_url() {
        global $wp;
        return add_query_arg( $_SERVER['QUERY_STRING'], '', home_url( $wp->request ) );
        //$current_url = add_query_arg( $wp->query_vars, home_url( $wp->request ) );
        //return $current_url;
    }

    private function get_authorization_url(Auth_State $state) {
		$url = sprintf(
			'%1$s%2$sresponse_type=code&scope=%3$s&client_id=%4$s&state=%5$s&redirect_uri=%6$s',
			$this->_settings->authorization_endpoint,
			'?',
			rawurlencode( $this->_settings->scope ),
			rawurlencode( $this->_settings->client_id ),
			$state->state,
			rawurlencode( $this->get_full_redirect_url() )
        );
        return $url;
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
        
        if ( ! isset( $response['body'] ) ) {
            echo 'Token body is missing';
            exit;
		}

		// Extract the token response from token.
		$result = json_decode( $response['body'], true );

		// Check that the token response body was able to be parsed.
		if ( is_null( $result ) ) {
			echo 'Invalid token';
            exit;
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

    function get_user_claims( $id_token ) {

        // Check if id token exists
		if ( ! isset( $id_token ) ) {
			return array();
		}

		// Break apart the id_token in the response for decoding.
		$tmp = explode( '.', $id_token );

		if ( ! isset( $tmp[1] ) ) {
			return array();
		}

		// Extract the id_token's claims from the token.
		$claims = json_decode(
			base64_decode(
				str_replace( // Because token is encoded in base64 URL (and not just base64).
					array( '-', '_' ),
					array( '+', '/' ),
					$tmp[1]
				)
			),
			true
		);
		return $claims;

    }

}

class Auth_State {
    public string $state;
    public string $return_url = '';
}