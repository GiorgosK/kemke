(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.kemkeIncomingPrint = {
    attach: function (context) {
      once('kemke-incoming-print', '.kemke-incoming-print-button', context).forEach(function (button) {
        button.addEventListener('click', function () {
          window.print();
        });
      });
    },
  };
})(Drupal, once);
