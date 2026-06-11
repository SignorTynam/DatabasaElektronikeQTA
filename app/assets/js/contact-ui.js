(function () {
  'use strict';

  var form = document.querySelector('#contactForm');
  if (!form) {
    return;
  }

  var mailto = document.querySelector('#contactMailto');
  var progress = document.querySelector('[data-contact-progress]');
  var fields = ['full_name', 'email', 'phone', 'subject', 'request_type', 'message'];

  function value(name) {
    return (form.elements[name]?.value || '').trim();
  }

  function updateProgress() {
    var filled = fields.filter(function (name) { return value(name).length > 0; }).length;
    if (progress) {
      progress.style.width = Math.max(12, Math.round((filled / fields.length) * 100)) + '%';
    }
  }

  function updateMailto() {
    if (!mailto) {
      return;
    }
    var subject = encodeURIComponent('[Kontakt QTA] ' + (value('subject') || value('request_type') || 'Kërkesë'));
    var body = encodeURIComponent(
      'Emër dhe mbiemër: ' + value('full_name') + '\n' +
      'Email: ' + value('email') + '\n' +
      'Telefon: ' + value('phone') + '\n' +
      'Lloji i kërkesës: ' + value('request_type') + '\n\n' +
      'Mesazhi:\n' + value('message') + '\n'
    );
    mailto.href = 'mailto:officialqta@gmail.com?subject=' + subject + '&body=' + body;
  }

  fields.forEach(function (name) {
    var el = form.elements[name];
    if (!el) {
      return;
    }
    el.addEventListener('input', function () {
      updateProgress();
      updateMailto();
    });
    el.addEventListener('change', function () {
      updateProgress();
      updateMailto();
    });
  });

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    form.classList.add('was-validated');

    if (!form.checkValidity()) {
      updateProgress();
      return;
    }

    updateMailto();
    if (window.qtaToast) {
      window.qtaToast('Mesazhi u përgatit. Përdorni email-in zyrtar ose telefonin për dërgim zyrtar.', 'success');
    }
    if (mailto) {
      mailto.focus();
    }
  });

  document.querySelectorAll('[data-copy]').forEach(function (button) {
    button.addEventListener('click', async function () {
      try {
        await navigator.clipboard.writeText(button.getAttribute('data-copy') || '');
        if (window.qtaToast) {
          window.qtaToast('U kopjua.', 'primary');
        }
      } catch (error) {
        if (window.qtaToast) {
          window.qtaToast('Kopjimi nuk u krye nga shfletuesi.', 'warning');
        }
      }
    });
  });

  updateProgress();
  updateMailto();
})();
