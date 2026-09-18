/**
 * GatewayKit Admin Form Builder Script
 *
 * @package GatewayKit
 */

/* global jQuery, gatewaykitFormBuilder */
(function($) {
	'use strict';

	$(document).ready(function() {
		var $list = $('#gk-fields-list');
		if (!$list.length) {
			return;
		}

		/**
		 * Generate a unique field ID.
		 *
		 * @return {string}
		 */
		function generateFieldId() {
			return 'f_' + Math.random().toString(36).substring(2, 8);
		}

		/**
		 * Reindex all field inputs so PHP receives an array with sequential indices.
		 */
		function reindexFields() {
			$list.find('.gk-field-row').each(function(index) {
				$(this).find('input, select, textarea').each(function() {
					var name = $(this).attr('name');
					if (name && name.indexOf('gk_config[fields]') !== -1) {
						var updated = name.replace(/gk_config\[fields\]\[[^\]]+\]/, 'gk_config[fields][' + index + ']');
						$(this).attr('name', updated);
					}
				});
			});
		}

		// Initialize jQuery UI Sortable.
		if ($.fn.sortable) {
			$list.sortable({
				handle: '.gk-field-handle',
				items: '.gk-field-row',
				placeholder: 'gk-field-placeholder',
				axis: 'y',
				update: function() {
					reindexFields();
				}
			});
		}

		// Move field row up.
		$list.on('click', '.gk-field-move-up', function(e) {
			e.preventDefault();
			e.stopPropagation();
			var $row = $(this).closest('.gk-field-row');
			var $prev = $row.prev('.gk-field-row');
			if ($prev.length) {
				$row.insertBefore($prev);
				reindexFields();
			}
		});

		// Move field row down.
		$list.on('click', '.gk-field-move-down', function(e) {
			e.preventDefault();
			e.stopPropagation();
			var $row = $(this).closest('.gk-field-row');
			var $next = $row.next('.gk-field-row');
			if ($next.length) {
				$row.insertAfter($next);
				reindexFields();
			}
		});

		// Toggle field row collapse.
		$list.on('click', '.gk-field-header', function(e) {
			if ($(e.target).closest('.gk-field-actions, .gk-field-handle').length) {
				return;
			}
			var $row = $(this).closest('.gk-field-row');
			$row.toggleClass('is-collapsed');
			var $icon = $row.find('.gk-field-toggle .dashicons');
			if ($row.hasClass('is-collapsed')) {
				$icon.removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-right-alt2');
			} else {
				$icon.removeClass('dashicons-arrow-right-alt2').addClass('dashicons-arrow-down-alt2');
			}
		});

		// Toggle button in actions.
		$list.on('click', '.gk-field-toggle', function(e) {
			e.preventDefault();
			e.stopPropagation();
			var $row = $(this).closest('.gk-field-row');
			$row.toggleClass('is-collapsed');
			var $icon = $(this).find('.dashicons');
			if ($row.hasClass('is-collapsed')) {
				$icon.removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-right-alt2');
			} else {
				$icon.removeClass('dashicons-arrow-right-alt2').addClass('dashicons-arrow-down-alt2');
			}
		});

		// Delete field row.
		$list.on('click', '.gk-field-delete', function(e) {
			e.preventDefault();
			e.stopPropagation();
			var $row = $(this).closest('.gk-field-row');
			var type = $row.attr('data-field-type');

			if (type === 'email') {
				var emailCount = $list.find('.gk-field-row[data-field-type="email"]').length;
				if (emailCount <= 1) {
					window.alert(gatewaykitFormBuilder.i18n.cannotDeleteEmail);
					return;
				}
			}

			if (window.confirm(gatewaykitFormBuilder.i18n.deleteConfirm)) {
				$row.fadeOut(200, function() {
					$(this).remove();
					reindexFields();
				});
			}
		});

		// Duplicate field row.
		$list.on('click', '.gk-field-duplicate', function(e) {
			e.preventDefault();
			e.stopPropagation();
			var $row = $(this).closest('.gk-field-row');
			var $clone = $row.clone();
			var newId = generateFieldId();

			$clone.attr('data-field-id', newId);
			$clone.find('.gk-field-input-id').val(newId);

			var $labelInput = $clone.find('.gk-field-label-input');
			var curLabel = $labelInput.val();
			$labelInput.val(curLabel + ' (Copy)');
			$clone.find('.gk-field-label-preview').text(curLabel + ' (Copy)');

			$clone.removeClass('is-collapsed');
			$clone.find('.gk-field-toggle .dashicons').removeClass('dashicons-arrow-right-alt2').addClass('dashicons-arrow-down-alt2');

			$clone.insertAfter($row);
			reindexFields();
		});

		// Live label preview update.
		$list.on('input', '.gk-field-label-input', function() {
			var val = $(this).val() || '';
			$(this).closest('.gk-field-row').find('.gk-field-label-preview').text(val);
		});

		// Live required indicator toggle.
		$list.on('change', '.gk-field-required-checkbox', function() {
			var isChecked = $(this).is(':checked');
			var $indicator = $(this).closest('.gk-field-row').find('.gk-field-required-indicator');
			if (isChecked) {
				$indicator.show();
			} else {
				$indicator.hide();
			}
		});

		// Add field from palette.
		$('.gk-add-field-btn').on('click', function(e) {
			e.preventDefault();
			var type = $(this).data('type');
			var isProField = (type === 'discount') || $(this).data('pro') === 1 || $(this).data('pro') === '1';

			// Pro field gate for Lite version.
			if (isProField && window.gatewaykitFormBuilder && !window.gatewaykitFormBuilder.isPro) {
				var $modal = $('#gk-discount-upsell-modal');
				if ($modal.length) {
					$modal.fadeIn(150);
				} else if (window.gatewaykitFormBuilder.upgradeUrl) {
					window.open(window.gatewaykitFormBuilder.upgradeUrl, '_blank');
				}
				return;
			}

			var typeLabel = $(this).data('label');
			var placeholder = $(this).data('placeholder') || '';
			var defaultLabel = $(this).data('default-label') || typeLabel;
			var newId = generateFieldId();

			var isEmail = (type === 'email');
			var isHeading = (type === 'heading');
			var isDivider = (type === 'divider');
			var isChoice = (type === 'select' || type === 'radio' || type === 'checkbox-group');
			var badgeHtml = (type === 'discount') ? ' <span class="gk-pro-badge">PRO</span>' : '';

			var html = '<div class="gk-field-row" data-field-id="' + newId + '" data-field-type="' + type + '">';
			html += '  <div class="gk-field-header">';
			html += '    <div class="gk-field-header-left">';
			html += '      <span class="gk-field-handle"><span class="dashicons dashicons-menu"></span></span>';
			html += '      <div class="gk-field-title">';
			html += '        <span class="gk-field-type-badge">' + $('<div>').text(typeLabel).html() + badgeHtml + '</span>';
			html += '        <span class="gk-field-label-preview">' + $('<div>').text(defaultLabel).html() + '</span>';
			html += '        <span class="gk-field-required-indicator"' + (isEmail ? '' : ' style="display:none;"') + '>*</span>';
			html += '      </div>';
			html += '    </div>';
			html += '    <div class="gk-field-actions">';
			html += '      <button type="button" class="button button-small gk-field-move-up" title="Move Up"><span class="dashicons dashicons-arrow-up-alt2"></span></button>';
			html += '      <button type="button" class="button button-small gk-field-move-down" title="Move Down"><span class="dashicons dashicons-arrow-down-alt2"></span></button>';
			html += '      <button type="button" class="button button-small gk-field-duplicate" title="Duplicate"><span class="dashicons dashicons-admin-page"></span></button>';
			html += '      <button type="button" class="button button-small gk-field-delete" title="Delete"><span class="dashicons dashicons-trash"></span></button>';
			html += '      <button type="button" class="button button-small gk-field-toggle"><span class="dashicons dashicons-arrow-down-alt2"></span></button>';
			html += '    </div>';
			html += '  </div>';

			html += '  <div class="gk-field-body">';
			html += '    <input type="hidden" name="gk_config[fields][0][id]" class="gk-field-input-id" value="' + newId + '" />';
			html += '    <input type="hidden" name="gk_config[fields][0][type]" class="gk-field-input-type" value="' + type + '" />';

			html += '    <div class="gk-builder-row">';
			html += '      <div class="gk-builder-col">';
			html += '        <label>Field Label</label>';
			html += '        <input type="text" name="gk_config[fields][0][label]" class="gk-field-label-input regular-text" value="' + $('<div>').text(defaultLabel).html() + '" />';
			html += '      </div>';
			html += '      <div class="gk-builder-col">';
			html += '        <label>Field Width</label>';
			html += '        <select name="gk_config[fields][0][width]">';
			html += '          <option value="100">100% (Full Width)</option>';
			html += '          <option value="50">50% (Half Width)</option>';
			html += '        </select>';
			html += '      </div>';
			html += '    </div>';

			if (!isDivider) {
				html += '    <div class="gk-builder-row">';
				if (!isHeading && type !== 'checkbox' && type !== 'hidden') {
					html += '      <div class="gk-builder-col">';
					html += '        <label>Placeholder</label>';
					html += '        <input type="text" name="gk_config[fields][0][placeholder]" class="regular-text" value="' + $('<div>').text(placeholder).html() + '" />';
					html += '      </div>';
				}
				html += '      <div class="gk-builder-col">';
				html += '        <label>Help Text / Description</label>';
				html += '        <input type="text" name="gk_config[fields][0][help_text]" class="regular-text" value="" />';
				html += '      </div>';
				if (!isHeading) {
					html += '      <div class="gk-builder-col">';
					html += '        <label>Default Value</label>';
					html += '        <input type="text" name="gk_config[fields][0][default]" class="regular-text" value="" />';
					html += '      </div>';
				}
				html += '    </div>';
			}

			if (isChoice) {
				html += '    <div class="gk-builder-row gk-options-box">';
				html += '      <div class="gk-builder-col">';
				html += '        <label>Options (one option per line)</label>';
				html += '        <textarea name="gk_config[fields][0][options]" rows="3" class="large-text">Option 1\nOption 2\nOption 3</textarea>';
				html += '      </div>';
				html += '    </div>';
			}

			if (!isHeading && !isDivider && type !== 'hidden') {
				html += '    <div class="gk-builder-row">';
				html += '      <div class="gk-builder-col">';
				if (isEmail) {
					html += '        <label><input type="checkbox" checked disabled /> Required Field (always required for email)</label>';
					html += '        <input type="hidden" name="gk_config[fields][0][required]" value="1" />';
				} else {
					html += '        <label><input type="checkbox" name="gk_config[fields][0][required]" value="1" class="gk-field-required-checkbox" /> Required Field</label>';
				}
				html += '      </div>';
				html += '    </div>';
			}

			html += '  </div>';
			html += '</div>';

			var $newRow = $(html);
			$list.append($newRow);
			reindexFields();

			$('html, body').animate({
				scrollTop: $newRow.offset().top - 100
			}, 200);

			$newRow.find('.gk-field-label-input').focus().select();
		});

		// Amount mode toggling.
		var fixedBox = document.getElementById('gk-mode-fixed-box');
		var donationBox = document.getElementById('gk-mode-donation-box');
		var customBox = document.getElementById('gk-mode-custom-box');

		function updateAmountMode() {
			var selected = document.querySelector('.gk-amount-mode-toggle:checked');
			if (!selected) {
				return;
			}
			var val = selected.value;
			if (fixedBox) {
				fixedBox.style.display = (val === 'fixed') ? 'block' : 'none';
			}
			if (donationBox) {
				donationBox.style.display = (val === 'donation') ? 'block' : 'none';
			}
			if (customBox) {
				customBox.style.display = (val === 'custom') ? 'block' : 'none';
			}
		}

		$('.gk-amount-mode-toggle').on('change', updateAmountMode);
		updateAmountMode();

		// Style Preset card selection & custom CSS toggle.
		var $styleCards = $('.gk-style-preset-card');
		var $customCssBox = $('#gk-custom-css-box');

		$styleCards.on('click', function(e) {
			var $radio = $(this).find('input[type="radio"]');
			if ($radio.prop('disabled')) {
				e.preventDefault();
				return;
			}
			$radio.prop('checked', true);
			$styleCards.removeClass('is-selected');
			$(this).addClass('is-selected');

			if ($radio.val() === 'custom') {
				$customCssBox.slideDown(150);
			} else {
				$customCssBox.slideUp(150);
			}
		});

		// Upsell modal event handlers.
		$(document).on('click', '.gk-upsell-modal-close, .gk-upsell-cancel', function(e) {
			e.preventDefault();
			$('#gk-discount-upsell-modal').fadeOut(150);
		});

		$(document).on('click', '.gk-upsell-modal-backdrop', function(e) {
			if ($(e.target).hasClass('gk-upsell-modal-backdrop')) {
				$(this).fadeOut(150);
			}
		});

		$(document).on('keydown', function(e) {
			if (e.key === 'Escape' || e.keyCode === 27) {
				$('#gk-discount-upsell-modal').fadeOut(150);
			}
		});
	});
})(jQuery);
