<?php
/**
 * Plugin Name: BCC – Keep translated posts' status same as original
 * Description: Ensures translated posts imported from Phrase (and any WPML saves) inherit the source post's status (e.g. draft, publish). This is done to avoid the use-case when an email is sent to post groups in e.g. English before Norwegian is ready to be published. In addition, this plugin creates a settings page where admins can see which translated posts have different post statuses than the original posts.
 * Author: BCC IT
 * Version: 1.6.5
 */

if ( !defined('ABSPATH') ) { exit; }

require_once( 'includes/class-bcc-keep-translated-posts-status-same-as-original-updater.php' );

class BCC_Keep_Translated_Posts_Status_Same_As_Original {

    /**
     * The plugin instance.
     */
    private static $instance = null;
    private $plugin_version = "1.6.5";
    private $plugin;
    private $plugin_slug;
    private $plugin_name = "BCC – Keep translated posts' status same as original";

    private BCC_Keep_Translated_Posts_Status_Same_As_Original_Updater $_updater;

    /**
     * Initialize the plugin.
     */
    private function __construct() {
        $this->plugin = plugin_basename( __FILE__ );
		$this->plugin_slug = plugin_basename( __DIR__ );
        
        $this->_updater = new BCC_Keep_Translated_Posts_Status_Same_As_Original_Updater( $this->plugin, $this->plugin_slug, $this->plugin_version, $this->plugin_name );

        add_filter( 'wp_insert_post_data', array( $this, 'bcc_filter_on_wp_insert_post_data' ), 20, 2 );
        add_action( 'wpml_pro_translation_completed', array( $this, 'bcc_action_wpml_translation_completed' ), 20 );
        add_action( 'admin_menu', array( $this, 'bcc_post_status_mismatch_settings_page' ) );
        add_filter( 'plugin_action_links_' . $this->plugin, array( $this, 'add_mismatches_link' ) );
    }

    /**
     * 1) Guardrail at save-time: before WordPress writes to DB, make translation status match its source.
     */
    function bcc_filter_on_wp_insert_post_data ( $data, $postarr ) {
        if ( strpos( $_SERVER['REQUEST_URI'], '/wp-json/memsource/v1/connector/translate/' ) !== false ) {
            // This is a Memsource translation request
            preg_match( '/\/wp-json\/memsource\/v1\/connector\/translate\/(\d+)\?token=/', $_SERVER['REQUEST_URI'], $matches );
            
            if ( ! isset($matches[1]) )
                return $data;

            $source_post_id = (int) $matches[1];
            if ( $source_post_id <= 0 )
                return $data;

            $source_post_status = get_post_status( $source_post_id );
            if ( ! $source_post_status ) {
                return $data;
            }

            error_log( 'bcc_filter_on_wp_insert_post_data called for post ID ' . $source_post_id . '" with current status: ' . $data['post_status'] . ' and new status: ' . $source_post_status );

            $data['post_status'] = $source_post_status;

            return $data;
        }
        
        // Only operate on posts (incl. CPTs) that already have an ID (updates) — new posts will be handled by the WPML hook below.
        $maybe_id = isset($postarr['ID']) ? (int) $postarr['ID'] : 0;
        if ( $maybe_id <= 0 ) {
            return $data;
        }

        // Skip autosaves/revisions
        if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) { return $data; }
        if ( wp_is_post_revision( $maybe_id ) ) { return $data; }

        $source = $this->bcc_wpml_get_source_post( $maybe_id );
        if ( ! $source ) {
            // This is the source itself, or we can't find it — nothing to sync.
            return $data;
        }

        $source_status = $source->post_status; // 'draft', 'publish', 'pending', 'private', etc.
        if ( ! empty($source_status) && $data['post_status'] !== $source_status
            && $data['post_status'] != 'trash' && $source_status != 'trash' )
        {
            $data['post_status'] = $source_status;
        }

