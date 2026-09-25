/**
 * Customer portal shell — profile dropdown, live notifications, account modals
 */
(function (global) {
  // Works both from XAMPP (/oxford-rms) and from the production domain root.
  const BASE = window.location.pathname.startsWith('/oxford-rms') ? '/oxford-rms' : '';

  function menuHref() {
    const params = new URLSearchParams(location.search);
    const token = (params.get('token') || '').trim();
    return token ? ('menu.html?token=' + encodeURIComponent(token)) : 'menu.html';
  }

  function initials(name) {
    return typeof RMS !== 'undefined' && RMS.initials
      ? RMS.initials(name)
      : String(name || '?').split(/\s+/).map((p) => p[0]).slice(0, 2).join('').toUpperCase();
  }

  function escapeHtml(s) {
    return String(s ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function navLinks(active, user) {
    const links = [
      ['home', 'Home', 'index.html'],
      ['menu', 'Menu', menuHref()],
      ['suites', 'The Suites', 'suites.html'],
      ['reserve', 'Reserve', 'reserve.html'],
      ['orders', 'Orders', 'orders.html'],
    ];
    return links
      .map(([key, label, href]) => {
        const on = active === key;
        const needsAuth = key === 'orders' || key === 'reserve' || key === 'home';
        const dest = !user && needsAuth ? 'login.html' : href;
        return `<a href="${dest}" data-nav="${key}" class="guest-nav-link whitespace-nowrap rounded-lg px-2.5 py-2 text-sm font-semibold sm:px-3 ${
          on ? 'is-active bg-[#A8342A] text-white' : 'text-[#3A322F] hover:bg-[#F3ECE1]'
        }"${on ? ' aria-current="page"' : ''}>${label}</a>`;
      })
      .join('');
  }

  function headerHtml(active, user) {
    const name = user?.name || '';
    const init = initials(name);

    const guestActions = user
      ? `
        <div class="header-dropdown-wrap">
          <button type="button" id="guest-notif-btn" class="icon-btn relative" title="Notifications" aria-label="Notifications" aria-expanded="false" aria-haspopup="true">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
            <span id="guest-notif-badge" class="notif-badge is-empty" aria-hidden="true">0</span>
          </button>
          <div id="guest-notif-panel" class="header-dropdown hidden" role="menu" aria-label="Notifications">
            <div class="notif-head">
              <h3>Updates</h3>
              <button type="button" id="guest-notif-mark-all" class="btn-sm !text-[10px]">Mark all read</button>
            </div>
            <div id="guest-notif-list" class="notif-empty">Loading…</div>
          </div>
        </div>
        <div class="header-dropdown-wrap">
          <button type="button" id="guest-profile-btn" class="user-pill !inline-flex" aria-expanded="false" aria-haspopup="true" title="Account menu">
            <div class="grid h-8 w-8 place-items-center rounded-full bg-gradient-to-br from-[#C89A4B] to-[#A8342A] text-[10px] font-bold text-white" data-guest-initials>${init}</div>
            <div class="user-pill-meta">
              <div class="truncate text-xs font-semibold text-[#241E1C]" data-guest-name>${escapeHtml(name)}</div>
              <div class="truncate text-[10px] text-[#6B635C]">Guest member</div>
            </div>
            <svg class="hidden h-3.5 w-3.5 shrink-0 text-[#6B635C] sm:block" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
          </button>
          <div id="guest-profile-menu" class="header-dropdown profile-menu hidden" role="menu">
            <div class="px-3 py-2">
              <div class="text-sm font-semibold text-[#241E1C]" data-guest-name>${escapeHtml(name)}</div>
              <div class="text-[11px] text-[#6B635C]">${escapeHtml(user.email || '')}</div>
            </div>
            <div class="header-dropdown-sep"></div>
            <button type="button" class="header-dropdown-item" data-guest-action="edit">Edit profile</button>
            <button type="button" class="header-dropdown-item" data-guest-action="a11y">Display &amp; accessibility</button>
            <button type="button" class="header-dropdown-item" data-guest-action="password">Change password</button>
            <button type="button" class="header-dropdown-item" data-guest-action="loyalty">Loyalty &amp; rewards</button>
            <button type="button" class="header-dropdown-item" data-guest-action="orders">My orders</button>
            <button type="button" class="header-dropdown-item" data-guest-action="reserve">My reservations</button>
            <div class="header-dropdown-sep"></div>
            <button type="button" class="header-dropdown-item danger" data-guest-action="logout">Sign out</button>
          </div>
        </div>`
      : `<a href="login.html" class="rounded-lg bg-[#A8342A] px-3 py-2 text-xs font-bold uppercase tracking-wide text-white hover:bg-[#8F2C24]">Sign in</a>`;

    return `
    <header class="guest-header sticky top-0 z-40 border-b border-[#D9CFC2] bg-[#FFFCFA]/95 backdrop-blur-sm">
      <div class="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-3 px-4 py-3 sm:px-6">
        <a href="${user ? 'index.html' : 'login.html'}" class="brand-mark">
          <img src="../assets/img/oxford-logo.jpg" alt="Oxford Suites" class="brand-logo" />
          <div class="brand-mark-text">
            <div class="brand-mark-title">Oxford Suites</div>
            <div class="brand-mark-sub">Makati · Guest</div>
          </div>
        </a>
        <nav class="order-3 flex w-full flex-wrap items-center gap-0.5 sm:order-none sm:w-auto">${navLinks(active, user)}</nav>
        <div class="flex flex-wrap items-center gap-2">${guestActions}</div>
      </div>
    </header>`;
  }

  async function promoStripHtml() {
    let promos = [];
    try {
      const res = await RMS.api('/promotions/active');
      promos = (res.data || []).slice(0, 4);
    } catch (_) {}
    if (!promos.length) {
      const ads = [
        { tag: 'Tonight', title: 'Wagyu evenings', copy: 'A5 ribeye with bone marrow butter — ask your captain.', href: menuHref() },
        { tag: 'Suite life', title: 'Private dining', copy: 'Host up to 12 in the Oak Suite with a curated tasting.', href: 'suites.html' },
        { tag: 'Member', title: 'Reserve ahead', copy: 'Secure terrace sunset seating for the weekend.', href: 'reserve.html' },
      ];
      return `
      <div class="border-b border-[#D9CFC2] bg-[#1C1412] text-white">
        <div class="mx-auto flex max-w-5xl gap-3 overflow-x-auto px-4 py-3 sm:px-6 [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
          ${ads.map((a) => `
            <a href="${a.href}" class="min-w-[240px] flex-1 rounded-xl border border-white/15 bg-white/5 px-4 py-3 transition hover:bg-white/10">
              <span class="text-[10px] font-bold uppercase tracking-[0.16em] text-[#E2C48A]">${a.tag}</span>
              <div class="mt-1 font-display text-base font-semibold">${a.title}</div>
              <p class="mt-0.5 text-xs leading-snug text-white/70">${a.copy}</p>
            </a>`).join('')}
        </div>
      </div>`;
    }
    const now = Date.now();
    return `
    <div id="promo-center" class="border-b border-[#D9CFC2] bg-[#1C1412] text-white">
      <div class="mx-auto flex max-w-5xl gap-3 overflow-x-auto px-4 py-3 sm:px-6 [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
        ${promos.map((p) => {
          const end = new Date(String(p.ends_at).replace(' ', 'T')).getTime();
          const left = Math.max(0, end - now);
          const days = Math.floor(left / 86400000);
          const hrs = Math.floor((left % 86400000) / 3600000);
          const countdown = left <= 0 ? 'Ended' : (days > 0 ? days + 'd ' + hrs + 'h left' : hrs + 'h left');
          const typeLabel = String(p.type || '').replace(/_/g, ' ');
          return `
            <div class="min-w-[260px] flex-1 rounded-xl border border-white/15 bg-white/5 px-4 py-3">
              <div class="flex items-center justify-between gap-2">
                <span class="text-[10px] font-bold uppercase tracking-[0.16em] text-[#E2C48A]">${escapeHtml(typeLabel)}</span>
                <span class="rounded-full bg-[#A8342A]/80 px-2 py-0.5 text-[10px] font-bold" data-promo-end="${p.ends_at}">${countdown}</span>
              </div>
              <div class="mt-1 font-display text-base font-semibold">${escapeHtml(p.name)}</div>
              <p class="mt-0.5 text-xs leading-snug text-white/70">Code <strong class="text-[#E2C48A]">${escapeHtml(p.code)}</strong> · mention at the cashier</p>
            </div>`;
        }).join('')}
      </div>
    </div>`;
  }

  function accountModalsHtml(user) {
    if (!user) return '';
    return `
    <div id="account-modal" class="rms-modal-backdrop hidden" role="dialog" aria-modal="true">
      <div class="rms-modal max-w-md">
        <div class="mb-4 flex items-center gap-3">
          <div class="grid h-12 w-12 place-items-center rounded-full bg-gradient-to-br from-[#C89A4B] to-[#A8342A] text-sm font-bold text-white" data-guest-initials>${initials(user.name)}</div>
          <div>
            <h2 class="font-display text-xl font-semibold">Your guest profile</h2>
            <p class="text-xs text-charcoal-muted">Member · Oxford Suites Makati</p>
          </div>
        </div>
        <form id="profile-form" class="mt-2 space-y-3">
          <div>
            <label class="mb-1 block text-xs font-semibold text-charcoal-muted">Full name</label>
            <input id="acc-name" required class="field" value="${(user.name || '').replace(/"/g, '&quot;')}" />
          </div>
          <div>
            <label class="mb-1 block text-xs font-semibold text-charcoal-muted">Email</label>
            <input id="acc-email" type="email" required class="field" value="${(user.email || '').replace(/"/g, '&quot;')}" />
          </div>
          <div>
            <label class="mb-1 block text-xs font-semibold text-charcoal-muted">Phone</label>
            <input id="acc-phone" class="field" value="${(user.phone || '').replace(/"/g, '&quot;')}" />
          </div>
          <div class="flex flex-wrap justify-end gap-2 pt-2">
            <button type="button" class="btn-secondary" data-modal-close>Cancel</button>
            <button type="submit" class="btn-primary">Save profile</button>
          </div>
        </form>
        <div class="mt-5 flex flex-wrap gap-2 border-t border-line pt-4">
          <button type="button" id="btn-open-password" class="btn-sm">Change password</button>
          <button type="button" id="btn-open-delete" class="btn-sm text-ember">Deactivate account</button>
        </div>
      </div>
    </div>

    <div id="guest-a11y-modal" class="rms-modal-backdrop hidden" role="dialog" aria-modal="true">
      <div class="rms-modal max-w-md">
        <h2 class="font-display text-xl font-semibold">Display &amp; accessibility</h2>
        <p class="mt-1 text-sm text-charcoal-muted">Preferences are saved on this device.</p>
        <div class="mt-4 space-y-4">
          <div>
            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-charcoal-muted">Accessibility</p>
            ${typeof RMS !== 'undefined' && RMS.a11yControlsHtml ? RMS.a11yControlsHtml(false) : ''}
            <ul class="mt-3 space-y-1.5 text-xs text-charcoal-muted">
              <li><strong class="text-charcoal">Aa</strong> — Larger text</li>
              <li><strong class="text-charcoal">HC</strong> — High contrast</li>
              <li><strong class="text-charcoal">Colorblind</strong> — Status colors tuned for color vision</li>
            </ul>
          </div>
          <div class="flex justify-end pt-2">
            <button type="button" class="btn-primary" data-modal-close>Done</button>
          </div>
        </div>
      </div>
    </div>

    <div id="password-modal" class="rms-modal-backdrop hidden" role="dialog" aria-modal="true">
      <div class="rms-modal max-w-md">
        <h2 class="font-display text-xl font-semibold">Change password</h2>
        <form id="password-form" class="mt-4 space-y-3">
          <div>
            <label class="mb-1 block text-xs font-semibold text-charcoal-muted">Current password</label>
            <input id="pwd-current" type="password" required class="field" autocomplete="current-password" />
          </div>
          <div>
            <label class="mb-1 block text-xs font-semibold text-charcoal-muted">New password</label>
            <input id="pwd-new" type="password" required minlength="6" class="field" autocomplete="new-password" />
          </div>
          <div class="flex justify-end gap-2 pt-2">
            <button type="button" class="btn-secondary" data-modal-close>Cancel</button>
            <button type="submit" class="btn-primary">Update password</button>
          </div>
        </form>
      </div>
    </div>

    <div id="delete-modal" class="rms-modal-backdrop hidden" role="dialog" aria-modal="true">
      <div class="rms-modal max-w-md">
        <h2 class="font-display text-xl font-semibold text-ember">Deactivate account</h2>
        <p class="mt-2 text-sm text-charcoal-muted">This signs you out and disables guest login. Confirm with your password.</p>
        <form id="delete-form" class="mt-4 space-y-3">
          <div>
            <label class="mb-1 block text-xs font-semibold text-charcoal-muted">Password</label>
            <input id="del-password" type="password" required class="field" autocomplete="current-password" />
          </div>
          <div class="flex justify-end gap-2 pt-2">
            <button type="button" class="btn-secondary" data-modal-close>Keep account</button>
            <button type="submit" class="btn-primary !bg-ember">Deactivate</button>
          </div>
        </form>
      </div>
    </div>`;
  }

  function footerHtml() {
    return `
    <footer class="mt-auto border-t border-[#D9CFC2] bg-[#FFFCFA] px-4 py-8">
      <div class="mx-auto grid max-w-5xl gap-6 text-sm text-[#6B635C] sm:grid-cols-3 sm:px-2">
        <div>
          <div class="font-display text-base font-semibold text-[#241E1C]">Oxford Suites Makati</div>
          <p class="mt-2 text-xs leading-relaxed">Restaurant of Suite — dining, terrace, and private rooms in the heart of Makati.</p>
        </div>
        <div>
          <div class="text-xs font-bold uppercase tracking-[0.14em] text-[#A67C3A]">Visit</div>
          <p class="mt-2 text-xs leading-relaxed">Dinner 5:30 PM – 10:30 PM<br />Reservations &amp; walk-ins welcome</p>
        </div>
        <div>
          <div class="text-xs font-bold uppercase tracking-[0.14em] text-[#A67C3A]">Guest desk</div>
          <p class="mt-2 text-xs leading-relaxed">+63 2 8888 0100<br />dining@oxfordsuites.ph</p>
        </div>
      </div>
      <p class="mt-6 text-center text-[11px] text-[#6B635C]">© Oxford Suites Makati · Guest dining</p>
    </footer>`;
  }

  function closeGuestDropdowns() {
    document.getElementById('guest-notif-panel')?.classList.add('hidden');
    document.getElementById('guest-profile-menu')?.classList.add('hidden');
    document.getElementById('guest-notif-btn')?.setAttribute('aria-expanded', 'false');
    document.getElementById('guest-profile-btn')?.setAttribute('aria-expanded', 'false');
  }

  function bindAccountChrome(user) {
    if (!user) return;

    let lastBadge = -1;
    let pollTimer = null;

    RMS.bindModalDismiss('account-modal');
    RMS.bindModalDismiss('guest-a11y-modal');
    RMS.bindModalDismiss('password-modal');
    RMS.bindModalDismiss('delete-modal');

    const notifBtn = document.getElementById('guest-notif-btn');
    const notifPanel = document.getElementById('guest-notif-panel');
    const profileBtn = document.getElementById('guest-profile-btn');
    const profileMenu = document.getElementById('guest-profile-menu');

    notifBtn?.addEventListener('click', (e) => {
      e.stopPropagation();
      const open = notifPanel.classList.contains('hidden');
      closeGuestDropdowns();
      if (open) {
        notifPanel.classList.remove('hidden');
        notifBtn.setAttribute('aria-expanded', 'true');
        refreshNotifications(true);
      }
    });

    profileBtn?.addEventListener('click', (e) => {
      e.stopPropagation();
      const open = profileMenu.classList.contains('hidden');
      closeGuestDropdowns();
      if (open) {
        profileMenu.classList.remove('hidden');
        profileBtn.setAttribute('aria-expanded', 'true');
      }
    });

    document.addEventListener('click', (e) => {
      if (!e.target.closest('.header-dropdown-wrap')) closeGuestDropdowns();
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') closeGuestDropdowns();
    });

    profileMenu?.addEventListener('click', async (e) => {
      const btn = e.target.closest('[data-guest-action]');
      if (!btn) return;
      const action = btn.dataset.guestAction;
      closeGuestDropdowns();
      if (action === 'edit') RMS.openModal('account-modal');
      else if (action === 'a11y') {
        RMS.openModal('guest-a11y-modal');
        RMS.bindA11yControls(document.getElementById('guest-a11y-modal'));
      } else if (action === 'password') {
        document.getElementById('password-form')?.reset();
        RMS.openModal('password-modal');
      } else if (action === 'loyalty') location.href = 'index.html#loyalty';
      else if (action === 'orders') location.href = 'orders.html';
      else if (action === 'reserve') location.href = 'reserve.html';
      else if (action === 'logout') {
        try { await RMS.api('/auth/logout', { method: 'POST', body: {} }); } catch (_) {}
        RMS.clearAuthCache?.();
        location.href = BASE + '/public/customer/login.html';
      }
    });

    document.getElementById('btn-open-password')?.addEventListener('click', () => {
      RMS.closeModal('account-modal');
      RMS.openModal('password-modal');
    });
    document.getElementById('btn-open-delete')?.addEventListener('click', () => {
      RMS.closeModal('account-modal');
      RMS.openModal('delete-modal');
    });

    document.getElementById('profile-form')?.addEventListener('submit', async (e) => {
      e.preventDefault();
      try {
        const res = await RMS.api('/auth/me', {
          method: 'PATCH',
          body: {
            name: document.getElementById('acc-name').value,
            email: document.getElementById('acc-email').value,
            phone: document.getElementById('acc-phone').value,
          },
        });
        const u = res.data.user;
        Object.assign(user, u);
        RMS.cacheAuthUser?.(u);
        document.querySelectorAll('[data-guest-name]').forEach((el) => { el.textContent = u.name; });
        document.querySelectorAll('[data-guest-initials]').forEach((el) => { el.textContent = initials(u.name); });
        RMS.closeModal('account-modal');
        RMS.toast('Profile updated');
      } catch (ex) {
        RMS.toast(ex.message, 'error');
      }
    });

    document.getElementById('password-form')?.addEventListener('submit', async (e) => {
      e.preventDefault();
      try {
        await RMS.api('/auth/password', {
          method: 'PATCH',
          body: {
            current_password: document.getElementById('pwd-current').value,
            new_password: document.getElementById('pwd-new').value,
          },
        });
        RMS.closeModal('password-modal');
        RMS.toast('Password updated');
        e.target.reset();
      } catch (ex) {
        RMS.toast(ex.message, 'error');
      }
    });

    document.getElementById('delete-form')?.addEventListener('submit', async (e) => {
      e.preventDefault();
      try {
        await RMS.api('/auth/me', {
          method: 'DELETE',
          body: { password: document.getElementById('del-password').value },
        });
        RMS.toast('Account deactivated');
        location.href = BASE + '/public/customer/login.html';
      } catch (ex) {
        RMS.toast(ex.message, 'error');
      }
    });

    document.getElementById('guest-notif-mark-all')?.addEventListener('click', async (e) => {
      e.stopPropagation();
      try {
        await RMS.api('/notifications/read-all', { method: 'PATCH', body: {} });
        await refreshNotifications(true);
        RMS.toast('All updates marked read');
      } catch (ex) {
        RMS.toast(ex.message, 'error');
      }
    });

    document.getElementById('guest-notif-list')?.addEventListener('click', async (e) => {
      const item = e.target.closest('[data-notif-id], [data-href]');
      if (!item) return;
      try {
        if (item.dataset.notifId && item.dataset.unread === '1') {
          await RMS.api('/notifications/' + item.dataset.notifId + '/read', { method: 'PATCH', body: {} });
        }
        if (item.dataset.href) location.href = item.dataset.href;
        else await refreshNotifications(true);
      } catch (_) {
        await refreshNotifications(true);
      }
    });

    function notifHref(n) {
      const t = String(n.type || '');
      if (t.startsWith('order') || t === 'order_ready' || t === 'order_served') return 'orders.html';
      if (t === 'reservation') return 'reserve.html';
      if (t === 'loyalty' || t === 'promo') return 'index.html#loyalty';
      return 'orders.html';
    }

    function renderNotifList(data) {
      const list = document.getElementById('guest-notif-list');
      if (!list) return;
      const items = data.items || [];
      if (!items.length) {
        list.innerHTML = '<div class="notif-empty">No updates yet. Order status and reservations appear here.</div>';
        return;
      }
      list.innerHTML = items.map((n) => {
        const unread = Number(n.is_read) === 0;
        const notificationId = n.id ? ` data-notif-id="${n.id}"` : '';
        return `<button type="button" class="notif-item ${unread ? 'is-unread' : ''}"${notificationId} data-unread="${unread ? '1' : '0'}" data-href="${notifHref(n)}">
          <div class="notif-title">${escapeHtml(n.title)}</div>
          ${n.body ? `<div class="notif-body">${escapeHtml(n.body)}</div>` : ''}
          <div class="notif-meta">${escapeHtml(n.created_at || '')}${unread ? ' · New' : ''}</div>
        </button>`;
      }).join('');
    }

    function orderProgressItems(orders) {
      return (orders || [])
        .filter((o) => !['paid', 'cancelled', 'served'].includes(String(o.status)))
        .slice(0, 3)
        .map((o) => ({
          id: null,
          is_read: 1,
          type: 'order_progress',
          title: `${o.order_number || 'Your order'} · ${o.status_label || 'Order received'}`,
          body: 'Live kitchen progress — tap to view your order.',
          created_at: o.updated_at || o.created_at || '',
          order_id: o.id,
        }));
    }

    function setBadge(count) {
      const badge = document.getElementById('guest-notif-badge');
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
          o.frequency.value = 740;
          g.gain.value = 0.035;
          o.start();
          setTimeout(() => { o.stop(); ctx.close(); }, 100);
        } catch (_) {}
      }
      lastBadge = count;
    }

    async function refreshNotifications(renderPanel) {
      const [notificationsResult, ordersResult] = await Promise.allSettled([
        RMS.api('/notifications'),
        RMS.api('/orders?mine=1'),
      ]);
      try {
        if (notificationsResult.status === 'rejected' && ordersResult.status === 'rejected') {
          throw notificationsResult.reason || new Error('Could not load updates');
        }
        const raw = notificationsResult.status === 'fulfilled' ? (notificationsResult.value.data || {}) : {};
        const base = Array.isArray(raw)
          ? { items: raw, unread: raw.filter((n) => !Number(n.is_read)).length, badge: raw.filter((n) => !Number(n.is_read)).length }
          : raw;
        const orders = ordersResult.status === 'fulfilled' ? (ordersResult.value.data || []) : [];
        const progress = orderProgressItems(orders);
        const payload = { ...base, items: [...progress, ...(base.items || [])] };
        setBadge(Number(payload.badge || payload.unread || 0));
        if (renderPanel || (notifPanel && !notifPanel.classList.contains('hidden'))) {
          renderNotifList(payload);
        }
      } catch (_) {
        if (renderPanel) {
          const list = document.getElementById('guest-notif-list');
          if (list) list.innerHTML = '<div class="notif-empty">Could not load updates.</div>';
        }
      }
    }

    refreshNotifications(false);
    pollTimer = setInterval(() => refreshNotifications(false), 8000);
    let liveStream = null;
    if (typeof RMS.connectNotificationStream === 'function') {
      liveStream = RMS.connectNotificationStream({
        onBadge(data) {
          if (data && (data.badge != null || data.unread != null)) {
            setBadge(Number(data.badge != null ? data.badge : data.unread));
          } else refreshNotifications(false);
        },
        onNotification() {
          refreshNotifications(notifPanel && !notifPanel.classList.contains('hidden'));
        },
      });
    }
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden) refreshNotifications(notifPanel && !notifPanel.classList.contains('hidden'));
    });
    window.addEventListener('beforeunload', () => {
      if (pollTimer) clearInterval(pollTimer);
      try { liveStream?.close(); } catch (_) {}
    });
  }

  async function pageFrame({ active, title, subtitle, user, showPromo, bodyInner, heroExtra }) {
    const promo = showPromo !== false ? await promoStripHtml() : '';
    return `
    <div class="flex min-h-screen flex-col bg-[#F6F1E9] font-sans text-[#241E1C] antialiased">
      ${headerHtml(active, user)}
      ${promo}
      ${title ? `
      <section class="bg-[#1C1412] px-4 py-9 text-white sm:px-6">
        <div class="mx-auto max-w-5xl">
          <p class="text-[11px] font-semibold uppercase tracking-[0.2em] text-[#E2C48A]">Restaurant of Suite · Makati</p>
          <h1 class="mt-2 font-display text-3xl font-semibold tracking-tight sm:text-4xl">${title}</h1>
          ${subtitle ? `<p class="mt-2 max-w-2xl text-sm leading-relaxed text-white/75">${subtitle}</p>` : ''}
          ${heroExtra || ''}
        </div>
      </section>` : ''}
      <main class="mx-auto w-full max-w-5xl flex-1 px-4 py-8 sm:px-6">${bodyInner || ''}</main>
      ${footerHtml()}
      ${accountModalsHtml(user)}
    </div>`;
  }

  async function mountCustomerPage(options) {
    const root = document.getElementById('app-root');
    if (!root) return null;
    const requireAuth = options.requireAuth !== false;
    let user = null;
    if (requireAuth) {
      user = await RMS.requireCustomer();
      if (!user) return null;
    } else {
      user = await RMS.optionalCustomer();
    }

    root.innerHTML = await pageFrame({
      active: options.active || root.dataset.active || 'home',
      title: options.title,
      subtitle: options.subtitle,
      user,
      showPromo: options.showPromo !== false,
      heroExtra: options.heroExtra || '',
      bodyInner: '<div id="portal-body"></div>',
    });

    bindAccountChrome(user);

    const body = document.getElementById('portal-body');
    if (typeof options.render === 'function') {
      await options.render(body, user);
    }
    if (typeof RMS.polish === 'function') RMS.polish(body);
    if (typeof RMS.bindModuleNavTransitions === 'function') RMS.bindModuleNavTransitions();
    return user;
  }

  async function injectGuestChrome(options) {
    const active = options.active || 'menu';
    const user = await RMS.optionalCustomer();
    const mount = document.getElementById('guest-chrome');
    if (!mount) return user;

    const promo = options.showPromo !== false ? await promoStripHtml() : '';
    mount.innerHTML = headerHtml(active, user) + promo + accountModalsHtml(user);
    mount.classList.add('rms-enter-soft');
    bindAccountChrome(user);
    if (typeof RMS.bindModuleNavTransitions === 'function') RMS.bindModuleNavTransitions();
    return user;
  }

  global.RMS.mountCustomerPage = mountCustomerPage;
  global.RMS.injectGuestChrome = injectGuestChrome;
  global.RMS.mountGuest = mountCustomerPage;
  global.RMS.customerMenuHref = menuHref;
  global.RMS.openGuestAccount = () => RMS.openModal('account-modal');
})(window);
