(function ($) {
	$(document).on('click', '#nxp-register-receiver', function (e) {
		e.preventDefault();
		var $btn = $(this);
		var $result = $('#nxp-register-result');
		$btn.prop('disabled', true);
		$result.text('...');
		$.post(nxp_admin.ajax_url, {
			action: 'nxp_register_receiver',
			nonce: nxp_admin.nonce
		}).done(function (resp) {
			if (resp && resp.success) {
				$result.text(resp.data.message || 'OK');
			} else {
				$result.text((resp && resp.data && resp.data.message) ? resp.data.message : 'Failed');
			}
		}).fail(function () {
			$result.text('Request failed');
		}).always(function () {
			$btn.prop('disabled', false);
		});
	});
})(jQuery);
