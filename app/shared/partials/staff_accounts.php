<?php
declare(strict_types=1);

/**
 * staff_accounts.php — Faqja e llogarive të stafit (administratorë ose editorë).
 *
 * E njëjta ndërfaqe për users.php dhe editors.php, që sjellja të jetë identike.
 * Faqja prind përgatit të dhënat dhe veprimet POST; kjo pjesë vetëm i shfaq.
 *
 * Pritet:
 *   $SA = [
 *     'page'     => 'users.php',           faqja që trajton POST-in
 *     'title'    => 'Administratorët',
 *     'lead'     => '…',
 *     'one'      => 'administrator',       emri i një llogarie
 *     'many'     => 'administratorë',
 *     'create'   => 'create_admin',        veprimi POST për krijim
 *     'endpoint' => 'user_inline.php',     ruajtja e emrit/email-it
 *     'nav'      => 'users_admins',
 *     'help'     => 'users',
 *     'can'      => ['…', '…'],            çfarë mund të bëjë ky rol
 *   ];
 *   $users, $total, $totalPages, $page, $q, $CSRF, $EDIT_MODE, $currentUser
 *   flash() — funksioni i faqes për mesazhet pas ringarkimit
 */

$NAV_ACTIVE = $SA['nav'];
$HELP_TOPIC = $SA['help'];
require __DIR__ . '/../../pages/inc/navbar.php';

$openAdd  = $EDIT_MODE && isset($_GET['add']);
$addHref  = $SA['page'] . '?' . http_build_query(['edit' => '1', 'add' => '1']);
$flashOk  = flash('ok');
$flashErr = flash('err');
$meId     = (int)($currentUser['id'] ?? 0);

$pageTitle = $SA['title'];
require __DIR__ . '/../app_head.php';
?>

