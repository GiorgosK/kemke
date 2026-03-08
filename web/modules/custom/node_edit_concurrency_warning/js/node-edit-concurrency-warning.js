(function (Drupal, once, drupalSettings) {
  const DEBUG = false;

  function getRelevantElements(form) {
    return Array.from(form.elements || []).filter((element) => {
      if (!element.name || element.disabled) {
        return false;
      }

      if (element.type === 'submit' || element.type === 'button' || element.type === 'image' || element.type === 'file') {
        return false;
      }

      return ![
        'form_build_id',
        'form_token',
        'form_id',
        'op',
        '_triggering_element_name',
        '_triggering_element_value',
        'incoming_edit_tweaks_active_tab',
      ].includes(element.name);
    });
  }

  function serializeElement(element) {
    if (element.type === 'checkbox' || element.type === 'radio') {
      return element.checked ? '1' : '0';
    }

    if (element.tagName === 'SELECT' && element.multiple) {
      return Array.from(element.options)
        .filter((option) => option.selected)
        .map((option) => option.value)
        .join('|');
    }

    return element.value || '';
  }

  function serializeForm(form) {
    return getRelevantElements(form).map((element) => {
      return `${element.name}:${serializeElement(element)}`;
    }).join('\n');
  }

  function captureNamedValues(form) {
    const values = new Map();

    getRelevantElements(form).forEach((element) => {
      const currentValue = serializeElement(element);
      if (values.has(element.name)) {
        values.set(element.name, `${values.get(element.name)}||${currentValue}`);
        return;
      }

      values.set(element.name, currentValue);
    });

    return values;
  }

  function addBanner(form, message) {
    if (form.querySelector('.node-edit-concurrency-warning')) {
      return;
    }

    const banner = document.createElement('div');
    banner.className = 'messages messages--error node-edit-concurrency-warning';
    banner.textContent = message;
    form.prepend(banner);
  }

  function findFieldContainer(element, form) {
    return element.closest('.js-form-item')
      || element.closest('.field-multiple-item')
      || element.closest('.field--type-entity-reference-revisions')
      || element.closest('.ief-form')
      || element.closest('.ief-entity-table')
      || element.closest('.field-multiple-table')
      || element.closest('.form-managed-file')
      || element.closest('.js-form-managed-file')
      || element.closest('[data-drupal-selector*="field-"]')
      || element.closest('[data-drupal-selector]')
      || form;
  }

  function extractFieldToken(element) {
    if (!element) {
      return '';
    }

    const candidates = [
      element.name || '',
      element.id || '',
      typeof element.getAttribute === 'function' ? (element.getAttribute('data-drupal-selector') || '') : '',
      element.className || '',
    ];

    for (const candidate of candidates) {
      const match = candidate.match(/(field_[a-z0-9_]+)/i);
      if (match) {
        return match[1].toLowerCase();
      }
    }

    return '';
  }

  function findStructuralContainerByFieldToken(fieldToken, form) {
    if (!fieldToken) {
      return null;
    }

    const dashToken = fieldToken.replace(/_/g, '-');
    const directMatch = form.querySelector(
      `#edit-${dashToken}-wrapper, [data-drupal-selector="edit-${dashToken}-wrapper"], [data-drupal-selector="edit-${dashToken}"], #edit-${dashToken}, .field--name-${dashToken}`,
    );
    if (directMatch) {
      return directMatch;
    }

    const relatedNode = form.querySelector(
      `[data-drupal-selector^="edit-${dashToken}"], [data-drupal-selector*="-${dashToken}-"], [name^="${fieldToken}["], [name^="${fieldToken}_"]`,
    );
    if (!relatedNode) {
      return null;
    }

    return relatedNode.closest(`[data-drupal-selector="edit-${dashToken}-wrapper"]`)
      || relatedNode.closest(`[data-drupal-selector="edit-${dashToken}"]`)
      || relatedNode.closest(`#edit-${dashToken}-wrapper`)
      || relatedNode.closest(`#edit-${dashToken}`)
      || relatedNode.closest('details')
      || relatedNode.closest('.js-form-item')
      || relatedNode.closest('.field-multiple-item')
      || relatedNode.closest('.field--type-entity-reference-revisions')
      || relatedNode.closest('.ief-form')
      || relatedNode.closest('.ief-entity-table')
      || relatedNode.closest('.field-multiple-table')
      || relatedNode.closest('.details-wrapper')
      || relatedNode.closest('[id^="edit-group-"]')
      || relatedNode;
  }

  function isStructuralChangeTrigger(element) {
    if (!element) {
      return false;
    }

    const name = element.name || '';
    const selector = element.getAttribute('data-drupal-selector') || '';
    const classes = element.className || '';
    const text = element.value || element.textContent || '';
    const haystack = `${name} ${selector} ${classes} ${text}`.toLowerCase();

    return haystack.includes('add_more')
      || haystack.includes('add-more')
      || haystack.includes('remove')
      || haystack.includes('upload')
      || haystack.includes('browse')
      || haystack.includes('ief-')
      || haystack.includes('inline entity')
      || haystack.includes('paragraph');
  }

  function findTabPane(container, form) {
    if (!container || container === form) {
      return null;
    }

    return container.closest('details[id^="edit-group-"]')
      || container.closest('.horizontal-tabs-pane[id]')
      || container.closest('.vertical-tabs__pane[id]')
      || container.closest('[id^="edit-group-"]');
  }

  function findAncestorTabPanes(container, form) {
    const panes = [];
    let current = findTabPane(container, form);

    while (current && current !== form) {
      panes.push(current);
      current = findTabPane(current.parentElement, form);
    }

    return panes;
  }

  function findTabLinkForPane(paneId, form) {
    if (!paneId) {
      return null;
    }

    return form.querySelector(`.horizontal-tab-button a[href="#${paneId}"]`)
      || form.querySelector(`.vertical-tabs__menu-item a[href="#${paneId}"]`);
  }

  function ensureFieldIndicatorForContainer(container, form) {
    if (!container) {
      return {};
    }

    container.classList.add('node-edit-concurrency-warning-field');

    const panes = findAncestorTabPanes(container, form);
    panes.forEach((pane) => {
      const tabLink = findTabLinkForPane(pane.id, form);
      if (!tabLink) {
        return;
      }

      tabLink.classList.add('node-edit-concurrency-warning-tab-link');
      tabLink.classList.add('node-edit-concurrency-warning-tab-dirty');
    });

    return {
      container,
      paneIds: panes.map((pane) => pane.id),
    };
  }

  function ensureFieldIndicator(element, form) {
    return ensureFieldIndicatorForContainer(findFieldContainer(element, form), form);
  }

  function removeFieldIndicator(element, form) {
    const container = findFieldContainer(element, form);
    if (!container) {
      return;
    }

    container.classList.remove('node-edit-concurrency-warning-field');
  }

  function showModal(message) {
    if (document.querySelector('.node-edit-concurrency-warning-modal')) {
      return;
    }

    const overlay = document.createElement('div');
    overlay.className = 'node-edit-concurrency-warning-modal';
    Object.assign(overlay.style, {
      position: 'fixed',
      inset: '0',
      zIndex: '1000',
      display: 'flex',
      alignItems: 'center',
      justifyContent: 'center',
      padding: '1.5rem',
      background: 'rgba(0, 0, 0, 0.45)',
    });

    const dialog = document.createElement('div');
    dialog.className = 'node-edit-concurrency-warning-modal__dialog';
    dialog.setAttribute('role', 'alertdialog');
    dialog.setAttribute('aria-modal', 'true');
    dialog.setAttribute('aria-labelledby', 'node-edit-concurrency-warning-title');
    Object.assign(dialog.style, {
      width: 'min(32rem, 100%)',
      padding: '1.25rem 1.5rem',
      background: '#fff',
      border: '3px solid #d72222',
      boxShadow: '0 1rem 2rem rgba(0, 0, 0, 0.2)',
    });

    const title = document.createElement('h2');
    title.id = 'node-edit-concurrency-warning-title';
    title.className = 'node-edit-concurrency-warning-modal__title';
    title.textContent = Drupal.t('Page Outdated');
    Object.assign(title.style, {
      margin: '0 0 0.75rem',
      color: '#9f1717',
      fontSize: '1.25rem',
      fontWeight: '700',
    });

    const body = document.createElement('p');
    body.textContent = message;

    const actions = document.createElement('div');
    actions.className = 'node-edit-concurrency-warning-modal__actions';
    Object.assign(actions.style, {
      display: 'flex',
      justifyContent: 'flex-end',
      marginTop: '1rem',
    });

    const closeButton = document.createElement('button');
    closeButton.type = 'button';
    closeButton.className = 'govgr-btn-secondary govgr-btn-small';
    closeButton.textContent = Drupal.t('Close');
    closeButton.addEventListener('click', () => {
      overlay.remove();
    });

    actions.appendChild(closeButton);
    dialog.appendChild(title);
    dialog.appendChild(body);
    dialog.appendChild(actions);
    overlay.appendChild(dialog);
    document.body.appendChild(overlay);
    closeButton.focus();
  }

  function logState(label, details) {
    if (!DEBUG || !(window.console && typeof window.console.log === 'function')) {
      return;
    }

    window.console.log('[node_edit_concurrency_warning]', label, details || {});
  }

  Drupal.behaviors.nodeEditConcurrencyWarning = {
    attach(context) {
      const settings = drupalSettings.nodeEditConcurrencyWarning || {};
      if (!settings.checkUrl || !settings.loadedChangedTime) {
        return;
      }

      once('node-edit-concurrency-warning', 'form.node-incoming-edit-form, form[data-drupal-selector="node-incoming-edit-form"]', context).forEach((form) => {
        const initialSnapshot = serializeForm(form);
        const initialValues = captureNamedValues(form);
        const dirtyFields = new Set();
        let dirty = false;
        let warned = false;
        let intervalId = null;

        const buildStateDetails = (extra) => ({
          dirty,
          warned,
          polling: Boolean(intervalId),
          hidden: document.hidden,
          ...extra,
        });

        const bindCkEditors = () => {
          if (!(window.Drupal && Drupal.CKEditor5Instances)) {
            return;
          }

          Drupal.CKEditor5Instances.forEach((editor, key) => {
            const source = editor.sourceElement || document.getElementById(key);
            if (!source || !form.contains(source)) {
              return;
            }

            if (source.dataset.nodeEditConcurrencyWarningCkBound) {
              return;
            }

            source.dataset.nodeEditConcurrencyWarningCkBound = 'true';
            logState('ckeditor-bound', buildStateDetails({
              field: source.name || source.id || '(unknown)',
            }));
            editor.model.document.on('change:data', () => {
              source.value = editor.getData();
              onPotentialChange({ target: source });
            });
          });
        };

        const stopPolling = () => {
          if (!intervalId) {
            return;
          }

          window.clearInterval(intervalId);
          intervalId = null;
          logState('polling-stopped', buildStateDetails());
        };

        const checkStale = async () => {
          if (document.hidden) {
            logState('stale-check-skipped-hidden', buildStateDetails());
            return;
          }

          logState('stale-check-start', buildStateDetails());

          try {
            const url = new URL(settings.checkUrl, window.location.origin);
            url.searchParams.set('loaded_changed_time', String(settings.loadedChangedTime));
            url.searchParams.set('_', String(Date.now()));

            const response = await fetch(url.toString(), {
              credentials: 'same-origin',
              headers: {
                'Accept': 'application/json',
              },
            });

            if (!response.ok) {
              return;
            }

            const payload = await response.json();
            if (!payload || !payload.stale) {
              logState('stale-check-clear', buildStateDetails({
                latestChangedTime: payload ? payload.latest_changed_time : null,
              }));
              return;
            }

            if (!warned) {
              warned = true;
              addBanner(form, settings.warningMessage || 'This form is stale. Reload the page before continuing.');
              showModal(settings.warningMessage || 'This form is stale. Reload the page before continuing.');
              stopPolling();
              logState('stale-check-hit', buildStateDetails({
                latestChangedTime: payload.latest_changed_time || null,
              }));
              return;
            }

            logState('stale-check-hit-already-warned', buildStateDetails({
              latestChangedTime: payload.latest_changed_time || null,
            }));
          }
          catch (error) {
            // Keep this warning-only behavior silent on network or parsing issues.
            logState('stale-check-error', buildStateDetails({
              message: error && error.message ? error.message : String(error),
            }));
          }
        };

        const ensurePolling = () => {
          if (intervalId || document.hidden || !dirty) {
            logState('polling-not-started', buildStateDetails());
            return;
          }

          const pollInterval = Number(settings.pollInterval) || 30000;
          intervalId = window.setInterval(checkStale, pollInterval);
          logState('polling-started', buildStateDetails({
            interval: pollInterval,
          }));
        };

        const syncDirtyIndicators = () => {
          const currentValues = captureNamedValues(form);
          const allNames = new Set([
            ...Array.from(initialValues.keys()),
            ...Array.from(currentValues.keys()),
          ]);

          form.querySelectorAll('.node-edit-concurrency-warning-field').forEach((container) => {
            container.classList.remove('node-edit-concurrency-warning-field');
          });
          form.querySelectorAll('.node-edit-concurrency-warning-tab-dirty').forEach((tabLink) => {
            tabLink.classList.remove('node-edit-concurrency-warning-tab-dirty');
          });

          dirtyFields.clear();
          allNames.forEach((name) => {
            const initialValue = initialValues.has(name) ? initialValues.get(name) : '';
            const currentValue = currentValues.has(name) ? currentValues.get(name) : '';
            if (currentValue === initialValue) {
              return;
            }

            const fieldToken = extractFieldToken({ name });
            const structuralContainer = findStructuralContainerByFieldToken(fieldToken, form);
            const elements = form.querySelectorAll(`[name="${CSS.escape(name)}"]`);
            let indicatorState = {};
            if (structuralContainer) {
              indicatorState = ensureFieldIndicatorForContainer(structuralContainer, form);
            }
            else if (elements.length) {
              indicatorState = ensureFieldIndicator(elements[0], form);
            }
            else {
              indicatorState = ensureFieldIndicatorForContainer(null, form);
            }

            dirtyFields.add(name);
          });

          return dirtyFields.size > 0;
        };

        const processStructuralChange = (changeContext) => {
          if (!changeContext) {
            return;
          }

          dirty = syncDirtyIndicators();
          logState('structural-change-processed', buildStateDetails({
            trigger: changeContext.triggerName || '',
            fieldToken: changeContext.fieldToken || '',
          }));
          if (!dirty) {
            stopPolling();
            return;
          }
          checkStale();
          ensurePolling();
        };

        const onPotentialChange = (event) => {
          dirty = syncDirtyIndicators();
          logState('field-change-processed', buildStateDetails({
            field: event && event.target ? (event.target.name || event.target.id || '(unknown)') : '(unknown)',
          }));
          if (!dirty) {
            stopPolling();
            return;
          }

          checkStale();
          ensurePolling();
        };

        const handleVisibilityChange = () => {
          if (document.hidden) {
            logState('tab-hidden', buildStateDetails());
            stopPolling();
            return;
          }

          logState('tab-visible', buildStateDetails());
          if (dirty) {
            checkStale();
          }
          ensurePolling();
        };

        form.addEventListener('input', onPotentialChange, { passive: true });
        form.addEventListener('change', onPotentialChange, { passive: true });
        form.addEventListener('click', (event) => {
          const trigger = event.target.closest('button, input[type="submit"], input[type="button"]');
          if (!isStructuralChangeTrigger(trigger)) {
            return;
          }

          const changeContext = {
            fieldToken: extractFieldToken(trigger),
            paneId: (() => {
              const pane = trigger.closest('[id^="edit-group-"]');
              return pane ? pane.id : '';
            })(),
            triggerName: trigger.name || trigger.id || '',
            container: findFieldContainer(trigger, form),
          };

          window.setTimeout(() => {
            processStructuralChange(changeContext);
          }, 50);

          if (window.jQuery) {
            window.jQuery(document).one('ajaxComplete.nodeEditConcurrencyWarning', () => {
              processStructuralChange(changeContext);
            });
          }
        }, true);
        if (window.jQuery && window.jQuery.fn && window.jQuery.fn.select2) {
          window.jQuery(form).on('select2:select select2:unselect select2:clear', 'select.select2-hidden-accessible', (event) => {
            onPotentialChange({ target: event.target });
          });
          if (!form.dataset.nodeEditConcurrencyWarningAjaxBound) {
            form.dataset.nodeEditConcurrencyWarningAjaxBound = 'true';
            window.jQuery(document).on('ajaxComplete.nodeEditConcurrencyWarningGlobal', () => {
              if (!document.body.contains(form) || warned) {
                return;
              }
              processStructuralChange({});
            });
          }
        }
        bindCkEditors();
        window.setTimeout(bindCkEditors, 1000);
        document.addEventListener('visibilitychange', handleVisibilityChange);
        window.addEventListener('blur', () => {
          logState('window-blur', buildStateDetails());
          stopPolling();
        });
        window.addEventListener('focus', () => {
          logState('window-focus', buildStateDetails());
          if (dirty) {
            checkStale();
          }
          ensurePolling();
        });
        logState('behavior-attached', buildStateDetails());
      });
    },
  };
})(Drupal, once, drupalSettings);
