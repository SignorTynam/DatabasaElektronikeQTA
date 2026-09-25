/* Hyrja: fusha përshtatet me llojin e llogarisë, kujton llojin e fundit,
   Caps Lock, validim i butë, gjendja "po hyn". */
(function () {
  'use strict';

  var form = document.querySelector('[data-login-form]');
  if (!form) return;

  var input = form.querySelector('#identifier');
  var label = form.querySelector('[data-id-label]');
  var help = form.querySelector('[data-id-help]');
  var hint = form.querySelector('[data-role-hint]');
  var forgot = form.querySelector('[data-forgot-text]');
  var password = form.querySelector('#password');
  var caps = form.querySelector('[data-caps-warning]');
  var radios = form.querySelectorAll('input[name="role"]');
  var STORE = 'qta_last_role';

  function applyRole(radio, focus) {
    if (!radio) return;
    var type = radio.getAttribute('data-type') || 'text';
    if (label) label.textContent = radio.getAttribute('data-label') || '';
    if (help) help.textContent = radio.getAttribute('data-help') || '';
    if (hint) hint.textContent = radio.getAttribute('data-hint') || '';
    if (forgot) forgot.textContent = radio.getAttribute('data-forgot') || '';
    if (input) {
      if (input.type !== type) input.value = '';
      input.type = type;
      input.placeholder = radio.getAttribute('data-placeholder') || '';
      input.classList.toggle('input-code', type !== 'email');
      input.setAttribute('inputmode', type === 'email' ? 'email' : 'text');
      input.setAttribute('autocapitalize', type === 'email' ? 'off' : 'characters');
      input.classList.remove('is-invalid');
      if (focus) input.focus();
    }
    try {
      var url = new URL(window.location.href);
      url.searchParams.set('role', radio.value);
      window.history.replaceState({}, '', url);
    } catch (e) { /* pa URL API */ }
  }

  Array.prototype.forEach.call(radios, function (radio) {
    radio.addEventListener('change', function () { applyRole(radio, true); });
  });

  /* Kush hyn shpesh nga i njëjti telefon nuk e zgjedh llojin çdo herë.
     Lidhja me ?role=… (p.sh. nga kryefaqja) ka përparësi. */
  if (!form.hasAttribute('data-role-fixed') && !document.querySelector('[data-login-error]')) {
    var last = null;
    try { last = window.localStorage.getItem(STORE); } catch (e) { last = null; }
    var match = last ? form.querySelector('input[name="role"][value="' + last + '"]') : null;
    if (match && !match.checked) {
      match.checked = true;
      applyRole(match, false);
    }
  }

  if (password && caps) {
    var update = function (event) {
      var on = !!(event.getModifierState && event.getModifierState('CapsLock'));
      caps.hidden = !on;
    };
    password.addEventListener('keydown', update);
    password.addEventListener('keyup', update);
    password.addEventListener('blur', function () { caps.hidden = true; });
  }

  [input, password].forEach(function (el) {
    if (el) el.addEventListener('input', function () { el.classList.remove('is-invalid'); });
  });

  form.addEventListener('submit', function (event) {
    var missing = [input, password].filter(function (el) { return el && !el.value.trim(); });
    if (missing.length) {
      event.preventDefault();
      missing.forEach(function (el) { el.classList.add('is-invalid'); });
      missing[0].focus();
      return;
    }
    var chosen = form.querySelector('input[name="role"]:checked');
    if (chosen) {
      try { window.localStorage.setItem(STORE, chosen.value); } catch (e) { /* ruajtja e bllokuar */ }
    }
    var button = form.querySelector('button[type="submit"]');
    if (button) {
      button.classList.add('is-loading');
      button.setAttribute('aria-busy', 'true');
    }
  });

  var error = document.querySelector('[data-login-error]');
  if (error) {
    error.focus();
  } else if (input && !input.value && window.matchMedia('(min-width: 768px)').matches) {
    /* Në telefon nuk hapim tastierën vetë: fillimisht shihet e gjithë faqja. */
    input.focus();
  }
})();
