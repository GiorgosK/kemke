(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.opinionRefIdTweaks = {
    attach: function (context) {
      once('opinion-ref-id-generate', 'a.opinion-ref-id-generate', context).forEach(function (link) {
        link.addEventListener('click', function () {
          var input = document.getElementById('opinion-ref-id-example');
          if (!input) {
            return;
          }

          var href = link.getAttribute('href');
          if (!href) {
            return;
          }

          var url = new URL(href, window.location.origin);
          var target = link.getAttribute('data-opinion-ref-id-target');
          if (target) {
            url.searchParams.set('target', target);
          }
          link.setAttribute('href', url.pathname + url.search);
        });
      });
    }
  };
})(Drupal, once);
