/* Shared bounded HTTP lifecycle. No mutation is retried automatically. */
(function (global) {
  'use strict';
  const nativeFetch = global.fetch.bind(global);
  const TIMEOUT = 'Serveri nuk u përgjigj në kohën e pritur. Kontrollo nëse ndryshimi është ruajtur para se ta provosh përsëri.';

  class QtaRequestError extends Error {
    constructor(message, code, status, data, requestId) {
      super(message);
      this.name = code === 'aborted' ? 'AbortError' : 'QtaRequestError';
      this.code = code;
      this.status = status || 0;
      this.data = data || null;
      this.requestId = requestId || (data && data.request_id) || null;
    }
  }

  async function request(url, options) {
    const opts = Object.assign({ credentials: 'same-origin' }, options || {});
    const controller = new AbortController();
    const external = opts.signal;
    const timeout = Number.isFinite(opts.timeout) && opts.timeout > 0 ? opts.timeout : 20000;
    const raw = opts.response === 'response';
    const allow = opts.allowHttpErrors || [];
    const headers = new Headers(opts.headers || {});
    if (Object.prototype.hasOwnProperty.call(opts, 'json')) {
      headers.set('Content-Type', 'application/json');
      opts.body = JSON.stringify(opts.json);
    }
    if (!raw && !headers.has('Accept')) headers.set('Accept', 'application/json');
    const expectsJson = !raw || (headers.get('Accept') || '').includes('application/json');
    const abort = () => controller.abort();
    if (external) {
      if (external.aborted) controller.abort();
      else external.addEventListener('abort', abort, { once: true });
    }
    let timedOut = false;
    let timer;
    let rejectAbort;
    const cancelled = new Promise((resolve, reject) => { rejectAbort = reject; });
    const onAbort = () => {
      if (!timedOut) rejectAbort(new QtaRequestError('Kërkesa u anulua. Kontrollo gjendjen para se ta provosh përsëri.', 'aborted'));
    };
    controller.signal.addEventListener('abort', onAbort, { once: true });
    if (controller.signal.aborted) onAbort();
    // Race as well as abort: even a stalled body (or transport ignoring abort) is bounded.
    const deadline = new Promise((resolve, reject) => {
      timer = setTimeout(() => {
        timedOut = true;
        controller.abort();
        reject(new QtaRequestError(TIMEOUT, 'timeout'));
      }, timeout);
    });
    delete opts.timeout; delete opts.response; delete opts.allowHttpErrors; delete opts.json;
    opts.headers = headers;
    opts.signal = controller.signal;
    try {
      return await Promise.race([deadline, cancelled, (async () => {
        const res = await nativeFetch(url, opts);
        const id = res.headers.get('X-QTA-Request-ID');
        const text = await res.text();
        let data = null;
        if (expectsJson) {
          if (res.status === 401 || (res.redirected && /(?:selectProfile|login_handler|[?&]hyr=)/i.test(res.url))) {
            throw new QtaRequestError('Seanca ka mbaruar. Hyr përsëri.', 'session_expired', 401, null, id);
          }
          try { data = JSON.parse(text); } catch (e) {
            throw new QtaRequestError('Përgjigjja e serverit nuk ishte e vlefshme. Kontrollo gjendjen dhe provo sërish.' + (id ? ' Referenca: ' + id : ''), 'invalid_json', res.status, null, id);
          }
          if (!data || typeof data !== 'object') throw new QtaRequestError('Përgjigjja e serverit nuk ishte e vlefshme.', 'invalid_json', res.status, null, id);
        }
        if (!res.ok && !allow.includes(res.status)) {
          const defaults = { 401: 'Seanca ka mbaruar. Hyr përsëri.', 403: 'Nuk ke leje për këtë veprim. Kontrollo nëse ndryshimet janë të hapura.' };
          throw new QtaRequestError((data && data.error) || defaults[res.status] || 'Serveri nuk e përfundoi kërkesën. Kontrollo gjendjen dhe provo sërish.' + (id ? ' Referenca: ' + id : ''), 'http_error', res.status, data, id);
        }
        if (!raw) {
          if (data.ok === false) throw new QtaRequestError(data.error || 'Veprimi nuk u krye.', 'validation', res.status, data, id);
          return data;
        }
        // Consume the body inside the deadline, then return the normal Response API.
        const buffered = new Response([204, 205, 304].includes(res.status) ? null : text, { status: res.status, statusText: res.statusText, headers: res.headers });
        Object.defineProperties(buffered, { url: { value: res.url }, redirected: { value: res.redirected } });
        return buffered;
      })()]);
    } catch (e) {
      if (timedOut) throw new QtaRequestError(TIMEOUT, 'timeout');
      if (e instanceof QtaRequestError) throw e;
      if (controller.signal.aborted) throw new QtaRequestError('Kërkesa u anulua. Kontrollo gjendjen para se ta provosh përsëri.', 'aborted');
      throw new QtaRequestError('Lidhja me serverin u ndërpre. Kontrollo nëse ndryshimi është ruajtur para se ta provosh përsëri.', 'network');
    } finally {
      clearTimeout(timer);
      controller.signal.removeEventListener('abort', onAbort);
      if (external) external.removeEventListener('abort', abort);
    }
  }

  // Existing callers inspect validation/confirmation JSON themselves. Transport,
  // session and server errors always reject; new callers use qtaFetch({json: ...}).
  request.response = (url, options) => request(url, Object.assign({ response: 'response', allowHttpErrors: [400, 403, 409, 422] }, options || {}));
  global.qtaFetch = request;
  global.QtaRequestError = QtaRequestError;

  const pendingForms = new Map();
  const submitEvents = new WeakMap();
  function resetForm(form) {
    const state = pendingForms.get(form);
    if (!state) return;
    clearTimeout(state.timer);
    state.controls.forEach(([el, disabled, aria]) => {
      el.disabled = disabled;
      el.classList.remove('is-loading');
      if (aria === null) el.removeAttribute('aria-busy'); else el.setAttribute('aria-busy', aria);
    });
    form.removeAttribute('aria-busy');
    delete form.dataset.ready; delete form.dataset.confirmed;
    pendingForms.delete(form);
    submitEvents.delete(form);
  }
  global.qtaNativeSubmit = function (form, submitter) {
    if (pendingForms.has(form)) return;
    if (form.requestSubmit) form.requestSubmit(submitter || undefined);
    else if (form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }))) HTMLFormElement.prototype.submit.call(form);
  };
  document.addEventListener('submit', function (event) {
    const previous = submitEvents.get(event.target);
    if (pendingForms.has(event.target) || (previous && !previous.defaultPrevented)) {
      event.preventDefault();
      event.stopImmediatePropagation();
      return;
    }
    submitEvents.set(event.target, event);
  }, true);
  document.addEventListener('submit', function (event) {
    const form = event.target;
    queueMicrotask(() => {
    if (event.defaultPrevented || form.method.toLowerCase() !== 'post' || (form.target && form.target !== '_self')) return;
    const controls = Array.from(form.querySelectorAll('button[type="submit"], button:not([type]), input[type="submit"]'));
    const state = { controls: controls.map(el => [el, el.disabled, el.getAttribute('aria-busy')]) };
    pendingForms.set(form, state);
    form.setAttribute('aria-busy', 'true');
    controls.forEach(el => { el.classList.add('is-loading'); el.setAttribute('aria-busy', 'true'); });
    // Let the native form collect the submitter's name/value before disabling it.
    setTimeout(() => { if (pendingForms.has(form)) controls.forEach(el => { el.disabled = true; }); }, 0);
    state.timer = setTimeout(() => {
      resetForm(form);
      if (global.qtaToast) global.qtaToast(TIMEOUT, 'warning', null, { autohide: false });
    }, 20000);
    });
  });
  global.addEventListener('pageshow', () => Array.from(pendingForms.keys()).forEach(resetForm));
})(window);
