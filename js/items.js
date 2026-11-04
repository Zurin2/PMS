/* PMS — items: master list with filters, add/edit modal, delete/archive */
(async () => {
  UI.shell('items', 'Items', 'Master list & stock levels');
  await UI.guard();

  const content = document.getElementById('content');
  const state = { items: [], cats: [] };

  content.innerHTML = `
    <div class="card">
      <div class="toolbar inline-form">
        <div class="col" style="width:210px">
          <input type="search" id="f-search" placeholder="Search code or name…">
        </div>
        <div class="col">
          <select id="f-cat"><option value="">All categories</option></select>
        </div>
        <div class="col narrow">
          <select id="f-status">
            <option value="">All status</option>
            <option value="ACTIVE">ACTIVE</option>
            <option value="ARCHIVED">ARCHIVED</option>
          </select>
        </div>
        <label class="hint" style="display:flex;align-items:center;gap:6px;font-size:13px;color:var(--ink-2);margin:0">
          <input type="checkbox" id="f-low" style="width:auto"> Low stock only
        </label>
        <span style="flex:1"></span>
        <button class="btn primary" id="btn-add" type="button">+ Add Item</button>
      </div>
      <div class="tablewrap">
        <table class="grid">
          <thead>
            <tr>
              <th>Item code</th><th>Name</th><th>Category</th><th>Unit</th>
              <th class="num">Price</th><th class="num">On hand</th><th class="num">Low-stock level</th>
              <th>Status</th><th>Actions</th>
            </tr>
          </thead>
          <tbody id="i-tbody"></tbody>
        </table>
      </div>
    </div>`;

  const $ = (id) => document.getElementById(id);
  const COLS = 9;

  /* ---------- data ---------- */
  async function refresh() {
    const r = await wb.get('items.php');
    state.items = Array.isArray(r) ? r : [];
    render();
  }

  try {
    state.cats = (await wb.get('categories.php')) || [];
  } catch (ex) {
    UI.toast(ex.message, 'err');
  }
  $('f-cat').innerHTML = '<option value="">All categories</option>' +
    state.cats.map(c => `<option value="${UI.esc(c.name)}">${UI.esc(c.name)}</option>`).join('');

  try {
    await refresh();
  } catch (ex) {
    UI.toast(ex.message, 'err');
    $('i-tbody').innerHTML = UI.emptyRow(COLS, 'Could not load items.');
  }

  /* ---------- rendering ---------- */
  function render() {
    const q = $('f-search').value.trim().toLowerCase();
    const cat = $('f-cat').value;
    const status = $('f-status').value;
    const lowOnly = $('f-low').checked;

    const rows = state.items.filter(it =>
      (!cat || String(it.category) === cat) &&
      (!status || it.status === status) &&
      (!lowOnly || Number(it.stock_on_hand) <= Number(it.low_stock_level)) &&
      (!q ||
        String(it.item_code || '').toLowerCase().includes(q) ||
        String(it.name || '').toLowerCase().includes(q))
    );

    $('i-tbody').innerHTML = rows.length ? rows.map(it => `<tr>
        <td>${UI.esc(it.item_code)}</td>
        <td>${UI.esc(it.name)}</td>
        <td>${UI.esc(it.category)}</td>
        <td>${UI.esc(it.unit)}</td>
        <td class="num">${UI.money(it.unit_price)}</td>
        <td class="num">${Number(it.stock_on_hand) <= 0
          ? `<span class="tag-low">${UI.qty(it.stock_on_hand)}</span>`
          : UI.qty(it.stock_on_hand)}</td>
        <td class="num">${UI.qty(it.low_stock_level)}</td>
        <td>${UI.badge(it.status, it.status === 'ACTIVE' ? 'pos' : 'mute')}</td>
        <td style="white-space:nowrap">
          <button class="btn sm" type="button" data-edit="${it.item_id}">Edit</button>
          <button class="btn sm danger" type="button" data-del="${it.item_id}">Delete</button>
        </td>
      </tr>`).join('') : UI.emptyRow(COLS, 'No items match the current filters.');
  }

  /* ---------- filters ---------- */
  $('f-search').addEventListener('input', render);
  $('f-cat').addEventListener('change', render);
  $('f-status').addEventListener('change', render);
  $('f-low').addEventListener('change', render);

  /* ---------- add / edit modal ---------- */
  function suggestCode() {
    let max = 0;
    for (const it of state.items) {
      const m = /(\d+)\s*$/.exec(String(it.item_code || ''));
      if (m) max = Math.max(max, parseInt(m[1], 10));
    }
    return 'ITM-' + String(max + 1).padStart(4, '0');
  }

  const catOptions = () => state.cats.map(c =>
    `<option value="${c.category_id}">${UI.esc(c.name)}</option>`).join('');

  function openForm(item) {
    const create = !item;
    const m = UI.modal({
      title: create ? 'New Item' : 'Edit Item',
      bodyHTML: `
        <form id="item-form" autocomplete="off">
          <div class="form-grid">
            <div class="field">
              <label class="fl" for="f-category">Category <span class="req">*</span></label>
              <select id="f-category">${catOptions()}</select>
            </div>
            <div class="field">
              <label class="fl" for="f-code">Item code <span class="req">*</span></label>
              <input type="text" id="f-code" value="${create ? UI.esc(suggestCode()) : UI.esc(item.item_code)}" ${create ? '' : 'disabled'} ${create ? '' : 'aria-disabled="true"'}>
              ${create ? '<div class="hint">Auto-suggested — leave as-is or retype.</div>'
                       : '<div class="hint">Item code cannot be changed.</div>'}
            </div>
            <div class="field span2">
              <label class="fl" for="f-name">Name <span class="req">*</span></label>
              <input type="text" id="f-name" required value="${create ? '' : UI.esc(item.name)}">
            </div>
            <div class="field">
              <label class="fl" for="f-unit">Unit <span class="req">*</span></label>
              <input type="text" id="f-unit" required placeholder="pc, box, ream…" value="${create ? '' : UI.esc(item.unit)}">
            </div>
            <div class="field">
              <label class="fl" for="f-price">Unit price <span class="req">*</span></label>
              <input type="number" id="f-price" step="0.01" min="0" required value="${create ? '' : Number(item.unit_price).toFixed(2)}">
            </div>
            <div class="field">
              <label class="fl" for="f-lsl">Low-stock level</label>
              <input type="number" id="f-lsl" step="1" min="0" value="${create ? 0 : Number(item.low_stock_level)}">
            </div>
          </div>
        </form>`,
      footerHTML: `
        <button class="btn" type="button" data-act="cancel">Cancel</button>
        <button class="btn primary" type="submit" form="item-form" id="f-save">Save</button>`
    });

    if (!create) $('f-category').value = String(item.category_id ?? '');

    m.el.querySelector('[data-act="cancel"]').onclick = () => m.close();

    $('item-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const btn = $('f-save');
      btn.disabled = true;
      const body = {
        category_id: $('f-category').value,
        name: $('f-name').value.trim(),
        unit: $('f-unit').value.trim(),
        unit_price: Number($('f-price').value) || 0,
        low_stock_level: Number($('f-lsl').value) || 0
      };
      if (create) body.item_code = $('f-code').value.trim();
      try {
        if (create) {
          await wb.post('items.php', body);
          UI.toast('Item created');
        } else {
          await wb.put('items.php?id=' + item.item_id, body);
          UI.toast('Item updated');
        }
        m.close();
        await refresh();
      } catch (ex) {
        UI.toast(ex.message, 'err');
        btn.disabled = false;
      }
    });
  }

  $('btn-add').addEventListener('click', () => openForm(null));

  /* ---------- row actions ---------- */
  $('i-tbody').addEventListener('click', (e) => {
    const editBtn = e.target.closest('[data-edit]');
    const delBtn = e.target.closest('[data-del]');
    if (editBtn) {
      const it = state.items.find(x => x.item_id === Number(editBtn.dataset.edit));
      if (it) openForm(it);
    } else if (delBtn) {
      remove(state.items.find(x => x.item_id === Number(delBtn.dataset.del)));
    }
  });

  async function remove(it) {
    if (!it) return;
    const ok = await UI.confirm(`Delete "${it.name}"? Items with movement history are archived instead.`, { danger: true });
    if (!ok) return;
    try {
      const r = await wb.del('items.php?id=' + it.item_id);
      UI.toast(r && r.archived ? 'Item archived (has history)' : 'Item deleted');
      await refresh();
    } catch (ex) {
      UI.toast(ex.message, 'err');
    }
  }
})();
