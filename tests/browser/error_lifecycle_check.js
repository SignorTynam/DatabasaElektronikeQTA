// Run after reliability_check.js in the same isolated, signed-in browser session.
async (page) => {
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  await page.goto('http://127.0.0.1:8765/courses.php?edit=1');
  // Shorten only this test's configurable deadline; use the actual transport/UI.
  await page.evaluate(() => {
    const response = window.qtaFetch.response;
    window.qtaFetch.response = (url, opts) => response(url, Object.assign({}, opts, { timeout: 150 }));
  });
  const results = [];
  for (const kind of [401, 403, 409, 500, 'invalid_json', 'network', 'timeout']) {
    const route = async r => {
      if (kind === 'timeout') return; // Simulate stalled response headers.
      await new Promise(resolve => setTimeout(resolve, 35));
      if (kind === 'network') { await r.abort('failed'); return; }
      await r.fulfill({ status: typeof kind === 'number' ? kind : 200,
        contentType: 'application/json', headers: { 'X-QTA-Request-ID': 'browser-test' },
        body: kind === 'invalid_json' ? '<html>invalid</html>' : JSON.stringify({ ok: false, error: 'Gabim prove ' + kind }) });
    };
    await page.route('**/courses_inline_update.php', route);
    const editable = page.locator('#coursesTable td[data-field="name"] .editable').first();
    const previous = await editable.innerText();
    await editable.fill('Test ' + kind);
    await editable.press('Enter');
    await page.waitForFunction(() => !document.querySelector('.cell-saving, .cell[aria-busy="true"]'));
    if (await editable.innerText() !== previous) throw new Error('Value was not restored: ' + kind);
    results.push({ kind, reset: true });
    await page.unroute('**/courses_inline_update.php', route);
  }
  // A genuine 409 confirmation must remain visible, allow cancel, and reset busy.
  await page.goto('http://127.0.0.1:8765/lesson_groups.php?edit=1');
  const link = page.locator('a[href^="lesson_group.php?id="]').first();
  const href = await link.getAttribute('href');
  await page.goto('http://127.0.0.1:8765/' + href);
  const cfg = await page.locator('#lgConfig').textContent();
  const group = JSON.parse(cfg);
  if (!group) throw new Error('No scheduled fixture');
  // The action uses the existing server-confirmation protocol; no DB mutation occurs.
  await page.route('**/lesson_group_update.php', async r => r.fulfill({ status: 409, contentType: 'application/json',
    body: JSON.stringify({ ok: false, confirm: { title: 'Konfirmim prove', message: 'Anulo ndryshimin e provës.', confirm: 'Konfirmo' } }) }));
  await page.locator('[data-bs-target="#kursantet"]').click();
  await page.locator('[data-bs-target="#lgMembers"]').click();
  await page.locator('#lgMembers form button[type="submit"]').click();
  await page.getByRole('heading', { name: 'Konfirmim prove', exact: true }).waitFor();
  await page.keyboard.press('Escape');
  await page.waitForFunction(() => !document.querySelector('.is-loading[aria-busy="true"]'));
  results.push({ kind: '409 confirmation cancelled', reset: true });
  await page.unroute('**/lesson_group_update.php');
  // A duplicate inline save must not clear the first request's busy state.
  await page.goto('http://127.0.0.1:8765/groups.php?edit=1');
  await page.getByRole('button', { name: /^Reliability fixture Grupi/ }).first().click();
  let inlineRequests = 0;
  await page.route('**/groups_inline_update.php', async r => {
    inlineRequests++;
    await new Promise(resolve => setTimeout(resolve, 200));
    await r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, display: '06.01.2095' }) });
  });
  const duplicate = await page.evaluate(async () => {
    const ed = document.querySelector('.modal.show .cell[data-field="exam_date"] .editable');
    ed.dataset.prev = ''; ed.textContent = '06.01.2095';
    const cell = ed.closest('.cell');
    const first = saveEditable(ed);
    await new Promise(resolve => setTimeout(resolve, 20));
    await saveEditable(ed);
    const busyDuringFirst = cell.classList.contains('cell-saving') && cell.getAttribute('aria-busy') === 'true';
    await first;
    return { busyDuringFirst, reset: !cell.classList.contains('cell-saving') && !cell.hasAttribute('aria-busy') };
  });
  await page.unroute('**/groups_inline_update.php');
  if (inlineRequests !== 1 || !duplicate.busyDuringFirst || !duplicate.reset) throw new Error('Duplicate inline lifecycle: ' + JSON.stringify({ inlineRequests, duplicate }));
  results.push({ kind: 'duplicate inline save', requests: inlineRequests, ...duplicate });
  await page.evaluate(result => { window.errorLifecycleResult = result; }, { results, errors });
  if (errors.length) throw new Error(JSON.stringify(errors));
}
