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

  /* ------------------------------------------- 8. Kërkimi i menjëhershëm */
  /* Hapet kudo me Ctrl+K ose "/". Rezultatet vijnë ndërsa shkruan; roli
     kufizohet nga serveri, jo nga ndërfaqja. */
  (function () {
    var veil, input, body, types;
    var timer = null, seq = 0, active = -1, flat = [], only = '';

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
                   'placeholder="Kërko kursant, grup ose modul…" aria-label="Kërko">' +
            '<span class="pal-esc">esc</span>' +
          '</div>' +
          '<div class="pal-types" role="group" aria-label="Kufizo llojin">' +
            '<button class="pal-type is-on" data-type="">Të gjitha</button>' +
            '<button class="pal-type" data-type="student">Kursantë</button>' +
            '<button class="pal-type" data-type="group">Grupe</button>' +
            '<button class="pal-type" data-type="course">Module</button>' +
            '<button class="pal-type" data-type="agency">Agjenci</button>' +
          '</div>' +
          '<div class="pal-body" role="listbox"></div>' +
          '<div class="pal-foot">' +
            '<span><kbd>↑</kbd><kbd>↓</kbd> lëviz</span>' +
            '<span><kbd>enter</kbd> hap</span>' +
            '<span><kbd>tab</kbd> ndrysho llojin</span>' +
            '<span><kbd>esc</kbd> mbyll</span>' +
          '</div>' +
        '</div>';
      document.body.appendChild(veil);

      input = veil.querySelector('input');
      body  = veil.querySelector('.pal-body');
      types = veil.querySelectorAll('.pal-type');

      veil.addEventListener('mousedown', function (e) { if (e.target === veil) close(); });
      input.addEventListener('input', function () { schedule(); });

      Array.prototype.forEach.call(types, function (b) {
        b.addEventListener('click', function () {
          only = b.getAttribute('data-type') || '';
          Array.prototype.forEach.call(types, function (o) { o.classList.toggle('is-on', o === b); });
          input.focus();
          schedule(0);
        });
      });

      body.addEventListener('mousemove', function (e) {
        var it = e.target.closest ? e.target.closest('.pal-item') : null;
        if (it) { active = flat.indexOf(it); paint(); }
      });
    }

    function open(seed) {
      if (!veil) build();
      veil.classList.add('is-open');

      /* Lloji rinis gjithnjë te "Të gjitha": përndryshe hap paletën javën
         tjetër dhe merr rezultate të filtruara pa e ditur pse. */
      only = '';
      Array.prototype.forEach.call(types, function (b) {
        b.classList.toggle('is-on', !b.getAttribute('data-type'));
      });

      input.value = seed || '';
      body.innerHTML = '<p class="pal-note">Shkruaj të paktën dy shkronja.</p>';
      flat = []; active = -1;
      input.focus();
      input.select();
      if (input.value) schedule(0);
      document.documentElement.style.overflow = 'hidden';
    }

    function close() {
      if (!veil) return;
      veil.classList.remove('is-open');
      document.documentElement.style.overflow = '';
    }

    function schedule(delay) {
      clearTimeout(timer);
      timer = setTimeout(run, delay === 0 ? 0 : 160);
    }

    function esc(str) {
      return String(str).replace(/[&<>"]/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
      });
    }

    function mark(text, needle) {
      var safe = esc(text);
      if (!needle) return safe;
      var i = safe.toLowerCase().indexOf(needle.toLowerCase());
      if (i < 0) return safe;
      return safe.slice(0, i) + '<mark>' + safe.slice(i, i + needle.length) + '</mark>' + safe.slice(i + needle.length);
    }

    function run() {
      var q = input.value.trim();
      if (q.length < 2) {
        body.innerHTML = '<p class="pal-note">Shkruaj të paktën dy shkronja.</p>';
        flat = []; active = -1;
        return;
      }

      var mine = ++seq;
      var url = 'app/actions/search.php?q=' + encodeURIComponent(q) + (only ? '&type=' + only : '');

      fetch(url, { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (json) {
          if (mine !== seq) return;
          if (!json.ok) {
            body.innerHTML = '<p class="pal-note">' + esc(json.error || 'Kërkimi dështoi.') + '</p>';
            flat = []; return;
          }
          if (!json.total) {
            body.innerHTML = '<p class="pal-note">Asgjë nuk përputhet me <b>' + esc(q) + '</b>.</p>';
            flat = []; active = -1; return;
          }

          var html = '';
          json.groups.forEach(function (g) {
            html += '<div class="pal-group-label">' + esc(g.label) + '</div>';
            g.items.forEach(function (it) {
              html += '<a class="pal-item" role="option" href="' + esc(it.href) + '">' +
                        '<i class="bi ' + esc(it.icon || 'bi-dot') + '" aria-hidden="true"></i>' +
                        '<span class="pal-item-main">' +
                          '<span class="pal-item-title">' + mark(it.title, q) + '</span>' +
                          (it.meta ? '<span class="pal-item-meta">' + esc(it.meta) + '</span>' : '') +
                        '</span>' +
                        (it.code ? '<span class="pal-item-code">' + mark(it.code, q) + '</span>' : '') +
                      '</a>';
            });
          });
          body.innerHTML = html;
          flat = Array.prototype.slice.call(body.querySelectorAll('.pal-item'));
          active = flat.length ? 0 : -1;
          paint();
        })
        .catch(function () {
          if (mine !== seq) return;
          body.innerHTML = '<p class="pal-note">Lidhja dështoi.</p>';
          flat = [];
        });
    }

    function paint() {
      flat.forEach(function (el, i) { el.classList.toggle('is-active', i === active); });
      if (active >= 0 && flat[active]) { flat[active].scrollIntoView({ block: 'nearest' }); }
    }

    function step(delta) {
      if (!flat.length) return;
      active = (active + delta + flat.length) % flat.length;
      paint();
    }

    function cycleType(delta) {
      var list = Array.prototype.slice.call(types);
      var i = 0;
      list.forEach(function (b, k) { if (b.classList.contains('is-on')) i = k; });
      list[(i + delta + list.length) % list.length].click();
    }

    document.addEventListener('keydown', function (e) {
      var tag = (e.target.tagName || '').toLowerCase();
      var typing = tag === 'input' || tag === 'textarea' || tag === 'select' || e.target.isContentEditable;

      if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
        e.preventDefault();
        open(typing && e.target.value ? e.target.value : '');
        return;
      }
      if (e.key === '/' && !typing) {
        e.preventDefault();
        open('');
        return;
      }

      if (!veil || !veil.classList.contains('is-open')) return;

      if (e.key === 'Escape')         { e.preventDefault(); close(); }
      else if (e.key === 'ArrowDown') { e.preventDefault(); step(1); }
      else if (e.key === 'ArrowUp')   { e.preventDefault(); step(-1); }
      else if (e.key === 'Tab')       { e.preventDefault(); cycleType(e.shiftKey ? -1 : 1); }
      else if (e.key === 'Enter' && active >= 0 && flat[active]) {
        e.preventDefault();
        window.location.href = flat[active].getAttribute('href');
      }
    }, true);

    /* Çelësi në kokëfletë hapet me klikim; fushat e faqes me fokus. */
    document.addEventListener('click', function (e) {
      var t = e.target.closest ? e.target.closest('[data-open-palette]') : null;
      if (t) { e.preventDefault(); open(''); }
    });

    document.querySelectorAll('.app-find input, .jump-field input')
      .forEach(function (el) {
        el.addEventListener('focus', function () {
          if (el.getAttribute('data-pal-off') === '1') return;
          open(el.value || '');
          el.blur();
        });
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
