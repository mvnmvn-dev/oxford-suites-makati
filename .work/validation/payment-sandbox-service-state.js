
    const params = new URLSearchParams(window.location.search);
    const method = String(params.get('method') || 'card').toLowerCase();
    const amount = Number(params.get('amount') || 0);
    const orderId = String(params.get('order') || 'â€”');
    const channel = String(params.get('channel') || 'staff').toLowerCase();
    const providers = {
      card: { name: 'OxfordPay Terminal', mark: 'O', detail: '<p class="font-semibold">Card present transaction</p><p class="mt-1 text-xs text-charcoal-muted">Present your card or enter the details below to continue.</p><div class="mt-3 rounded-lg border border-line bg-parchment-light px-3 py-2 font-mono text-xs">â€¢â€¢â€¢â€¢ 4242 <span class="float-right text-charcoal-muted">VISA</span></div>' },
      gcash: { name: 'GCash Checkout', mark: 'G', detail: '<p class="font-semibold">GCash wallet payment</p><p class="mt-1 text-xs text-charcoal-muted">Confirm the payment in your GCash wallet to continue.</p><div class="mt-3 rounded-lg border border-line bg-parchment-light px-3 py-2 text-xs">Mobile wallet <span class="float-right font-semibold">09â€¢â€¢ â€¢â€¢â€¢ 1234</span></div>' },
      maya: { name: 'Maya Checkout', mark: 'M', detail: '<p class="font-semibold">Maya wallet payment</p><p class="mt-1 text-xs text-charcoal-muted">Confirm the payment in your Maya wallet to continue.</p><div class="mt-3 rounded-lg border border-line bg-parchment-light px-3 py-2 text-xs">Maya wallet <span class="float-right font-semibold">â€¢â€¢â€¢â€¢ 7788</span></div>' },
    };
    const provider = providers[method] || providers.card;
    const money = (value) => 'â‚±' + Number(value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    document.getElementById('provider-name').textContent = provider.name;
    document.getElementById('provider-mark').textContent = provider.mark;
    document.getElementById('amount').textContent = money(amount);
    document.getElementById('order-ref').textContent = '#' + orderId;
    const qrMarkup = (seed) => {
      const size = 13;
      const cells = [];
      const finder = (x, y) => x < 3 || x > 9 || y < 3 || y > 9 ? 0 : 1;
      for (let y = 0; y < size; y++) {
        for (let x = 0; x < size; x++) {
          const inFinder = (x < 5 && y < 5) || (x >= size - 5 && y < 5) || (x < 5 && y >= size - 5);
          const fx = x < 5 ? x : x >= size - 5 ? x - (size - 5) : x;
          const fy = y < 5 ? y : y >= size - 5 ? y - (size - 5) : y;
          const dark = inFinder ? finder(fx, fy) : ((x * 17 + y * 31 + seed.length * 13 + x * y) % 7 < 3 ? 1 : 0);
          cells.push(`<i class="${dark ? 'bg-[#241E1C]' : 'bg-white'}"></i>`);
        }
      }
      return `<div class="grid gap-0.5 rounded-lg bg-white p-2 shadow-sm" style="grid-template-columns:repeat(13,minmax(0,1fr))">${cells.join('')}</div>`;
    };
    const guestWallet = channel === 'guest' && ['gcash', 'maya'].includes(method);
    document.getElementById('method-details').innerHTML = guestWallet
      ? `<div class="flex items-center justify-between gap-3"><div><p class="font-semibold">Scan merchant QR</p><p class="mt-1 text-xs text-charcoal-muted">Open ${provider.name.replace(' Checkout', '')} and scan this code to pay.</p></div><span class="rounded-full bg-brass/20 px-2 py-1 text-[10px] font-bold uppercase tracking-wide text-brass-deep">Merchant QR</span></div><div class="mx-auto mt-4 w-fit">${qrMarkup(method + orderId)}</div><div class="mt-3 rounded-lg border border-line bg-parchment-light px-3 py-2 text-center text-xs"><span class="text-charcoal-muted">Merchant</span><strong class="ml-1">Oxford Suites Makati</strong></div>`
      : provider.detail;
    document.getElementById('approve').textContent = guestWallet ? 'I have paid â€” confirm payment' : 'Authorize payment';

    function finish(status, reference) {
      const result = document.getElementById('result');
      result.className = 'mt-4 rounded-xl p-3 text-sm ' + (status === 'approved' ? 'bg-sage-soft text-sage-deep' : 'bg-ember-soft text-ember');
      result.textContent = status === 'approved' ? 'Approved Â· ' + reference + ' Â· You may return to the POS.' : 'Transaction declined Â· No funds were moved.';
      result.classList.remove('hidden');
      document.getElementById('approve').disabled = true;
      document.getElementById('decline').disabled = true;
      if (window.opener && !window.opener.closed) {
        window.opener.postMessage({ type: 'oxford-payment-sandbox', status, reference, order_id: orderId }, window.location.origin);
      }
      if (status === 'approved') setTimeout(() => window.close(), 1400);
    }

    document.getElementById('approve').onclick = () => {
      const reference = 'SIM-' + method.toUpperCase() + '-' + Date.now().toString(36).toUpperCase();
      document.getElementById('approve').textContent = 'Authorizingâ€¦';
      setTimeout(() => finish('approved', reference), 850);
    };
    document.getElementById('decline').onclick = () => finish('declined', '');
  
