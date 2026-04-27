/* global jQuery, BCCImageOCRWithOpenAI */
(function ($) {
	'use strict';

	const SELECTORS = {
		editImageBtn: '.wp_attachment_image .button, #imgedit-open-btn-' + BCCImageOCRWithOpenAI.attachment_id,
		altInput:     '#attachment_alt',
		captionInput: '#attachment_caption',
		descTextarea: '#attachment_content'
	};

	function createButton() {
		return $('<button>', {
			type: 'button',
			id: 'openai-ocr-button',
			class: 'button button-primary',
			text: BCCImageOCRWithOpenAI.i18n.button,
			css: { marginLeft: '6px' }
		});
	}

	function setFieldValue($field, value) {
		if (!$field.length) {
			return;
		}
		$field.val(value).trigger('change').trigger('input');
	}

	function fillFields(data) {
		setFieldValue($(SELECTORS.descTextarea),    data.description || '');
		setFieldValue($(SELECTORS.altInput),        data.alt_text    || '');
		setFieldValue($(SELECTORS.captionInput),    data.caption     || '');
	}

	function fieldsHaveContent() {
		return [SELECTORS.descTextarea, SELECTORS.altInput, SELECTORS.captionInput]
			.some(function (sel) {
				const $f = $(sel);
				return $f.length && $.trim($f.val() || '') !== '';
			});
	}

	function run($btn) {
		if (fieldsHaveContent() && !window.confirm(BCCImageOCRWithOpenAI.i18n.confirm_over)) {
			return;
		}

		const originalLabel = $btn.text();
		$btn.prop('disabled', true).text(BCCImageOCRWithOpenAI.i18n.working);

		// Detect the WPML / Polylang language selected in the admin top-bar.
		// 1. The current admin URL usually carries ?lang=xx.
		// 2. Otherwise look for the WPML admin language switcher in the top-bar.
		let lang = '';
		try {
			lang = new URL(window.location.href).searchParams.get('lang') || '';
		} catch (e) { /* ignore */ }
		if (!lang) {
			const $wpmlActive = jQuery('#wp-admin-bar-WPML_ALS .ab-item').first();
			if ($wpmlActive.length) {
				lang = ($wpmlActive.attr('href') || '').split('lang=')[1] || '';
				lang = lang.split('&')[0];
			}
		}

		$.post(BCCImageOCRWithOpenAI.ajax_url, {
			action: BCCImageOCRWithOpenAI.action,
			nonce: BCCImageOCRWithOpenAI.nonce,
			attachment_id: BCCImageOCRWithOpenAI.attachment_id,
			lang: lang
		})
			.done(function (response) {
				if (response && response.success) {
					fillFields(response.data || {});
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

	function insertButton() {
		if ($('#openai-ocr-button').length) {
			return true;
		}

		// Preferred location: directly after the "Edit Image" button under the thumbnail.
		const $editBtn = $('#imgedit-open-btn-' + BCCImageOCRWithOpenAI.attachment_id);
		if ($editBtn.length) {
			const $btn = createButton();
			$editBtn.after($btn);
			$btn.on('click', function (e) { e.preventDefault(); run($btn); });
			return true;
		}

		// Fallback: above the description textarea.
		const $desc = $(SELECTORS.descTextarea);
		if ($desc.length) {
			const $btn = createButton().css({ marginLeft: 0, marginBottom: '6px', display: 'inline-block' });
			$desc.before($btn);
			$btn.on('click', function (e) { e.preventDefault(); run($btn); });
			return true;
		}

		return false;
	}

	$(function () {
		if (insertButton()) {
			return;
		}
		// Some elements load asynchronously; retry briefly.
		let attempts = 0;
		const interval = window.setInterval(function () {
			attempts += 1;
			if (insertButton() || attempts > 30) {
				window.clearInterval(interval);
			}
		}, 250);
	});

})(jQuery);
