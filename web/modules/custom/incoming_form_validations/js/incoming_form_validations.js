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
  const ruleIncTypePlan = 
    {
      selector: '#edit-field-incoming-type',
      type: 'select',
      value: ['41', '3', '2', '5','6'], // Άποψη, Γνωμοδότηση, ΕΕ, Κοινοποιήση και Γνωστοποιήση
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
      value: ['2', '5','6'], //ΕΕ, Κοινοποιήση και Γνωστοποιήση
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
  const ruleDocStatusUnderProcessing =
    {
      selector: '[doc-status="under_processing"]',
      type: 'exists',
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
  const ruleExtensionChecked =
    {
      selector: '#edit-field-extension-value',
      type: 'checkbox',
      value: 'checked',
    };
  const ruleSignatureChecked =
    {
      selector: '#edit-field-signature-rejection-signature',
      type: 'checkbox',
      value: 'checked',
    };
  const ruleRejectionChecked =
    {
      selector: '#edit-field-signature-rejection-rejection',
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
                tab_button_links: ['#edit-group-responsible'],
                tab_button_active_class: 'selected',
                indicator: 'span',
              },
              {
                selector: '#edit-field-supervisor',
                type: 'select2',
                tab_button_links: ['#edit-group-responsible'],
                tab_button_active_class: 'selected',
                indicator: 'span',
              },
              {
                selector: '#edit-field-incoming-type',
                type: 'select',
                tab_button_links: ['#edit-group-status'],
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
                tab_button_links: ['#edit-group-answer', '#edit-group-plan'],
                tab_button_active_class: 'selected',
                indicator: 'div',
              },
              {
                selector: '#opinion-ref-id-field, #edit-field-opinion-ref-id-0-value',
                type: 'input',
                // Required only for incoming type = Γνωμοδότηση (value 3).
                requires: {
                  valueIs: [ruleIncTypeGnomodotisi],
                },
                tab_button_links: ['#edit-group-status'],
                tab_button_active_class: 'selected',
                indicator: 'input',
              },
            ],
          },
        ],
      },
    ],
    OpinionRefIdVis: [
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
    OpinionRefIdDisabled: [
      {
        selector: [
          '#opinion-ref-id-field',
          '#edit-field-opinion-ref-id-0-value',
          '#edit-field-opinion-ref-id-0-opinion-ref-id-tweaks-generate',
        ],
        rules: [
          {
            type: 'disableIf',
            valueNot: [ruleDocStatusUnderProcessing],
          },
        ],
      },
    ],
    baseForCompleted: [
      {
        selector: '#edit-moderation-state-published',
        rules: [
          {
            type: 'require',
            valueIs: [ruleDocStatusUnderProcessing],
          },
          {
            type: 'button',
            disabled: true,
            toggleclasses: ['govgr-btn--disabled', 'is-disabled'],
            emptyFields: [
              {
                selector: '[data-drupal-selector="edit-field-answer-files"]',
                type: 'file',
                tab_button_links: ['#edit-group-answer', '#edit-group-no-plan'],
                tab_button_active_class: 'selected',
                indicator: 'div',
              },
            ],
          },
          {
            type: 'hideIf',
            valueNot: [ruleIncTypePlan],
          },
        ],
      },
    ],    
    TabEEVis: [
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
    ],
    TabPlanVis: [
      {
        selector: [
          '.horizontal-tab-button-5',
          '.horizontal-tab-button-6'
        ],
        rules: [
          {
            type: 'hideIf',
            valueNot: [ruleIncTypePlan],
          },
        ],
      },
    ],
    EditGroupSubtypeVis: [
      {
        selector: '#edit-group-subtype',
        rules: [{
            type: 'hideIf',
            valueNotAND: [ruleIncTypeOpinion,ruleIncTypeEE],
        },],
      },
    ],
    SubtypeHierarchyVis: [
      {
        selector: '.form-item-field-incoming-subtype-60',
        rules: [{
            type: 'hideIf',
            valueNot: [ruleIncTypeOpinion],
        },],
      },
    ],
    SubtypeAnaktisiVis: [
      {
        selector: '.form-item-field-incoming-subtype-61',
        rules: [{
            type: 'hideIf',
            valueNot: [ruleIncTypeEE],
        },],
      },
    ],
    SubtypeSariVis: [
      {
        selector: '.form-item-field-incoming-subtype-59',
        rules: [{
            type: 'hideIf',
            valueNot: [ruleIncTypeEE],
        },],
      },
    ],
    GroupSignatureRejecionVis: [
      {
        selector: '#edit-group-signature-rejection',
        rules: [{
            type: 'hideIf',
            valueNot: [ruleIncTypeKoinopGnostop],
        },],
      },
    ],
    GroupReportCasesVis: [
      {
        selector: '#edit-group-report-cases',
        rules: [{
            type: 'hideIf',
            valueNot: [ruleSubtypeSariChecked],
        },],
      },
    ],
    GroupExtensionVis: [
      {
        selector: '#edit-group-extension',
        rules: [{
            type: 'hideIf',
            valueNotAND: [ruleSubtypeAnaktisiChecked,ruleIncTypeEE],
        },],
      },
    ],
    SubtypeDateVis: [
      {
        selector: '.field--name-field-subtype-date',
        rules: [{
            type: 'hideIf',
            valueNot: [ruleSubtypeAnaktisiChecked],
        },],
      },
    ],
    ExtensionDateVis: [
      {
        selector: '.field--name-field-extension-date',
        rules: [{
            type: 'hideIf',
            valueNot: [ruleExtensionChecked],
        },],
      },
    ],
    SignatureRejectionDateVis: [
      {
        selector: '#edit-field-signature-rejection-date-wrapper',
        rules: [{
            type: 'hideIf',
            valueNotAND: [ruleSignatureChecked, ruleRejectionChecked],
        },],
      },
    ],
    PendingIssuesRequiresLegalEntity: [
      {
        selector: '#edit-moderation-state-pending-issues',
        rules: [
          {
            type: 'button',
            disabled: true,
            toggleclasses: ['govgr-btn--disabled', 'is-disabled'],
            emptyFields: [
              {
                selector: '#edit-field-legal-entity',
                type: 'select2',
                tab_button_links: ['#edit-group-status'],                
                tab_button_active_class: 'selected',
                indicator: '.select2-selection',
              },
            ],
          },
        ],
      },
    ],
   
    // Example reusable ruleset (shared across multiple roles).
    // rulesetExample: [
    //   {
    //     selector: '#edit-submit',
    //     rules: [
    //       { type: 'require', valueNot: [ruleDocStatusUnderProcessing] },
    //       { type: 'require', valueIs: [{ selector: '[doc-status]', type: 'attribute', attribute: 'doc-status', value: 'for_signature' }] },
    //       // Clear exists examples:
    //       // Run validation only when this selector exists.
    //       { type: 'require', valueIs: [{ selector: '[doc-status=\"under_processing\"]', type: 'exists' }] },
    //       // Run validation only when this selector does NOT exist.
    //       { type: 'require', valueNot: [{ selector: '[doc-status=\"published\"]', type: 'exists' }] },
    //       { type: 'button', disabled: true, ... },
    //     ],
    //   },
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
      ...ruleSets.TabEEVis,
      ...ruleSets.TabPlanVis,
      ...ruleSets.baseForCompleted,
      ...ruleSets.OpinionRefIdDisabled,
      ...ruleSets.OpinionRefIdVis,
      ...ruleSets.EditGroupSubtypeVis,
      ...ruleSets.SubtypeHierarchyVis,
      ...ruleSets.GroupSignatureRejecionVis,
      ...ruleSets.GroupReportCasesVis,
      ...ruleSets.GroupExtensionVis,
      ...ruleSets.SubtypeDateVis,
      ...ruleSets.SubtypeAnaktisiVis,
      ...ruleSets.SubtypeSariVis, 
      ...ruleSets.ExtensionDateVis,
      ...ruleSets.SignatureRejectionDateVis,
      ...ruleSets.PendingIssuesRequiresLegalEntity,
    ],
    // amke_user: [
    //   ...ruleSets.baseAssignment,
    //   ...ruleSets.baseFullness,
    //   ...ruleSets.baseForSignature,
    //   ...ruleSets.TabsVis,
    //   ...ruleSets.PublishedVis,
    //   ...ruleSets.rulesetShowWhenFilled,
    // ],
    // manager: [
    //   ...ruleSets.baseAssignment,
    //   ...ruleSets.baseFullness,
    //   ...ruleSets.baseForSignature,
    //   ...ruleSets.TabsVis,
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
      if (scoped) {
        return scoped;
      }
      // File fields may use wrapper selectors (e.g. details), so search within field first.
      if (field?.querySelector) {
        const nested = field.querySelector(indicator);
        if (nested) {
          return nested;
        }
      }
      // Avoid highlighting a random global element for generic selectors like "div"/"span".
      const isGlobalSafe = /^([#.[])/.test(indicator.trim());
      return isGlobalSafe ? document.querySelector(indicator) : null;
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
    const hasNonEmptyFids = (root) => {
      if (!root || typeof root.querySelectorAll !== 'function') {
        return false;
      }
      const hiddenFids = root.querySelectorAll('input[type="hidden"][name*="[fids]"]');
      return Array.from(hiddenFids).some((input) => String(input.value || '').trim() !== '');
    };

    if (!el && config.type === 'file') {
      if (config.selector) {
        const selectorId = config.selector
          .replace(/^#/, '')
          .replace('[data-drupal-selector="', '')
          .replace('"]', '')
          .replace('-upload', '');
        const byDataSelector = document.querySelector(
          `input[type="hidden"][name*="[fids]"][data-drupal-selector^="${selectorId}"]`
        );
        if (byDataSelector) {
          return String(byDataSelector.value || '').trim() === '';
        }
      }
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
      if (hasNonEmptyFids(el) || hasNonEmptyFids(el.closest('.js-form-managed-file, .js-form-item, details'))) {
        return false;
      }
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
    const targets = getTabButtonsToOpen(config);
    return targets.length ? targets[0] : null;
  };

  const getTabButtonsToOpen = (config) => {
    if (!config) {
      return [];
    }
    const buttons = [];
    const seen = new Set();
    const addButton = (el) => {
      if (!el || seen.has(el)) {
        return;
      }
      seen.add(el);
      buttons.push(el);
    };

    if (Array.isArray(config.tab_button_links)) {
      config.tab_button_links.forEach((href) => {
        if (typeof href !== 'string' || href.trim() === '') {
          return;
        }
        const link = document.querySelector(`.horizontal-tab-button a[href="${href.trim()}"]`);
        if (link) {
          addButton(link.closest('.horizontal-tab-button'));
        }
      });
    }

    if (Array.isArray(config.tab_button_classes)) {
      config.tab_button_classes.forEach((cls) => {
        if (typeof cls === 'string' && cls.trim() !== '') {
          addButton(document.querySelector(`.${cls.trim()}`));
        }
      });
    }
    if (config.tab_button_parent_class) {
      addButton(document.querySelector(`.${config.tab_button_parent_class}`));
    }
    if (config.tab_button_class) {
      addButton(document.querySelector(`.${config.tab_button_class}`));
    }
    return buttons;
  };

  const getFieldValue = (field, cfg = {}) => {
    const type = cfg.type;
    if (!field) {
      return null;
    }
    if (type === 'attribute') {
      const attribute = cfg.attribute || 'doc-status';
      return field.getAttribute(attribute);
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
    if (cfg.type === 'exists') {
      return !field;
    }
    if (!field) {
      return false;
    }
    const value = getFieldValue(field, cfg);
    if (Array.isArray(cfg.value)) {
      const allowed = cfg.value.map((v) => String(v));
      return !allowed.includes(String(value));
    }
    return String(value) !== String(cfg.value);
  };

  const shouldHideByValueIs = (cfg) => {
    const field = document.querySelector(cfg.selector);
    if (cfg.type === 'exists') {
      return !!field;
    }
    if (!field) {
      return false;
    }
    const value = getFieldValue(field, cfg);
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

  const isConfigActive = (config) => {
    if (!config || !config.requires) {
      return true;
    }
    return shouldHideByRequirements(config.requires);
  };

  const getRequirementWatchers = (requirement) => {
    if (!requirement) {
      return [];
    }
    return [
      ...(requirement.valueNotOR || []),
      ...(requirement.valueNotAND || []),
      ...(requirement.valueNot || []),
      ...(requirement.valueIsOR || []),
      ...(requirement.valueIsAND || []),
      ...(requirement.valueIs || []),
    ];
  };

  const ensureTabOpen = (config) => {
    const tabButtons = getTabButtonsToOpen(config);
    if (!tabButtons.length) {
      return;
    }
    tabButtons.forEach((tabButton) => {
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
    });
  };

  const tabFieldsFilled = (tabButton, rule) => {
    if (!tabButton) {
      return true;
    }
    const related = (rule.emptyFields || [])
      .filter((cfg) => isConfigActive(cfg))
      .filter((cfg) => getTabButtonsToOpen(cfg).includes(tabButton));
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
    if (rule.requires && !shouldHideByRequirements(rule.requires)) {
      button.dataset.ifvDisabled = 'false';
      button.setAttribute('aria-disabled', 'false');
      (rule.toggleclasses || []).forEach((cls) => button.classList.remove(cls));
      return;
    }
    const tabStatus = new Map();
    let hasEmpty = false;

    (rule.emptyFields || []).forEach((config) => {
      if (!isConfigActive(config)) {
        return;
      }
      const field = document.querySelector(config.selector);
      const indicator = resolveIndicator(field, config.indicator);
      const empty = isEmpty(field, config);

      if (empty) {
        hasEmpty = true;
      }
      else {
        clearHighlight(indicator);
      }

      getTabButtonsToOpen(config).forEach((tabButton) => {
        if (!tabStatus.has(tabButton)) {
          tabStatus.set(tabButton, true);
        }
        if (empty) {
          tabStatus.set(tabButton, false);
        }
      });
    });

    tabStatus.forEach((filled, tabButton) => {
      if (filled) {
        clearHighlight(tabButton);
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

    const attachFieldWatcher = (config) => {
      const field = document.querySelector(config.selector);
      if (field) {
        const events = ['input', 'change', 'blur', 'keyup'];
        const handler = () => {
          if (!isEmpty(field, config)) {
            clearHighlight(resolveIndicator(field, config.indicator));
            getTabButtonsToOpen(config).forEach((tabButton) => {
              if (tabFieldsFilled(tabButton, rule)) {
                clearHighlight(tabButton);
              }
            });
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
    };

    const watchers = [];
    (rule.emptyFields || []).forEach((config) => {
      watchers.push(config);
      watchers.push(...getRequirementWatchers(config.requires));
    });
    const seen = new Set();
    watchers.forEach((config) => {
      if (!config || !config.selector) {
        return;
      }
      const key = `${config.selector}::${config.type || ''}`;
      if (seen.has(key)) {
        return;
      }
      seen.add(key);
      attachFieldWatcher(config);
    });

    button.addEventListener('click', (event) => {
      if (button.dataset.ifvDisabled !== 'true') {
        return;
      }
      event.preventDefault();

      // Clear any previous indicators before adding new ones.
      document.querySelectorAll('.ifv-missing').forEach((el) => clearHighlight(el));

      (rule.emptyFields || []).forEach((config) => {
        if (!isConfigActive(config)) {
          return;
        }
        const field = document.querySelector(config.selector);
        const indicator = resolveIndicator(field, config.indicator);
        const tabButtons = getTabButtonsToOpen(config);
        if (isEmpty(field, config)) {
          addHighlight(indicator || field);
          tabButtons.forEach((tabButton) => addHighlight(tabButton));
          ensureTabOpen(config);
          scrollToField(indicator || field || button);
        }
      });
    });

    refresh();
  };

  const applyDisabled = (selector, rule) => {
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
    const shouldDisable = shouldHideByRequirements(rule);
    const isLink = el.tagName === 'A';
    if (shouldDisable) {
      if (isLink) {
        if (!el.dataset.ifvOriginalTabindex) {
          el.dataset.ifvOriginalTabindex = el.getAttribute('tabindex') ?? '';
        }
        el.setAttribute('tabindex', '-1');
        el.style.pointerEvents = 'none';
      }
      else {
        el.setAttribute('disabled', 'disabled');
      }
    }
    else if (isLink) {
      const originalTabindex = el.dataset.ifvOriginalTabindex ?? '';
      if (originalTabindex === '') {
        el.removeAttribute('tabindex');
      }
      else {
        el.setAttribute('tabindex', originalTabindex);
      }
      delete el.dataset.ifvOriginalTabindex;
      el.style.pointerEvents = '';
    }
    else {
      el.removeAttribute('disabled');
    }
    el.setAttribute('aria-disabled', shouldDisable ? 'true' : 'false');
    (rule.toggleclasses || []).forEach((cls) => el.classList.toggle(cls, shouldDisable));
  };

  const attachDisableHandler = (selector, rule) => {
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
      const handler = () => applyDisabled(selector, rule);
      events.forEach((evt) => field.addEventListener(evt, handler));

      if (cfg.type === 'select2' && window.jQuery) {
        const $field = window.jQuery(field);
        if (typeof $field.on === 'function') {
          $field.on('select2:select select2:unselect select2:clear select2:close select2:opening select2:closing select2:open', handler);
        }
      }
    });

    applyDisabled(selector, rule);
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

  const normalizeSelectors = (selector) => {
    if (Array.isArray(selector)) {
      return selector.filter(Boolean);
    }
    if (typeof selector === 'string' && selector.trim() !== '') {
      return [selector];
    }
    return [];
  };

  const expandRequirements = (requirements) => {
    const pairs = [];
    requirements.forEach((req) => {
      const selectors = normalizeSelectors(req.selector);
      if (!selectors.length) {
        return;
      }
      const requires = (req.rules || []).filter((rule) => rule.type === 'require');
      (req.rules || []).forEach((rule) => {
        if (rule.type === 'require') {
          return;
        }
        const withRequires = requires.length
          ? {
            ...rule,
            requires: {
              valueNotOR: requires.flatMap((r) => r.valueNotOR || r.valueNot || []),
              valueNotAND: requires.flatMap((r) => r.valueNotAND || []),
              valueIsOR: requires.flatMap((r) => r.valueIsOR || r.valueIs || []),
              valueIsAND: requires.flatMap((r) => r.valueIsAND || []),
            },
          }
          : rule;
        selectors.forEach((selector) => pairs.push([selector, withRequires]));
      });
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
        if (rule.type === 'disableIf') {
          attachDisableHandler(selector, rule);
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
          else if (rule.type === 'disableIf') {
            applyDisabled(selector, rule);
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
          else if (rule.type === 'disableIf') {
            applyDisabled(selector, rule);
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
