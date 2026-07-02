import {
  ClinkSDK,
  generateSecretKey,
  decodeBech32
} from '@shocknet/clink-sdk';

const QR_API = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=';

function el(tag, attrs = {}, children = []) {
  const elem = document.createElement(tag);
  for (const [key, val] of Object.entries(attrs)) {
    if (key === 'className') elem.className = val;
    else if (key === 'textContent') elem.textContent = val;
    else if (key === 'innerHTML') elem.innerHTML = val;
    else if (key.startsWith('on')) elem.addEventListener(key.slice(2).toLowerCase(), val);
    else elem.setAttribute(key, val);
  }
  for (const child of children) {
    if (typeof child === 'string') elem.appendChild(document.createTextNode(child));
    else if (child) elem.appendChild(child);
  }
  return elem;
}

function getQueryParam(name) {
  const params = new URLSearchParams(window.location.search);
  return params.get(name);
}

class ClinkPaymentUI {
  constructor(container, data) {
    this.container = container;
    this.data = data;
    this.sdk = null;
    this.invoice = null;
    this.paid = false;
    this.pollTimer = null;
    this.startTime = Date.now();
    this.timeout = parseInt(data.timeout, 10) * 1000 || 600000;
    this.ephemeralKey = null;
    this.render();
  }

  render() {
    this.container.innerHTML = '';
    this.container.appendChild(
      el('div', { className: 'wc-clink-container' }, [
        el('div', { className: 'wc-clink-header' }, [
          el('div', { className: 'wc-clink-bolt-icon' }, ['⚡']),
          el('h3', { textContent: wcClinkData.i18n.generatingInvoice }),
        ]),
        el('div', { className: 'wc-clink-loader' }, [
          el('img', { src: wcClinkData.loader, alt: 'Loading...' }),
        ]),
      ])
    );
    this.generateInvoice();
  }

  async generateInvoice() {
    try {
      const nofferData = this.data.noffer;
      if (!nofferData || !nofferData.startsWith('noffer1')) {
        throw new Error('Invalid noffer string');
      }

      const decoded = decodeBech32(nofferData);
      if (!decoded || decoded.type !== 'noffer') {
        throw new Error('Invalid noffer type');
      }

      const offer = decoded.data;

      this.ephemeralKey = generateSecretKey();
      this.sdk = new ClinkSDK({
        privateKey: this.ephemeralKey,
        relays: [offer.relay],
        toPubKey: offer.pubkey,
        defaultTimeoutSeconds: parseInt(this.data.timeout, 10) || 600,
      });

      const amountSats = parseInt(this.data.amountSats, 10);
      if (!amountSats || amountSats <= 0) {
        throw new Error('Invalid amount');
      }

      const description = this.data.description || '';

      const receiptCallback = (receipt) => {
        this.onPaymentConfirmed();
      };

      const response = await this.sdk.Noffer(
        {
          offer: offer.offer,
          amount_sats: amountSats,
          description: description.substring(0, 100),
          expires_in_seconds: parseInt(this.data.timeout, 10) || 600,
        },
        receiptCallback
      );

      if ('bolt11' in response && response.bolt11) {
        this.invoice = response.bolt11;
        await this.confirmInvoice();
        this.showInvoice();
      } else if ('error' in response) {
        const errData = response;
        let errMsg = errData.error || 'Unknown error';
        if (errData.range) {
          errMsg += ` (allowed range: ${errData.range.min} - ${errData.range.max} sats)`;
        }
        throw new Error(errMsg);
      } else {
        throw new Error('Unexpected response from CLINK provider');
      }
    } catch (err) {
      console.error('CLINK invoice generation failed:', err);
      this.showError(err.message || 'Failed to generate invoice');
    }
  }

