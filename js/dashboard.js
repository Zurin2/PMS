/* PMS — dashboard: KPI strip, low stock table, quick links */
(async () => {
  UI.shell('dashboard', 'Dashboard');
  await UI.guard();

  const content = document.getElementById('content');
  const LOW_COLS = 5;
  const TOP_COLS = 4;

  // Skeleton first (with empty states) so a failed API call never breaks the page.
  content.innerHTML = `
    <div class="kpi-grid">
      <div class="kpi positive"><div class="kpi-label">Inventory Valuation</div><div class="kpi-value" id="k-val">—</div><div class="kpi-note">Stock on hand × unit price</div></div>
      <div class="kpi"><div class="kpi-label">Total Items</div><div class="kpi-value" id="k-items">—</div><div class="kpi-note">In master list</div></div>
      <div class="kpi"><div class="kpi-label">Stock On Hand</div><div class="kpi-value" id="k-soh">—</div><div class="kpi-note">Units, active items</div></div>
      <div class="kpi"><div class="kpi-label">Low Stock</div><div class="kpi-value" id="k-low">—</div><div class="kpi-note">At or below low-stock level</div></div>
      <div class="kpi positive"><div class="kpi-label">Revenue YTD</div><div class="kpi-value" id="k-rev">—</div><div class="kpi-note">Delivered orders, this year</div></div>
      <div class="kpi positive"><div class="kpi-label">Revenue This Month</div><div class="kpi-value" id="k-mrev">—</div><div class="kpi-note">Delivered orders</div></div>
      <div class="kpi positive"><div class="kpi-label">Collected YTD</div><div class="kpi-value" id="k-col">—</div><div class="kpi-note">Payments this year</div></div>
      <div class="kpi"><div class="kpi-label">Outstanding</div><div class="kpi-value" id="k-out">—</div><div class="kpi-note">Unpaid delivered balances</div></div>
      <div class="kpi"><div class="kpi-label">Deliveries YTD</div><div class="kpi-value" id="k-dlv">—</div><div class="kpi-note">Recorded this year</div></div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px;align-items:start;margin-bottom:14px">
      <div class="card">
        <div class="card-head"><h2>Revenue — Last 6 Months</h2></div>
        <div class="card-body">
          <div class="bars" id="trend-bars"></div>
          <p class="hint" style="margin-top:10px" id="trend-foot"></p>
        </div>
      </div>

      <div class="card">
        <div class="card-head">
          <h2>Top Items — Outgoing (90 days)</h2>
          <span class="grow"></span>
          <a class="btn ghost" href="items.html">View items</a>
        </div>
        <div class="card-body flush tablewrap">
          <table class="grid">
            <thead>
              <tr><th>Code</th><th>Name</th><th>Category</th><th class="num">Outgoing</th></tr>
            </thead>
            <tbody id="top-tbody">${UI.emptyRow(TOP_COLS, 'Loading…')}</tbody>
          </table>
        </div>
      </div>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:14px;align-items:start">
      <div class="card">
        <div class="card-head">
          <h2>Low Stock</h2>
          <span class="grow"></span>
          <a class="btn ghost" href="items.html">View items</a>
        </div>
        <div class="card-body flush tablewrap">
          <table class="grid">
            <thead>
              <tr><th>Code</th><th>Name</th><th>Category</th><th class="num">On hand</th><th class="num">Low-stock level</th></tr>
            </thead>
            <tbody id="low-tbody">${UI.emptyRow(LOW_COLS, 'Loading…')}</tbody>
          </table>
        </div>
      </div>

      <div class="card">
        <div class="card-head"><h2>Quick Links</h2></div>
        <div class="card-body" style="display:flex;flex-direction:column;gap:6px">
          <a class="btn ghost" href="stock_in.html" style="justify-content:flex-start">Stock In</a>
          <a class="btn ghost" href="delivery_new.html" style="justify-content:flex-start">New Delivery</a>
          <a class="btn ghost" href="payments.html" style="justify-content:flex-start">Payments</a>
          <a class="btn ghost" href="reports.html" style="justify-content:flex-start">Reports</a>
        </div>
      </div>
    </div>`;

  const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };

  try {
    const d = await wb.get('dashboard.php');
    set('k-val', UI.money(d.inventory_valuation));
    set('k-items', UI.qty(d.active_items));
    set('k-soh', UI.qty(d.stock_on_hand_units));
    set('k-low', UI.qty(d.low_stock_items));
    set('k-rev', UI.money(d.revenue_total_ytd));
    set('k-mrev', UI.money(d.revenue_this_month));
    set('k-col', UI.money(d.collected_ytd));
    set('k-out', UI.money(d.outstanding));
    set('k-dlv', UI.qty(d.deliveries_this_year));

    // Revenue trend bars (pure CSS; heights proportional to the best month)
    const trend = Array.isArray(d.revenue_trend) ? d.revenue_trend : [];
    const maxT = Math.max(0, ...trend.map(m => Number(m.total) || 0));
    document.getElementById('trend-bars').innerHTML = trend.map(m => {
      const t = Number(m.total) || 0;
      const h = maxT > 0 ? Math.max(2, Math.round((t / maxT) * 100)) : 0;
      return `<div class="bar">
        <span class="bar-val">${t > 0 ? '₱' + (t >= 100000 ? (t / 1000).toFixed(0) + 'k' : t.toLocaleString('en-PH', { maximumFractionDigits: 0 })) : '—'}</span>
        <div class="bar-track"><div class="bar-fill" style="height:${h}%"></div></div>
        <span class="bar-label">${UI.esc(m.label)}</span>
      </div>`;
    }).join('');
    const trendTotal = trend.reduce((s, m) => s + (Number(m.total) || 0), 0);
    document.getElementById('trend-foot').textContent =
      `6-month total: ₱${trendTotal.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

    // Top items table (90 days)
    const top = (Array.isArray(d.top_items) ? d.top_items : []);
    const trows = top.map(r => {
      const q = Number(r.out_qty) || 0;
      return `<tr>
        <td>${UI.esc(r.item_code)}</td>
        <td>${UI.esc(r.name)}</td>
        <td>${UI.esc(r.category)}</td>
        <td class="num"><strong>${UI.qty(q)}</strong> ${UI.esc(r.unit)}</td>
      </tr>`;
    });
    document.getElementById('top-tbody').innerHTML = trows.length
      ? trows.join('')
      : UI.emptyRow(TOP_COLS, 'No outgoing movements in the last 90 days.');

    const rows = (Array.isArray(d.low_stock) ? d.low_stock : []).map(r => {
      const soh = Number(r.stock_on_hand) || 0;
      return `<tr>
        <td>${UI.esc(r.item_code)}</td>
        <td>${UI.esc(r.name)}</td>
        <td>${UI.esc(r.category)}</td>
        <td class="num">${soh <= 0 ? `<span class="tag-low">${UI.qty(soh)}</span>` : UI.qty(soh)}</td>
        <td class="num">${UI.qty(r.low_stock_level)}</td>
      </tr>`;
    });
    document.getElementById('low-tbody').innerHTML = rows.length ? rows.join('') : UI.emptyRow(LOW_COLS, 'No low-stock items. All good.');
  } catch (ex) {
    UI.toast(ex.message, 'err');
    document.getElementById('low-tbody').innerHTML = UI.emptyRow(LOW_COLS, 'Could not load dashboard data.');
  }
})();
