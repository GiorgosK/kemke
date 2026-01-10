(function ($, Drupal) {
  'use strict';

  Drupal.behaviors.incomingCreateSelectCaseModal = {
    attach: function () {
      $.fn.incomingCreateSelectCaseInject = function (data) {
        var payload = JSON.parse(data);
        var $select = $('#edit-field-case');
        if (!$select.length || !payload || !payload.tid) {
          return;
        }

        var tid = String(payload.tid);
        var label = payload.label || tid;
        if ($select.find('option[value="' + tid + '"]').length === 0) {
          $select.append(new Option(label, tid, true, true));
        } else {
          $select.find('option[value="' + tid + '"]').prop('selected', true);
        }

        $select.trigger('change');
      };
    }
  };
})(jQuery, Drupal);
