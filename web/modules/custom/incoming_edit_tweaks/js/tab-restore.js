(function (Drupal, once) {
  'use strict';

  function normalizeTabToken(value) {
    return (value || '').replace(/^#/, '').trim();
  }

  function toTabAlias(value) {
    return normalizeTabToken(value).replace(/^edit-group-/i, '').toLowerCase();
  }

  function findHorizontalTabTarget(form, rawToken) {
    const token = normalizeTabToken(rawToken).toLowerCase();
    if (token === '') {
      return '';
    }

    const links = form.querySelectorAll(
      '.horizontal-tab-button a[href^="#edit-group-"]',
    );
    for (const link of links) {
      const target = normalizeTabToken(link.getAttribute('href'));
      const alias = toTabAlias(target);
      if (target.toLowerCase() === token || alias === token) {
        return target;
      }
    }

    return '';
  }

  function activateHorizontalTab(form, targetId) {
    if (!targetId) {
      return;
    }
    const escapedId =
      typeof CSS !== 'undefined' && CSS.escape
        ? CSS.escape(targetId)
        : targetId.replace(/([ #;?%&,.+*~':"!^$[\]()=>|/@])/g, '\\$1');
    const link = form.querySelector(
      `.horizontal-tab-button a[href="#${escapedId}"]`,
    );
    if (link) {
      link.click();
    }
  }

  function restoreFromQuery(form) {
    const params = new URLSearchParams(window.location.search);
    const requested = params.get('tab') || '';
    const targetId = findHorizontalTabTarget(form, requested);
    if (targetId === '') {
      return false;
    }

    window.requestAnimationFrame(() => activateHorizontalTab(form, targetId));
    return true;
  }

  function getActiveTabId(form) {
    const selectedHorizontalTab = form.querySelector(
      '.horizontal-tab-button.selected a[href^="#"]',
    );
    if (selectedHorizontalTab) {
      const value = toTabAlias(selectedHorizontalTab.getAttribute('href'));
      if (value !== '') {
        return value;
      }
    }

    const activeHorizontalInputs = form.querySelectorAll(
      'input.horizontal-tabs-active-tab',
    );
    for (const input of activeHorizontalInputs) {
      const value = toTabAlias(input.value);
      if (value !== '') {
        return value;
      }
    }

    const activeInputs = form.querySelectorAll('input.vertical-tabs__active-tab');
    for (const input of activeInputs) {
      const value = toTabAlias(input.value);
      if (value !== '') {
        return value;
      }
    }

    const selectedTabLink = form.querySelector(
      '.vertical-tabs__menu-item.is-selected a[href^="#"]',
    );
    if (!selectedTabLink) {
      return '';
    }

    return toTabAlias(selectedTabLink.getAttribute('href'));
  }

  Drupal.behaviors.incomingEditTweaksTabRestore = {
    attach(context) {
      once('incoming-edit-tweaks-tab-restore', 'form', context).forEach(
        (form) => {
          const hidden = form.querySelector(
            'input[name="incoming_edit_tweaks_active_tab"]',
          );
          if (!hidden) {
            return;
          }

          const setActiveTab = () => {
            hidden.value = getActiveTabId(form);
          };

          restoreFromQuery(form);

          form.addEventListener(
            'click',
            (event) => {
              if (!event.target.closest('button, input[type="submit"]')) {
                return;
              }
              setActiveTab();
            },
            true,
          );

          form.addEventListener(
            'submit',
            () => {
              setActiveTab();
            },
            true,
          );
        },
      );
    },
  };
})(Drupal, once);
