/*!
 * GatewayKit - UI Kit Interactions
 */

(function($) {
  'use strict';

  $(document).ready(function() {
    initUIkit();
  });

  /**
   * Initialize UI kit behaviors
   */
  function initUIkit() {
    initTabs();
    initCopyButtons();
    initFocusManagement();
  }

  /**
   * Tabs Controller
   * Markup expected:
   * <div class="gatewaykit-ui-tabs">
   *   <div class="gatewaykit-ui-tabs__list" role="tablist">
   *     <button class="gatewaykit-ui-tabs__tab" aria-controls="panel-general" aria-selected="true">...</button>
   *     <button class="gatewaykit-ui-tabs__tab" aria-controls="panel-gateways">...</button>
   *   </div>
   *   <div id="panel-general" class="gatewaykit-ui-tabs__panel" data-state="active">...</div>
   *   <div id="panel-gateways" class="gatewaykit-ui-tabs__panel">...</div>
   * </div>
   */
  function initTabs() {
    var $containers = $('.gatewaykit-ui .gatewaykit-ui-tabs');
    if ($containers.length === 0) return;

    $containers.each(function() {
      var $tabs = $(this);
      var $list = $tabs.find('.gatewaykit-ui-tabs__list').first();
      var $buttons = $list.find('.gatewaykit-ui-tabs__tab, [role="tab"]');
      var $panels = $tabs.find('.gatewaykit-ui-tabs__panel');

      if ($buttons.length === 0 || $panels.length === 0) return;

      // Ensure ARIA roles
      $list.attr('role', 'tablist');
      $buttons.each(function(i) {
        var $btn = $(this);
        var controls = $btn.attr('aria-controls') || $btn.data('target');
        var $panel = controls ? $panels.filter('#' + controls) : $();

        $btn.attr('role', 'tab');
        $btn.attr('tabindex', $btn.attr('aria-selected') === 'true' ? '0' : '-1');

        // Click
        $btn.on('click', function(e) {
          e.preventDefault();
          activateTab($tabs, $btn, $panel);
        });

        // Keyboard navigation
        $btn.on('keydown', function(e) {
          var key = e.key;
          if (key === 'ArrowRight' || key === 'ArrowLeft') {
            e.preventDefault();
            var dir = key === 'ArrowRight' ? 1 : -1;
            var next = (i + dir + $buttons.length) % $buttons.length;
            $buttons.eq(next).trigger('click').focus();
          }
          if (key === 'Home') {
            e.preventDefault();
            $buttons.eq(0).trigger('click').focus();
          }
          if (key === 'End') {
            e.preventDefault();
            $buttons.eq($buttons.length - 1).trigger('click').focus();
          }
        });
      });

      // Initial state
      var $activeBtn = $buttons.filter('[aria-selected="true"]').first();
      if ($activeBtn.length) {
        $activeBtn.trigger('click');
      } else {
        var $first = $buttons.first();
        var controls = $first.attr('aria-controls') || $first.data('target');
        var $panel = controls ? $panels.filter('#' + controls) : $panels.first();
        $first.attr('aria-selected', 'true');
        activateTab($tabs, $first, $panel);
      }
    });
  }

  /**
   * Activate a tab and its panel
   */
  function activateTab($tabs, $btn, $panel) {
    var $list = $tabs.find('.gatewaykit-ui-tabs__list').first();
    var $buttons = $list.find('.gatewaykit-ui-tabs__tab, [role="tab"]');
    var $panels = $tabs.find('.gatewaykit-ui-tabs__panel');

    $buttons
      .attr('aria-selected', 'false')
      .attr('tabindex', '-1')
      .removeAttr('data-state');

    $btn
      .attr('aria-selected', 'true')
      .attr('tabindex', '0')
      .attr('data-state', 'active');

    $panels
      .attr('data-state', 'inactive')
      .hide();

    if ($panel && $panel.length) {
      $panel
        .attr('data-state', 'active')
        .show();
    }
  }

  /**
   * Initialize copy buttons functionality
   */
  function initCopyButtons() {
    // Handle click on copy buttons (legacy class)
    $(document).on('click', '.gatewaykit-copy-btn', function(e) {
      e.preventDefault();

      var $btn = $(this);
      var $codeContainer = $btn.closest('.gatewaykit-receipt-code');
      var $codeElement = $codeContainer.length ? $codeContainer : $btn.prev('.gatewaykit-receipt-code');
      var codeText = $codeElement.text().trim();

      copyToClipboard(codeText, $btn);
    });

    // Handle click on receipt copy buttons (new class, uses data-gk-copy attribute)
    $(document).on('click', '.gk-receipt-copy', function(e) {
      e.preventDefault();

      var $btn = $(this);
      var codeText = $btn.data('gk-copy') || '';

      // Fallback: try to find the receipt code in a sibling element.
      if (!codeText) {
        var $codeContainer = $btn.siblings('.gk-receipt-code');
        codeText = $codeContainer.length ? $codeContainer.text().trim() : '';
      }

      if (codeText) {
        copyToClipboard(codeText, $btn);
      }
    });
  }

  /**
   * Copy text to clipboard with visual feedback
   */
  function copyToClipboard(text, $btn) {
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(function() {
        showCopyFeedback($btn, true);
      }).catch(function() {
        fallbackCopy(text, $btn);
      });
    } else {
      fallbackCopy(text, $btn);
    }
  }

  /**
   * Fallback copy for older browsers
   */
  function fallbackCopy(text, $btn) {
    var textArea = document.createElement('textarea');
    textArea.value = text;
    textArea.style.position = 'fixed';
    textArea.style.left = '-999999px';
    textArea.style.top = '-999999px';
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();

    try {
      var successful = document.execCommand('copy');
      document.body.removeChild(textArea);
      showCopyFeedback($btn, successful);
    } catch (err) {
      document.body.removeChild(textArea);
      showCopyFeedback($btn, false);
    }
  }

  /**
   * Show visual feedback when copying
   */
  function showCopyFeedback($btn, success) {
    var originalText = $btn.html();
    var originalClasses = $btn.attr('class');

    if (success) {
      $btn.html('<span>\u2713</span> ' + (typeof gatewaykit_ui_vars !== 'undefined' ? gatewaykit_ui_vars.copied : 'Copied!'));
      $btn.addClass('gatewaykit-copy-btn--success gk-receipt-copy--success');
    } else {
      $btn.html('<span>\u2717</span> ' + (typeof gatewaykit_ui_vars !== 'undefined' ? gatewaykit_ui_vars.failed : 'Failed'));
      $btn.addClass('gatewaykit-copy-btn--error gk-receipt-copy--error');
    }

    // Reset after 2 seconds
    setTimeout(function() {
      $btn.html(originalText);
      $btn.attr('class', originalClasses);
    }, 2000);
  }


  /**
   * Initialize focus management
   */
  function initFocusManagement() {
    // Trap focus within modals
    $(document).on('keydown', '.gatewaykit-modal', function(e) {
      if (e.key === 'Tab') {
        var $modal = $(this);
        var $focusable = $modal.find(
          'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
        ).filter(':visible');
        
        var $first = $focusable.first();
        var $last = $focusable.last();
        
        if (e.shiftKey) {
          if (document.activeElement === $first[0]) {
            e.preventDefault();
            $last.focus();
          }
        } else {
          if (document.activeElement === $last[0]) {
            e.preventDefault();
            $first.focus();
          }
        }
      }
      
      // Close modal on Escape
      if (e.key === 'Escape') {
        $('.gatewaykit-modal-close').trigger('click');
      }
    });

    // Enhance focus indicators
    $('.gatewaykit-ui button, .gatewaykit-ui input, .gatewaykit-ui select, .gatewaykit-ui textarea, .gatewaykit-ui a')
      .on('focus', function() {
        $(this).addClass('gatewaykit-focused');
      })
      .on('blur', function() {
        $(this).removeClass('gatewaykit-focused');
      });

    // Add focus management for toggle switches
    $('.gatewaykit-toggle-track').on('keydown', function(e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        $(this).trigger('click');
      }
    });
  }

})(jQuery);