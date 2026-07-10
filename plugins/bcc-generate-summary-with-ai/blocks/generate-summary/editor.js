( function () {
	'use strict';

	var registerBlockType = wp.blocks.registerBlockType;
	var el                = wp.element.createElement;
	var useState          = wp.element.useState;
	var useBlockProps     = wp.blockEditor.useBlockProps;
	var RichText          = wp.blockEditor.RichText;
	var BlockControls     = wp.blockEditor.BlockControls;
	var ToolbarGroup      = wp.components.ToolbarGroup;
	var ToolbarButton     = wp.components.ToolbarButton;
	var Button            = wp.components.Button;
	var Spinner           = wp.components.Spinner;
	var useSelect         = wp.data.useSelect;
	var apiFetch          = wp.apiFetch;
	var i18n              = BCCGenerateSummary.i18n;

	registerBlockType( 'bcc-generate-summary-with-ai/generate-summary', {
		apiVersion: 3,

		edit: function ( props ) {
			var content       = props.attributes.content;
			var setAttributes = props.setAttributes;
			var blockProps    = useBlockProps( { className: 'bcc-generate-summary-block' } );

			var _s1 = useState( false );
			var loading    = _s1[0];
			var setLoading = _s1[1];

			var _s2 = useState( '' );
			var error    = _s2[0];
			var setError = _s2[1];

			var postId = useSelect( function ( select ) {
				return select( 'core/editor' ).getCurrentPostId();
			} );

			function generate() {
				setLoading( true );
				setError( '' );

				apiFetch( {
					path:   '/bcc-generate-summary-with-ai/v1/generate',
					method: 'POST',
					data:   { post_id: postId },
				} ).then( function ( result ) {
					setAttributes( { content: result.summary || '' } );
				} ).catch( function ( err ) {
					setError( ( err && err.message ) ? err.message : i18n.failed );
				} ).finally( function () {
					setLoading( false );
				} );
			}

			return el( 'div', blockProps,
				el( BlockControls, null,
					el( ToolbarGroup, null,
						el( ToolbarButton, {
							icon:     'update',
							label:    i18n.generate,
							onClick:  generate,
							isBusy:   loading,
							disabled: loading,
						} )
					)
				),

				// Static title — matches PHP-rendered output
				el( 'div', { className: 'bcc-generate-summary-block__header' },
					el( 'span', { className: 'bcc-generate-summary-block__title' }, i18n.block_title )
				),

				el( 'div', { className: 'bcc-generate-summary-block__body' },
					! content && ! loading && el( 'div', { className: 'bcc-generate-summary-block__placeholder' },
						el( Button, { variant: 'secondary', onClick: generate, icon: 'edit-page' },
							i18n.generate
						)
					),

					loading && el( 'div', { className: 'bcc-generate-summary-block__loading' },
						el( Spinner, null ),
						el( 'span', null, i18n.generating )
					),

					error && el( 'div', { className: 'bcc-generate-summary-block__error' }, error ),

					el( RichText, {
						tagName:     'ul',
						multiline:   'li',
						className:   'bcc-generate-summary-block__content',
						value:       content,
						onChange:    function ( val ) { setAttributes( { content: val } ); },
						placeholder: i18n.placeholder,
					} )
				)
			);
		},

		// Dynamic block — PHP render_callback handles the frontend output.
		save: function () { return null; },
	} );
} )();
