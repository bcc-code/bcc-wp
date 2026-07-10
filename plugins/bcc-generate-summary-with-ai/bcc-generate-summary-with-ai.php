<?php
/**
 * Plugin Name: BCC – Generate summary with AI
 * Description: Adds a panel to the Gutenberg post editor that generates a short summary of the post content using OpenAI.
 * Version:     1.1.0
 * Author:      BCC IT
 * License:     GPL-2.0-or-later
 * Requires at least: 6.5
 * Requires PHP: 8.0
 */

declare( strict_types=1 );

namespace BCC_Generate_Summary_With_AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION   = '1.1.0';
const OPTION_KEY = 'bcc_generate_summary_with_ai_settings';
const REST_NS   = 'bcc-generate-summary-with-ai/v1';

define( __NAMESPACE__ . '\\PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( __NAMESPACE__ . '\\PLUGIN_URL',  plugin_dir_url( __FILE__ ) );

require_once PLUGIN_PATH . 'admin/settings.php';
require_once PLUGIN_PATH . 'includes/class-bcc-generate-summary-with-ai-updater.php';

/**
 * Self-hosted plugin updater (pulls metadata from this repo's package.json
 * and downloads release zips from GitHub Releases).
 */
add_action( 'init', static function (): void {
	new \BCC_Generate_Summary_With_AI_Updater(
		plugin_basename( __FILE__ ),
		plugin_basename( __DIR__ ),
		VERSION,
		'BCC – Generate summary with AI'
	);
} );

/**
 * Plugin settings with defaults.
 *
 * @return array{api_key:string, model:string}
 */
function get_settings(): array {
	$defaults = [
		'api_key' => '',
		'model'   => 'gpt-4o-mini',
	];
	$saved = get_option( OPTION_KEY, [] );
	if ( ! is_array( $saved ) ) {
		$saved = [];
	}
	return array_merge( $defaults, $saved );
}

/**
 * Add a Settings link on the Plugins list row.
 */
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), static function ( array $links ): array {
	$url  = admin_url( 'options-general.php?page=bcc-generate-summary-with-ai' );
	$link = sprintf( '<a href="%1$s">%2$s</a>', esc_url( $url ), esc_html__( 'Settings', 'bcc-generate-summary-with-ai' ) );
	array_unshift( $links, $link );
	return $links;
} );

/**
 * Register the "Generate summary with AI" Gutenberg block.
 */
