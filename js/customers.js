/* PMS — Customers: list, add/edit modal with subtype fields + contacts repeater */
(async () => {
  UI.shell('customers', 'Customers');
  await UI.guard();
  const content = document.getElementById('content');

  content.innerHTML = `
    <div class="card">
      <div class="toolbar">
        <div class="col" style="width:150px">
          <label class="fl" for="csType">Type</label>
          <select id="csType">
            <option value="">All</option>
            <option value="SCHOOL">SCHOOL</option>
            <option value="OFFICE">OFFICE</option>
          </select>
        </div>
        <div style="flex:1;min-width:220px">
          <label class="fl" for="csSearch">Search</label>
          <input type="search" id="csSearch" placeholder="Name or address…">
        </div>
        <button class="btn primary" id="csAdd" type="button" style="margin-left:auto">＋ Add customer</button>
      </div>
      <div class="card-body flush"><div class="tablewrap">
        <table class="grid">
          <thead><tr>
            <th>Name</th><th>Type</th><th>Address</th><th>Contact info</th><th>Details</th><th style="text-align:right">Actions</th>
          </tr></thead>
          <tbody id="csRows">${UI.emptyRow(6, 'Loading…')}</tbody>
        </table>
      </div></div>
    </div>`;

  const typeEl = document.getElementById('csType');
  const searchEl = document.getElementById('csSearch');
  const tbody = document.getElementById('csRows');
  const addBtn = document.getElementById('csAdd');
  let cache = [];

  const SCHOOL_LEVELS = ['ELEMENTARY', 'JUNIOR_HIGH', 'SENIOR_HIGH', 'COLLEGE', 'OTHER'];
  const OFFICE_TYPES = ['GOVERNMENT', 'PRIVATE'];
  const CTYPES = ['MOBILE', 'LANDLINE', 'EMAIL'];

  async function load() {
    const p = new URLSearchParams();
    if (typeEl.value) p.set('type', typeEl.value);
    if (searchEl.value.trim()) p.set('search', searchEl.value.trim());
    const q = p.toString();
    try {
      const r = await wb.get('customers.php' + (q ? '?' + q : ''));
      cache = Array.isArray(r) ? r : (r.customers || []);
      render();
    } catch (ex) {
      UI.toast(ex.message, 'err');
      tbody.innerHTML = UI.emptyRow(6, 'Could not load customers.');
    }
  }

  function render() {
    if (!cache.length) { tbody.innerHTML = UI.emptyRow(6, 'No customers found.'); return; }
    tbody.innerHTML = cache.map(c => {
      const contacts = (c.contacts || []).map(x => `${x.contact_type}: ${x.contact_value}`).join(' · ');
      const detail = c.customer_type === 'SCHOOL'
        ? [c.school_level ?? '', c.head_name ? 'Head: ' + c.head_name : ''].filter(Boolean).join(' · ')
        : [c.office_type ?? '', c.contact_person ?? ''].filter(Boolean).join(' · ');
      const id = Number(c.customer_id);
      return `<tr>
        <td><strong>${UI.esc(c.name)}</strong></td>
        <td>${UI.badge(c.customer_type, UI.typeTone(c.customer_type))}</td>
        <td>${UI.esc(c.address)}</td>
        <td>${UI.esc(contacts || '—')}</td>
        <td>${UI.esc(detail || '—')}</td>
        <td style="text-align:right;white-space:nowrap">
          <button class="btn sm linklike" data-edit="${id}" type="button">Edit</button>
          <button class="btn sm danger" data-del="${id}" type="button">Delete</button>
        </td>
      </tr>`;
    }).join('');
  }

  /* ---- Add / Edit modal ---- */
  function contactRow(type = 'MOBILE', value = '') {
    const wrap = document.createElement('div');
    wrap.className = 'inline-form';
    wrap.style.marginBottom = '8px';
    wrap.innerHTML = `
      <div class="col narrow">
        <select data-ctype>${CTYPES.map(t => `<option value="${t}"${t === type ? ' selected' : ''}>${t}</option>`).join('')}</select>
      </div>
      <div style="flex:1"><input type="text" data-cvalue placeholder="Contact value" value="${UI.esc(value)}"></div>
      <button class="btn sm" type="button" data-rm title="Remove contact">✕</button>`;
    wrap.querySelector('[data-rm]').addEventListener('click', () => wrap.remove());
    return wrap;
  }

  function openModal(c) {
    const isNew = !c;
    const t0 = c?.customer_type ?? 'SCHOOL';
    const m = UI.modal({
      title: isNew ? 'Add customer' : 'Edit customer',
      bodyHTML: `
        <div class="field"><label class="fl">Name <span class="req">*</span></label>
          <input type="text" id="cuName" value="${UI.esc(c?.name ?? '')}" required></div>
        <div class="field"><label class="fl">Address <span class="req">*</span></label>
          <input type="text" id="cuAddr" value="${UI.esc(c?.address ?? '')}" required></div>
        <div class="field"><label class="fl">Customer type <span class="req">*</span></label>
          <label style="margin-right:18px"><input type="radio" name="cuType" value="SCHOOL"${t0 === 'SCHOOL' ? ' checked' : ''}> SCHOOL</label>
          <label><input type="radio" name="cuType" value="OFFICE"${t0 === 'OFFICE' ? ' checked' : ''}> OFFICE</label></div>
        <div id="cuSchoolFields" style="display:none">
          <div class="form-grid">
            <div class="field"><label class="fl">School level</label>
              <select id="cuLevel">${SCHOOL_LEVELS.map(l =>
                `<option value="${l}"${l === (c?.school_level ?? 'ELEMENTARY') ? ' selected' : ''}>${l}</option>`).join('')}</select></div>
            <div class="field"><label class="fl">Head name</label>
              <input type="text" id="cuHead" value="${UI.esc(c?.head_name ?? '')}"></div>
          </div>
        </div>
        <div id="cuOfficeFields" style="display:none">
          <div class="form-grid">
            <div class="field"><label class="fl">Office type</label>
              <select id="cuOfficeType">${OFFICE_TYPES.map(l =>
                `<option value="${l}"${l === (c?.office_type ?? 'GOVERNMENT') ? ' selected' : ''}>${l}</option>`).join('')}</select></div>
            <div class="field"><label class="fl">Contact person</label>
              <input type="text" id="cuPerson" value="${UI.esc(c?.contact_person ?? '')}"></div>
          </div>
        </div>
        <div class="section-title">Contacts</div>
        <div id="cuContacts"></div>
        <button class="btn ghost" type="button" id="cuAddContact">＋ Add contact</button>`,
      footerHTML: `
        <button class="btn" type="button" data-close>Cancel</button>
        <button class="btn primary" type="button" id="cuSave">${isNew ? 'Create customer' : 'Save changes'}</button>`
    });

    const q = (s) => m.el.querySelector(s);
    const contBox = q('#cuContacts');
    (c?.contacts || []).forEach(x => contBox.appendChild(contactRow(x.contact_type, x.contact_value)));

    const applyType = () => {
      const t = m.el.querySelector('input[name="cuType"]:checked')?.value;
      q('#cuSchoolFields').style.display = t === 'SCHOOL' ? '' : 'none';
      q('#cuOfficeFields').style.display = t === 'OFFICE' ? '' : 'none';
    };
    m.el.querySelectorAll('input[name="cuType"]').forEach(r => r.addEventListener('change', applyType));
    applyType();

    q('#cuAddContact').addEventListener('click', () => contBox.appendChild(contactRow()));
    q('[data-close]').addEventListener('click', () => m.close());

    q('#cuSave').addEventListener('click', async () => {
      const name = q('#cuName').value.trim();
      const address = q('#cuAddr').value.trim();
      const t = m.el.querySelector('input[name="cuType"]:checked')?.value || '';
      if (!name || !address || !t) { UI.toast('Name, address and type are required', 'warn'); return; }
      const contacts = [...m.el.querySelectorAll('#cuContacts .inline-form')].map(row => ({
        contact_type: row.querySelector('[data-ctype]').value,
        contact_value: row.querySelector('[data-cvalue]').value.trim()
      })).filter(x => x.contact_value);

      const body = { name, address, customer_type: t, contacts };
      if (t === 'SCHOOL') {
        body.school_level = q('#cuLevel').value;
        body.head_name = q('#cuHead').value.trim() || null;
      } else {
        body.office_type = q('#cuOfficeType').value;
        body.contact_person = q('#cuPerson').value.trim() || null;
      }

      const btn = q('#cuSave');
      btn.disabled = true;
      try {
        if (isNew) await wb.post('customers.php', body);
        else await wb.put('customers.php?id=' + c.customer_id, body);
        m.close();
        UI.toast(isNew ? 'Customer created' : 'Customer updated');
        load();
      } catch (ex) {
        UI.toast(ex.message, 'err');
        btn.disabled = false;
      }
    });
  }

  /* ---- Row actions ---- */
  tbody.addEventListener('click', async (e) => {
    const b = e.target.closest('button');
    if (!b) return;
    const id = Number(b.dataset.edit || b.dataset.del);
    const c = cache.find(x => Number(x.customer_id) === id);
    if (b.dataset.edit) openModal(c);
    else if (b.dataset.del && c) {
      if (await UI.confirm(`Delete customer "${c.name}"? This cannot be undone.`, { danger: true })) {
        try {
          await wb.del('customers.php?id=' + id);
          UI.toast('Customer deleted');
          load();
        } catch (ex) { UI.toast(ex.message, 'err'); } /* 409 "Customer has deliveries" surfaced here */
      }
    }
  });

  addBtn.addEventListener('click', () => openModal(null));
  typeEl.addEventListener('change', load);
  let deb;
  searchEl.addEventListener('input', () => { clearTimeout(deb); deb = setTimeout(load, 300); });

  await load();
})();
