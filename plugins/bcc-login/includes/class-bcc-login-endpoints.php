<?php

class BCC_Login_Endpoints {

    /**
     * The endpoints served under the `bcc-login` query var, each from endpoints/<name>.php.
     * 'BCC_Login_Visibility::on_template_redirect()' lets these through without login,
     * so add only endpoints that are safe for anyone to call.
     */
    public const ENDPOINTS = array(
        'access-token',
        'clear-cookies',
        'id-token',
        'refresh-login',
        'logout',
        'user-profile',
        'invalidate-person-cache',
    );

    private BCC_Login_Settings $settings;

    function __construct( BCC_Login_Settings $settings ) {
        $this->settings = $settings;

        add_action( 'init', array( $this, 'add_rewrite_rules' ) );
        add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
        add_action( 'template_redirect', array( $this, 'serve_endpoint' ), 1 );
    }

    /**
     * Adds rewrite rules for prettier URLs to the enpoints.
     */
    function add_rewrite_rules() {
        add_rewrite_rule(
            '^bcc-login/([a-z-]+)$',
            'index.php?bcc-login=$matches[1]',
            'top'
        );
    }

    /**
     * Adds the `bcc-login` query var to use it in the `serve_endpoint` method below.
     *
     * @param array $query_vars
     * @return array
     */
    function add_query_vars( $query_vars ) {
        $query_vars[] = 'bcc-login';
        return $query_vars;
    }

    /**
     * Serves the requested endpoint as the whole response.
     *
     * Runs right after 'BCC_Login_Visibility::on_template_redirect()' (priority 0),
     * which lets the endpoints through, and before WordPress renders anything else
     * the URL asks for. Swapping the template in was not enough: WordPress renders a
     * feed before it picks a template, so '/<restricted post>/feed/?bcc-login=logout'
     * served the post's content.
     *
     * @return void
     */
    function serve_endpoint() {
        $endpoint = get_query_var( 'bcc-login' );

        if ( ! in_array( $endpoint, self::ENDPOINTS, true ) ) {
            return;
        }

        // Answer HEAD requests the way WordPress does, without running the endpoint:
        // some of them log out or clear cookies.
        if ( 'HEAD' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && apply_filters( 'exit_on_http_head', true ) ) {
            exit;
        }

        include BCC_LOGIN_PATH . "endpoints/{$endpoint}.php";
        exit;
    }
}
