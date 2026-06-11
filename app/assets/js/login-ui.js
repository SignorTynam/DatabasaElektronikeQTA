(function () {
  'use strict';

  var roleMeta = {
    administrator: {
      title: 'Administrator',
      hint: 'Akses për menaxhim të plotë të sistemit.'
    },
    editor: {
      title: 'Editor',
      hint: 'Akses për menaxhimin e proceseve dhe të dhënave.'
    },
    agjencia: {
      title: 'Agjenci',
      hint: 'Akses për kursantët dhe grupet e agjencisë.'
    },
    student: {
      title: 'Student',
      hint: 'Akses për të dhënat personale, progresin dhe certifikatat.'
    }
  };

  function selectRole(role, focusInput) {
    if (!roleMeta[role]) {
      role = 'administrator';
    }

    document.querySelectorAll('[data-role-option]').forEach(function (button) {
      var active = button.getAttribute('data-role-option') === role;
      button.classList.toggle('active', active);
      button.setAttribute('aria-pressed', active ? 'true' : 'false');
    });

    document.querySelectorAll('form.login-form').forEach(function (form) {
      form.classList.toggle('d-none', form.getAttribute('data-role') !== role);
    });

    var badge = document.querySelector('[data-role-badge]');
    var hint = document.querySelector('[data-role-hint]');
    if (badge) {
      badge.textContent = roleMeta[role].title;
    }
    if (hint) {
      hint.textContent = roleMeta[role].hint;
    }

    var url = new URL(window.location.href);
    url.searchParams.set('role', role);
    window.history.replaceState({}, '', url);

    if (focusInput) {
      var input = document.querySelector('form.login-form[data-role="' + role + '"] input[name="identifier"]');
      if (input) {
        input.focus();
      }
    }
  }

  document.querySelectorAll('[data-role-option]').forEach(function (button) {
    button.addEventListener('click', function () {
      selectRole(button.getAttribute('data-role-option'), true);
    });
  });

  document.querySelectorAll('[data-password-toggle]').forEach(function (button) {
    button.addEventListener('click', function () {
      var input = document.querySelector(button.getAttribute('data-password-toggle'));
      if (!input) {
        return;
      }
      var show = input.getAttribute('type') === 'password';
      input.setAttribute('type', show ? 'text' : 'password');
      button.innerHTML = show ? '<i class="bi bi-eye-slash"></i>' : '<i class="bi bi-eye"></i>';
      input.focus();
    });
  });

  document.querySelectorAll('.login-password').forEach(function (input) {
    var warning = input.closest('form')?.querySelector('[data-caps-warning]');
    if (!warning) {
      return;
    }
    var update = function (event) {
      warning.classList.toggle('d-none', !(event.getModifierState && event.getModifierState('CapsLock')));
    };
    input.addEventListener('keydown', update);
    input.addEventListener('keyup', update);
    input.addEventListener('blur', function () { warning.classList.add('d-none'); });
  });

  document.querySelectorAll('form.login-form').forEach(function (form) {
    form.addEventListener('submit', function (event) {
      if (!form.checkValidity()) {
        event.preventDefault();
        event.stopPropagation();
        form.classList.add('was-validated');
        return;
      }

      var button = form.querySelector('button[type="submit"]');
      if (button) {
        button.disabled = true;
        button.innerHTML = '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Duke u futur...';
      }
    });
  });

  if (window.QTA_LOGIN_ERROR && window.qtaToast) {
    window.qtaToast(window.QTA_LOGIN_ERROR, 'danger');
  }

  var initial = document.querySelector('[data-role-option].active')?.getAttribute('data-role-option') || 'administrator';
  selectRole(initial, false);
})();
