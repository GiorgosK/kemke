(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.kemkeIncomingPrint = {
    attach: function (context) {
      once('kemke-incoming-print', '.kemke-incoming-print-link', context).forEach(function (link) {
        link.addEventListener('click', function (event) {
          event.preventDefault();
          window.print();
        });
      });
    },
  };
})(Drupal, once);
