/* error-page.js — Faqet e gabimeve.
   Ngarkohet në <head> (pa defer) që pamja e zgjedhur të vendoset para vizatimit;
   pjesa tjetër pret DOMContentLoaded. */
(function () {
  'use strict';

  /* Pamja: e njëjta zgjedhje si në portal (qta_theme = light | dark | system) */
  var mode = 'system';
  try { mode = window.localStorage.getItem('qta_theme') || 'system'; } catch (e) { mode = 'system'; }
  var dark = mode === 'dark' || (mode !== 'light' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
  document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');

  function pad(n) { return (n < 10 ? '0' : '') + n; }

  document.addEventListener('DOMContentLoaded', function () {
    var path = document.querySelector('[data-request-path]');
    if (path) path.textContent = window.location.pathname + window.location.search;

    /* Ora e gabimit: e ndihmon QTA-në ta gjejë në regjistrat e serverit */
    var when = document.querySelector('[data-when]');
    if (when) {
      var d = new Date();
      when.textContent = pad(d.getDate()) + '.' + pad(d.getMonth() + 1) + '.' + d.getFullYear() + ', ora ' + pad(d.getHours()) + ':' + pad(d.getMinutes());
    }

    var back = document.querySelector('[data-back]');
    if (back) {
      if (window.history.length <= 1) back.hidden = true;
      back.addEventListener('click', function () { window.history.back(); });
    }
  });
}());
