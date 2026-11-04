/* PMS — Physical Count: audit stock by counting vs system qty */
(async () => {
  UI.shell('count', 'Physical Count');
  await UI.guard();
  const content = document.getElementById('content');

  const today = () => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  };

  content.innerHTML = `
    <div class="card" style="margin-bottom:16px">
      <div class="card-head"><h2>Record physical count</h2></div>
      <div class="card-body">
        <div class="field" style="max-width:420px">
          <label class="fl" for="ctItem">Item <span class="req">*</span></label>
          <select id="ctItem"><option value="">— loading —</option></select>
        </div>
        <div id="ctMetaBox" style="display:none;margin:6px 0 16px">
          <div class="meta-grid" style="max-width:560px" id="ctMeta"></div>
          <div class="kpi" style="max-width:220px;margin-top:12px">
            <div class="kpi-label">System qty</div>
            <div class="kpi-value" id="ctSysQty">0</div>
          </div>
        </div>
        <div class="form-grid" style="max-width:640px">
          <div class="field"><label class="fl" for="ctCounted">Counted quantity <span class="req">*</span></label>
            <input type="number" id="ctCounted" min="0" step="1" placeholder="0"></div>
          <div class="field"><label class="fl" for="ctDate">Adjust date <span class="req">*</span></label>
            <input type="date" id="ctDate" value="${today()}"></div>
        </div>
        <div class="field" style="max-width:640px"><label class="fl" for="ctReason">Reason</label>
          <input type="text" id="ctReason" placeholder="e.g. damaged, expired, miscount"></div>
        <button class="btn primary" id="ctSubmit" type="button">Record Count</button>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h2>Recent adjustments</h2></div>
      <div class="card-body flush"><div class="tablewrap">
        <table class="grid">
          <thead><tr>
            <th>Date</th><th>Item</th><th class="num">System qty</th><th class="num">Counted qty</th>
            <th>Variance</th><th>Reason</th>
          </tr></thead>
          <tbody id="ctRows">${UI.emptyRow(6, 'Loading…')}</tbody>
        </table>
      </div></div>
    </div>`;

  const sel = document.getElementById('ctItem');
  const metaBox = document.getElementById('ctMetaBox');
  const meta = document.getElementById('ctMeta');
  const sysQty = document.getElementById('ctSysQty');
  const countedEl = document.getElementById('ctCounted');
  const dateEl = document.getElementById('ctDate');
  const reasonEl = document.getElementById('ctReason');
  const submitBtn = document.getElementById('ctSubmit');
  const tbody = document.getElementById('ctRows');

  let items = [];
  const itemsById = new Map();

  const mv = (l, v) => `<div><div class="lbl">${l}</div><div class="val">${UI.esc(v)}</div></div>`;

  async function loadItems() {
    const current = sel.value;
    try {
      const r = await wb.get('items.php?status=ACTIVE');
      items = Array.isArray(r) ? r : (r.items || []);
      itemsById.clear();
      items.forEach(i => itemsById.set(Number(i.item_id), i));
      sel.innerHTML = '<option value="">— choose item —</option>' + items.map(i =>
        `<option value="${Number(i.item_id)}">${UI.esc(i.item_code)} — ${UI.esc(i.name)} (on hand ${UI.qty(i.stock_on_hand)})</option>`
      ).join('');
      if (current) sel.value = current;
      applySel();
    } catch (ex) {
      UI.toast(ex.message, 'err');
      sel.innerHTML = '<option value="">— load failed —</option>';
    }
  }

  function applySel() {
    const it = itemsById.get(Number(sel.value));
    if (!it) { metaBox.style.display = 'none'; return; }
    metaBox.style.display = '';
    meta.innerHTML =
      mv('Code', it.item_code ?? '—') +
      mv('Name', it.name ?? '—') +
      mv('Unit', it.unit ?? '—');
    sysQty.textContent = UI.qty(it.stock_on_hand);
  }

  sel.addEventListener('change', applySel);

  submitBtn.addEventListener('click', async () => {
    const item_id = Number(sel.value);
    if (!item_id) { UI.toast('Choose an item', 'warn'); return; }
    const counted = countedEl.value;
    if (counted === '' || Number(counted) < 0) { UI.toast('Counted quantity must be 0 or more', 'warn'); return; }
    const adjust_date = dateEl.value;
    if (!adjust_date) { UI.toast('Adjust date is required', 'warn'); return; }
    submitBtn.disabled = true;
    try {
      await wb.post('adjustments.php', {
        item_id,
        counted_quantity: Number(counted),
        adjust_date,
        reason: reasonEl.value.trim() || null
      });
      UI.toast('Adjustment recorded');
      countedEl.value = '';
      reasonEl.value = '';
      await loadAdjustments();
      await loadItems(); /* refresh on-hand figures */
    } catch (ex) {
      UI.toast(ex.message, 'err');
    } finally { submitBtn.disabled = false; }
  });

  function varBadge(v) {
    const n = Number(v || 0);
    if (n > 0) return UI.badge(`+${UI.qty(n)} OVER`, 'pos');
    if (n < 0) return UI.badge(`${UI.qty(n)} SHORT`, 'neg');
    return UI.badge('MATCHED', 'mute');
  }

  async function loadAdjustments() {
    try {
      const r = await wb.get('adjustments.php');
      const rows = Array.isArray(r) ? r : (r.adjustments || []);
      tbody.innerHTML = rows.length ? rows.map(a => `<tr>
        <td>${UI.date(a.adjust_date)}</td>
        <td>${UI.esc(a.item_code ?? '')} — ${UI.esc(a.name ?? '')}</td>
        <td class="num">${UI.qty(a.system_qty)}</td>
        <td class="num">${UI.qty(a.counted_qty)}</td>
        <td>${varBadge(a.variance)}</td>
        <td>${UI.esc(a.reason ?? '—')}</td>
      </tr>`).join('') : UI.emptyRow(6, 'No adjustments recorded yet.');
    } catch (ex) {
      UI.toast(ex.message, 'err');
      tbody.innerHTML = UI.emptyRow(6, 'Could not load adjustments.');
    }
  }

  await loadItems();
  await loadAdjustments();
})();
