<?php
/**
 * Plugin Name: BCC – Image OCR with OpenAI
 * Description: Adds a button to the attachment edit screen that uses OpenAI Vision to extract all visible text from an image and fill the Description, Alternative Text and Caption fields in a single request.
 * Version:     1.3.1
 * Author:      BCC IT
 * License:     GPL-2.0-or-later
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

declare( strict_types=1 );

namespace BCC_Image_OCR_With_OpenAI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION      = '1.3.1';
const OPTION_KEY   = 'bcc_image_ocr_with_openai_settings';
const AJAX_ACTION  = 'bcc_image_ocr_with_openai_extract';
const NONCE_ACTION = 'bcc_image_ocr_with_openai';

define( __NAMESPACE__ . '\\PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( __NAMESPACE__ . '\\PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

require_once PLUGIN_PATH . 'admin/settings.php';
require_once PLUGIN_PATH . 'includes/class-bcc-image-ocr-with-openai-updater.php';

/**
 * Self-hosted plugin updater (pulls metadata from this repo's package.json
 * and downloads release zips from GitHub Releases).
 */
add_action( 'init', static function (): void {
	new \BCC_Image_OCR_With_OpenAI_Updater(
		plugin_basename( __FILE__ ),                    // e.g. bcc-image-ocr-with-openai/bcc-image-ocr-with-openai.php
		plugin_basename( __DIR__ ),                     // e.g. bcc-image-ocr-with-openai
		VERSION,
		'BCC – Image OCR with OpenAI'
	);
} );

/**
 * Add a Settings link on the Plugins list row.
 */
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), static function ( array $links ): array {
	$settings_url  = admin_url( 'options-general.php?page=bcc-image-ocr-with-openai' );
	$settings_link = sprintf(
		'<a href="%1$s">%2$s</a>',
		esc_url( $settings_url ),
		esc_html__( 'Settings', 'bcc-image-ocr-with-openai' )
	);

	array_unshift( $links, $settings_link );
	return $links;
} );

/**
 * Plugin settings with defaults.
 *
 * @return array{api_key:string, model:string, enrich_feed_images:int}
 */
function get_settings(): array {
	$defaults = [
		'api_key'            => '',
		'model'              => 'gpt-4o-mini',
		'enrich_feed_images' => 1,
	];
	$saved = get_option( OPTION_KEY, [] );
	if ( ! is_array( $saved ) ) {
		$saved = [];
	}
	return array_merge( $defaults, $saved );
}

/**
 * Enqueue the admin JS on the attachment edit screen.
 */
add_action( 'admin_enqueue_scripts', static function ( string $hook ): void {
	if ( $hook !== 'post.php' || get_post_type() !== 'attachment' ) {
		return;
	}
	$post = get_post();
	if ( ! $post || ! wp_attachment_is_image( $post->ID ) ) {
		return;
	}

	wp_enqueue_script(
		'bcc-image-ocr-with-openai',
		PLUGIN_URL . 'admin/media-button.js',
		[ 'jquery' ],
		VERSION,
		true
	);

	wp_localize_script( 'bcc-image-ocr-with-openai', 'BCCImageOCRWithOpenAI', [
		'ajax_url'      => admin_url( 'admin-ajax.php' ),
		'nonce'         => wp_create_nonce( NONCE_ACTION ),
		'action'        => AJAX_ACTION,
		'attachment_id' => $post->ID,
		'i18n'          => [
			'button'       => __( 'Extract text with AI', 'bcc-image-ocr-with-openai' ),
			'working'      => __( 'Extracting…', 'bcc-image-ocr-with-openai' ),
			'done'         => __( 'Fields filled. Remember to click Update.', 'bcc-image-ocr-with-openai' ),
			'failed'       => __( 'OCR failed:', 'bcc-image-ocr-with-openai' ),
			'confirm_over' => __( 'Overwrite the existing Description, Alt text and Caption with AI-generated values?', 'bcc-image-ocr-with-openai' ),
		],
	] );
} );

/**
 * AJAX entry point.
 */
add_action( 'wp_ajax_' . AJAX_ACTION, __NAMESPACE__ . '\\handle_ajax' );

