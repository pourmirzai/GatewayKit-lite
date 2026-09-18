/**
 * GatewayKit Standalone Frontend Form Logic
 *
 * Handles donation presets, custom amounts, gateway card selection,
 * cache-safe nonce refreshing, and AJAX payment submission.
 */
(function ($) {
	'use strict';

	$(document).ready(function () {
		initForms();
	});

	function initForms() {
		$('.gatewaykit-payment-form').each(function () {
			var $form = $(this);
			if ($form.data('gk-initialized')) {
				return;
			}
			$form.data('gk-initialized', true);

			var $wrap = $form.closest('.gatewaykit-form-wrap');
			var $amountInput = $form.find('.gk-amount-input');
			var $customWrapper = $form.find('.gk-custom-amount-wrapper');
			var $customInput = $form.find('.gk-custom-amount-input');
			var $presetPills = $form.find('.gk-preset-pill');
			var $gatewayCards = $form.find('.gk-gateway-card');
			var $notices = $form.find('.gk-form-notices');
			var $submitBtn = $form.find('.gk-submit-btn');
			var $spinner = $submitBtn.find('.gk-spinner');
			var $btnText = $submitBtn.find('.gk-btn-text');
			var originalBtnText = $btnText.text();

			// Check URL for cancellation or failure notices.
			if (window.location && window.location.search) {
				var urlParams = new URLSearchParams(window.location.search);
				var noticeParam = urlParams.get('gatewaykit_notice');
				if (noticeParam === 'cancelled') {
					showNotice($notices, (typeof GatewayKitFormConfig !== 'undefined' && GatewayKitFormConfig.i18n && GatewayKitFormConfig.i18n.payment_cancelled) || 'Payment was cancelled. You can try again below.', 'info');
				} else if (noticeParam === 'failed') {
					showNotice($notices, (typeof GatewayKitFormConfig !== 'undefined' && GatewayKitFormConfig.i18n && GatewayKitFormConfig.i18n.payment_failed) || 'Payment failed. Please try again or use another payment method.', 'error');
				}
			}

			// Preset Pill Click
			$presetPills.on('click', function (e) {
				e.preventDefault();
				var $pill = $(this);
				var amount = $pill.data('amount');

				$presetPills.removeClass('is-selected');
				$pill.addClass('is-selected');

				if (amount === 'custom') {
					$customWrapper.slideDown(150);
					$customInput.focus();
					var customVal = parseFloat($customInput.val()) || 0;
					$amountInput.val(customVal);
					var $amountValC = $form.find('.gk-amount-value');
					if ($amountValC.length) {
						var symC = $form.data('currency-symbol') || '$';
						var curC = $form.data('currency') || 'USD';
						$amountValC.text(symC + customVal.toFixed(2) + ' ' + curC);
						$amountValC.removeClass('gk-amount-pulse');
					}
				} else {
					$customWrapper.slideUp(150);
					$amountInput.val(amount);
					var $amountValP = $form.find('.gk-amount-value');
					if ($amountValP.length) {
						var symP = $form.data('currency-symbol') || '$';
						var curP = $form.data('currency') || 'USD';
						$amountValP.text(symP + (parseFloat(amount) || 0).toFixed(2) + ' ' + curP);
						$amountValP.removeClass('gk-amount-pulse');
					}
				}
			});

			// Custom Amount Input Change
			$customInput.on('input change', function () {
				var val = parseFloat($(this).val()) || 0;
				$amountInput.val(val);
				var $amountVal = $form.find('.gk-amount-value');
				if ($amountVal.length) {
					var sym = $form.data('currency-symbol') || '$';
					var cur = $form.data('currency') || 'USD';
					$amountVal.text(sym + val.toFixed(2) + ' ' + cur);
					$amountVal.removeClass('gk-amount-pulse');
				}
			});

			// Field Option Pricing (select / radio with data-price)
			$form.on('change', 'select, input[type="radio"]', function () {
				var $el = $(this);
				var price = null;
				if ($el.is('select')) {
					var $selectedOpt = $el.find('option:selected');
					price = $selectedOpt.data('price');
				} else if ($el.is(':checked')) {
					price = $el.data('price');
				}

				if (price !== undefined && price !== null && price !== '') {
					var numPrice = parseFloat(price) || 0;
					if (numPrice > 0) {
						$amountInput.val(numPrice);
						var $amountVal = $form.find('.gk-amount-value');
						if ($amountVal.length) {
							var sym = $form.data('currency-symbol') || '$';
							var cur = $form.data('currency') || 'USD';
							$amountVal.text(sym + numPrice.toFixed(2) + ' ' + cur);
						}
						// If preset pills exist, select matching pill
						$presetPills.each(function () {
							var pillAmt = parseFloat($(this).data('amount')) || 0;
							if (Math.abs(pillAmt - numPrice) < 0.009) {
								$presetPills.removeClass('is-selected');
								$(this).addClass('is-selected');
							}
						});
					}
				}
			});

			// If an initial select option or radio has data-price, sync amount
			var $initialSelected = $form.find('select option:selected[data-price], input[type="radio"]:checked[data-price]');
			if ($initialSelected.length) {
				var initPrice = parseFloat($initialSelected.first().data('price')) || 0;
				if (initPrice > 0) {
					$amountInput.val(initPrice);
					var $initAmountVal = $form.find('.gk-amount-value');
					if ($initAmountVal.length) {
						var initSym = $form.data('currency-symbol') || '$';
						var initCur = $form.data('currency') || 'USD';
						$initAmountVal.text(initSym + initPrice.toFixed(2) + ' ' + initCur);
					}
				}
			}

			// Gateway Card Selection
			$gatewayCards.on('click', function () {
				var $radio = $(this).find('input[type="radio"]');
				$radio.prop('checked', true);
				$gatewayCards.removeClass('is-selected');
				$(this).addClass('is-selected');
			});

			// Discount Code Live AJAX Validation
			$form.on('click', '.gk-discount-apply-btn', function (e) {
				e.preventDefault();
				var $btn = $(this);
				var inputId = $btn.data('input-id');
				var $input = inputId ? $('#' + inputId) : $form.find('.gk-discount-input');
				var $feedback = $btn.closest('.gk-discount-group').find('.gk-discount-feedback');
				var code = $.trim($input.val());
				var amount = parseFloat($amountInput.val()) || 0;

				if (!code) {
					$feedback.removeClass('is-success').addClass('is-error').text(
						(typeof GatewayKitFormConfig !== 'undefined' && GatewayKitFormConfig.i18n && GatewayKitFormConfig.i18n.enter_code) || 'Please enter a discount code.'
					).slideDown(150);
					return;
				}

				if (amount <= 0) {
					$feedback.removeClass('is-success').addClass('is-error').text(
						(typeof GatewayKitFormConfig !== 'undefined' && GatewayKitFormConfig.i18n && GatewayKitFormConfig.i18n.enter_amount) || 'Please enter a valid amount.'
					).slideDown(150);
					return;
				}

				var origText = $btn.text();
				$btn.prop('disabled', true).text(
					(typeof GatewayKitFormConfig !== 'undefined' && GatewayKitFormConfig.i18n && GatewayKitFormConfig.i18n.applying) || 'Applying...'
				);
				$feedback.slideUp(100);

				$.post(GatewayKitFormConfig.ajaxurl, {
					action: 'gatewaykit_validate_discount_code',
					code: code,
					amount: amount,
					nonce: (GatewayKitFormConfig && GatewayKitFormConfig.discount_nonce) || ''
				})
				.done(function (res) {
					if (res && res.success && res.data) {
						var dAmount = parseFloat(res.data.discount_amount) || 0;
						var fAmount = parseFloat(res.data.final_amount) || 0;
						var sym = $form.data('currency-symbol') || '$';
						var cur = $form.data('currency') || 'USD';
						var msg = (typeof GatewayKitFormConfig !== 'undefined' && GatewayKitFormConfig.i18n && GatewayKitFormConfig.i18n.discount_applied) || 'Discount applied:';
						msg += ' -' + sym + dAmount.toFixed(2) + ' ' + cur;
						$feedback.removeClass('is-error').addClass('is-success').text(msg).slideDown(150);

						var $amountVal = $form.find('.gk-amount-value');
						if ($amountVal.length) {
							$amountVal.html(
								'<span class="gk-amount-original">' + sym + amount.toFixed(2) + '</span>' +
								'<span class="gk-amount-final">' + sym + fAmount.toFixed(2) + ' ' + cur + '</span>'
							);
							$amountVal.removeClass('gk-amount-pulse');
							void $amountVal[0].offsetWidth; // trigger reflow to restart CSS animation
							$amountVal.addClass('gk-amount-pulse');
						}
					} else {
						var errMsg = (res && res.data) ? res.data : 'Invalid discount code.';
						$feedback.removeClass('is-success').addClass('is-error').text(errMsg).slideDown(150);
						var $amountValErr = $form.find('.gk-amount-value');
						if ($amountValErr.length) {
							var symErr = $form.data('currency-symbol') || '$';
							var curErr = $form.data('currency') || 'USD';
							$amountValErr.text(symErr + amount.toFixed(2) + ' ' + curErr);
							$amountValErr.removeClass('gk-amount-pulse');
						}
					}
				})
				.fail(function (xhr) {
					var errMsg = (xhr.responseJSON && xhr.responseJSON.data) ? xhr.responseJSON.data : 'Error validating discount code.';
					$feedback.removeClass('is-success').addClass('is-error').text(errMsg).slideDown(150);
				})
				.always(function () {
					$btn.prop('disabled', false).text(origText);
				});
			});

			// Enter key in discount input triggers Apply
			$form.on('keydown', '.gk-discount-input', function (e) {
				if (e.key === 'Enter' || e.keyCode === 13) {
					e.preventDefault();
					$(this).closest('.gk-discount-input-wrapper').find('.gk-discount-apply-btn').trigger('click');
				}
			});

			// Clear feedback and restore amount if discount input is emptied
			$form.on('input', '.gk-discount-input', function () {
				if (!$.trim($(this).val())) {
					var $fb = $(this).closest('.gk-discount-group').find('.gk-discount-feedback');
					$fb.slideUp(100).removeClass('is-success is-error');
					var $amtVal = $form.find('.gk-amount-value');
					if ($amtVal.length) {
						var curAmt = parseFloat($amountInput.val()) || 0;
						var s = $form.data('currency-symbol') || '$';
						var c = $form.data('currency') || 'USD';
						$amtVal.text(s + curAmt.toFixed(2) + ' ' + c);
						$amtVal.removeClass('gk-amount-pulse');
					}
				}
			});

			// Form Submission
			$form.on('submit', function (e) {
				e.preventDefault();

				var gateway = $form.find('input[name="gateway"]:checked').val();
				if (!gateway) {
					showNotice($notices, GatewayKitFormConfig.i18n.select_gateway || 'Please select a payment method.', 'error');
					return;
				}

				var amount = parseFloat($amountInput.val()) || 0;
				if (amount <= 0) {
					showNotice($notices, GatewayKitFormConfig.i18n.enter_amount || 'Please enter a valid amount.', 'error');
					return;
				}

				// Set Loading State
				setLoading(true);
				$notices.hide().empty();

				// Step 1: Request fresh cache-safe nonce (context: 'form')
				$.post(GatewayKitFormConfig.ajaxurl, {
					action: 'gatewaykit_get_nonce',
					context: 'form'
				})
				.done(function (nonceRes) {
					var nonce = (nonceRes && nonceRes.success && nonceRes.data && nonceRes.data.nonce)
						? nonceRes.data.nonce
						: $form.find('input[name="gatewaykit_nonce"]').val() || GatewayKitFormConfig.nonce;

					$form.find('input[name="gatewaykit_nonce"]').val(nonce);

					// Step 2: Post Payment Request
					var formData = $form.serializeArray();
					formData.push({ name: '_wpnonce', value: nonce });
					if (window.location && window.location.href) {
						formData.push({ name: 'form_page_url', value: window.location.href });
					}

					$.ajax({
						url: GatewayKitFormConfig.ajaxurl,
						type: 'POST',
						data: formData,
						dataType: 'json'
					})
					.done(function (res) {
						if (res && res.success) {
							var redirectUrl = null;
							if (res.data && res.data.redirect_url) {
								redirectUrl = res.data.redirect_url;
							} else if (res.data && res.data.data && res.data.data.redirect_url) {
								redirectUrl = res.data.data.redirect_url;
							}

							if (redirectUrl) {
								window.location.href = redirectUrl;
								return;
							}

							showNotice($notices, res.message || 'Payment initiated successfully.', 'success');
							setLoading(false);
						} else {
							var errMsg = (res && res.data) ? res.data : (GatewayKitFormConfig.i18n.generic_error || 'An error occurred.');
							showNotice($notices, errMsg, 'error');
							setLoading(false);
						}
					})
					.fail(function (xhr) {
						var errMsg = GatewayKitFormConfig.i18n.generic_error || 'Payment processing failed. Please try again.';
						if (xhr.responseJSON && xhr.responseJSON.data) {
							errMsg = xhr.responseJSON.data;
						}
						showNotice($notices, errMsg, 'error');
						setLoading(false);
					});
				})
				.fail(function () {
					showNotice($notices, GatewayKitFormConfig.i18n.security_error || 'Security check failed. Please reload the page.', 'error');
					setLoading(false);
				});
			});

			function setLoading(loading) {
				if (loading) {
					$submitBtn.prop('disabled', true);
					$spinner.show();
					$btnText.text(GatewayKitFormConfig.i18n.processing || 'Processing...');
				} else {
					$submitBtn.prop('disabled', false);
					$spinner.hide();
					$btnText.text(originalBtnText);
				}
			}

			function showNotice($container, msg, type) {
				$container.removeClass('gk-notice-error gk-notice-success gk-notice-info')
					.addClass('gk-notice gk-notice-' + (type || 'error'))
					.text(msg)
					.slideDown(200);
			}
		});
	}
})(jQuery);
