// admin/super/js/super.js
// Enforce PHP-session auth for SUPER admin, and wire logout to PHP endpoint
(async () => {
  try {
    const res = await fetch('/shreedatta-capital-web/admin/super/auth.php', {
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      cache: 'no-store'
    });
    if (!res.ok) throw new Error('unauthorized');
    const data = await res.json().catch(() => ({}));
    if (!data || data.ok !== true) throw new Error('unauthorized');
    // Optionally show username in header if you add an element for it
    const title = document.querySelector('.dash-title .title');
    if (title && data.user && data.user.username) {
      // No-op: keep existing title, or uncomment to display username
      // title.textContent = `Welcome, ${data.user.username}`;
    }
  } catch (_) {
    window.location.replace('/shreedatta-capital-web/index.html');
    return;
  }

  // Logout button should open modal first (handled by app.js). When confirmed, app.js will redirect to PHP logout.
})();