function handle_ajax(): void {
	check_ajax_referer( NONCE_ACTION, 'nonce' );

	$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;

	if ( ! $attachment_id || ! current_user_can( 'edit_post', $attachment_id ) ) {
		wp_send_json_error( [ 'message' => __( 'Permission denied.', 'bcc-image-ocr-with-openai' ) ], 403 );
	}

	if ( ! wp_attachment_is_image( $attachment_id ) ) {
		wp_send_json_error( [ 'message' => __( 'Attachment is not an image.', 'bcc-image-ocr-with-openai' ) ], 400 );
	}

	$settings = get_settings();
	if ( $settings['api_key'] === '' ) {
		wp_send_json_error( [ 'message' => __( 'OpenAI API key is not configured. Open Settings → BCC – Image OCR with OpenAI.', 'bcc-image-ocr-with-openai' ) ], 500 );
	}

	$image = build_image_payload( $attachment_id );
	if ( is_wp_error( $image ) ) {
		wp_send_json_error( [ 'message' => $image->get_error_message() ], 500 );
	}

	$lang_hint = isset( $_POST['lang'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['lang'] ) ) : '';
	$result    = call_openai( $settings, $image, current_site_language( $lang_hint ) );
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( [ 'message' => $result->get_error_message() ], 502 );
	}

	$description = (string) ( $result['description'] ?? '' );
	$alt_text    = (string) ( $result['alt_text']    ?? '' );
	$caption     = (string) ( $result['caption']     ?? '' );

	// Persist server-side so a refresh keeps the values.
	wp_update_post( [
		'ID'           => $attachment_id,
		'post_content' => wp_kses_post( $description ),
		'post_excerpt' => sanitize_textarea_field( $caption ),
	] );
	update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $alt_text ) );

	wp_send_json_success( [
		'description' => $description,
		'alt_text'    => $alt_text,
		'caption'     => $caption,
	] );
}

/**
 * Resolve the human-readable language used by the editor's language switcher
 * (WPML / Polylang admin top-bar). Falls back to the site locale.
 *
 * @param string $hint Optional language code coming from the admin URL (?lang=xx).
 */
function current_site_language( string $hint = '' ): string {
	$code = '';

	// 1. Hint from the admin URL forwarded by the JS (most reliable for WPML's top-bar).
	if ( $hint !== '' && $hint !== 'all' ) {
		$code = $hint;
	}

	// 2. WPML.
	if ( $code === '' && has_filter( 'wpml_current_language' ) ) {
		$wpml = apply_filters( 'wpml_current_language', null );
		if ( is_string( $wpml ) && $wpml !== '' ) {
			$code = $wpml;
		}
	}
	if ( $code === '' && defined( 'ICL_LANGUAGE_CODE' ) ) {
		$code = (string) constant( 'ICL_LANGUAGE_CODE' );
	}

	// 3. Polylang.
	if ( $code === '' && function_exists( 'pll_current_language' ) ) {
		$pll = \pll_current_language();
		if ( is_string( $pll ) && $pll !== '' ) {
			$code = $pll;
		}
	}

	// 4. Fallback: site / user locale.
	if ( $code === '' ) {
		$code = determine_locale();
	}

	/** Allow site-specific overrides. */
	$code = (string) apply_filters( 'bcc_image_ocr_with_openai_locale', $code );

	$map = [
		'en' => 'English',
		'nb' => 'Norwegian Bokmål',
		'no' => 'Norwegian',
		'da' => 'Danish',
		'fi' => 'Finnish',
		'de' => 'German',
		'fr' => 'French',
		'es' => 'Spanish',
		'it' => 'Italian',
		'pt' => 'Portuguese',
		'nl' => 'Dutch',
		'pl' => 'Polish',
		'ru' => 'Russian',
		'tr' => 'Turkish',
		'ro' => 'Romanian',
		'hu' => 'Hungarian',
		'uk' => 'Ukrainian',
		'zh' => 'Chinese',
	];

	$short = strtolower( substr( $code, 0, 2 ) );
	return $map[ $short ] ?? str_replace( '_', '-', $code );
}

/**
 * Build the image payload. Uses a base64 data URL so it works on local/private sites
 * where OpenAI cannot fetch wp_get_attachment_url() directly.
 *
 * @return array{url:string}|\WP_Error
 */
function build_image_payload( int $attachment_id ) {
	$file = get_attached_file( $attachment_id );
	if ( $file && is_readable( $file ) ) {
		$mime  = get_post_mime_type( $attachment_id ) ?: 'image/jpeg';
		$bytes = maybe_resize( $file, $mime );
		if ( is_wp_error( $bytes ) ) {
			return $bytes;
		}
		return [ 'url' => 'data:' . $mime . ';base64,' . base64_encode( $bytes ) ];
	}

	$url = wp_get_attachment_url( $attachment_id );
	if ( ! $url ) {
		return new \WP_Error( 'no_image', __( 'Could not locate the image file.', 'bcc-image-ocr-with-openai' ) );
	}
	return [ 'url' => $url ];
}

