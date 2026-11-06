/* PMS — shared UI kit: shell, guards, tables helpers, toasts, modals */
const UI = (() => {

  const NAV = [
    { key: 'dashboard',  ico: '▤', label: 'Dashboard',    href: 'dashboard.html',  group: null },
    { key: 'items',      ico: '▣', label: 'Items',         href: 'items.html',      group: 'Inventory' },
    { key: 'stock_in',   ico: '⇩', label: 'Stock In',      href: 'stock_in.html',   group: 'Inventory' },
    { key: 'stock_card', ico: '▥', label: 'Stock Card',    href: 'stock_card.html', group: 'Inventory' },
    { key: 'count',      ico: '⇆', label: 'Physical Count',href: 'count.html',      group: 'Inventory' },
    { key: 'customers',  ico: '◉', label: 'Customers',     href: 'customers.html',  group: 'Sales' },
    { key: 'deliveries', ico: '⇨', label: 'Deliveries',    href: 'deliveries.html', group: 'Sales' },
    { key: 'payments',   ico: '₱', label: 'Payments',      href: 'payments.html',   group: 'Sales' },
    { key: 'reports',    ico: '▦', label: 'Reports',       href: 'reports.html',    group: 'Insights' },
  ];

  const esc = (s) => String(s ?? '')
    .replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;').replaceAll("'", '&#39;');

  const money = (n) => '₱' + Number(n || 0).toLocaleString('en-PH', {
    minimumFractionDigits: 2, maximumFractionDigits: 2
  });

  const qty = (n) => Number(n || 0).toLocaleString('en-PH');

  const date = (s) => {
    if (!s) return '—';
    const d = new Date(String(s).replace(' ', 'T'));
    if (isNaN(d)) return esc(s);
    return d.toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: '2-digit' });
  };

  const badge = (text, tone = 'mute') =>
    `<span class="badge ${esc(tone)}">${esc(text)}</span>`;

  /* status/type tone helpers shared by all pages */
  const statusTone = (s) => ({ DELIVERED: 'pos', CANCELLED: 'mute' }[s] || 'mute');
  const typeTone = (t) => ({ SCHOOL: 'info', OFFICE: 'warn', DIRECT: 'pos', BIDDING: 'warn' }[t] || 'mute');

  function toast(msg, type = 'ok') {
    let zone = document.getElementById('toast-zone');
    if (!zone) { zone = document.createElement('div'); zone.id = 'toast-zone'; document.body.appendChild(zone); }
    const el = document.createElement('div');
    el.className = 'toast' + (type === 'err' ? ' err' : type === 'warn' ? ' warn' : '');
    el.textContent = msg;
    zone.appendChild(el);
    setTimeout(() => { el.style.opacity = '0'; el.style.transition = 'opacity .25s'; setTimeout(() => el.remove(), 260); },
      type === 'err' ? 5200 : 3200);
  }

  function confirm(msg, { danger = false } = {}) {
    return new Promise((resolve) => {
      const m = modal({
        title: 'Please confirm',
        width: 'narrow',
        bodyHTML: `<p style="margin:0;color:var(--ink-2)">${esc(msg)}</p>`,
        footerHTML: `<button class="btn" data-act="no">Cancel</button>
                     <button class="btn ${danger ? 'danger' : 'primary'}" data-act="yes">${danger ? 'Delete' : 'Confirm'}</button>`
      });
      m.el.querySelector('[data-act="no"]').onclick = () => { m.close(); resolve(false); };
      m.el.querySelector('[data-act="yes"]').onclick = () => { m.close(); resolve(true); };
      m.el.querySelector('.modal-head .x').addEventListener('click', () => resolve(false));
    });
  }

  /* modal({title, bodyHTML, footerHTML, width}) -> {el, close} */
  function modal({ title, bodyHTML = '', footerHTML = '', width = '' }) {
    const backdrop = document.createElement('div');
    backdrop.className = 'modal-backdrop';
    backdrop.innerHTML = `
      <div class="modal ${width === 'narrow' ? '' : width}">
        <div class="modal-head"><h3>${esc(title)}</h3><button class="x" type="button" aria-label="Close">✕</button></div>
        <div class="modal-body">${bodyHTML}</div>
        <div class="modal-foot">${footerHTML}</div>
      </div>`;
    document.body.appendChild(backdrop);
    const close = () => backdrop.remove();
    backdrop.addEventListener('click', (e) => { if (e.target === backdrop) close(); });
    backdrop.querySelector('.modal-head .x').addEventListener('click', close);
    document.addEventListener('keydown', function onkey(e) {
      if (e.key === 'Escape') { close(); document.removeEventListener('keydown', onkey); }
    });
    return { el: backdrop, close };
  }

  /* Render the consistent sidebar + topbar into #shell[data-page] */
  function shell(activeKey, title, hint = '') {
    const shellEl = document.getElementById('shell');
    if (!shellEl) return;
    const page = shellEl.dataset.page || activeKey || '';

    let lastGroup = null;
    const links = NAV.map(n => {
      const isPage = n.key === page;
      let g = '';
      if (n.group && n.group !== lastGroup) { lastGroup = n.group; g = `<div class="nav-group">${n.group}</div>`; }
      return `${g}<a href="${n.href}" class="${isPage ? 'active' : ''}" ${isPage ? 'aria-current="page"' : ''}>
        <span class="nav-ico">${n.ico}</span>${n.label}</a>`;
    }).join('');

    shellEl.outerHTML = `
      <div class="app">
        <aside class="sidebar">
          <div class="brand">
            <div class="brand-name">PMS Trading</div>
            <div class="brand-sub">Inventory &amp; Delivery</div>
          </div>
          <nav class="nav" aria-label="Main">${links}</nav>
          <div class="user-strip">
            <span class="who" id="who">…</span>
            <button class="link-ghost" id="logout" type="button">Logout</button>
          </div>
        </aside>
        <div class="sb-backdrop" id="sb-backdrop" aria-hidden="true"></div>
        <main class="main">
          <header class="topbar">
            <button class="burger" id="burger" type="button" aria-label="Open menu" aria-expanded="false">
              <svg width="20" height="20" viewBox="0 0 20 20" aria-hidden="true"><path d="M3 5h14M3 10h14M3 15h14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            </button>
            <h1 class="page-title">${esc(title ?? page)}</h1>
            <span class="spacer"></span>
            ${hint ? `<span class="page-hint">${esc(hint)}</span>` : ''}
          </header>
          <section class="content" id="content"></section>
        </main>
      </div>`;

    document.getElementById('logout')?.addEventListener('click', async () => {
      try { await wb.get('auth.php?action=logout'); } catch {}
      location.href = 'login.html';
    });

    /* Drawer (mobile/tablet): burger opens the sidebar, backdrop/Escape/link-tap closes it */
    const burger = document.getElementById('burger');
    const backdrop = document.getElementById('sb-backdrop');
    const setDrawer = (open) => {
      document.body.classList.toggle('sb-open', open);
      burger?.setAttribute('aria-expanded', String(open));
    };
    burger?.addEventListener('click', () => setDrawer(!document.body.classList.contains('sb-open')));
    backdrop?.addEventListener('click', () => setDrawer(false));
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') setDrawer(false); });
    document.querySelectorAll('.sidebar .nav a').forEach((a) => a.addEventListener('click', () => setDrawer(false)));
    const mqDrawer = window.matchMedia('(max-width: 900px)');
    if (mqDrawer.addEventListener) {
      mqDrawer.addEventListener('change', (e) => { if (!e.matches) setDrawer(false); });
    } else if (mqDrawer.addListener) {
      mqDrawer.addListener((e) => { if (!e.matches) setDrawer(false); });
    }
  }

  /* Session guard + owner name rendering */
  async function guard() {
    try {
      const me = await wb.get('auth.php?action=me');
      const who = document.getElementById('who');
      if (who) who.textContent = me.full_name || me.username;
      return me;
    } catch (e) {
      if (e.message !== 'Session expired') console.warn(e);
      location.href = 'login.html';
      throw e;
    }
  }

  /* empty-state row helper */
  const emptyRow = (cols, msg = 'Nothing here yet.') =>
    `<tr class="empty-row"><td colspan="${cols}">${esc(msg)}</td></tr>`;

  return {
    esc, money, qty, date, badge, statusTone, typeTone,
    toast, confirm, modal, shell, guard, emptyRow
  };
})();
