(function (Drupal, once) {
  'use strict';

  const PASSWORD_SELECTOR = '#edit-pass-pass1, #edit-pass-pass2';

  function setVisibleState(input, button, isVisible) {
    input.type = isVisible ? 'text' : 'password';
    button.classList.toggle('is-active', isVisible);
    button.setAttribute('aria-pressed', isVisible ? 'true' : 'false');
    button.setAttribute('aria-label', isVisible ? Drupal.t('Hide password') : Drupal.t('Show password'));
  }

  Drupal.behaviors.kemkePasswordToggle = {
    attach(context) {
      once('kemke-password-toggle', PASSWORD_SELECTOR, context).forEach((input) => {
        if (!(input instanceof HTMLInputElement) || input.type !== 'password' || !input.parentNode) {
          return;
        }

        const wrapper = document.createElement('div');
        wrapper.className = 'kemke-password-toggle';
        input.parentNode.insertBefore(wrapper, input);
        wrapper.appendChild(input);

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'kemke-password-toggle__button';
        button.setAttribute('aria-controls', input.id);
        button.innerHTML = '<span class="visually-hidden">' + Drupal.t('Show password') + '</span>';

        button.addEventListener('click', () => {
          setVisibleState(input, button, input.type === 'password');
        });

        wrapper.appendChild(button);
        setVisibleState(input, button, false);
      });
    },
  };
})(Drupal, once);