/**
 * Down-scale very large images before sending to the API.
 *
 * @return string|\WP_Error  Raw image bytes.
 */
function maybe_resize( string $file, string $mime ) {
	$max_dim   = 2000;
	$max_bytes = 4 * 1024 * 1024;

	$bytes = @file_get_contents( $file );
	if ( $bytes === false ) {
		return new \WP_Error( 'read_failed', __( 'Could not read the image file.', 'bcc-image-ocr-with-openai' ) );
	}

	$size = @getimagesize( $file );
	if ( $size && ( $size[0] > $max_dim || $size[1] > $max_dim || strlen( $bytes ) > $max_bytes ) ) {
		$editor = wp_get_image_editor( $file );
		if ( ! is_wp_error( $editor ) ) {
			$editor->resize( $max_dim, $max_dim, false );
			$tmp   = wp_tempnam( 'openai-ocr' );
			$saved = $editor->save( $tmp, $mime );
			if ( ! is_wp_error( $saved ) && ! empty( $saved['path'] ) && is_readable( $saved['path'] ) ) {
				$bytes = (string) file_get_contents( $saved['path'] );
				@unlink( $saved['path'] );
			}
		}
	}

	return $bytes;
}

/**
 * Call OpenAI Chat Completions and decode the JSON response.
 *
 * @param array{api_key:string, model:string} $settings
 * @param array{url:string}                   $image
 * @param string                              $language Human-readable language name for alt/caption.
 * @return array{description?:string, alt_text?:string, caption?:string}|\WP_Error
 */
function call_openai( array $settings, array $image, string $language = 'English' ) {
	$language = $language !== '' ? $language : 'English';

	$prompt = <<<PROMPT
You are an OCR + accessibility assistant. Look at the image and reply with ONE JSON object
(no markdown fences, no commentary) using EXACTLY these keys:

{
  "description": "All visible text from the image, transcribed verbatim in its ORIGINAL language. Preserve line breaks, ordering, headings and lists as plain text. Do NOT summarise, translate or invent content. If the image has no text, return an empty string.",
  "alt_text":    "A concise (max ~140 chars) alternative text describing the image for screen-reader users, written in {$language}. Mention the type of document (e.g. 'Event programme for…') and key visual content. Plain text only.",
  "caption":     "A short caption (max ~200 chars) suitable for display under the image, written in {$language}."
}

Return ONLY the JSON object.
PROMPT;

	$body = [
		'model'           => $settings['model'],
		'response_format' => [ 'type' => 'json_object' ],
		'messages'        => [
			[
				'role'    => 'user',
				'content' => [
					[ 'type' => 'text',      'text'      => $prompt ],
					[ 'type' => 'image_url', 'image_url' => [ 'url' => $image['url'] ] ],
				],
			],
		],
	];

	// Reasoning models (o-series, gpt-5 family) reject the `temperature` parameter.
	if ( ! preg_match( '/^(o\d|gpt-5)/', $settings['model'] ) ) {
		$body['temperature'] = 0.2;
	}

	$response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
		'timeout' => 120,
		'headers' => [
			'Authorization' => 'Bearer ' . $settings['api_key'],
			'Content-Type'  => 'application/json',
		],
		'body'    => wp_json_encode( $body ),
	] );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = wp_remote_retrieve_response_code( $response );
	$raw  = wp_remote_retrieve_body( $response );
	$json = json_decode( $raw, true );

	if ( $code < 200 || $code >= 300 ) {
		$msg = $json['error']['message'] ?? sprintf( 'HTTP %d', $code );
		return new \WP_Error( 'openai_http', $msg );
	}

	$content = $json['choices'][0]['message']['content'] ?? '';
	if ( ! is_string( $content ) || $content === '' ) {
		return new \WP_Error( 'openai_empty', __( 'Empty response from OpenAI.', 'bcc-image-ocr-with-openai' ) );
	}

	$decoded = json_decode( $content, true );
	if ( ! is_array( $decoded ) && preg_match( '/\{.*\}/s', $content, $m ) ) {
		$decoded = json_decode( $m[0], true );
	}
	if ( ! is_array( $decoded ) ) {
		return new \WP_Error( 'openai_parse', __( 'Could not parse JSON response from OpenAI.', 'bcc-image-ocr-with-openai' ) );
	}

	return $decoded;
}

