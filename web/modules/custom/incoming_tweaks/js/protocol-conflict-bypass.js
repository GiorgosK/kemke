(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.incomingTweaksProtocolConflictBypass = {
    attach: function (context) {
      once('incoming-tweaks-protocol-bypass', '.incoming-tweaks-protocol-bypass-trigger', context).forEach(function (button) {
        button.setAttribute('type', 'button');

        button.addEventListener('click', function (event) {
          event.preventDefault();

          var form = button.form || button.closest('form');
          if (!form) {
            return;
          }

          var bypassInput = form.querySelector('input[name="incoming_tweaks_bypass_protocol_conflict"]');
          if (bypassInput) {
            bypassInput.value = '1';
          }

          var actions = button.closest('[id*="-actions"]');
          var originalName = (button.name || '').replace(/_bypass$/, '');
          var original = null;

          if (actions && originalName) {
            original = actions.querySelector(
              'input[type="submit"][name="' + originalName + '"]:not(.incoming-tweaks-protocol-bypass-trigger):not([id$="--override"])'
            );
          }
          if (!original) {
            original = form.querySelector(
              'input[type="submit"][name="' + originalName + '"]:not(.incoming-tweaks-protocol-bypass-trigger):not([id$="--override"])'
            );
          }
          if (!original && actions) {
            original = actions.querySelector('input.incoming-tweaks-ief-create-original[type="submit"]:not(.incoming-tweaks-protocol-bypass-trigger):not([id$="--override"])');
          }

          if (original) {
            var ajaxTriggered = false;
            if (Drupal.ajax && Array.isArray(Drupal.ajax.instances)) {
              for (var i = 0; i < Drupal.ajax.instances.length; i++) {
                var instance = Drupal.ajax.instances[i];
                if (instance && instance.element === original && typeof instance.eventResponse === 'function') {
                  var ajaxClickEvent = new MouseEvent('click', {
                    view: window,
                    bubbles: true,
                    cancelable: true
                  });
                  instance.eventResponse(original, ajaxClickEvent);
                  ajaxTriggered = true;
                  window.setTimeout(function () {
                    if (bypassInput) {
                      bypassInput.value = '0';
                    }
                  }, 200);
                  break;
                }
              }
            }

            if (!ajaxTriggered) {
              var clickEvent = new MouseEvent('click', {
                view: window,
                bubbles: true,
                cancelable: true
              });
              original.dispatchEvent(clickEvent);
              window.setTimeout(function () {
                if (bypassInput) {
                  bypassInput.value = '0';
                }
              }, 200);
            }
          }
        });
      });
    }
  };
})(Drupal, once);