<main class="app-main" id="main" tabindex="-1">

  <header class="page-head">
    <div class="page-head-main">
      <h1 class="page-title"><?= h($SA['title']) ?></h1>
      <p class="page-lead"><?= h($SA['lead']) ?></p>
    </div>
    <div class="page-actions">
      <?php require __DIR__ . '/edit_lock.php'; ?>
      <?= qta_help_button() ?>
      <?php if ($EDIT_MODE): ?>
        <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#addStaffModal">
          <i class="bi bi-person-plus" aria-hidden="true"></i>Shto <?= h($SA['one']) ?>
        </button>
      <?php else: ?>
        <a class="btn btn-primary" href="<?= h($addHref) ?>"><i class="bi bi-person-plus" aria-hidden="true"></i>Shto <?= h($SA['one']) ?></a>
      <?php endif; ?>
    </div>
  </header>

  <?php if (!empty($SA['can'])): ?>
    <div class="notice is-sunken mb-4">
      <i class="bi bi-info-circle" aria-hidden="true"></i>
      <span><b>Çfarë mund të bëjë një <?= h($SA['one']) ?>:</b> <?= h(implode(' · ', $SA['can'])) ?></span>
    </div>
  <?php endif; ?>

  <form class="filters filters-compact" method="get" action="<?= h($SA['page']) ?>" role="search" aria-label="Kërko <?= h($SA['many']) ?>">
    <div class="filter-field is-grow">
      <label class="visually-hidden" for="saQ">Kërko sipas emrit ose email-it</label>
      <div class="search-field">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input class="form-control" id="saQ" type="search" name="q" value="<?= h($q) ?>" placeholder="Kërko sipas emrit ose email-it">
      </div>
    </div>
    <div class="filter-actions">
      <?php if ($q !== ''): ?><a class="btn btn-ghost" href="<?= h($SA['page']) ?>">Pastro</a><?php endif; ?>
      <button class="btn btn-secondary" type="submit">Kërko</button>
    </div>
  </form>

  <?php require __DIR__ . '/edit_mode_off_banner.php'; ?>

  <section class="section" aria-labelledby="saTitle">
    <div class="section-head">
      <h2 class="section-title" id="saTitle">
        <?= $q !== '' ? 'Llogaritë që përputhen' : 'Të gjitha llogaritë' ?>
        <span class="count"><?= number_format($total, 0, ',', '.') ?></span>
      </h2>
      <?php if ($EDIT_MODE && $users): ?>
        <span class="section-meta">Kliko emrin ose email-in për ta ndryshuar.</span>
      <?php endif; ?>
    </div>

    <?php if ($users): ?>
      <div class="table-responsive">
        <table class="table" id="staffTable" data-sortable>
          <thead>
            <tr>
              <th scope="col" class="col-wide" data-sort="text">Emri</th>
              <th scope="col" class="nowrap" data-sort="text">Email-i (për hyrje)</th>
              <th scope="col" class="nowrap" data-sort="date">Që nga</th>
              <th scope="col" class="col-actions" data-sort="none"><span class="visually-hidden">Veprime</span></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($users as $u):
              $uid  = (int)$u['id'];
              $isMe = ($uid === $meId);
              $name = (string)($u['full_name'] ?: ($u['email'] ?: ucfirst($SA['one'])));
              $since = substr((string)$u['created_at'], 0, 10); ?>
              <tr>
                <td class="cell col-wide" data-id="<?= $uid ?>" data-field="full_name">
                  <div class="d-flex align-items-center gap-3">
                    <span class="avatar" aria-hidden="true"><?= h(qta_initials($name)) ?></span>
                    <span class="min-w-0">
                      <span class="editable person-name" contenteditable="<?= $EDIT_MODE ? 'true' : 'false' ?>" <?= $EDIT_MODE ? 'role="textbox" aria-label="Emri"' : '' ?>><?= h((string)($u['full_name'] ?? '')) ?></span>
                      <?php if ($isMe): ?><span class="status status-accent ms-1">Ti</span><?php endif; ?>
                    </span>
                  </div>
                </td>
                <td class="cell nowrap" data-id="<?= $uid ?>" data-field="email">
                  <span class="editable" contenteditable="<?= $EDIT_MODE ? 'true' : 'false' ?>" inputmode="email" <?= $EDIT_MODE ? 'role="textbox" aria-label="Email-i"' : '' ?>><?= h((string)($u['email'] ?? '')) ?></span>
                </td>
                <td class="nowrap" data-sort-value="<?= h($since) ?>"><?= h(qta_date($since)) ?></td>
                <td class="col-actions">
                  <?php if ($EDIT_MODE): ?>
                    <div class="row-actions">
                      <button class="btn btn-secondary btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#resetPassModal"
                              data-user-id="<?= $uid ?>" data-user-name="<?= h($name) ?>">
                        <i class="bi bi-key" aria-hidden="true"></i>Fjalëkalim i ri
                      </button>
                      <?php if (!$isMe): ?>
                        <form method="post" action="<?= h($SA['page']) ?>" class="d-inline"
                              data-confirm="<?= h($name . ' nuk do të mund të hyjë më në portal. Të dhënat që ka futur mbeten. Kjo nuk mund të kthehet mbrapsht.') ?>"
                              data-confirm-title="Të fshihet llogaria?" data-confirm-ok="Po, fshije llogarinë">
                          <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
                          <input type="hidden" name="action" value="delete_user">
                          <input type="hidden" name="user_id" value="<?= $uid ?>">
                          <button class="btn btn-ghost btn-sm btn-icon" type="submit" aria-label="Fshi llogarinë e <?= h($name) ?>" title="Fshi llogarinë">
                            <i class="bi bi-trash" aria-hidden="true"></i>
                          </button>
                        </form>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($totalPages > 1):
        $pBase = $SA['page'] . '?' . http_build_query(array_filter(['q' => $q !== '' ? $q : null]));
        $pLink = static fn(int $p) => $pBase . (str_ends_with($pBase, '?') ? '' : '&') . 'page=' . $p; ?>
        <nav class="pager mt-3" aria-label="Faqet e listës">
          <a class="btn btn-secondary btn-sm<?= $page <= 1 ? ' disabled' : '' ?>" href="<?= h($pLink(max(1, $page - 1))) ?>" <?= $page <= 1 ? 'aria-disabled="true" tabindex="-1"' : '' ?>><i class="bi bi-chevron-left" aria-hidden="true"></i>Më parë</a>
          <span class="text-muted small">Faqja <?= (int)$page ?> nga <?= (int)$totalPages ?></span>
          <a class="btn btn-secondary btn-sm<?= $page >= $totalPages ? ' disabled' : '' ?>" href="<?= h($pLink(min($totalPages, $page + 1))) ?>" <?= $page >= $totalPages ? 'aria-disabled="true" tabindex="-1"' : '' ?>>Më pas<i class="bi bi-chevron-right" aria-hidden="true"></i></a>
        </nav>
      <?php endif; ?>

    <?php else: ?>
      <?= $q !== ''
        ? qta_empty('Asnjë llogari nuk përputhet', 'Provo një pjesë tjetër të emrit ose email-it.', 'bi-search', '<a class="btn btn-secondary" href="' . h($SA['page']) . '">Pastro kërkimin</a>')
        : qta_empty('Ende pa ' . $SA['many'], 'Shto llogarinë e parë për një koleg.', 'bi-person-plus', '<a class="btn btn-primary" href="' . h($addHref) . '">Shto ' . h($SA['one']) . '</a>') ?>
    <?php endif; ?>
  </section>
