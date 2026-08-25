/* ============================================================================
   QTA · PROTOKOLL — sjellja e panelit.
   Vetëm shtresa e ndërfaqes; asnjë endpoint nuk preket.
   ========================================================================= */
(function () {
  'use strict';

  var root = document.documentElement;
  var THEME_KEY = 'qta_theme';
  var DENSITY_KEY = 'qta_density';

  /* ------------------------------------------------------------- 1. Pamja */
  function resolveTheme() {
    var stored = localStorage.getItem(THEME_KEY);
    if (stored === 'dark' || stored === 'light') return stored;
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }

  function paintThemeButtons(theme) {
    var archive = theme === 'dark';
    document.querySelectorAll('[data-theme-toggle]').forEach(function (btn) {
      btn.setAttribute('title', archive ? 'Fleta origjinale' : 'Kopja e arkivit');
      btn.setAttribute('aria-label', archive ? 'Kalo te fleta origjinale' : 'Kalo te kopja e arkivit');
      var icon = btn.querySelector('i');
      if (icon) icon.className = archive ? 'bi bi-sun' : 'bi bi-circle-half';
      var label = btn.querySelector('[data-theme-label]');
      if (label) label.textContent = archive ? 'Fleta origjinale' : 'Kopja e arkivit';
    });
  }

  function setTheme(theme) {
    root.setAttribute('data-theme', theme);
    localStorage.setItem(THEME_KEY, theme);
    paintThemeButtons(theme);
  }

  setTheme(resolveTheme());

  /* --------------------------------------------------------- 2. Densiteti */
  function setDensity(mode) {
    document.body.classList.toggle('dense', mode === 'dense');
    localStorage.setItem(DENSITY_KEY, mode);
    document.querySelectorAll('[data-density-toggle]').forEach(function (btn) {
      var dense = mode === 'dense';
      btn.setAttribute('aria-pressed', dense ? 'true' : 'false');
      btn.setAttribute('title', dense ? 'Rreshta të gjerë' : 'Rreshta të ngjeshur');
    });
  }

  setDensity(localStorage.getItem(DENSITY_KEY) === 'dense' ? 'dense' : 'normal');

  /* ------------------------------------------------------------ 3. Klikimet */
  document.addEventListener('click', function (event) {
    var themeBtn = event.target.closest('[data-theme-toggle]');
    if (themeBtn) {
      event.preventDefault();
      setTheme(root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
      return;
    }

    var densityBtn = event.target.closest('[data-density-toggle]');
    if (densityBtn) {
      event.preventDefault();
      setDensity(document.body.classList.contains('dense') ? 'normal' : 'dense');
      return;
    }

    var topBtn = event.target.closest('[data-back-top]');
    if (topBtn) {
      event.preventDefault();
      window.scrollTo({ top: 0, behavior: 'smooth' });
      return;
    }

    /* Menuja kryesore në celular */
    var navBtn = event.target.closest('[data-nav-toggle]');
    if (navBtn) {
      event.preventDefault();
      var nav = document.getElementById('appNav');
      if (nav) {
        var open = nav.classList.toggle('is-open');
        navBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
      }
      return;
    }

    /* Nënmenutë: në celular hapen me klikim, në desktop me hover/fokus. */
    var subBtn = event.target.closest('[data-sub-toggle]');
    if (subBtn && window.matchMedia('(max-width: 1099.98px)').matches) {
      event.preventDefault();
      var sub = document.getElementById(subBtn.getAttribute('aria-controls'));
      if (sub) {
        var shown = sub.style.display === 'block';
        sub.style.display = shown ? 'none' : 'block';
        subBtn.setAttribute('aria-expanded', shown ? 'false' : 'true');
      }
    }
  });

  /* -------------------------------------------------- 4. Shkurtore tastiere */
  document.addEventListener('keydown', function (event) {
    var tag = (event.target.tagName || '').toLowerCase();
    var typing = tag === 'input' || tag === 'textarea' || tag === 'select' || event.target.isContentEditable;

    if (event.key === '/' && !typing && !event.ctrlKey && !event.metaKey && !event.altKey) {
      var find = document.querySelector('[data-app-search] input, .app-find input');
      if (find) {
        event.preventDefault();
        find.focus();
        find.select();
      }
      return;
    }

    if (event.key === 'Escape') {
      var nav = document.getElementById('appNav');
      if (nav && nav.classList.contains('is-open')) {
        nav.classList.remove('is-open');
        var t = document.querySelector('[data-nav-toggle]');
        if (t) { t.setAttribute('aria-expanded', 'false'); t.focus(); }
      }
      if (event.target && typeof event.target.matches === 'function'
          && event.target.matches('[data-table-filter]')) {
        event.target.value = '';
        event.target.dispatchEvent(new Event('input', { bubbles: true }));
      }
    }
  });

  /* --------------------------------------- 5b. Filtrimi i menjëhershëm */
  /* Zëvendëson filtrin e vjetër: mban parasysh tabelat me trupa të shumtë
     (grupet), numëron sa mbeten dhe pranon filtra të shpejtë me një klik. */
  document.querySelectorAll('[data-tfilter]').forEach(function (box) {
    var input = box.querySelector('[data-table-filter]');
    var count = box.querySelector('[data-tfilter-count]');
    var clear = box.querySelector('[data-tfilter-clear]');
    var chips = box.querySelectorAll('[data-tfilter-chip]');
    if (!input) return;

    var sel = input.getAttribute('data-table-filter');
    var table = sel ? document.querySelector(sel) : document.querySelector('.app-main table');
    if (!table) return;

    var multi = table.tBodies.length > 1;
    var units = multi
      ? Array.prototype.slice.call(table.tBodies)
      : Array.prototype.slice.call(table.tBodies[0] ? table.tBodies[0].rows : []);
    var total = units.length;

    /* Teksti i çdo njësie lexohet një herë, jo në çdo shtypje. */
    var haystack = units.map(function (u) { return (u.textContent || '').toLowerCase(); });

    function apply() {
      var needle = input.value.trim().toLowerCase();
      var shown = 0;

      units.forEach(function (u, i) {
        var hit = needle === '' || haystack[i].indexOf(needle) !== -1;
        if (multi) {
          u.style.display = hit ? '' : 'none';
        } else {
          u.hidden = !hit;
        }
        if (hit) shown++;
      });

      box.classList.toggle('is-on', needle !== '');
      if (count) {
        count.textContent = needle === ''
          ? total + (total === 1 ? ' zë' : ' zëra')
          : shown + ' nga ' + total;
        count.classList.toggle('is-narrowed', needle !== '');
      }
    }

    var t = null;
    input.addEventListener('input', function () {
      clearTimeout(t);
      t = setTimeout(apply, 90);
    });

    input.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { e.stopPropagation(); input.value = ''; apply(); }
    });

    if (clear) clear.addEventListener('click', function () {
      input.value = '';
      Array.prototype.forEach.call(chips, function (c) { c.classList.remove('is-on'); });
      apply();
      input.focus();
    });

    Array.prototype.forEach.call(chips, function (chip) {
      chip.addEventListener('click', function () {
        var on = chip.classList.contains('is-on');
        Array.prototype.forEach.call(chips, function (c) { c.classList.remove('is-on'); });
        if (on) {
          input.value = '';
        } else {
          chip.classList.add('is-on');
          input.value = chip.getAttribute('data-tfilter-chip') || '';
        }
        apply();
      });
    });

    apply();
  });

  /* ------------------------------------------------- 6. Renditja e kolonave */
  /* Gjenerike: çdo tabelë me [data-sortable] e merr. Lloji i kolonës thuhet
     te th[data-sort] — "text", "num", "date". Tabelat me trupa të shumtë
     (p.sh. grupet, ku çdo grup është një <tbody>) renditen si blloqe, që
     rreshti i fëmijëve të mos ndahet kurrë nga i prindit. */
  function cellKey(row, index, kind) {
    var cell = row.cells[index];
    if (!cell) return kind === 'text' ? '' : 0;
    var raw = (cell.textContent || '').trim();

    if (kind === 'num') {
      var n = parseFloat(raw.replace(/[^0-9.,-]/g, '').replace(/\.(?=\d{3})/g, '').replace(',', '.'));
      return isNaN(n) ? -Infinity : n;
    }
    if (kind === 'date') {
      var m = raw.match(/(\d{2})[-.\/](\d{2})[-.\/](\d{4})/);
      if (m) return new Date(+m[3], +m[2] - 1, +m[1]).getTime();
      var d = Date.parse(raw);
      return isNaN(d) ? -Infinity : d;
    }
    return raw.toLowerCase();
  }

  function sortTable(table, index, kind, dir) {
    var groups = table.tBodies.length > 1
      ? Array.prototype.slice.call(table.tBodies)
      : Array.prototype.slice.call(table.tBodies[0] ? table.tBodies[0].rows : []);

    var keyed = groups.map(function (node) {
      var row = node.tagName === 'TBODY' ? node.rows[0] : node;
      return { node: node, key: cellKey(row, index, kind) };
    });

    keyed.sort(function (a, b) {
      if (a.key < b.key) return -dir;
      if (a.key > b.key) return dir;
      return 0;
    });

    if (table.tBodies.length > 1) {
      keyed.forEach(function (k) { table.appendChild(k.node); });
    } else {
      var body = table.tBodies[0];
      keyed.forEach(function (k) { body.appendChild(k.node); });
    }
  }

  document.querySelectorAll('table[data-sortable]').forEach(function (table) {
    var head = table.tHead;
    if (!head) return;

    Array.prototype.forEach.call(head.rows[0].cells, function (th, index) {
      var kind = th.getAttribute('data-sort');
      if (!kind || kind === 'none') return;

      th.setAttribute('role', 'button');
      th.setAttribute('tabindex', '0');

      var activate = function () {
        var asc = !th.classList.contains('is-asc');

        Array.prototype.forEach.call(head.rows[0].cells, function (other) {
          other.classList.remove('is-asc', 'is-desc');
          other.removeAttribute('aria-sort');
        });
        th.classList.add(asc ? 'is-asc' : 'is-desc');
        th.setAttribute('aria-sort', asc ? 'ascending' : 'descending');

        sortTable(table, index, kind, asc ? 1 : -1);
      };

      th.addEventListener('click', activate);
      th.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); activate(); }
      });
    });
  });

  /* --------------------------------------------------- 6b. Kthimi në krye */
  var toTop = document.querySelector('[data-back-top]');
  if (toTop) {
    var onScroll = function () { toTop.classList.toggle('is-on', window.scrollY > 420); };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  /* ------------------------------------------- 7. Mbyll menunë pas klikimit */
  document.querySelectorAll('.app-nav a.app-nav-link').forEach(function (link) {
    link.addEventListener('click', function () {
      var nav = document.getElementById('appNav');
      if (nav) nav.classList.remove('is-open');
    });
  });

  /* ------------------------------------------ 8. Kërkimi inteligjent global */
  /* Hapet me Ctrl+K, "/", nga çelësi i kokëfletës dhe nga çdo fushë q/search.
     Serveri vendos kufijtë e rolit; ndërfaqja vetëm paraqet aftësitë e lejuara. */
  (function () {
    var veil, input, body, types, summary, filtersPanel, filterToggle, pageAction;
    var sortSelect, statusSelect, periodSelect, matchSelect, limitSelect;
    var timer = null, seq = 0, controller = null;
    var active = -1, flat = [], only = '', sourceInput = null;
    var sort = 'relevance', status = 'any', period = 'any', matchMode = 'contains', limit = '6';
    var available = [];

    var typeLabels = {
      student: 'Kursantë', group: 'Grupe', course: 'Module', agency: 'Agjenci',
      user: 'Llogari', audit: 'Auditim'
    };

    function esc(value) {
      return String(value == null ? '' : value).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
      });
    }

    function mark(text, needle) {
      var raw = String(text == null ? '' : text);
      var at = raw.toLocaleLowerCase().indexOf(String(needle || '').toLocaleLowerCase());
      if (at < 0 || !needle) return esc(raw);
      return esc(raw.slice(0, at)) + '<mark>' + esc(raw.slice(at, at + needle.length)) + '</mark>' + esc(raw.slice(at + needle.length));
    }

    function build() {
      veil = document.createElement('div');
      veil.className = 'pal-veil';
      veil.setAttribute('role', 'dialog');
      veil.setAttribute('aria-modal', 'true');
      veil.setAttribute('aria-label', 'Kërko në regjistër');
      veil.innerHTML =
        '<div class="pal">' +
          '<div class="pal-head">' +
            '<i class="bi bi-search" aria-hidden="true"></i>' +
            '<input type="text" autocomplete="off" spellcheck="false" ' +
                   'placeholder="Kërko emër, AMZË, telefon, grup, modul…" aria-label="Kërko">' +
            '<span class="pal-head-tag">Kërkim inteligjent</span>' +
            '<button class="pal-close" type="button" aria-label="Mbyll"><span>esc</span></button>' +
          '</div>' +
          '<div class="pal-types" role="tablist" aria-label="Kufizo llojin">' +
            '<button class="pal-type is-on" type="button" data-type="" role="tab">Të gjitha</button>' +
            '<button class="pal-type" type="button" data-type="student" role="tab">Kursantë</button>' +
            '<button class="pal-type" type="button" data-type="group" role="tab">Grupe</button>' +
            '<button class="pal-type" type="button" data-type="course" role="tab">Module</button>' +
            '<button class="pal-type" type="button" data-type="agency" role="tab">Agjenci</button>' +
            '<button class="pal-type" type="button" data-type="user" role="tab">Llogari</button>' +
            '<button class="pal-type" type="button" data-type="audit" role="tab">Auditim</button>' +
          '</div>' +
          '<div class="pal-tools">' +
            '<span class="pal-summary" data-pal-summary aria-live="polite">Gati për kërkim</span>' +
            '<label class="pal-sort"><span>Rendit</span>' +
              '<select data-pal-sort aria-label="Rendit rezultatet">' +
                '<option value="relevance">Më të përafërtat</option>' +
                '<option value="az">A → Z</option><option value="za">Z → A</option>' +
                '<option value="newest">Më të rejat</option><option value="oldest">Më të vjetrat</option>' +
              '</select>' +
            '</label>' +
            '<button class="pal-filter-toggle" type="button" data-pal-filter-toggle aria-expanded="false">' +
              '<i class="bi bi-sliders2" aria-hidden="true"></i> Filtra <span class="pal-filter-count">0</span>' +
            '</button>' +
          '</div>' +
          '<div class="pal-filters" data-pal-filters hidden>' +
            '<label><span>Gjendja</span><select data-pal-status>' +
              '<option value="any">Çfarëdo gjendjeje</option><option value="active">Aktive / të hapura</option>' +
              '<option value="closed">Të mbyllura</option><option value="ungrouped">Pa grup / caktim</option>' +
            '</select></label>' +
            '<label><span>Periudha</span><select data-pal-period>' +
              '<option value="any">Çfarëdo periudhe</option><option value="30">30 ditët e fundit</option>' +
              '<option value="90">90 ditët e fundit</option><option value="365">12 muajt e fundit</option>' +
            '</select></label>' +
            '<label><span>Përputhja</span><select data-pal-match>' +
              '<option value="contains">Përmban fjalën</option><option value="prefix">Fillon me</option>' +
              '<option value="exact">Përputhje e saktë</option>' +
            '</select></label>' +
            '<label><span>Rezultate / lloj</span><select data-pal-limit>' +
              '<option value="6">6 rezultate</option><option value="10">10 rezultate</option><option value="15">15 rezultate</option>' +
            '</select></label>' +
            '<button class="pal-reset" type="button" data-pal-reset><i class="bi bi-arrow-counterclockwise"></i> Pastro filtrat</button>' +
          '</div>' +
          '<div class="pal-body" role="listbox" aria-live="polite"></div>' +
          '<button class="pal-page-action" type="button" data-pal-page-action hidden>' +
            '<i class="bi bi-funnel" aria-hidden="true"></i><span>Filtro faqen aktuale</span><kbd>alt ↵</kbd>' +
          '</button>' +
          '<div class="pal-foot">' +
            '<span><kbd>↑</kbd><kbd>↓</kbd> lëviz</span>' +
            '<span><kbd>enter</kbd> hap</span>' +
            '<span><kbd>tab</kbd> ndrysho llojin</span>' +
            '<span><kbd>alt</kbd><kbd>enter</kbd> filtro faqen</span>' +
            '<span><kbd>esc</kbd> mbyll</span>' +
          '</div>' +
        '</div>';
      document.body.appendChild(veil);

      input = veil.querySelector('.pal-head input');
      body = veil.querySelector('.pal-body');
      types = veil.querySelectorAll('.pal-type');
      summary = veil.querySelector('[data-pal-summary]');
      filtersPanel = veil.querySelector('[data-pal-filters]');
      filterToggle = veil.querySelector('[data-pal-filter-toggle]');
      pageAction = veil.querySelector('[data-pal-page-action]');
      sortSelect = veil.querySelector('[data-pal-sort]');
      statusSelect = veil.querySelector('[data-pal-status]');
      periodSelect = veil.querySelector('[data-pal-period]');
      matchSelect = veil.querySelector('[data-pal-match]');
      limitSelect = veil.querySelector('[data-pal-limit]');

      veil.addEventListener('mousedown', function (event) {
        if (event.target === veil) close();
      });
      veil.querySelector('.pal-close').addEventListener('click', close);
      input.addEventListener('input', function () { updatePageAction(); schedule(); });

      Array.prototype.forEach.call(types, function (button) {
        button.addEventListener('click', function () {
          setType(button.getAttribute('data-type') || '');
          input.focus();
          schedule(0);
        });
      });

      sortSelect.addEventListener('change', function () { sort = sortSelect.value; schedule(0); });
      statusSelect.addEventListener('change', function () { status = statusSelect.value; filtersChanged(); });
      periodSelect.addEventListener('change', function () { period = periodSelect.value; filtersChanged(); });
      matchSelect.addEventListener('change', function () { matchMode = matchSelect.value; filtersChanged(); });
      limitSelect.addEventListener('change', function () { limit = limitSelect.value; filtersChanged(); });

      filterToggle.addEventListener('click', function () {
        var expanded = filterToggle.getAttribute('aria-expanded') === 'true';
        filterToggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
        filtersPanel.hidden = expanded;
        if (!expanded) statusSelect.focus();
      });
      veil.querySelector('[data-pal-reset]').addEventListener('click', function () {
        status = 'any'; period = 'any'; matchMode = 'contains'; limit = '6'; sort = 'relevance';
        syncControls(); updateFilterCount(); input.focus(); schedule(0);
      });
      pageAction.addEventListener('click', applyToPage);

      body.addEventListener('mousemove', function (event) {
        var item = event.target.closest ? event.target.closest('.pal-item') : null;
        if (item) { active = flat.indexOf(item); paint(); }
      });
      body.addEventListener('click', function (event) {
        var item = event.target.closest ? event.target.closest('.pal-item') : null;
        if (item) remember(input.value.trim());
      });
    }

    function syncControls() {
      sortSelect.value = sort;
      statusSelect.value = status;
      periodSelect.value = period;
      matchSelect.value = matchMode;
      limitSelect.value = limit;
    }

    function filtersChanged() {
      updateFilterCount();
      input.focus();
      schedule(0);
    }

    function updateFilterCount() {
      if (!filterToggle) return;
      var count = 0;
      if (status !== 'any') count++;
      if (period !== 'any') count++;
      if (matchMode !== 'contains') count++;
      if (limit !== '6') count++;
      filterToggle.querySelector('.pal-filter-count').textContent = String(count);
      filterToggle.classList.toggle('has-filters', count > 0);
    }

    function contextType(element) {
      var explicit = element && element.getAttribute ? element.getAttribute('data-search-type') : '';
      if (explicit) return explicit;
      var page = (window.location.pathname.split('/').pop() || '').toLowerCase();
      if (page === 'groups.php' || page === 'groups_agjencia.php' || page === 'groups_student.php') return 'group';
      if (page === 'courses.php') return 'course';
      if (page === 'agencies.php') return 'agency';
      if (page === 'users.php' || page === 'editors.php') return 'user';
      if (page === 'logs.php' || page === 'logs_editor.php') return 'audit';
      if (/student|register/.test(page)) return 'student';
      return '';
    }

    function typesFromPage() {
      var owner = document.querySelector('[data-search-types]');
      var raw = owner ? owner.getAttribute('data-search-types') : '';
      return raw ? raw.split(',').filter(Boolean) : [];
    }

    function applyCapabilities(list) {
      if (list && list.length) available = list.slice();
      Array.prototype.forEach.call(types, function (button) {
        var value = button.getAttribute('data-type') || '';
        var allowed = value === '' || !available.length || available.indexOf(value) >= 0;
        button.hidden = !allowed;
      });
      if (only && available.length && available.indexOf(only) < 0) setType('');
    }

    function setType(value) {
      only = value || '';
      Array.prototype.forEach.call(types, function (button) {
        var selected = (button.getAttribute('data-type') || '') === only;
        button.classList.toggle('is-on', selected);
        button.setAttribute('aria-selected', selected ? 'true' : 'false');
      });
    }

    function open(seed, trigger) {
      if (!veil) build();
      sourceInput = trigger && isPageSearch(trigger) ? trigger : null;
      available = typesFromPage();
      applyCapabilities(available);
      setType(sourceInput ? contextType(sourceInput) : '');

      sort = 'relevance'; status = 'any'; period = 'any'; matchMode = 'contains'; limit = '6';
      syncControls(); updateFilterCount();
      filtersPanel.hidden = true;
      filterToggle.setAttribute('aria-expanded', 'false');

      input.value = seed || '';
      flat = []; active = -1;
      veil.classList.add('is-open');
      document.documentElement.style.overflow = 'hidden';
      updatePageAction();
      renderStart();
      input.focus();
      input.select();
      if (input.value.trim().length >= 2) schedule(0);
    }

    function close() {
      if (!veil) return;
      clearTimeout(timer);
      if (controller) controller.abort();
      veil.classList.remove('is-open');
      document.documentElement.style.overflow = '';
    }

    function schedule(delay) {
      clearTimeout(timer);
      timer = setTimeout(run, delay === 0 ? 0 : 180);
    }

    function recent() {
      try { return JSON.parse(localStorage.getItem('qta_recent_searches') || '[]').slice(0, 6); }
      catch (error) { return []; }
    }

    function remember(term) {
      if (!term || term.length < 2) return;
      var list = recent().filter(function (item) { return item.toLocaleLowerCase() !== term.toLocaleLowerCase(); });
      list.unshift(term);
      try { localStorage.setItem('qta_recent_searches', JSON.stringify(list.slice(0, 6))); } catch (error) { /* private mode */ }
    }

    function renderStart() {
      var items = recent();
      summary.textContent = 'Gati për kërkim';
      if (!items.length) {
        body.innerHTML = '<div class="pal-welcome"><i class="bi bi-stars"></i>' +
          '<b>Kërko në të gjithë regjistrin</b><span>Shkruaj të paktën dy shenja. Mund të përdorësh emër të plotë, atësi, AMZË, telefon, kod moduli ose numër grupi.</span></div>';
        return;
      }
      body.innerHTML = '<div class="pal-recent"><span class="pal-recent-label">Kërkimet e fundit</span>' +
        items.map(function (item) {
          return '<button type="button" data-pal-recent="' + esc(item) + '"><i class="bi bi-clock-history"></i>' + esc(item) + '</button>';
        }).join('') + '</div>';
      body.querySelectorAll('[data-pal-recent]').forEach(function (button) {
        button.addEventListener('click', function () {
          input.value = button.getAttribute('data-pal-recent') || '';
          updatePageAction(); input.focus(); schedule(0);
        });
      });
    }

    function renderLoading() {
      body.setAttribute('aria-busy', 'true');
      body.innerHTML = '<div class="pal-loading"><span></span><span></span><span></span></div>';
      summary.textContent = 'Po kërkoj…';
    }

    function updatePageAction() {
      if (!pageAction) return;
      var q = input.value.trim();
      var usable = sourceInput && sourceInput.form && q.length >= 2;
      pageAction.hidden = !usable;
      if (usable) {
        var heading = document.querySelector('main h1, main h2');
        var where = heading ? heading.textContent.trim() : 'faqen aktuale';
        pageAction.querySelector('span').textContent = 'Filtro “' + q + '” te ' + where;
      }
    }

    function applyToPage() {
      var q = input.value.trim();
      if (!sourceInput || !sourceInput.form || q.length < 2) return;
      remember(q);
      sourceInput.value = q;
      var form = sourceInput.form;
      close();
      form.submit();
    }

    function run() {
      var q = input.value.trim();
      updatePageAction();
      if (q.length < 2) {
        flat = []; active = -1; renderStart(); return;
      }

      var mine = ++seq;
      if (controller) controller.abort();
      controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
      renderLoading();

      var params = new URLSearchParams({
        q: q, sort: sort, status: status, period: period,
        match: matchMode, limit: limit
      });
      if (only) params.set('type', only);

      fetch('app/actions/search_advanced.php?' + params.toString(), {
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin',
        signal: controller ? controller.signal : undefined
      })
        .then(function (response) {
          return response.json().then(function (json) {
            if (!response.ok && !json.error) json.error = 'Kërkimi dështoi.';
            return json;
          });
        })
        .then(function (json) {
          if (mine !== seq) return;
          body.removeAttribute('aria-busy');
          if (!json.ok) {
            body.innerHTML = '<div class="pal-message is-error"><i class="bi bi-exclamation-triangle"></i><b>' +
              esc(json.error || 'Kërkimi dështoi.') + '</b><span>Provo sërish ose rifresko faqen.</span></div>';
            summary.textContent = 'Kërkimi dështoi'; flat = []; active = -1; return;
          }
          applyCapabilities(json.capabilities || []);
          updateTypeCounts(json.counts || {});
          if (!json.total) {
            body.innerHTML = '<div class="pal-message"><i class="bi bi-search"></i><b>Asgjë nuk u gjet</b>' +
              '<span>Nuk ka përputhje me “' + esc(q) + '”. Provo “përmban fjalën” ose pastro filtrat.</span></div>';
            summary.textContent = '0 rezultate · ' + (json.took_ms || 0) + ' ms';
            flat = []; active = -1; return;
          }

          var html = '';
          (json.groups || []).forEach(function (group) {
            html += '<div class="pal-group-label"><span>' + esc(group.label) + '</span><b>' + group.items.length + '</b></div>';
            group.items.forEach(function (item) {
              var state = /^(active|closed|ungrouped|neutral|insert|update|delete)$/.test(item.status || '') ? item.status : 'neutral';
              html += '<a class="pal-item" role="option" aria-selected="false" href="' + esc(item.href) + '">' +
                        '<span class="pal-item-icon"><i class="bi ' + esc(item.icon || 'bi-dot') + '" aria-hidden="true"></i></span>' +
                        '<span class="pal-item-main">' +
                          '<span class="pal-item-title">' + mark(item.title, q) + '</span>' +
                          (item.meta ? '<span class="pal-item-meta">' + esc(item.meta) + '</span>' : '') +
                        '</span>' +
                        '<span class="pal-item-side">' +
                          (item.status_label ? '<span class="pal-status is-' + state + '">' + esc(item.status_label) + '</span>' : '') +
                          (item.code ? '<span class="pal-item-code">' + mark(item.code, q) + '</span>' : '') +
                        '</span>' +
                        '<i class="bi bi-arrow-up-right pal-item-open" aria-hidden="true"></i>' +
                      '</a>';
            });
          });
          body.innerHTML = html;
          flat = Array.prototype.slice.call(body.querySelectorAll('.pal-item'));
          active = flat.length ? 0 : -1;
          summary.textContent = json.total + (json.total === 1 ? ' rezultat' : ' rezultate') + ' · ' + (json.took_ms || 0) + ' ms';
          paint();
        })
        .catch(function (error) {
          if (error && error.name === 'AbortError') return;
          if (mine !== seq) return;
          body.removeAttribute('aria-busy');
          body.innerHTML = '<div class="pal-message is-error"><i class="bi bi-wifi-off"></i><b>Lidhja dështoi</b><span>Kontrollo lidhjen dhe provo sërish.</span></div>';
          summary.textContent = 'Pa lidhje'; flat = []; active = -1;
        });
    }

    function updateTypeCounts(counts) {
      Array.prototype.forEach.call(types, function (button) {
        var old = button.querySelector('.pal-type-count');
        if (old) old.remove();
        var value = button.getAttribute('data-type') || '';
        if (value && Object.prototype.hasOwnProperty.call(counts, value)) {
          var badge = document.createElement('span');
          badge.className = 'pal-type-count';
          badge.textContent = counts[value];
          button.appendChild(badge);
        }
      });
    }

    function paint() {
      flat.forEach(function (element, index) {
        var selected = index === active;
        element.classList.toggle('is-active', selected);
        element.setAttribute('aria-selected', selected ? 'true' : 'false');
      });
      if (active >= 0 && flat[active]) flat[active].scrollIntoView({ block: 'nearest' });
    }

    function step(delta) {
      if (!flat.length) return;
      active = (active + delta + flat.length) % flat.length;
      paint();
    }

    function cycleType(delta) {
      var list = Array.prototype.slice.call(types).filter(function (button) { return !button.hidden; });
      if (!list.length) return;
      var index = 0;
      list.forEach(function (button, at) { if (button.classList.contains('is-on')) index = at; });
      list[(index + delta + list.length) % list.length].click();
    }

    function isPageSearch(element) {
      if (!element || !element.matches || element.getAttribute('data-pal-off') === '1') return false;
      if (element.getAttribute('data-search-native') === '1') return false;
      if (veil && veil.contains(element)) return false;
      return element.matches('input[name="q"], input[type="search"], .jump-field input');
    }

    document.addEventListener('keydown', function (event) {
      var tag = (event.target.tagName || '').toLowerCase();
      var typing = tag === 'input' || tag === 'textarea' || tag === 'select' || event.target.isContentEditable;

      if ((event.ctrlKey || event.metaKey) && (event.key === 'k' || event.key === 'K')) {
        event.preventDefault();
        open(typing && event.target.value ? event.target.value : '', isPageSearch(event.target) ? event.target : null);
        return;
      }
      if (event.key === '/' && !typing) {
        event.preventDefault(); open('', null); return;
      }
      if (!veil || !veil.classList.contains('is-open')) return;

      if (event.key === 'Escape') { event.preventDefault(); close(); }
      else if (event.key === 'ArrowDown') { event.preventDefault(); step(1); }
      else if (event.key === 'ArrowUp') { event.preventDefault(); step(-1); }
      else if (event.key === 'Tab') { event.preventDefault(); cycleType(event.shiftKey ? -1 : 1); }
      else if (event.key === 'Enter' && event.altKey) { event.preventDefault(); applyToPage(); }
      else if (event.key === 'Enter' && active >= 0 && flat[active]) {
        event.preventDefault();
        remember(input.value.trim());
        window.location.href = flat[active].getAttribute('href');
      }
    }, true);

    document.addEventListener('click', function (event) {
      var trigger = event.target.closest ? event.target.closest('[data-open-palette]') : null;
      if (trigger) { event.preventDefault(); open('', null); }
    });

    /* Delegimi e mbulon edhe fushat që shtohen më vonë nga modale/komponentë. */
    document.addEventListener('pointerdown', function (event) {
      var target = event.target;
      if (!isPageSearch(target)) return;
      event.preventDefault();
      open(target.value || '', target);
    }, true);
    document.addEventListener('focusin', function (event) {
      if (!isPageSearch(event.target)) return;
      open(event.target.value || '', event.target);
    });
  })();

  /* --------------------------------------------------------- 9. Tooltip-et */
  if (window.bootstrap && window.bootstrap.Tooltip) {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
      new window.bootstrap.Tooltip(el);
    });
  }

  /* ----------------------------------------------------------- 10. Njoftimet */
  window.qtaToast = function (message, variant, title) {
    variant = variant || 'info';

    var area = document.querySelector('[data-toast-area]');
    if (!area) {
      area = document.createElement('div');
      area.className = 'toast-container position-fixed bottom-0 start-0 p-3';
      area.setAttribute('data-toast-area', '');
      document.body.appendChild(area);
    }

    var titles = { success: 'Regjistruar', danger: 'E papranuar', warning: 'Kujdes', info: 'Shënim' };

    var el = document.createElement('div');
    el.className = 'toast qta-toast toast-' + variant;
    el.setAttribute('role', 'status');
    el.setAttribute('aria-live', 'polite');
    el.innerHTML =
      '<div class="toast-header"><strong class="me-auto"></strong>' +
      '<button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Mbyll"></button></div>' +
      '<div class="toast-body"></div>';
    el.querySelector('strong').textContent = title || titles[variant] || titles.info;
    el.querySelector('.toast-body').textContent = message;
    area.appendChild(el);

    if (window.bootstrap) {
      var t = new window.bootstrap.Toast(el, { delay: 3600 });
      t.show();
      el.addEventListener('hidden.bs.toast', function () { el.remove(); });
    }
  };
})();
