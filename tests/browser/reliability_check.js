// Run with playwright-cli run-code --filename tests/browser/reliability_check.js
// Requires the isolated browser_router test server and a signed-in synthetic admin.
async (page) => {
  const errors = [], failures = [], shots = [];
  page.on('pageerror', e => errors.push(e.message));
  page.on('requestfailed', r => { if (!r.failure()?.errorText.includes('ERR_ABORTED')) failures.push(r.url()); });
  await page.goto('http://127.0.0.1:8765/index.php?hyr=staff');
  await page.locator('#loginId').fill('administrator@test.invalid');
  await page.locator('#loginPassword').fill('Qta-Test-2026!');
  await Promise.all([page.waitForURL('**/dashboard_admin.php'), page.locator('.login-form button[type="submit"]').click()]);
  await page.evaluate(() => sessionStorage.removeItem('qtaReopenModal'));
  await page.goto('http://127.0.0.1:8765/groups.php?edit=1');
  const range = await page.evaluate(() => { const start = performance.now(); const nums = parseAmzeRangesClient('1-999999999'); return { count: nums.length, ms: performance.now() - start }; });
  if (range.count !== 0 || range.ms > 100) throw new Error('Unbounded client AMZE parser');
  if (!(await page.getByRole('button', { name: 'Ndrysho kursant\u00ebt', exact: true }).isVisible())) await page.getByRole('button', { name: /^Reliability fixture Grupi/ }).first().click();
  await page.getByRole('button', { name: 'Ndrysho kursant\u00ebt', exact: true }).click();
  // The template can contain one form per group; select the visible dialog.
  const visibleForm = page.locator('.modal.show form[data-group-form]').filter({ has: page.locator('textarea') });
  await visibleForm.waitFor({ state: 'visible' });
  const group = await visibleForm.locator('input[name="group_id"]').inputValue();
  const textarea = visibleForm.locator('textarea');
  await textarea.fill('7977-7981');
  let mutations = 0;
  page.on('request', r => { if (r.method() === 'POST' && r.url().includes('/groups.php')) mutations++; });
  await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded' }), visibleForm.locator('button[type="submit"]').evaluate(btn => { btn.click(); btn.click(); })]);
  await page.getByRole('button', { name: new RegExp('Reliability fixture Grupi #' + group + '$') }).waitFor();
  if (mutations !== 1) throw new Error('Expected exactly one native mutation; got ' + mutations);
  if (await page.locator('.is-loading[aria-busy="true"]').count()) throw new Error('Native submit left busy controls');
  await page.getByRole('button', { name: 'Ndrysho kursant\u00ebt', exact: true }).waitFor({ state: 'visible' });
  for (const theme of ['light', 'dark']) {
    await page.evaluate(theme => { localStorage.setItem('qta_theme', theme); document.documentElement.dataset.theme = theme; document.documentElement.dataset.bsTheme = theme; }, theme);
    for (const width of [320, 375, 768, 1024, 1440]) {
      await page.setViewportSize({ width, height: 1000 });
      const path = 'output/playwright/groups-' + theme + '-' + width + '.png';
      await page.screenshot({ path, fullPage: false }); shots.push(path);
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1);
      if (overflow) throw new Error('Page overflow: ' + theme + ' ' + width);
    }
  }
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await page.keyboard.press('Tab');
  const focus = await page.evaluate(() => ({ tag: document.activeElement.tagName, outline: getComputedStyle(document.activeElement).outlineStyle }));
  if (focus.tag === 'BODY') throw new Error('Keyboard focus lost');
  const transport = await page.evaluate(async () => {
    const csrf = document.querySelector('input[name="csrf"]').value;
    const results = [];
    for (const payload of [{ action: 'save', group_id: 1, csrf: 'expired' }, { action: 'save', group_id: 1, csrf }]) {
      try { await qtaFetch('/group_results.php', { method: 'POST', json: payload }); results.push('unexpected success'); }
      catch (e) { results.push({ code: e.code, status: e.status }); }
    }
    return results;
  });
  await page.evaluate(result => { window.reliabilityResult = result; }, { mutations: 'one per double click', screenshots: shots.length, focus, transport, errors, failures });
  if (errors.length || failures.length) throw new Error('Console/network failures: ' + JSON.stringify({ errors, failures }));
}
