// Utility: safely set footer year if present
const yearEl = document.getElementById('year');
if (yearEl) yearEl.textContent = new Date().getFullYear();

// Toggle password visibility
const toggleBtn = document.getElementById('togglePw');
if (toggleBtn) {
  toggleBtn.addEventListener('click', () => {
    const pw = document.getElementById('password');
    const isPw = pw.type === 'password';
    pw.type = isPw ? 'text' : 'password';
    toggleBtn.textContent = isPw ? '🙈' : '👁️';
  });
}

// Login handling with simple validation
const form = document.getElementById('loginForm');
if (form) {
  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const error = document.getElementById('error');
    if (error) error.textContent = '';

    const username = document.getElementById('username').value.trim();
    const password = document.getElementById('password').value;

    if (!username || !password) {
      if (error) error.textContent = 'Please enter both username and password.';
      return;
    }

    // Server-side validation against DB
    const btn = form.querySelector('button[type="submit"]');
    const prevText = btn ? btn.textContent : '';
    if (btn) { btn.disabled = true; btn.textContent = 'Signing in...'; }
    // Clear previous error
    if (error) error.textContent = '';

    fetch('login.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin', // send cookies for PHP session
      cache: 'no-store',
      body: JSON.stringify({ username, password })
    })
      .then(async (res) => {
        let data = {};
        try { data = await res.json(); } catch (_) { /* non-JSON */ }
        if (!res.ok || !data.ok) {
          const msg = (data && (data.error || (data.debug && data.debug.message))) || (res.status >= 500 ? 'Server error' : 'Invalid username or password');
          throw new Error(msg);
        }
        try { localStorage.setItem('auth', '1'); } catch (_) {}
        window.location.href = 'dashboard.php';
      })
      .catch((err) => {
        if (error) error.textContent = err && err.message ? err.message : 'Login failed';
      })
      .finally(() => {
        if (btn) { btn.disabled = false; btn.textContent = prevText || 'Sign in'; }
      });
  });
}

