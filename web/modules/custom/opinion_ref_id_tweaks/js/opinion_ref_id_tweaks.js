(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.opinionRefIdTweaks = {
    attach: function (context) {
      once('opinion-ref-id-format-check', 'input[id^="edit-field-opinion-ref-id-"][name$="[value]"], input#opinion-ref-id-field', context).forEach(function (input) {
        var currentYear = String(new Date().getFullYear());
        var formatRegex = /^ΓΝ([1-9]\d*)-(\d{4})$/;

        var applyValidityStyle = function () {
          var value = (input.value || '').trim();
          var matches = value.match(formatRegex);
          var isValid = matches && matches[2] === currentYear;

          if (value === '' || isValid) {
            input.style.borderColor = '';
            input.style.backgroundColor = '';
            input.removeAttribute('aria-invalid');
            return;
          }

          input.style.borderColor = '#d72222';
          input.style.backgroundColor = '#fff5f5';
          input.setAttribute('aria-invalid', 'true');
        };

        input.addEventListener('input', applyValidityStyle);
        input.addEventListener('blur', applyValidityStyle);
        applyValidityStyle();
      });

      once('opinion-ref-id-generate', 'a.opinion-ref-id-generate', context).forEach(function (link) {
        link.addEventListener('click', function () {
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
