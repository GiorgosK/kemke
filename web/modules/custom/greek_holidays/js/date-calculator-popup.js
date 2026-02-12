(function (Drupal, drupalSettings, once) {
  'use strict';

  function buildDialog(config, targetInputId) {
    var dialog = document.createElement('dialog');
    dialog.className = 'greek-holidays-calc-dialog';

    var header = document.createElement('div');
    header.className = 'greek-holidays-calc-dialog__header';

    var title = document.createElement('h3');
    title.className = 'greek-holidays-calc-dialog__title';
    title.textContent = config.title || 'Date calculator';

    var closeBtn = document.createElement('button');
    closeBtn.type = 'button';
    closeBtn.className = 'greek-holidays-calc-dialog__close';
    closeBtn.textContent = '\u00d7';
    closeBtn.setAttribute('aria-label', config.closeLabel || 'Close');

    header.appendChild(title);
    header.appendChild(closeBtn);

    var body = document.createElement('div');
    body.className = 'greek-holidays-calc-dialog__body';

    var startLabel = document.createElement('label');
    startLabel.className = 'govgr-label';
    startLabel.textContent = config.startDateLabel || 'Start date';
    var startInput = document.createElement('input');
    startInput.type = 'date';
    startInput.className = 'govgr-input';
    startInput.value = new Date().toISOString().slice(0, 10);

    var daysLabel = document.createElement('label');
    daysLabel.className = 'govgr-label';
    daysLabel.textContent = config.workingDaysLabel || 'Working days';
    var daysInput = document.createElement('input');
    daysInput.type = 'number';
    daysInput.min = '0';
    daysInput.step = '1';
    daysInput.value = '20';
    daysInput.className = 'govgr-input';

    var actions = document.createElement('div');
    actions.className = 'greek-holidays-calc-dialog__actions';

    var fetchBtn = document.createElement('button');
    fetchBtn.type = 'button';
    fetchBtn.className = 'govgr-btn govgr-btn-primary';
    fetchBtn.textContent = config.fetchLabel || 'Fetch';

    actions.appendChild(fetchBtn);
    body.appendChild(startLabel);
    body.appendChild(startInput);
    body.appendChild(daysLabel);
    body.appendChild(daysInput);

    dialog.appendChild(header);
    dialog.appendChild(body);
    dialog.appendChild(actions);

    closeBtn.addEventListener('click', function () {
      dialog.close();
      dialog.remove();
    });

    fetchBtn.addEventListener('click', function () {
      var startDate = startInput.value;
      var workingDays = daysInput.value;
      if (!startDate || workingDays === '' || Number(workingDays) < 0) {
        window.alert(config.invalidInputMessage || 'Invalid input');
        return;
      }

      var url = config.endpoint + '?start_date=' + encodeURIComponent(startDate) + '&working_days=' + encodeURIComponent(workingDays);
      fetch(url, {credentials: 'same-origin'})
        .then(function (response) {
          return response.json().then(function (data) {
            if (!response.ok) {
              throw new Error(data && data.error ? data.error : 'Request failed');
            }
            return data;
          });
        })
        .then(function (payload) {
          var target = document.getElementById(targetInputId);
          if (!target) {
            throw new Error('Target field not found');
          }

          target.value = payload.end_date || '';
          target.dispatchEvent(new Event('input', {bubbles: true}));
          target.dispatchEvent(new Event('change', {bubbles: true}));
          dialog.close();
          dialog.remove();
        })
        .catch(function (error) {
          window.alert(error.message || config.requestFailedMessage || 'Unable to calculate date');
        });
    });

    return dialog;
  }

  Drupal.behaviors.greekHolidaysDateCalculatorPopup = {
    attach: function (context) {
      var config = drupalSettings.greekHolidaysDateCalculator || {};
      if (!config.endpoint) {
        return;
      }

      once('greek-holidays-calc-trigger', '.js-greek-holidays-calc-trigger', context).forEach(function (trigger) {
        trigger.addEventListener('click', function (event) {
          event.preventDefault();

          var targetInputId = trigger.getAttribute('data-target-input-id');
          if (!targetInputId) {
            return;
          }

          var dialog = buildDialog(config, targetInputId);
          document.body.appendChild(dialog);
          dialog.showModal();
        });
      });
    }
  };

})(Drupal, drupalSettings, once);
