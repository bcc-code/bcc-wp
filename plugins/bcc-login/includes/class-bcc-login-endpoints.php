<?php

class BCC_Login_Endpoints {

    private BCC_Login_Settings $settings;

    function __construct( BCC_Login_Settings $settings ) {
        $this->settings = $settings;

        add_action( 'init', array( $this, 'add_rewrite_rules' ) );
        add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
        add_filter( 'template_include', array( $this, 'include_endpoint' ), 99 );
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
     * Adds the `bcc-login` query var to use it in the `include_endpoint` method below.
     *
     * @param array $query_vars
     * @return array
     */
    function add_query_vars( $query_vars ) {
        $query_vars[] = 'bcc-login';
        return $query_vars;
    }

    /**
     * Load the requested endpoint.
     *
     * @param string $template
     * @return string
     */
    function include_endpoint( $template ) {
        switch ( get_query_var( 'bcc-login' ) ) {
            case 'access-token': return BCC_LOGIN_PATH . 'endpoints/access-token.php';
            case 'clear-cookies': return BCC_LOGIN_PATH . 'endpoints/clear-cookies.php';
            case 'id-token': return BCC_LOGIN_PATH . 'endpoints/id-token.php';
            case 'refresh-login': return BCC_LOGIN_PATH . 'endpoints/refresh-login.php';
            case 'logout': return BCC_LOGIN_PATH . 'endpoints/logout.php';
            case 'user-profile': return BCC_LOGIN_PATH . 'endpoints/user-profile.php';
            case 'invalidate-person-cache': return BCC_LOGIN_PATH . 'endpoints/invalidate-person-cache.php';
        }
        return $template;
    }
}
