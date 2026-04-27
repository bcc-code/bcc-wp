<?php
/**
 * Settings page for the BCC – Image OCR with OpenAI plugin.
 */

declare( strict_types=1 );

namespace BCC_Image_OCR_With_OpenAI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', static function (): void {
	add_options_page(
		__( 'BCC – Image OCR with OpenAI', 'bcc-image-ocr-with-openai' ),
		__( 'BCC – Image OCR with OpenAI', 'bcc-image-ocr-with-openai' ),
		'manage_options',
		'bcc-image-ocr-with-openai',
		__NAMESPACE__ . '\\render_settings_page'
	);
} );

add_action( 'admin_init', static function (): void {
	register_setting(
		'openai_ocr',
		OPTION_KEY,
		[
			'type'              => 'array',
			'sanitize_callback' => __NAMESPACE__ . '\\sanitize_settings',
			'default'           => [
				'api_key'            => '',
				'model'              => 'gpt-4o-mini',
				'enrich_feed_images' => 1,
			],
		]
	);

	add_settings_section(
		'bcc_image_ocr_with_openai_main',
		__( 'OpenAI credentials', 'bcc-image-ocr-with-openai' ),
		'__return_false',
		'bcc-image-ocr-with-openai'
	);

	add_settings_field(
		'api_key',
		__( 'API key', 'bcc-image-ocr-with-openai' ),
		__NAMESPACE__ . '\\field_api_key',
		'bcc-image-ocr-with-openai',
		'bcc_image_ocr_with_openai_main'
	);

	add_settings_field(
		'model',
		__( 'Vision model', 'bcc-image-ocr-with-openai' ),
		__NAMESPACE__ . '\\field_model',
		'bcc-image-ocr-with-openai',
		'bcc_image_ocr_with_openai_main'
	);

	add_settings_section(
		'bcc_image_ocr_with_openai_feed',
		__( 'RSS feed', 'bcc-image-ocr-with-openai' ),
		'__return_false',
		'bcc-image-ocr-with-openai'
	);

	add_settings_field(
		'enrich_feed_images',
		__( 'Enrich feed images', 'bcc-image-ocr-with-openai' ),
		__NAMESPACE__ . '\\field_enrich_feed_images',
		'bcc-image-ocr-with-openai',
		'bcc_image_ocr_with_openai_feed'
	);
} );

/**
 * @param mixed $input
 * @return array{api_key:string, model:string, enrich_feed_images:int}
 */
function sanitize_settings( $input ): array {
	$input = is_array( $input ) ? $input : [];
	$key   = isset( $input['api_key'] ) ? trim( (string) $input['api_key'] ) : '';
	$model = isset( $input['model'] ) ? sanitize_text_field( (string) $input['model'] ) : 'gpt-4o-mini';

	$allowed_models = [
		'gpt-5',
		'gpt-5-mini',
		'gpt-5-nano',
		'gpt-4.1',
		'gpt-4.1-mini',
		'gpt-4.1-nano',
		'gpt-4o',
		'gpt-4o-mini',
	];
	if ( ! in_array( $model, $allowed_models, true ) ) {
		$model = 'gpt-4o-mini';
	}

	return [
		'api_key'            => sanitize_text_field( $key ),
		'model'              => $model,
		'enrich_feed_images' => ! empty( $input['enrich_feed_images'] ) ? 1 : 0,
	];
}

function field_api_key(): void {
	$settings = get_settings();
	printf(
		'<input type="password" name="%1$s[api_key]" value="%2$s" class="regular-text" autocomplete="new-password" /><p class="description">%3$s</p>',
		esc_attr( OPTION_KEY ),
		esc_attr( $settings['api_key'] ),
		esc_html__( 'Get one at https://platform.openai.com/api-keys', 'bcc-image-ocr-with-openai' )
	);
}

function field_model(): void {
	$settings = get_settings();
	$models   = [
		'gpt-5'        => 'gpt-5 (best quality, slower & most expensive)',
		'gpt-5-mini'   => 'gpt-5-mini (great quality, balanced cost)',
		'gpt-5-nano'   => 'gpt-5-nano (fastest & cheapest in the GPT-5 family)',
		'gpt-4.1'      => 'gpt-4.1',
		'gpt-4.1-mini' => 'gpt-4.1-mini',
		'gpt-4.1-nano' => 'gpt-4.1-nano',
		'gpt-4o'       => 'gpt-4o (legacy)',
		'gpt-4o-mini'  => 'gpt-4o-mini (legacy, cheapest)',
	];
	echo '<select name="' . esc_attr( OPTION_KEY ) . '[model]">';
	foreach ( $models as $value => $label ) {
		printf(
			'<option value="%1$s" %2$s>%3$s</option>',
			esc_attr( $value ),
			selected( $settings['model'], $value, false ),
			esc_html( $label )
		);
	}
	echo '</select>';
}

function field_enrich_feed_images(): void {
	$settings = get_settings();
	$enabled  = ! empty( $settings['enrich_feed_images'] );
	printf(
		'<label><input type="checkbox" name="%1$s[enrich_feed_images]" value="1" %2$s /> %3$s</label><p class="description">%4$s</p>',
		esc_attr( OPTION_KEY ),
		checked( $enabled, true, false ),
		esc_html__( 'Include alt text, caption and image description in the RSS feed', 'bcc-image-ocr-with-openai' ),
		esc_html__( 'When enabled, every image block in RSS output is enriched with the attachment\'s alt text, caption and description.', 'bcc-image-ocr-with-openai' )
	);
}

function render_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'BCC – Image OCR with OpenAI', 'bcc-image-ocr-with-openai' ); ?></h1>
		<form method="post" action="options.php">
			<?php
			settings_fields( 'openai_ocr' );
			do_settings_sections( 'bcc-image-ocr-with-openai' );
			submit_button();
			?>
		</form>
	</div>
	<?php
}
