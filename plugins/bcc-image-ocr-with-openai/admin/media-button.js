/* global jQuery, BCCImageOCRWithOpenAI, wp */
(function ($) {
	'use strict';

	const BUTTON_CLASS = 'bcc-openai-ocr-button';

	// Selectors for the classic attachment edit screen (post.php?post=…&action=edit).
	const EDIT_SCREEN = {
		altInput:     '#attachment_alt',
		captionInput: '#attachment_caption',
		descTextarea: '#attachment_content'
	};

	// Selectors used inside the Media Library / Attachment Details modal.
	const MODAL = {
		root:        '.media-modal',
		details:     '.attachment-details',
		actions:     '.attachment-actions',
		altInput:    '[data-setting="alt"] input, [data-setting="alt"] textarea',
		captionTa:   '[data-setting="caption"] textarea, [data-setting="caption"] input',
		descTa:      '[data-setting="description"] textarea, [data-setting="description"] input'
	};

	function createButton() {
		return $('<button>', {
			type: 'button',
			class: 'button button-primary ' + BUTTON_CLASS,
			text: BCCImageOCRWithOpenAI.i18n.button,
			css: { marginLeft: '6px' }
		});
	}

	function setFieldValue($field, value) {
		if (!$field.length) return;
		$field.val(value).trigger('change').trigger('input');
	}

	function getContextFor($btn) {
		const $details = $btn.closest(MODAL.details);
		if ($details.length) {
			return { kind: 'modal', $details: $details };
		}
		return { kind: 'edit' };
	}

	function getAttachmentId(ctx) {
		if (ctx.kind === 'modal') {
			// Most reliable: the attachment-details element carries data-id.
			let id = parseInt(ctx.$details.attr('data-id'), 10);
			if (id) return id;

			// Fallback: ask the active media frame for the current selection.
			try {
				if (window.wp && wp.media && wp.media.frame) {
					const sel = wp.media.frame.state() && wp.media.frame.state().get('selection');
					if (sel && sel.first()) {
						id = parseInt(sel.first().id, 10);
						if (id) return id;
					}
				}
			} catch (e) { /* ignore */ }

			// Fallback: the Media Library uses ?item=<id> in the URL when an
			// attachment is opened in the modal (upload.php?item=123).
			try {
				const params = new URL(window.location.href).searchParams;
				const item = parseInt(params.get('item') || '', 10);
				if (item) return item;
			} catch (e) { /* ignore */ }

			// Fallback: hash often contains "#?item=123" while the modal is open.
			try {
				const hash = (window.location.hash || '').replace(/^#/, '');
				const m = hash.match(/(?:^|[?&])item=(\d+)/);
				if (m) {
					const hashItem = parseInt(m[1], 10);
					if (hashItem) return hashItem;
				}
			} catch (e) { /* ignore */ }

			// Last resort: currently selected thumbnail in the grid.
			const selectedId = parseInt($('.media-modal .attachments .attachment.selected, .media-modal .attachments .attachment.details').first().attr('data-id') || '', 10);
			if (selectedId) return selectedId;

			return 0;
		}

		return parseInt(BCCImageOCRWithOpenAI.attachment_id, 10) || 0;
	}

	function getFieldsFor(ctx) {
		if (ctx.kind === 'modal') {
			return {
				$desc:    ctx.$details.find(MODAL.descTa).first(),
				$alt:     ctx.$details.find(MODAL.altInput).first(),
				$caption: ctx.$details.find(MODAL.captionTa).first()
			};
		}
		return {
			$desc:    $(EDIT_SCREEN.descTextarea),
			$alt:     $(EDIT_SCREEN.altInput),
			$caption: $(EDIT_SCREEN.captionInput)
		};
	}

	function fieldsHaveContent(fields) {
		return [fields.$desc, fields.$alt, fields.$caption].some(function ($f) {
			return $f && $f.length && $.trim($f.val() || '') !== '';
		});
	}

	function fillFields(ctx, fields, data) {
		setFieldValue(fields.$desc,    data.description || '');
		setFieldValue(fields.$alt,     data.alt_text    || '');
		setFieldValue(fields.$caption, data.caption     || '');

		// In modal context, also push values into the Backbone model so the
		// modal's own auto-save (attachment.save()) persists the change and
		// the user does not need to click anything else.
		if (ctx.kind === 'modal') {
			try {
				const id = getAttachmentId(ctx);
				if (id && window.wp && wp.media && wp.media.attachment) {
					const model = wp.media.attachment(id);
					const attrs = {
						alt:         data.alt_text    || '',
						caption:     data.caption     || '',
						description: data.description || ''
					};
					model.set(attrs);
					if (typeof model.save === 'function') {
						model.save(attrs);
					}
				}
			} catch (e) { /* ignore – server-side save already happened */ }
		}
	}

	function detectLang() {
		// 1) URL ?lang= takes precedence (matches WPML/Polylang admin top-bar).
		let lang = '';
		try {
			lang = new URL(window.location.href).searchParams.get('lang') || '';
		} catch (e) { /* ignore */ }
		if (lang && lang !== 'all') return lang;

		// 2) Server-side resolved value (WPML/Polylang/locale).
		if (BCCImageOCRWithOpenAI.current_lang) {
			return String(BCCImageOCRWithOpenAI.current_lang);
		}

		// 3) WPML's icl_vars (loaded by the sitepress-js-extra script).
		try {
			if (typeof window.icl_vars === 'object' && window.icl_vars && window.icl_vars.current_language) {
				return String(window.icl_vars.current_language);
			}
		} catch (e) { /* ignore */ }

		// 4) WPML admin language switcher in the top-bar.
		const $wpml = $('#wp-admin-bar-WPML_ALS .ab-item').first();
		if ($wpml.length) {
			const href = $wpml.attr('href') || '';
			const fromHref = (href.split('lang=')[1] || '').split('&')[0];
			if (fromHref) return fromHref;
		}

		return '';
	}

	function run($btn) {
		const ctx = getContextFor($btn);
		const attachmentId = getAttachmentId(ctx);
		if (!attachmentId) {
			window.alert(BCCImageOCRWithOpenAI.i18n.failed + ' ' + BCCImageOCRWithOpenAI.i18n.no_id);
			return;
		}

		const fields = getFieldsFor(ctx);
		if (fieldsHaveContent(fields) && !window.confirm(BCCImageOCRWithOpenAI.i18n.confirm_over)) {
			return;
		}

		const originalLabel = $btn.text();
		$btn.prop('disabled', true).text(BCCImageOCRWithOpenAI.i18n.working);

		$.post(BCCImageOCRWithOpenAI.ajax_url, {
			action: BCCImageOCRWithOpenAI.action,
			nonce: BCCImageOCRWithOpenAI.nonce,
			attachment_id: attachmentId,
			lang: detectLang()
		})
			.done(function (response) {
				if (response && response.success) {
					fillFields(ctx, fields, response.data || {});
					$btn.text(BCCImageOCRWithOpenAI.i18n.done);
					window.setTimeout(function () {
						$btn.prop('disabled', false).text(originalLabel);
					}, 2500);
				} else {
					const msg = (response && response.data && response.data.message) || 'Unknown error';
					window.alert(BCCImageOCRWithOpenAI.i18n.failed + ' ' + msg);
					$btn.prop('disabled', false).text(originalLabel);
				}
			})
			.fail(function (xhr) {
				const msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || xhr.statusText || 'Request failed';
				window.alert(BCCImageOCRWithOpenAI.i18n.failed + ' ' + msg);
				$btn.prop('disabled', false).text(originalLabel);
			});
	}

	// ---- Insertion: classic attachment edit screen ----------------------------

	function insertButtonOnEditScreen() {
		// Don't insert twice on this screen.
		if ($('.wp_attachment_image').find('.' + BUTTON_CLASS).length ||
			$('#wpbody-content').find('> .' + BUTTON_CLASS).length) {
			return true;
		}

		const id = parseInt(BCCImageOCRWithOpenAI.attachment_id, 10) || 0;
		const $editBtn = id ? $('#imgedit-open-btn-' + id) : $();

		if ($editBtn.length) {
			const $btn = createButton();
			$editBtn.after($btn);
			$btn.on('click', function (e) { e.preventDefault(); run($btn); });
			return true;
		}

		const $desc = $(EDIT_SCREEN.descTextarea);
		if ($desc.length) {
			const $btn = createButton().css({ marginLeft: 0, marginBottom: '6px', display: 'inline-block' });
			$desc.before($btn);
			$btn.on('click', function (e) { e.preventDefault(); run($btn); });
			return true;
		}

		return false;
	}

	// ---- Insertion: media library / attachment details modal -----------------

	function insertButtonInModal($details) {
		if (!$details || !$details.length) return false;
		if ($details.find('.' + BUTTON_CLASS).length) return true;

		const $actions = $details.find(MODAL.actions).first();
		if (!$actions.length) return false;

		// Only show for images.
		const dataType = ($details.attr('data-type') || '').toLowerCase();
		if (dataType && dataType !== 'image') return false;

		const $btn = createButton();
		$actions.append($btn);
		$btn.on('click', function (e) { e.preventDefault(); run($btn); });
		return true;
	}

	function refreshModalButtons() {
		$('.media-modal').find(MODAL.details).each(function () {
			insertButtonInModal($(this));
		});
	}

	// ---- Bootstrap -----------------------------------------------------------

	$(function () {
		// Edit screen: try once, then briefly retry for late DOM.
		if (!insertButtonOnEditScreen()) {
			let attempts = 0;
			const interval = window.setInterval(function () {
				attempts += 1;
				if (insertButtonOnEditScreen() || attempts > 30) {
					window.clearInterval(interval);
				}
			}, 250);
		}

		// Modal: observe the body for the modal's attachment-details panel and
		// refresh on selection changes.
		refreshModalButtons();

		// Re-check whenever a media item is clicked or the modal content updates.
		$(document).on('click', '.media-modal .attachments .attachment, .media-modal .media-button', function () {
			window.setTimeout(refreshModalButtons, 30);
		});

		// MutationObserver: catch frame swaps (Upload Files vs. Media Library tabs,
		// next/previous arrows, etc.) without polling forever.
		if (typeof MutationObserver !== 'undefined') {
			const observer = new MutationObserver(function () {
				refreshModalButtons();
			});
			$('body').each(function () {
				observer.observe(this, { childList: true, subtree: true });
			});
		}
	});

})(jQuery);
