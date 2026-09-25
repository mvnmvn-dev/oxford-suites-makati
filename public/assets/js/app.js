/**
 * Oxford Suites RMS — API client + Tailwind UI shell helpers
 */
(function (global) {
  // Works both from XAMPP (/oxford-rms) and from the production domain root.
  const BASE = window.location.pathname.startsWith('/oxford-rms') ? '/oxford-rms' : '';
  const API = BASE + '/api';
  let csrfPromise = null;

  async function csrfToken() {
    if (!csrfPromise) csrfPromise = (async () => {
      const controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(), 10000);
      try {
        const response = await fetch(API + '/auth/csrf', { credentials: 'include', signal: controller.signal });
        const result = await response.json();
        return result.data?.token || '';
      } finally {
        clearTimeout(timeout);
      }
    })();
    try { return await csrfPromise; } catch (_) { csrfPromise = null; return ''; }
  }

  async function api(path, options = {}) {
    const method = String(options.method || 'GET').toUpperCase();
    const headers = { 'Content-Type': 'application/json', ...(options.headers || {}) };
    if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(method) && !/^\/auth\/(login|register)/.test(path)) {
      const token = await csrfToken();
      if (token) headers['X-CSRF-Token'] = token;
    }
    const opts = {
      credentials: 'include',
      headers,
      ...options,
    };
    if (opts.body && typeof opts.body === 'object' && !(opts.body instanceof FormData)) {
      opts.body = JSON.stringify(opts.body);
    }
    const controller = opts.signal ? null : new AbortController();
    const timeout = controller ? setTimeout(() => controller.abort(), 20000) : null;
    if (controller) opts.signal = controller.signal;
    let res;
    try {
      res = await fetch(API + path, opts);
    } catch (error) {
      if (error.name === 'AbortError') throw new Error('The request timed out. Please try again.');
      throw error;
    } finally {
      if (timeout) clearTimeout(timeout);
    }
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data.success === false) {
      const err = new Error(data.error || res.statusText || 'Request failed');
      err.status = res.status;
      err.data = data;
      throw err;
    }
    return data;
  }

  function paginateTables(root = document) {
    root.querySelectorAll('table').forEach((table) => {
      if (table.dataset.paginated === '1') return;
      const body = table.tBodies?.[0];
      if (!body) return;
      const rows = Array.from(body.rows);
      table.dataset.paginated = '1';
      if (rows.length <= 5) return;
      const controls = document.createElement('div');
      controls.className = 'rms-pagination mt-3 flex items-center justify-between gap-3 text-xs text-charcoal-muted';
      let page = 0;
      const render = () => {
        const pages = Math.ceil(rows.length / 5);
        rows.forEach((row, i) => { row.hidden = i < page * 5 || i >= (page + 1) * 5; });
        controls.innerHTML = `<span>Showing ${Math.min(page * 5 + 1, rows.length)}–${Math.min((page + 1) * 5, rows.length)} of ${rows.length}</span><span class="flex gap-1"><button type="button" class="btn-sm" data-page="prev" ${page === 0 ? 'disabled' : ''}>Previous</button><button type="button" class="btn-sm" data-page="next" ${page >= pages - 1 ? 'disabled' : ''}>Next</button></span>`;
      };
      controls.addEventListener('click', (event) => { const action = event.target.closest('[data-page]')?.dataset.page; if (action === 'prev') page--; if (action === 'next') page++; render(); });
      table.after(controls);
      render();
    });
  }

  function observeTables() {
    paginateTables(document);
    new MutationObserver(() => paginateTables(document)).observe(document.body, { childList: true, subtree: true });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', observeTables, { once: true }); else observeTables();

  /**
   * Live notification stream (SSE) with automatic browser reconnect.
   * Falls back silently when EventSource is unavailable.
   */
  function connectNotificationStream(handlers = {}) {
    if (typeof EventSource === 'undefined') return null;
    let es = null;
    try {
      es = new EventSource(API + '/notifications/stream', { withCredentials: true });
    } catch (_) {
      return null;
    }
    es.addEventListener('badge', (ev) => {
      try {
        const data = JSON.parse(ev.data || '{}');
        handlers.onBadge?.(data);
        window.dispatchEvent(new CustomEvent('rms:live', { detail: { kind: 'badge', data } }));
      } catch (_) {}
    });
    es.addEventListener('notification', (ev) => {
      try {
        const data = JSON.parse(ev.data || '{}');
        handlers.onNotification?.(data);
        window.dispatchEvent(new CustomEvent('rms:live', { detail: { kind: 'notification', data } }));
      } catch (_) {}
    });
    es.addEventListener('ping', () => {
      handlers.onPing?.();
    });
    return es;
  }

  function money(n) {
    return '₱' + Number(n || 0).toLocaleString('en-PH', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });
  }

  function initials(name) {
    return String(name || '?')
      .split(/\s+/)
      .map((p) => p[0])
      .slice(0, 2)
      .join('')
      .toUpperCase();
  }

  function formatNow() {
    const d = new Date();
    const time = d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
    const date = d.toLocaleDateString('en-US', {
      weekday: 'short',
      month: 'short',
      day: 'numeric',
      year: 'numeric',
    });
    return time + ' | ' + date;
  }

  const badgeTone = {
    available: 'bg-sage-soft text-sage-deep dark:text-sage',
    occupied: 'bg-ember-soft text-ember',
    reserved: 'bg-brass/25 text-brass-deep dark:text-brass-soft',
    reserved_soon: 'bg-clay-soft text-clay-deep dark:text-clay',
    cleaning: 'bg-clay-soft text-clay-deep dark:text-clay',
    pending: 'bg-clay-soft text-clay-deep dark:text-clay',
    confirmed: 'bg-sage-soft text-sage-deep dark:text-sage',
    seated: 'bg-brass/25 text-brass-deep dark:text-brass-soft',
    completed: 'bg-parchment-deep text-charcoal-muted',
    cancelled: 'bg-parchment-deep text-charcoal-muted',
    no_show: 'bg-parchment-deep text-charcoal-muted',
    preparing: 'bg-clay-soft text-clay-deep dark:text-clay',
    ready: 'bg-sage-soft text-sage-deep dark:text-sage',
    served: 'bg-sage-soft text-sage-deep dark:text-sage',
    paid: 'bg-brass/25 text-brass-deep dark:text-brass-soft',
    new: 'bg-clay-soft text-clay-deep dark:text-clay',
    in_progress: 'bg-brass/25 text-brass-deep dark:text-brass-soft',
    bumped: 'bg-parchment-deep text-charcoal-muted',
    low: 'bg-ember-soft text-ember',
  };

  function statusBadge(status) {
    const tone = badgeTone[status] || 'bg-parchment-deep text-charcoal-muted';
    const label = String(status).replace(/_/g, ' ');
    return `<span data-status="${status}" class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[11px] font-semibold capitalize ${tone}"><span class="h-1.5 w-1.5 rounded-full bg-current opacity-80" aria-hidden="true"></span>${label}</span>`;
  }

  function dishImage(item, sizeClass = 'h-[72px] w-[72px]') {
    const name = (item && item.name) || '?';
    const src = item && item.image_url ? String(item.image_url) : '';
    const letter = (name[0] || '?').toUpperCase();
    if (src) {
      return `<div class="dish-thumb relative ${sizeClass} overflow-hidden rounded-full shadow-inner ring-1 ring-line">
        <img src="${src}" alt="${name.replace(/"/g, '&quot;')}" class="h-full w-full object-cover" loading="lazy" onerror="this.classList.add('hidden');this.nextElementSibling.classList.remove('hidden');this.nextElementSibling.classList.add('grid');" />
        <div class="absolute inset-0 hidden place-items-center bg-gradient-to-br from-brass to-ember font-display text-lg text-white">${letter}</div>
      </div>`;
    }
    return `<div class="${sizeClass} grid place-items-center rounded-full bg-gradient-to-br from-brass to-ember font-display text-lg text-white shadow-inner">${letter}</div>`;
  }

  async function uploadMenuImage(file) {
    const fd = new FormData();
    fd.append('image', file);
    const token = await csrfToken();
    const res = await fetch(API + '/menu/upload', {
      method: 'POST',
      credentials: 'include',
      headers: token ? { 'X-CSRF-Token': token } : {},
      body: fd,
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data.success === false) {
      throw new Error(data.error || 'Upload failed');
    }
    return data.data.url;
  }

  /** Module visibility by staff role — only these appear in the sidebar. */
  const MODULE_ROLES = {
    dashboard: ['admin', 'cashier', 'kitchen'],
    menu: ['admin', 'cashier', 'kitchen'],
    orders: ['admin', 'cashier', 'kitchen'],
    tables: ['admin', 'cashier'],
    reservations: ['admin', 'cashier'],
    kitchen: ['admin', 'kitchen'],
    pos: ['admin', 'cashier'],
    promotions: ['admin'],
    inventory: ['admin', 'kitchen'],
    analytics: ['admin', 'cashier'],
    audit: ['admin'],
  };

  const NAV_ITEMS = [
    ['dashboard', 'Dashboard', 'dashboard.html'],
    ['menu', 'Menu', 'menu.html'],
    ['orders', 'Orders', 'orders.html'],
    ['tables', 'Tables & QR', 'tables.html'],
    ['reservations', 'Reservations', 'reservations.html'],
    ['kitchen', 'Kitchen (KOT)', 'kitchen.html'],
    ['pos', 'POS & Billing', 'pos.html'],
    ['promotions', 'Promotions', 'promotions.html'],
    ['inventory', 'Inventory', 'inventory.html'],
    ['analytics', 'Analytics', 'analytics.html'],
    ['audit', 'Audit & Activity', 'audit.html'],
  ];

  function canAccessModule(role, moduleKey) {
    const allowed = MODULE_ROLES[moduleKey];
    if (!allowed) return false;
    return allowed.includes(role);
  }

  function modulesForRole(role) {
    return NAV_ITEMS.filter(([key]) => canAccessModule(role, key));
  }

  function homeForRole(role) {
    const first = modulesForRole(role)[0];
    return first ? first[2] : 'dashboard.html';
  }

  const AUTH_CACHE_KEY = 'oxford-rms-auth-user';
  const AUTH_CACHE_TTL_MS = 5 * 60 * 1000;

  function getCachedUser() {
    try {
      const raw = sessionStorage.getItem(AUTH_CACHE_KEY);
      if (!raw) return null;
      const parsed = JSON.parse(raw);
      if (!parsed || !parsed.user || !parsed.exp || Date.now() > parsed.exp) {
        sessionStorage.removeItem(AUTH_CACHE_KEY);
        return null;
      }
      return parsed.user;
    } catch (_) {
      return null;
    }
  }

  function cacheAuthUser(user) {
    if (!user) return;
    try {
      sessionStorage.setItem(
        AUTH_CACHE_KEY,
        JSON.stringify({ user, exp: Date.now() + AUTH_CACHE_TTL_MS })
      );
    } catch (_) {}
  }

  function clearAuthCache() {
    try {
      sessionStorage.removeItem(AUTH_CACHE_KEY);
    } catch (_) {}
  }

  async function fetchMe() {
    const res = await api('/auth/me');
    const user = res.data.user;
    cacheAuthUser(user);
    return user;
  }

  async function requireCustomer() {
    try {
      let user = getCachedUser();
      if (!user) user = await fetchMe();
      if (!user || user.role !== 'customer') {
        clearAuthCache();
        window.location.href = BASE + '/public/customer/login.html';
        return null;
      }
      return user;
    } catch (e) {
      clearAuthCache();
      window.location.href = BASE + '/public/customer/login.html';
      return null;
    }
  }

  async function optionalCustomer() {
    try {
      let user = getCachedUser();
      if (!user) user = await fetchMe();
      return user && user.role === 'customer' ? user : null;
    } catch (e) {
      return null;
    }
  }

  async function requireStaff(roles) {
    try {
      let user = getCachedUser();
      if (!user) user = await fetchMe();
      if (!user || user.role === 'customer') {
        clearAuthCache();
        window.location.href = BASE + '/public/customer/';
        return null;
      }
      if (roles && roles.length && !roles.includes(user.role)) {
        return { ...user, _forbidden: true };
      }
      return user;
    } catch (e) {
      clearAuthCache();
      window.location.href = BASE + '/public/login.html';
      return null;
    }
  }

  const icons = {
    dashboard:
      '<svg class="h-[18px] w-[18px] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/></svg>',
    menu:
      '<svg class="h-[18px] w-[18px] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 6h16M4 12h16M4 18h10"/><circle cx="18" cy="18" r="2"/></svg>',
    orders:
      '<svg class="h-[18px] w-[18px] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M7 4h10l1 16H6L7 4z"/><path d="M9 8h6M9 12h6M9 16h4"/></svg>',
    tables:
      '<svg class="h-[18px] w-[18px] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><ellipse cx="12" cy="8" rx="8" ry="3"/><path d="M4 8v4c0 1.7 3.6 3 8 3s8-1.3 8-3V8M6 14v4M18 14v4"/></svg>',
    reservations:
      '<svg class="h-[18px] w-[18px] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg>',
    kitchen:
      '<svg class="h-[18px] w-[18px] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 10h16v9a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-9z"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>',
    pos:
      '<svg class="h-[18px] w-[18px] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="5" y="3" width="14" height="18" rx="2"/><path d="M8 7h8M8 11h8M8 15h5"/></svg>',
    inventory:
      '<svg class="h-[18px] w-[18px] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 7l9-4 9 4-9 4-9-4z"/><path d="M3 7v10l9 4 9-4V7"/></svg>',
    analytics:
      '<svg class="h-[18px] w-[18px] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 19V5M4 19h16"/><path d="M8 16v-5M12 16V8M16 16v-3"/></svg>',
    promotions:
      '<svg class="h-[18px] w-[18px] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3l2.2 4.5 5 .7-3.6 3.5.9 5L12 14.8 7.5 16.7l.9-5L4.8 8.2l5-.7L12 3z"/></svg>',
    audit:
      '<svg class="h-[18px] w-[18px] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12h6M9 16h4"/></svg>',
  };

  function sidebarHtml(prefix, role) {
    const base = prefix || '';
    const items = role ? modulesForRole(role) : NAV_ITEMS;
    return items
      .map(
        ([key, label, href]) =>
          `<li>
            <a href="${base}${href}" data-nav="${key}" title="${label}"
              class="nav-link group flex items-center gap-3 rounded-xl border-l-[3px] border-transparent px-3 py-2.5 text-sm font-medium text-charcoal-muted transition hover:bg-ember/5 hover:text-charcoal">
              ${icons[key] || ''}
              <span class="nav-text truncate">${label}</span>
            </a>
          </li>`
      )
      .join('');
  }

  function forbiddenHtml(user, moduleLabel) {
    const home = homeForRole(user.role);
    return `
      <div class="mx-auto flex max-w-lg flex-col items-center py-16 text-center">
        <div class="grid h-16 w-16 place-items-center rounded-full bg-ember/10 text-ember">
          <svg class="h-8 w-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
            <rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
          </svg>
        </div>
        <h2 class="mt-5 font-display text-2xl font-semibold text-charcoal">Access restricted</h2>
        <p class="mt-2 text-sm leading-relaxed text-charcoal-muted">
          Your <span class="font-semibold capitalize text-charcoal">${user.role}</span> account
          cannot open ${moduleLabel || 'this module'}. Use the sidebar to open a workspace you are allowed to use.
        </p>
        <a href="${home}" class="btn-primary mt-6">Go to your workspace</a>
      </div>`;
  }

  function getTheme() {
    return document.documentElement.classList.contains('dark') ? 'dark' : 'light';
  }

  function setTheme(mode) {
    const dark = mode === 'dark';
    document.documentElement.classList.toggle('dark', dark);
    try {
      localStorage.setItem('oxford-rms-theme', dark ? 'dark' : 'light');
    } catch (e) {}
    syncThemeToggleUI();
    document.dispatchEvent(new CustomEvent('rms:theme', { detail: { theme: dark ? 'dark' : 'light' } }));
  }

  function toggleTheme() {
    setTheme(getTheme() === 'dark' ? 'light' : 'dark');
  }

  function syncThemeToggleUI() {
    const dark = getTheme() === 'dark';
    document.querySelectorAll('[data-theme-light]').forEach((el) => {
      el.classList.toggle('active', !dark);
    });
    document.querySelectorAll('[data-theme-dark]').forEach((el) => {
      el.classList.toggle('active', dark);
    });
    document.querySelectorAll('[data-theme-label]').forEach((el) => {
      el.textContent = dark ? 'Dark' : 'Light';
    });
  }

  function bindThemeControls(root) {
    const scope = root || document;
    scope.querySelectorAll('[data-theme-toggle]').forEach((btn) => {
      if (btn.dataset.bound === '1') return;
      btn.dataset.bound = '1';
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        const mode = btn.getAttribute('data-theme-toggle');
        if (mode === 'light' || mode === 'dark') setTheme(mode);
        else toggleTheme();
      });
    });
    syncThemeToggleUI();
  }

  function themeToggleHtml() {
    return `
      <div class="theme-toggle" role="group" aria-label="Color theme">
        <button type="button" class="theme-opt" data-theme-toggle="light" data-theme-light title="Light mode" aria-label="Light mode">
          <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
        </button>
        <button type="button" class="theme-opt" data-theme-toggle="dark" data-theme-dark title="Dark mode" aria-label="Dark mode">
          <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 14.5A8.5 8.5 0 1 1 9.5 3 7 7 0 0 0 21 14.5z"/></svg>
        </button>
      </div>`;
  }

  function getA11yPrefs() {
    return {
      large: document.documentElement.classList.contains('a11y-large'),
      contrast: document.documentElement.classList.contains('a11y-high-contrast'),
      colorblind: document.documentElement.classList.contains('a11y-colorblind'),
      motion: document.documentElement.classList.contains('a11y-reduced-motion'),
    };
  }

  function setA11yPref(key, on) {
    const map = {
      large: ['a11y-large', 'oxford-rms-a11y-large'],
      contrast: ['a11y-high-contrast', 'oxford-rms-a11y-contrast'],
      colorblind: ['a11y-colorblind', 'oxford-rms-a11y-colorblind'],
      motion: ['a11y-reduced-motion', 'oxford-rms-a11y-motion'],
    };
    const pair = map[key];
    if (!pair) return;
    document.documentElement.classList.toggle(pair[0], !!on);
    try { localStorage.setItem(pair[1], on ? '1' : '0'); } catch (e) {}
    syncA11yUI();
    document.dispatchEvent(new CustomEvent('rms:a11y', { detail: getA11yPrefs() }));
  }

  function syncA11yUI() {
    const prefs = getA11yPrefs();
    document.querySelectorAll('[data-a11y]').forEach((btn) => {
      const key = btn.getAttribute('data-a11y');
      btn.setAttribute('aria-pressed', prefs[key] ? 'true' : 'false');
    });
  }

  function bindA11yControls(root) {
    const scope = root || document;
    scope.querySelectorAll('[data-a11y]').forEach((btn) => {
      if (btn.dataset.boundA11y === '1') return;
      btn.dataset.boundA11y = '1';
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        const key = btn.getAttribute('data-a11y');
        const prefs = getA11yPrefs();
        setA11yPref(key, !prefs[key]);
      });
    });
    syncA11yUI();
  }

  function a11yControlsHtml(compact) {
    return `
      <div class="a11y-bar" role="group" aria-label="Accessibility">
        <button type="button" class="a11y-chip" data-a11y="large" aria-pressed="false" title="Larger text">Aa</button>
        <button type="button" class="a11y-chip" data-a11y="contrast" aria-pressed="false" title="High contrast">HC</button>
        <button type="button" class="a11y-chip" data-a11y="colorblind" aria-pressed="false" title="Colorblind-friendly status colors">${compact ? 'CB' : 'Colorblind'}</button>
        <button type="button" class="a11y-chip" data-a11y="motion" aria-pressed="false" title="Reduced motion">${compact ? 'RM' : 'Less motion'}</button>
      </div>`;
  }

  function mountShell(active, user) {
    const timeEl = document.getElementById('live-time');
    if (timeEl) {
      timeEl.textContent = formatNow();
      setInterval(() => {
        timeEl.textContent = formatNow();
      }, 30000);
    }

    document.querySelectorAll('.nav-list a, #nav-list a').forEach((a) => {
      if (a.dataset.nav === active) {
        a.classList.add('bg-ember/10', 'text-ember', 'border-ember', 'font-semibold', 'is-active');
        a.classList.remove('border-transparent', 'text-charcoal-muted');
        a.setAttribute('aria-current', 'page');
      }
    });

    if (user) {
      document.querySelectorAll('[data-user-name]').forEach((el) => {
        el.textContent = user.name;
      });
      document.querySelectorAll('[data-user-role]').forEach((el) => {
        el.textContent = user.role;
      });
      document.querySelectorAll('[data-user-initials]').forEach((el) => {
        el.textContent = initials(user.name);
      });
    }

    const logout = document.getElementById('logout-btn');
    if (logout) {
      logout.addEventListener('click', async () => {
        clearAuthCache();
        await api('/auth/logout', { method: 'POST', body: {} });
        window.location.href = BASE + '/public/login.html';
      });
    }

    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    const openMobile = () => {
      sidebar?.classList.remove('-translate-x-full');
      overlay?.classList.remove('hidden');
    };
    const closeMobile = () => {
      sidebar?.classList.add('-translate-x-full');
      overlay?.classList.add('hidden');
    };
    overlay?.addEventListener('click', closeMobile);

    bindSidebarToggle({ openMobile, closeMobile });
    bindThemeControls();
    bindA11yControls();
    bindModuleNavTransitions();
  }

  function motionReduced() {
    return (
      document.documentElement.classList.contains('a11y-reduced-motion') ||
      (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches)
    );
  }

  /** Soft exit → navigate → enter when switching staff/guest modules (full page loads). */
  function bindModuleNavTransitions() {
    const panel =
      document.getElementById('suite-module-panel') ||
      document.getElementById('portal-body') ||
      document.querySelector('main');
    if (panel) {
      panel.classList.remove('rms-module-panel-exit');
      panel.classList.remove('rms-module-panel-enter');
      void panel.offsetWidth;
      panel.classList.add('rms-module-panel-enter');
    }

    document.querySelectorAll('a[data-nav]').forEach((a) => {
      if (a.dataset.boundModuleNav === '1') return;
      a.dataset.boundModuleNav = '1';
      a.addEventListener('click', (e) => {
        if (e.defaultPrevented) return;
        if (e.metaKey || e.ctrlKey || e.shiftKey || e.altKey || e.button !== 0) return;
        if (a.getAttribute('target') === '_blank') return;
        const href = a.getAttribute('href');
        if (!href || href.startsWith('#') || href.startsWith('javascript:')) return;

        const root = document.getElementById('app-root');
        const current = root?.dataset?.active || '';
        if (a.dataset.nav && current && a.dataset.nav === current) {
          e.preventDefault();
          return;
        }

        try {
          const next = new URL(href, location.href);
          if (next.pathname === location.pathname && next.search === location.search) return;
        } catch (_) {}

        if (motionReduced()) return;
        if (document.documentElement.dataset.moduleNav === '1') {
          e.preventDefault();
          return;
        }

        e.preventDefault();
        document.documentElement.dataset.moduleNav = '1';
        try { sessionStorage.setItem('oxford-rms-module-nav', '1'); } catch (_) {}

        const leavePanel =
          document.getElementById('suite-module-panel') ||
          document.getElementById('portal-body') ||
          document.querySelector('main');
        document.documentElement.classList.add('rms-module-exit');
        leavePanel?.classList.add('rms-module-panel-exit');

        window.setTimeout(() => {
          location.href = href;
        }, 280);
      });
    });
  }

  function isSidebarCollapsed() {
    try {
      return localStorage.getItem('oxford-rms-sidebar') === 'collapsed';
    } catch (e) {
      return false;
    }
  }

  function isDesktopSidebar() {
    return window.matchMedia && window.matchMedia('(min-width: 1024px)').matches;
  }

  function syncSidebarToggleUI() {
    const btn = document.getElementById('sidebar-toggle');
    if (!btn) return;
    const sidebar = document.getElementById('sidebar');
    if (!sidebar) return;
    if (isDesktopSidebar()) {
      const collapsed = sidebar.classList.contains('sidebar-collapsed');
      btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
      btn.title = collapsed ? 'Expand sidebar' : 'Collapse sidebar';
      btn.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
    } else {
      const open = !sidebar.classList.contains('-translate-x-full');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
      btn.title = open ? 'Close menu' : 'Open menu';
      btn.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    }
  }

  function setSidebarCollapsed(collapsed) {
    const sidebar = document.getElementById('sidebar');
    if (!sidebar) return;
    sidebar.classList.toggle('sidebar-collapsed', collapsed);
    try {
      localStorage.setItem('oxford-rms-sidebar', collapsed ? 'collapsed' : 'expanded');
    } catch (e) {}
    syncSidebarToggleUI();
  }

  function bindSidebarToggle(handlers = {}) {
    const sidebar = document.getElementById('sidebar');
    const btn = document.getElementById('sidebar-toggle');
    if (!sidebar || !btn) return;
    if (isDesktopSidebar()) setSidebarCollapsed(isSidebarCollapsed());
    syncSidebarToggleUI();
    if (btn.dataset.bound === '1') return;
    btn.dataset.bound = '1';
    btn.addEventListener('click', () => {
      if (isDesktopSidebar()) {
        setSidebarCollapsed(!sidebar.classList.contains('sidebar-collapsed'));
      } else if (sidebar.classList.contains('-translate-x-full')) {
        handlers.openMobile?.();
        syncSidebarToggleUI();
      } else {
        handlers.closeMobile?.();
        syncSidebarToggleUI();
      }
    });
    window.addEventListener('resize', () => {
      if (isDesktopSidebar()) {
        handlers.closeMobile?.();
        setSidebarCollapsed(isSidebarCollapsed());
      } else {
        sidebar.classList.remove('sidebar-collapsed');
        syncSidebarToggleUI();
      }
    });
  }

  // Back-compat alias
  function bindSidebarCollapse() {
    bindSidebarToggle();
  }

  function ensureToastHost() {
    let host = document.getElementById('rms-toast-host');
    if (!host) {
      host = document.createElement('div');
      host.id = 'rms-toast-host';
      host.className = 'rms-toast-host';
      host.setAttribute('aria-live', 'polite');
      document.body.appendChild(host);
    }
    return host;
  }

  function toast(message, type = 'success', duration = 3200) {
    const host = ensureToastHost();
    const el = document.createElement('div');
    el.className = 'rms-toast rms-toast-' + type;
    el.innerHTML = `
      <span class="rms-toast-dot"></span>
      <span class="rms-toast-msg">${String(message)}</span>
      <button type="button" class="rms-toast-close" aria-label="Dismiss">×</button>`;
    const remove = () => {
      el.classList.add('rms-toast-out');
      setTimeout(() => el.remove(), 220);
    };
    el.querySelector('.rms-toast-close').onclick = remove;
    host.appendChild(el);
    requestAnimationFrame(() => el.classList.add('rms-toast-in'));
    if (duration > 0) setTimeout(remove, duration);
    return el;
  }

  function openModal(id) {
    const m = document.getElementById(id);
    if (!m) return;
    // Page-local modals can be rendered inside #portal-body, whose entrance
    // animation creates a stacking context. Move them to body before opening
    // so they always sit above the sticky guest header and promo strip.
    if (m.parentElement !== document.body) {
      const placeholder = document.createComment('rms-modal-placeholder');
      m.before(placeholder);
      m.__rmsModalPlaceholder = placeholder;
      document.body.appendChild(m);
    }
    m.classList.remove('hidden');
    m.classList.add('grid');
    // Force reflow so the enter transition runs
    void m.offsetWidth;
    m.classList.add('is-open');
    document.body.classList.add('overflow-hidden');
  }

  function closeModal(id) {
    const m = document.getElementById(id);
    if (!m) return;
    m.classList.remove('is-open');
    const reduced =
      document.documentElement.classList.contains('a11y-reduced-motion') ||
      (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    const finish = () => {
      m.classList.add('hidden');
      m.classList.remove('grid');
      const placeholder = m.__rmsModalPlaceholder;
      if (placeholder) {
        if (placeholder.parentNode) {
          placeholder.parentNode.insertBefore(m, placeholder);
          placeholder.remove();
        } else {
          // The page re-rendered while the modal was open; discard the stale
          // detached modal and let the freshly rendered one remain in place.
          m.remove();
        }
        delete m.__rmsModalPlaceholder;
      }
      if (!document.querySelector('.rms-modal-backdrop.is-open')) {
        document.body.classList.remove('overflow-hidden');
      }
    };
    if (reduced) finish();
    else setTimeout(finish, 220);
  }

  /** Soft entrance + stagger for freshly rendered page content. */
  function polish(root) {
    if (!root || !(root instanceof Element)) return;
    root.classList.add('rms-enter');
    const kids = Array.from(root.children).filter((el) => el.nodeType === 1);
    if (kids.length > 1 && kids.length <= 20) {
      root.classList.add('rms-stagger');
    }
  }

  function bindModalDismiss(id) {
    const m = document.getElementById(id);
    if (!m || m.dataset.boundDismiss === '1') return;
    m.dataset.boundDismiss = '1';
    m.addEventListener('click', (e) => {
      if (e.target === m || e.target.closest('[data-modal-close]')) closeModal(id);
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && !m.classList.contains('hidden')) closeModal(id);
    });
  }

  function ensureDialogHost() {
    let host = document.getElementById('rms-dialog-host');
    if (host) return host;
    host = document.createElement('div');
    host.id = 'rms-dialog-host';
    document.body.appendChild(host);
    return host;
  }

  function confirmDialog(message, options = {}) {
    const host = ensureDialogHost();
    const id = 'rms-confirm-' + Date.now();
    host.innerHTML = `<div id="${id}" class="rms-modal-backdrop grid is-open" role="dialog" aria-modal="true" aria-labelledby="${id}-title"><div class="rms-modal max-w-md"><h2 id="${id}-title"></h2><p class="mt-2 text-sm text-charcoal-muted" data-dialog-message></p><div class="mt-6 flex justify-end gap-2"><button type="button" class="btn-secondary" data-dialog-cancel>${options.cancelLabel || 'Cancel'}</button><button type="button" class="btn-primary" data-dialog-confirm>${options.confirmLabel || 'Confirm'}</button></div></div></div>`;
    const modal = document.getElementById(id);
    modal.querySelector('h2').textContent = options.title || 'Please confirm';
    modal.querySelector('[data-dialog-message]').textContent = message;
    document.body.classList.add('overflow-hidden');
    return new Promise((resolve) => {
      let settled = false;
      const finish = (value) => { if (settled) return; settled = true; modal.remove(); if (!document.querySelector('.rms-modal-backdrop.is-open')) document.body.classList.remove('overflow-hidden'); resolve(value); };
      modal.querySelector('[data-dialog-confirm]').onclick = () => finish(true);
      modal.querySelector('[data-dialog-cancel]').onclick = () => finish(false);
      modal.addEventListener('click', (event) => { if (event.target === modal) finish(false); });
      document.addEventListener('keydown', (event) => { if (event.key === 'Escape') finish(false); }, { once: true });
      modal.querySelector('[data-dialog-confirm]').focus();
    });
  }

  function promptDialog(message, options = {}) {
    const host = ensureDialogHost();
    const id = 'rms-prompt-' + Date.now();
    const isNumpad = options.numpad === true;
    const keypad = ['1','2','3','4','5','6','7','8','9','clear','0','backspace'].map((key) => {
      const label = key === 'clear' ? 'Clear' : key === 'backspace' ? '⌫' : key;
      const tone = key === 'clear' ? 'text-ember' : 'text-charcoal';
      const aria = key === 'clear' ? 'Clear PIN' : key === 'backspace' ? 'Delete last digit' : 'Digit ' + key;
      return `<button type="button" class="rms-pin-key grid min-h-12 place-items-center rounded-xl border border-line bg-surface text-lg font-semibold ${tone} shadow-sm transition hover:border-brass hover:bg-brass/10 active:scale-95" data-keypad-key="${key}" aria-label="${aria}">${label}</button>`;
    }).join('');
    host.innerHTML = `<div id="${id}" class="rms-modal-backdrop grid is-open" role="dialog" aria-modal="true" aria-labelledby="${id}-title"><div class="rms-modal ${isNumpad ? 'max-w-sm' : 'max-w-md'}"><div class="flex items-start gap-3"><div class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-ember/10 text-ember"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg></div><div><h2 id="${id}-title"></h2><p class="mt-1 text-sm text-charcoal-muted" data-dialog-message></p></div></div>${isNumpad ? `<div class="rms-pin-display mt-5 rounded-2xl border border-line bg-parchment/60 px-4 py-3 text-center"><div class="min-h-8 text-xl font-semibold tracking-[0.28em] text-ember" data-pin-value>Enter PIN</div><div class="mt-2 flex min-h-3 items-center justify-center gap-2" data-pin-dots aria-label="PIN digits"></div><p class="mt-2 text-[10px] uppercase tracking-[0.14em] text-charcoal-muted">${options.minLength || 4}–${options.maxLength || 6} digits</p></div><div class="mt-4 grid grid-cols-3 gap-2">${keypad}</div>` : '<input class="field mt-4" data-dialog-input autocomplete="off" />'}<div class="mt-5 flex justify-end gap-2"><button type="button" class="btn-secondary" data-dialog-cancel>Cancel</button><button type="button" class="btn-primary" data-dialog-confirm disabled>${options.confirmLabel || 'Save'}</button></div></div></div>`;
    const modal = document.getElementById(id);
    modal.querySelector('h2').textContent = options.title || 'Enter a value';
    modal.querySelector('[data-dialog-message]').textContent = message;
    const input = modal.querySelector('[data-dialog-input]');
    if (input) { input.type = options.type || 'text'; input.placeholder = options.placeholder || ''; }
    let value = '';
    const minLength = Number(options.minLength || 1);
    const maxLength = Number(options.maxLength || 120);
    const confirmButton = modal.querySelector('[data-dialog-confirm]');
    const syncValue = () => {
      if (isNumpad) {
        modal.querySelector('[data-pin-value]').textContent = value ? '•'.repeat(value.length) : 'Enter PIN';
        modal.querySelector('[data-pin-dots]').innerHTML = Array.from({ length: maxLength }, (_, i) => `<span class="h-3 w-3 rounded-full border ${i < value.length ? 'border-ember bg-ember' : 'border-charcoal-muted/50 bg-transparent'}"></span>`).join('');
        confirmButton.disabled = value.length < minLength;
      } else confirmButton.disabled = !input.value;
    };
    document.body.classList.add('overflow-hidden');
    return new Promise((resolve) => {
      let settled = false;
      const finish = (result) => { if (settled) return; settled = true; modal.remove(); if (!document.querySelector('.rms-modal-backdrop.is-open')) document.body.classList.remove('overflow-hidden'); resolve(result); };
      confirmButton.onclick = () => finish(isNumpad ? value : input.value);
      modal.querySelector('[data-dialog-cancel]').onclick = () => finish(null);
      modal.addEventListener('click', (event) => { if (event.target === modal) finish(null); });
      if (isNumpad) {
        const pressKey = (key) => {
          if (/^\d$/.test(key) && value.length < maxLength) value += key;
          else if (key === 'clear') value = '';
          else if (key === 'backspace') value = value.slice(0, -1);
          syncValue();
        };
        modal.querySelectorAll('[data-keypad-key]').forEach((button) => {
          let lastPointerActivation = 0;
          const activate = (event) => { event.preventDefault(); event.stopPropagation(); if (event.type === 'click' && Date.now() - lastPointerActivation < 500) return; if (event.type === 'pointerup') lastPointerActivation = Date.now(); pressKey(button.dataset.key); };
          button.onclick = activate;
          button.onpointerup = activate;
          button.addEventListener('pointerdown', () => button.classList.add('scale-95'));
          button.addEventListener('pointerup', () => button.classList.remove('scale-95'));
          button.addEventListener('pointerleave', () => button.classList.remove('scale-95'));
        });
      }
      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') finish(null);
        if (isNumpad) {
          if (/^\d$/.test(event.key) && value.length < maxLength) value += event.key;
          if (event.key === 'Backspace') value = value.slice(0, -1);
          if (event.key === 'Enter' && value.length >= minLength) finish(value);
          syncValue();
        } else if (event.key === 'Enter') finish(input.value);
      });
      syncValue();
      if (input) input.focus();
    });
  }

  function requestManagerPin(actionLabel = 'this sensitive action') {
    const host = ensureDialogHost();
    let modal = document.getElementById('rms-manager-pin-dialog');
    if (!modal) {
      const keys = ['1','2','3','4','5','6','7','8','9','clear','0','delete'];
      modal = document.createElement('div');
      modal.id = 'rms-manager-pin-dialog';
      modal.className = 'rms-modal-backdrop grid is-open';
      modal.setAttribute('role', 'dialog');
      modal.setAttribute('aria-modal', 'true');
      modal.innerHTML = `<div class="rms-modal max-w-sm"><div class="flex items-start gap-3"><div class="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-ember/10 text-ember">⌁</div><div><h2>Manager approval</h2><p class="mt-1 text-sm text-charcoal-muted">Enter the manager PIN to <span data-manager-action></span>.</p></div></div><div class="rms-pin-display mt-5 rounded-2xl border border-line bg-parchment/60 px-4 py-3 text-center"><div class="min-h-8 text-xl font-semibold tracking-[0.28em] text-ember" data-manager-value>Enter PIN</div><div class="mt-2 flex min-h-3 items-center justify-center gap-2" data-manager-dots></div><p class="mt-2 text-[10px] uppercase tracking-[0.14em] text-charcoal-muted">4–6 digits</p></div><div class="mt-4 grid grid-cols-3 gap-2">${keys.map((key) => `<button type="button" class="rms-pin-key grid min-h-12 place-items-center rounded-xl border border-line bg-surface text-lg font-semibold ${key === 'clear' ? 'text-ember' : 'text-charcoal'}" data-manager-key="${key}">${key === 'clear' ? 'Clear' : key === 'delete' ? '⌫' : key}</button>`).join('')}</div><div class="mt-5 flex justify-end gap-2"><button type="button" class="btn-secondary" data-manager-cancel>Cancel</button><button type="button" class="btn-primary" data-manager-approve disabled>Approve</button></div></div>`;
      host.appendChild(modal);
    }
    const action = modal.querySelector('[data-manager-action]');
    const valueOutput = modal.querySelector('[data-manager-value]');
    const dots = modal.querySelector('[data-manager-dots]');
    const approve = modal.querySelector('[data-manager-approve]');
    let value = '';
    let resolveResult;
    let keydownHandler;
    const close = (result) => {
      if (keydownHandler) document.removeEventListener('keydown', keydownHandler);
      modal.classList.remove('is-open');
      modal.classList.add('hidden');
      modal.classList.remove('grid');
      modal.style.pointerEvents = 'none';
      modal.setAttribute('aria-hidden', 'true');
      document.body.classList.remove('overflow-hidden');
      if (resolveResult) { const resolve = resolveResult; resolveResult = null; resolve(result); }
    };
    const sync = () => {
      valueOutput.textContent = value ? '•'.repeat(value.length) : 'Enter PIN';
      dots.innerHTML = Array.from({ length: 6 }, (_, i) => `<span class="h-3 w-3 rounded-full border ${i < value.length ? 'border-ember bg-ember' : 'border-charcoal-muted/50'}"></span>`).join('');
      approve.disabled = value.length < 4;
    };
    const press = (key) => {
      if (/^\d$/.test(key) && value.length < 6) value += key;
      else if (key === 'clear') value = '';
      else if (key === 'delete') value = value.slice(0, -1);
      sync();
    };
    action.textContent = actionLabel;
    modal.classList.remove('hidden');
    modal.classList.add('grid', 'is-open');
    modal.style.pointerEvents = 'auto';
    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('overflow-hidden');
    modal.querySelectorAll('[data-manager-key]').forEach((button) => { button.onclick = (event) => { event.preventDefault(); event.stopPropagation(); press(button.dataset.managerKey); }; });
    modal.querySelector('[data-manager-cancel]').onclick = () => close(null);
    approve.onclick = () => { if (value.length >= 4) close(value); };
    modal.onclick = (event) => { if (event.target === modal) close(null); };
    keydownHandler = (event) => {
      if (event.key === 'Escape') { event.preventDefault(); close(null); return; }
      if (/^\d$/.test(event.key) || ['Backspace', 'Delete', 'Enter', 'c', 'C'].includes(event.key)) {
        event.preventDefault();
        if (/^\d$/.test(event.key)) press(event.key);
        else if (event.key === 'Backspace' || event.key === 'Delete') press('delete');
        else if (event.key === 'c' || event.key === 'C') press('clear');
        else if (event.key === 'Enter' && value.length >= 4) close(value);
      }
    };
    document.addEventListener('keydown', keydownHandler);
    sync();
    modal.querySelector('[data-manager-key="1"]').focus();
    return new Promise((resolve) => { resolveResult = resolve; });
  }

  /** Shared staff chrome markup (Tailwind) */
  function staffChrome() {
    return {
      sidebarOpen: '',
      sidebarClosed: '-translate-x-full lg:translate-x-0',
    };
  }

  function formatMods(notes) {
    if (!notes) return '';
    try {
      const raw = typeof notes === 'string' && notes.trim().startsWith('{') ? JSON.parse(notes) : notes;
      if (raw && typeof raw === 'object') {
        const src = raw._display && typeof raw._display === 'object' ? raw._display : raw;
        const parts = Object.entries(src)
          .filter(([k]) => k !== '_display')
          .map(([k, v]) => {
            const label = String(k).replace(/_/g, ' ');
            const val = Array.isArray(v) ? v.join(', ') : String(v).replace(/_/g, ' ');
            return `<span class="font-semibold">${label}:</span> ${val}`;
          });
        if (parts.length) {
          return `<div class="mt-1.5 rounded-lg bg-ember-soft/80 px-2.5 py-1.5 text-[11px] leading-snug text-ember">${parts.join('<br>')}</div>`;
        }
      }
    } catch (_) {}
    return `<div class="mt-1.5 text-[11px] text-ember">⚙ ${notes}</div>`;
  }

  global.RMS = {
    BASE,
    API,
    api,
    request: api,
    money,
    formatMoney: money,
    initials,
    formatNow,
    statusBadge,
    connectNotificationStream,
    badge: statusBadge,
    dishImage,
    uploadMenuImage,
    requireStaff,
    requireCustomer,
    optionalCustomer,
    cacheAuthUser,
    clearAuthCache,
    mountShell,
    sidebarHtml,
    icons,
    MODULE_ROLES,
    canAccessModule,
    modulesForRole,
    homeForRole,
    forbiddenHtml,
    staffChrome,
    getTheme,
    setTheme,
    toggleTheme,
    bindThemeControls,
    themeToggleHtml,
    syncThemeToggleUI,
    getA11yPrefs,
    setA11yPref,
    bindA11yControls,
    a11yControlsHtml,
    syncA11yUI,
    setSidebarCollapsed,
    isSidebarCollapsed,
    formatMods,
    toast,
    openModal,
    closeModal,
    confirm: confirmDialog,
    prompt: promptDialog,
    requestManagerPin,
    polish,
    bindModuleNavTransitions,
    bindModalDismiss,
  };
  // Back-compat aliases for mixed page naming
  RMS.api = api;
  RMS.money = money;
  RMS.statusBadge = statusBadge;
  RMS.bindModalDismiss = bindModalDismiss;
  RMS.openModal = openModal;
  RMS.closeModal = closeModal;
  RMS.confirm = confirmDialog;
  RMS.prompt = promptDialog;
  RMS.requestManagerPin = requestManagerPin;
  RMS.polish = polish;
  RMS.bindModuleNavTransitions = bindModuleNavTransitions;
})(window);