</main>

<!-- Dialog: shto llogari -->
<div class="modal fade" id="addStaffModal" tabindex="-1" aria-labelledby="addStaffTitle" aria-hidden="true"<?= $openAdd ? ' data-open-on-load="add"' : '' ?>>
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post" action="<?= h($SA['page']) ?>" data-loading data-password-pair>
      <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
      <input type="hidden" name="action" value="<?= h($SA['create']) ?>">
      <div class="modal-header">
        <h2 class="modal-title" id="addStaffTitle"><i class="bi bi-person-plus" aria-hidden="true"></i>Shto një <?= h($SA['one']) ?></h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label" for="asName">Emri dhe mbiemri <span class="req" aria-hidden="true">*</span></label>
            <input id="asName" type="text" name="full_name" class="form-control" autocomplete="off" placeholder="p.sh. Arta Hoxha" required <?= $EDIT_MODE ? '' : 'disabled' ?>>
          </div>
          <div class="col-12">
            <label class="form-label" for="asEmail">Email-i <span class="req" aria-hidden="true">*</span></label>
            <input id="asEmail" type="email" name="email" class="form-control" autocomplete="off" placeholder="p.sh. arta.hoxha@qta.al" aria-describedby="asEmailHelp" required <?= $EDIT_MODE ? '' : 'disabled' ?>>
            <div class="form-text" id="asEmailHelp">Me këtë email hyn në portal. Duhet të jetë i veçantë.</div>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="asPass">Fjalëkalimi <span class="req" aria-hidden="true">*</span></label>
            <div class="password-field">
              <input id="asPass" type="password" name="password" class="form-control" minlength="8" autocomplete="new-password" aria-describedby="asPassHelp" required data-pw-main <?= $EDIT_MODE ? '' : 'disabled' ?>>
              <button class="btn btn-ghost btn-icon password-toggle" type="button" data-password-toggle="#asPass" aria-label="Shfaq fjalëkalimin" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
            </div>
            <div class="form-text" id="asPassHelp">Të paktën 8 shenja.</div>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="asPass2">Shkruaje sërish <span class="req" aria-hidden="true">*</span></label>
            <div class="password-field">
              <input id="asPass2" type="password" name="password2" class="form-control" minlength="8" autocomplete="new-password" required data-pw-repeat <?= $EDIT_MODE ? '' : 'disabled' ?>>
              <button class="btn btn-ghost btn-icon password-toggle" type="button" data-password-toggle="#asPass2" aria-label="Shfaq fjalëkalimin" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
            </div>
            <div class="invalid-feedback">Dy fjalëkalimet nuk janë njësoj.</div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anulo</button>
        <button class="btn btn-primary" type="submit" <?= $EDIT_MODE ? '' : 'disabled' ?>><i class="bi bi-check-lg" aria-hidden="true"></i>Krijo llogarinë</button>
      </div>
    </form>
  </div>