/**
 * Enrich every core/image block in RSS feeds with the attachment's alt text,
 * caption and description.
 *
 * We hook into `render_block_core/image` so we modify the already-rendered
 * block HTML in place. This preserves the original wrapping <a>, classes, etc.
 */
add_filter( 'render_block_core/image', function ( $block_content, $block ) {
    if ( ! is_feed() ) {
        return $block_content;
    }

    $settings = \BCC_Image_OCR_With_OpenAI\get_settings();
    if ( empty( $settings['enrich_feed_images'] ) ) {
        return $block_content;
    }

    $attachment_id = isset( $block['attrs']['id'] ) ? (int) $block['attrs']['id'] : 0;
    if ( ! $attachment_id || empty( $block_content ) ) {
        return $block_content;
    }

    $alt         = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
    $caption     = trim( (string) wp_get_attachment_caption( $attachment_id ) );
    $description = trim( (string) get_post_field( 'post_content', $attachment_id ) );

    // 1) Fill <img alt=""> if it's empty/missing.
    if ( $alt !== '' ) {
        $block_content = preg_replace_callback(
            '/<img\b[^>]*>/i',
            function ( $m ) use ( $alt ) {
                $tag = $m[0];
                if ( preg_match( '/\salt\s*=\s*("|\')(.*?)\1/i', $tag, $am ) ) {
                    if ( trim( $am[2] ) === '' ) {
                        $tag = preg_replace(
                            '/\salt\s*=\s*("|\').*?\1/i',
                            ' alt="' . esc_attr( $alt ) . '"',
                            $tag,
                            1
                        );
                    }
                } else {
                    $tag = preg_replace( '/<img\b/i', '<img alt="' . esc_attr( $alt ) . '"', $tag, 1 );
                }
                return $tag;
            },
            $block_content,
            1
        );
    }

    // 2) Inject a <figcaption> if the figure doesn't already have one.
    if ( $caption !== '' && stripos( $block_content, '<figcaption' ) === false ) {
        $block_content = preg_replace(
            '/<\/figure>/i',
            '<figcaption>' . wp_kses_post( $caption ) . '</figcaption></figure>',
            $block_content,
            1
        );
    }

    // 3) Append the long description after the figure.
    if ( $description !== '' ) {
        $block_content .= "\n" . '<p><strong>'
            . esc_html__( 'Image description:', 'bcc-image-ocr-with-openai' )
            . '</strong> ' . wp_kses_post( $description ) . '</p>';
    }

    return $block_content;
}, 10, 2 );

/**
 * Enrich images rendered by visual editors / page builders (e.g. Flatsome
 * UX Builder) in RSS feeds.
 *
 * Many builders output `<img>` tags inside their own wrappers (e.g.
 * `<div class="img"> … <img …> … </div>`) instead of using `core/image`
 * blocks, so the `render_block_core/image` filter above never fires for them.
 *
 * This filter runs on the final feed content and:
 *   1. Skips `<img>` elements that are inside a `<figure>` (already handled
 *      by the block filter above).
 *   2. Resolves the attachment ID from `wp-image-{ID}` class or, as a
 *      fallback, by URL lookup.
 *   3. Fills empty `alt`, then inserts a caption + description after the
 *      image's nearest block-level wrapper (so we don't end up nested inside
 *      an `<a>` lightbox link).
 */
add_filter( 'the_content_feed', __NAMESPACE__ . '\\enrich_non_block_feed_images', 20 );
add_filter( 'the_excerpt_rss',  __NAMESPACE__ . '\\enrich_non_block_feed_images', 20 );

