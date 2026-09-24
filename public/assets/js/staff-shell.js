/**
 * Shared Tailwind staff layout — profile menu, live notifications, theme
 */
(function (global) {
  function assetPrefix() {
    const path = location.pathname;
    if (path.includes('/staff/') || path.includes('/customer/') || path.includes('/kitchen/') || path.includes('/pos/')) {
      return '../assets';
    }
    return 'assets';
  }

  function logoUrl() {
    return assetPrefix() + '/img/oxford-logo.jpg';
  }

  function escapeHtml(s) {
    return String(s ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function shellHtml(options) {
    const logo = logoUrl();
    const title = options.title || '';
    return `
    <div id="sidebar-overlay" class="fixed inset-0 z-30 hidden bg-charcoal/50 backdrop-blur-sm lg:hidden"></div>
    <div class="flex min-h-screen w-full bg-suite-page font-sans text-charcoal">
      <aside id="sidebar"
        class="suite-sidebar fixed inset-y-0 left-0 z-40 flex h-screen w-[272px] shrink-0 -translate-x-full flex-col border-r border-line bg-surface/95 shadow-suite backdrop-blur transition-all duration-300 lg:sticky lg:top-0 lg:translate-x-0">
        <div class="sidebar-brand flex items-center gap-3 border-b border-line px-4 py-4">
          <img src="${logo}" alt="Oxford Suites" class="brand-logo" />
          <div class="sidebar-copy min-w-0 flex-1">
            <div class="font-display text-[15px] font-semibold leading-tight text-charcoal">Oxford Suites</div>
            <div class="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-[0.14em] text-ember"><span class="sidebar-live-dot"></span>Makati · RMS</div>
          </div>
        </div>
        <div class="sidebar-section-label px-5 pb-2 pt-5 text-[11px] font-semibold uppercase tracking-[0.1em] text-charcoal-muted">Main</div>
        <ul id="nav-list" class="nav-list flex-1 space-y-0.5 overflow-y-auto overflow-x-hidden px-3 pb-4"></ul>
        <div class="sidebar-user mt-auto flex items-center gap-3 border-t border-line px-3 py-4">
          <div class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-gradient-to-br from-brass to-ember text-xs font-bold text-white" data-user-initials></div>
          <div class="sidebar-copy min-w-0 flex-1">
            <div class="truncate text-sm font-semibold text-charcoal" data-user-name></div>
            <div class="truncate text-[11px] capitalize text-charcoal-muted" data-user-role></div>
          </div>
          <button id="logout-btn" type="button" title="Sign out" class="icon-btn shrink-0">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
          </button>
        </div>
      </aside>

      <div class="flex min-h-screen min-w-0 flex-1 flex-col">
        <header class="suite-header">
          <div class="flex min-w-0 items-center gap-3">
            <button id="sidebar-toggle" type="button" class="icon-btn" title="Toggle sidebar" aria-label="Toggle sidebar" aria-expanded="true">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 6h16M4 12h10M4 18h16"/></svg>
            </button>
            <h2 class="truncate font-display text-lg font-semibold tracking-tight text-charcoal" id="header-crumb">${title}</h2>
          </div>

          <div class="header-actions">
            <div id="live-time" class="hidden text-sm font-medium text-charcoal-muted sm:block"></div>
            <div class="header-dropdown-wrap hidden md:block">
              <input id="global-search" type="search" class="field !w-44 !rounded-full !py-1.5 !text-xs lg:!w-56" placeholder="Search orders, menu…" aria-label="Global search" autocomplete="off" />
              <div id="search-panel" class="header-dropdown hidden !w-80" role="listbox"></div>
            </div>
            ${typeof RMS !== 'undefined' && RMS.themeToggleHtml ? RMS.themeToggleHtml() : ''}

            <div class="header-dropdown-wrap">
              <button type="button" id="notif-btn" class="icon-btn relative" title="Notifications" aria-label="Notifications" aria-expanded="false" aria-haspopup="true">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
                <span id="notif-badge" class="notif-badge is-empty" aria-hidden="true">0</span>
              </button>
              <div id="notif-panel" class="header-dropdown hidden" role="menu" aria-label="Notifications">
                <div class="notif-head">
                  <h3>Notifications</h3>
                  <button type="button" id="notif-mark-all" class="btn-sm !text-[10px]">Mark all read</button>
                </div>
                <div id="notif-list" class="notif-empty">Loading…</div>
              </div>
            </div>

            <div class="header-dropdown-wrap">
              <button type="button" id="profile-btn" class="user-pill" aria-expanded="false" aria-haspopup="true" title="Account menu">
                <div class="grid h-8 w-8 place-items-center rounded-full bg-gradient-to-br from-brass to-ember text-[10px] font-bold text-white" data-user-initials></div>
                <div class="user-pill-meta">
                  <div class="truncate text-xs font-semibold text-charcoal" data-user-name></div>
                  <div class="truncate text-[10px] capitalize text-charcoal-muted" data-user-role></div>
                </div>
                <svg class="hidden h-3.5 w-3.5 shrink-0 text-charcoal-muted sm:block" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
              </button>
              <div id="profile-menu" class="header-dropdown profile-menu hidden" role="menu">
                <button type="button" class="header-dropdown-item" data-profile-action="edit">Edit profile</button>
                <button type="button" class="header-dropdown-item" data-profile-action="a11y">Display &amp; accessibility</button>
                <button type="button" class="header-dropdown-item" data-profile-action="password">Change password</button>
                <div class="header-dropdown-sep"></div>
                <button type="button" class="header-dropdown-item danger" data-profile-action="logout">Sign out</button>
              </div>
            </div>
          </div>
        </header>

        <main class="flex w-full flex-1 flex-col px-4 pb-6 pt-4 sm:px-6 lg:px-8">
          <section id="suite-module-panel" class="suite-module-panel flex min-h-0 w-full flex-1 flex-col overflow-hidden rounded-2xl border border-line bg-surface shadow-suite">
            <div class="relative shrink-0 bg-banner-ember px-6 py-5 text-white sm:px-8 sm:py-6">
              <div class="pointer-events-none absolute inset-0 opacity-30" style="background-image:url('data:image/svg+xml,%3Csvg width=60 height=60 xmlns=%27http://www.w3.org/2000/svg%27%3E%3Cpath d=%27M0 30h60M30 0v60%27 stroke=%27rgba(255,255,255,0.08)%27 stroke-width=%271%27/%3E%3C/svg%3E')"></div>
              <div class="relative flex flex-wrap items-end justify-between gap-3">
                <div class="module-title-lockup">
                  <p class="mb-1 text-[11px] font-semibold uppercase tracking-[0.14em] text-brass-soft">Oxford Suites · Makati</p>
                  <div class="flex items-center gap-3"><span class="module-title-accent"></span><h1 id="module-title" class="font-display text-2xl font-semibold tracking-tight sm:text-[28px]"></h1></div>
                  <p id="module-subtitle" class="mt-1.5 max-w-2xl text-sm text-white/85"></p>
                </div>
                <div class="rounded-full border border-white/20 bg-white/10 px-3 py-1 text-[11px] font-medium text-white/90 backdrop-blur">
                  Theme: <span data-theme-label>Light</span>
                </div>
              </div>
            </div>
            <div id="module-body" class="w-full flex-1 p-5 sm:p-6 lg:p-8"></div>
          </section>
        </main>
        <footer class="shrink-0 px-8 pb-4 text-center text-[11px] text-charcoal-muted">© Oxford Suites Makati · Restaurant Management System</footer>
      </div>
    </div>

    <div id="staff-profile-modal" class="rms-modal-backdrop hidden" role="dialog" aria-modal="true" aria-labelledby="staff-profile-title">
      <div class="rms-modal max-w-md">
        <h2 id="staff-profile-title">Edit profile</h2>
        <p class="mt-1 text-sm text-charcoal-muted">Update how you appear across the suite workspace.</p>
        <form id="staff-profile-form" class="mt-4 space-y-3">
          <div>
            <label class="mb-1 block text-xs font-semibold text-charcoal-muted" for="staff-acc-name">Full name</label>
            <input id="staff-acc-name" required class="field" autocomplete="name" />
          </div>
          <div>
            <label class="mb-1 block text-xs font-semibold text-charcoal-muted" for="staff-acc-email">Email</label>
            <input id="staff-acc-email" type="email" required class="field" autocomplete="email" />
          </div>
          <div>
            <label class="mb-1 block text-xs font-semibold text-charcoal-muted" for="staff-acc-phone">Phone</label>
            <input id="staff-acc-phone" class="field" autocomplete="tel" />
          </div>
          <div class="rounded-xl border border-line bg-parchment/60 px-3 py-2 text-xs text-charcoal-muted">
            Role: <strong id="staff-acc-role" class="capitalize text-charcoal"></strong> (managed by admin)
          </div>
          <div class="flex justify-end gap-2 pt-2">
            <button type="button" class="btn-secondary" data-modal-close>Cancel</button>
            <button type="submit" class="btn-primary">Save profile</button>
          </div>
        </form>
      </div>
    </div>

    <div id="staff-a11y-modal" class="rms-modal-backdrop hidden" role="dialog" aria-modal="true" aria-labelledby="staff-a11y-title">
      <div class="rms-modal max-w-md">
        <h2 id="staff-a11y-title">Display &amp; accessibility</h2>
        <p class="mt-1 text-sm text-charcoal-muted">Preferences are saved on this device.</p>
        <div class="mt-4 space-y-4">
          <div>
            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-charcoal-muted">Color theme</p>
            ${typeof RMS !== 'undefined' && RMS.themeToggleHtml ? RMS.themeToggleHtml() : ''}
          </div>
          <div>
            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-charcoal-muted">Accessibility</p>
            ${typeof RMS !== 'undefined' && RMS.a11yControlsHtml ? RMS.a11yControlsHtml(false) : ''}
            <ul class="mt-3 space-y-1.5 text-xs text-charcoal-muted">
              <li><strong class="text-charcoal">Aa</strong> — Larger text across the workspace</li>
              <li><strong class="text-charcoal">HC</strong> — High contrast colors</li>
              <li><strong class="text-charcoal">Colorblind</strong> — Status hues tuned for color vision</li>
            </ul>
          </div>
          <div class="flex justify-end pt-2">
            <button type="button" class="btn-primary" data-modal-close>Done</button>
          </div>
        </div>
      </div>
    </div>

    <div id="staff-password-modal" class="rms-modal-backdrop hidden" role="dialog" aria-modal="true" aria-labelledby="staff-pwd-title">
      <div class="rms-modal max-w-md">
        <h2 id="staff-pwd-title">Change password</h2>
        <form id="staff-password-form" class="mt-4 space-y-3">
          <div>
            <label class="mb-1 block text-xs font-semibold text-charcoal-muted" for="staff-pwd-current">Current password</label>
            <input id="staff-pwd-current" type="password" required class="field" autocomplete="current-password" />
          </div>
          <div>
            <label class="mb-1 block text-xs font-semibold text-charcoal-muted" for="staff-pwd-new">New password</label>
            <input id="staff-pwd-new" type="password" required minlength="6" class="field" autocomplete="new-password" />
          </div>
          <div class="flex justify-end gap-2 pt-2">
            <button type="button" class="btn-secondary" data-modal-close>Cancel</button>
            <button type="submit" class="btn-primary">Update password</button>
          </div>
        </form>
      </div>
    </div>`;
  }

  function closeAllDropdowns() {
    document.getElementById('notif-panel')?.classList.add('hidden');
    document.getElementById('profile-menu')?.classList.add('hidden');
    document.getElementById('notif-btn')?.setAttribute('aria-expanded', 'false');
    document.getElementById('profile-btn')?.setAttribute('aria-expanded', 'false');
  }

  function bindStaffChrome(user) {
    let lastBadge = -1;
    let pollTimer = null;
    let liveStream = null;

    RMS.bindModalDismiss('staff-profile-modal');
    RMS.bindModalDismiss('staff-a11y-modal');
    RMS.bindModalDismiss('staff-password-modal');

    const notifBtn = document.getElementById('notif-btn');
    const notifPanel = document.getElementById('notif-panel');
    const profileBtn = document.getElementById('profile-btn');
    const profileMenu = document.getElementById('profile-menu');

    notifBtn?.addEventListener('click', (e) => {
      e.stopPropagation();
      const open = notifPanel.classList.contains('hidden');
      closeAllDropdowns();
      if (open) {
        notifPanel.classList.remove('hidden');
        notifBtn.setAttribute('aria-expanded', 'true');
        refreshNotifications(true);
      }
    });

    profileBtn?.addEventListener('click', (e) => {
      e.stopPropagation();
      const open = profileMenu.classList.contains('hidden');
      closeAllDropdowns();
      if (open) {
        profileMenu.classList.remove('hidden');
        profileBtn.setAttribute('aria-expanded', 'true');
      }
    });

    document.addEventListener('click', (e) => {
      if (!e.target.closest('.header-dropdown-wrap')) closeAllDropdowns();
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') closeAllDropdowns();
    });

    profileMenu?.addEventListener('click', async (e) => {
      const btn = e.target.closest('[data-profile-action]');
      if (!btn) return;
      const action = btn.dataset.profileAction;
      closeAllDropdowns();
      if (action === 'edit') {
        document.getElementById('staff-acc-name').value = user.name || '';
        document.getElementById('staff-acc-email').value = user.email || '';
        document.getElementById('staff-acc-phone').value = user.phone || '';
        document.getElementById('staff-acc-role').textContent = user.role || '';
        RMS.openModal('staff-profile-modal');
      } else if (action === 'a11y') {
        RMS.openModal('staff-a11y-modal');
        RMS.bindThemeControls(document.getElementById('staff-a11y-modal'));
        RMS.bindA11yControls(document.getElementById('staff-a11y-modal'));
      } else if (action === 'password') {
        document.getElementById('staff-password-form').reset();
        RMS.openModal('staff-password-modal');
      } else if (action === 'logout') {
        try { await RMS.api('/auth/logout', { method: 'POST', body: {} }); } catch (_) {}
        RMS.clearAuthCache?.();
        location.href = RMS.BASE + '/public/login.html';
      }
    });

    document.getElementById('staff-profile-form')?.addEventListener('submit', async (e) => {
      e.preventDefault();
      try {
        const res = await RMS.api('/auth/me', {
          method: 'PATCH',
          body: {
            name: document.getElementById('staff-acc-name').value.trim(),
            email: document.getElementById('staff-acc-email').value.trim(),
            phone: document.getElementById('staff-acc-phone').value.trim(),
          },
        });
        const u = res.data.user;
        Object.assign(user, u);
        RMS.cacheAuthUser?.(u);
        document.querySelectorAll('[data-user-name]').forEach((el) => { el.textContent = u.name; });
        document.querySelectorAll('[data-user-role]').forEach((el) => { el.textContent = u.role; });
        document.querySelectorAll('[data-user-initials]').forEach((el) => { el.textContent = RMS.initials(u.name); });
        RMS.closeModal('staff-profile-modal');
        RMS.toast('Profile updated');
      } catch (ex) {
        RMS.toast(ex.message, 'error');
      }
    });

    document.getElementById('staff-password-form')?.addEventListener('submit', async (e) => {
      e.preventDefault();
      try {
        await RMS.api('/auth/password', {
          method: 'PATCH',
          body: {
            current_password: document.getElementById('staff-pwd-current').value,
            new_password: document.getElementById('staff-pwd-new').value,
          },
        });
        RMS.closeModal('staff-password-modal');
        RMS.toast('Password updated');
      } catch (ex) {
        RMS.toast(ex.message, 'error');
      }
    });

    document.getElementById('notif-mark-all')?.addEventListener('click', async (e) => {
      e.stopPropagation();
      try {
        await RMS.api('/notifications/read-all', { method: 'PATCH', body: {} });
        await refreshNotifications(true);
        RMS.toast('All notifications marked read');
      } catch (ex) {
        RMS.toast(ex.message, 'error');
      }
    });

    document.getElementById('notif-list')?.addEventListener('click', async (e) => {
      const item = e.target.closest('[data-notif-id]');
      if (!item) return;
      const id = item.dataset.notifId;
      try {
        if (id && item.dataset.unread === '1') {
          await RMS.api('/notifications/' + id + '/read', { method: 'PATCH', body: {} });
        }
        if (item.dataset.href) location.href = item.dataset.href;
        else await refreshNotifications(true);
      } catch (_) {
        await refreshNotifications(true);
      }
    });

    function notifHref(n) {
      const t = String(n.type || '');
      if (t === 'service_request') return 'tables.html';
      if (t === 'kitchen' || t === 'order_ready') return 'kitchen.html';
      if (t === 'payment' || t === 'order') return 'orders.html';
      return '';
    }

    function renderNotifList(data) {
      const list = document.getElementById('notif-list');
      if (!list) return;
      const items = data.items || [];
      const openReq = Number(data.open_requests || 0);
      let html = '';
      if (openReq > 0) {
        html += `<button type="button" class="notif-item is-unread" data-href="tables.html">
          <div class="notif-title">${openReq} open service request${openReq > 1 ? 's' : ''}</div>
          <div class="notif-body">Guests need water, utensils, bill, or assistance</div>
          <div class="notif-meta">Live · Tables floor</div>
        </button>`;
      }
      if (!items.length && !openReq) {
        list.innerHTML = '<div class="notif-empty">No notifications yet.</div>';
        return;
      }
      html += items.map((n) => {
        const unread = Number(n.is_read) === 0;
        const href = notifHref(n);
        return `<button type="button" class="notif-item ${unread ? 'is-unread' : ''}" data-notif-id="${n.id}" data-unread="${unread ? '1' : '0'}" ${href ? `data-href="${href}"` : ''}>
          <div class="notif-title">${escapeHtml(n.title)}</div>
          ${n.body ? `<div class="notif-body">${escapeHtml(n.body)}</div>` : ''}
          <div class="notif-meta">${escapeHtml(n.created_at || '')}${unread ? ' · New' : ''}</div>
        </button>`;
      }).join('');
      list.innerHTML = html;
    }

    function setBadge(count) {
      const badge = document.getElementById('notif-badge');
      if (!badge) return;
      if (count > 0) {
        badge.textContent = count > 99 ? '99+' : String(count);
        badge.classList.remove('is-empty');
      } else {
        badge.textContent = '0';
        badge.classList.add('is-empty');
      }
      if (lastBadge >= 0 && count > lastBadge) {
        try {
          const ctx = new (window.AudioContext || window.webkitAudioContext)();
          const o = ctx.createOscillator();
          const g = ctx.createGain();
          o.connect(g); g.connect(ctx.destination);
          o.frequency.value = 880;
          g.gain.value = 0.04;
          o.start();
          setTimeout(() => { o.stop(); ctx.close(); }, 120);
        } catch (_) {}
      }
      lastBadge = count;
    }

    async function refreshNotifications(renderPanel) {
      try {
        const res = await RMS.api('/notifications');
        const data = res.data || {};
        // Support legacy array response
        const payload = Array.isArray(data)
          ? { items: data, unread: data.filter((n) => !Number(n.is_read)).length, open_requests: 0, badge: data.filter((n) => !Number(n.is_read)).length }
          : data;
        setBadge(Number(payload.badge || 0));
        if (renderPanel || !notifPanel.classList.contains('hidden')) {
          renderNotifList(payload);
        }
      } catch (_) {
        if (renderPanel) {
          const list = document.getElementById('notif-list');
          if (list) list.innerHTML = '<div class="notif-empty">Could not load notifications.</div>';
        }
      }
    }

    function startLiveUpdates() {
      if (pollTimer != null) return;
      refreshNotifications(false);
      pollTimer = setInterval(() => refreshNotifications(false), 8000);
      if (typeof RMS.connectNotificationStream === 'function') {
        liveStream = RMS.connectNotificationStream({
          onBadge(data) {
            if (data && data.badge != null) setBadge(Number(data.badge));
            else refreshNotifications(false);
          },
          onNotification() {
            refreshNotifications(notifPanel && !notifPanel.classList.contains('hidden'));
          },
        });
      }
    }

    // Global search
    const searchInput = document.getElementById('global-search');
    const searchPanel = document.getElementById('search-panel');
    let searchTimer = null;
    searchInput?.addEventListener('input', () => {
      clearTimeout(searchTimer);
      const q = searchInput.value.trim();
      if (q.length < 2) {
        searchPanel.classList.add('hidden');
        return;
      }
      searchTimer = setTimeout(async () => {
        try {
          const res = await RMS.api('/search?q=' + encodeURIComponent(q));
          const rows = res.data.results || [];
          searchPanel.classList.remove('hidden');
          searchPanel.innerHTML = rows.length ? rows.map((r) => `
            <a href="${r.href}" class="header-dropdown-item">
              <span class="rounded bg-parchment-deep px-1.5 py-0.5 text-[9px] font-bold uppercase">${r.type}</span>
              <span class="min-w-0"><span class="block truncate">${escapeHtml(r.title)}</span><span class="block truncate text-[11px] font-normal text-charcoal-muted">${escapeHtml(r.subtitle)}</span></span>
            </a>`).join('') : '<div class="notif-empty">No matches</div>';
        } catch (_) {
          searchPanel.classList.add('hidden');
        }
      }, 220);
    });
    searchInput?.addEventListener('focus', () => {
      if (searchInput.value.trim().length >= 2) searchPanel.classList.remove('hidden');
    });

    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) refreshNotifications(!notifPanel.classList.contains('hidden'));
    });
    window.addEventListener('beforeunload', () => {
      if (pollTimer) clearInterval(pollTimer);
      try { liveStream?.close(); } catch (_) {}
    });

    return { startLiveUpdates };
  }

  async function mountStaffPage(options) {
    const root = document.getElementById('app-root');
    if (!root) return null;
    const active = root.dataset.active || options.active;
    const roles =
      (RMS.MODULE_ROLES && RMS.MODULE_ROLES[active]) ||
      (root.dataset.roles || 'admin,cashier,kitchen').split(',').map((r) => r.trim()).filter(Boolean);
    const user = await RMS.requireStaff(roles);
    if (!user) return null;

    const forbidden = !!user._forbidden;
    const cleanUser = { ...user };
    delete cleanUser._forbidden;

    root.className = 'contents';
    root.innerHTML = shellHtml(options);
    document.getElementById('nav-list').innerHTML = RMS.sidebarHtml('', cleanUser.role);
    document.getElementById('module-title').textContent = options.title || '';
    document.getElementById('module-subtitle').textContent = options.subtitle || '';
    RMS.mountShell(active, cleanUser);
    const chrome = bindStaffChrome(cleanUser);

    const body = document.getElementById('module-body');
    if (forbidden) {
      document.getElementById('module-subtitle').textContent = 'This workspace is outside your role permissions.';
      body.innerHTML = RMS.forbiddenHtml(cleanUser, options.title || 'this module');
      chrome.startLiveUpdates();
      return cleanUser;
    }

    if (typeof options.render === 'function') {
      await options.render(body, cleanUser);
    }
    if (typeof RMS.polish === 'function') RMS.polish(body);
    chrome.startLiveUpdates();
    return cleanUser;
  }

  global.RMS.mountStaffPage = mountStaffPage;
  global.RMS.mountStaff = mountStaffPage;
  global.RMS.assetPrefix = assetPrefix;
  global.RMS.logoUrl = logoUrl;
})(window);
