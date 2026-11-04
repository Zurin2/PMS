/* PMS — Stock Card: item picker + chronological movement ledger */
(async () => {
  UI.shell('stock_card', 'Stock Card');
  await UI.guard();
  const content = document.getElementById('content');

  content.innerHTML = `
    <div class="card" style="margin-bottom:16px">
      <div class="toolbar no-print">
        <div style="flex:1;min-width:260px;max-width:420px">
          <label class="fl" for="scItem">Item</label>
          <select id="scItem"><option value="">— loading —</option></select>
        </div>
        <div class="col"><label class="fl" for="scFrom">From</label><input type="date" id="scFrom"></div>
        <div class="col"><label class="fl" for="scTo">To</label><input type="date" id="scTo"></div>
        <button class="btn primary" id="scShow" type="button">Show Ledger</button>
      </div>
    </div>

    <div class="card" id="scMetaCard" style="margin-bottom:16px;display:none">
      <div class="card-body"><div class="meta-grid" id="scMeta"></div></div>
    </div>

    <div class="card">
      <div class="card-head"><h2>Ledger</h2><span class="grow"></span><span class="hint">IN → OUT → ADJ order within a date</span></div>
      <div class="card-body flush"><div class="tablewrap">
        <table class="grid">
          <thead><tr>
            <th>Date</th><th>Type</th>
            <th class="num">Qty in</th><th class="num">Qty out</th><th class="num">Balance</th>
          </tr></thead>
          <tbody id="scRows">${UI.emptyRow(5, 'Select an item and click Show Ledger.')}</tbody>
        </table>
      </div></div>
    </div>`;

  const sel = document.getElementById('scItem');
  const from = document.getElementById('scFrom');
  const to = document.getElementById('scTo');
  const metaCard = document.getElementById('scMetaCard');
  const meta = document.getElementById('scMeta');
  const rowsEl = document.getElementById('scRows');
  const showBtn = document.getElementById('scShow');

  const mv = (l, v) => `<div><div class="lbl">${l}</div><div class="val">${UI.esc(v)}</div></div>`;

  async function loadPicker() {
    try {
      const r = await wb.get('stock_card.php');
      const items = Array.isArray(r) ? r : (r.items || []);
      sel.innerHTML = '<option value="">— choose item —</option>' + items.map(it =>
        `<option value="${Number(it.item_id)}">${UI.esc(it.item_code)} — ${UI.esc(it.name)} (${UI.qty(it.stock_on_hand)})</option>`
      ).join('');
    } catch (ex) { UI.toast(ex.message, 'err'); sel.innerHTML = '<option value="">— load failed —</option>'; }
  }

  async function show() {
    const id = sel.value;
    if (!id) { UI.toast('Choose an item first', 'warn'); return; }
    const p = new URLSearchParams({ item_id: id });
    if (from.value) p.set('from', from.value);
    if (to.value) p.set('to', to.value);
    showBtn.disabled = true;
    rowsEl.innerHTML = UI.emptyRow(5, 'Loading ledger…');
    try {
      const r = await wb.get('stock_card.php?' + p.toString());
      const it = r.item || {};
      metaCard.style.display = '';
      meta.innerHTML =
        mv('Code', it.item_code ?? '—') +
        mv('Name', it.name ?? '—') +
        mv('Category', it.category ?? '—') +
        mv('Unit', it.unit ?? '—') +
        mv('Stock on hand', UI.qty(it.stock_on_hand));
      const entries = r.entries || [];
      rowsEl.innerHTML = entries.length ? entries.map(e => {
        const signed = Number(e.qty || 0);
        const isIn = signed >= 0;
        const tone = e.move_type === 'IN' ? 'pos' : e.move_type === 'OUT' ? 'neg' : 'info';
        return `<tr>
          <td>${UI.date(e.move_date)}</td>
          <td>${UI.badge(e.move_type, tone)}</td>
          <td class="num">${isIn ? UI.qty(signed) : ''}</td>
          <td class="num">${isIn ? '' : UI.qty(Math.abs(signed))}</td>
          <td class="num">${UI.qty(e.balance)}</td>
        </tr>`;
      }).join('') : UI.emptyRow(5, 'No movements recorded for this item in the selected range.');
    } catch (ex) {
      UI.toast(ex.message, 'err');
      rowsEl.innerHTML = UI.emptyRow(5, 'Could not load ledger.');
    } finally { showBtn.disabled = false; }
  }

  showBtn.addEventListener('click', show);
  sel.addEventListener('change', show);
  await loadPicker();
})();
