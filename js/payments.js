/* PMS — Payments: record payment against unpaid deliveries + history */
(async () => {
  UI.shell('payments', 'Payments');
  await UI.guard();
  const content = document.getElementById('content');

  const today = () => {
    const d = new Date();
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
  };

  content.innerHTML = `
    <div class="card" style="margin-bottom:16px">
      <div class="card-head"><h2>Record payment</h2></div>
      <div class="card-body">
        <div class="field" style="max-width:460px">
          <label class="fl" for="pmDel">Delivery (unpaid only) <span class="req">*</span></label>
          <select id="pmDel"><option value="">Loading…</option></select>
          <div class="hint">Only deliveries with an outstanding balance are listed.</div>
        </div>
        <div style="margin:8px 0 16px;display:none" id="pmMetaBox">
          <div class="meta-grid" style="max-width:560px" id="pmMeta"></div>
        </div>
        <div class="form-grid" style="max-width:640px">
          <div class="field"><label class="fl" for="pmAmt">Amount <span class="req">*</span></label>
            <input type="number" id="pmAmt" min="0.01" step="0.01" placeholder="0.00"></div>
          <div class="field"><label class="fl" for="pmDate">Payment date <span class="req">*</span></label>
            <input type="date" id="pmDate" value="${today()}"></div>
          <div class="field"><label class="fl" for="pmMethod">Method <span class="req">*</span></label>
            <select id="pmMethod">
              <option value="CASH">CASH</option>
              <option value="CHECK">CHECK</option>
              <option value="BANK TRANSFER">BANK TRANSFER</option>
              <option value="OTHER">OTHER</option>
            </select></div>
          <div class="field"><label class="fl" for="pmRef">Reference no</label>
            <input type="text" id="pmRef" placeholder="Check / transfer no."></div>
        </div>
        <div class="field" style="max-width:640px"><label class="fl" for="pmRemarks">Remarks</label>
          <textarea id="pmRemarks" rows="2"></textarea></div>
        <button class="btn primary" id="pmSubmit" type="button">Record Payment</button>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h2>Payment history</h2></div>
      <div class="card-body flush"><div class="tablewrap">
        <table class="grid">
          <thead><tr>
            <th>Date</th><th>Receipt no</th><th>Customer</th><th class="num">Amount</th>
            <th>Method</th><th>Reference</th><th>Remarks</th>
          </tr></thead>
          <tbody id="pmRows">${UI.emptyRow(7, 'Loading…')}</tbody>
        </table>
      </div></div>
    </div>`;

  const sel = document.getElementById('pmDel');
  const metaBox = document.getElementById('pmMetaBox');
  const meta = document.getElementById('pmMeta');
  const amtEl = document.getElementById('pmAmt');
  const dateEl = document.getElementById('pmDate');
  const methodEl = document.getElementById('pmMethod');
  const refEl = document.getElementById('pmRef');
  const remEl = document.getElementById('pmRemarks');
  const submitBtn = document.getElementById('pmSubmit');
  const tbody = document.getElementById('pmRows');

  let unpaid = [];

  const mv = (l, v) => `<div><div class="lbl">${l}</div><div class="val">${UI.esc(v)}</div></div>`;
  const custName = (d) => d.customer_name ?? d.name ?? '—';

  async function loadUnpaid() {
    try {
      const r = await wb.get('deliveries.php?unpaid=1');
      unpaid = Array.isArray(r) ? r : (r.deliveries || []);
      sel.innerHTML = '<option value="">— select delivery —</option>' + unpaid.map(d =>
        `<option value="${Number(d.delivery_id)}">${UI.esc(d.receipt_no ?? '')} — ${UI.esc(custName(d))} — balance ${UI.money(d.balance)}</option>`
      ).join('');
      metaBox.style.display = 'none';
      amtEl.value = '';
    } catch (ex) {
      UI.toast(ex.message, 'err');
      sel.innerHTML = '<option value="">— load failed —</option>';
    }
  }

  sel.addEventListener('change', () => {
    const d = unpaid.find(x => Number(x.delivery_id) === Number(sel.value));
    if (!d) { metaBox.style.display = 'none'; amtEl.value = ''; return; }
    amtEl.value = Number(d.balance || 0).toFixed(2);
    metaBox.style.display = '';
    meta.innerHTML =
      mv('Total', UI.money(d.total_amount ?? d.total ?? 0)) +
      mv('Paid', UI.money(d.total_paid ?? d.paid ?? 0)) +
      mv('Balance', UI.money(d.balance ?? 0));
  });

  submitBtn.addEventListener('click', async () => {
    const delivery_id = Number(sel.value);
    if (!delivery_id) { UI.toast('Choose a delivery', 'warn'); return; }
    const amount = Number(amtEl.value);
    if (!Number.isFinite(amount) || amount <= 0) { UI.toast('Amount must be greater than 0', 'warn'); return; }
    const payment_date = dateEl.value;
    if (!payment_date) { UI.toast('Payment date is required', 'warn'); return; }
    submitBtn.disabled = true;
    try {
      await wb.post('payments.php', {
        delivery_id,
        amount,
        payment_date,
        method: methodEl.value,
        reference_no: refEl.value.trim() || null,
        remarks: remEl.value.trim() || null
      });
      UI.toast('Payment recorded');
      refEl.value = '';
      remEl.value = '';
      await loadUnpaid();
      await loadHistory();
    } catch (ex) {
      UI.toast(ex.message, 'err'); /* 409 overpay / status messages surfaced */
    } finally { submitBtn.disabled = false; }
  });

  async function loadHistory() {
    try {
      const r = await wb.get('payments.php');
      const rows = Array.isArray(r) ? r : (r.payments || []);
      tbody.innerHTML = rows.length ? rows.map(p => `<tr>
        <td>${UI.date(p.payment_date)}</td>
        <td class="mono">${UI.esc(p.receipt_no ?? '—')}</td>
        <td>${UI.esc(p.customer_name ?? p.name ?? '—')}</td>
        <td class="num">${UI.money(p.amount)}</td>
        <td>${UI.esc(p.method ?? '—')}</td>
        <td>${UI.esc(p.reference_no ?? '—')}</td>
        <td>${UI.esc(p.remarks ?? '—')}</td>
      </tr>`).join('') : UI.emptyRow(7, 'No payments recorded yet.');
    } catch (ex) {
      UI.toast(ex.message, 'err');
      tbody.innerHTML = UI.emptyRow(7, 'Could not load payment history.');
    }
  }

  await loadUnpaid();
  await loadHistory();
})();
