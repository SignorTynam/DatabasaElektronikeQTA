/* Kontakti: kontrollon fushat, pastaj ose hap email-in e gatshëm, ose kopjon
   mesazhin (për kë nuk ka program email-i). Asgjë nuk dërgohet te serveri. */
(function () {
  'use strict';

  var form = document.querySelector('#contactForm');
  if (!form) return;

  var TO = form.getAttribute('data-contact-email') || 'officialqta@gmail.com';
  var topicField = form.querySelector('.topic-field');

  function value(name) {
    var el = form.elements[name];
    if (!el) return '';
    if (el instanceof RadioNodeList) return String(el.value || '').trim();
    return String(el.value || '').trim();
  }

  /* Email-i ose telefoni: pranohet njëri nga të dy. */
  function validReplyTo(v) {
    if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) return true;
    return v.replace(/[^\d]/g, '').length >= 8 && /^[+\d\s().-]+$/.test(v);
  }

  function check() {
    var bad = [];
    var name = form.elements.full_name;
    var reply = form.elements.reply_to;
    var message = form.elements.message;

    name.classList.toggle('is-invalid', value('full_name') === '');
    if (value('full_name') === '') bad.push(name);

    var replyOk = validReplyTo(value('reply_to'));
    reply.classList.toggle('is-invalid', !replyOk);
    if (!replyOk) bad.push(reply);

    var topicOk = value('topic') !== '';
    topicField.classList.toggle('is-invalid', !topicOk);
    if (!topicOk) bad.push(form.querySelector('input[name="topic"]'));

    message.classList.toggle('is-invalid', value('message') === '');
    if (value('message') === '') bad.push(message);

    if (bad.length) bad[0].focus();
    return bad.length === 0;
  }

  function subject() {
    return '[Kontakt QTA] ' + value('topic') + ' — ' + value('full_name');
  }

  function body() {
    return value('message') + '\n\n' +
      '— ' + value('full_name') + '\n' +
      'Kontakt: ' + value('reply_to') + '\n' +
      'Tema: ' + value('topic') + '\n';
  }

  function copyText(text, done, fail) {
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done, function () { fallback(text, done, fail); });
    } else {
      fallback(text, done, fail);
    }
  }
  function fallback(text, done, fail) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
    ta.remove();
    if (ok) done(); else fail();
  }

  function toast(message, variant) {
    if (window.qtaToast) window.qtaToast(message, variant || 'success');
  }

  /* Gabimi hiqet sapo fusha plotësohet */
  ['full_name', 'reply_to', 'message'].forEach(function (name) {
    var el = form.elements[name];
    if (el) el.addEventListener('input', function () { el.classList.remove('is-invalid'); });
  });
  Array.prototype.forEach.call(form.querySelectorAll('input[name="topic"]'), function (radio) {
    radio.addEventListener('change', function () { topicField.classList.remove('is-invalid'); });
  });

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    if (!check()) return;
    window.location.href = 'mailto:' + TO + '?subject=' + encodeURIComponent(subject()) + '&body=' + encodeURIComponent(body());
    toast('Email-i u përgatit në programin tuaj. Mos harroni ta dërgoni.');
  });

  var copyBtn = form.querySelector('[data-contact-copy]');
  if (copyBtn) {
    copyBtn.addEventListener('click', function () {
      if (!check()) return;
      var text = subject() + '\n\n' + body() + '\nPër: ' + TO;
      copyText(text, function () {
        toast('Mesazhi u kopjua. Ngjiteni në email, WhatsApp ose SMS dhe dërgojeni te ' + TO + '.');
      }, function () {
        toast('Shfletuesi nuk lejoi kopjimin. Përzgjidhni tekstin dhe shtypni Ctrl+C.', 'warning');
      });
    });
  }
})();
