<?php
/**
 * Plugin Name: BCC – Post Update Translations Notifier
 * Description: When the ACF field "translation_stage" changes to "translate", email addresses from the option "update_notification_emails" are notified. Includes admin settings page and logging.
 * Version: 1.5.6
 * Author: BCC IT
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once( 'includes/class-bcc-post-update-translations-notifier-updater.php');

class Post_Update_Translations_Notifier {

    /**
     * The plugin instance.
     */
    private static $instance = null;
    private $plugin_version = "1.5.6";
    private $plugin;
    private $plugin_slug;
    private $plugin_name = "BCC – Post Update Translations Notifier";

    private BCC_Post_Update_Translations_Notifier_Updater $_updater;

    const OPTION_FROM_EMAIL = 'from_email_translations_notifier';
    const OPTION_EMAILS = 'update_notification_emails';
    const OPTION_LOG = 'acf_translation_notifier_log';
    const LOG_LIMIT = 100; // keep last 100 entries
    const OPTION_CAP = 'manage_options';
    const LOGS_PER_PAGE = 20;

    public function __construct() {
        $this->plugin = plugin_basename( __FILE__ );
		$this->plugin_slug = plugin_basename( __DIR__ );

        $this->_updater = new BCC_Post_Update_Translations_Notifier_Updater( $this->plugin, $this->plugin_slug, $this->plugin_version, $this->plugin_name );

        add_filter( 'acf/update_value/name=translation_stage', array( $this, 'maybe_notify_on_translate' ), 10, 3 );

        // Admin settings
        add_action( 'admin_menu', array( $this, 'admin_menu' ) );
        add_action( 'admin_init', array( $this, 'admin_init' ) );

        // Handle clearing logs
        add_action( 'admin_post_acf_tn_clear_logs', array( $this, 'handle_clear_logs' ) );
    }

    /**
     * ACF save_value hook: called before ACF writes the new value.
     */
    public function maybe_notify_on_translate( $value, $post_id, $field ) {
        // Skip autosaves and revisions
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) ) {
            return $value;
        }

        if ( ! is_numeric( $post_id ) ) {
            return $value;
        }

        if ( $value !== 'translate' ) {
            return $value;
        }

        $old_value = get_field($field['name'], $post_id, false);
        if ( $old_value === 'translate' ) {
            return $value;
        }

        // Compose email
        $post = get_post( $post_id );
        $title = $post ? $post->post_title : sprintf( 'Post #%d', $post_id );
        $permalink = get_permalink( $post_id );

        $subject = "Translation update requested";
        $message_lines = array();
        $message_lines[] = "The content of the following post was updated and the editor requested the translation to be reviewed again.";
        $message_lines[] = "";
        $message_lines[] = "Post: " . $title;
        
        // Get the last revision author
        $revisions = wp_get_post_revisions( $post_id, array( 'numberposts' => 1 ) );
        if ( ! empty( $revisions ) ) {
            $last_revision = reset( $revisions );
            $author_name = get_the_author_meta( 'display_name', $last_revision->post_author );
            if ( $author_name ) {
                $message_lines[] = "Last edited by: " . $author_name;
            }
        }
        
        // Get target languages
        $target_languages = get_field( 'target_languages', $post_id );
        if ( $target_languages ) {
            if ( is_array( $target_languages ) ) {
                $message_lines[] = "Translation languages: " . implode( ', ', $target_languages );
            } else {
                $message_lines[] = "Translation languages: " . $target_languages;
            }
        }

        if ( $permalink ) {
            $message_lines[] = "URL: " . $permalink;
        }
        
        $message_lines[] = "";
        $message_lines[] = "---";
        $message_lines[] = "This is an automated notification which is triggered whenever \"Translation stage\" is changed to \"Translate\".";

        $message = implode( "\n", $message_lines );

        $from = trim( (string) get_option( self::OPTION_FROM_EMAIL ) );
        $headers = array();

        if ( $from !== '' ) {
            // Optional: basic validation — allow "Name <email>" or plain email
            if ( preg_match('/<([^>]+)>/', $from, $m) ) {
                if ( is_email( $m[1] ) ) {
                    $headers[] = 'From: ' . $from;
                }
            } elseif ( is_email( $from ) ) {
                $headers[] = 'From: ' . $from;
            }
        }

        // Get email recipients and use first as To, rest as Bcc
        $raw_emails = get_option( self::OPTION_EMAILS );
        $emails = $this->parse_emails( $raw_emails );
        
        if ( empty( $emails ) ) {
            // No valid emails configured, skip sending
            $this->add_log( array(
                'time' => current_time( 'mysql' ),
                'post_id' => $post_id,
                'title' => $title,
                'recipients' => array(),
                'status' => 'failed',
                'message' => 'No valid email addresses configured',
            ) );
            return $value;
        }

        $all_recipients = $emails; // Keep original list for logging
        $to = array_shift( $emails ); // First email becomes the To recipient
        
        if ( ! empty( $emails ) ) {
            $bcc = implode( ',', $emails );
            $headers[] = 'Bcc: ' . $bcc;
        }

        $sent = wp_mail( $to, $subject, $message, $headers );

        $this->add_log( array(
            'time' => current_time( 'mysql' ),
            'post_id' => $post_id,
            'title' => $title,
            'recipients' => $all_recipients,
            'status' => $sent ? 'sent' : 'failed',
            'message' => $sent ? 'Email queued/sent successfully' : 'wp_mail returned false',
        ) );

        return $value;
    }

    private function get_post_title( $post_id ) {
        $p = get_post( $post_id );
        return $p ? $p->post_title : '';
    }

    private function parse_emails( $raw ) {
        $emails = array();

        if ( is_array( $raw ) ) {
            $candidate = $raw;
        } else {
            $candidate = preg_split( '/[;, \r\n]+/', $raw );
        }

        if ( ! empty( $candidate ) ) {
            foreach ( $candidate as $c ) {
                $c = trim( $c );
                if ( ! empty( $c ) && is_email( $c ) ) {
                    $emails[] = $c;
                }
            }
        }

        // unique
        $emails = array_values( array_unique( $emails ) );

        return $emails;
    }

    private function add_log( $entry ) {
        $logs = get_option( self::OPTION_LOG, array() );
        if ( ! is_array( $logs ) ) {
            $logs = array();
        }

        // Prepend new entry so newest-first is natural
        array_unshift( $logs, $entry );

        // Trim to last LOG_LIMIT entries (since we prepend, slice the first LOG_LIMIT)
        if ( count( $logs ) > self::LOG_LIMIT ) {
            $logs = array_slice( $logs, 0, self::LOG_LIMIT );
        }

        update_option( self::OPTION_LOG, $logs );
    }

    public function admin_menu() {
        add_options_page(
            'Translation Notifications',
            'Translation Notifications',
            self::OPTION_CAP,
            'post-update-translations-notifier',
            array( $this, 'settings_page' )
        );
    }

    public function admin_init() {
        register_setting( 'post_update_translations_notifier_group', self::OPTION_FROM_EMAIL );
        register_setting( 'post_update_translations_notifier_group', self::OPTION_EMAILS, array( $this, 'sanitize_emails_callback' ) );

        add_settings_section(
            'acf_tn_main_section',
            'Notification Settings',
            function() { echo '<p>Configure who receives a notification when a post is moved to the translation stage.</p>'; },
            'post-update-translations-notifier'
        );

        add_settings_field(
            self::OPTION_FROM_EMAIL,
            'From email',
            array( $this, 'from_email_field_html' ),
            'post-update-translations-notifier',
            'acf_tn_main_section'
        );

        add_settings_field(
            self::OPTION_EMAILS,
            'Notification emails',
            array( $this, 'emails_field_html' ),
            'post-update-translations-notifier',
            'acf_tn_main_section'
        );
    }

    public function sanitize_emails_callback( $input ) {
        if ( is_array( $input ) ) {
            $raw = $input;
        } else {
            $raw = trim( $input );
        }

        // Keep as string; validation will happen when parsing
        return $raw;
    }

    public function from_email_field_html() {
        $val = (string) get_option( self::OPTION_FROM_EMAIL, '' );

        printf(
            '<input type="text" name="%s" value="%s" class="large-text" />',
            esc_attr( self::OPTION_FROM_EMAIL ),
            esc_attr( $val )
        );

        echo '<p class="description">Example: BCC &lt;noreply@bcc.no&gt;</p>';
    }

    public function emails_field_html() {
        $val = get_option( self::OPTION_EMAILS );
        if ( is_array( $val ) ) {
            $display = implode( ",\n", $val );
        } else {
            $display = $val;
        }

        printf(
            '<textarea name="%s" rows="6" cols="60" class="large-text code">%s</textarea>',
            esc_attr( self::OPTION_EMAILS ),
            esc_textarea( $display )
        );

        echo '<p class="description">Enter one or more email addresses separated by comma, semicolon, or new line. The recipients will be sent as Bcc.</p>';
    }

    public function settings_page() {
        if ( ! current_user_can( self::OPTION_CAP ) ) {
            return;
        }

        // Handle possible settings messages
        ?>
        <div class="wrap">
            <h1>Translation Notifications</h1>
            <form method="post" action="options.php">
                <?php
                    settings_fields( 'post_update_translations_notifier_group' );
                    do_settings_sections( 'post-update-translations-notifier' );
                    submit_button();
                ?>
            </form>

            <h2>Notification log</h2>
            <p>Shows the most recent entries (newest first).</p>
            <?php $this->render_log_table(); ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1em;">
                <?php wp_nonce_field( 'acf_tn_clear_logs' ); ?>
                <input type="hidden" name="action" value="acf_tn_clear_logs">
                <?php submit_button( 'Clear log', 'delete' ); ?>
            </form>
        </div>
        <?php
    }

    private function render_log_table() {
        $logs = get_option( self::OPTION_LOG, array() );
        if ( ! is_array( $logs ) || empty( $logs ) ) {
            echo '<p>No log entries yet.</p>';
            return;
        }

        // Newest-first already ensured when adding logs. Implement pagination.
        $total = count( $logs );
        $per_page = self::LOGS_PER_PAGE;
        $total_pages = (int) ceil( $total / $per_page );

        $paged = isset( $_GET['acf_tn_page'] ) ? intval( $_GET['acf_tn_page'] ) : 1;
        if ( $paged < 1 ) { $paged = 1; }
        if ( $paged > $total_pages ) { $paged = $total_pages; }

        $offset = ( $paged - 1 ) * $per_page;
        $page_logs = array_slice( $logs, $offset, $per_page );

        echo '<table class="widefat fixed">';
        echo '<thead><tr><th width="150">Time</th><th>Post</th><th>Recipients</th><th>Status</th><th>Message</th></tr></thead>';
        echo '<tbody>';

        foreach ( $page_logs as $entry ) {
            $time = isset( $entry['time'] ) ? esc_html( $entry['time'] ) : '';
            $post_id = isset( $entry['post_id'] ) ? intval( $entry['post_id'] ) : 0;
            $title = isset( $entry['title'] ) ? esc_html( $entry['title'] ) : '';
            $recipients = isset( $entry['recipients'] ) ? $entry['recipients'] : array();
            if ( is_array( $recipients ) ) {
                $recipients = implode( ', ', array_map( 'esc_html', $recipients ) );
            } else {
                $recipients = esc_html( $recipients );
            }
            $status = isset( $entry['status'] ) ? esc_html( $entry['status'] ) : '';
            $message = isset( $entry['message'] ) ? esc_html( $entry['message'] ) : '';

            $post_link = $post_id ? sprintf( '<a href="%s" target="_blank">%s</a>', esc_url( get_edit_post_link( $post_id ) ), $title ? $title : ( 'Post #' . $post_id ) ) : esc_html( $title );

            echo '<tr>';
            echo '<td>' . $time . '</td>';
            echo '<td>' . $post_link . '</td>';
            echo '<td>' . $recipients . '</td>';
            echo '<td>' . $status . '</td>';
            echo '<td>' . $message . '</td>';
            echo '</tr>';
        }

        echo '</tbody>';
        echo '</table>';

        // Pagination controls
        if ( $total_pages > 1 ) {
            $base_url = remove_query_arg( 'acf_tn_page' );
            echo '<div style="margin-top:12px;">';
            echo '<strong>Page:</strong> ';

            // prev link
            if ( $paged > 1 ) {
                $prev_url = add_query_arg( 'acf_tn_page', $paged - 1 );
                echo '<a href="' . esc_url( $prev_url ) . '">&laquo; Prev</a> ';
            }

            // numeric links (limit the range to avoid too many links)
            $start = max( 1, $paged - 3 );
            $end = min( $total_pages, $paged + 3 );

            if ( $start > 1 ) {
                $first_url = add_query_arg( 'acf_tn_page', 1 );
                echo '<a href="' . esc_url( $first_url ) . '">1</a> ... ';
            }

            for ( $i = $start; $i <= $end; $i++ ) {
                if ( $i === $paged ) {
                    echo '<strong>' . $i . '</strong> ';
                } else {
                    $url = add_query_arg( 'acf_tn_page', $i );
                    echo '<a href="' . esc_url( $url ) . '">' . $i . '</a> ';
                }
            }

            if ( $end < $total_pages ) {
                $last_url = add_query_arg( 'acf_tn_page', $total_pages );
                echo '... <a href="' . esc_url( $last_url ) . '">' . $total_pages . '</a> ';
            }

            // next link
            if ( $paged < $total_pages ) {
                $next_url = add_query_arg( 'acf_tn_page', $paged + 1 );
                echo '<a href="' . esc_url( $next_url ) . '">Next &raquo;</a>';
            }

            echo '</div>';
        }
    }

    public function handle_clear_logs() {
        if ( ! current_user_can( self::OPTION_CAP ) ) {
            wp_die( 'Insufficient permissions' );
        }

        check_admin_referer( 'acf_tn_clear_logs' );

        update_option( self::OPTION_LOG, array() );

        wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'options-general.php?page=post-update-translations-notifier' ) );
        exit;
    }

    /**
     * Creates and returns a single instance of this class.
     *
     * @return Post_Update_Translations_Notifier
     */
    static function get_instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new Post_Update_Translations_Notifier();
        }
        return self::$instance;
    }

}

function bcc_post_update_translations_notifier() {
    return Post_Update_Translations_Notifier::get_instance();
}

bcc_post_update_translations_notifier();

// End of file