</div>

<!-- Dialog: fjalëkalim i ri -->
<div class="modal fade" id="resetPassModal" tabindex="-1" aria-labelledby="resetPassTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" method="post" action="<?= h($SA['page']) ?>" data-loading data-password-pair>
      <input type="hidden" name="csrf" value="<?= h($CSRF) ?>">
      <input type="hidden" name="action" value="reset_password">
      <input type="hidden" name="user_id" id="resetUserId" value="">
      <div class="modal-header">
        <div>
          <span class="eyebrow mb-0" id="resetUserName"><?= h(ucfirst($SA['one'])) ?></span>
          <h2 class="modal-title" id="resetPassTitle"><i class="bi bi-key" aria-hidden="true"></i>Vendos një fjalëkalim të ri</h2>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Mbyll"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small">Përdore kur dikush e ka harruar fjalëkalimin. Fjalëkalimi i vjetër nuk vlen më sapo ruan këtë.</p>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label" for="rpPass">Fjalëkalimi i ri</label>
            <div class="password-field">
              <input id="rpPass" type="password" name="new_password" class="form-control" minlength="8" autocomplete="new-password" required data-pw-main <?= $EDIT_MODE ? '' : 'disabled' ?>>
              <button class="btn btn-ghost btn-icon password-toggle" type="button" data-password-toggle="#rpPass" aria-label="Shfaq fjalëkalimin" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
            </div>
            <div class="form-text">Të paktën 8 shenja.</div>
          </div>
          <div class="col-md-6">
            <label class="form-label" for="rpPass2">Shkruaje sërish</label>
            <div class="password-field">
              <input id="rpPass2" type="password" name="new_password2" class="form-control" minlength="8" autocomplete="new-password" required data-pw-repeat <?= $EDIT_MODE ? '' : 'disabled' ?>>
              <button class="btn btn-ghost btn-icon password-toggle" type="button" data-password-toggle="#rpPass2" aria-label="Shfaq fjalëkalimin" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button>
            </div>
            <div class="invalid-feedback">Dy fjalëkalimet nuk janë njësoj.</div>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Anulo</button>
        <button class="btn btn-primary" type="submit" <?= $EDIT_MODE ? '' : 'disabled' ?>>Ruaj fjalëkalimin</button>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/../app_scripts.php'; ?>
