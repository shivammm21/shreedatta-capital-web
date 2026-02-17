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

// If we're on the login page and already authenticated, redirect to the right dashboard
const form = document.getElementById('loginForm');
if (form) {
  (async () => {
    try {
      const res = await fetch('asset/db/session.php', { headers: { 'Accept': 'application/json' }, cache: 'no-store' });
      const data = await res.json().catch(() => ({}));
      if (res.ok && data && data.ok && data.redirect) {
        let r = data.redirect;
        if (typeof r === 'string' && r.startsWith('/')) r = r.slice(1);
        window.location.href = r;
        return;
      }
    } catch (_) { /* ignore */ }
  })();

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const errorEl = document.getElementById('error');
    if (errorEl) errorEl.textContent = '';
    const btn = form.querySelector('button[type="submit"]');
    const prevText = btn ? btn.textContent : '';
    if (btn) { btn.disabled = true; btn.textContent = 'Signing in...'; }

    try {
      const fd = new FormData(form);
      const res = await fetch('asset/db/login.php?ajax=1', {
        method: 'POST',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: fd,
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data || data.ok !== true) {
        const msg = (data && data.error) ? data.error : 'Invalid username or password.';
        if (errorEl) errorEl.textContent = msg;
        return;
      }
      let redirect = data.redirect || 'admin/super/index.php';
      if (typeof redirect === 'string' && redirect.startsWith('/')) redirect = redirect.slice(1);
      window.location.href = redirect;
    } catch (err) {
      if (errorEl) errorEl.textContent = 'Network error. Please try again.';
    } finally {
      if (btn) { btn.disabled = false; btn.textContent = prevText || 'Sign in'; }
    }
  });
}

