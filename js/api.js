// =============================================================
// PMS Inventory Tracking & Delivery Monitoring System
// File: js/api.js
// Description: Centralized fetch wrapper for all API calls
//              Auto-redirects to login on 401 Unauthorized
// =============================================================

const API = {
    /**
     * Core fetch wrapper.
     * @param {string} path   - API endpoint path (relative to api/)
     * @param {string} method - HTTP method
     * @param {object|null} body - Request payload (will be JSON-encoded)
     * @returns {Promise<any>} Parsed JSON response
     */
    async call(path, method = 'GET', body = null) {
        const res = await fetch('api/' + path, {
            method,
            headers: { 'Content-Type': 'application/json' },
            body: body ? JSON.stringify(body) : undefined,
            credentials: 'same-origin'
        });

        const data = await res.json().catch(() => ({}));

        if (res.status === 401) {
            location.href = 'login.html';
            return;
        }
        if (!res.ok) throw new Error(data.error || 'Request failed');
        return data;
    },

    get:  (path)       => API.call(path),
    post: (path, body) => API.call(path, 'POST', body),
    put:  (path, body) => API.call(path, 'PUT', body),
    del:  (path)       => API.call(path, 'DELETE'),
};
