<?php
/**
 * Settings page for the BCC – Generate summary with AI plugin.
 */

declare( strict_types=1 );

namespace BCC_Generate_Summary_With_AI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', static function (): void {
	add_options_page(
		__( 'BCC – Generate summary with AI', 'bcc-generate-summary-with-ai' ),
		__( 'BCC – Generate summary with AI', 'bcc-generate-summary-with-ai' ),
		'manage_options',
		'bcc-generate-summary-with-ai',
		__NAMESPACE__ . '\\render_settings_page'
	);
} );

add_action( 'admin_init', static function (): void {
	register_setting(
		'bcc_generate_summary_with_ai',
		OPTION_KEY,
		[
			'type'              => 'array',
			'sanitize_callback' => __NAMESPACE__ . '\\sanitize_settings',
			'default'           => [
				'api_key' => '',
				'model'   => 'gpt-4o-mini',
			],
		]
	);

	add_settings_section(
		'bcc_generate_summary_main',
		__( 'OpenAI credentials', 'bcc-generate-summary-with-ai' ),
		'__return_false',
		'bcc-generate-summary-with-ai'
	);

	add_settings_field(
		'api_key',
		__( 'API key', 'bcc-generate-summary-with-ai' ),
		__NAMESPACE__ . '\\field_api_key',
		'bcc-generate-summary-with-ai',
		'bcc_generate_summary_main'
	);

	add_settings_field(
		'model',
		__( 'Model', 'bcc-generate-summary-with-ai' ),
		__NAMESPACE__ . '\\field_model',
		'bcc-generate-summary-with-ai',
		'bcc_generate_summary_main'
	);
} );

/**
 * @param mixed $input
 * @return array{api_key:string, model:string}
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
		'api_key' => sanitize_text_field( $key ),
		'model'   => $model,
	];
}

function field_api_key(): void {
	$settings = get_settings();
	printf(
		'<input type="password" name="%1$s[api_key]" value="%2$s" class="regular-text" autocomplete="new-password" /><p class="description">%3$s</p>',
		esc_attr( OPTION_KEY ),
		esc_attr( $settings['api_key'] ),
		esc_html__( 'Get one at https://platform.openai.com/api-keys', 'bcc-generate-summary-with-ai' )
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

function render_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'BCC – Generate summary with AI', 'bcc-generate-summary-with-ai' ); ?></h1>
		<form method="post" action="options.php">
			<?php
			settings_fields( 'bcc_generate_summary_with_ai' );
			do_settings_sections( 'bcc-generate-summary-with-ai' );
			submit_button();
			?>
		</form>
	</div>
	<?php
}