        return $data;
    }

    /**
     * 2) When WPML TM (Phrase) finishes a translation and creates/updates the translated post,
     *    force status to match the source.
     *
     * Hook signature: do_action( 'wpml_pro_translation_completed', $post_id, $fields, $job );
     * This fires on imports from translation services like Phrase.
     */
    function bcc_action_wpml_translation_completed ( $translated_post_id ) {
        static $in_progress = [];

        $translated_post_id = (int) $translated_post_id;
        if ( $translated_post_id <= 0 ) { return; }

        // Prevent loops if wp_update_post triggers the same hook chain
        if ( isset($in_progress[$translated_post_id]) ) { return; }
        $in_progress[$translated_post_id] = true;

        $source = $this->bcc_wpml_get_source_post( $translated_post_id );
        if ( $source ) {
            $source_status = $source->post_status;
            $translated    = get_post( $translated_post_id );

            if ( $translated && $translated->post_status !== $source_status ) {
                // Update translated post to match source status
                wp_update_post(array(
                    'ID'          => $translated_post_id,
                    'post_status' => $source_status,
                ));
            }
        }

        unset($in_progress[$translated_post_id]);
    }

    /**
     * Admin menu.
     */
    function bcc_post_status_mismatch_settings_page() {
        add_options_page(
            'Post status mismatch',
            'Post status mismatch',
            'manage_options',
            'bcc-post-status-mismatch',
            array( $this, 'bcc_post_status_mismatch_page' )
        );
    }

    /**
     * Add settings link to plugin actions on plugins page.
     */
    function add_mismatches_link( $links ) {
        $settings_link = '<a href="' . admin_url( 'options-general.php?page=bcc-post-status-mismatch' ) . '">' . __( 'Mismatches' ) . '</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    /**
     * Helper: get source post for a given translated post ID.
     */
    function bcc_wpml_get_source_post( $post_id ) {
        if ( ! $post_id ) {
            return null;
        }

        // WPML default language (source language from settings)
        $wpml_default_lang = apply_filters( 'wpml_default_language', null );

        $translation = get_post($post_id);
        $post_type = $translation->post_type;

        $has_translations = apply_filters('wpml_element_has_translations', '', $post_id, $post_type);
        if (!$has_translations) return null;

        $trid = apply_filters('wpml_element_trid', NULL, $post_id, 'post_' . $post_type);
        $translations = apply_filters('wpml_get_element_translations', NULL, $trid, 'post_' . $post_type);

        $source_post_id = 0;

        foreach ($translations as $lang => $details) {
            if ($lang == $wpml_default_lang && $details->original == '1') {
                $source_post_id = $details->element_id;
                break;
            }
        }
        
        return $source_post_id
            && $source_post_id != $post_id
            ? get_post($source_post_id)
            : null;
    }


    /**
     * Renderer for the settings page.
     */
    function bcc_post_status_mismatch_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'bcc' ) );
        }

        if ( ! defined('ICL_SITEPRESS_VERSION') ) {
            wp_die( esc_html__( 'WPML is not installed.', 'bcc' ) );
        }

        // Config: pagination
        $paged = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;

        // Build the query.
        $query_args = array(
            'post_type'        => array('post', 'page', 'targeted-articles', 'important-dates', 'ledige-stillinger'),
            'post_status'      => 'any',
            'fields'           => 'ids',
            'orderby'          => 'ID',
            'order'            => 'DESC',
            'posts_per_page'   => -1,
            'suppress_filters' => false,
        );
        $query = new WP_Query( $query_args );

        $rows = array();

        // WPML default language (source language from settings)
        $wpml_default_lang = apply_filters( 'wpml_default_language', null );

        if ( $query->have_posts() ) {
            while ( $query->have_posts() ) { $query->the_post();
                
                $source = get_post(get_the_ID());
                $post_id = $source->ID;
                $post_type = $source->post_type;

                $has_translations = apply_filters('wpml_element_has_translations', '', $post_id, $post_type);
                if (!$has_translations) continue;

                $trid = apply_filters('wpml_element_trid', NULL, $post_id, 'post_' . $post_type);
                $translations = apply_filters('wpml_get_element_translations', NULL, $trid, 'post_' . $post_type);

                foreach ($translations as $lang => $details) {
                    if ($details->original == '1') continue;

                    $translation = get_post($details->element_id);
                    
                    if ( $translation && $source && $translation->post_status !== $source->post_status ) {
                        $rows[] = array(
                            'ptype'        => $post_type,
                            'title'        => get_the_title( $translation->ID ),
                            'edit_url'     => get_edit_post_link( $translation->ID, '' ),
                            'src_title'    => get_the_title( $source->ID ),
                            'src_edit_url' => get_edit_post_link( $source->ID, '' ),
                            'src_status'   => $source->post_status,
                            'tr_status'    => $translation->post_status,
                            'lang_src'     => $wpml_default_lang,
                            'lang_trg'     => $lang,
                        );
                    }
                }
            }
        }

        wp_reset_postdata();

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Post Translation - Status mismatch', 'bcc' ); ?></h1>
            <p><?php esc_html_e( 'Showing translated posts where the publish status differs from their original/source post.', 'bcc' ); ?></p>

            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:12%"><?php esc_html_e( 'Post Type', 'bcc' ); ?></th>
                        <th><?php esc_html_e( 'Source Title', 'bcc' ); ?></th>
                        <th style="width:14%"><?php esc_html_e( 'Source Status', 'bcc' ); ?></th>
                        <th style="width:12%"><?php esc_html_e( 'Lang', 'bcc' ); ?></th>
                        <th style="width:16%"><?php esc_html_e( 'Translated Status', 'bcc' ); ?></th>
                        <th><?php esc_html_e( 'Translated Title', 'bcc' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $rows ) ) : ?>
                        <tr>
                            <td colspan="6"><?php esc_html_e( 'No mismatches found in this batch.', 'bcc' ); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $rows as $r ) : ?>
                            <tr>
                                <td><?php echo esc_html( $r['ptype'] ); ?></td>
                                <td>
                                    <a href="<?php echo esc_url( $r['src_edit_url'] ); ?>">
                                        <?php echo esc_html( $r['src_title'] ); ?>
                                    </a>
                                </td>
                                <td><?php echo $r['src_status']; ?></td>
                                <td><?php echo esc_html( strtoupper( $r['lang_src'] ) . ' → ' . strtoupper( $r['lang_trg'] ) ); ?></td>
                                <td><?php echo $r['tr_status']; ?></td>
                                <td>
                                    <a href="<?php echo esc_url( $r['edit_url'] ); ?>">
                                        <?php echo esc_html( $r['title'] ); ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <p style="margin-top:10px;color:#666;">
                <?php esc_html_e( 'Tip: Click any title to edit the post.', 'bcc' ); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Creates and returns a single instance of this class.
     *
     * @return BCC_Keep_Translated_Posts_Status_Same_As_Original
     */
    static function get_instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new BCC_Keep_Translated_Posts_Status_Same_As_Original();
        }
        return self::$instance;
    }
}

function bcc_keep_translated_posts_status_same_as_original() {
    return BCC_Keep_Translated_Posts_Status_Same_As_Original::get_instance();
}

bcc_keep_translated_posts_status_same_as_original();