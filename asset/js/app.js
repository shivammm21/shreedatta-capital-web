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

    // Validate credentials
    if (username === 'admin' && password === 'admin') {
      // naive auth flag
      try { localStorage.setItem('auth', '1'); } catch (_) {}
      window.location.href = 'dashboard.html';
      return;
    }

    if (error) error.textContent = 'Invalid credentials. Try admin/admin.';
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
    // Wire up logout
    const logoutBtn = document.getElementById('logout');
    if (logoutBtn) {
      logoutBtn.addEventListener('click', () => {
        try { localStorage.removeItem('auth'); } catch (_) {}
        window.location.replace('index.html');
      });
    }

    // Render absolute URLs for category links (single link per block)
    const origin = window.location.origin || '';
    const linkEls = dashboardRoot.querySelectorAll('.category-links a[data-path]');
    linkEls.forEach(a => {
      const path = a.getAttribute('data-path') || '';
      const absolute = `${origin}${path}`;
      a.href = absolute;
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
      const getText = () => urlEl.getAttribute('href') || '';
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

    // Populate counts from localStorage (and util to re-render counts)
    const countEls = dashboardRoot.querySelectorAll('[data-count-key]');
    const renderCounts = () => {
      countEls.forEach(el => {
        const key = el.getAttribute('data-count-key');
        let val = 0;
        try {
          const raw = localStorage.getItem(key);
          const n = parseInt(raw, 10);
          val = Number.isFinite(n) ? n : 0;
        } catch (_) { /* noop */ }
        el.textContent = String(val);
      });
    };
    renderCounts();

    // User search and results rendering
    const userRows = document.getElementById('user-rows');
    const userEmpty = document.getElementById('user-empty');
    const searchInput = document.getElementById('user-search-input');
    const filterButtons = Array.from(dashboardRoot.querySelectorAll('.filter-btn'));

    const getUsers = () => {
      try {
        const raw = localStorage.getItem('users');
        if (raw) {
          const parsed = JSON.parse(raw);
          // Backfill createdAt if missing
          const withDates = parsed.map((u, i) => ({
            ...u,
            createdAt: u.createdAt || new Date(Date.now() - (parsed.length - i) * 3600_000).toISOString(),
          }));
          try { localStorage.setItem('users', JSON.stringify(withDates)); } catch (_) {}
          return withDates;
        }
      } catch (_) {}
      // Seed demo users if none
      const now = Date.now();
      const seed = [
        { id: 'G-1001', name: 'Ravi Kumar',   phone: '9876543210', form: 'gold', createdAt: new Date(now - 6*3600_000).toISOString() },
        { id: 'C-2001', name: 'Pooja Shah',   phone: '9123456780', form: 'cash', createdAt: new Date(now - 5*3600_000).toISOString() },
        { id: 'B-3001', name: 'Amit Verma',   phone: '9012345678', form: 'bike', createdAt: new Date(now - 4*3600_000).toISOString() },
        { id: 'G-1002', name: 'Sanjay Patel', phone: '9811112222', form: 'gold', createdAt: new Date(now - 3*3600_000).toISOString() },
        { id: 'C-2002', name: 'Neha Gupta',   phone: '9822223333', form: 'cash', createdAt: new Date(now - 2*3600_000).toISOString() },
        { id: 'B-3002', name: 'Rahul Singh',  phone: '9833334444', form: 'bike', createdAt: new Date(now - 90*60_000).toISOString() },
        { id: 'G-1003', name: 'Anita Desai',  phone: '9844445555', form: 'gold', createdAt: new Date(now - 60*60_000).toISOString() },
        { id: 'C-2003', name: 'Vikas Mehta',  phone: '9855556666', form: 'cash', createdAt: new Date(now - 45*60_000).toISOString() },
        { id: 'B-3003', name: 'Kiran Rao',    phone: '9866667777', form: 'bike', createdAt: new Date(now - 30*60_000).toISOString() },
        { id: 'G-1004', name: 'Priya Nair',   phone: '9877778888', form: 'gold', createdAt: new Date(now - 10*60_000).toISOString() },
      ];
      try { localStorage.setItem('users', JSON.stringify(seed)); } catch (_) {}
      return seed;
    };

    const buildUrl = (u) => {
      const base = window.location.origin || '';
      if (u.form === 'gold') return `${base}/gold/registration/${u.id}`;
      if (u.form === 'cash') return `${base}/cash/payouts/${u.id}`;
      return `${base}/bike/participants/${u.id}`;
    };

    const buildPdfUrl = (u) => `${buildUrl(u)}/pdf`;

    const formatDT = (iso) => {
      if (!iso) return '';
      try {
        const d = new Date(iso);
        const date = d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: '2-digit' });
        const time = d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', hour12: true });
        return `${date} ${time}`;
      } catch { return iso; }
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
        tdForm.textContent = u.form;
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
        btnDel.className = 'btn-danger';
        btnDel.textContent = 'Delete';
        btnDel.addEventListener('click', () => openConfirm(u.id));
        tdAct.append(btnPdf, btnDel);
        tr.append(tdUser, tdForm, tdWhen, tdAct);
        frag.appendChild(tr);
      });
      userRows.appendChild(frag);
    };

    let users = getUsers();
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
      confirmDeleteBtn.addEventListener('click', () => {
        if (!pendingDeleteUserId) return;
        // Remove user from list
        users = users.filter(u => u.id !== pendingDeleteUserId);
        try { localStorage.setItem('users', JSON.stringify(users)); } catch (_) {}
        // Update per-form counts in storage then re-render
        const gold = users.filter(u => u.form === 'gold').length;
        const cash = users.filter(u => u.form === 'cash').length;
        const bike = users.filter(u => u.form === 'bike').length;
        try {
          localStorage.setItem('gold_registration', String(gold));
          localStorage.setItem('cash_registration', String(cash));
          localStorage.setItem('bike_registration', String(bike));
        } catch (_) {}
        renderCounts();
        closeConfirm();
        applyFilters();
      });
    }

    // Initial render
    applyFilters();

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
