(function (Drupal) {
  'use strict';

  /**
   * Rulesets are defined once and then explicitly composed per role.
   * Add new keys under ruleSets, then include them in roleRuleSets.
   */
  const ruleIncTypeOpinion = 
    {
      selector: '#edit-field-incoming-type',
      type: 'select',
      value: ['41', '3'], // Άποψη, Γνωμοδότηση
    };
  const ruleIncTypeAllExceptOpinion = 
    {
      selector: '#edit-field-incoming-type',
      type: 'select',
      value: ['9', '2', '5', '42', '8', '9', '_none'],
    };
  const ruleIncTypeEE = 
    {
      selector: '#edit-field-incoming-type',
      type: 'select',
      value: '2',
    };
  const ruleIncTypeNone = 
    {
      selector: '#edit-field-incoming-type',
      type: 'select',
      value: ['_none'],
    };
  const ruleIncTypeGnomodotisi = 
    {
      selector: '#edit-field-incoming-type',
      type: 'select',
      value: ['3'], // Γνωμοδότηση
    };
  const ruleIncTypeKoinopGnostop = 
    {
      selector: '#edit-field-incoming-type',
      type: 'select',
      value: ['5','6'], // Κοινοποιήση και Γνωστοποιήση
    };
  const ruleButtonUndeprocessingStay = 
    {
      selector: '#edit-moderation-state-under-processing',
      type: 'input',
      value: 'Αποθήκευση',
    };
  const ruleSubtypeSariChecked =
    {
      selector: '#edit-field-incoming-subtype-59',
      type: 'checkbox',
      value: 'checked',
    };
  const ruleSubtypeAnaktisiChecked =
    {
      selector: '#edit-field-incoming-subtype-61',
      type: 'checkbox',
      value: 'checked',
    };

  const ruleSets = {
    baseAssignment: [
      {
        selector: '#edit-moderation-state-to-be-assigned',
        rules: [
          {
            type: 'button',
            disabled: true,
            toggleclasses: ['govgr-btn--disabled', 'is-disabled'],
            emptyFields: [],
          },
        ],
      },
    ],
    baseFullness: [
      {
        selector: '#edit-moderation-state-fullness-check',
        rules: [
          {
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
                selector: '#edit-field-supervisor',
                type: 'select2',
                tab_button_class: 'horizontal-tab-button-4',
                tab_button_active_class: 'selected',
                indicator: 'span',
              },
              {
                selector: '#edit-field-incoming-type',
                type: 'select',
                tab_button_class: 'horizontal-tab-button-2',
                tab_button_active_class: 'selected',
                indicator: 'select',
              },
            ],
          },
        ],
      },
    ],
    baseForSignature: [
      {
        selector: '#edit-moderation-state-for-signature',
        rules: [
          {
            type: 'button',
            disabled: true,
            toggleclasses: ['govgr-btn--disabled', 'is-disabled'],
            emptyFields: [
              {
                selector: '#edit-field-plan-0-upload',
                type: 'file',
                tab_button_class: 'horizontal-tab-button-5',
                tab_button_active_class: 'selected',
                indicator: 'div',
              },
            ],
          },
          {
            type: 'hideIf',
            valueIsAND: [
              ruleIncTypeAllExceptOpinion,
              ruleButtonUndeprocessingStay
            ],
          },
        ],
      },
    ],
    baseTabsVis: [
      {
        selector: '.horizontal-tab-button-7',
        rules: [
          {
            type: 'hideIf',
            display: 'none',
            valueNot: [ruleIncTypeEE],
          },
        ],
      },
      {
        selector: '.horizontal-tab-button-5',
        rules: [
          {
            type: 'hideIf',
            valueNot: [ruleIncTypeOpinion],
          },
        ],
      },
    ],
    basePublishedVis: [
      {
        selector: '#edit-moderation-state-published',
        rules: [
          {
            type: 'hideIf',
            valueIsAND: [ruleIncTypeOpinion,ruleButtonUndeprocessingStay],
          },
        ],
      },
    ],
    baseOpinionRefIdVis: [
      {
        selector: '.field--name-field-opinion-ref-id',
        rules: [
          {
            type: 'hideIf',
            valueNot: [ruleIncTypeGnomodotisi],
          },
        ],
      },
    ],
    baseEditGroupSubtypeVis: [
      {
        selector: '#edit-group-subtype',
        rules: [{
            type: 'hideIf',
            valueNotAND: [ruleIncTypeOpinion,ruleIncTypeEE],
        },],
      },
    ],
    baseSubtypeHierarchyVis: [
      {
        selector: '.form-item-field-incoming-subtype-60',
        rules: [{
            type: 'hideIf',
            valueNot: [ruleIncTypeOpinion],
        },],
      },
    ],
    baseSubtypeAnaktisiVis: [
      {
        selector: '.form-item-field-incoming-subtype-61',
        rules: [{
            type: 'hideIf',
            valueNot: [ruleIncTypeEE],
        },],
      },
    ],
    baseSubtypeSariVis: [
      {
        selector: '.form-item-field-incoming-subtype-59',
        rules: [{
            type: 'hideIf',
            valueNot: [ruleIncTypeEE],
        },],
      },
    ],
    baseGroupSignatureVis: [
      {
        selector: '#edit-group-signature-rejection',
        rules: [{
            type: 'hideIf',
            valueNot: [ruleIncTypeKoinopGnostop],
        },],
      },
    ],
    baseGroupReportCasesVis: [
      {
        selector: '#edit-group-report-cases',
        rules: [{
            type: 'hideIf',
            valueNot: [ruleSubtypeSariChecked],
        },],
      },
    ],
    baseGroupExtensionVis: [
      {
        selector: '#edit-group-extension',
        rules: [{
            type: 'hideIf',
            valueNot: [ruleSubtypeAnaktisiChecked],
        },],
      },
    ],
    baseSubtypeDateVis: [
      {
        selector: '.field--name-field-subtype-date',
        rules: [{
            type: 'hideIf',
            valueNot: [ruleSubtypeAnaktisiChecked],
        },],
      },
    ],
   
    // Example reusable ruleset (shared across multiple roles).
    // rulesetExample: [
    //   { selector: '#edit-submit', rules: [{ type: 'button', ... }] },
    // ],
    // Example showWhenFilled ruleset.
    // rulesetShowWhenFilled: [
    //   {
    //     selector: '#edit-field-selector-wrapper',
    //     rules: [
    //       {
    //         type: 'showWhenFilled',
    //         mode: 'all', // or 'any'
    //         display: 'none',
    //         fields: [
    //           { selector: '#edit-field-one', type: 'select' },
    //           { selector: '#edit-field-two', type: 'input' },
    //         ],
    //       },
    //     ],
    //   },
    // ],
  };

  /**
   * Explicit assignment of rulesets per role.
   * Compose using spread: default gets base sets; roles extend with extras.
   */
  const roleRuleSets = {
    default: [
      ...ruleSets.baseAssignment,
      ...ruleSets.baseFullness,
      ...ruleSets.baseForSignature,
      ...ruleSets.baseTabsVis,
      ...ruleSets.basePublishedVis,
      ...ruleSets.baseOpinionRefIdVis,
      ...ruleSets.baseEditGroupSubtypeVis,
      ...ruleSets.baseSubtypeHierarchyVis,
      ...ruleSets.baseGroupSignatureVis,
      ...ruleSets.baseGroupReportCasesVis,
      ...ruleSets.baseGroupExtensionVis,
      ...ruleSets.baseSubtypeDateVis,
      ...ruleSets.baseSubtypeAnaktisiVis,
      ...ruleSets.baseSubtypeSariVis, 
    ],
    // amke_user: [
    //   ...ruleSets.baseAssignment,
    //   ...ruleSets.baseFullness,
    //   ...ruleSets.baseForSignature,
    //   ...ruleSets.baseTabsVisibility,
    //   ...ruleSets.basePublishedVisibility,
    //   ...ruleSets.rulesetShowWhenFilled,
    // ],
    // manager: [
    //   ...ruleSets.baseAssignment,
    //   ...ruleSets.baseFullness,
    //   ...ruleSets.baseForSignature,
    //   ...ruleSets.baseTabsVisibility,
    //   ...ruleSets.rulesetExample,
    // ],
  };

  const getCurrentRoles = () => {
    const rolesFromSettings =
      (typeof drupalSettings !== 'undefined' && (drupalSettings.user?.roles || drupalSettings.user?.data?.roles)) ||
      [];
    if (Array.isArray(rolesFromSettings)) {
      return rolesFromSettings;
    }
    if (typeof rolesFromSettings === 'object' && rolesFromSettings !== null) {
      return Object.keys(rolesFromSettings).filter((key) => rolesFromSettings[key]);
    }
    return [];
  };

  const buildActiveRequirements = () => {
    const roles = getCurrentRoles();
    const active = [...(roleRuleSets.default || [])];
    roles.forEach((role) => {
      if (roleRuleSets[role]) {
        active.push(...roleRuleSets[role]);
      }
    });
    return active;
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
    if (!el && config.type === 'file') {
      const fallbackFidsSelector = config.selector?.replace('-upload', '-fids');
      const hiddenFids =
        (fallbackFidsSelector && document.querySelector(fallbackFidsSelector)) ||
        document.querySelector('input[type="hidden"][name*="[fids]"][data-drupal-selector*="field-plan"]');
      if (hiddenFids) {
        const val = String(hiddenFids.value || '').trim();
        return val === '';
      }
      return true;
    }
    if (!el) {
      return true;
    }
    if (config.type === 'file' || el.type === 'file') {
      if (el.files && el.files.length !== undefined) {
        return el.files.length === 0;
      }
      const wrapper = el.closest('.js-form-managed-file');
      if (wrapper) {
        const hiddenFids = wrapper.querySelector('input[type="hidden"][name*="[fids]"]');
        if (hiddenFids) {
          const val = String(hiddenFids.value || '').trim();
          if (val !== '') {
            return false;
          }
        }
      }
      return !el.value;
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
    indicator.style.outline = '3px solid #c00';
    indicator.style.outlineOffset = '-3px';
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

  const getFieldValue = (field, type) => {
    if (!field) {
      return null;
    }
    if (type === 'checkbox' || field.type === 'checkbox') {
      return field.checked ? 'checked' : 'unchecked';
    }
    if (type === 'select2') {
      const select2Val = isSelect2Empty(field);
      if (select2Val !== null) {
        const $ = window.jQuery;
        return $ ? $(field).val() : field.value;
      }
    }
    if (field.tagName === 'SELECT') {
      const selectedOptions = Array.from(field.selectedOptions || []);
      if (selectedOptions.length > 0) {
        return selectedOptions[0].value;
      }
    }
    return field.value;
  };

  const shouldHideByValueNot = (cfg) => {
    const field = document.querySelector(cfg.selector);
    if (!field) {
      return false;
    }
    const value = getFieldValue(field, cfg.type);
    if (Array.isArray(cfg.value)) {
      const allowed = cfg.value.map((v) => String(v));
      return !allowed.includes(String(value));
    }
    return String(value) !== String(cfg.value);
  };

  const shouldHideByValueIs = (cfg) => {
    const field = document.querySelector(cfg.selector);
    if (!field) {
      return false;
    }
    const value = getFieldValue(field, cfg.type);
    if (Array.isArray(cfg.value)) {
      const allowed = cfg.value.map((v) => String(v));
      return allowed.includes(String(value));
    }
    return String(value) === String(cfg.value);
  };

  const shouldHideByRequirements = (requirement) => {
    const valueNotOR = requirement.valueNotOR || requirement.valueNot || [];
    const valueNotAND = requirement.valueNotAND || [];
    const valueIsOR = requirement.valueIsOR || requirement.valueIs || [];
    const valueIsAND = requirement.valueIsAND || [];

    const notOr = valueNotOR.some((cfg) => shouldHideByValueNot(cfg));
    const notAnd = valueNotAND.length > 0 && valueNotAND.every((cfg) => shouldHideByValueNot(cfg));
    const isOr = valueIsOR.some((cfg) => shouldHideByValueIs(cfg));
    const isAnd = valueIsAND.length > 0 && valueIsAND.every((cfg) => shouldHideByValueIs(cfg));

    return notOr || notAnd || isOr || isAnd;
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

  const tabFieldsFilled = (tabClass, rule) => {
    const related = (rule.emptyFields || []).filter((cfg) => cfg.tab_button_class === tabClass);
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

  const updateButtonState = (button, rule) => {
    const tabStatus = {};
    let hasEmpty = false;

    (rule.emptyFields || []).forEach((config) => {
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

    const disabled = rule.disabled && hasEmpty;
    button.dataset.ifvDisabled = disabled ? 'true' : 'false';
    button.setAttribute('aria-disabled', disabled ? 'true' : 'false');
    (rule.toggleclasses || []).forEach((cls) => button.classList.toggle(cls, disabled));
  };

  const attachHandlers = (buttonSelector, rule) => {
    const button = document.querySelector(buttonSelector);
    if (!button || button.dataset.ifvAttached === 'true') {
      return;
    }
    button.dataset.ifvAttached = 'true';

    const refresh = () => updateButtonState(button, rule);

    (rule.emptyFields || []).forEach((config) => {
      const field = document.querySelector(config.selector);
      if (field) {
        const events = ['input', 'change', 'blur', 'keyup'];
        const handler = () => {
          if (!isEmpty(field, config)) {
            clearHighlight(resolveIndicator(field, config.indicator));
            if (config.tab_button_class && tabFieldsFilled(config.tab_button_class, rule)) {
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

      (rule.emptyFields || []).forEach((config) => {
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

  const applyVisibility = (selector, rule) => {
    const el = document.querySelector(selector);
    const hasConditions =
      (rule.valueNotOR && rule.valueNotOR.length) ||
      (rule.valueNot && rule.valueNot.length) ||
      (rule.valueNotAND && rule.valueNotAND.length) ||
      (rule.valueIsOR && rule.valueIsOR.length) ||
      (rule.valueIs && rule.valueIs.length) ||
      (rule.valueIsAND && rule.valueIsAND.length);
    if (!el || !hasConditions) {
      return;
    }
    el.style.display = shouldHideByRequirements(rule) ? rule.display || 'none' : '';
  };

  const attachVisibilityHandler = (selector, rule) => {
    const watchers = [
      ...(rule.valueNotOR || []),
      ...(rule.valueNotAND || []),
      ...(rule.valueNot || []),
      ...(rule.valueIsOR || []),
      ...(rule.valueIsAND || []),
      ...(rule.valueIs || []),
    ];
    if (!watchers.length) {
      return;
    }
    watchers.forEach((cfg) => {
      const field = document.querySelector(cfg.selector);
      if (!field) {
        return;
      }
      const events = ['input', 'change', 'blur', 'keyup'];
      const handler = () => applyVisibility(selector, rule);
      events.forEach((evt) => field.addEventListener(evt, handler));

      if (cfg.type === 'select2' && window.jQuery) {
        const $field = window.jQuery(field);
        if (typeof $field.on === 'function') {
          $field.on('select2:select select2:unselect select2:clear select2:close select2:opening select2:closing select2:open', handler);
        }
      }
    });

    applyVisibility(selector, rule);
  };

  const showWhenFilled = (selector, rule) => {
    const el = document.querySelector(selector);
    if (!el || !(rule.fields || []).length) {
      return;
    }
    const mode = rule.mode === 'any' ? 'any' : 'all';
    const filledCount = (rule.fields || []).reduce((count, cfg) => {
      const field = document.querySelector(cfg.selector);
      return !isEmpty(field, cfg) ? count + 1 : count;
    }, 0);
    const shouldShow = mode === 'all'
      ? filledCount === (rule.fields || []).length
      : filledCount > 0;
    el.style.display = shouldShow ? '' : rule.display || 'none';
  };

  const attachShowWhenFilled = (selector, rule) => {
    const watchers = rule.fields || [];
    if (!watchers.length) {
      return;
    }
    const handler = () => showWhenFilled(selector, rule);
    watchers.forEach((cfg) => {
      const field = document.querySelector(cfg.selector);
      if (!field) {
        return;
      }
      const events = ['input', 'change', 'blur', 'keyup'];
      events.forEach((evt) => field.addEventListener(evt, handler));
      if (cfg.type === 'select2' && window.jQuery) {
        const $field = window.jQuery(field);
        if (typeof $field.on === 'function') {
          $field.on('select2:select select2:unselect select2:clear select2:close select2:opening select2:closing select2:open', handler);
        }
      }
    });
    handler();
  };

  const expandRequirements = (requirements) => {
    const pairs = [];
    requirements.forEach((req) => {
      (req.rules || []).forEach((rule) => pairs.push([req.selector, rule]));
    });
    return pairs;
  };

  Drupal.behaviors.incomingFormValidations = {
    attach() {
      const activeRequirements = buildActiveRequirements();
      const rules = expandRequirements(activeRequirements);

      rules.forEach(([selector, rule]) => {
        if (rule.type === 'hideIf') {
          attachVisibilityHandler(selector, rule);
          return;
        }
        if (rule.type === 'showWhenFilled') {
          attachShowWhenFilled(selector, rule);
          return;
        }
        attachHandlers(selector, rule);
      });
      // Safety: re-check shortly after attach for dynamic widgets (e.g. select2).
      setTimeout(() => {
        rules.forEach(([selector, rule]) => {
          if (rule.type === 'hideIf') {
            applyVisibility(selector, rule);
          }
          else if (rule.type === 'showWhenFilled') {
            showWhenFilled(selector, rule);
          }
          else {
            const button = document.querySelector(selector);
            if (button) {
              updateButtonState(button, rule);
            }
          }
        });
      }, 300);
      // Second pass in case select2 initializes later.
      setTimeout(() => {
        rules.forEach(([selector, rule]) => {
          if (rule.type === 'hideIf') {
            applyVisibility(selector, rule);
          }
          else if (rule.type === 'showWhenFilled') {
            showWhenFilled(selector, rule);
          }
          else {
            const button = document.querySelector(selector);
            if (button) {
              updateButtonState(button, rule);
            }
          }
        });
      }, 1000);
    },
  };
})(Drupal);