<script>
(function(){
  const CSRF = <?= json_encode($CSRF) ?>;
  const ENDPOINT = <?= json_encode($SA['endpoint']) ?>;
  const EDIT_ENABLED = <?= $EDIT_MODE ? 'true' : 'false' ?>;
  const notify = (type, text, opts={}) => window.qtaToast ? window.qtaToast(text, type, opts.title, opts) : null;
  const clean = s => { const v = (s||'').replace(/\s+/g,' ').trim(); return v === '—' ? '' : v; };

  /* Emri dhe email-i ndryshohen në vend */
  if (EDIT_ENABLED) {
    document.querySelectorAll('#staffTable td.cell .editable[contenteditable="true"]').forEach(el => {
      el.dataset.prev = clean(el.textContent);
      el.addEventListener('focus', () => { el.dataset.prev = clean(el.textContent); });
      el.addEventListener('keydown', ev => {
        if (ev.key === 'Enter') { ev.preventDefault(); el.blur(); }
        if (ev.key === 'Escape') { ev.preventDefault(); el.textContent = el.dataset.prev || ''; el.blur(); }
      });
      el.addEventListener('paste', ev => {
        ev.preventDefault();
        const text = (ev.clipboardData || window.clipboardData).getData('text/plain') || '';
        document.execCommand('insertText', false, clean(text));
      });
      el.addEventListener('blur', async () => {
        const cell = el.closest('td.cell');
        const field = cell.dataset.field;
        const val = clean(el.textContent);
        el.textContent = val;
        if (val === (el.dataset.prev || '')) return;
        let problem = '';
        if (field === 'email' && val === '') problem = 'Email-i nuk mund të mbetet bosh — me të hyhet në portal.';
        else if (field === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) problem = 'Email-i nuk duket i saktë. Kontrolloje, p.sh. emri@qta.al.';
        else if (field === 'full_name' && val === '') problem = 'Emri nuk mund të mbetet bosh.';
        if (problem) { el.textContent = el.dataset.prev || ''; notify('warning', problem); return; }
        cell.classList.add('cell-saving');
        try {
          const res = await fetch(ENDPOINT, {
            method: 'POST',
            headers: {'Content-Type':'application/json','Accept':'application/json'},
            body: JSON.stringify({csrf: CSRF, user_id: parseInt(cell.dataset.id, 10), field, value: val})
          });
          const json = await res.json().catch(() => null);
          if (!json || !json.ok) throw new Error((json && json.error) || 'Ndryshimi nuk u ruajt. Provo sërish.');
          el.textContent = clean(json.display ?? val);
          el.dataset.prev = el.textContent;
          cell.classList.remove('cell-saving');
          cell.classList.add('cell-ok'); setTimeout(() => cell.classList.remove('cell-ok'), 800);
          notify('success', 'Ndryshimi u ruajt.');
        } catch (e) {
          el.textContent = el.dataset.prev || '';
          cell.classList.remove('cell-saving');
          cell.classList.add('cell-err'); setTimeout(() => cell.classList.remove('cell-err'), 1200);
          notify('danger', e.message);
        }
      });
    });
  }

  /* Dy fjalëkalimet duhet të jenë njësoj */
  document.querySelectorAll('form[data-password-pair]').forEach(form => {
    const p1 = form.querySelector('[data-pw-main]'), p2 = form.querySelector('[data-pw-repeat]');
    if (!p1 || !p2) return;
    const check = () => {
      const bad = p2.value !== '' && p1.value !== p2.value;
      p2.classList.toggle('is-invalid', bad);
      p2.setCustomValidity(bad ? 'Dy fjalëkalimet nuk janë njësoj.' : '');
    };
    p1.addEventListener('input', check);
    p2.addEventListener('input', check);
  });

  /* Dialogu i fjalëkalimit të ri */
  document.getElementById('resetPassModal')?.addEventListener('show.bs.modal', ev => {
    const btn = ev.relatedTarget;
    if (!btn) { ev.preventDefault(); return; }
    document.getElementById('resetUserId').value = btn.getAttribute('data-user-id');
    document.getElementById('resetUserName').textContent = btn.getAttribute('data-user-name') || '';
    ev.target.querySelectorAll('input[type="password"]').forEach(i => { i.value = ''; i.classList.remove('is-invalid'); i.setCustomValidity(''); });
  });

  document.addEventListener('DOMContentLoaded', () => {
<?php if ($flashOk): ?>
    notify('success', <?= json_encode($flashOk, JSON_UNESCAPED_UNICODE) ?>, {delay: 7000});
<?php endif; ?>
<?php if ($flashErr): ?>
    notify('danger', <?= json_encode($flashErr, JSON_UNESCAPED_UNICODE) ?>, {autohide: false});
<?php endif; ?>
  });
})();
</script>
</body>
</html>
