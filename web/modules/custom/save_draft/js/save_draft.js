(function (Drupal, once, drupalSettings) {
  'use strict';

  window.saveDraftDebug = window.saveDraftDebug || {};
  window.saveDraftDebug.click = function (selector) {
    const element = document.querySelector(selector);
    if (!element) {
      console.warn('[save_draft] debug click: not found', selector);
      return false;
    }
    element.click();
    return true;
  };
  window.saveDraftDebug.ajaxClick = function (selector) {
    const element = document.querySelector(selector);
    if (!element) {
      console.warn('[save_draft] debug ajaxClick: not found', selector);
      return false;
    }
    if (!window.Drupal || !Drupal.ajax || !Drupal.ajax.instances) {
      console.warn('[save_draft] debug ajaxClick: Drupal.ajax missing');
      return false;
    }
    const ajax = Object.values(Drupal.ajax.instances || {}).find((instance) => instance && instance.element === element);
    if (!ajax) {
      console.warn('[save_draft] debug ajaxClick: instance not ready', selector);
      return false;
    }
    ajax.eventResponse(element, new Event('mousedown'));
    return true;
  };

  function normalizeValue(value) {
    if (value === null || value === undefined) {
      return '';
    }
    return String(value);
  }

  function applyValueToElement(element, value) {
    const tag = element.tagName.toLowerCase();
    const type = (element.getAttribute('type') || '').toLowerCase();

    if (type === 'file') {
      return;
    }

    if (window.Drupal && Drupal.CKEditor5Instances) {
      let editor = null;
      if (element.id && Drupal.CKEditor5Instances.get(element.id)) {
        editor = Drupal.CKEditor5Instances.get(element.id);
      } else {
        Drupal.CKEditor5Instances.forEach((instance) => {
          if (instance.sourceElement === element) {
            editor = instance;
          }
        });
      }
      if (editor) {
        editor.setData(normalizeValue(value));
        element.value = normalizeValue(value);
        return;
      }
    }

    if (type === 'checkbox' || type === 'radio') {
      if (Array.isArray(value)) {
        element.checked = value.map(normalizeValue).includes(normalizeValue(element.value));
      } else {
        element.checked = normalizeValue(value) === normalizeValue(element.value) || normalizeValue(value) === '1' || normalizeValue(value) === 'true';
      }
      return;
    }

    if (tag === 'select' && element.multiple) {
      const values = Array.isArray(value) ? value.map(normalizeValue) : [normalizeValue(value)];
      if (window.jQuery && window.jQuery.fn && window.jQuery.fn.select2 && window.jQuery(element).data('select2')) {
        window.jQuery(element).val(values).trigger('change');
      } else {
        Array.from(element.options).forEach((option) => {
          option.selected = values.includes(normalizeValue(option.value));
        });
      }
      return;
    }

    if (tag === 'select' && !element.multiple) {
      const nextValue = Array.isArray(value) ? value[0] : value;
      if (window.jQuery && window.jQuery.fn && window.jQuery.fn.select2 && window.jQuery(element).data('select2')) {
        window.jQuery(element).val(normalizeValue(nextValue)).trigger('change');
      } else {
        element.value = normalizeValue(nextValue);
      }
      return;
    }

    element.value = normalizeValue(value);
  }

  function setStatus(container, message) {
    if (!container) {
      return;
    }
    container.textContent = message || '';
  }

  function showDraftButtons(form, show) {
    const loadButton = form.querySelector('.load-draft-button');
    const clearButton = form.querySelector('.clear-draft-button');
    [loadButton, clearButton].forEach((button) => {
      if (!button) {
        return;
      }
      button.hidden = !show;
      button.disabled = !show;
      button.classList.toggle('govgr-btn--disabled', !show);
    });
  }

  function isEmptyValue(value) {
    return value === undefined || value === null || value === '';
  }

  function applyValueWithAppend(name, value, elements) {
    if (!elements.length) {
      return;
    }

    const isMulti = name.endsWith('[]') || name.endsWith('[fids]');
    const values = Array.isArray(value) ? value : [value];

    if (elements.length > 1) {
      let index = 0;
      elements.forEach((element) => {
        if (index >= values.length) {
          return;
        }
        const current = element.value;
        if (!isEmptyValue(current)) {
          return;
        }
        applyValueToElement(element, values[index]);
        index += 1;
      });
      return;
    }

    const element = elements[0];
    const current = element.value;
    if (isMulti && !isEmptyValue(current) && !isEmptyValue(values[0])) {
      const existing = current.split(/\s+|,/).filter(Boolean);
      const incoming = String(values[0]).split(/\s+|,/).filter(Boolean);
      const merged = Array.from(new Set(existing.concat(incoming)));
      element.value = merged.join(' ');
      if (element.tagName.toLowerCase() === 'select' && window.jQuery && window.jQuery.fn && window.jQuery.fn.select2 && window.jQuery(element).data('select2')) {
        window.jQuery(element).val(merged).trigger('change');
      }
      return;
    }

    if (isEmptyValue(current) || !isMulti) {
      applyValueToElement(element, values[0]);
    }
  }

  function findAddMoreButton(form, name) {
    const base = name.split('[')[0];
    if (!base) {
      return null;
    }
    const selectors = [
      `[data-drupal-selector="edit-${base.replace(/_/g, '-')}-add-more"] input.field-add-more-submit`,
      `[data-drupal-selector="edit-${base.replace(/_/g, '-')}-add-more"] .field-add-more-submit`,
      `input[type="submit"][name*="${base}"][name*="add_more"]`,
      `button[name*="${base}"][name*="add_more"]`,
      `[data-drupal-selector*="${base.replace(/_/g, '-')}--add-more"]`,
      `[data-drupal-selector*="${base.replace(/_/g, '-')}--add-more-button"]`,
      `[data-drupal-selector*="${base.replace(/_/g, '-')}"] [data-drupal-selector*="add-more"]`,
    ];
    for (const selector of selectors) {
      const button = form.querySelector(selector);
      if (button) {
        return button;
      }
    }
    return null;
  }

  function applyLoadedData(form, data, attempt = 0) {
    const missing = [];

    Object.keys(data).forEach((name) => {
      const elements = form.querySelectorAll(`[name="${CSS.escape(name)}"]`);
      if (!elements.length) {
        missing.push(name);
        return;
      }
      applyValueWithAppend(name, data[name], elements);
    });

    Object.keys(data).forEach((name) => {
      const elements = form.querySelectorAll(`[name="${CSS.escape(name)}"]`);
      elements.forEach((element) => {
        if (element.tagName.toLowerCase() !== 'select') {
          return;
        }
        if (window.jQuery && window.jQuery.fn && window.jQuery.fn.select2 && window.jQuery(element).data('select2')) {
          const value = data[name];
          const values = Array.isArray(value) ? value.map(normalizeValue) : [normalizeValue(value)];
          window.jQuery(element).val(element.multiple ? values : values[0]).trigger('change');
        }
      });
    });

    if (!missing.length || attempt >= 3) {
      return;
    }

    let triggered = false;
    missing.forEach((name) => {
      const button = findAddMoreButton(form, name);
      if (button) {
        if (window.Drupal && Drupal.ajax && Drupal.ajax.instances) {
          const ajax = Object.values(Drupal.ajax.instances || {}).find((instance) => instance && instance.element === button);
          if (ajax) {
            ajax.eventResponse(button, new Event('mousedown'));
          } else {
            button.click();
          }
        } else {
          button.click();
        }
        triggered = true;
      }
    });

    if (triggered) {
      if (window.jQuery && !form.dataset.saveDraftAjaxPending) {
        form.dataset.saveDraftAjaxPending = 'true';
        window.jQuery(document).one('ajaxComplete.saveDraft', () => {
          delete form.dataset.saveDraftAjaxPending;
          setTimeout(() => applyLoadedData(form, data, attempt + 1), 100);
        });
      } else {
        setTimeout(() => applyLoadedData(form, data, attempt + 1), 800);
      }
    }
  }

  Drupal.behaviors.saveDraft = {
    attach(context) {
      const settings = drupalSettings.saveDraft || {};
      const saveButtons = once('save-draft-button', '.save-draft-button', context);
      const loadButtons = once('load-draft-button', '.load-draft-button', context);
      const clearButtons = once('clear-draft-button', '.clear-draft-button', context);

      if (!saveButtons.length) {
        return;
      }

      saveButtons.forEach((button) => {
        const form = button.closest('form');
        if (!form) {
          return;
        }

        const saveUrl = button.getAttribute('data-save-draft-save-url') || settings.saveUrl;
        const status = form.querySelector('.save-draft-status');
        let dirty = false;

        button.disabled = true;
        button.classList.add('govgr-btn--disabled');

        const resolveTarget = (target) => {
          if (!target) {
            return { element: null, name: '(unnamed)', value: undefined };
          }
          if (target.name) {
            const value = target.type === 'checkbox' ? target.checked : target.value;
            return { element: target, name: target.name, value };
          }
          if (target.isContentEditable) {
            const wrapper = target.closest('.form-textarea-wrapper') || target.closest('.ck-editor');
            if (wrapper) {
              const textarea = wrapper.querySelector('textarea[data-ckeditor5-id]');
              if (textarea) {
                const editor = window.Drupal && Drupal.CKEditor5Instances ? Drupal.CKEditor5Instances.get(textarea.id) : null;
                const data = editor ? editor.getData() : (target.innerHTML || textarea.value);
                textarea.value = data;
                return { element: textarea, name: textarea.name || '(unnamed)', value: data };
              }
            }
          }
          return { element: target, name: '(unnamed)', value: undefined };
        };

        const markDirty = (event) => {
          if (!dirty) {
            dirty = true;
            button.disabled = false;
            button.classList.remove('govgr-btn--disabled');
          }
          if (event && event.target) {
            const resolved = resolveTarget(event.target);
            const name = resolved.name;
            const value = resolved.value;
            const preview = value === undefined ? '(undefined)' : value === '' ? '(empty)' : value;
            console.log('[save_draft] change', name, preview);
          }
        };

        form.addEventListener('input', markDirty, { passive: true });
        form.addEventListener('change', markDirty, { passive: true });

        setStatus(status, settings.draftLabel ? Drupal.t('Draft available (@date).', { '@date': settings.draftLabel }) : Drupal.t('No draft available.'));
        showDraftButtons(form, !!settings.draftLabel);

        if (window.jQuery && window.jQuery.fn && window.jQuery.fn.select2) {
          if (!form.dataset.saveDraftSelect2Delegated) {
            form.dataset.saveDraftSelect2Delegated = 'true';
            window.jQuery(form).on('select2:select select2:unselect select2:clear', 'select.select2-hidden-accessible', (event) => {
              markDirty({ target: event.target });
            });
          }
        }

        if (window.Drupal && Drupal.CKEditor5Instances) {
          Drupal.CKEditor5Instances.forEach((editor, key) => {
            const source = editor.sourceElement || document.getElementById(key);
            if (!source || !form.contains(source)) {
              return;
            }
            if (source.dataset.saveDraftCkBound) {
              return;
            }
            source.dataset.saveDraftCkBound = 'true';
            editor.model.document.on('change:data', () => {
              const data = editor.getData();
              source.value = data;
              markDirty({ target: source });
              console.log('[save_draft] ckeditor data', source.name || '(unnamed)', data);
            });
          });
        }

        button.addEventListener('click', async (event) => {
          event.preventDefault();
          if (!dirty) {
            setStatus(status, Drupal.t('No changes to save.'));
            return;
          }

          const formData = new FormData(form);
          const ignore = new Set([
            'form_build_id',
            'form_token',
            'form_id',
            'op',
            '_triggering_element_name',
            '_triggering_element_value',
          ]);

          const data = {};
          formData.forEach((value, name) => {
            if (ignore.has(name)) {
              return;
            }
            if (name.indexOf('save_draft') !== -1 || name.indexOf('load_draft') !== -1) {
              return;
            }
            if (data[name] === undefined) {
              data[name] = value;
            } else if (Array.isArray(data[name])) {
              data[name].push(value);
            } else {
              data[name] = [data[name], value];
            }
          });

          button.disabled = true;
          button.classList.add('govgr-btn--disabled');
          setStatus(status, Drupal.t('Saving draft...'));
          console.log('[save_draft] saving payload', data);

          try {
            const response = await fetch(saveUrl, {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': settings.csrfToken || '',
              },
              body: JSON.stringify({ data }),
            });

            const payload = await response.json();
            if (!response.ok || payload.status !== 'ok') {
              throw new Error(payload.message || 'Save failed');
            }

            dirty = false;
            button.disabled = true;
            button.classList.add('govgr-btn--disabled');
            setStatus(status, Drupal.t('Draft available (@date).', { '@date': payload.label }));
            showDraftButtons(form, true);

            loadButtons.forEach((loadButton) => {
              if (loadButton.closest('form') === form) {
                loadButton.hidden = false;
              }
            });
          } catch (error) {
            button.disabled = false;
            button.classList.remove('govgr-btn--disabled');
            setStatus(status, Drupal.t('Unable to save draft.'));
          }
        });
      });

      loadButtons.forEach((button) => {
        const form = button.closest('form');
        if (!form) {
          return;
        }
        const status = form.querySelector('.save-draft-status');

        const loadUrl = button.getAttribute('data-save-draft-load-url') || settings.loadUrl;
        button.addEventListener('click', async (event) => {
          event.preventDefault();
          setStatus(status, Drupal.t('Loading draft...'));

          try {
            const response = await fetch(loadUrl, { method: 'GET' });
            const payload = await response.json();
            if (!response.ok || payload.status !== 'ok') {
              throw new Error(payload.message || 'Load failed');
            }

            const data = payload.data || {};
            console.log('[save_draft] loaded payload', data);
            applyLoadedData(form, data);

            Object.keys(data).forEach((name) => {
              const elements = form.querySelectorAll(`[name="${CSS.escape(name)}"]`);
              elements.forEach((element) => {
                if (element.tagName.toLowerCase() === 'textarea' && element.hasAttribute('data-ckeditor5-id')) {
                  return;
                }
                if (window.jQuery && window.jQuery.fn && window.jQuery.fn.select2) {
                  if (element.tagName.toLowerCase() === 'select') {
                    window.jQuery(element).trigger('change');
                  }
                }
                element.dispatchEvent(new Event('change', { bubbles: true }));
                element.dispatchEvent(new Event('input', { bubbles: true }));
              });
            });

            // Best-effort preview for managed_file widgets (fids).
            const fileInfoUrl = (fid) => `/save-draft/file-info/${fid}`;
            Object.keys(data).forEach((name) => {
              if (!name.endsWith('[fids]')) {
                return;
              }
              const elements = form.querySelectorAll(`[name="${CSS.escape(name)}"]`);
              elements.forEach(async (element) => {
                const value = normalizeValue(element.value || data[name]);
                if (!value) {
                  return;
                }
                const fids = value.split(/\\s+|,/).filter(Boolean);
                if (!fids.length) {
                  return;
                }
                const wrapper = element.closest('.js-form-managed-file') || element.closest('.form-managed-file');
                if (!wrapper) {
                  return;
                }
                const container = wrapper.closest('details') || wrapper.parentElement;
                const table = container ? container.querySelector('table') : null;
                const tbody = table ? table.querySelector('tbody') : null;

                for (const fid of fids) {
                  try {
                    const response = await fetch(fileInfoUrl(fid), { method: 'GET' });
                    const info = await response.json();
                    if (!response.ok || info.status !== 'ok') {
                      throw new Error('file info');
                    }
                    if (tbody) {
                      const existing = tbody.querySelector(`tr[data-save-draft-fid="${fid}"]`);
                      if (existing) {
                        continue;
                      }
                      const tr = document.createElement('tr');
                      tr.className = 'draggable odd';
                      tr.dataset.saveDraftFid = fid;

                      const tdInfo = document.createElement('td');
                      const handle = document.createElement('a');
                      handle.href = '#';
                      handle.title = 'Change order';
                      handle.className = 'tabledrag-handle tabledrag-handle-y';
                      const handleDiv = document.createElement('div');
                      handleDiv.className = 'handle';
                      handle.appendChild(handleDiv);

                      const managed = document.createElement('div');
                      managed.className = 'ajax-new-content js-form-managed-file form-managed-file';
                      const fileSpan = document.createElement('span');
                      fileSpan.className = 'file';
                      const link = document.createElement('a');
                      link.href = info.url;
                      link.textContent = info.filename;
                      link.target = '_blank';
                      link.rel = 'noopener noreferrer';
                      fileSpan.appendChild(link);
                      managed.appendChild(fileSpan);

                      tdInfo.appendChild(handle);
                      tdInfo.appendChild(managed);
                      tr.appendChild(tdInfo);

                      const tdWeight = document.createElement('td');
                      tdWeight.className = 'tabledrag-hide';
                      tdWeight.style.display = 'none';
                      tr.appendChild(tdWeight);

                      const tdOps = document.createElement('td');
                      const removeBtn = document.createElement('button');
                      removeBtn.type = 'button';
                      removeBtn.className = 'button js-form-submit form-submit govgr-btn govgr-btn-primary';
                      removeBtn.textContent = Drupal.t('Remove');
                      removeBtn.addEventListener('click', () => {
                        element.value = '';
                        tr.remove();
                      });
                      tdOps.appendChild(removeBtn);
                      tr.appendChild(tdOps);

                      tbody.appendChild(tr);
                    } else {
                      const existing = wrapper.querySelector(`.save-draft-file-preview[data-save-draft-fid="${fid}"]`);
                      if (existing) {
                        continue;
                      }
                      const fileSpan = document.createElement('span');
                      fileSpan.className = 'file save-draft-file-preview';
                      fileSpan.dataset.saveDraftFid = fid;
                      if (info.mime) {
                        const mimeClass = info.mime.replace('/', '-').replace(/[^a-z0-9-]/gi, '');
                        fileSpan.classList.add(`file--mime-${mimeClass}`, `file--${mimeClass}`);
                      }
                      const link = document.createElement('a');
                      link.href = info.url;
                      link.textContent = info.filename;
                      link.target = '_blank';
                      link.rel = 'noopener noreferrer';
                      fileSpan.appendChild(link);

                      const removeBtn = document.createElement('button');
                      removeBtn.type = 'button';
                      removeBtn.className = 'button form-submit govgr-btn govgr-btn-primary save-draft-file-remove';
                      removeBtn.textContent = Drupal.t('Remove');
                      removeBtn.addEventListener('click', () => {
                        element.value = '';
                        fileSpan.remove();
                      });

                      const containerDiv = document.createElement('div');
                      containerDiv.className = 'save-draft-file-preview';
                      containerDiv.appendChild(fileSpan);
                      containerDiv.appendChild(removeBtn);
                      wrapper.prepend(containerDiv);
                    }
                  } catch (error) {
                    if (tbody) {
                      const tr = document.createElement('tr');
                      tr.className = 'draggable odd';
                      const tdInfo = document.createElement('td');
                      tdInfo.textContent = Drupal.t('File @fid', { '@fid': fid });
                      tr.appendChild(tdInfo);
                      tbody.appendChild(tr);
                    } else {
                      const fallback = document.createElement('div');
                      fallback.className = 'save-draft-file-preview';
                      fallback.textContent = Drupal.t('File @fid', { '@fid': fid });
                      wrapper.prepend(fallback);
                    }
                  }
                }
              });
            });
            const saveButton = form.querySelector('.save-draft-button');
            if (saveButton) {
              saveButton.disabled = false;
              saveButton.classList.remove('govgr-btn--disabled');
            }

            setStatus(status, Drupal.t('Draft available (@date).', { '@date': payload.label }));
          } catch (error) {
            setStatus(status, Drupal.t('Unable to load draft.'));
          }
        });
      });

      clearButtons.forEach((button) => {
        const form = button.closest('form');
        if (!form) {
          return;
        }
        const status = form.querySelector('.save-draft-status');
        const clearUrl = button.getAttribute('data-save-draft-clear-url') || settings.clearUrl;
        button.addEventListener('click', async (event) => {
          event.preventDefault();
          setStatus(status, Drupal.t('Clearing draft...'));
          try {
            const response = await fetch(clearUrl, {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': settings.csrfToken || '',
              },
              body: JSON.stringify({}),
            });
            const payload = await response.json();
            if (!response.ok || payload.status !== 'ok') {
              throw new Error(payload.message || 'Clear failed');
            }
            setStatus(status, Drupal.t('Draft cleared.'));
            showDraftButtons(form, false);
          } catch (error) {
            setStatus(status, Drupal.t('Unable to clear draft.'));
          }
        });
      });

      // Clear draft on regular form submissions (non-draft buttons).
      const regularButtons = once('save-draft-regular-submit', '.form-submit', context);
      regularButtons.forEach((button) => {
        if (button.classList.contains('save-draft-button') || button.classList.contains('load-draft-button') || button.classList.contains('clear-draft-button')) {
          return;
        }
        button.addEventListener('click', () => {
          const form = button.closest('form');
          if (!form) {
            return;
          }
          const clearUrl = (form.querySelector('.clear-draft-button') || button).getAttribute('data-save-draft-clear-url') || settings.clearUrl;
          if (!clearUrl) {
            return;
          }
          try {
            fetch(clearUrl, {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': settings.csrfToken || '',
              },
              body: JSON.stringify({}),
              keepalive: true,
            });
          } catch (error) {
            // Ignore clear failures on submit.
          }
        });
      });
    },
  };
})(Drupal, once, drupalSettings);
