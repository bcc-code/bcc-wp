<?php
/*
 * Adapted from: https://github.com/rudrastyh/misha-update-checker/
 * Ref: https://rudrastyh.com/wordpress/self-hosted-plugin-update.html
 */

/**/

defined( 'ABSPATH' ) || exit;

class BCC_Attachments_RSS_Feed_Updater {

	public $plugin_slug;
	public $version;
	public $cache_key;
	public $cache_allowed;
	public $plugin;
	public $name;

	public function __construct( $plugin, $slug, $version, $name ) {

		$this->name = $name;
		$this->plugin = $plugin;
		$this->plugin_slug = $slug;
		$this->version = $version;
		$this->cache_key = $slug . '_updater';
		$this->cache_allowed = true;

		add_filter( 'plugins_api', array( $this, 'info' ), 20, 3 );
		add_filter( 'site_transient_update_plugins', array( $this, 'update' ) );
		add_action( 'upgrader_process_complete', array( $this, 'purge' ), 10, 2 );
		add_filter( 'plugin_row_meta', array( $this, 'plugin_row_meta' ), 10, 2 );
		add_action( 'load-plugins.php', array( $this, 'maybe_force_check' ) );
		add_action( 'admin_notices', array( $this, 'update_checked_notice' ) );

	}

	public function request(){

		// A recent failed/empty check is cached briefly so a GitHub outage
		// doesn't turn into a check-on-every-request loop.
		if( get_transient( $this->cache_key . '_failed' ) ) {
			return false;
		}

		$remote = get_transient( $this->cache_key );

		if( false === $remote || ! $this->cache_allowed ) {

			$remote = wp_remote_get(
				'https://raw.githubusercontent.com/bcc-code/bcc-wp/master/plugins/' . $this->plugin_slug . '/package.json',
				array(
					'timeout' => 10,
					'headers' => array(
						'Accept' => 'application/json'
					)
				)
			);

			if(
				is_wp_error( $remote )
				|| 200 !== wp_remote_retrieve_response_code( $remote )
				|| empty( wp_remote_retrieve_body( $remote ) )
			) {
				set_transient( $this->cache_key . '_failed', 1, HOUR_IN_SECONDS );
				return false;
			}

			// Check GitHub at most twice a day; the "Check for updates" row
			// link below bypasses this by clearing the transient on demand.
			set_transient( $this->cache_key, $remote, 12 * HOUR_IN_SECONDS );

		}

		$remote = json_decode( wp_remote_retrieve_body( $remote ) );

		return $remote;

	}


	function info( $res, $action, $args ) {

		// print_r( $action );
		// print_r( $args );

		// do nothing if you're not getting plugin information right now
		if( 'plugin_information' !== $action ) {
			return false;
		}

		// do nothing if it is not our plugin
		if( $this->plugin_slug !== $args->slug ) {
			return false;
		}

		// get updates
		$remote = $this->request();

		if( ! $remote ) {
			return false;
		}

		$res = new stdClass();

		$res->name = $this->name; //$remote->name;
		$res->slug = $remote->slug;
		$res->version = $remote->version;
		$res->tested =  "$remote->tested";
		$res->requires = $remote->requires;
		$res->author = $remote->author;
		$res->author_profile = "https://github.com/bcc-code"; //$remote->author_profile;
		$res->download_link = "https://github.com/bcc-code/bcc-wp/releases/download/" . $this->plugin_slug . "-v" . $remote->version . "/" . $this->plugin_slug . ".zip"; //$remote->download_url;
		$res->trunk = $res->download_link; // $remote->download_url;
		$res->requires_php = $remote->requires_php;
		$res->last_updated = $remote->last_updated;

		$res->sections = array(
			'description' =>  $remote->sections->description,
			'installation' => $remote->sections->installation,
			'changelog' => $remote->sections->changelog
		);

		if( ! empty( $remote->banners ) ) {
			$res->banners = array(
				'low' => $remote->banners->low,
				'high' => $remote->banners->high
			);
		}

		return $res;

	}

	public function update( $transient ) {

		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$remote = $this->request();

		$res = new stdClass();
		$res->slug = $this->plugin_slug;
		$res->plugin = $this->plugin; 
		$res->new_version = $this->version;
		$res->tested = null;
		$res->package = "https://github.com/bcc-code/bcc-wp/releases/download/" . $this->plugin_slug . "-v" . $this->version . "/" . $this->plugin_slug . ".zip"; //$remote->download_url;

		if(
			$remote
			&& version_compare( $this->version, $remote->version, '<' )
			&& version_compare( $remote->requires, get_bloginfo( 'version' ), '<' )
			&& version_compare( $remote->requires_php, PHP_VERSION, '<' )
		) {
			$res->new_version = $remote->version;
			$res->tested = $remote->tested;
			$res->package = "https://github.com/bcc-code/bcc-wp/releases/download/" . $this->plugin_slug . "-v" . $remote->version . "/" . $this->plugin_slug . ".zip"; //$remote->download_url;
	
			$transient->response[ $res->plugin ] = $res;
		} else {
			$transient->no_update[ $res->plugin ] = $res;
		}
		return $transient;

	}

	public function purge( $upgrader_object, $options ){

		if (
			$this->cache_allowed
			&& 'update' === $options['action']
			&& 'plugin' === $options[ 'type' ]
		) {
			// just clean the cache when new plugin version is installed
			delete_transient( $this->cache_key );
			delete_transient( $this->cache_key . '_failed' );
		}

	}

	public function plugin_row_meta( $links, $file ) {

		if ( $file !== $this->plugin || ! current_user_can( 'update_plugins' ) ) {
			return $links;
		}

		$url = wp_nonce_url(
			add_query_arg( 'bcc-check-update', $this->plugin_slug, self_admin_url( 'plugins.php' ) ),
			'bcc-check-update-' . $this->plugin_slug
		);

		$links[] = '<a href="' . esc_url( $url ) . '">Check for updates</a>';

		return $links;

	}

	public function maybe_force_check() {

		$requested = isset( $_GET['bcc-check-update'] ) ? sanitize_text_field( wp_unslash( $_GET['bcc-check-update'] ) ) : '';

		if ( $this->plugin_slug !== $requested || ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		check_admin_referer( 'bcc-check-update-' . $this->plugin_slug );

		delete_transient( $this->cache_key );
		delete_transient( $this->cache_key . '_failed' );
		delete_site_transient( 'update_plugins' );

		wp_safe_redirect( add_query_arg( 'bcc-update-checked', $this->plugin_slug, self_admin_url( 'plugins.php' ) ) );
		exit;

	}

	public function update_checked_notice() {

		$checked = isset( $_GET['bcc-update-checked'] ) ? sanitize_text_field( wp_unslash( $_GET['bcc-update-checked'] ) ) : '';

		if ( $this->plugin_slug !== $checked ) {
			return;
		}

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( 'Checked GitHub for a new version of %s.', $this->name ) ) . '</p></div>';

	}

}
