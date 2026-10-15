



    let openOrders = [];
    let newOrderItems = [];
    let newOrderCatalog = [];
    let posBusy = false;
    let lastPosSignature = '';
    let posTimer = null;
    RMS.bindModalDismiss('pay-modal');
    RMS.bindModalDismiss('create-order-modal');

    function renderNewOrderLines() {
      const root = document.getElementById('new-order-lines');
      root.innerHTML = newOrderItems.length ? newOrderItems.map((item, index) =>
        `<div class="flex items-center justify-between gap-2"><span>${item.quantity}Ã— ${item.name}</span><button type="button" class="btn-sm" data-remove-new-line="${index}">Remove</button></div>`
      ).join('') : '<p class="text-xs text-charcoal-muted">No items added yet.</p>';
    }

    async function openCreateOrder() {
      const [menu, tables, reservations] = await Promise.all([
        RMS.api('/menu/items'),
        RMS.api('/tables'),
        RMS.api('/reservations?date=' + encodeURIComponent(new Date().toLocaleDateString('en-CA'))),
      ]);
      const items = (menu.data || []).filter((item) => Number(item.is_available) !== 0);
      newOrderCatalog = items;
      document.getElementById('new-order-item').innerHTML = '<option value="">Select menu item</option>' + items.map((item) => `<option value="${item.id}">${item.name} Â· ${RMS.money(item.price)}</option>`).join('');
      document.getElementById('new-order-table').innerHTML = '<option value="">Select table</option>' + (tables.data || []).filter((t) => t.status !== 'cleaning').map((t) => `<option value="${t.id}">${t.table_number} Â· ${t.capacity} seats</option>`).join('');
      const guest = (reservations.data || []).find((r) => ['pending', 'confirmed', 'seated'].includes(String(r.status)));
      document.getElementById('new-order-guest').value = guest?.customer_name || '';
      if (guest?.table_id) document.getElementById('new-order-table').value = String(guest.table_id);
      newOrderItems = [];
      renderNewOrderLines();
      RMS.openModal('create-order-modal');
    }

    function syncSplitUi() {
      const mode = document.getElementById('split-mode').value;
      document.getElementById('split-fields').classList.toggle('hidden', mode !== 'mixed');
      document.getElementById('equal-fields').classList.toggle('hidden', !['equal', 'by_customer'].includes(mode));
    }

    function syncPayMethodUi() {
      const method = document.getElementById('pay-method').value;
      const ewallet = ['gcash', 'maya'].includes(method);
      const card = ['card', 'debit', 'credit'].includes(method);
      const cash = method === 'cash';
      document.getElementById('ewallet-fields').classList.toggle('hidden', !ewallet);
      document.getElementById('card-terminal').classList.toggle('hidden', !card);
      document.getElementById('cash-fields').classList.toggle('hidden', !cash);
      if (cash) updateCashChange();
      if (!card) {
        document.getElementById('terminal-confirmed').value = '0';
        document.getElementById('terminal-status').textContent = 'Ready to open the hosted terminalâ€¦';
      }
    }

    function updateCashChange() {
      const received = Number(document.getElementById('cash-tendered').value || 0);
      const summary = document.getElementById('pay-summary').textContent.match(/Remaining â‚±([\d,]+\.\d{2})/);
      const due = summary ? Number(summary[1].replace(/,/g, '')) : 0;
      const change = Math.max(0, received - due);
      document.getElementById('cash-change').textContent = RMS.money(change);
      document.getElementById('cash-change').classList.toggle('text-ember', received > 0 && received < due);
    }

    function syncDiscountIdUi() {
      const type = document.getElementById('discount-type').value;
      document.getElementById('discount-id-wrap').classList.toggle('hidden', !['senior', 'pwd'].includes(type));
    }

    function launchHostedPayment(method) {
      const orderId = Number(document.getElementById('pay-order').value);
      const summary = document.getElementById('pay-summary').textContent.match(/Remaining â‚±([\d,]+\.\d{2})/);
      const amount = summary ? Number(summary[1].replace(/,/g, '')) : 0;
      if (!orderId || amount <= 0) return RMS.toast('Open a valid bill before starting checkout', 'error');
      const params = new URLSearchParams({ method, amount: amount.toFixed(2), order: String(orderId) });
      const checkout = window.open('payment-sandbox.html?' + params.toString(), 'oxfordHostedPayment', 'width=480,height=760,resizable=yes,scrollbars=yes');
      if (!checkout) RMS.toast('Please allow pop-ups to open the hosted checkout', 'error');
    }

    window.addEventListener('message', (event) => {
      if (event.origin !== window.location.origin || event.data?.type !== 'oxford-payment-sandbox') return;
      if (String(event.data.order_id) !== String(document.getElementById('pay-order').value)) return;
      if (event.data.status === 'approved') {
        document.getElementById('pay-reference').value = event.data.reference || '';
        document.getElementById('terminal-confirmed').value = '1';
        document.getElementById('terminal-status').textContent = 'Approved Â· ' + (event.data.reference || 'transaction authorized');
        document.getElementById('ewallet-status').textContent = 'Approved Â· ' + (event.data.reference || 'transaction authorized');
        document.getElementById('ewallet-status').classList.remove('hidden');
        RMS.toast('Hosted checkout approved. Review and complete the payment.');
      } else if (event.data.status === 'declined') {
        document.getElementById('terminal-confirmed').value = '0';
        RMS.toast('Hosted checkout was declined', 'error');
      }
    });

    function posSignature(orders, pays, circuitText) {
      const orderKey = orders.map((o) => [o.id, o.order_number, o.table_number || '', o.total, o.status].join(':')).join('|');
      const payKey = pays.map((p) => [p.id, p.receipt_no, p.amount, p.method, p.status].join(':')).join('|');
      return orderKey + '::' + payKey + '::' + (circuitText || '');
    }

    async function refresh(force) {
      if (posBusy) return;
      posBusy = true;
      try {
        const [ordersRes, paysRes] = await Promise.all([
          RMS.api('/orders'),
          RMS.api('/payments'),
        ]);
        openOrders = (ordersRes.data || []).filter((o) => !['paid', 'cancelled'].includes(o.status));
        const pays = (paysRes.data || []).slice(0, 8);
        let circuitText = '';
        try {
          const cb = await RMS.api('/payments/circuit');
          circuitText = 'Circuit breaker: ' + (cb.data.state || 'closed') + ' Â· failures ' + (cb.data.failures || 0);
        } catch (_) {}

        const signature = posSignature(openOrders, pays, circuitText);
        if (!force && signature === lastPosSignature) return;
        lastPosSignature = signature;

        const ordersEl = document.getElementById('open-orders');
        const receiptsEl = document.getElementById('receipts');
        const circuitEl = document.getElementById('circuit-status');
        if (!ordersEl || !receiptsEl) return;

        ordersEl.innerHTML = openOrders.map((o) => `
          <tr class="border-b border-line/60 hover:bg-parchment/50">
            <td class="px-3 py-3 text-sm"><strong>${o.order_number}</strong><br><span class="text-xs text-charcoal-muted">#${o.id}</span></td>
            <td class="px-3 py-3 text-sm">${o.table_number || 'â€”'}</td>
            <td class="px-3 py-3 text-sm font-semibold">${RMS.money(o.total)}</td>
            <td class="px-3 py-3">${RMS.statusBadge(o.status)}</td>
            <td class="px-3 py-3"><button type="button" class="btn-sm" data-pay="${o.id}">Pay</button></td>
          </tr>`).join('') || '<tr><td colspan="5" class="px-3 py-8 text-center text-sm text-charcoal-muted">No open checks.</td></tr>';

        receiptsEl.innerHTML = pays.map((p) =>
          `<div class="flex items-center justify-between gap-2 border-b border-line py-2 text-sm"><span><strong>${p.receipt_no}</strong> Â· ${RMS.money(p.amount)} Â· ${p.method}</span>${p.status === 'completed' ? `<button type="button" class="btn-sm text-ember" data-refund="${p.id}">Refund</button>` : ''}</div>`
        ).join('') || '<p class="py-4 text-sm text-charcoal-muted">No payments yet.</p>';

        if (circuitEl && circuitText) circuitEl.textContent = circuitText;
      } catch (_) {
        /* keep last render on transient poll failures */
      } finally {
        posBusy = false;
      }
    }

    RMS.mountStaffPage({
      title: 'POS & Billing',
      subtitle: 'Settle checks â€” equal / by-guest / by-item splits, mixed tenders, promos & loyalty vouchers.',
      async render(body) {
        if (posTimer) clearInterval(posTimer);
        body.innerHTML = `
          <div class="mb-4 flex flex-wrap items-center gap-3">
            <p id="circuit-status" class="text-xs text-charcoal-muted"></p>
            <div class="flex-1"></div>
            <button type="button" id="btn-create-order" class="btn-primary !text-xs">Create order</button>
            <button type="button" id="btn-refresh" class="btn-secondary !text-xs">Refresh</button>
          </div>
          <div class="grid gap-5 lg:grid-cols-[1.35fr_1fr]">
            <div>
              <h3 class="mb-3 font-display text-lg font-semibold">Open checks</h3>
              <div class="overflow-x-auto rounded-2xl border border-line">
                <table class="w-full text-left">
                  <thead class="bg-parchment/80 text-[11px] uppercase tracking-wide text-charcoal-muted">
                    <tr><th class="px-3 py-3">Order</th><th class="px-3 py-3">Table</th><th class="px-3 py-3">Total</th><th class="px-3 py-3">Status</th><th class="px-3 py-3"></th></tr>
                  </thead>
                  <tbody id="open-orders"></tbody>
                </table>
              </div>
            </div>
            <div class="rounded-2xl border border-line bg-parchment-light p-5">
              <h3 class="mb-2 font-display text-lg font-semibold">Recent receipts</h3>
              <div id="receipts"></div>
            </div>
          </div>`;

        document.getElementById('btn-refresh').onclick = () => refresh(true);
        document.getElementById('btn-create-order').onclick = async () => {
          try { await openCreateOrder(); } catch (ex) { RMS.toast(ex.message, 'error'); }
        };
        document.getElementById('new-order-add').onclick = () => {
          const id = Number(document.getElementById('new-order-item').value);
          const item = newOrderCatalog.find((x) => Number(x.id) === id);
          const quantity = Math.max(1, Number(document.getElementById('new-order-qty').value) || 1);
          if (!item) return RMS.toast('Select a menu item first', 'error');
          const existing = newOrderItems.find((x) => Number(x.menu_item_id) === id);
          if (existing) existing.quantity += quantity;
          else newOrderItems.push({ menu_item_id: id, name: item.name, quantity });
          renderNewOrderLines();
        };
        document.getElementById('new-order-lines').onclick = (event) => {
          const remove = event.target.closest('[data-remove-new-line]');
          if (!remove) return;
          newOrderItems.splice(Number(remove.dataset.removeNewLine), 1);
          renderNewOrderLines();
        };
        document.getElementById('create-order-form').onsubmit = async (event) => {
          event.preventDefault();
          if (!newOrderItems.length) return RMS.toast('Add at least one menu item', 'error');
          try {
            const res = await RMS.api('/orders', {
              method: 'POST',
              body: {
                table_id: Number(document.getElementById('new-order-table').value),
                customer_name: document.getElementById('new-order-guest').value.trim(),
                source: 'pos',
                items: newOrderItems.map(({ menu_item_id, quantity }) => ({ menu_item_id, quantity })),
              },
            });
            RMS.closeModal('create-order-modal');
            RMS.toast('Order sent to kitchen Â· ' + res.data.order_number);
            await refresh(true);
          } catch (ex) { RMS.toast(ex.message, 'error'); }
        };
        document.getElementById('open-orders').onclick = async (e) => {
          const btn = e.target.closest('[data-pay]');
          if (!btn) return;
          const order = openOrders.find((o) => String(o.id) === btn.dataset.pay);
          document.getElementById('pay-order').value = order.id;
          document.getElementById('force-fail').checked = false;
          document.getElementById('split-mode').value = '';
          document.getElementById('promo-code').value = '';
          document.getElementById('loyalty-voucher').value = '';
          document.getElementById('discount-type').value = '';
          document.getElementById('discount-id-number').value = '';
          document.getElementById('pay-reference').value = '';
          document.getElementById('cash-tendered').value = '';
          document.getElementById('cash-change').textContent = 'â‚±0.00';
          document.getElementById('terminal-confirmed').value = '0';
          document.getElementById('preview-box').classList.add('hidden');
          document.getElementById('receipt-preview').classList.add('hidden');
          syncSplitUi();
          let remaining = order.total;
          try {
            const bill = await RMS.api('/payments/bill/' + order.id);
            remaining = bill.data.remaining ?? order.total;
          } catch (_) {}
          document.getElementById('pay-summary').textContent =
            order.order_number + ' Â· Table ' + (order.table_number || 'â€”') + ' Â· Remaining ' + RMS.money(remaining);
          document.getElementById('terminal-amount').textContent = RMS.money(remaining);
          document.getElementById('cash-tendered').value = Number(remaining).toFixed(2);
          document.getElementById('cash-change').textContent = 'â‚±0.00';
          document.getElementById('terminal-status').textContent = 'Ready to open the hosted terminalâ€¦';
          document.getElementById('split-amount').value = '';
          document.getElementById('split-amount-2').value = '';
          syncPayMethodUi();
          syncDiscountIdUi();
          RMS.openModal('pay-modal');
        };
        document.getElementById('receipts').onclick = async (e) => {
          const btn = e.target.closest('[data-refund]');
          if (!btn) return;
          if (!await RMS.confirm('Refund this completed payment?', { title: 'Refund payment', confirmLabel: 'Continue' })) return;
          const managerPin = await RMS.requestManagerPin('refund this payment');
          if (!managerPin) return;
          try {
            await RMS.api('/payments/refund/' + btn.dataset.refund, { method: 'POST', body: { manager_pin: managerPin } });
            RMS.toast('Payment refunded');
            await refresh(true);
          } catch (ex) { RMS.toast(ex.message, 'error'); }
        };
        await refresh(true);
        posTimer = setInterval(() => refresh(false), 12000);
        window.addEventListener('rms:live', () => { refresh(false); });
      },
    });

    document.getElementById('split-mode').onchange = syncSplitUi;
    document.getElementById('pay-method').onchange = syncPayMethodUi;
    document.getElementById('cash-tendered').oninput = updateCashChange;
    document.getElementById('discount-type').onchange = syncDiscountIdUi;
    document.getElementById('btn-card-checkout').onclick = () => launchHostedPayment('card');
    document.getElementById('btn-ewallet-checkout').onclick = () => launchHostedPayment(document.getElementById('pay-method').value);
    document.getElementById('btn-terminal-reset').onclick = () => {
      document.getElementById('terminal-confirmed').value = '0';
      document.getElementById('pay-reference').value = '';
      document.getElementById('ewallet-status').classList.add('hidden');
      document.getElementById('terminal-status').textContent = 'Ready to open the hosted terminalâ€¦';
    };
    document.getElementById('btn-preview').onclick = async () => {
      const mode = document.getElementById('split-mode').value;
      if (!['equal', 'by_customer', 'by_item'].includes(mode)) {
        RMS.toast('Select equal, by guest, or by item to preview', 'error');
        return;
      }
      try {
        const res = await RMS.api('/payments/preview-split', {
          method: 'POST',
          body: {
            order_id: Number(document.getElementById('pay-order').value),
            split_mode: mode,
            split_count: Number(document.getElementById('split-count').value) || 2,
            method: document.getElementById('pay-method').value,
          },
        });
        const box = document.getElementById('preview-box');
        box.classList.remove('hidden');
        box.innerHTML = (res.data.splits || []).map((s) =>
          `<div class="flex justify-between py-1"><span>${s.label}</span><strong>${RMS.money(s.amount)} Â· ${s.method}</strong></div>`
        ).join('') + `<div class="mt-2 border-t border-line pt-2">Remaining base: ${RMS.money(res.data.remaining)}</div>`;
      } catch (ex) {
        RMS.toast(ex.message, 'error');
      }
    };

    document.getElementById('btn-receipt-preview').onclick = async () => {
      const orderId = Number(document.getElementById('pay-order').value);
      const box = document.getElementById('receipt-preview');
      try {
        const bill = await RMS.api('/payments/bill/' + orderId);
        const order = await RMS.api('/orders/' + orderId);
        const o = order.data;
        const b = bill.data;
        const discount = document.getElementById('discount-type').value;
        const promo = document.getElementById('promo-code').value.trim();
        const voucher = document.getElementById('loyalty-voucher').value.trim();
        const method = document.getElementById('pay-method').value;
        const mode = document.getElementById('split-mode').value;
        const lines = (o.items || []).flatMap((i) => {
          const main = `${String(i.quantity).padStart(2)} Ã— ${(i.item_name || i.name || 'Item').slice(0, 22).padEnd(22)} ${RMS.money((i.unit_price || 0) * i.quantity)}`;
          const mods = [];
          if (i.notes) {
            try {
              const raw = typeof i.notes === 'string' && i.notes.trim().startsWith('{') ? JSON.parse(i.notes) : null;
              if (raw && typeof raw === 'object') {
                Object.entries(raw).forEach(([k, v]) => {
                  if (k === '_display') return;
                  const val = Array.isArray(v) ? v.join(', ') : v;
                  mods.push(`     Â· ${k}: ${val}`);
                });
              } else if (typeof i.notes === 'string') {
                mods.push(`     Â· ${i.notes}`);
              }
            } catch (_) {
              mods.push(`     Â· ${i.notes}`);
            }
          }
          return [main, ...mods];
        });
        let tenderNote = mode
          ? `Split: ${mode.replace(/_/g, ' ')}`
          : `Tender: ${method.toUpperCase()}`;
        if (mode === 'mixed') {
          tenderNote = `Mixed: ${document.getElementById('split-method').value}/${document.getElementById('split-method-2').value}`;
        }
        box.classList.remove('hidden');
        box.textContent = [
          'OXFORD SUITES Â· MAKATI',
          'Restaurant of Suite',
          '--------------------------------',
          `Order  ${o.order_number}`,
          `Table  ${o.table_number || 'â€”'}`,
          `Date   ${o.created_at || ''}`,
          '--------------------------------',
          ...lines,
          '--------------------------------',
          `Subtotal          ${RMS.money(o.subtotal ?? b.subtotal ?? o.total)}`,
          `VAT (12%)         ${RMS.money(o.tax || 0)}`,
          `Discount          ${RMS.money(o.discount || 0)}`,
          discount ? `(Applied: ${discount})` : '',
          promo ? `(Promo: ${promo})` : '',
          voucher ? `(Loyalty: ${voucher})` : '',
          `TOTAL DUE         ${RMS.money(b.remaining ?? o.total)}`,
          tenderNote,
          '--------------------------------',
          'Preview only â€” not charged',
          'Thank you for dining with us',
        ].filter(Boolean).join('\n');
      } catch (ex) {
        RMS.toast(ex.message, 'error');
      }
    };

    document.getElementById('pay-form').onsubmit = async (e) => {
      e.preventDefault();
      const mode = document.getElementById('split-mode').value;
      const method = document.getElementById('pay-method').value;
      const body = {
        order_id: Number(document.getElementById('pay-order').value),
        method,
        force_fail: document.getElementById('force-fail').checked,
        discount_type: document.getElementById('discount-type').value,
        discount_id_number: document.getElementById('discount-id-number').value.trim(),
        promo_code: document.getElementById('promo-code').value.trim(),
        loyalty_voucher: document.getElementById('loyalty-voucher').value.trim(),
        reference_no: document.getElementById('pay-reference').value.trim() || null,
        cash_tendered: Number(document.getElementById('cash-tendered').value || 0),
        terminal_confirmed: document.getElementById('terminal-confirmed').value === '1',
      };
      if (['gcash', 'maya'].includes(method) && !body.reference_no) {
        RMS.toast('Enter the GCash / Maya reference number', 'error');
        return;
      }
      if (['card', 'debit', 'credit'].includes(method) && !body.terminal_confirmed) {
        RMS.toast('Confirm the card on the terminal first (tap / swipe)', 'error');
        return;
      }
      if (method === 'cash' && body.cash_tendered <= 0) {
        RMS.toast('Enter the amount of cash received', 'error');
        return;
      }
      if (mode === 'mixed') {
        body.splits = [
          { method: document.getElementById('split-method').value, amount: Number(document.getElementById('split-amount').value), label: 'Tender 1', reference_no: body.reference_no, terminal_confirmed: body.terminal_confirmed },
          { method: document.getElementById('split-method-2').value, amount: Number(document.getElementById('split-amount-2').value), label: 'Tender 2', reference_no: body.reference_no, terminal_confirmed: body.terminal_confirmed },
        ];
      } else if (['equal', 'by_customer', 'by_item'].includes(mode)) {
        body.split_mode = mode;
        body.split_count = Number(document.getElementById('split-count').value) || 2;
      }
      if (['senior', 'pwd'].includes(body.discount_type) && !body.discount_id_number) {
        RMS.toast('Enter the PWD / Senior ID or OSCA number', 'error');
        return;
      }
      if (body.discount_type || body.promo_code || body.loyalty_voucher) {
        body.manager_pin = await RMS.requestManagerPin('override the discount');
        if (!body.manager_pin) return;
      }
      try {
        const res = await RMS.api('/payments', { method: 'POST', body });
        RMS.closeModal('pay-modal');
        const receipt = res.data.receipt_no || (res.data.receipts && res.data.receipts[0]) || '';
        const change = Number(res.data.change_due || 0) > 0 ? ' Â· Change ' + RMS.money(res.data.change_due) : '';
        RMS.toast((res.data.settled ? 'Settled' : 'Partial pay') + (receipt ? ' Â· ' + receipt : '') + ' Â· ' + RMS.money(res.data.paid_now || res.data.amount) + change);
        await refresh(true);
      } catch (ex) {
        RMS.toast(ex.message, 'error');
        await refresh(true);
      }
    };
  
