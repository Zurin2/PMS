/* PMS — stock in: record received inventory + receipt history */
(async () => {
  UI.shell('stock_in', 'Stock In', 'Record received inventory');
  await UI.guard();

  const content = document.getElementById('content');
  const today = () => {
    const d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  };
  const COLS = 6;

  content.innerHTML = `
    <div class="card">
      <form id="receipt-form" class="toolbar inline-form" autocomplete="off">
        <div class="col" style="width:250px">
          <label class="fl" for="s-item">Item</label>
          <select id="s-item"><option value="">— select item —</option></select>
        </div>
        <div class="col narrow">
          <label class="fl" for="s-qty">Quantity</label>
          <input type="number" id="s-qty" min="1" step="1">
        </div>
        <div class="col">
          <label class="fl" for="s-date">Date received</label>
          <input type="date" id="s-date" value="${today()}">
        </div>
        <div class="col" style="width:230px">
          <label class="fl" for="s-remarks">Remarks</label>
          <input type="text" id="s-remarks" placeholder="optional notes">
        </div>
        <span style="flex:1"></span>
        <button class="btn primary" id="s-save" type="submit">Record Receipt</button>
      </form>
      <div class="card-head"><h2>Receipt log</h2><span class="grow"></span>
        <input type="search" id="r-search" placeholder="Search receipts…"></div>
      <div class="tablewrap">
        <table class="grid">
          <thead>
            <tr><th>Date</th><th>Item code</th><th>Item name</th><th class="num">Qty</th><th>Remarks</th><th></th></tr>
          </thead>
          <tbody id="r-tbody">
            <tr class="empty-row"><td colspan="${COLS}">Loading…</td></tr>
          </tbody>
        </table>
      </div>
    </div>`;

  const $ = (id) => document.getElementById(id);
  UI.addSearch('r-search', $('r-tbody'));

  /* ---------- data (item list is cached; only receipts refresh) ---------- */
  const itemById = new Map();
  try {
    const items = (await wb.get('items.php')) || [];
    for (const it of items) itemById.set(it.item_id, it);
    $('s-item').innerHTML = '<option value="">— select item —</option>' + items
      .filter(it => it.status === 'ACTIVE')
      .map(it => `<option value="${it.item_id}">${UI.esc(it.item_code)} — ${UI.esc(it.name)}</option>`)
      .join('');
  } catch (ex) {
    UI.toast(ex.message, 'err');
  }

  async function loadReceipts() {
    try {
      const rows = (await wb.get('stock_in.php')) || [];
      $('r-tbody').innerHTML = rows.length ? rows.map(r => `<tr>
          <td>${UI.date(r.date_received)}</td>
          <td><code>${UI.esc((itemById.get(r.item_id) || {}).item_code || '—')}</code></td>
          <td>${UI.esc(r.item_name)}</td>
          <td class="num">${UI.qty(r.quantity)}</td>
          <td>${r.remarks ? UI.esc(r.remarks) : '<span style="color:var(--faint)">—</span>'}</td>
          <td><button class="btn sm danger" type="button" data-del="${r.stock_in_id}">Delete</button></td>
        </tr>`).join('') : UI.emptyRow(COLS, 'No receipts recorded yet.');
    } catch (ex) {
      UI.toast(ex.message, 'err');
      $('r-tbody').innerHTML = UI.emptyRow(COLS, 'Could not load receipts.');
    }
  }

  try { await loadReceipts(); } catch (ex) { UI.toast(ex.message, 'err'); }

  /* ---------- record receipt ---------- */
  $('receipt-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const item_id = Number($('s-item').value);
    const quantity = Number($('s-qty').value);
    const date_received = $('s-date').value;
    if (!item_id || !(quantity > 0) || !date_received) {
      UI.toast('Select an item, enter a quantity above 0 and pick a date.', 'err');
      return;
    }
    const btn = $('s-save');
    btn.disabled = true;
    try {
      await wb.post('stock_in.php', { item_id, quantity, date_received, remarks: $('s-remarks').value.trim() });
      UI.toast('Stock recorded');
      $('receipt-form').reset();
      $('s-date').value = today();
      await loadReceipts();
    } catch (ex) {
      UI.toast(ex.message, 'err');
    } finally {
      btn.disabled = false;
    }
  });

  /* ---------- delete receipt ---------- */
  $('r-tbody').addEventListener('click', async (e) => {
    const delBtn = e.target.closest('[data-del]');
    if (!delBtn) return;
    const ok = await UI.confirm('Delete this receipt? Stock on hand will be reduced and cannot go negative.', { danger: true });
    if (!ok) return;
    try {
      await wb.del('stock_in.php?id=' + delBtn.dataset.del);
      UI.toast('Receipt deleted');
      await loadReceipts();
    } catch (ex) {
      UI.toast(ex.message, 'err'); // e.g. trigger guard: stock would go negative -> 409
    }
  });
})();
