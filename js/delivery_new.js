/* PMS — New Delivery: fulfillment form (DIRECT/BIDDING) + dynamic line items */
(async () => {
  UI.shell('deliveries', 'New Delivery');
  await UI.guard();
  const content = document.getElementById('content');

  const today = () => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  };

  content.innerHTML = `
    <div class="card" style="margin-bottom:16px">
      <div class="card-head"><h2>Delivery details</h2></div>
      <div class="card-body">
        <div class="form-grid">
          <div class="field">
            <label class="fl" for="dnCust">Customer <span class="req">*</span></label>
            <select id="dnCust"><option value="">Loading…</option></select>
          </div>
          <div class="field">
            <label class="fl" for="dnDate">Delivery date <span class="req">*</span></label>
            <input type="date" id="dnDate" value="${today()}">
          </div>
        </div>
        <div class="field">
          <label class="fl">Order type <span class="req">*</span></label>
          <label style="margin-right:18px"><input type="radio" name="dnOtype" value="DIRECT" checked> DIRECT</label>
          <label><input type="radio" name="dnOtype" value="BIDDING"> BIDDING</label>
        </div>
        <div class="form-grid" id="dnDirect">
          <div class="field"><label class="fl" for="dnPo">PO number <span class="req">*</span></label>
            <input type="text" id="dnPo"></div>
          <div class="field"><label class="fl" for="dnPoDate">PO date</label>
            <input type="date" id="dnPoDate"></div>
        </div>
        <div class="form-grid" id="dnBid" style="display:none">
          <div class="field"><label class="fl" for="dnBidRef">Bid ref no <span class="req">*</span></label>
            <input type="text" id="dnBidRef"></div>
          <div class="field"><label class="fl" for="dnDeadline">Delivery deadline</label>
            <input type="date" id="dnDeadline"></div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-head">
        <h2>Line items</h2>
        <span class="grow"></span>
        <button class="btn ghost" type="button" id="dnAddItem">＋ Add item</button>
      </div>
      <div class="card-body flush"><div class="tablewrap">
        <table class="grid">
          <thead><tr>
            <th style="width:44%">Item</th><th style="width:12%">Qty</th><th style="width:16%">Unit price</th>
            <th class="num">Line total</th><th></th>
          </tr></thead>
          <tbody id="dnRows"></tbody>
        </table>
      </div></div>
      <div class="card-body" style="display:flex;justify-content:flex-end;align-items:center;gap:18px;border-top:1px solid var(--line)">
        <span class="lbl" style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:var(--muted)">Total</span>
        <strong style="font-size:16px" id="dnTotal">₱0.00</strong>
        <button class="btn primary" id="dnSubmit" type="button">Create Delivery</button>
      </div>
    </div>`;

  const custSel = document.getElementById('dnCust');
  const dateEl = document.getElementById('dnDate');
  const directBox = document.getElementById('dnDirect');
  const bidBox = document.getElementById('dnBid');
  const rowsEl = document.getElementById('dnRows');
  const addItemBtn = document.getElementById('dnAddItem');
  const submitBtn = document.getElementById('dnSubmit');

  let items = [];
  const itemsById = new Map();

  /* ---- Reference data ---- */
  try {
    const [cs, it] = await Promise.allSettled([
      wb.get('customers.php'),
      wb.get('items.php?status=ACTIVE')
    ]);
    if (cs.status === 'fulfilled') {
      const custs = Array.isArray(cs.value) ? cs.value : (cs.value.customers || []);
      const opts = (type) => custs.filter(c => c.customer_type === type)
        .map(c => `<option value="${Number(c.customer_id)}">${UI.esc(c.name)}</option>`).join('');
      custSel.innerHTML = `<option value="">— select customer —</option>
        <optgroup label="SCHOOL">${opts('SCHOOL')}</optgroup>
        <optgroup label="OFFICE">${opts('OFFICE')}</optgroup>`;
    } else { UI.toast(cs.reason.message, 'err'); }

    if (it.status === 'fulfilled') {
      items = Array.isArray(it.value) ? it.value : (it.value.items || []);
      items.forEach(i => itemsById.set(Number(i.item_id), i));
    } else { UI.toast(it.reason.message, 'err'); }
  } catch (ex) { UI.toast(ex.message, 'err'); }

  const itemOpts = items.map(i =>
    `<option value="${Number(i.item_id)}">${UI.esc(i.item_code)} — ${UI.esc(i.name)}</option>`).join('');

  /* ---- Order type toggle ---- */
  const applyType = () => {
    const t = document.querySelector('input[name="dnOtype"]:checked')?.value;
    directBox.style.display = t === 'DIRECT' ? '' : 'none';
    bidBox.style.display = t === 'BIDDING' ? '' : 'none';
  };
  document.querySelectorAll('input[name="dnOtype"]').forEach(r => r.addEventListener('change', applyType));

  /* ---- Line item rows ---- */
  function addItemRow() {
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td><select class="dn-item"><option value="">— select item —</option>${itemOpts}</select></td>
      <td><input type="number" class="dn-qty" min="1" step="1" value="1"></td>
      <td><input type="number" class="dn-price" min="0" step="0.01" value="0"></td>
      <td class="num dn-line">₱0.00</td>
      <td style="text-align:right;white-space:nowrap">
        <button class="btn sm" type="button" data-rm title="Remove row">✕</button></td>`;
    rowsEl.appendChild(tr);
  }

  function recalc() {
    let total = 0;
    rowsEl.querySelectorAll('tr').forEach(tr => {
      const q = Number(tr.querySelector('.dn-qty').value) || 0;
      const pr = Number(tr.querySelector('.dn-price').value) || 0;
      const line = q * pr;
      tr.querySelector('.dn-line').textContent = UI.money(line);
      total += line;
    });
    document.getElementById('dnTotal').textContent = UI.money(total);
  }

  addItemBtn.addEventListener('click', addItemRow);
  rowsEl.addEventListener('change', (e) => {
    if (!e.target.classList.contains('dn-item')) return;
    const it = itemsById.get(Number(e.target.value));
    if (it) e.target.closest('tr').querySelector('.dn-price').value = it.unit_price;
    recalc();
  });
  rowsEl.addEventListener('input', (e) => {
    if (e.target.classList.contains('dn-qty') || e.target.classList.contains('dn-price')) recalc();
  });
  rowsEl.addEventListener('click', (e) => {
    const b = e.target.closest('button[data-rm]');
    if (!b) return;
    b.closest('tr').remove();
    if (!rowsEl.querySelector('tr')) addItemRow();
    recalc();
  });

  addItemRow();
  recalc();

  /* ---- Submit ---- */
  submitBtn.addEventListener('click', async () => {
    const customer_id = Number(custSel.value);
    if (!customer_id) { UI.toast('Choose a customer', 'warn'); return; }
    const delivery_date = dateEl.value;
    if (!delivery_date) { UI.toast('Delivery date is required', 'warn'); return; }
    const order_type = document.querySelector('input[name="dnOtype"]:checked')?.value || '';
    if (!order_type) { UI.toast('Order type is required', 'warn'); return; }

    const body = { customer_id, delivery_date, order_type, items: [] };
    if (order_type === 'DIRECT') {
      body.po_number = document.getElementById('dnPo').value.trim();
      body.po_date = document.getElementById('dnPoDate').value;
      if (!body.po_number || !body.po_date) { UI.toast('PO number and PO date are required for DIRECT orders', 'warn'); return; }
    } else {
      body.bid_ref_no = document.getElementById('dnBidRef').value.trim();
      body.delivery_deadline = document.getElementById('dnDeadline').value;
      if (!body.bid_ref_no || !body.delivery_deadline) { UI.toast('Bid ref no and delivery deadline are required for BIDDING orders', 'warn'); return; }
    }

    const seen = new Set();
    const trs = [...rowsEl.querySelectorAll('tr')];
    for (const tr of trs) {
      const item_id = Number(tr.querySelector('.dn-item').value);
      if (!item_id) { UI.toast('Choose an item in every line', 'warn'); return; }
      const quantity = Number(tr.querySelector('.dn-qty').value);
      const unit_price = Number(tr.querySelector('.dn-price').value);
      if (!quantity || quantity < 1) { UI.toast('Quantity must be at least 1', 'warn'); return; }
      if (!Number.isFinite(unit_price) || unit_price < 0) { UI.toast('Unit price must be 0 or more', 'warn'); return; }
      if (seen.has(item_id)) { UI.toast('Duplicate item in line items', 'warn'); return; }
      seen.add(item_id);
      body.items.push({ item_id, quantity, unit_price });
    }
    if (!body.items.length) { UI.toast('Add at least one line item', 'warn'); return; }

    submitBtn.disabled = true;
    try {
      const r = await wb.post('deliveries.php', body);
      UI.toast('Delivery created');
      content.innerHTML = `
        <div class="card" style="max-width:560px">
          <div class="card-body">
            <h2 style="margin-bottom:6px">Delivery created</h2>
            <p style="color:var(--ink-2)">Receipt <strong>${UI.esc(r.receipt_no ?? '')}</strong> is ready to print.</p>
            <div style="display:flex;gap:8px;margin-top:14px;flex-wrap:wrap">
              <a class="btn primary" href="api/receipt.php?id=${Number(r.delivery_id)}" target="_blank" rel="noopener">Open Receipt</a>
              <button class="btn" type="button" id="dnAgain">New Delivery</button>
              <a class="btn" href="deliveries.html">Back to Deliveries</a>
            </div>
          </div>
        </div>`;
      document.getElementById('dnAgain').addEventListener('click', () => location.reload());
    } catch (ex) {
      UI.toast(ex.message, 'err'); /* 409 insufficient stock message surfaced */
      submitBtn.disabled = false;
    }
  });
})();