  async confirmInvoice() {
    try {
      await fetch(wcClinkData.ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
          action: 'wc_clink_confirm_payment',
          nonce: wcClinkData.nonce,
          order_id: this.data.orderId,
          invoice: this.invoice,
        }),
      });
    } catch (err) {
      console.error('Failed to confirm invoice with backend:', err);
    }
  }

  showInvoice() {
    const bolt11 = this.invoice;
    const encodedBolt11 = encodeURIComponent(bolt11.toUpperCase());
    const qrUrl = `${QR_API}${encodedBolt11}`;
    const walletUrl = `lightning:${bolt11.toUpperCase()}`;

    this.container.innerHTML = '';
    this.container.appendChild(
      el('div', { className: 'wc-clink-container' }, [
        el('div', { className: 'wc-clink-header' }, [
          el('div', { className: 'wc-clink-bolt-icon' }, ['⚡']),
          el('h3', { textContent: wcClinkData.i18n.scanToPay }),
        ]),
        el('div', { className: 'wc-clink-qr' }, [
          el('img', {
            src: qrUrl,
            alt: 'Lightning Invoice QR Code',
            className: 'wc-clink-qr-img',
          }),
        ]),
        el('div', { className: 'wc-clink-amount' }, [
          el('span', { textContent: `${this.data.amountSats} sats` }),
        ]),
        el('div', { className: 'wc-clink-actions' }, [
          el('a', {
            href: walletUrl,
            className: 'wc-clink-btn wc-clink-btn-primary',
            textContent: wcClinkData.i18n.openInWallet,
          }),
          el('button', {
            className: 'wc-clink-btn wc-clink-btn-secondary',
            textContent: wcClinkData.i18n.copyInvoice,
            onClick: () => this.copyInvoice(bolt11),
          }),
        ]),
        el('div', { className: 'wc-clink-status' }, [
          el('div', { className: 'wc-clink-status-waiting' }, [
            el('img', { src: wcClinkData.loader, alt: '', className: 'wc-clink-inline-loader' }),
            el('span', { textContent: wcClinkData.i18n.waitingPayment }),
          ]),
        ]),
      ])
    );

    this.startPolling();
  }

  async copyInvoice(bolt11) {
    try {
      await navigator.clipboard.writeText(bolt11.toUpperCase());
      const btn = this.container.querySelector('.wc-clink-btn-secondary');
      if (btn) {
        const original = btn.textContent;
        btn.textContent = wcClinkData.i18n.invoiceCopied;
        setTimeout(() => { btn.textContent = original; }, 2000);
      }
    } catch {
      const textarea = document.createElement('textarea');
      textarea.value = bolt11.toUpperCase();
      document.body.appendChild(textarea);
      textarea.select();
      document.execCommand('copy');
      document.body.removeChild(textarea);
    }
  }

  startPolling() {
    if (this.pollTimer) clearInterval(this.pollTimer);

    this.pollTimer = setInterval(async () => {
      if (this.paid) return;

      if (Date.now() - this.startTime > this.timeout) {
        this.showExpired();
        return;
      }

      try {
        const resp = await fetch(wcClinkData.ajaxUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({
            action: 'wc_clink_check_payment',
            nonce: wcClinkData.nonce,
            order_id: this.data.orderId,
          }),
        });
        const json = await resp.json();
        if (json.success && json.data.paid) {
          this.onPaymentConfirmed();
        }
      } catch (err) {
        console.error('Poll error:', err);
      }
    }, parseInt(wcClinkData.pollInterval, 10) || 5000);
  }

  onPaymentConfirmed() {
    if (this.paid) return;
    this.paid = true;

    if (this.pollTimer) {
      clearInterval(this.pollTimer);
      this.pollTimer = null;
    }

    this.container.innerHTML = '';
    this.container.appendChild(
      el('div', { className: 'wc-clink-container' }, [
        el('div', { className: 'wc-clink-header' }, [
          el('div', { className: 'wc-clink-check-icon' }, ['✓']),
          el('h3', { textContent: wcClinkData.i18n.paymentConfirmed }),
        ]),
      ])
    );

    fetch(wcClinkData.ajaxUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        action: 'wc_clink_mark_paid',
        nonce: wcClinkData.nonce,
        order_id: this.data.orderId,
      }),
    })
      .then((r) => r.json())
      .then((json) => {
        if (json.success && json.data.redirect) {
          window.location.href = json.data.redirect;
        } else {
          window.location.reload();
        }
      })
      .catch(() => {
        window.location.reload();
      });
  }

  showError(message) {
    this.container.innerHTML = '';
    this.container.appendChild(
      el('div', { className: 'wc-clink-container wc-clink-error-container' }, [
        el('div', { className: 'wc-clink-error-icon' }, ['✕']),
        el('p', { className: 'wc-clink-error-msg', textContent: message }),
        el('button', {
          className: 'wc-clink-btn wc-clink-btn-primary',
          textContent: 'Try Again',
          onClick: () => this.render(),
        }),
      ])
    );
  }

  showExpired() {
    this.container.innerHTML = '';
    this.container.appendChild(
      el('div', { className: 'wc-clink-container wc-clink-error-container' }, [
        el('div', { className: 'wc-clink-error-icon' }, ['⏰']),
        el('p', { className: 'wc-clink-error-msg', textContent: wcClinkData.i18n.expired }),
        el('button', {
          className: 'wc-clink-btn wc-clink-btn-primary',
          textContent: 'Try Again',
          onClick: () => {
            this.startTime = Date.now();
            this.render();
          },
        }),
      ])
    );
  }
}

document.addEventListener('DOMContentLoaded', () => {
  const root = document.getElementById('wc-clink-payment-root');
  if (!root) return;

  if (!wcClinkData.noffer || !wcClinkData.amountSats) {
    root.innerHTML = '<div class="wc-clink-error-container"><p>Payment configuration missing.</p></div>';
    return;
  }

  new ClinkPaymentUI(root, wcClinkData);
});
