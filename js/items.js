// =============================================================
// PMS Inventory Tracking & Delivery Monitoring System
// File: js/items.js
// Description: Renders the inventory items table
//              Low-stock rows are highlighted via CSS class 'low'
// =============================================================

/**
 * Fetch and render the items list into #items tbody.
 */
async function loadItems() {
    try {
        const items = await API.get('items.php');
        const tbody = document.querySelector('#items tbody');
        tbody.replaceChildren();

        for (const it of items) {
            const tr = document.createElement('tr');

            // Highlight rows where stock is at or below the low-stock threshold
            if (Number(it.stock_on_hand) <= Number(it.low_stock_level)) {
                tr.className = 'low';
            }

            const cols = [
                it.item_code,
                it.name,
                it.category,
                it.unit,
                Number(it.unit_price).toFixed(2),
                it.stock_on_hand,
                it.status,
            ];

            for (const v of cols) {
                const td = document.createElement('td');
                td.textContent = v ?? '';
                tr.appendChild(td);
            }

            // Action buttons placeholder
            const actions = document.createElement('td');
            actions.innerHTML = `
                <button class="btn-edit" data-id="${it.item_id}">Edit</button>
                <button class="btn-del"  data-id="${it.item_id}">Delete</button>
            `;
            tr.appendChild(actions);

            tbody.appendChild(tr);
        }
    } catch (err) {
        console.error('Failed to load items:', err);
        alert('Error loading items: ' + err.message);
    }
}

document.addEventListener('DOMContentLoaded', loadItems);
