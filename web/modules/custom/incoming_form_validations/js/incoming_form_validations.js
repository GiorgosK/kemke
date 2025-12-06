(function (Drupal) {
  'use strict';

  /**
   * Configuration-driven requirements.
   *
   * - key: selector of the button to control.
   * - emptyFields: list of field configs that must be non-empty.
   */
  const requirements = {
    '#edit-moderation-state-to-be-assigned': {
      type: 'button',
      disabled: true,
      toggleclasses: ['govgr-btn--disabled', 'is-disabled'],
      emptyFields: [
        {
          selector: '#edit-field-protocol-incoming-0-value',
          type: 'text',
          tab_button_class: 'horizontal-tab-button-0',
          tab_button_active_class: 'selected',
          indicator: 'input',
        },
      ],
    },
    '#edit-moderation-state-fullness-check': {
      type: 'button',
      disabled: true,
      toggleclasses: ['govgr-btn--disabled', 'is-disabled'],
      emptyFields: [
        {
          selector: '#edit-field-basic-operator',
          type: 'select2',
          tab_button_class: 'horizontal-tab-button-4',
          tab_button_active_class: 'selected',
          indicator: 'span',
        },
        {
          selector: '#edit-field-protocol-incoming-0-value',
          type: 'text',
          tab_button_class: 'horizontal-tab-button-0',
          tab_button_active_class: 'selected',
          indicator: 'input',
        },
        {
          selector: '#edit-field-incoming-type',
          type: 'select',
          tab_button_class: 'horizontal-tab-button-0',
          tab_button_active_class: 'selected',
          indicator: 'select',
        },
      ],
    },
    '#edit-moderation-state-supervisor-assigned': {
      type: 'button',
      disabled: true,
      toggleclasses: ['govgr-btn--disabled', 'is-disabled'],
      emptyFields: [
        {
          selector: '#edit-field-supervisor',
          type: 'select2',
          tab_button_class: 'horizontal-tab-button-4',
          tab_button_active_class: 'selected',
          indicator: 'span',
        },
      ],
    },
  };

  const findLabelFor = (field) => {
    if (!field) {
      return null;
    }
    const id = field.getAttribute('id');
    if (id) {
      const byFor = document.querySelector(`label[for="${id}"]`);
      if (byFor) {
        return byFor;
      }
    }
    return field.closest('.js-form-item, .form-item')?.querySelector('label') || null;
  };

  const resolveIndicator = (field, indicator) => {
    if (!indicator) {
      return null;
    }
    if (indicator === 'label') {
      return findLabelFor(field);
    }
    if (typeof indicator === 'string') {
      const scoped = field?.closest('.js-form-item, .form-item')?.querySelector(indicator);
      return scoped || document.querySelector(indicator);
    }
    return null;
  };

  const isSelect2Empty = (el) => {
    const $ = window.jQuery;
    if (!$ || !el) {
      return null;
    }
    if (typeof $(el).select2 !== 'function') {
      return null;
    }
    const val = $(el).val();
    return val === undefined || val === null || val === '' || (Array.isArray(val) && val.length === 0);
  };

  function isSelect2Empty2(selector) {
    const el = document.querySelector(selector);
    if (!el) return true;

    // For <select multiple>
    if (el.multiple) {
      return el.selectedOptions.length === 0;
    }

    // For normal select
    return !el.value;
  }

  const isEmpty = (el, config = {}) => {
    if (!el) {
      return true;
    }
    if (config.type === 'select2') {
      const select2Empty = isSelect2Empty(el);
      if (select2Empty !== null) {
        return select2Empty;
      }
    }
    if (el.tagName === 'SELECT') {
      const value = el.value;
      const selectedOptions = Array.from(el.selectedOptions || []);
      if (selectedOptions.length > 0) {
        const first = selectedOptions[0].value;
        return first === undefined || first === null || String(first).trim() === '' || String(first) === '_none';
      }
      return value === undefined || value === null || String(value).trim() === '' || String(value) === '_none';
    }
    const value = el.value;
    return value === undefined || value === null || String(value).trim() === '' || String(value) === '_none';
  };

  const addHighlight = (indicator) => {
    if (!indicator) {
      return;
    }
    indicator.classList.add('ifv-missing');
    indicator.style.outline = '2px solid #c00';
    indicator.style.outlineOffset = '2px';
  };

  const clearHighlight = (indicator) => {
    if (!indicator) {
      return;
    }
    indicator.classList.remove('ifv-missing');
    indicator.style.outline = '';
    indicator.style.outlineOffset = '';
  };

  const getTabButton = (config) => {
    if (!config || !config.tab_button_class) {
      return null;
    }
    return document.querySelector(`.${config.tab_button_class}`);
  };

  const ensureTabOpen = (config) => {
    if (!config.tab_button_class) {
      return;
    }
    const tabButton = document.querySelector(`.${config.tab_button_class}`);
    if (!tabButton) {
      return;
    }
    const isActive = config.tab_button_active_class && tabButton.classList.contains(config.tab_button_active_class);
    if (!isActive) {
      const link = tabButton.querySelector('a, button');
      if (link && typeof link.click === 'function') {
        link.click();
      }
      const details = tabButton.closest('details');
      if (details) {
        details.open = true;
      }
    }
  };

  const tabFieldsFilled = (tabClass, requirement) => {
    const related = requirement.emptyFields.filter((cfg) => cfg.tab_button_class === tabClass);
    if (!related.length) {
      return true;
    }
    return related.every((cfg) => {
      const field = document.querySelector(cfg.selector);
      return !isEmpty(field, cfg);
    });
  };

  const scrollToField = (field) => {
    if (!field) {
      return;
    }
    if (typeof field.scrollIntoView === 'function') {
      field.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    if (typeof field.focus === 'function') {
      field.focus({ preventScroll: true });
    }
  };

  const updateButtonState = (button, requirement) => {
    const tabStatus = {};
    let hasEmpty = false;

    requirement.emptyFields.forEach((config) => {
      const field = document.querySelector(config.selector);
      const indicator = resolveIndicator(field, config.indicator);
      const empty = isEmpty(field, config);

      if (empty) {
        hasEmpty = true;
      }
      else {
        clearHighlight(indicator);
      }

      if (config.tab_button_class) {
        if (!(config.tab_button_class in tabStatus)) {
          tabStatus[config.tab_button_class] = true;
        }
        if (empty) {
          tabStatus[config.tab_button_class] = false;
        }
      }
    });

    Object.entries(tabStatus).forEach(([tabClass, filled]) => {
      if (filled) {
        clearHighlight(getTabButton({ tab_button_class: tabClass }));
      }
    });

    const disabled = requirement.disabled && hasEmpty;
    button.dataset.ifvDisabled = disabled ? 'true' : 'false';
    button.setAttribute('aria-disabled', disabled ? 'true' : 'false');
    (requirement.toggleclasses || []).forEach((cls) => button.classList.toggle(cls, disabled));
  };

  const attachHandlers = (buttonSelector, requirement) => {
    const button = document.querySelector(buttonSelector);
    if (!button || button.dataset.ifvAttached === 'true') {
      return;
    }
    button.dataset.ifvAttached = 'true';

    const refresh = () => updateButtonState(button, requirement);

    requirement.emptyFields.forEach((config) => {
      const field = document.querySelector(config.selector);
      if (field) {
        const events = ['input', 'change', 'blur', 'keyup'];
        const handler = () => {
          if (!isEmpty(field, config)) {
            clearHighlight(resolveIndicator(field, config.indicator));
            if (config.tab_button_class && tabFieldsFilled(config.tab_button_class, requirement)) {
              clearHighlight(getTabButton(config));
            }
          }
          refresh();
        };
        events.forEach((evt) => field.addEventListener(evt, handler));

        if (config.type === 'select2' && window.jQuery) {
          const $field = window.jQuery(field);
          if (typeof $field.on === 'function') {
            $field.on('select2:select select2:unselect select2:clear select2:close select2:opening select2:closing select2:open', handler);
          }
        }
      }
      else {
      }
    });

    button.addEventListener('click', (event) => {
      if (button.dataset.ifvDisabled !== 'true') {
        return;
      }
      event.preventDefault();

      // Clear any previous indicators before adding new ones.
      document.querySelectorAll('.ifv-missing').forEach((el) => clearHighlight(el));

      requirement.emptyFields.forEach((config) => {
        const field = document.querySelector(config.selector);
        const indicator = resolveIndicator(field, config.indicator);
        const tabButton = getTabButton(config);
        if (isEmpty(field, config)) {
          addHighlight(indicator || field);
          addHighlight(tabButton);
          ensureTabOpen(config);
          scrollToField(indicator || field || button);
        }
      });
    });

    refresh();
  };

  Drupal.behaviors.incomingFormValidations = {
    attach() {
      Object.entries(requirements).forEach(([buttonSelector, requirement]) => {
        attachHandlers(buttonSelector, requirement);
      });
      // Safety: re-check shortly after attach for dynamic widgets (e.g. select2).
      setTimeout(() => {
        Object.entries(requirements).forEach(([buttonSelector, requirement]) => {
          const button = document.querySelector(buttonSelector);
          if (button) {
            updateButtonState(button, requirement);
          }
        });
      }, 300);
      // Second pass in case select2 initializes later.
      setTimeout(() => {
        Object.entries(requirements).forEach(([buttonSelector, requirement]) => {
          const button = document.querySelector(buttonSelector);
          if (button) {
            updateButtonState(button, requirement);
          }
        });
      }, 1000);
    },
  };
})(Drupal);
