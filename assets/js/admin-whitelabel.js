/**
 * GatewayKit White Label admin JS
 */
(function($) {
    'use strict';

    $(document).ready(function() {

        /**
         * Copy a field value or URL to clipboard and show a brief "Copied!" message.
         *
         * @param {string} buttonId  Button element ID.
         * @param {string} sourceSel Selector for the input/code element to copy.
         */
        function bindCopy(buttonId, sourceSel) {
            $(buttonId).on('click', function(e) {
                e.preventDefault();
                var $src = $(sourceSel);
                if ($src.is('code')) {
                    // Copy text content from <code> element.
                    var range = document.createRange();
                    range.selectNodeContents($src[0]);
                    var sel = window.getSelection();
                    sel.removeAllRanges();
                    sel.addRange(range);
                    document.execCommand('copy');
                    sel.removeAllRanges();
                } else {
                    $src.select();
                    document.execCommand('copy');
                }
                $(this).after(' <span class="gatewaykit-wl-copied">' + gatewaykit_wl_vars.copied + '</span>');
                setTimeout(function() {
                    $('.gatewaykit-wl-copied').remove();
                }, 2000);
            });
        }

        // Copy magic link URL.
        bindCopy('#gatewaykit-wl-copy-url', '#gatewaykit-wl-magic-url');
    });
})(jQuery);