function enrich_non_block_feed_images( string $content ): string {
    if ( ! is_feed() ) {
        return $content;
    }

    $settings = get_settings();
    if ( empty( $settings['enrich_feed_images'] ) ) {
        return $content;
    }

    if ( $content === '' || stripos( $content, '<img' ) === false ) {
        return $content;
    }

    $previous = libxml_use_internal_errors( true );
    $dom      = new \DOMDocument();
    $loaded   = $dom->loadHTML(
        '<?xml encoding="UTF-8"?><div id="bcc-ocr-feed-root">' . $content . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();
    libxml_use_internal_errors( $previous );

    if ( ! $loaded ) {
        return $content;
    }

    $root = $dom->getElementById( 'bcc-ocr-feed-root' );
    if ( ! $root ) {
        return $content;
    }

    $imgs = iterator_to_array( $dom->getElementsByTagName( 'img' ) );
    foreach ( $imgs as $img ) {
        // Skip images that are already inside a <figure> – they were enriched
        // by render_block_core/image above (or by the theme's own figure).
        if ( has_ancestor( $img, 'figure', $root ) ) {
            continue;
        }

        $attachment_id = resolve_attachment_id_from_img( $img );
        if ( ! $attachment_id ) {
            continue;
        }

        $alt         = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
        $caption     = trim( (string) wp_get_attachment_caption( $attachment_id ) );
        $description = trim( (string) get_post_field( 'post_content', $attachment_id ) );

        // 1) Fill empty alt.
        if ( $alt !== '' && trim( $img->getAttribute( 'alt' ) ) === '' ) {
            $img->setAttribute( 'alt', $alt );
        }

        if ( $caption === '' && $description === '' ) {
            continue;
        }

        // 2) Pick a sane insertion point: the closest block-level ancestor
        // (the page-builder wrapper). This keeps the appended caption /
        // description out of any surrounding <a> lightbox link.
        $insertion_after = $img;
        $walker          = $img->parentNode;
        $block_tags      = [ 'div', 'figure', 'section', 'article', 'p', 'li' ];
        while ( $walker && $walker !== $root ) {
            if ( in_array( strtolower( $walker->nodeName ), $block_tags, true ) ) {
                $insertion_after = $walker;
                break;
            }
            $walker = $walker->parentNode;
        }

        $next_sibling = $insertion_after->nextSibling;
        $parent       = $insertion_after->parentNode;
        if ( ! $parent ) {
            continue;
        }

        // 3) Caption (italic, like a typical figcaption).
        if ( $caption !== '' ) {
            $cap_p = $dom->createElement( 'p' );
            $cap_p->setAttribute( 'class', 'bcc-image-caption' );
            $cap_em = $dom->createElement( 'em' );
            $cap_em->appendChild( $dom->createTextNode( $caption ) );
            $cap_p->appendChild( $cap_em );
            $parent->insertBefore( $cap_p, $next_sibling );
        }

        // 4) Long description.
        if ( $description !== '' ) {
            $desc_p = $dom->createElement( 'p' );
            $desc_p->setAttribute( 'class', 'bcc-image-description' );

            $strong = $dom->createElement( 'strong' );
            $strong->appendChild( $dom->createTextNode(
                __( 'Image description:', 'bcc-image-ocr-with-openai' ) . ' '
            ) );
            $desc_p->appendChild( $strong );

            // Allow basic HTML in the description (matches the block filter).
            $desc_p->appendChild( $dom->createTextNode( wp_strip_all_tags( $description ) ) );

            $parent->insertBefore( $desc_p, $next_sibling );
        }
    }

    // Serialize back the inner HTML of our wrapper.
    $html = '';
    foreach ( $root->childNodes as $child ) {
        $html .= $dom->saveHTML( $child );
    }

    return $html;
}

/**
 * Walk up the DOM looking for an ancestor with the given tag name,
 * stopping at $stop (exclusive).
 */
function has_ancestor( \DOMNode $node, string $tag, \DOMNode $stop ): bool {
    $tag = strtolower( $tag );
    $p   = $node->parentNode;
    while ( $p && $p !== $stop ) {
        if ( strtolower( $p->nodeName ) === $tag ) {
            return true;
        }
        $p = $p->parentNode;
    }
    return false;
}

/**
 * Try to resolve the attachment ID for an `<img>` element, first via the
 * `wp-image-{ID}` class, then by URL lookup as a fallback (slower).
 */
function resolve_attachment_id_from_img( \DOMElement $img ): int {
    $class = $img->getAttribute( 'class' );
    if ( $class !== '' && preg_match( '/wp-image-(\d+)/', $class, $m ) ) {
        return (int) $m[1];
    }

    $src = $img->getAttribute( 'src' );
    if ( $src === '' ) {
        return 0;
    }

    // Strip query string and resolve `-WIDTHxHEIGHT` resized variants back to
    // the original to maximize hit rate against the media library.
    $url = strtok( $src, '?' ) ?: $src;
    $url = preg_replace( '/-\d+x\d+(\.[a-zA-Z0-9]+)$/', '$1', $url );

    return (int) attachment_url_to_postid( $url );
}