/* PMS — dashboard: KPI strip, low stock table, quick links */
(async () => {
  UI.shell('dashboard', 'Dashboard');
  await UI.guard();

  const content = document.getElementById('content');
  const LOW_COLS = 5;

  // Skeleton first (with empty states) so a failed API call never breaks the page.
  content.innerHTML = `
    <div class="kpi-grid">
      <div class="kpi positive"><div class="kpi-label">Revenue YTD</div><div class="kpi-value" id="k-rev">—</div><div class="kpi-note">Delivered orders, this year</div></div>
      <div class="kpi positive"><div class="kpi-label">Collected YTD</div><div class="kpi-value" id="k-col">—</div><div class="kpi-note">Payments this year</div></div>
      <div class="kpi"><div class="kpi-label">Outstanding</div><div class="kpi-value" id="k-out">—</div><div class="kpi-note">Unpaid delivered balances</div></div>
      <div class="kpi"><div class="kpi-label">Active Items</div><div class="kpi-value" id="k-items">—</div><div class="kpi-note">In master list</div></div>
      <div class="kpi"><div class="kpi-label">Low Stock</div><div class="kpi-value" id="k-low">—</div><div class="kpi-note">At or below low-stock level</div></div>
      <div class="kpi"><div class="kpi-label">Deliveries YTD</div><div class="kpi-value" id="k-dlv">—</div><div class="kpi-note">Recorded this year</div></div>
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
    set('k-rev', UI.money(d.revenue_total_ytd));
    set('k-col', UI.money(d.collected_ytd));
    set('k-out', UI.money(d.outstanding));
    set('k-items', UI.qty(d.active_items));
    set('k-low', UI.qty(d.low_stock_items));
    set('k-dlv', UI.qty(d.deliveries_this_year));

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
