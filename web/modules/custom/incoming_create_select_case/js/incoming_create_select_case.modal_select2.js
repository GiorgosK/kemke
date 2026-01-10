(function ($, Drupal) {
  'use strict';

  function initSelect2InModal(context) {
    var $modal = $(context).closest('.ui-dialog');
    if (!$modal.length) {
      $modal = $(context).closest('.ui-dialog-content');
    }
    if (!$modal.length) {
      return;
    }

    $(context).find('select.select2-widget').each(function () {
      var $select = $(this);
      if (typeof $select.select2 !== 'function') {
        return;
      }

      if ($select.hasClass('select2-hidden-accessible')) {
        $select.select2('destroy');
      }

      $select.removeAttr('data-once');
      var config = $select.data('select2-config') || {};
      config.dropdownParent = $modal;
      if (!config.width) {
        config.width = '100%';
      }
      $select.select2(config);
    });
  }

  Drupal.behaviors.incomingCreateSelectCaseModalSelect2 = {
    attach: function (context) {
      initSelect2InModal(context);
    }
  };
})(jQuery, Drupal);
