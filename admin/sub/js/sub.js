// admin/sub/js/sub.js
// Enforce PHP-session auth for SUB admin
(async () => {
  try {
    const res = await fetch('/shreedatta-capital-web/admin/sub/auth.php', {
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      cache: 'no-store'
    });
    if (!res.ok) throw new Error('unauthorized');
    const data = await res.json().catch(() => ({}));
    if (!data || data.ok !== true) throw new Error('unauthorized');
    const who = document.getElementById('who');
    if (who && data.user && data.user.username) {
      who.textContent = ` · Logged in as ${data.user.username}`;
    }
  } catch (_) {
    window.location.replace('/shreedatta-capital-web/index.html');
  }
})();
