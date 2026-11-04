/* PMS — login page handler */
const errBox = document.getElementById('authErr');

// Already signed in? Go straight to the dashboard.
wb.get('auth.php?action=me')
  .then(() => { location.href = 'dashboard.html'; })
  .catch(() => {}); // not logged in: stay

// Show / hide password toggle
const pwInput = document.getElementById('password');
document.getElementById('togglePw').addEventListener('click', (e) => {
  const btn = e.currentTarget;
  const show = pwInput.type === 'password';
  pwInput.type = show ? 'text' : 'password';
  btn.textContent = show ? '○' : '●';
  btn.setAttribute('aria-pressed', String(show));
  btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
  btn.title = show ? 'Hide password' : 'Show password';
  pwInput.focus();
});

document.getElementById('loginForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  errBox.classList.remove('show');
  const btn = document.getElementById('loginBtn');
  btn.disabled = true;
  try {
    await wb.post('auth.php', {
      username: document.getElementById('username').value.trim(),
      password: document.getElementById('password').value
    });
    location.href = 'dashboard.html';
  } catch (ex) {
    errBox.textContent = ex.message === 'Request failed (400)'
      ? 'Invalid username or password.'
      : ex.message;
    errBox.classList.add('show');
    btn.disabled = false;
  }
});
