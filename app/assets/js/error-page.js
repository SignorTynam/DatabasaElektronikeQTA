(function () {
  'use strict';
  var path = document.querySelector('[data-request-path]');
  if (path) path.textContent = window.location.pathname + window.location.search;
  var back = document.querySelector('[data-back]');
  if (back) {
    back.addEventListener('click', function () {
      if (window.history.length > 1) window.history.back();
      else window.location.href = '/DatabasaElektronike/';
    });
  }
}());
