/* PMS — Deliveries: filterable list, view modal, receipt print, cancel/delete */
(async () => {
  UI.shell('deliveries', 'Deliveries');
  await UI.guard();
  const content = document.getElementById('content');

  content.innerHTML = `
    <div class="card">
      <div class="toolbar">
        <div class="col" style="width:150px">
          <label class="fl" for="dvStatus">Status</label>
          <select id="dvStatus">
            <option value="">All</option>
            <option value="DELIVERED">DELIVERED</option>
            <option value="CANCELLED">CANCELLED</option>
          </select>
        </div>
        <div class="col" style="width:150px">
          <label class="fl" for="dvType">Order type</label>
          <select id="dvType">
            <option value="">All</option>
            <option value="DIRECT">DIRECT</option>
            <option value="BIDDING">BIDDING</option>
          </select>
        </div>
        <label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;padding-bottom:9px">
          <input type="checkbox" id="dvUnpaid"> Unpaid only</label>
        <div class="col"><label class="fl" for="dvFrom">From</label><input type="date" id="dvFrom"></div>
        <div class="col"><label class="fl" for="dvTo">To</label><input type="date" id="dvTo"></div>
        <div style="flex:1;min-width:200px">
          <label class="fl" for="dvSearch">Search</label>
          <input type="search" id="dvSearch" placeholder="Receipt, customer, PO/ref…">
        </div>
        <span style="flex:1"></span>
        <a class="btn primary" href="delivery_new.html" style="margin-bottom:9px">+ New Delivery</a>
      </div>
      <div class="card-body flush"><div class="tablewrap">
        <table class="grid">
          <thead><tr>
            <th>Receipt no</th><th>Date</th><th>Customer</th><th>Type</th><th>PO/Ref</th>
            <th class="num">Total</th><th class="num">Paid</th><th class="num">Balance</th>
            <th>Status</th><th style="text-align:right">Actions</th>
          </tr></thead>
          <tbody id="dvRows">${UI.emptyRow(10, 'Loading…')}</tbody>
        </table>
      </div></div>
    </div>`;

  const stEl = document.getElementById('dvStatus');
  const tyEl = document.getElementById('dvType');
  const unEl = document.getElementById('dvUnpaid');
  const frEl = document.getElementById('dvFrom');
  const toEl = document.getElementById('dvTo');
  const tbody = document.getElementById('dvRows');
  UI.addSearch('dvSearch', tbody);
  let list = [];

  const custName = (d) => d.customer_name ?? d.name ?? '—';

  async function load() {
    const p = new URLSearchParams();
    if (stEl.value) p.set('status', stEl.value);
    if (tyEl.value) p.set('order_type', tyEl.value);
    if (unEl.checked) p.set('unpaid', '1');
    if (frEl.value) p.set('from', frEl.value);
    if (toEl.value) p.set('to', toEl.value);
    const q = p.toString();
    tbody.innerHTML = UI.emptyRow(10, 'Loading…');
    try {
      const r = await wb.get('deliveries.php' + (q ? '?' + q : ''));
      list = Array.isArray(r) ? r : (r.deliveries || []);
      render();
    } catch (ex) {
      UI.toast(ex.message, 'err');
      tbody.innerHTML = UI.emptyRow(10, 'Could not load deliveries.');
    }
  }

  function render() {
    if (!list.length) { tbody.innerHTML = UI.emptyRow(10, 'No deliveries found.'); return; }
    tbody.innerHTML = list.map(d => {
      const bal = Number(d.balance || 0);
      const id = Number(d.delivery_id);
      const btns = [
        `<button class="btn sm linklike" data-act="view" data-id="${id}" type="button">View</button>`,
        `<button class="btn sm linklike" data-act="receipt" data-id="${id}" type="button">Receipt</button>`
      ];
      if (d.status === 'DELIVERED')
        btns.push(`<button class="btn sm linklike" data-act="cancel" data-id="${id}" type="button">Cancel</button>`);
      if (d.status === 'CANCELLED')
        btns.push(`<button class="btn sm danger" data-act="delete" data-id="${id}" type="button">Delete</button>`);
      const ref = d.order_type === 'DIRECT' ? (d.po_number ?? '—') : (d.bid_ref_no ?? '—');
      return `<tr>
        <td class="mono">${UI.esc(d.receipt_no)}</td>
        <td>${UI.date(d.delivery_date)}</td>
        <td>${UI.esc(custName(d))}</td>
        <td>${UI.badge(d.order_type, UI.typeTone(d.order_type))}</td>
        <td>${UI.esc(ref)}</td>
        <td class="num">${UI.money(d.total_amount)}</td>
        <td class="num">${UI.money(d.total_paid)}</td>
        <td class="num${bal > 0 ? ' tag-low' : ''}">${UI.money(bal)}</td>
        <td>${UI.badge(d.status, UI.statusTone(d.status))}</td>
        <td style="text-align:right;white-space:nowrap">${btns.join(' ')}</td>
      </tr>`;
    }).join('');
  }

  function mv(l, v) { return `<div><div class="lbl">${l}</div><div class="val">${UI.esc(v)}</div></div>`; }

  async function openView(id) {
    try {
      const d = await wb.get('deliveries.php?id=' + id);
      const total = Number(d.total_amount ?? d.total ?? 0);
      const paid = Number(d.total_paid ?? d.paid ?? 0);
      const bal = Number(d.balance ?? (total - paid));
      const items = d.items || [];
      const payments = d.payments || [];
      const refMeta = d.order_type === 'DIRECT'
        ? mv('PO number', d.po_number ?? '—') + mv('PO date', UI.date(d.po_date))
        : mv('Bid ref no', d.bid_ref_no ?? '—') + mv('Deadline', UI.date(d.delivery_deadline));
      const totalLines = items.reduce((s, it) =>
        s + Number(it.line_total ?? (Number(it.quantity || 0) * Number(it.unit_price || 0))), 0);

      const m = UI.modal({
        title: 'Delivery ' + (d.receipt_no ?? '#' + id),
        width: 'wide',
        bodyHTML: `
          <div class="meta-grid">
            ${mv('Receipt no', d.receipt_no ?? '—')}
            ${mv('Customer', custName(d))}
            ${mv('Date', UI.date(d.delivery_date))}
            ${mv('Order type', d.order_type ?? '—')}
            ${mv('Status', d.status ?? '—')}
            ${refMeta}
          </div>
          <div class="section-title">Items</div>
          <div class="tablewrap"><table class="grid">
            <thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Line total</th></tr></thead>
            <tbody>
              ${items.length ? items.map(it => `<tr>
                <td>${UI.esc(it.item_code)} — ${UI.esc(it.name)}</td>
                <td class="num">${UI.qty(it.quantity)}</td>
                <td class="num">${UI.money(it.unit_price)}</td>
                <td class="num">${UI.money(it.line_total ?? (Number(it.quantity || 0) * Number(it.unit_price || 0)))}</td>
              </tr>`).join('')
                : UI.emptyRow(4, 'No line items.')}
              <tr><td colspan="3" style="text-align:right"><strong>Total</strong></td>
                <td class="num"><strong>${UI.money(totalLines || total)}</strong></td></tr>
            </tbody>
          </table></div>
          <div class="section-title">Payments</div>
          <div class="tablewrap"><table class="grid">
            <thead><tr><th>Date</th><th class="num">Amount</th><th>Method</th><th>Reference</th><th>Remarks</th></tr></thead>
            <tbody>${payments.length ? payments.map(p => `<tr>
                <td>${UI.date(p.payment_date)}</td>
                <td class="num">${UI.money(p.amount)}</td>
                <td>${UI.esc(p.method ?? '—')}</td>
                <td>${UI.esc(p.reference_no ?? '—')}</td>
                <td>${UI.esc(p.remarks ?? '—')}</td>
              </tr>`).join('')
              : UI.emptyRow(5, 'No payments yet.')}
            </tbody>
          </table></div>
          <div class="meta-grid" style="margin-top:14px">
            ${mv('Total', UI.money(total))}
            ${mv('Paid', UI.money(paid))}
            ${mv('Balance', UI.money(bal))}
          </div>`,
        footerHTML: `<button class="btn" type="button" data-close>Close</button>`
      });
      m.el.querySelector('[data-close]').addEventListener('click', () => m.close());
    } catch (ex) { UI.toast(ex.message, 'err'); }
  }

  tbody.addEventListener('click', async (e) => {
    const b = e.target.closest('button');
    if (!b) return;
    const id = Number(b.dataset.id);
    const d = list.find(x => Number(x.delivery_id) === id);
    if (!d) return;
    if (b.dataset.act === 'view') { await openView(id); return; }
    if (b.dataset.act === 'receipt') { window.open('api/receipt.php?id=' + id, '_blank'); return; }
    if (b.dataset.act === 'cancel') {
      if (await UI.confirm(`Cancel delivery ${d.receipt_no}? Cancelled deliveries are excluded from stock movements.`, { danger: true })) {
        try {
          await wb.put('deliveries.php?id=' + id, { status: 'CANCELLED' });
          UI.toast('Delivery cancelled');
          load();
        } catch (ex) { UI.toast(ex.message, 'err'); }
      }
      return;
    }
    if (b.dataset.act === 'delete') {
      if (await UI.confirm(`Delete delivery ${d.receipt_no} permanently? This cannot be undone.`, { danger: true })) {
        try {
          await wb.del('deliveries.php?id=' + id);
          UI.toast('Delivery deleted');
          load();
        } catch (ex) { UI.toast(ex.message, 'err'); } /* 409 when payments exist */
      }
    }
  });

  [stEl, tyEl, unEl, frEl, toEl].forEach(el => el.addEventListener('change', load));
  await load();
})();
