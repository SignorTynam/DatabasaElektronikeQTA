/* Kontakti: kontrollon fushat, pastaj hap mesazhin e gatshëm në programin e email-it. */
(function () {
  'use strict';

  var form = document.querySelector('#contactForm');
  if (!form) return;

  function value(name) {
    var el = form.elements[name];
    return el ? String(el.value || '').trim() : '';
  }

  function mailtoHref() {
    var subject = '[Kontakt QTA] ' + (value('subject') || value('request_type') || 'Kërkesë');
    var body =
      'Emri dhe mbiemri: ' + value('full_name') + '\n' +
      'Email: ' + value('email') + '\n' +
      'Telefoni: ' + (value('phone') || '—') + '\n' +
      'Arsyeja: ' + value('request_type') + '\n\n' +
      value('message') + '\n';
    return 'mailto:officialqta@gmail.com?subject=' + encodeURIComponent(subject) + '&body=' + encodeURIComponent(body);
  }

  Array.prototype.forEach.call(form.elements, function (el) {
    if (!el.name) return;
    ['input', 'change'].forEach(function (type) {
      el.addEventListener(type, function () { if (el.checkValidity()) el.classList.remove('is-invalid'); });
    });
  });

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    var invalid = Array.prototype.filter.call(form.elements, function (el) {
      return el.name && el.willValidate && !el.checkValidity();
    });
    invalid.forEach(function (el) { el.classList.add('is-invalid'); });
    if (invalid.length) {
      invalid[0].focus();
      return;
    }
    window.location.href = mailtoHref();
    if (window.qtaToast) {
      window.qtaToast('Mesazhi u hap në programin e email-it. Mos harroni ta dërgoni.', 'success');
    }
  });
})();
