/**
 * GatewayKit White Label admin JS
 */
(function($) {
    'use strict';

    $(document).ready(function() {
        // Copy magic URL to clipboard
        $('#gatewaykit-wl-copy-url').on('click', function(e) {
            e.preventDefault();
            var urlInput = $('#gatewaykit-wl-magic-url');
            urlInput.select();
            document.execCommand('copy');
            $(this).after(' <span class="gatewaykit-wl-copied">' + gatewaykit_wl_vars.copied + '</span>');
            setTimeout(function() {
                $('.gatewaykit-wl-copied').remove();
            }, 2000);
        });
    });
})(jQuery);
