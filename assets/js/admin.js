/**
 * GatewayKit Admin JavaScript
 * Namespaced to prevent conflicts with other plugins
 */

(function($) {
    'use strict';

    // Create GatewayKit namespace
    window.GatewayKit = window.GatewayKit || {};
    window.GatewayKit.Admin = {
        
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
            
            // Filter and search buttons: allow native submit; add diagnostics
            $(document).on('click', '#gatewaykit-filter-btn, #gatewaykit-search-btn', function(e) {
                var $form = $(this).closest('form');
                if ($form.length) {
                    // GatewayKit button clicked
                } else {
                    // GatewayKit button clicked but no form found
                }
                // Let native submit proceed (button type=submit)
            });

            // Confirm bulk delete on WP_List_Table bulk form and prevent empty selection
            $(document).on('submit', 'form', function(e) {
                self.handleBulkDelete(e);
            });

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

            // Transaction details modal (if needed)
            $('.gatewaykit-transaction-details').on('click', function(e) {
                e.preventDefault();
                var transactionId = $(this).data('transaction-id');
                window.location.href = gatewaykit_admin_vars.transaction_url + transactionId;
            });

            // Form data popup
            $('.gatewaykit-view-form-data').on('click', function(e) {
                e.preventDefault();
                var transactionId = $(this).data('transaction-id');
                self.showFormDataModal(transactionId);
            });

            // Notes popup
            $(document).on('click', '.gatewaykit-view-notes', function(e) {
                e.preventDefault();
                var transactionId = $(this).data('transaction-id');
                self.showNotesModal(transactionId);
            });

            // Error details popup
            $('.gatewaykit-error-info').on('click', function(e) {
                e.preventDefault();
                var transactionId = $(this).closest('tr').find('.gatewaykit-view-form-data').data('transaction-id');
                if (transactionId) {
                    self.showErrorDetailsModal(transactionId);
                }
            });

            // Close modal
            $(document).on('click', '.gatewaykit-modal-close, .gatewaykit-modal-overlay', function() {
                $('.gatewaykit-modal').hide();
            });

            // Copy error details to clipboard
            $(document).on('click', '.gatewaykit-copy-button', function(e) {
                e.preventDefault();
                self.handleCopyToClipboard($(this));
            });

            // Save transaction note
            $(document).on('click', '.gatewaykit-save-note', function(e) {
                e.preventDefault();
                var btn = $(this);
                var transactionId = btn.data('transaction-id');
                var note = $('#gatewaykit-new-note').val();

                if (!note || !note.trim()) return;

                btn.prop('disabled', true).text(gatewaykit_admin_vars.saving);

                $.ajax({
                    url: gatewaykit_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'gatewaykit_add_transaction_note',
                        transaction_id: transactionId,
                        note: note,
                        nonce: gatewaykit_ajax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            window.GatewayKit.Admin.renderNotes(response.data.notes, transactionId);
                        } else {
                            alert(response.data.message || gatewaykit_admin_vars.error);
                            btn.prop('disabled', false).text(gatewaykit_admin_vars.save_note);
                        }
                    },
                    error: function() {
                        alert(gatewaykit_admin_vars.ajax_error_occurred);
                        btn.prop('disabled', false).text(gatewaykit_admin_vars.save_note);
                    }
                });
            });

            // Copy shortcode to clipboard (usage guide)
            $(document).on('click', '.gatewaykit-copy-shortcode', function(e) {
                e.preventDefault();
                var button = $(this);
                var textToCopy = button.data('copy-text');

                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(textToCopy).then(function() {
                        GatewayKit.Admin.showCopyShortcodeFeedback(button);
                    });
                } else {
                    var textArea = document.createElement('textarea');
                    textArea.value = textToCopy;
                    textArea.style.position = 'fixed';
                    textArea.style.left = '-999999px';
                    textArea.style.top = '-999999px';
                    document.body.appendChild(textArea);
                    textArea.focus();
                    textArea.select();

                    try {
                        document.execCommand('copy');
                        GatewayKit.Admin.showCopyShortcodeFeedback(button);
                    } catch (err) {
                        // Copy fallback failed
                    }

                    document.body.removeChild(textArea);
                }
            });
        },
        
        handleBulkDelete: function(e) {
            // In delegated handler, `this` is the matched element (the form).
            var $form = $(e.currentTarget);
            // Only run on list table forms
            if ($form.find('table.wp-list-table').length === 0) {
                return;
            }
            var action1 = $form.find('select[name="action"]').val() || '';
            var action2 = $form.find('select[name="action2"]').val() || '';
            var actionVal = action1 && action1 !== '-1' ? action1 : (action2 && action2 !== '-1' ? action2 : '');

            if (actionVal === 'delete') {
                var selectedCount = $form.find('input[name="transaction_ids[]"]:checked').length;
                if (selectedCount === 0) {
                    alert(gatewaykit_admin_vars.validation_error);
                    e.preventDefault();
                    return false;
                }
                if (!window.confirm(gatewaykit_admin_vars.confirm_delete)) {
                    e.preventDefault();
                    return false;
                }
            }
        },
        
        testGateway: function(button) {
            var gatewayId = button.data('gateway');
            var originalText = button.text();

            button.prop('disabled', true).text(gatewaykit_admin_vars.testing);

            // Gather the gateway's current (possibly unsaved) settings from
            // the form so the test runs on what the user just typed in.
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
            var originalText = button.text();
            var resultDiv = button.closest('td').find('.gatewaykit-test-webhook-result');

            // Gather current (unsaved) webhook URLs and secret from the form.
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
                    console.log('GatewayKit Test Webhook response:', response);
                    if (response.success) {
                        var msg = (response.data && response.data.message) ? response.data.message : response.data;
                        resultDiv.html('<span style="color:#46b450;">&#10004; ' + self.escapeHtml(msg) + '</span>').show();
                    } else {
                        var msg = (response.data && response.data.message) ? response.data.message : (typeof response.data === 'string' ? response.data : (gatewaykit_admin_vars.test_failed || 'Test failed'));
                        resultDiv.html('<span style="color:#dc3232;">&#10008; ' + self.escapeHtml(msg) + '</span>').show();
                    }
                },
                error: function(xhr, status, error) {
                    console.error('GatewayKit Test Webhook AJAX error:', status, error, xhr.responseText);
                    resultDiv.html('<span style="color:#dc3232;">&#10008; ' + (gatewaykit_admin_vars.ajax_error || 'AJAX error occurred') + '</span>').show();
                },
                complete: function() {
                    button.prop('disabled', false).text(originalText);
                }
            });
        },
        
        handleCopyToClipboard: function(button) {
            var textToCopy = button.data('copy-text') || button.closest('.gatewaykit-error-details').text();

            // Use modern clipboard API if available
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(textToCopy).then(function() {
                    GatewayKit.Admin.showCopyFeedback(button);
                });
            } else {
                // Fallback for older browsers
                var textArea = document.createElement('textarea');
                textArea.value = textToCopy;
                textArea.style.position = 'fixed';
                textArea.style.left = '-999999px';
                textArea.style.top = '-999999px';
                document.body.appendChild(textArea);
                textArea.focus();
                textArea.select();

                try {
                    document.execCommand('copy');
                    GatewayKit.Admin.showCopyFeedback(button);
                } catch (err) {
                    // Fallback copy failed
                }

                document.body.removeChild(textArea);
            }
        },
        
        showCopyFeedback: function(button) {
            var originalText = button.text();
            button.text((gatewaykit_admin_vars ? gatewaykit_admin_vars.copied : 'Copied!')).addClass('copied');

            setTimeout(function() {
                button.text(originalText).removeClass('copied');
            }, 2000);
        },

        showCopyShortcodeFeedback: function(button) {
            var $icon = button.find('.dashicons');
            var $text = button.find('.gatewaykit-copy-text');
            var originalIcon = 'dashicons-clipboard';
            var originalText = $text.text();

            button.addClass('gatewaykit-copied');
            $icon.removeClass(originalIcon).addClass('dashicons-yes-alt');
            $text.text(gatewaykit_admin_vars.copied || 'Copied!');

            setTimeout(function() {
                button.removeClass('gatewaykit-copied');
                $icon.removeClass('dashicons-yes-alt').addClass(originalIcon);
                $text.text(originalText);
            }, 2000);
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
                    label.text((typeof gatewaykit_admin_vars !== 'undefined' && gatewaykit_admin_vars.enabled) ? gatewaykit_admin_vars.enabled : 'Enabled');
                    card.removeClass('disabled').addClass('enabled');
                    content.removeClass('hidden').addClass('visible');
                    content.css({ 'max-height': '1000px', 'opacity': '1' });
                } else {
                    track.removeClass('enabled').addClass('disabled');
                    label.removeClass('active');
                    label.text((typeof gatewaykit_admin_vars !== 'undefined' && gatewaykit_admin_vars.disabled) ? gatewaykit_admin_vars.disabled : 'Disabled');
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
                    label.text((typeof gatewaykit_admin_vars !== 'undefined' && gatewaykit_admin_vars.enabled) ? gatewaykit_admin_vars.enabled : 'Enabled');
                    card.removeClass('disabled').addClass('enabled');
                    content.removeClass('hidden').addClass('visible');
                    content.css({ 'max-height': '1000px', 'opacity': '1' });
                } else {
                    track.removeClass('enabled').addClass('disabled');
                    label.removeClass('active');
                    label.text((typeof gatewaykit_admin_vars !== 'undefined' && gatewaykit_admin_vars.disabled) ? gatewaykit_admin_vars.disabled : 'Disabled');
                    card.removeClass('enabled').addClass('disabled');
                    content.removeClass('visible').addClass('hidden');
                    content.css({ 'max-height': '0', 'opacity': '0' });
                }
            });
        },

        validateSettingsForm: function() {
            var isValid = true;
            var validationErrors = [];

            // Clear all previous errors
            $('.gatewaykit-validation-error').remove();
            $('.gatewaykit-field-error').removeClass('gatewaykit-field-error');

            // Validate merchant IDs for enabled gateways
            $('.gatewaykit-gateway-settings').each(function() {
                var gatewaySection = $(this);
                var merchantIdField = gatewaySection.find('input[name*="merchant_id"], input[name*="api_key"], input[name*="token"]');
                var gatewayName = gatewaySection.closest('.gatewaykit-gateway-card').find('.gatewaykit-gateway-name').text();

                if (merchantIdField.length > 0) {
                    if (merchantIdField.val().trim() === '') {
                        isValid = false;
                        merchantIdField.addClass('gatewaykit-field-error');
                        validationErrors.push(gatewayName + ': ' + (gatewaykit_admin_vars.merchant_id_required || 'Merchant ID is required'));
                    } else if (!GatewayKit.Admin.isValidApiKey(merchantIdField.val())) {
                        isValid = false;
                        merchantIdField.addClass('gatewaykit-field-error');
                        validationErrors.push(gatewayName + ': ' + (gatewaykit_admin_vars.invalid_api_key || 'Invalid API key format'));
                    } else {
                        merchantIdField.removeClass('gatewaykit-field-error');
                    }
                }
            });

            // Validate rate limiting settings
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

            // Clear previous error
            GatewayKit.Admin.clearFieldError($field);

            // Required field validation
            if ($field.prop('required') && value === '') {
                GatewayKit.Admin.showFieldError($field, 'This field is required');
                isValid = false;
            }

            // API key format validation
            if (fieldName && fieldName.includes('api_key') && value !== '') {
                if (!GatewayKit.Admin.isValidApiKey(value)) {
                    GatewayKit.Admin.showFieldError($field, 'Invalid API key format');
                    isValid = false;
                }
            }

            // Numeric validation
            if (fieldName && (fieldName.includes('rate_limit') || fieldName.includes('amount'))) {
                var numValue = parseInt(value, 10);
                if (isNaN(numValue) || numValue < 0) {
                    GatewayKit.Admin.showFieldError($field, 'Please enter a valid positive number');
                    isValid = false;
                }
            }

            return isValid;
        },

        isValidApiKey: function(apiKey) {
            // Basic validation - adjust based on actual gateway requirements
            return apiKey.length >= 8 && /^[a-zA-Z0-9_-]+$/.test(apiKey);
        },

        showFieldError: function($field, message) {
            $field.addClass('gatewaykit-field-error');
            
            // Remove existing error message
            $field.siblings('.gatewaykit-field-error-message').remove();
            
            // Add error message
            $('<div class="gatewaykit-field-error-message">' + message + '</div>')
                .insertAfter($field)
                .css({
                    'color': 'var(--gatewaykit-danger-color)',
                    'font-size': '12px',
                    'margin-top': '4px'
                });
        },

        clearFieldError: function($field) {
            $field.removeClass('gatewaykit-field-error');
            $field.siblings('.gatewaykit-field-error-message').remove();
        },

        showValidationErrors: function(errors) {
            if (errors && errors.length > 0) {
                var errorHtml = '<div class="notice notice-error gatewaykit-validation-error" style="margin: 20px 0;">';
                errorHtml += '<h4>Please fix the following errors:</h4>';
                errorHtml += '<ul>';
                
                errors.forEach(function(error) {
                    errorHtml += '<li>' + error + '</li>';
                });
                
                errorHtml += '</ul>';
                errorHtml += '</div>';
                
                // Insert at the top of the form
                $('.gatewaykit-settings-container').prepend(errorHtml);
                
                // Scroll to error message
                $('html, body').animate({
                    scrollTop: $('.gatewaykit-validation-error').offset().top - 50
                }, 500);
            }
        },

        formatCurrency: function(amount, currency) {
            currency = currency || gatewaykit_admin_vars.default_currency || 'IRR';

            var labels = gatewaykit_admin_vars.currency_labels || {};
            var label = labels[currency] ? labels[currency] : currency;

            return GatewayKit.Admin.number_format(amount) + ' ' + label;
        },

        showFormDataModal: function(transactionId) {
            var modal = $('#gatewaykit-form-data-modal');
            var content = $('#gatewaykit-form-data-content');

            // Show loading
            content.html('<p>' + gatewaykit_admin_vars.loading + '</p>');
            modal.show();

            // Make AJAX request
            $.ajax({
                url: gatewaykit_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'gatewaykit_get_form_data',
                    transaction_id: transactionId,
                    nonce: gatewaykit_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        var data = response.data;
                        var html = '<div class="gatewaykit-form-data">';

                        // Transaction info
                        html += '<div class="gatewaykit-section">';
                        html += '<h3>' + GatewayKit.Admin.escapeHtml(gatewaykit_admin_vars.transaction_details) + '</h3>';
                        html += '<table class="gatewaykit-info-table"><tbody>';
                        html += '<tr><th>' + GatewayKit.Admin.escapeHtml(gatewaykit_admin_vars.id) + '</th><td>' + GatewayKit.Admin.escapeHtml(data.transaction_id) + '</td></tr>';
                        html += '<tr><th>' + GatewayKit.Admin.escapeHtml(gatewaykit_admin_vars.amount) + '</th><td>' + GatewayKit.Admin.formatCurrency(data.amount, data.currency) + '</td></tr>';
                        html += '<tr><th>' + GatewayKit.Admin.escapeHtml(gatewaykit_admin_vars.status) + '</th><td>' + GatewayKit.Admin.escapeHtml(data.status) + '</td></tr>';
                        html += '<tr><th>' + GatewayKit.Admin.escapeHtml(gatewaykit_admin_vars.date) + '</th><td>' + GatewayKit.Admin.escapeHtml(data.created_at) + '</td></tr>';
                        if (data.user_id) {
                            html += '<tr><th>' + GatewayKit.Admin.escapeHtml(gatewaykit_admin_vars.user_id) + '</th><td>' + GatewayKit.Admin.escapeHtml(data.user_id) + '</td></tr>';
                        }
                        html += '<tr><th>' + GatewayKit.Admin.escapeHtml(gatewaykit_admin_vars.description) + '</th><td>' + GatewayKit.Admin.escapeHtml(data.description || '-') + '</td></tr>';
                        if (data.discount_code) {
                            html += '<tr><th>' + GatewayKit.Admin.escapeHtml(gatewaykit_admin_vars.discount_code) + '</th><td>' + GatewayKit.Admin.escapeHtml(data.discount_code) + '</td></tr>';
                        }
                        if (data.discount_amount > 0) {
                            html += '<tr><th>' + GatewayKit.Admin.escapeHtml(gatewaykit_admin_vars.discount_amount) + '</th><td>' + GatewayKit.Admin.formatCurrency(data.discount_amount, data.currency) + '</td></tr>';
                        }
                        html += '</tbody></table>';
                        html += '</div>';

                        // Form data
                        if (data.form_data && Object.keys(data.form_data).length > 0) {
                            html += '<div class="gatewaykit-form-fields">';
                            html += '<h3>' + gatewaykit_admin_vars.form_data + '</h3>';
                            html += '<table class="wp-list-table widefat fixed striped">';
                            html += '<thead><tr><th>' + gatewaykit_admin_vars.field + '</th><th>' + gatewaykit_admin_vars.value + '</th></tr></thead><tbody>';

                            $.each(data.form_data, function(key, field) {
                                var fieldName = key;
                                var fieldValue = '-';

                                if (typeof field === 'object' && field !== null) {
                                    if (field.title) {
                                        fieldName = field.title;
                                    } else if (field.id) {
                                        fieldName = field.id;
                                    }

                                    if (field.value !== undefined) {
                                        fieldValue = field.value;
                                    } else if (field.raw_value !== undefined) {
                                        fieldValue = field.raw_value;
                                    } else {
                                        fieldValue = JSON.stringify(field);
                                    }
                                } else {
                                    fieldValue = field;
                                }

                                html += '<tr>';
                                html += '<td><strong>' + self.escapeHtml(fieldName) + '</strong></td>';
                                html += '<td>' + self.escapeHtml(fieldValue) + '</td>';
                                html += '</tr>';
                            });

                            html += '</tbody></table>';
                            html += '</div>';
                        } else {
                            html += '<p>' + self.escapeHtml(gatewaykit_admin_vars.no_form_data) + '</p>';
                        }

                        // User data
                        if (data.user_data && Object.keys(data.user_data).length > 0) {
                            html += '<div class="gatewaykit-user-data">';
                            html += '<h3>' + self.escapeHtml(gatewaykit_admin_vars.user_data) + '</h3>';
                            html += '<table class="wp-list-table widefat fixed striped">';
                            html += '<thead><tr><th>' + self.escapeHtml(gatewaykit_admin_vars.field) + '</th><th>' + self.escapeHtml(gatewaykit_admin_vars.value) + '</th></tr></thead><tbody>';

                            $.each(data.user_data, function(key, value) {
                                html += '<tr>';
                                html += '<td><strong>' + self.escapeHtml(key) + '</strong></td>';
                                html += '<td>' + self.escapeHtml(value || '-') + '</td>';
                                html += '</tr>';
                            });

                            html += '</tbody></table>';
                            html += '</div>';
                        }

                        html += '</div>';
                        content.html(html);
                    } else {
                        content.html('<p>' + self.escapeHtml(gatewaykit_admin_vars.error) + ': ' + self.escapeHtml(response.data || gatewaykit_admin_vars.unknown_error) + '</p>');
                    }
                },
                error: function() {
                    content.html('<p>' + gatewaykit_admin_vars.ajax_error_occurred + '</p>');
                }
            });
        },

        showNotesModal: function(transactionId) {
            var modal = $('#gatewaykit-notes-modal');
            var content = $('#gatewaykit-notes-content');

            content.html('<p>' + gatewaykit_admin_vars.loading + '</p>');
            modal.show();

            this.loadTransactionNotes(transactionId);
        },

        loadTransactionNotes: function(transactionId) {
            var self = this;
            var container = $('#gatewaykit-notes-content');

            if (!container.length) return;

            container.html('<p>' + gatewaykit_admin_vars.loading + '</p>');

            $.ajax({
                url: gatewaykit_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'gatewaykit_get_transaction_notes',
                    transaction_id: transactionId,
                    nonce: gatewaykit_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        self.renderNotes(response.data.notes, transactionId);
                    } else {
                        container.html('<p>' + gatewaykit_admin_vars.error + '</p>');
                    }
                },
                error: function() {
                    container.html('<p>' + gatewaykit_admin_vars.ajax_error_occurred + '</p>');
                }
            });
        },

        renderNotes: function(notes, transactionId) {
            var self = this;
            var container = $('#gatewaykit-notes-content');
            var html = '';

            html += '<h3>' + gatewaykit_admin_vars.notes + '</h3>';

            if (notes && notes.length > 0) {
                html += '<div class="gatewaykit-notes-list">';
                for (var i = 0; i < notes.length; i++) {
                    var note = notes[i];
                    html += '<div class="gatewaykit-note-item">';
                    html += '<div class="gatewaykit-note-meta">';
                    html += '<strong>' + self.escapeHtml(note.author_name || gatewaykit_admin_vars.unknown_user) + '</strong>';
                    html += ' <span class="gatewaykit-note-date">' + self.escapeHtml(note.created_at) + '</span>';
                    html += '</div>';
                    html += '<div class="gatewaykit-note-text">' + self.escapeHtml(note.note) + '</div>';
                    html += '</div>';
                }
                html += '</div>';
            } else {
                html += '<p>' + gatewaykit_admin_vars.no_notes + '</p>';
            }

            // Add note form
            html += '<div class="gatewaykit-add-note">';
            html += '<textarea id="gatewaykit-new-note" placeholder="' + gatewaykit_admin_vars.add_note_placeholder + '" rows="3"></textarea>';
            html += '<button type="button" class="button button-primary gatewaykit-save-note" data-transaction-id="' + transactionId + '">' + gatewaykit_admin_vars.save_note + '</button>';
            html += '</div>';

            container.html(html);
        },

        showErrorDetailsModal: function(transactionId) {
            var self = this;
            
            // Create modal if it doesn't exist
            if (!$('#gatewaykit-error-details-modal').length) {
                var modalHtml = '<div id="gatewaykit-error-details-modal" class="gatewaykit-modal gatewaykit-error-modal" style="display: none;">' +
                    '<div class="gatewaykit-modal-overlay"></div>' +
                    '<div class="gatewaykit-modal-content">' +
                        '<div class="gatewaykit-modal-header">' +
                            '<h2>' + (gatewaykit_admin_vars ? gatewaykit_admin_vars.error_details : 'Error Details') + '</h2>' +
                            '<button type="button" class="gatewaykit-modal-close">&times;</button>' +
                        '</div>' +
                        '<div class="gatewaykit-modal-body">' +
                            '<div id="gatewaykit-error-details-content">' +
                                '<p>' + (gatewaykit_admin_vars ? gatewaykit_admin_vars.loading : 'Loading...') + '</p>' +
                            '</div>' +
                        '</div>' +
                    '</div>' +
                '</div>';
                $('body').append(modalHtml);
            }

            var modal = $('#gatewaykit-error-details-modal');
            var content = $('#gatewaykit-error-details-content');

            // Show loading
            content.html('<p>' + (gatewaykit_admin_vars ? gatewaykit_admin_vars.loading : 'Loading...') + '</p>');
            modal.show();

            // Make AJAX request
            $.ajax({
                url: gatewaykit_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'gatewaykit_get_error_details',
                    transaction_id: transactionId,
                    nonce: gatewaykit_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        var data = response.data;
                        var html = '<div class="gatewaykit-error-details">';

                        // Error summary
                        html += '<h4>' + (gatewaykit_admin_vars ? gatewaykit_admin_vars.error_summary : 'Error Summary') + '</h4>';
                        html += '<p><strong>' + (gatewaykit_admin_vars ? gatewaykit_admin_vars.error_type : 'Type') + ':</strong> ' + (data.error_type_label || data.error_type || 'Unknown') + '</p>';
                        html += '<p><strong>' + (gatewaykit_admin_vars ? gatewaykit_admin_vars.error_message : 'Message') + ':</strong> ' + (data.error_message || 'No message available') + '</p>';
                        if (data.error_code) {
                            html += '<p><strong>' + (gatewaykit_admin_vars ? gatewaykit_admin_vars.error_code : 'Code') + ':</strong> ' + data.error_code + '</p>';
                        }
                        if (data.error_timestamp) {
                            html += '<p><strong>' + (gatewaykit_admin_vars ? gatewaykit_admin_vars.timestamp : 'Timestamp') + ':</strong> ' + data.error_timestamp + '</p>';
                        }

                        // Error details
                        if (data.error_details && Object.keys(data.error_details).length > 0) {
                            html += '<h4>' + (gatewaykit_admin_vars ? gatewaykit_admin_vars.error_details : 'Error Details') + '</h4>';
                            $.each(data.error_details, function(key, value) {
                                if (typeof value === 'object') {
                                    value = JSON.stringify(value, null, 2);
                                }
                                html += '<p><strong>' + key + ':</strong> ' + value + '</p>';
                            });
                        }

                        // Copy button
                        var copyText = 'Error Type: ' + (data.error_type_label || data.error_type || 'Unknown') + '\n';
                        copyText += 'Error Message: ' + (data.error_message || 'No message available') + '\n';
                        if (data.error_code) {
                            copyText += 'Error Code: ' + data.error_code + '\n';
                        }
                        if (data.error_timestamp) {
                            copyText += 'Timestamp: ' + data.error_timestamp + '\n';
                        }
                        if (data.error_details) {
                            copyText += 'Details: ' + JSON.stringify(data.error_details, null, 2) + '\n';
                        }

                        html += '<button class="gatewaykit-copy-button" data-copy-text="' + encodeURIComponent(copyText) + '">' +
                            (gatewaykit_admin_vars ? gatewaykit_admin_vars.copy_error_details : 'Copy Error Details') +
                            '</button>';

                        html += '</div>';
                        content.html(html);
                    } else {
                        content.html('<p>' + (gatewaykit_admin_vars ? gatewaykit_admin_vars.error : 'Error') + ': ' + (response.data || (gatewaykit_admin_vars ? gatewaykit_admin_vars.unknown_error : 'Unknown error')) + '</p>');
                    }
                },
                error: function() {
                    content.html('<p>' + (gatewaykit_admin_vars ? gatewaykit_admin_vars.ajax_error_occurred : 'AJAX error occurred') + '</p>');
                }
            });
        },

        number_format: function(number, decimals, dec_point, thousands_sep) {
            number = (number + '').replace(/[^0-9+\-Ee.]/g, '');
            var n = !isFinite(+number) ? 0 : +number,
                prec = !isFinite(+decimals) ? 0 : Math.abs(decimals),
                sep = (typeof thousands_sep === 'undefined') ? ',' : thousands_sep,
                dec = (typeof dec_point === 'undefined') ? '.' : dec_point,
                s = '',
                toFixedFix = function (n, prec) {
                    var k = Math.pow(10, prec);
                    return '' + Math.round(n * k) / k;
                };

            s = (prec ? toFixedFix(n, prec) : '' + Math.round(n)).split('.');
            if (s[0].length > 3) {
                s[0] = s[0].replace(/\B(?=(?:\d{3})+(?!\d))/g, sep);
            }
            if ((s[1] || '').length < prec) {
                s[1] = s[1] || '';
                s[1] += new Array(prec - s[1].length + 1).join('0');
            }
            return s.join(dec);
        },

        showNotification: function(message, type) {
            type = type || 'info';
            
            var className = 'notice notice-' + type + ' is-dismissible';
            var notification = $('<div class="' + className + '" style="margin: 20px 0; display: none;">' +
                '<p>' + message + '</p>' +
                '<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>' +
                '</div>');
            
            // Insert at the top of the main content
            $('.wrap > h1').after(notification);
            
            // Slide down animation
            notification.slideDown(300);
            
            // Auto-dismiss after 5 seconds for success/info messages
            if (type === 'success' || type === 'info') {
                setTimeout(function() {
                    notification.slideUp(300, function() {
                        $(this).remove();
                    });
                }, 5000);
            }
            
            // Handle dismiss button
            notification.find('.notice-dismiss').on('click', function() {
                notification.slideUp(300, function() {
                    $(this).remove();
                });
            });
        },

        showLoadingState: function($element, message) {
            message = message || 'Loading...';
            
            var loadingHtml = '<div class="gatewaykit-loading-overlay" style="' +
                'position: absolute;' +
                'top: 0;' +
                'left: 0;' +
                'right: 0;' +
                'bottom: 0;' +
                'background: rgba(255, 255, 255, 0.9);' +
                'display: flex;' +
                'align-items: center;' +
                'justify-content: center;' +
                'z-index: 1000;' +
                'border-radius: 8px;' +
                '">' +
                '<div style="' +
                'text-align: center;' +
                'padding: 20px;' +
                '">' +
                '<div class="gatewaykit-spinner" style="' +
                'border: 3px solid #f3f3f3;' +
                'border-top: 3px solid var(--gatewaykit-primary-color);' +
                'border-radius: 50%;' +
                'width: 30px;' +
                'height: 30px;' +
                'animation: gatewaykit-spin 1s linear infinite;' +
                'margin: 0 auto 10px;' +
                '"></div>' +
                '<div>' + message + '</div>' +
                '</div>' +
                '</div>';
            
            $element.css('position', 'relative').append(loadingHtml);
        },

        hideLoadingState: function($element) {
            $element.find('.gatewaykit-loading-overlay').fadeOut(300, function() {
                $(this).remove();
            });
        }
    };

    $(document).ready(function() {
        GatewayKit.Admin.init();
    });

    // Add CSS for spinner animation
    $('<style>')
        .prop('type', 'text/css')
        .html('@keyframes gatewaykit-spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }')
        .appendTo('head');

})(jQuery);