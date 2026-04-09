(function (Drupal, once) {
  'use strict';

  function syncToolbarTitle(link) {
    if (!(link instanceof HTMLElement)) {
      return;
    }

    const fullTitle = link.getAttribute('data-full-title');
    if (!fullTitle) {
      return;
    }

    if (link.getAttribute('title') !== fullTitle) {
      link.setAttribute('title', fullTitle);
    }
  }

  Drupal.behaviors.usersTweaksToolbarTitle = {
    attach(context) {
      once('users-tweaks-toolbar-title', '#toolbar-item-user', context).forEach((link) => {
        syncToolbarTitle(link);

        const observer = new MutationObserver(() => {
          syncToolbarTitle(link);
        });

        observer.observe(link, {
          attributes: true,
          attributeFilter: ['title', 'data-full-title'],
        });
      });
    },
  };
})(Drupal, once);