// Dashboard guard and interactions
const dashboardRoot = document.getElementById('dashboard');
if (dashboardRoot) {
  // Determine which dashboard we are on
  const inSuper = location.pathname.includes('/admin/super/');
  const inSub = location.pathname.includes('/admin/sub/');
  // Only SUPER can add/edit/delete categories from UI; SUB is read-only
  const isAdmin = inSuper;
  
  // Wire up logout with confirmation
    const logoutBtn = document.getElementById('logout');
    if (logoutBtn) {
      logoutBtn.addEventListener('click', () => {
        openLogoutConfirm();
      });
    }

    // Render absolute URLs for category links using data-path (static)
    const origin = window.location.origin || '';
    const linkEls = dashboardRoot.querySelectorAll('.category-links a.url');
    linkEls.forEach(a => {
      const dataPath = a.getAttribute('data-path') || '';
      const href = dataPath || a.getAttribute('href') || '';
      // Set actual href so clicking opens the static page
      if (href) a.setAttribute('href', href);
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

    // Detect root prefix when running under /admin/super/ or /admin/sub/
    const getRootPrefix = () => (
      location.pathname.includes('/admin/super/') || location.pathname.includes('/admin/sub/')
        ? '../../'
        : ''
    );

    // Load users + counts from static JSON
    const getUsers = async () => {
      const res = await fetch(`${getRootPrefix()}asset/forms/api/users.json`, { cache: 'no-store' });
      const data = await res.json().catch(() => ({}));
      if (!res.ok) throw new Error('Failed to load users');
      const list = (data.users || []).map(u => ({
        id: String(u.id),
        name: `${u.firstName || ''} ${u.lastName || ''}`.trim(),
        form: String(u.drawCategory || '').toLowerCase(),
        drawName: u.drawName || '',
        createdAt: parseMySQLDateTime(u.dateAndTime),
      }));
      const counts = data.counts || { gold: 0, cash: 0, bike: 0, car: 0 };
      return { list, counts };
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
      return `/asset/forms/pdfFiles/${safeName}_${safeDraw}.pdf`;
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

    // Helpers to recalc counts from local users list (static mode)
    const recalcCounts = (list) => {
      const counts = { gold: 0, cash: 0, bike: 0, car: 0 };
      list.forEach(u => {
        if (u.form === 'gold') counts.gold++;
        else if (u.form === 'cash') counts.cash++;
        else if (u.form === 'bike') counts.bike++;
        else if (u.form === 'car') counts.car++;
      });
      return counts;
    };

    // Render summary stats if present
    const renderStats = (counts = { gold:0, cash:0, bike:0, car:0 }) => {
      const gold = counts.gold || 0;
      const cash = counts.cash || 0;
      const bike = counts.bike || 0;
      const car  = counts.car  || 0;
      const total = gold + cash + bike + car;
      const setText = (id, val) => {
        const el = document.getElementById(id);
        if (el) el.textContent = String(val);
      };
      setText('stats-total', total);
      setText('stats-gold', gold);
      setText('stats-cash', cash);
      setText('stats-bike', bike);
      setText('stats-car', car);
    };

    // Super admin: categories grid (4 columns) + plus card
    const suGrid = document.getElementById('su-grid');
    const suTotal = document.getElementById('su-total');
    const suCategories = document.getElementById('su-categories');

    const loadCustomCategories = () => {
      try { return JSON.parse(localStorage.getItem('customCategories') || '[]'); } catch { return []; }
    };
    const saveCustomCategories = (arr) => {
      try { localStorage.setItem('customCategories', JSON.stringify(arr || [])); } catch {}
    };

    const normalizeCat = (s) => String(s || '').trim().toLowerCase().replace(/\s+/g, ' ');
    const displayName = (s) => s ? s.charAt(0).toUpperCase() + s.slice(1) : '';

    const buildCategoryCounts = (usersList, baseCounts = {}) => {
      // Start empty to avoid showing categories with 0 by default
      const cnt = {};
      // Include only base counts that are > 0
      Object.keys(baseCounts || {}).forEach(k => {
        const v = Number(baseCounts[k] || 0);
        if (v > 0) cnt[k] = v;
      });
      // Derive from users list
      (usersList || []).forEach(u => {
        const key = normalizeCat(u.form);
        if (!key) return;
        cnt[key] = (cnt[key] || 0) + 1;
      });
      // Include any custom categories (with 0 if not present)
      loadCustomCategories().forEach(c => {
        const key = normalizeCat(c);
        if (key && !(key in cnt)) cnt[key] = 0;
      });
      return cnt;
    };

    // Add Category modal wiring
    const addCatModal = document.getElementById('addCategoryModal');
    const addCatInput = document.getElementById('addCategoryInput');
    const addCatCancel = document.getElementById('addCategoryCancel');
    const addCatConfirm = document.getElementById('addCategoryConfirm');

    // Rename Category modal wiring
    const renCatModal = document.getElementById('renameCategoryModal');
    const renCatInput = document.getElementById('renameCategoryInput');
    const renCatCancel = document.getElementById('renameCategoryCancel');
    const renCatConfirm = document.getElementById('renameCategoryConfirm');
    let pendingRenameKey = null;

    const openAddCategoryModal = () => {
      if (!addCatModal) return;
      addCatModal.classList.remove('hidden');
      if (addCatInput) {
        addCatInput.value = '';
        setTimeout(() => addCatInput.focus(), 0);
      }
    };
    const closeAddCategoryModal = () => {
      if (!addCatModal) return;
      addCatModal.classList.add('hidden');
    };

    // Cancel confirmation functions
    window.showCancelConfirmation = () => {
      // Check if any data has been entered
      const hasData = checkIfFormDataEntered();
      if (hasData) {
        const modal = document.getElementById('cancelModal');
        if (modal) modal.classList.remove('hidden');
      } else {
        closeAddCategoryModal();
      }
    };

    const checkIfFormDataEntered = () => {
      const name = addCatInput ? addCatInput.value.trim() : '';
      return !!name;
    };

    window.closeCancelModal = () => {
      const modal = document.getElementById('cancelModal');
      if (modal) modal.classList.add('hidden');
    };

    window.confirmCancel = () => {
      closeCancelModal();
      // Check which modal is currently open and close it
      const addModal = document.getElementById('addCategoryModal');
      const renameModal = document.getElementById('renameCategoryModal');
      
      if (addModal && !addModal.classList.contains('hidden')) {
        closeAddCategoryModal();
      } else if (renameModal && !renameModal.classList.contains('hidden')) {
        closeRenameCategoryModal();
      }
    };

    // Prevent confirmation modal from closing on outside click
    const cancelModal = document.getElementById('cancelModal');
    if (cancelModal) {
      cancelModal.addEventListener('click', (e) => {
        e.stopPropagation();
      });
    }
    if (addCatCancel) addCatCancel.addEventListener('click', window.showCancelConfirmation);
    // Remove outside click handler to prevent modal from closing

    const handleAddCategory = async (countsObj) => {
      const name = addCatInput ? addCatInput.value : '';
      const key = normalizeCat(name);
      if (!key) { if (addCatInput) addCatInput.focus(); return; }

      // If we're in SUPER admin area, persist to backend table `forms_data`
      const inSuper = location.pathname.includes('/admin/super/');
      if (inSuper) {
        try {
          const fd = new FormData();
          fd.append('name', name.trim());
          const res = await fetch('/admin/super/api/category_add.php', {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: fd,
          });
          const data = await res.json().catch(() => ({}));
          if (!res.ok || !data || data.ok !== true) {
            const msg = (data && data.error) ? data.error : 'Failed to add category';
            const detail = (data && data.detail) ? `\nDetails: ${data.detail}` : '';
            alert(msg + detail);
            return;
          }
        } catch (_) {
          alert('Network error. Please try again.');
          return;
        }
      } else {
        // Static/local fallback: remember locally
        const custom = loadCustomCategories();
        if (!custom.includes(key)) {
          custom.push(key);
          saveCustomCategories(custom);
        }
      }

      const merged = { ...countsObj };
      if (!(key in merged)) merged[key] = 0;
      renderSuperGrid(merged);
      closeAddCategoryModal();
    };
    if (addCatConfirm) addCatConfirm.addEventListener('click', () => handleAddCategory(lastRenderedCounts || {}));
    if (addCatInput) addCatInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); handleAddCategory(lastRenderedCounts || {}); } });

    let lastRenderedCounts = null;
    // Map normalized category name -> forms_data.id
    let catIdMap = {};
    // Server-provided total users (rows in `all-submissions`). If null, UI falls back to derived sum
    let serverTotalUsers = null;

    const openRenameCategoryModal = (key) => {
      pendingRenameKey = key;
      if (!renCatModal) return;
      renCatModal.classList.remove('hidden');
      if (renCatInput) {
        renCatInput.value = displayName(key);
        setTimeout(() => renCatInput.focus(), 0);
      }
    };
    const closeRenameCategoryModal = () => {
      if (!renCatModal) return;
      renCatModal.classList.add('hidden');
      pendingRenameKey = null;
    };

    // Rename modal cancel confirmation functions
    window.showRenameCancelConfirmation = () => {
      // Check if any data has been changed
      const hasChanges = checkIfRenameFormDataChanged();
      if (hasChanges) {
        const modal = document.getElementById('cancelModal');
        if (modal) modal.classList.remove('hidden');
      } else {
        closeRenameCategoryModal();
      }
    };

    const checkIfRenameFormDataChanged = () => {
      if (!pendingRenameKey || !renCatInput) return false;
      
      const originalName = displayName(pendingRenameKey);
      const currentName = renCatInput.value.trim();
      
      return currentName !== originalName;
    };
    if (renCatCancel) renCatCancel.addEventListener('click', window.showRenameCancelConfirmation);
    // Remove outside click handler to prevent modal from closing
    const handleRenameCategory = async (countsObj) => {
      const key = pendingRenameKey;
      if (!key) return;
      const newName = renCatInput ? renCatInput.value : '';
      const newKey = normalizeCat(newName);
      if (!newKey || newKey === key) { if (!newKey && renCatInput) renCatInput.focus(); return; }

      const inSuper = location.pathname.includes('/admin/super/');
      if (inSuper) {
        try {
          const fd = new FormData();
          fd.append('old', key);
          fd.append('new', newName.trim());
          const res = await fetch('/admin/super/api/category_rename.php', {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: fd,
          });
          const data = await res.json().catch(() => ({}));
          if (!res.ok || !data || data.ok !== true) {
            alert((data && data.error) ? data.error : 'Failed to rename category');
            return;
          }
        } catch (_) {
          alert('Network error. Please try again.');
          return;
        }
      } else {
        // keep local list updated for static mode
        const custom = loadCustomCategories().filter(Boolean).map(normalizeCat);
        const idx = custom.indexOf(key);
        if (idx !== -1) { custom[idx] = newKey; saveCustomCategories(Array.from(new Set(custom))); }
      }
      // rename in counts object (UI)
      const updated = { ...countsObj };
      updated[newKey] = (updated[newKey] || 0) + (updated[key] || 0);
      delete updated[key];
      renderSuperGrid(updated);
      closeRenameCategoryModal();
    };
    if (renCatConfirm) renCatConfirm.addEventListener('click', () => handleRenameCategory(lastRenderedCounts || {}));
    if (renCatInput) renCatInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); handleRenameCategory(lastRenderedCounts || {}); } });

    const renderSuperGrid = (countsObj) => {
      if (!suGrid) return;
      const entries = Object.entries(countsObj || {})
        .filter(([k]) => k); // ignore empty
      const derivedTotal = entries.reduce((acc, [,v]) => acc + Number(v||0), 0);
      const totalUsersToShow = (serverTotalUsers != null) ? serverTotalUsers : derivedTotal;
      if (suTotal) suTotal.textContent = String(totalUsersToShow);
      if (suCategories) suCategories.textContent = String(entries.length);
      lastRenderedCounts = { ...countsObj };
      const frag = document.createDocumentFragment();
      // Create a card for each category
      entries.forEach(([key, val]) => {
        const card = document.createElement('div');
        card.className = 'cat-card';
        const name = document.createElement('div');
        name.className = 'cat-name';
        name.textContent = displayName(key);
        const count = document.createElement('div');
        count.className = 'cat-count';
        count.textContent = String(val || 0);
        // actions
        const actions = document.createElement('div');
        actions.className = 'cat-actions';
        
        // Only show edit and delete buttons for admin users
        if (isAdmin) {
          const editBtn = document.createElement('button');
          editBtn.type = 'button';
          editBtn.className = 'icon-btn-sm';
          editBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/></svg>';
          editBtn.setAttribute('aria-label', 'Edit category');
          editBtn.title = 'Edit';
          editBtn.addEventListener('click', () => openRenameCategoryModal(key));
          const delBtn = document.createElement('button');
          delBtn.type = 'button';
          delBtn.className = 'icon-btn-sm';
          delBtn.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3,6 5,6 21,6"></polyline><path d="m5,6 1,14c0,1.1 0.9,2 2,2h8c1.1,0 2,0.9 2,2l1-14"></path><path d="m10,11 0,6"></path><path d="m14,11 0,6"></path><path d="m8,6 0,-2c0,-1.1 0.9,-2 2,-2h4c1.1,0 2,0.9 2,2v2"></path></svg>';
          delBtn.setAttribute('aria-label', 'Delete category');
          delBtn.title = 'Delete';
          delBtn.addEventListener('click', () => {
            const cm = document.getElementById('confirmModal');
            const confirmDeleteBtn = document.getElementById('confirmDelete');
            const confirmCancelBtn = document.getElementById('confirmCancel');
            if (!cm || !confirmDeleteBtn || !confirmCancelBtn) return;
            cm.classList.remove('hidden');
            const onCancel = () => {
              cm.classList.add('hidden');
              confirmCancelBtn.removeEventListener('click', onCancel);
              confirmDeleteBtn.removeEventListener('click', onConfirm);
            };
            const onConfirm = async () => {
              try {
                const inSuper = location.pathname.includes('/admin/super/');
                const catId = (catIdMap && catIdMap[key]) ? Number(catIdMap[key]) : 0;
                if (inSuper && catId > 0) {
                  const fd = new FormData();
                  fd.append('category_id', String(catId));
                  const res = await fetch('/admin/super/api/category_delete.php', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: fd,
                  });
                  const data = await res.json().catch(() => ({}));
                  if (!res.ok || !data || data.ok !== true) {
                    alert((data && data.error) ? data.error : 'Failed to delete category');
                  }
                }
              } catch (_) { /* ignore errors and proceed to refresh UI */ }
              finally {
                // Try to refresh from server to reflect latest counts and categories
                try {
                  const res2 = await fetch('/admin/super/api/category_list.php', { headers: { 'Accept': 'application/json' }, cache: 'no-store' });
                  const data2 = await res2.json().catch(() => ({}));
                  if (res2.ok && data2 && Array.isArray(data2.categories)) {
                    const merged = {};
                    catIdMap = {};
                    data2.categories.forEach(item => {
                      const name = typeof item === 'string' ? item : (item && item.name) || '';
                      const id = typeof item === 'object' && item ? Number(item.id) : undefined;
                      const subCount = typeof item === 'object' && item && item.sub_count != null ? Number(item.sub_count) : 0;
                      const k = normalizeCat(name);
                      if (!k) return;
                      if (id && !Number.isNaN(id)) { catIdMap[k] = id; }
                      merged[k] = subCount;
                    });
                    renderSuperGrid(merged);
                  } else {
                    const updated = { ...countsObj };
                    delete updated[key];
                    const custom = loadCustomCategories().filter(c => normalizeCat(c) !== key);
                    saveCustomCategories(custom);
                    renderSuperGrid(updated);
                  }
                } catch (_) {
                  const updated = { ...countsObj };
                  delete updated[key];
                  const custom = loadCustomCategories().filter(c => normalizeCat(c) !== key);
                  saveCustomCategories(custom);
                  renderSuperGrid(updated);
                }
                onCancel();
              }
            };
            confirmCancelBtn.addEventListener('click', onCancel, { once: true });
            confirmDeleteBtn.addEventListener('click', onConfirm, { once: true });
          });
          actions.append(editBtn, delBtn);
        }
        // clicking the card navigates to category page (except on action buttons)
        card.addEventListener('click', (ev) => {
          if (ev.target.closest('.cat-actions')) return;
          const inSuper = location.pathname.includes('/admin/super/');
          const inSub = location.pathname.includes('/admin/sub/');
          const cid = catIdMap && catIdMap[key] ? `&category_id=${encodeURIComponent(String(catIdMap[key]))}` : '';
          let href;
          if (inSuper || inSub) {
            // Use relative navigation within the current admin area
            href = `category.html?name=${encodeURIComponent(key)}${cid}`;
          } else {
            // Fallback (should not happen in normal flow)
            href = `/admin/super/category.html?name=${encodeURIComponent(key)}${cid}`;
          }
          window.location.href = href;
        });
        card.append(actions, name, count);
        frag.appendChild(card);
      });
      // Plus card - only for admin users
      if (isAdmin) {
        const add = document.createElement('div');
        add.className = 'cat-card add-card';
        add.title = 'Add new category';
        const plus = document.createElement('div');
        plus.className = 'add-plus';
        plus.textContent = '+';
        add.appendChild(plus);
        add.addEventListener('click', openAddCategoryModal);
        frag.appendChild(add);
      }
      suGrid.innerHTML = '';
      suGrid.appendChild(frag);
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
          // Call backend to delete from DB when in SUPER area
          const inSuper = location.pathname.includes('/admin/super/');
          if (inSuper) {
            try {
              const fd = new FormData();
              fd.append('user_id', String(pendingDeleteUserId));
              const res = await fetch('/admin/super/api/user_delete.php', {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: fd,
              });
              const data = await res.json().catch(() => ({}));
              if (!res.ok || !data || data.ok !== true) {
                throw new Error((data && data.error) ? data.error : 'Delete failed');
              }
            } catch (e) {
              alert('Failed to delete from server: ' + (e && e.message ? e.message : 'Unknown error'));
              return; // keep modal open
            }
          }
          // Update local list and UI
          users = users.filter(u => String(u.id) !== String(pendingDeleteUserId));
          const counts = recalcCounts(users);
          renderCounts(counts);
          const merged = buildCategoryCounts(users, counts);
          renderSuperGrid(merged);
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
        window.location.href = '/asset/db/logout.php';
      });
    }

    // Pre-render super grid so the plus card is visible immediately
    try { renderSuperGrid(buildCategoryCounts([], {})); } catch {}

    // Initial load from backend and render
    (async () => {
      try {
        const { list, counts } = await getUsers();
        users = list;
        renderCounts(counts);
        renderStats(counts);
        // Build and render super grid with plus card
        let merged = buildCategoryCounts(users, counts);
        // If we are in SUPER or SUB dashboard, also load categories from DB and merge
        if (inSuper || inSub) {
          try {
            const endpoint = inSuper
              ? '/admin/super/api/category_list.php'
              : '/admin/sub/api/category_list.php';
            const res = await fetch(endpoint, { headers: { 'Accept': 'application/json' }, cache: 'no-store' });
            const data = await res.json().catch(() => ({}));
            if (res.ok && data && Array.isArray(data.categories)) {
              merged = { ...merged };
              // data.categories may be array of strings (old) or objects {id,name,sub_count} (new)
              catIdMap = {};
              data.categories.forEach(item => {
                const name = typeof item === 'string' ? item : (item && item.name) || '';
                const id = typeof item === 'object' && item ? Number(item.id) : undefined;
                const subCount = typeof item === 'object' && item && item.sub_count != null ? Number(item.sub_count) : undefined;
                const key = normalizeCat(name);
                if (!key) return;
                if (id && !Number.isNaN(id)) { catIdMap[key] = id; }
                // Use backend-provided sub_count as the number on each main container
                if (subCount != null && !Number.isNaN(subCount)) {
                  merged[key] = subCount;
                } else if (!(key in merged)) {
                  merged[key] = 0;
                }
              });
            }
          } catch (_) { /* ignore */ }

          // Also fetch total users (rows in all-submissions) for header badge (SUPER only)
          try {
            const subsRes = await fetch('/admin/super/api/get_submissions.php', { headers: { 'Accept': 'application/json' }, cache: 'no-store' });
            const subsData = await subsRes.json().catch(() => ({}));
            if (subsRes.ok && subsData && subsData.success && typeof subsData.count === 'number') {
              serverTotalUsers = subsData.count;
            } else {
              serverTotalUsers = null;
            }
          } catch (_) { serverTotalUsers = null; }
        }
        renderSuperGrid(merged);
      } catch (e) {
        const zero = { gold:0, cash:0, bike:0, car:0 };
        renderCounts(zero);
        renderStats(zero);
        const merged = buildCategoryCounts([], zero);
        renderSuperGrid(merged);
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
