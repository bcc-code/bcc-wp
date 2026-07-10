( function () {
	'use strict';

	var registerPlugin  = wp.plugins.registerPlugin;
	var el              = wp.element.createElement;
	var useState        = wp.element.useState;
	var Fragment        = wp.element.Fragment;
	var Button          = wp.components.Button;
	var TextareaControl = wp.components.TextareaControl;
	var Notice          = wp.components.Notice;
	var useSelect       = wp.data.useSelect;
	var useDispatch     = wp.data.useDispatch;
	var apiFetch        = wp.apiFetch;
	var i18n            = BCCGenerateSummary.i18n;

	// PluginDocumentSettingPanel moved to wp.editor in WP 6.6+; fall back to wp.editPost.
	var PluginDocumentSettingPanel =
		( wp.editor && wp.editor.PluginDocumentSettingPanel ) ||
		( wp.editPost && wp.editPost.PluginDocumentSettingPanel );

	if ( ! PluginDocumentSettingPanel ) {
		return;
	}

	function GenerateSummaryPanel() {
		var _useState1     = useState( '' );
		var summary        = _useState1[ 0 ];
		var setSummary     = _useState1[ 1 ];

		var _useState2     = useState( false );
		var loading        = _useState2[ 0 ];
		var setLoading     = _useState2[ 1 ];

		var _useState3     = useState( '' );
		var error          = _useState3[ 0 ];
		var setError       = _useState3[ 1 ];

		var _useState4     = useState( false );
		var excerptSet     = _useState4[ 0 ];
		var setExcerptSet  = _useState4[ 1 ];

		var postId   = useSelect( function ( select ) {
			return select( 'core/editor' ).getCurrentPostId();
		} );
		var editPost = useDispatch( 'core/editor' ).editPost;

		function generate() {
			setLoading( true );
			setError( '' );
			setSummary( '' );
			setExcerptSet( false );

			apiFetch( {
				path:   '/bcc-generate-summary-with-ai/v1/generate',
				method: 'POST',
				data:   { post_id: postId },
			} ).then( function ( result ) {
				setSummary( result.summary || '' );
			} ).catch( function ( err ) {
				setError( ( err && err.message ) ? err.message : i18n.failed );
			} ).finally( function () {
				setLoading( false );
			} );
		}

		function useAsExcerpt() {
			editPost( { excerpt: summary } );
			setExcerptSet( true );
		}

		return el( PluginDocumentSettingPanel, {
				name:  'bcc-generate-summary-panel',
				title: i18n.panel_title,
				icon:  'edit-page',
			},
			el( 'div', { style: { display: 'flex', flexDirection: 'column', gap: '12px' } },
				el( Button, {
					variant:  'secondary',
					onClick:  generate,
					isBusy:   loading,
					disabled: loading || ! postId,
				}, loading ? i18n.generating : i18n.generate ),

				error && el( Notice, {
					status:        'error',
					isDismissible: true,
					onRemove:      function () { setError( '' ); },
				}, error ),

				excerptSet && el( Notice, {
					status:        'success',
					isDismissible: true,
					onRemove:      function () { setExcerptSet( false ); },
				}, i18n.excerpt_set ),

				summary && el( Fragment, null,
					el( TextareaControl, {
						label:    i18n.summary_label,
						value:    summary,
						onChange: setSummary,
						rows:     4,
					} ),
					el( Button, {
						variant: 'secondary',
						onClick: useAsExcerpt,
					}, i18n.use_as_excerpt )
				)
			)
		);
	}

	registerPlugin( 'bcc-generate-summary', {
		render: GenerateSummaryPanel,
		icon:   'edit-page',
	} );
} )();
