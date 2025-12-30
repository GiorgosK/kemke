(function (Drupal, once, drupalSettings) {
  'use strict';

  const toDrupalSelector = (fieldName) =>
    `edit-${fieldName.replace(/_/g, '-')}`;

  const findFieldWrappers = (context, fieldName) => {
    const selector = `[data-drupal-selector="${toDrupalSelector(fieldName)}"]`;
    return Array.from(context.querySelectorAll(selector));
  };

  const bindCheckboxGroup = (wrapper) => {
    const checkboxes = Array.from(
      wrapper.querySelectorAll('input[type="checkbox"]')
    );

    if (checkboxes.length === 0) {
      return;
    }

    checkboxes.forEach((checkbox) => {
      checkbox.addEventListener('change', () => {
        if (!checkbox.checked) {
          return;
        }

        checkboxes.forEach((other) => {
          if (other === checkbox || !other.checked) {
            return;
          }

          other.checked = false;
          other.dispatchEvent(new Event('change', { bubbles: true }));
        });
      });
    });
  };

  Drupal.behaviors.checkboxRadios = {
    attach(context) {
      const fields = drupalSettings.checkboxRadios?.fields || [];
      if (fields.length === 0) {
        return;
      }

      fields.forEach((fieldName) => {
        findFieldWrappers(context, fieldName).forEach((wrapper) => {
          once('checkbox-radios', wrapper).forEach(bindCheckboxGroup);
        });
      });
    },
  };
})(Drupal, once, drupalSettings);
