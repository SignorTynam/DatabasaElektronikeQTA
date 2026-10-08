// Run with playwright-cli on an isolated fixed_range test group, signed in as test staff.
// The check changes that group's course and one day decision. Never run on working data.
async (page) => {
  if (new URL(page.url()).hostname !== '127.0.0.1') throw new Error('Local test server required');
  const cfg = JSON.parse(await page.locator('#lgConfig').textContent());
  if (!cfg.edit || cfg.mode !== 'fixed_range') throw new Error('Open an editable fixed_range test group');
  const errors = [], failures = [], checks = [];
  page.on('pageerror', e => errors.push(e.message));
  page.on('response', r => { if (r.status() >= 400 && r.status() !== 409) failures.push({ status: r.status(), url: r.url() }); });
  const dialog = page.locator('#lgFixedSettings');
  if (!await dialog.isVisible()) await page.getByRole('button', { name: 'Ndrysho kursin dhe periudhën' }).click();
  await page.waitForFunction(() => document.activeElement.id === 'lgfsCourse');
  const course = dialog.getByRole('combobox', { name: 'Kursi' });
  await course.waitFor({ state: 'visible' });
  const days = (Date.parse(cfg.end) - Date.parse(cfg.start)) / 86400000 + 1;
  const options = await course.locator('option').evaluateAll(els => els.map(e => ({ value: e.value, label: e.textContent, disabled: e.disabled })));
  const target = options.find(o => !o.disabled && Number(o.value) !== cfg.course && Number((o.label.match(/·\s*(\d+)\s*orë/) || [])[1]) <= days * 8);
  if (!target) throw new Error('Create another ready course that fits this test period: ' + JSON.stringify({ days, course: cfg.course, options: options.slice(0, 3) }));
  const previewResponse = page.waitForResponse(r => r.url().endsWith('/lesson_group_update.php') && r.request().postDataJSON().dry_run);
  await course.selectOption(target.value);
  await previewResponse;
  await dialog.locator('[data-lg-impact].is-warning').waitFor();
  const preview = await dialog.locator('[data-lg-impact-text]').textContent();
  if (!preview.includes('Kursi bëhet') || !preview.includes('Periudha mbetet')) throw new Error(preview);
  await page.setViewportSize({ width: 1440, height: 1000 });
  await page.screenshot({ path: 'output/playwright/course-change-preview-light.png' });
  await dialog.getByRole('button', { name: 'Ruaj dhe rindërto' }).click();
  let confirm = page.getByRole('dialog').filter({ has: page.getByRole('heading', { name: 'Ndryshon kursin e grupit' }) });
  await confirm.getByRole('button', { name: 'Anulo', exact: true }).click();
  if (!await dialog.isVisible()) throw new Error('Cancel did not restore settings');
  await dialog.getByRole('button', { name: 'Ruaj dhe rindërto' }).click();
  confirm = page.getByRole('dialog').filter({ has: page.getByRole('heading', { name: 'Ndryshon kursin e grupit' }) });
  await Promise.all([page.waitForEvent('load'), confirm.getByRole('button', { name: 'Po, ndrysho kursin dhe orarin' }).click()]);
  const saved = JSON.parse(await page.locator('#lgConfig').textContent());
  if (saved.course !== Number(target.value) || saved.start !== cfg.start || saved.end !== cfg.end) throw new Error('Course or period was saved incorrectly');
  checks.push('preview, cancel, confirmation, saved course and preserved dates');
  // Digit keys edit the existing day-plan grid after the course replacement.
  const planCfg = JSON.parse(await page.locator('#lgConfig').textContent());
  const onDate = Object.keys(planCfg.rules).find(d => planCfg.rules[d].hours > 1);
  const offDate = Object.keys(planCfg.rules).find(d => planCfg.rules[d].hours === 0);
  if (!onDate || !offDate) throw new Error('Test fixture needs a teaching day and a day off');
  await page.locator('.dplan-day[data-date="' + onDate + '"]').focus();
  await page.keyboard.press(String(planCfg.rules[onDate].hours - 1));
  await page.locator('.dplan-day[data-date="' + offDate + '"]').focus();
  await page.keyboard.press('1');
  const save = page.locator('[data-fx-save]');
  await page.locator('[data-fx-save]:not([disabled])').waitFor();
  await Promise.all([page.waitForEvent('load'), save.click()]);
  const corrected = JSON.parse(await page.locator('#lgConfig').textContent());
  if (corrected.rules[offDate].hours !== 1) throw new Error('Day correction was not saved');
  checks.push('day-plan keyboard correction saved after course replacement');
  await page.getByRole('button', { name: 'Ndrysho kursin dhe periudhën' }).click();
  await page.waitForFunction(() => document.activeElement.id === 'lgfsCourse');
  await course.focus();
  await page.keyboard.press('Escape');
  await dialog.waitFor({ state: 'hidden' });
  await page.waitForFunction(() => document.activeElement.matches('[data-lg-fixed-settings]'));
  checks.push('keyboard focus and Escape restore');
  await page.emulateMedia({ reducedMotion: 'reduce' });
  for (const theme of ['light', 'dark']) {
    await page.evaluate(t => { localStorage.setItem('qta_theme', t); document.documentElement.dataset.theme = t; }, theme);
    for (const width of [320, 375, 768, 1024, 1440]) {
      await page.setViewportSize({ width, height: 1000 });
      await page.getByRole('button', { name: 'Ndrysho kursin dhe periudhën' }).click();
      await page.waitForFunction(() => document.activeElement.id === 'lgfsCourse');
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1);
      if (overflow) throw new Error('Page overflow at ' + theme + ' ' + width);
      if (!await dialog.getByRole('button', { name: 'Ruaj dhe rindërto' }).isVisible()) throw new Error('Save inaccessible');
      if ([375, 1440].includes(width)) await page.screenshot({ path: 'output/playwright/course-change-' + theme + '-' + width + '.png' });
      await dialog.getByRole('button', { name: 'Anulo', exact: true }).click();
      await dialog.waitFor({ state: 'hidden' });
      checks.push(theme + ' ' + width);
    }
  }
  await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
  await page.setViewportSize({ width: 375, height: 1000 });
  await page.getByRole('button', { name: 'Ndrysho kursin dhe periudhën' }).click();
  await page.waitForFunction(() => document.activeElement.id === 'lgfsCourse');
  await dialog.getByRole('button', { name: 'Ruaj dhe rindërto' }).scrollIntoViewIfNeeded();
  await page.screenshot({ path: 'output/playwright/course-change-zoom.png' });
  await dialog.getByRole('button', { name: 'Anulo', exact: true }).click();
  checks.push('200% text zoom, reduced motion');
  if (errors.length || failures.length) throw new Error(JSON.stringify({ errors, failures }));
  await page.evaluate(result => { window.courseChangeResult = result; }, { checks, errors, failures });
}
