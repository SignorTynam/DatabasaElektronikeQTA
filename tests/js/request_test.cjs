const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync(require('node:path').join(__dirname, '../../app/assets/js/request.js'), 'utf8');

function client(fetch) {
  const listeners = {};
  const document = { addEventListener(name, fn, capture) { (listeners[name] ||= []).push({ fn, capture: !!capture }); } };
  const window = { fetch, addEventListener() {} };
  vm.runInNewContext(source, { window, document, Headers, Response, AbortController, setTimeout, clearTimeout, queueMicrotask, Event, HTMLFormElement: {} });
  return { fetch: window.qtaFetch, listeners, window };
}
const json = (body, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json', 'X-QTA-Request-ID': 'testref' } });

test('JSON, same-origin and payload; exactly one mutation attempt', async () => {
  let calls = 0;
  const c = client(async (url, opts) => {
    calls++;
    assert.equal(opts.credentials, 'same-origin');
    assert.deepEqual(JSON.parse(opts.body), { action: 'save' });
    return json({ ok: true });
  });
  assert.equal((await c.fetch('/save', { method: 'POST', json: { action: 'save' } })).ok, true);
  assert.equal(calls, 1);
});
test('400, 403, 409 and 422 preserve existing confirmation/validation protocol', async () => {
  for (const status of [400, 403, 409, 422]) {
    const c = client(async () => json({ ok: false, error: 'Gabim domeni', confirm: { title: 'Konfirmo' } }, status));
    const res = await c.fetch.response('/save', { headers: { Accept: 'application/json' } });
    assert.equal(res.status, status);
    assert.equal((await res.json()).confirm.title, 'Konfirmo');
    await assert.rejects(c.fetch('/save'), e => e.status === status && e.requestId === 'testref');
  }
});
test('401 and 500 give standardized errors with correlation ID', async () => {
  for (const status of [401, 500]) {
    const c = client(async () => json({ ok: false, error: 'Gabim serveri' }, status));
    await assert.rejects(c.fetch.response('/save', { headers: { Accept: 'application/json' } }), e => e.status === status && e.requestId === 'testref');
  }
});
test('invalid JSON, network failure and external cancellation', async () => {
  await assert.rejects(client(async () => new Response('<html>login</html>')).fetch('/save'), e => e.code === 'invalid_json');
  await assert.rejects(client(async () => { throw new TypeError('offline'); }).fetch('/save'), e => e.code === 'network');
  const ac = new AbortController();
  const c = client((url, opts) => new Promise((resolve, reject) => opts.signal.addEventListener('abort', () => reject(new Error('aborted')))));
  const pending = c.fetch('/save', { signal: ac.signal });
  ac.abort();
  await assert.rejects(pending, e => e.code === 'aborted' && e.name === 'AbortError');
  const alreadyAborted = new AbortController(); alreadyAborted.abort();
  await assert.rejects(client(() => new Promise(() => {})).fetch('/save', { signal: alreadyAborted.signal }), e => e.code === 'aborted');
});
test('deadline covers stalled headers and stalled body; finally resets UI; no retry', async () => {
  for (const bodyStall of [false, true]) {
    let calls = 0, busy = false;
    const c = client(async () => {
      calls++;
      if (!bodyStall) return new Promise(() => {});
      return { headers: new Headers(), text: () => new Promise(() => {}) };
    });
    busy = true;
    await assert.rejects(c.fetch('/save', { method: 'POST', timeout: 15 }).finally(() => { busy = false; }), e => e.code === 'timeout' && /Kontrollo nëse/.test(e.message));
    assert.equal(busy, false);
    assert.equal(calls, 1);
  }
});
test('live HTML response retains redirect URL and body', async () => {
  const c = client(async () => ({ status: 200, statusText: 'OK', ok: true, url: 'https://test/students.php?q=a', redirected: false, headers: new Headers(), text: async () => '<main>Lista</main>' }));
  const res = await c.fetch.response('/students', { headers: { Accept: 'text/html' } });
  assert.equal(res.url, 'https://test/students.php?q=a');
  assert.equal(await res.text(), '<main>Lista</main>');
  const empty = await client(async () => new Response(null, { status: 204 })).fetch.response('/empty');
  assert.equal(empty.status, 204);
  assert.equal(await empty.text(), '');
});
test('native duplicate submit is blocked; prevented confirmation does not start loading', async () => {
  const c = client(async () => json({ ok: true }));
  const form = { method: 'post', target: '', dataset: {}, querySelectorAll: () => [], setAttribute() {}, removeAttribute() {} };
  const dispatch = () => {
    const e = { target: form, defaultPrevented: false, stopped: false, preventDefault() { this.defaultPrevented = true; }, stopImmediatePropagation() { this.stopped = true; } };
    for (const l of c.listeners.submit.filter(x => x.capture)) { l.fn(e); if (e.stopped) return e; }
    for (const l of c.listeners.submit.filter(x => !x.capture)) l.fn(e);
    return e;
  };
  const first = dispatch();
  first.preventDefault(); // target's validation/confirmation cancels native submission
  await Promise.resolve();
  const second = dispatch();
  assert.equal(second.defaultPrevented, false);
  assert.equal(dispatch().defaultPrevented, true);
  // No actual browser navigation in this test; avoid the native 20s watchdog.
  second.preventDefault();
});
