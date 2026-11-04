/* PMS — Reports: movement matrix / summary KPIs / monthly revenue */
(async () => {
  UI.shell('reports', 'Reports');
  await UI.guard();
  const content = document.getElementById('content');

  const year = new Date().getFullYear();
  const today = () => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  };

  content.innerHTML = `
    <div class="card" style="margin-bottom:16px">
      <div class="toolbar no-print">
        <div class="col" style="width:200px">
          <label class="fl" for="rpType">Report</label>
          <select id="rpType">
            <option value="matrix">Movement matrix</option>
            <option value="summary">Summary</option>
            <option value="revenue">Revenue</option>
          </select>
        </div>
        <div class="col"><label class="fl" for="rpFrom">From</label><input type="date" id="rpFrom" value="${year}-01-01"></div>
        <div class="col"><label class="fl" for="rpTo">To</label><input type="date" id="rpTo" value="${today()}"></div>
        <button class="btn primary" id="rpRun" type="button">Run</button>
        <button class="btn" id="rpPrint" type="button">Print</button>
      </div>
    </div>
    <div id="rpOut"></div>`;

  const typeEl = document.getElementById('rpType');
  const fromEl = document.getElementById('rpFrom');
  const toEl = document.getElementById('rpTo');
  const runBtn = document.getElementById('rpRun');
  const out = document.getElementById('rpOut');

  runBtn.addEventListener('click', run);
  document.getElementById('rpPrint').addEventListener('click', () => window.print());
  typeEl.addEventListener('change', run);

  function loadingHtml() {
    return `<div class="card"><div class="card-body"><p style="color:var(--muted);margin:0">Generating report…</p></div></div>`;
  }
  const range = () => `${UI.date(fromEl.value)} – ${UI.date(toEl.value)}`;
  const num = (n) => UI.qty(n);

  function kpiCard(label, value, strong) {
    return `<div class="kpi${strong ? ' positive' : ''}"><div class="kpi-label">${label}</div><div class="kpi-value">${value}</div></div>`;
  }

  async function run() {
    if (!fromEl.value || !toEl.value) { UI.toast('From and to dates are required', 'warn'); return; }
    const type = typeEl.value;
    out.innerHTML = loadingHtml();
    try {
      const r = await wb.get(`reports.php?report=${type}&from=${fromEl.value}&to=${toEl.value}`);
      if (type === 'matrix') renderMatrix(Array.isArray(r) ? r : (r.rows || []));
      else if (type === 'summary') renderSummary(r || {});
      else renderRevenue(Array.isArray(r) ? r : (r.rows || []));
    } catch (ex) {
      UI.toast(ex.message, 'err');
      out.innerHTML = `<div class="card"><div class="card-body"><p style="color:var(--danger);margin:0">${UI.esc(ex.message)}</p></div></div>`;
    }
  }

  function renderMatrix(rows) {
    const S = (k) => rows.reduce((s, r) => s + Number(r[k] || 0), 0);
    out.innerHTML = `
      <div class="card">
        <div class="card-head"><h2>Stock movement matrix</h2><span class="grow"></span><span class="hint">${range()}</span></div>
        <div class="card-body flush"><div class="tablewrap">
          <table class="grid">
            <thead><tr>
              <th>Code</th><th>Name</th><th>Category</th><th>Unit</th>
              <th class="num">Beginning</th><th class="num">In</th><th class="num">Out</th>
              <th class="num">Adj</th><th class="num">Ending</th>
            </tr></thead>
            <tbody>
              ${rows.length ? rows.map(r => `<tr>
                <td class="mono">${UI.esc(r.item_code)}</td>
                <td>${UI.esc(r.name)}</td>
                <td>${UI.esc(r.category)}</td>
                <td>${UI.esc(r.unit)}</td>
                <td class="num">${num(r.beginning)}</td>
                <td class="num">${num(r.in_qty)}</td>
                <td class="num">${num(r.out_qty)}</td>
                <td class="num">${num(r.adj)}</td>
                <td class="num">${num(r.ending)}</td>
              </tr>`).join('') : UI.emptyRow(9, 'No movement data for this period.')}
              ${rows.length ? `<tr>
                <td colspan="4"><strong>TOTAL</strong></td>
                <td class="num"><strong>${num(S('beginning'))}</strong></td>
                <td class="num"><strong>${num(S('in_qty'))}</strong></td>
                <td class="num"><strong>${num(S('out_qty'))}</strong></td>
                <td class="num"><strong>${num(S('adj'))}</strong></td>
                <td class="num"><strong>${num(S('ending'))}</strong></td>
              </tr>` : ''}
            </tbody>
          </table>
        </div></div>
      </div>`;
  }

  function renderSummary(r) {
    out.innerHTML = `
      <div class="card" style="margin-bottom:16px">
        <div class="card-head"><h2>Summary</h2><span class="grow"></span><span class="hint">${range()}</span></div>
        <div class="card-body"><div class="kpi-grid" style="margin-bottom:0">
          ${kpiCard('Revenue', UI.money(r.revenue), true)}
          ${kpiCard('Collected', UI.money(r.collected), true)}
          ${kpiCard('Deliveries', UI.qty(r.deliveries_count))}
          ${kpiCard('Outflow value', UI.money(r.outflow_value))}
        </div></div>
      </div>`;
  }

  function renderRevenue(rows) {
    out.innerHTML = `
      <div class="card">
        <div class="card-head"><h2>Revenue by month</h2><span class="grow"></span><span class="hint">${range()}</span></div>
        <div class="card-body flush"><div class="tablewrap">
          <table class="grid">
            <thead><tr><th>Month</th><th class="num">Revenue</th><th class="num">Collected</th><th class="num">Deliveries</th></tr></thead>
            <tbody>${rows.length ? rows.map(r => `<tr>
              <td>${UI.esc(r.ym)}</td>
              <td class="num">${UI.money(r.revenue)}</td>
              <td class="num">${UI.money(r.collected)}</td>
              <td class="num">${num(r.deliveries_count)}</td>
            </tr>`).join('') : UI.emptyRow(4, 'No revenue data for this period.')}
            </tbody>
          </table>
        </div></div>
      </div>`;
  }

  await run();
})();
