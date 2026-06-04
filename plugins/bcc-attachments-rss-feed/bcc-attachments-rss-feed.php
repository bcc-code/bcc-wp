<?php
/**
 * Plugin Name: BCC – Attachments RSS Feed
 * Description: Registers a paginated RSS2 feed for media attachments at /feed/attachments. Each item maps the attachment's alt text + caption to the <description> and the attachment's description (post_content) + searchwp_content meta to <content:encoded>. Only attachments that have at least one of those fields are included.
 * Author: BCC IT
 * Version: 1.0.2
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once( 'includes/class-bcc-attachments-rss-feed-updater.php' );

class BCC_Attachments_RSS_Feed {

	/**
	 * The plugin instance.
	 */
	private static $instance = null;
	private $plugin_version = "1.0.2";
	private $plugin;
	private $plugin_slug;
	private $plugin_name = "BCC – Attachments RSS Feed";

	/**
	 * Feed slug, e.g. /feed/attachments
	 */
	const FEED_SLUG = 'attachments';

	/**
	 * Number of items per feed page.
	 */
	const PER_PAGE = 50;

	private BCC_Attachments_RSS_Feed_Updater $_updater;

	/**
	 * Initialize the plugin.
	 */
	private function __construct() {
		$this->plugin      = plugin_basename( __FILE__ );
		$this->plugin_slug = plugin_basename( __DIR__ );

		$this->_updater = new BCC_Attachments_RSS_Feed_Updater( $this->plugin, $this->plugin_slug, $this->plugin_version, $this->plugin_name );

		add_action( 'init', array( $this, 'register_feed' ) );

		register_activation_hook( __FILE__, array( $this, 'on_activation' ) );
		register_deactivation_hook( __FILE__, array( $this, 'on_deactivation' ) );
	}

	/**
	 * Register the custom attachments feed.
	 */
	public function register_feed() {
		add_feed( self::FEED_SLUG, array( $this, 'render_feed' ) );
	}

	/**
	 * Flush rewrite rules on activation so the feed endpoint works immediately.
	 */
	public function on_activation() {
		$this->register_feed();
		flush_rewrite_rules();
	}

	/**
	 * Clean up rewrite rules on deactivation.
	 */
	public function on_deactivation() {
		flush_rewrite_rules();
	}

	/**
	 * Restrict the attachments query to items that have at least one of:
	 * - alt text  (_wp_attachment_image_alt)
	 * - caption   (post_excerpt)
	 * - description (post_content)
	 * - searchwp_content meta
	 *
	 * Done at SQL level so pagination is accurate.
	 */
	public function filter_where( $where, $wp_query ) {
		global $wpdb;

		$where .= " AND (
			{$wpdb->posts}.post_content <> ''
			OR {$wpdb->posts}.post_excerpt <> ''
			OR EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} pm_alt
				WHERE pm_alt.post_id = {$wpdb->posts}.ID
				  AND pm_alt.meta_key = '_wp_attachment_image_alt'
				  AND pm_alt.meta_value <> ''
			)
			OR EXISTS (
				SELECT 1 FROM {$wpdb->postmeta} pm_swp
				WHERE pm_swp.post_id = {$wpdb->posts}.ID
				  AND pm_swp.meta_key = 'searchwp_content'
				  AND pm_swp.meta_value <> ''
			)
		)";

		return $where;
	}

	/**
	 * Render the paginated attachment feed in WordPress's default RSS2 format.
	 *
	 * Field mapping:
	 *   <description>      = alt_text + caption
	 *   <content:encoded>  = post_content (Description) + searchwp_content post meta
	 */
	public function render_feed() {
		header( 'Content-Type: ' . feed_content_type( 'rss2' ) . '; charset=' . get_option( 'blog_charset' ), true );

		$paged = get_query_var( 'paged' ) ? absint( get_query_var( 'paged' ) ) : 1;

		add_filter( 'posts_where', array( $this, 'filter_where' ), 10, 2 );

		// Flag this secondary query as a feed query (is_feed = true) so access-control
		// plugins (e.g. BCC Login) recognize it as a feed and apply their feed-key
		// bypass. Without this, the query is treated as a normal archive query and the
		// content-access visibility filter hides any attachment that isn't explicitly
		// set to "Public" — even when a valid feed key is supplied.
		//
		// WordPress overrides 'posts_per_page' with 'posts_per_rss' for feed queries
		// (see WP_Query::get_posts), so 'posts_per_rss' must be set to preserve PER_PAGE.
		$query = new WP_Query( array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'feed'           => self::FEED_SLUG,
			'posts_per_rss'  => self::PER_PAGE,
			'posts_per_page' => self::PER_PAGE,
			'paged'          => $paged,
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );

		remove_filter( 'posts_where', array( $this, 'filter_where' ), 10 );

		echo '<?xml version="1.0" encoding="' . esc_attr( get_option( 'blog_charset' ) ) . '"?' . '>';
		?>

	<rss version="2.0"
		xmlns:content="http://purl.org/rss/1.0/modules/content/"
		xmlns:wfw="http://wellformedweb.org/CommentAPI/"
		xmlns:dc="http://purl.org/dc/elements/1.1/"
		xmlns:atom="http://www.w3.org/2005/Atom"
		xmlns:sy="http://purl.org/rss/1.0/modules/syndication/"
		xmlns:slash="http://purl.org/rss/1.0/modules/slash/"
		xmlns:media="http://search.yahoo.com/mrss/"
		<?php do_action( 'rss2_ns' ); ?>>

	<channel>
		<title><?php bloginfo_rss( 'name' ); ?> &#8211; <?php esc_html_e( 'Attachments', 'bcc-attachments-rss-feed' ); ?></title>
		<atom:link href="<?php echo esc_url( get_feed_link( self::FEED_SLUG ) ); ?>" rel="self" type="application/rss+xml" />
		<link><?php bloginfo_rss( 'url' ); ?></link>
		<description><?php esc_html_e( 'Paginated media attachments feed', 'bcc-attachments-rss-feed' ); ?></description>
		<lastBuildDate><?php echo esc_html( mysql2date( 'D, d M Y H:i:s +0000', get_lastpostmodified( 'GMT' ), false ) ); ?></lastBuildDate>
		<language><?php bloginfo_rss( 'language' ); ?></language>
		<sy:updatePeriod><?php echo esc_html( apply_filters( 'rss_update_period', 'hourly' ) ); ?></sy:updatePeriod>
		<sy:updateFrequency><?php echo esc_html( apply_filters( 'rss_update_frequency', '1' ) ); ?></sy:updateFrequency>

	<?php
		$total_pages = (int) $query->max_num_pages;
		$self_link   = get_feed_link( self::FEED_SLUG );

		if ( $paged > 1 ) {
			echo "\t" . '<atom:link rel="previous" href="' . esc_url( add_query_arg( 'paged', $paged - 1, $self_link ) ) . '" />' . "\n";
		}
		if ( $paged < $total_pages ) {
			echo "\t" . '<atom:link rel="next" href="' . esc_url( add_query_arg( 'paged', $paged + 1, $self_link ) ) . '" />' . "\n";
		}

		do_action( 'rss2_head' );

		while ( $query->have_posts() ) : $query->the_post();
			$id       = get_the_ID();
			$file_url = wp_get_attachment_url( $id );
			$file     = get_attached_file( $id );
			$mime     = get_post_mime_type( $id ) ?: 'application/octet-stream';

			$size = ( $file && file_exists( $file ) ) ? (int) filesize( $file ) : 0;

			$alt_text         = trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) );
			$caption          = trim( (string) wp_get_attachment_caption( $id ) );
			$post_description = trim( (string) get_post_field( 'post_content', $id ) );
			$searchwp_content = trim( (string) get_post_meta( $id, 'searchwp_content', true ) );

			// <description> = alt text + caption
			$description_parts = array_filter( array( $alt_text, $caption ), 'strlen' );
			$description       = implode( "\n\n", $description_parts );

			// <content:encoded> = post_content + searchwp_content
			$content_parts = array_filter( array( $post_description, $searchwp_content ), 'strlen' );
			$content       = implode( "\n\n", $content_parts );

			$thumb = wp_get_attachment_image_src( $id, 'full' );
		?>

		<item>
			<title><?php the_title_rss(); ?></title>
			<link><?php echo esc_url( $file_url ); ?></link>
			<dc:creator><![CDATA[<?php the_author(); ?>]]></dc:creator>
			<pubDate><?php echo esc_html( mysql2date( 'D, d M Y H:i:s +0000', get_post_time( 'Y-m-d H:i:s', true ), false ) ); ?></pubDate>
			<guid isPermaLink="false"><?php the_permalink_rss(); ?></guid>
			<description><![CDATA[<?php echo $this->cdata( $description ); ?>]]></description>
			<content:encoded><![CDATA[<?php echo $this->cdata( $content ); ?>]]></content:encoded>
			<?php if ( $file_url && $size && $mime ) : ?><enclosure url="<?php echo esc_url( $file_url ); ?>" length="<?php echo esc_attr( (string) $size ); ?>" type="<?php echo esc_attr( $mime ); ?>" /><?php endif; ?>

			<?php if ( $file_url ) : ?><media:content url="<?php echo esc_url( $file_url ); ?>" type="<?php echo esc_attr( $mime ); ?>" fileSize="<?php echo esc_attr( (string) $size ); ?>">
				<media:title type="plain"><![CDATA[<?php echo $this->cdata( $alt_text ); ?>]]></media:title>
				<media:description type="plain"><![CDATA[<?php echo $this->cdata( $caption ); ?>]]></media:description>
				<?php if ( $thumb ) : ?><media:thumbnail url="<?php echo esc_url( $thumb[0] ); ?>" /><?php endif; ?>
			</media:content><?php endif; ?>

			<?php do_action( 'rss2_item' ); ?>
		</item>

	<?php
		endwhile;
		wp_reset_postdata();
	?>

	</channel>
	</rss>

	<?php
	}

	/**
	 * Escape a string for safe inclusion inside a CDATA block.
	 *
	 * Performs two things:
	 *  1. Removes characters that are illegal in XML 1.0 (e.g. NUL 0x00 and
	 *     other control characters). These are invalid even inside CDATA and
	 *     would make the whole feed unparseable. Extracted document text
	 *     (searchwp_content) frequently contains such bytes.
	 *  2. Splits any literal ']]>' sequence so it cannot terminate the CDATA
	 *     section early.
	 *
	 * @param mixed $string The value to escape.
	 * @return string
	 */
	private function cdata( $string ) {
		$string = (string) $string;

		// Strip characters that are not allowed in XML 1.0.
		// Allowed: #x9, #xA, #xD, #x20-#xD7FF, #xE000-#xFFFD, #x10000-#x10FFFF.
		$string = preg_replace(
			'/[^\x09\x0A\x0D\x20-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u',
			'',
			$string
		);

		// If the input was not valid UTF-8, preg_replace() with the /u flag
		// returns null. Fall back to stripping low control bytes only.
		if ( null === $string ) {
			$string = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) func_get_arg( 0 ) );
		}

		return str_replace( ']]>', ']]]]><![CDATA[>', (string) $string );
	}

	/**
	 * Creates and returns a single instance of this class.
	 *
	 * @return BCC_Attachments_RSS_Feed
	 */
	static function get_instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new BCC_Attachments_RSS_Feed();
		}
		return self::$instance;
	}
}

function bcc_attachments_rss_feed() {
	return BCC_Attachments_RSS_Feed::get_instance();
}

bcc_attachments_rss_feed();
