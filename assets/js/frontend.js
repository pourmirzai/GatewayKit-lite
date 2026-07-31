/**
 * GatewayKit Frontend - Cache-safe nonce refresher.
 *
 * Problem: when the site uses a full-page cache (WP Rocket, LiteSpeed Cache,
 * WP Super Cache, a CDN, ...), the nonce that Elementor embeds in the page
 * HTML becomes stale (expired or generated for a different user). When a
 * visitor then submits a GatewayKit-enabled Elementor form, the nonce check on
 * the server fails and the payment is blocked.
 *
 * Fix: this script fetches a fresh 'elementor_ajax' nonce from the server and
 * patches Elementor's frontend config BEFORE the form is submitted. As a
 * belt-and-suspenders fallback it also rewrites the nonce in the actual
 * outgoing Elementor AJAX request data via jQuery's ajaxPrefilter.
 *
 * No nonce is required to obtain a fresh one (generating a nonce is safe),
 * so this works even for fully anonymous visitors on cached pages.
 */
(function ($) {
    'use strict';

    if (window.GatewayKitFrontendNonceLoaded) {
        return;
    }
    window.GatewayKitFrontendNonceLoaded = true;

    window.GatewayKit = window.GatewayKit || {};

    var gk = {
        currentNonce: null,
        debug: false,

        init: function () {
            if (typeof GatewayKitFrontend === 'undefined') {
                return;
            }

            this.debug = !!GatewayKitFrontend.debug;

            // Only act on pages where Elementor is present.
            if (!gk.hasElementor()) {
                return;
            }

            // Fetch the fresh nonce as soon as possible, then install the
            // belt-and-suspenders request interceptor.
            gk.refreshNonce();
            gk.installRequestInterceptor();
        },

        /**
         * Check whether Elementor's frontend is active on this page.
         */
        hasElementor: function () {
            return (
                typeof window.elementorFrontend !== 'undefined' ||
                typeof window.elementorFrontendConfig !== 'undefined' ||
                (typeof window.elementorProFrontend !== 'undefined')
            );
        },

        /**
         * Fetch a fresh nonce and patch Elementor's config objects.
         */
        refreshNonce: function () {
            try {
                $.post(GatewayKitFrontend.ajaxurl, { action: 'gatewaykit_get_nonce' })
                    .done(function (response) {
                        if (response && response.success && response.data && response.data.nonce) {
                            gk.currentNonce = response.data.nonce;
                            gk.patchConfig(response.data.nonce);
                            if (gk.debug && window.console) {
                                // eslint-disable-next-line no-console
                                console.log('[GatewayKit] Fresh nonce applied.');
                            }
                        }
                    })
                    .fail(function () {
                        if (gk.debug && window.console) {
                            // eslint-disable-next-line no-console
                            console.warn('[GatewayKit] Failed to fetch fresh nonce.');
                        }
                    });
            } catch (e) {
                if (gk.debug && window.console) {
                    // eslint-disable-next-line no-console
                    console.warn('[GatewayKit] Nonce refresh error:', e);
                }
            }
        },

        /**
         * Replace the stale nonce inside Elementor's global config objects.
         * Elementor reads the nonce from these at form-submit time.
         */
        patchConfig: function (nonce) {
            try {
                if (typeof window.elementorFrontendConfig !== 'undefined') {
                    window.elementorFrontendConfig.nonce = nonce;
                }
                if (typeof window.elementorFrontend !== 'undefined' && window.elementorFrontend.config) {
                    window.elementorFrontend.config.nonce = nonce;
                }
                if (typeof window.elementorProFrontend !== 'undefined') {
                    if (window.elementorProFrontend.config) {
                        window.elementorProFrontend.config.nonce = nonce;
                    }
                    // Some Elementor Pro versions nest it under forms.
                    if (window.elementorProFrontend.config && window.elementorProFrontend.config.forms) {
                        window.elementorProFrontend.config.forms.nonce = nonce;
                    }
                }
            } catch (e) {
                if (gk.debug && window.console) {
                    // eslint-disable-next-line no-console
                    console.warn('[GatewayKit] Config patch error:', e);
                }
            }
        },

        /**
         * Belt-and-suspenders: rewrite the nonce in the outgoing Elementor
         * form AJAX request, regardless of where Elementor reads it from.
         * Runs via jQuery's ajaxPrefilter so it always affects the real data.
         * Handles both serialized-string and FormData payloads.
         */
        installRequestInterceptor: function () {
            if (typeof $.ajaxPrefilter !== 'function') {
                return;
            }

            $.ajaxPrefilter(function (options, originalOptions) {
                if (!gk.currentNonce) {
                    return;
                }

                var isFormSubmission = false;
                var data = (originalOptions && originalOptions.data !== undefined) ? originalOptions.data : options.data;

                // Determine whether this is an Elementor form / GatewayKit request.
                if (typeof data === 'string') {
                    isFormSubmission = (data.indexOf('elementor_pro_forms_send_form') !== -1 ||
                        data.indexOf('gatewaykit_process_payment') !== -1);
                } else if (typeof FormData !== 'undefined' && data instanceof FormData) {
                    isFormSubmission = (data.get('action') === 'elementor_pro_forms_send_form' ||
                        data.get('action') === 'gatewaykit_process_payment' ||
                        data.get('gatewaykit_action') === 'process_payment');
                }

                if (!isFormSubmission) {
                    return;
                }

                // Rewrite nonce in string payload.
                if (typeof options.data === 'string') {
                    if (options.data.indexOf('_wpnonce=') !== -1) {
                        options.data = options.data.replace(
                            /_wpnonce=[^&]*/,
                            '_wpnonce=' + gk.currentNonce
                        );
                    } else {
                        options.data += '&_wpnonce=' + gk.currentNonce;
                    }
                    if (options.data.indexOf('gatewaykit_nonce=') === -1) {
                        options.data += '&gatewaykit_nonce=' + gk.currentNonce;
                    }
                } else if (typeof FormData !== 'undefined' && options.data instanceof FormData) {
                    // Rewrite nonce in FormData payload.
                    options.data.set('_wpnonce', gk.currentNonce);
                    options.data.set('gatewaykit_nonce', gk.currentNonce);
                }
            });
        }
    };

    window.GatewayKit.Frontend = gk;

    $(function () {
        gk.init();
    });
})(jQuery);
