// =============================================================
// PMS Inventory Tracking & Delivery Monitoring System
// File: js/auth.js
// Description: Login form handler and session state helpers
// =============================================================

/**
 * Attempt login. On success, redirects to the dashboard.
 * @param {string} username
 * @param {string} password
 */
async function login(username, password) {
    try {
        await API.post('auth.php?action=login', { username, password });
        location.href = 'index.html';
    } catch (err) {
        document.getElementById('login-error').textContent = err.message;
    }
}

/**
 * Logout the current session and redirect to login page.
 */
async function logout() {
    await API.get('auth.php?action=logout');
    location.href = 'login.html';
}

// Wire up the login form if it exists on this page
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('login-form');
    if (!form) return;

    form.addEventListener('submit', (e) => {
        e.preventDefault();
        const username = form.username.value.trim();
        const password = form.password.value;
        login(username, password);
    });
});
