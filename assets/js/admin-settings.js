/**
 * GatewayKit Admin Settings JavaScript
 *
 * Dedicated handler for Settings tabs, gateway configuration toggles,
 * inline validation, test transactions, and webhook connectivity tests.
 *
 * @package GatewayKit
 */

(function($) {
    'use strict';

    window.GatewayKit = window.GatewayKit || {};
    window.GatewayKit.Admin = window.GatewayKit.Admin || {};

    var AdminSettings = {
        escapeHtml: function(text) {
            if (text == null) {
                return '';
            }
            return String(text)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        },

        init: function() {
            this.bindEvents();
            this.initGatewayToggles();
        },

        bindEvents: function() {
            var self = this;

            // Enhanced settings form validation
            $('#gatewaykit-settings-form').on('submit', function(e) {
                var validation = self.validateSettingsForm();
                if (!validation.isValid) {
                    e.preventDefault();
                    self.showValidationErrors(validation.errors);
                    return false;
                }
            });

            // Real-time validation feedback
            $('.gatewaykit-gateway-settings-form input, .gatewaykit-gateway-settings-form select').on('blur', function() {
                self.validateField($(this));
            });

            // Clear validation errors on input
            $('.gatewaykit-gateway-settings-form input, .gatewaykit-gateway-settings-form select').on('input', function() {
                self.clearFieldError($(this));
            });

            // Gateway test functionality
            $('.gatewaykit-test-gateway').on('click', function(e) {
                e.preventDefault();
                self.testGateway($(this));
            });

            // Webhook test functionality
            $('.gatewaykit-test-webhook').on('click', function(e) {
                e.preventDefault();
                self.testWebhook($(this));
            });
        },

        testGateway: function(button) {
            var gatewayId = button.data('gateway');
            var originalText = button.text();

            button.prop('disabled', true).text(gatewaykit_admin_vars.testing);

            var settings = {};
            var card = button.closest('.gatewaykit-gateway-card');
            card.find('.gatewaykit-gateway-settings-form [name]').each(function() {
                var el = $(this);
                var name = el.attr('name') || '';
                var prefix = 'gatewaykit_' + gatewayId + '_settings[';
                if (name.indexOf(prefix) !== 0) {
                    return;
                }
                var key = name.substring(prefix.length, name.length - 1);
                if (el.attr('type') === 'checkbox') {
                    settings[key] = el.is(':checked') ? '1' : '0';
                } else {
                    settings[key] = el.val();
                }
            });

            $.ajax({
                url: (typeof gatewaykit_ajax !== 'undefined' && gatewaykit_ajax.ajax_url) ? gatewaykit_ajax.ajax_url : ajaxurl,
                type: 'POST',
                data: {
                    action: 'gatewaykit_test_gateway',
                    gateway: gatewayId,
                    settings: settings,
                    nonce: (typeof gatewaykit_ajax !== 'undefined' && gatewaykit_ajax.nonce) ? gatewaykit_ajax.nonce : gatewaykit_admin_vars.nonce
                },
                success: function(response) {
                    if (response.success) {
                        alert(response.data.message);
                    } else {
                        alert(response.data && response.data.message ? response.data.message : gatewaykit_admin_vars.test_failed);
                    }
                },
                error: function() {
                    alert(gatewaykit_admin_vars.ajax_error);
                },
                complete: function() {
                    button.prop('disabled', false).text(originalText);
                }
            });
        },

        testWebhook: function(button) {
            var self = this;
            var originalText = button.text();
            var resultDiv = button.closest('td').find('.gatewaykit-test-webhook-result');
            var webhookUrls = button.closest('table').find('textarea[name="gatewaykit_webhook_urls"]').val();
            var webhookSecret = button.closest('table').find('input[name="gatewaykit_webhook_secret"]').val();

            button.prop('disabled', true).text(gatewaykit_admin_vars.sending_test || 'Sending...');
            resultDiv.hide().html('');

            $.ajax({
                url: (typeof gatewaykit_ajax !== 'undefined' && gatewaykit_ajax.ajax_url) ? gatewaykit_ajax.ajax_url : ajaxurl,
                type: 'POST',
                data: {
                    action: 'gatewaykit_test_webhook',
                    webhook_urls: webhookUrls,
                    webhook_secret: webhookSecret,
                    nonce: (typeof gatewaykit_ajax !== 'undefined' && gatewaykit_ajax.nonce) ? gatewaykit_ajax.nonce : gatewaykit_admin_vars.nonce
                },
                success: function(response) {
                    if (response.success) {
                        var msg = (response.data && response.data.message) ? response.data.message : response.data;
                        resultDiv.html('<span style="color:#46b450;">&#10004; ' + self.escapeHtml(msg) + '</span>').show();
                    } else {
                        var msg = (response.data && response.data.message) ? response.data.message : (typeof response.data === 'string' ? response.data : (gatewaykit_admin_vars.test_failed || 'Test failed'));
                        resultDiv.html('<span style="color:#dc3232;">&#10008; ' + self.escapeHtml(msg) + '</span>').show();
                    }
                },
                error: function(xhr, status, error) {
                    resultDiv.html('<span style="color:#dc3232;">&#10008; ' + (gatewaykit_admin_vars.ajax_error || 'AJAX error occurred') + '</span>').show();
                },
                complete: function() {
                    button.prop('disabled', false).text(originalText);
                }
            });
        },

        initGatewayToggles: function() {
            $(document).off('click.gkToggle', '.gatewaykit-gateway-toggle').on('click.gkToggle', '.gatewaykit-gateway-toggle', function(e) {
                e.preventDefault();
                var toggle = $(this);
                var input = toggle.find('.gatewaykit-gateway-status-input');
                var track = toggle.find('.gatewaykit-toggle-track');
                var label = toggle.find('.gatewaykit-toggle-label');
                var card = toggle.closest('.gatewaykit-gateway-card');
                var content = card.find('.gatewaykit-gateway-card-content');
                var currentVal = input.val();
                var newVal = currentVal === '1' ? '0' : '1';

                input.val(newVal);

                if (newVal === '1') {
                    track.removeClass('disabled').addClass('enabled');
                    label.removeClass('active').addClass('active');
                    label.text(gatewaykit_admin_vars.enabled || 'Enabled');
                    card.removeClass('disabled').addClass('enabled');
                    content.removeClass('hidden').addClass('visible');
                    content.css({ 'max-height': '1000px', 'opacity': '1' });
                } else {
                    track.removeClass('enabled').addClass('disabled');
                    label.removeClass('active');
                    label.text(gatewaykit_admin_vars.disabled || 'Disabled');
                    card.removeClass('enabled').addClass('disabled');
                    content.removeClass('visible').addClass('hidden');
                    content.css({ 'max-height': '0', 'opacity': '0' });
                }
            });

            $('.gatewaykit-gateway-toggle').each(function() {
                var toggle = $(this);
                var input = toggle.find('.gatewaykit-gateway-status-input');
                var track = toggle.find('.gatewaykit-toggle-track');
                var label = toggle.find('.gatewaykit-toggle-label');
                var card = toggle.closest('.gatewaykit-gateway-card');
                var content = card.find('.gatewaykit-gateway-card-content');

                if (input.val() === '1') {
                    track.removeClass('disabled').addClass('enabled');
                    label.removeClass('active').addClass('active');
                    label.text(gatewaykit_admin_vars.enabled || 'Enabled');
                    card.removeClass('disabled').addClass('enabled');
                    content.removeClass('hidden').addClass('visible');
                    content.css({ 'max-height': '1000px', 'opacity': '1' });
                } else {
                    track.removeClass('enabled').addClass('disabled');
                    label.removeClass('active');
                    label.text(gatewaykit_admin_vars.disabled || 'Disabled');
                    card.removeClass('enabled').addClass('disabled');
                    content.removeClass('visible').addClass('hidden');
                    content.css({ 'max-height': '0', 'opacity': '0' });
                }
            });
        },

        validateSettingsForm: function() {
            var isValid = true;
            var validationErrors = [];

            $('.gatewaykit-validation-error').remove();
            $('.gatewaykit-field-error').removeClass('gatewaykit-field-error');

            $('.gatewaykit-gateway-settings').each(function() {
                var settings = $(this);
                var requiredFields = settings.find('input[name*="merchant_id"], input[name*="api_key"], input[name*="token"]');
                var gatewayName = settings.closest('.gatewaykit-gateway-card').find('.gatewaykit-gateway-name').text();

                if (requiredFields.length > 0) {
                    requiredFields.each(function() {
                        var field = $(this);
                        var value = field.val().trim();

                        if (value === '') {
                            isValid = false;
                            field.addClass('gatewaykit-field-error');
                            validationErrors.push(gatewayName + ': ' + (gatewaykit_admin_vars.merchant_id_required || 'Merchant ID is required'));
                        } else if (field.attr('name').indexOf('api_key') !== -1 && !AdminSettings.isValidApiKey(value)) {
                            isValid = false;
                            field.addClass('gatewaykit-field-error');
                            validationErrors.push(gatewayName + ': ' + (gatewaykit_admin_vars.invalid_api_key || 'Invalid API key format'));
                        } else {
                            field.removeClass('gatewaykit-field-error');
                        }
                    });
                }
            });

            var rateLimitEnabled = $('input[name="gatewaykit_rate_limit_enabled"]').is(':checked');
            if (rateLimitEnabled) {
                var elementorLimit = parseInt($('input[name="gatewaykit_elementor_action_rate_limit"]').val(), 10);
                var adminLimit = parseInt($('input[name="gatewaykit_admin_ajax_rate_limit"]').val(), 10);
                var callbackLimit = parseInt($('input[name="gatewaykit_callback_rate_limit"]').val(), 10);

                if (isNaN(elementorLimit) || elementorLimit < 1 || elementorLimit > 100) {
                    isValid = false;
                    $('input[name="gatewaykit_elementor_action_rate_limit"]').addClass('gatewaykit-field-error');
                    validationErrors.push('Elementor rate limit must be between 1 and 100');
                }

                if (isNaN(adminLimit) || adminLimit < 1 || adminLimit > 100) {
                    isValid = false;
                    $('input[name="gatewaykit_admin_ajax_rate_limit"]').addClass('gatewaykit-field-error');
                    validationErrors.push('Admin AJAX rate limit must be between 1 and 100');
                }

                if (isNaN(callbackLimit) || callbackLimit < 1 || callbackLimit > 100) {
                    isValid = false;
                    $('input[name="gatewaykit_callback_rate_limit"]').addClass('gatewaykit-field-error');
                    validationErrors.push('Callback rate limit must be between 1 and 100');
                }
            }

            return { isValid: isValid, errors: validationErrors };
        },

        validateField: function($field) {
            var value = $field.val().trim();
            var fieldName = $field.attr('name');
            var isValid = true;

            this.clearFieldError($field);

            if ($field.prop('required') && value === '') {
                this.showFieldError($field, 'This field is required');
                isValid = false;
            }

            if (fieldName && fieldName.indexOf('api_key') !== -1 && value !== '') {
                if (!this.isValidApiKey(value)) {
                    this.showFieldError($field, 'Invalid API key format');
                    isValid = false;
                }
            }

            if (fieldName && (fieldName.indexOf('rate_limit') !== -1 || fieldName.indexOf('amount') !== -1)) {
                var num = parseInt(value, 10);
                if (isNaN(num) || num < 0) {
                    this.showFieldError($field, 'Please enter a valid positive number');
                    isValid = false;
                }
            }

            return isValid;
        },

        isValidApiKey: function(apiKey) {
            return apiKey.length >= 8 && /^[a-zA-Z0-9_-]+$/.test(apiKey);
        },

        showFieldError: function($field, message) {
            $field.addClass('gatewaykit-field-error');
            $field.siblings('.gatewaykit-field-error-message').remove();
            $('<div class="gatewaykit-field-error-message">' + this.escapeHtml(message) + '</div>')
                .insertAfter($field)
                .css({
                    'color': 'var(--gatewaykit-danger-color, #dc2626)',
                    'font-size': '12px',
                    'margin-top': '4px'
                });
        },

        clearFieldError: function($field) {
            $field.removeClass('gatewaykit-field-error');
            $field.siblings('.gatewaykit-field-error-message').remove();
        },

        showValidationErrors: function(errors) {
            var self = this;
            if (errors && errors.length > 0) {
                var errorHtml = '<div class="notice notice-error gatewaykit-validation-error" style="margin: 20px 0;">' +
                    '<h4>' + self.escapeHtml(gatewaykit_admin_vars.validation_error || 'Please check the form for errors.') + '</h4>' +
                    '<ul>';

                errors.forEach(function(error) {
                    errorHtml += '<li>' + self.escapeHtml(error) + '</li>';
                });

                errorHtml += '</ul></div>';

                $('.gatewaykit-settings-container').prepend(errorHtml);
                $('html, body').animate({
                    scrollTop: $('.gatewaykit-validation-error').offset().top - 50
                }, 500);
            }
        }
    };

    // Expose to GatewayKit namespace and initialize
    $.extend(window.GatewayKit.Admin, AdminSettings);

    $(document).ready(function() {
        AdminSettings.init();
    });

})(jQuery);