// Dashboard guard and interactions
const dashboardRoot = document.getElementById('dashboard');
if (dashboardRoot) {
  const isAuthed = (() => { try { return localStorage.getItem('auth') === '1'; } catch (_) { return false; } })();
  if (!isAuthed) {
    // Not authenticated, send to login
    window.location.replace('index.html');
  } else {
    // Wire up logout with confirmation
    const logoutBtn = document.getElementById('logout');
    if (logoutBtn) {
      logoutBtn.addEventListener('click', () => {
        openLogoutConfirm();
      });
    }

    // Render absolute URLs for category links (single link per block), using existing hrefs
    const origin = window.location.origin || '';
    const linkEls = dashboardRoot.querySelectorAll('.category-links a.url');
    linkEls.forEach(a => {
      const href = a.getAttribute('href') || '';
      const absolute = href.startsWith('http') ? href : `${origin}${href.startsWith('/') ? href : `/${href}`}`;
      a.textContent = absolute;
      a.target = '_blank';
      a.rel = 'noopener noreferrer';
    });

    // Copy-to-clipboard for each block
    const blocks = dashboardRoot.querySelectorAll('.category-card');
    blocks.forEach(block => {
      const urlEl = block.querySelector('.category-links a.url');
      const copyBtn = block.querySelector('.category-links .copy-btn');
      if (!urlEl || !copyBtn) return;
      const getText = () => {
        const txt = (urlEl.textContent || '').trim();
        if (txt.startsWith('http://') || txt.startsWith('https://')) return txt;
        const href = urlEl.getAttribute('href') || '';
        const origin = window.location.origin || '';
        return href.startsWith('http') ? href : `${origin}${href.startsWith('/') ? href : `/${href}`}`;
      };
      copyBtn.addEventListener('click', async () => {
        const text = getText();
        try {
          if (navigator.clipboard && navigator.clipboard.writeText) {
            await navigator.clipboard.writeText(text);
          } else {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
          }
          const prev = copyBtn.textContent;
          copyBtn.textContent = 'Copied!';
          copyBtn.classList.add('copied');
          setTimeout(() => {
            copyBtn.textContent = prev || 'Copy';
            copyBtn.classList.remove('copied');
          }, 1200);
        } catch (_) {
          copyBtn.textContent = 'Error';
          setTimeout(() => (copyBtn.textContent = 'Copy'), 1000);
        }
      });
    });

    // Count badges in header cards
    const countEls = dashboardRoot.querySelectorAll('[data-count-key]');
    const renderCounts = (counts = { gold:0, cash:0, bike:0 }) => {
      countEls.forEach(el => {
        const key = el.getAttribute('data-count-key');
        let val = 0;
        if (key === 'gold_registration') val = counts.gold || 0;
        else if (key === 'cash_registration') val = counts.cash || 0;
        else if (key === 'bike_registration') val = counts.bike || 0;
        el.textContent = String(val);
      });
    };

    // User search and results rendering
    const userRows = document.getElementById('user-rows');
    const userEmpty = document.getElementById('user-empty');
    const searchInput = document.getElementById('user-search-input');
    const filterButtons = Array.from(dashboardRoot.querySelectorAll('.filter-btn'));

    // Convert MySQL 'YYYY-MM-DD HH:MM:SS' to Date
    const parseMySQLDateTime = (s) => {
      if (!s) return null;
      const m = /^([0-9]{4})-([0-9]{2})-([0-9]{2})\s+([0-9]{2}):([0-9]{2})(?::([0-9]{2}))?$/.exec(s);
      if (!m) return null;
      const [_, Y, M, D, h, i, sec] = m;
      return new Date(Number(Y), Number(M)-1, Number(D), Number(h), Number(i), Number(sec||'0'));
    };

    // Load users + counts from backend
    const getUsers = async () => {
      const res = await fetch('asset/forms/api/list_users.php', { cache: 'no-store' });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.ok) throw new Error(data && data.error ? data.error : 'Failed to load users');
      const list = (data.users || []).map(u => ({
        id: String(u.id),
        name: `${u.firstName || ''} ${u.lastName || ''}`.trim(),
        form: String(u.drawCategory || '').toLowerCase(),
        drawName: u.drawName || '',
        createdAt: parseMySQLDateTime(u.dateAndTime),
      }));
      return { list, counts: data.counts || {} };
    };

    const buildUrl = (u) => {
      const base = window.location.origin || '';
      if (u.form === 'gold') return `${base}/gold/registration/${u.id}`;
      if (u.form === 'cash') return `${base}/cash/payouts/${u.id}`;
      return `${base}/bike/participants/${u.id}`;
    };

    const buildPdfUrl = (u) => {
      // Create safe filename: firstName_lastName_drawName.pdf
      const safeName = u.name.replace(/[^A-Za-z0-9_-]+/g, '_').replace(/_{2,}/g, '_').trim('_');
      const safeDraw = u.drawName ? u.drawName.replace(/[^A-Za-z0-9_-]+/g, '-').replace(/-{2,}/g, '-').trim('-') : u.form.toLowerCase();
      const origin = window.location.origin || '';
      // Use absolute URL to avoid any base href or server-side prefixing side-effects
      return `/pdffiles/${safeName}_${safeDraw}.pdf`;
    };

    const formatDT = (dt) => {
      if (!dt) return '';
      try {
        const d = (dt instanceof Date) ? dt : new Date(dt);
        const date = d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: '2-digit' });
        const time = d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', hour12: true });
        return `${date} ${time}`;
      } catch { return String(dt); }
    };

    const renderUsers = (list) => {
      if (!userRows) return;
      userRows.innerHTML = '';
      if (!list || list.length === 0) {
        if (userEmpty) userEmpty.style.display = '';
        return;
      }
      if (userEmpty) userEmpty.style.display = 'none';
      const frag = document.createDocumentFragment();
      list.forEach(u => {
        const tr = document.createElement('tr');
        const tdUser = document.createElement('td');
        tdUser.textContent = `${u.name}`;
        const tdForm = document.createElement('td');
        tdForm.textContent = u.form.charAt(0).toUpperCase() + u.form.slice(1);
        const tdWhen = document.createElement('td');
        tdWhen.textContent = formatDT(u.createdAt);
        const tdAct = document.createElement('td');
        tdAct.className = 'user-actions';
        const btnPdf = document.createElement('button');
        btnPdf.type = 'button';
        btnPdf.className = 'btn-pdf';
        btnPdf.textContent = 'PDF';
        btnPdf.addEventListener('click', () => window.open(buildPdfUrl(u), '_blank'));
        const btnDel = document.createElement('button');
        btnDel.type = 'button';
        btnDel.className = 'btn-icon-delete';
        btnDel.innerHTML = `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <polyline points="3,6 5,6 21,6"></polyline>
          <path d="m5,6 1,14c0,1.1 0.9,2 2,2h8c1.1,0 2-0.9 2-2l1-14"></path>
          <path d="m10,11 0,6"></path>
          <path d="m14,11 0,6"></path>
          <path d="m8,6 0,-2c0,-1.1 0.9,-2 2,-2h4c1.1,0 2,0.9 2,2v2"></path>
        </svg>`;
        btnDel.title = 'Delete';
        btnDel.addEventListener('click', () => openConfirm(u.id));
        tdAct.append(btnPdf, btnDel);
        tr.append(tdUser, tdForm, tdWhen, tdAct);
        frag.appendChild(tr);
      });
      userRows.appendChild(frag);
    };

    let users = [];
    let activeForm = null; // 'gold' | 'bike' | 'cash' | null

    const applyFilters = () => {
      const q = (searchInput?.value || '').trim().toLowerCase();
      const list = users.filter(u => {
        const matchesForm = activeForm ? u.form === activeForm : true;
        if (!q) return matchesForm;
        const inName = u.name && u.name.toLowerCase().includes(q);
        const inPhone = u.phone && u.phone.toLowerCase().includes(q);
        const inForm = u.form && u.form.toLowerCase().includes(q);
        const inWhen = u.createdAt && formatDT(u.createdAt).toLowerCase().includes(q);
        return matchesForm && (inName || inPhone || inForm || inWhen);
      });
      renderUsers(list);
    };

    // Confirm delete modal wiring
    const modal = document.getElementById('confirmModal');
    const confirmDeleteBtn = document.getElementById('confirmDelete');
    const confirmCancelBtn = document.getElementById('confirmCancel');
    let pendingDeleteUserId = null;

    const openConfirm = (id) => {
      pendingDeleteUserId = id;
      if (modal) modal.classList.remove('hidden');
    };
    const closeConfirm = () => {
      if (modal) modal.classList.add('hidden');
      pendingDeleteUserId = null;
    };
    if (confirmCancelBtn) confirmCancelBtn.addEventListener('click', closeConfirm);
    if (modal) modal.addEventListener('click', (e) => { if (e.target === modal) closeConfirm(); });
    if (confirmDeleteBtn) {
      confirmDeleteBtn.addEventListener('click', async () => {
        if (!pendingDeleteUserId) return;
        const prevText = confirmDeleteBtn.textContent;
        confirmDeleteBtn.disabled = true; confirmDeleteBtn.textContent = 'Deleting...';
        try {
          const res = await fetch('asset/forms/api/delete_user.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: pendingDeleteUserId })
          });
          const data = await res.json().catch(() => ({}));
          if (!res.ok || !data.ok) throw new Error(data && data.error ? data.error : 'Delete failed');
          // Reload users and counts from backend
          const { list, counts } = await getUsers();
          users = list;
          renderCounts(counts);
          closeConfirm();
          applyFilters();
        } catch (_) {
          // keep modal open but re-enable button
        } finally {
          confirmDeleteBtn.disabled = false; confirmDeleteBtn.textContent = prevText || 'Delete';
        }
      });
    }

    // Logout confirmation modal wiring
    const logoutModal = document.getElementById('logoutModal');
    const logoutConfirmBtn = document.getElementById('logoutConfirm');
    const logoutCancelBtn = document.getElementById('logoutCancel');

    const openLogoutConfirm = () => {
      if (logoutModal) logoutModal.classList.remove('hidden');
    };
    const closeLogoutConfirm = () => {
      if (logoutModal) logoutModal.classList.add('hidden');
    };
    if (logoutCancelBtn) logoutCancelBtn.addEventListener('click', closeLogoutConfirm);
    if (logoutModal) logoutModal.addEventListener('click', (e) => { if (e.target === logoutModal) closeLogoutConfirm(); });
    if (logoutConfirmBtn) {
      logoutConfirmBtn.addEventListener('click', async () => {
        try { localStorage.removeItem('auth'); } catch (_) {}
        try {
          await fetch('logout.php', { method: 'POST', credentials: 'same-origin', cache: 'no-store' });
        } catch (_) {}
        window.location.replace('index.html');
      });
    }

    // Initial load from backend and render
    (async () => {
      try {
        const { list, counts } = await getUsers();
        users = list;
        renderCounts(counts);
      } catch (e) {
        renderCounts({ gold:0, cash:0, bike:0 });
      } finally {
        applyFilters();
      }
    })();

    // Search by name, phone, or form (case-insensitive)
    if (searchInput) {
      searchInput.addEventListener('input', () => applyFilters());
    }

    // Filter buttons: toggle active and set activeForm
    if (filterButtons.length) {
      filterButtons.forEach(btn => {
        btn.addEventListener('click', () => {
          const val = btn.getAttribute('data-form');
          // Toggle: clicking the same active filter clears it
          if (activeForm === val) {
            activeForm = null;
            btn.classList.remove('active');
          } else {
            activeForm = val;
            filterButtons.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
          }
          applyFilters();
        });
      });
    }
  }
}
