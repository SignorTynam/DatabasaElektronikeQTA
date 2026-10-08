/* Hyrja: dialogu mbi faqet publike (shared/partials/login_dialog.php).
   - Çdo [data-login] e hap pa ndërruar faqe; vlera e tij (student, agjencia,
     staff) zgjedh llojin e llogarisë. Ctrl/⌘ + klik e hap lidhjen si zakonisht.
   - Fusha përshtatet me llojin, lloji i fundit kujtohet, Caps Lock paralajmërohet.
   - Formulari dërgohet me fetch: gabimi del brenda dialogut dhe fokusi shkon aty
     ku duhet ndrequr; hyrja e suksesshme hap panelin e rolit.
   - Pa Bootstrap (CDN-ja nuk u ngarkua) lidhjet çojnë te selectProfile.php dhe
     dialogu i kërkuar (?hyr) shfaqet në faqe si formular i zakonshëm. */
(function () {
  'use strict';

  var modalEl = document.getElementById('loginModal');
  var form = modalEl ? modalEl.querySelector('[data-login-form]') : null;
  if (!form) return;

  var Modal = window.bootstrap && window.bootstrap.Modal ? window.bootstrap.Modal : null;
  var input = form.querySelector('#loginId');
  var label = form.querySelector('[data-login-label]');
  var help = form.querySelector('[data-login-help]');
  var missing = form.querySelector('[data-login-missing]');
  var forgot = form.querySelector('[data-login-forgot]');
  var password = form.querySelector('#loginPassword');
  var toggle = form.querySelector('[data-password-toggle]');
  var caps = form.querySelector('[data-caps-warning]');
  var csrf = form.querySelector('input[name="csrf"]');
  var submit = form.querySelector('button[type="submit"]');
  var status = form.querySelector('[data-login-status]');
  var slot = modalEl.querySelector('[data-login-error]');
  var radios = form.querySelectorAll('input[name="role"]');
  var STORE = 'qta_last_role';
  var MSG_SERVER = 'Hyrja nuk u krye për shkak të një problemi teknik. Provo sërish pas pak.';
  var MSG_OFFLINE = 'Hyrja nuk u krye. Kontrollo lidhjen me internetin dhe provo sërish.';
  var busy = false;
  var pending = null;

  /* ------------------------------------------------ Lloji i llogarisë */
  function radioFor(role) {
    for (var i = 0; i < radios.length; i++) {
      if (radios[i].value === role) return radios[i];
    }
    return null;
  }

  /* Fusha ndjek llojin e llogarisë. Vlera e shkruar mbetet: kush e shkroi te
     lloji i gabuar, ndërron llojin pa e rishkruar. */
  function applyRole(radio) {
    var type = radio.getAttribute('data-type') || 'text';
    var isEmail = type === 'email';
    label.textContent = radio.getAttribute('data-label') || '';
    help.textContent = radio.getAttribute('data-help') || '';
    missing.textContent = radio.getAttribute('data-missing') || '';
    forgot.textContent = radio.getAttribute('data-forgot') || '';
    input.type = type;
    input.placeholder = radio.getAttribute('data-placeholder') || '';
    input.classList.toggle('input-code', !isEmail);
    input.setAttribute('inputmode', isEmail ? 'email' : 'text');
    input.setAttribute('autocapitalize', isEmail ? 'off' : 'characters');
    markInvalid(input, false);
  }

  function selectRole(role) {
    var radio = radioFor(role);
    if (!radio) return;
    radio.checked = true;
    applyRole(radio);
  }

  function remember() {
    var chosen = form.querySelector('input[name="role"]:checked');
    if (!chosen) return;
    try { window.localStorage.setItem(STORE, chosen.value); } catch (e) { /* ruajtja e bllokuar */ }
  }

  Array.prototype.forEach.call(radios, function (radio) {
    radio.addEventListener('change', function () {
      applyRole(radio);
      clearError();
    });
  });

  /* Kush hyn shpesh nga i njëjti shfletues nuk e zgjedh llojin çdo herë.
     Lloji në adresë (?hyr=student) ose te butoni që e hap ka përparësi. */
  if (!form.hasAttribute('data-role-fixed') && !slot.firstElementChild) {
    var last = null;
    try { last = window.localStorage.getItem(STORE); } catch (e) { last = null; }
    if (last) selectRole(last);
  }

  /* -------------------------------------------- Fushat dhe gabimet */
  /* Mesazhi i gabimit dhe Caps Lock i përshkruajnë fushën vetëm kur shihen. */
  function describe() {
    input.setAttribute('aria-describedby', 'loginIdHelp' + (input.classList.contains('is-invalid') ? ' loginIdError' : ''));
    var ids = [];
    if (password.classList.contains('is-invalid')) ids.push('loginPasswordError');
    if (!caps.hidden) ids.push('loginCaps');
    if (ids.length) password.setAttribute('aria-describedby', ids.join(' '));
    else password.removeAttribute('aria-describedby');
  }

  function markInvalid(el, on) {
    el.classList.toggle('is-invalid', on);
    if (on) el.setAttribute('aria-invalid', 'true');
    else el.removeAttribute('aria-invalid');
    describe();
  }

  [input, password].forEach(function (el) {
    el.addEventListener('input', function () {
      if (el.classList.contains('is-invalid')) markInvalid(el, false);
    });
  });

  function updateCaps(event) {
    var on = !!(event.getModifierState && event.getModifierState('CapsLock'));
    if (caps.hidden === !on) return;
    caps.hidden = !on;
    describe();
  }
  password.addEventListener('keydown', updateCaps);
  password.addEventListener('keyup', updateCaps);
  password.addEventListener('blur', function () {
    if (caps.hidden) return;
    caps.hidden = true;
    describe();
  });

  function clearError() {
    slot.textContent = '';
  }

  /* Nyje e re çdo herë: lexuesi i ekranit e lexon edhe kur mesazhi përsëritet. */
  function showError(message) {
    slot.textContent = '';
    var box = document.createElement('div');
    box.className = 'alert alert-danger login-error';
    var icon = document.createElement('i');
    icon.className = 'bi bi-exclamation-octagon';
    icon.setAttribute('aria-hidden', 'true');
    var text = document.createElement('span');
    text.textContent = message;
    box.appendChild(icon);
    box.appendChild(text);
    slot.appendChild(box);
  }

  function hidePassword() {
    if (password.type === 'password') return;
    password.type = 'password';
    if (!toggle) return;
    toggle.setAttribute('aria-pressed', 'false');
    toggle.setAttribute('aria-label', 'Shfaq fjalëkalimin');
    var icon = toggle.querySelector('i');
    if (icon) icon.className = 'bi bi-eye';
  }

  /* ---------------------------------------------------------- Dërgimi */
  function setBusy(on, message) {
    busy = on;
    submit.classList.toggle('is-loading', on);
    if (on) submit.setAttribute('aria-busy', 'true');
    else submit.removeAttribute('aria-busy');
    if (status) status.textContent = on ? (message || 'Po hyn…') : '';
  }

  function stop(message, code) {
    pending = null;
    setBusy(false);
    showError(message);
    if (code === 'credentials') {
      password.focus();
      password.select();
    }
  }

  function send(retried) {
    var controller = window.AbortController ? new window.AbortController() : null;
    pending = controller || true;
    setBusy(true);
    window.qtaFetch.response(form.action, {
      method: 'POST',
      body: new window.FormData(form),
      headers: { 'Accept': 'application/json' },
      credentials: 'same-origin',
      signal: controller ? controller.signal : undefined
    }).then(function (response) {
      return response.json().catch(function () { return null; });
    }).then(function (data) {
      if (pending !== (controller || true)) return; /* dialogu u mbyll ndërkohë */
      if (data && data.csrf && csrf) csrf.value = data.csrf;
      if (data && data.ok && data.redirect) {
        remember();
        setBusy(true, 'Hyrja u krye. Po hapet paneli…');
        window.location.assign(new URL(data.redirect, form.action).href);
        return;
      }
      /* Faqja kishte qëndruar e hapur gjatë: serveri dha një shenjë të re, provojmë një herë vetë. */
      if (data && data.code === 'csrf' && !retried) {
        send(true);
        return;
      }
      stop(data && data.error ? data.error : MSG_SERVER, data && data.code);
    }).catch(function (error) {
      if (error && error.name === 'AbortError') return;
      if (pending !== (controller || true)) return;
      stop(error.message || MSG_OFFLINE);
    }).finally(function () {
      if (pending === (controller || true)) { pending = null; setBusy(false); }
    });
  }

  form.addEventListener('submit', function (event) {
    if (!window.fetch || !window.FormData) return; /* shfletues i vjetër: dërgim i zakonshëm */
    event.preventDefault();
    if (busy) return;
    var empty = [];
    if (!input.value.trim()) empty.push(input);
    if (!password.value) empty.push(password);
    if (empty.length) {
      empty.forEach(function (el) { markInvalid(el, true); });
      empty[0].focus();
      return;
    }
    clearError();
    send(false);
  });

  /* ------------------------------------------------- Hapja dhe mbyllja */
  if (!Modal) {
    if (modalEl.classList.contains('is-requested')) modalEl.classList.add('is-static');
    return;
  }

  document.addEventListener('click', function (event) {
    var trigger = event.target.closest ? event.target.closest('[data-login]') : null;
    if (!trigger || event.defaultPrevented) return;
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    /* "Hyr si agjenci" pas një prove si staf: fusha nis bosh për llojin e ri. */
    var role = trigger.getAttribute('data-login');
    var current = form.querySelector('input[name="role"]:checked');
    if (role && radioFor(role) && (!current || current.value !== role)) {
      input.value = '';
      selectRole(role);
    }
    Modal.getOrCreateInstance(modalEl).show(trigger);
  });

  modalEl.addEventListener('shown.bs.modal', function () {
    var target = input.value.trim() ? password : input;
    try { target.focus(); } catch (e) { /* fokusi mbetet te dialogu */ }
  });

  modalEl.addEventListener('hidden.bs.modal', function () {
    if (pending && pending.abort) pending.abort();
    pending = null;
    setBusy(false);
    clearError();
    password.value = '';
    hidePassword();
    caps.hidden = true;
    markInvalid(input, false);
    markInvalid(password, false);
    /* Dialogu i hapur nga adresa (?hyr) nuk ka buton që e hapi: fokusi shkon
       te "Hyr" në kokë, ose te "Menuja" kur koka është e mbledhur. */
    window.setTimeout(function () {
      var active = document.activeElement;
      if (document.querySelector('.modal.show') || (active && active !== document.body)) return;
      var places = document.querySelectorAll('.masthead [data-login], [data-mast-toggle]');
      for (var i = 0; i < places.length; i++) {
        if (places[i].getClientRects().length) {
          try { places[i].focus({ preventScroll: true }); } catch (e) { /* mbetet ku është */ }
          return;
        }
      }
    }, 60);
  });
})();
