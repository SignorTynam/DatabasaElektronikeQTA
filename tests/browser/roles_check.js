// Isolated test identities from seed_reliability.php; never use production accounts.
async (page) => {
  const results = [];
  const roles = [
    { role: 'staff', user: 'administrator@test.invalid', dashboard: 'dashboard_admin.php', pages: ['students.php', 'groups.php', 'lesson_groups.php', 'calendar.php', 'logs.php', 'profile.php'] },
    { role: 'staff', user: 'editor@test.invalid', dashboard: 'dashboard_editor.php', pages: ['students.php', 'lesson_groups.php', 'logs_editor.php', 'profile.php'] },
    { role: 'agjencia', user: 'T12345678A', dashboard: 'dashboard_agjencia.php', pages: ['groups_agjencia.php', 'register_agjencia.php', 'profile.php'] },
    { role: 'student', user: 'T26010100A', dashboard: 'dashboard_student.php', pages: ['groups_student.php', 'profile.php'] },
    { role: null, pages: ['index.php', 'verify.php', 'index.php?hyr=staff', 'contact.php', 'ndihme.php', 'aboutus.php'] }
  ];
  for (const identity of roles) {
    const context = await page.context().browser().newContext({ viewport: { width: 1440, height: 1000 }, reducedMotion: 'reduce' });
    const p = await context.newPage();
    const errors = [], failures = [];
    p.on('pageerror', e => errors.push(e.message));
    p.on('response', r => { if (r.status() >= 400) failures.push({ status: r.status(), url: r.url() }); });
    try {
      if (identity.role) {
        await p.goto('http://127.0.0.1:8765/index.php?hyr=' + identity.role);
        await p.locator('.login-form input[name="role"][value="' + identity.role + '"]').check();
        await p.locator('#loginId').fill(identity.user);
        await p.locator('#loginPassword').fill('Qta-Test-2026!');
        await Promise.all([p.waitForURL('**/' + identity.dashboard), p.locator('.login-form button[type="submit"]').click()]);
      }
      for (const route of identity.pages) {
        const res = await p.goto('http://127.0.0.1:8765/' + route);
        if (res.status() !== 200) throw new Error(route + ': ' + res.status());
        await p.locator('main').waitFor();
      }
      // Text zoom, narrow reflow, theme and keyboard checks on each role's final page.
      await p.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
      await p.setViewportSize({ width: 375, height: 1000 });
      await p.keyboard.press('Tab');
      const focus = await p.evaluate(() => document.activeElement.tagName);
      if (focus === 'BODY') throw new Error('Missing keyboard focus for ' + identity.user);
      await p.screenshot({ path: 'output/playwright/role-' + (identity.user || 'public').replace(/[^a-z0-9]/gi, '-') + '-zoom.png' });
      if (errors.length || failures.length) throw new Error(JSON.stringify({ identity: identity.user, errors, failures }));
      results.push({ role: identity.user || 'public', pages: identity.pages.length, errors, failures, textZoom: '200%', focus });
    } finally { await context.close(); }
  }
  await page.evaluate(result => { window.rolesResult = result; }, results);
}
