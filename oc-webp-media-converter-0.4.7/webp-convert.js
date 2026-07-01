(function($) {
	$(document).on('click', '.ocwc-media-convert-button', function(e) {
		e.preventDefault();

		var $button = $(this);
		var $wrap = $button.closest('.ocwc-media-conversion-fields');
		var $spinner = $wrap.find('.ocwc-media-convert-spinner');
		var $status = $wrap.find('.ocwc-media-convert-status');

		var attachmentId = parseInt($button.data('attachment-id'), 10) || parseInt($wrap.data('attachment-id'), 10);
		var quality = parseInt($wrap.find('.ocwc-media-quality').val(), 10);
		var maxWidth = parseInt($wrap.find('.ocwc-media-max-width').val(), 10);
		var updateRefs = $wrap.find('.ocwc-media-update-refs').is(':checked') ? 1 : 0;

		if (!attachmentId) {
			$status.css('color', '#b32d2e').text('Missing attachment ID.');
			return;
		}

		if (isNaN(quality)) {
			quality = 90;
		}

		if (quality < 1) {
			quality = 1;
		}

		if (quality > 100) {
			quality = 100;
		}

		if (isNaN(maxWidth) || maxWidth < 0) {
			maxWidth = 0;
		}

		if (!window.confirm('Convert this image to WebP and delete the original JPG/PNG file?')) {
			return;
		}

		$button.prop('disabled', true);
		$spinner.addClass('is-active');
		$status.css('color', '').text(OCWCWebPConvert.i18n.processing || 'Converting...');

		$.ajax({
			url: OCWCWebPConvert.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			data: {
				action: 'ocwc_convert_media_attachment_to_webp',
				nonce: OCWCWebPConvert.nonce,
				attachment_id: attachmentId,
				quality: quality,
				max_width: maxWidth,
				update_refs: updateRefs
			}
		})
		.done(function(response) {
			if (response && response.success) {
				var message = response.data && response.data.message ? response.data.message : (OCWCWebPConvert.i18n.success || 'Converted successfully.');

				$status.css('color', 'green').text(message + ' You may need to close and reopen the Media Library to see the refreshed thumbnail.');
				$button.text('Converted to WebP');

				if (window.wp && wp.media && wp.media.attachment) {
					var attachment = wp.media.attachment(attachmentId);
					if (attachment && attachment.fetch) {
						attachment.fetch();
					}
				}

				return;
			}

			var errorMessage = response && response.data && response.data.message ? response.data.message : (OCWCWebPConvert.i18n.error || 'Conversion failed.');
			$status.css('color', '#b32d2e').text(errorMessage);
			$button.prop('disabled', false);
		})
		.fail(function(xhr) {
			var errorMessage = OCWCWebPConvert.i18n.error || 'Conversion failed.';

			if (
				xhr.responseJSON &&
				xhr.responseJSON.data &&
				xhr.responseJSON.data.message
			) {
				errorMessage = xhr.responseJSON.data.message;
			}

			$status.css('color', '#b32d2e').text(errorMessage);
			$button.prop('disabled', false);
		})
		.always(function() {
			$spinner.removeClass('is-active');
		});
	});
})(jQuery);