add_action( 'init', static function (): void {
	wp_register_script(
		'bcc-generate-summary-block-editor',
		PLUGIN_URL . 'blocks/generate-summary/editor.js',
		[ 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-data', 'wp-api-fetch' ],
		VERSION,
		true
	);

	wp_localize_script( 'bcc-generate-summary-block-editor', 'BCCGenerateSummary', [
		'i18n' => [
			'block_title' => __( 'Kortversjon', 'bcc-generate-summary-with-ai' ),
			'generate'    => __( 'Generate summary', 'bcc-generate-summary-with-ai' ),
			'generating'  => __( 'Generating…', 'bcc-generate-summary-with-ai' ),
			'placeholder' => __( 'AI-generated summary will appear here. Click "Generate summary" in the toolbar.', 'bcc-generate-summary-with-ai' ),
			'failed'      => __( 'Failed to generate summary.', 'bcc-generate-summary-with-ai' ),
		],
	] );

	wp_register_script(
		'bcc-generate-summary-block-view',
		PLUGIN_URL . 'blocks/generate-summary/view.js',
		[ 'tippy' ],
		VERSION,
		true
	);

	wp_localize_script( 'bcc-generate-summary-block-view', 'BCCGenerateSummaryView', [
		'vis_mer'    => __( 'Vis mer', 'bcc-generate-summary-with-ai' ),
		'vis_mindre' => __( 'Vis mindre', 'bcc-generate-summary-with-ai' ),
	] );

	wp_register_style(
		'bcc-generate-summary-block',
		PLUGIN_URL . 'blocks/generate-summary/style.css',
		[],
		VERSION
	);

	register_block_type( 'bcc-generate-summary-with-ai/generate-summary', [
		'api_version'           => 3,
		'title'                 => __( 'Generate summary with AI', 'bcc-generate-summary-with-ai' ),
		'description'           => __( 'Generates a short AI summary of the post and inserts it as editable text.', 'bcc-generate-summary-with-ai' ),
		'category'              => 'text',
		'icon'                  => 'edit-page',
		'editor_script_handles' => [ 'bcc-generate-summary-block-editor' ],
		'view_script_handles'   => [ 'bcc-generate-summary-block-view' ],
		'style_handles'         => [ 'bcc-generate-summary-block' ],
		'render_callback'       => __NAMESPACE__ . '\\render_generate_summary_block',
		'attributes'            => [
			'content' => [
				'type'    => 'string',
				'default' => '',
			],
		],
	] );
} );

function render_generate_summary_block( array $attributes ): string {
	$content = wp_kses_post( $attributes['content'] ?? '' );
	if ( trim( wp_strip_all_tags( $content ) ) === '' ) {
		return '';
	}

	$title        = __( 'Kortversjon', 'bcc-generate-summary-with-ai' );
	$tooltip_text = esc_attr( __( 'Oppsummeringen er laget med kunstig intelligens og kvalitetssikret av BCC redaktørene.', 'bcc-generate-summary-with-ai' ) );
	$badge_label  = esc_attr( __( 'AI-generated summary', 'bcc-generate-summary-with-ai' ) );

	$sparkle_svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false">'
		. '<path d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456zM16.894 20.567L16.5 21.75l-.394-1.183a2.25 2.25 0 00-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 001.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 001.423 1.423l1.183.394-1.183.394a2.25 2.25 0 00-1.423 1.423z"/>'
		. '</svg>';

	$toggle_label = esc_html( __( 'Vis mer', 'bcc-generate-summary-with-ai' ) );

	return '<div ' . get_block_wrapper_attributes() . '>'
		. '<div class="bcc-generate-summary-block__header">'
		. '<span class="bcc-generate-summary-block__title">' . esc_html( $title ) . '</span>'
		. '</div>'
		. '<div class="bcc-generate-summary-block__body is-collapsed">'
		. '<ul class="bcc-generate-summary-block__content">' . $content . '</ul>'
		. '</div>'
		. '<button type="button" class="bcc-generate-summary-block__toggle" aria-expanded="false">'
		. '<span>' . $toggle_label . '</span>'
		. '</button>'
		. '<button type="button" class="bcc-generate-summary-block__ai-badge" data-ai-tooltip="' . $tooltip_text . '" aria-label="' . $badge_label . '">'
		. $sparkle_svg
		. '</button>'
		. '</div>';
}

/**
 * Register the REST endpoint.
 */
add_action( 'rest_api_init', static function (): void {
	register_rest_route( REST_NS, '/generate', [
		'methods'             => 'POST',
		'callback'            => __NAMESPACE__ . '\\handle_generate',
		'permission_callback' => static fn(): bool => current_user_can( 'edit_posts' ),
		'args'                => [
			'post_id' => [
				'type'     => 'integer',
				'required' => true,
				'minimum'  => 1,
			],
		],
	] );
} );

function handle_generate( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
	$post_id = (int) $request->get_param( 'post_id' );
	$post    = get_post( $post_id );

	if ( ! $post || ! current_user_can( 'edit_post', $post_id ) ) {
		return new \WP_Error( 'permission_denied', __( 'Permission denied.', 'bcc-generate-summary-with-ai' ), [ 'status' => 403 ] );
	}

	$settings = get_settings();
	if ( $settings['api_key'] === '' ) {
		return new \WP_Error(
			'no_api_key',
			__( 'OpenAI API key is not configured. Open Settings → BCC – Generate summary with AI.', 'bcc-generate-summary-with-ai' ),
			[ 'status' => 500 ]
		);
	}

	// Render blocks to HTML, then strip tags for clean plain-text input to OpenAI.
	$content = wp_strip_all_tags( apply_filters( 'the_content', $post->post_content ) );
	$content = trim( (string) preg_replace( '/\s+/', ' ', $content ) );

	if ( $content === '' ) {
		return new \WP_Error( 'empty_content', __( 'The post has no content to summarize.', 'bcc-generate-summary-with-ai' ), [ 'status' => 400 ] );
	}

	$language = resolve_post_language( $post_id );
	$result   = call_openai( $settings, $content, $language );

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	// Convert plain-text AI output (markdown bullets or bare lines) to <li> items.
	if ( ! preg_match( '/<li\b/i', $result ) ) {
		$lines   = array_values( array_filter( array_map( 'trim', preg_split( '/\r?\n+/', $result ) ), fn( $l ) => $l !== '' ) );
		$result  = implode( '', array_map(
			fn( $l ) => '<li>' . trim( preg_replace( '/^[\-\*•]\s*/', '', $l ) ) . '</li>',
			$lines
		) );
	}

	return new \WP_REST_Response( [ 'summary' => $result ], 200 );
}

/**
 * Resolve a human-readable language name for the post.
 *
 * Priority order:
 *   1. WPML post language details (most accurate for multilingual posts).
 *   2. WPML runtime `wpml_current_language` filter.
 *   3. `ICL_LANGUAGE_CODE` constant (set early by WPML).
 *   4. Polylang `pll_current_language()`.
 *   5. WordPress `determine_locale()` as a final fallback.
 *   6. `bcc_generate_summary_with_ai_locale` filter for site-specific overrides.
 */
function resolve_post_language( int $post_id ): string {
	$code = '';

	// 1. WPML post-specific language.
	if ( has_filter( 'wpml_post_language_details' ) ) {
		$details = apply_filters( 'wpml_post_language_details', null, $post_id );
		if ( is_array( $details ) && isset( $details['language_code'] ) ) {
			$code = (string) $details['language_code'];
		}
	}

	// 2. WPML runtime current language.
	if ( $code === '' && has_filter( 'wpml_current_language' ) ) {
		$wpml = apply_filters( 'wpml_current_language', null );
		if ( is_string( $wpml ) && $wpml !== '' ) {
			$code = $wpml;
		}
	}

	// 3. WPML early-set constant.
	if ( $code === '' && defined( 'ICL_LANGUAGE_CODE' ) ) {
		$icl = (string) constant( 'ICL_LANGUAGE_CODE' );
		if ( $icl !== '' ) {
			$code = $icl;
		}
	}

	// 4. Polylang.
	if ( $code === '' && function_exists( 'pll_current_language' ) ) {
		$pll = \pll_current_language();
		if ( is_string( $pll ) && $pll !== '' ) {
			$code = $pll;
		}
	}

	// 5. WordPress locale fallback.
	if ( $code === '' ) {
		$code = (string) determine_locale();
	}

	// 6. Site-specific override.
	$code = (string) apply_filters( 'bcc_generate_summary_with_ai_locale', $code );

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
 * Call OpenAI Chat Completions and return a summary string.
 *
 * @param array{api_key:string, model:string} $settings
 * @return string|\WP_Error
 */
function call_openai( array $settings, string $content, string $language = 'English' ) {
	$language = $language !== '' ? $language : 'English';

	// Cap input length to stay within token limits.
	if ( mb_strlen( $content ) > 12000 ) {
		$content = mb_substr( $content, 0, 12000 ) . '…';
	}

	$prompt = <<<PROMPT
Summarize the following article as a list, max 5 items (100-150 words, between 700-1000 characters), which contains some words about the topic, target audience, and the most important points for the reader.
Write in {$language}.
Return ONLY the summary — no introductory phrase, no quotes, no labels.

Article:
{$content}
PROMPT;

	// o-series and gpt-5+ use max_completion_tokens and reject temperature.
	$is_new_api = (bool) preg_match( '/^(o\d|gpt-5)/', $settings['model'] );

	$body = [
		'model'    => $settings['model'],
		'messages' => [
			[ 'role' => 'user', 'content' => $prompt ],
		],
		$is_new_api ? 'max_completion_tokens' : 'max_tokens' => 300,
	];

	if ( ! $is_new_api ) {
		$body['temperature'] = 0.5;
	}

	$response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
		'timeout' => 60,
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
		$msg = is_array( $json ) ? ( $json['error']['message'] ?? sprintf( 'HTTP %d', $code ) ) : sprintf( 'HTTP %d', $code );
		return new \WP_Error( 'openai_http', $msg );
	}

	$summary = $json['choices'][0]['message']['content'] ?? '';
	if ( ! is_string( $summary ) || trim( $summary ) === '' ) {
		return new \WP_Error( 'openai_empty', __( 'Empty response from OpenAI.', 'bcc-generate-summary-with-ai' ) );
	}

	return trim( $summary );
}
