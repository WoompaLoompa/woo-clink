import {
  ClinkSDK,
  generateSecretKey,
  decodeBech32,
} from '@shocknet/clink-sdk';
import qrcode from 'qrcode-generator';

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

function formatAmount(sats, format) {
  switch (format) {
    case 'btc':
      return (sats / 100000000).toFixed(8) + ' BTC';
    case 'bip0177':
      return '\u20BF ' + (sats / 100000000).toFixed(8);
    case 'sats':
    default:
      return Number(sats).toLocaleString() + ' sats';
  }
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
    this.hasSubscription = data.hasSubscription || false;
    this.isRenewal = data.isRenewal || false;
    this.currencyFormat = data.currencyFormat || 'sats';
    this.render();
  }

  render() {
    this.container.innerHTML = '';
    this.container.appendChild(
      el('div', { className: 'wc-clink-container' }, [
        el('div', { className: 'wc-clink-header' }, [
          el('div', { className: 'wc-clink-bolt-icon' }, ['\u26A1']),
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
    const qr = qrcode(0, 'M');
    qr.addData(bolt11.toUpperCase());
    qr.make();
    const qrDataUrl = qr.createDataURL(10, 4);
    const walletUrl = `lightning:${bolt11.toUpperCase()}`;
    const formattedAmount = formatAmount(this.data.amountSats, this.currencyFormat);

    this.container.innerHTML = '';
    this.container.appendChild(
      el('div', { className: 'wc-clink-container' }, [
        el('div', { className: 'wc-clink-header' }, [
          el('div', { className: 'wc-clink-bolt-icon' }, ['\u26A1']),
          el('h3', { textContent: this.isRenewal ? wcClinkData.i18n.renewalProcessing : wcClinkData.i18n.scanToPay }),
        ]),
        el('div', { className: 'wc-clink-qr' }, [
          el('img', {
            src: qrDataUrl,
            alt: 'Lightning Invoice QR Code',
            className: 'wc-clink-qr-img',
          }),
        ]),
        el('div', { className: 'wc-clink-amount' }, [
          el('span', { textContent: formattedAmount }),
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
            el('span', { textContent: this.isRenewal ? wcClinkData.i18n.renewalAutoPay : wcClinkData.i18n.waitingPayment }),
          ]),
        ]),
        this.isRenewal && this.data.ndebit ? el('div', { className: 'wc-clink-renewal-info' }, [
          el('p', { textContent: wcClinkData.i18n.ndebitActive }),
        ]) : null,
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

  async markPaid() {
    try {
      const resp = await fetch(wcClinkData.ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
          action: 'wc_clink_mark_paid',
          nonce: wcClinkData.nonce,
          order_id: this.data.orderId,
        }),
      });
      return await resp.json();
    } catch {
      return { success: false };
    }
  }

  onPaymentConfirmed() {
    if (this.paid) return;
    this.paid = true;

    if (this.pollTimer) {
      clearInterval(this.pollTimer);
      this.pollTimer = null;
    }

    if (this.hasSubscription && !this.isRenewal) {
      this.markPaid().then(() => this.showNdebitSetup());
    } else {
      this.markPaid().then((json) => {
        if (json.success && json.data && json.data.redirect) {
          window.location.href = json.data.redirect;
        } else {
          window.location.reload();
        }
      });
    }
  }

  showNdebitSetup() {
    this.container.innerHTML = '';
    this.container.appendChild(
      el('div', { className: 'wc-clink-container' }, [
        el('div', { className: 'wc-clink-header' }, [
          el('div', { className: 'wc-clink-check-icon' }, ['\u2713']),
          el('h3', { textContent: wcClinkData.i18n.paymentConfirmed }),
        ]),
        el('div', { className: 'wc-clink-ndebit-section' }, [
          el('h4', { textContent: wcClinkData.i18n.ndebitTitle }),
          el('p', { className: 'wc-clink-ndebit-desc', innerHTML: wcClinkData.i18n.ndebitDescription }),
          el('input', {
            type: 'text',
            className: 'wc-clink-ndebit-input',
            placeholder: wcClinkData.i18n.ndebitPlaceholder,
            id: 'wc-clink-ndebit-input',
          }),
          el('div', { className: 'wc-clink-ndebit-actions' }, [
            el('button', {
              className: 'wc-clink-btn wc-clink-btn-primary',
              textContent: wcClinkData.i18n.ndebitSave,
              onClick: () => this.saveNdebit(),
            }),
            el('button', {
              className: 'wc-clink-btn wc-clink-btn-link',
              textContent: wcClinkData.i18n.ndebitSkip,
              onClick: () => this.redirectAfterPayment(),
            }),
          ]),
          el('div', { className: 'wc-clink-ndebit-saved', id: 'wc-clink-ndebit-saved', style: 'display:none' }, [
            el('span', { className: 'wc-clink-ndebit-saved-icon' }, ['\u2713']),
            el('span', { textContent: wcClinkData.i18n.ndebitSaved }),
          ]),
        ]),
      ])
    );
  }

  async saveNdebit() {
    const ndebit = document.getElementById('wc-clink-ndebit-input').value.trim();
    if (!ndebit || !ndebit.startsWith('ndebit1')) {
      alert('Please enter a valid ndebit string starting with "ndebit1".');
      return;
    }
    try {
      const resp = await fetch(wcClinkData.ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
          action: 'wc_clink_save_ndebit',
          nonce: wcClinkData.nonce,
          order_id: this.data.orderId,
          ndebit: ndebit,
        }),
      });
      const json = await resp.json();
      if (json.success) {
        document.getElementById('wc-clink-ndebit-saved').style.display = 'block';
        const input = document.querySelector('.wc-clink-ndebit-input');
        const actions = document.querySelector('.wc-clink-ndebit-actions');
        if (input) input.style.display = 'none';
        if (actions) actions.style.display = 'none';
        setTimeout(() => this.redirectAfterPayment(), 2000);
      } else {
        alert(json.data && json.data.message ? json.data.message : 'Failed to save auto-renewal settings.');
      }
    } catch (err) {
      console.error('Ndebit save error:', err);
      alert('Error saving auto-renewal settings. Please try again.');
    }
  }

  redirectAfterPayment() {
    const url = wcClinkData.redirectUrl;
    if (url) {
      window.location.href = url;
    } else {
      window.location.reload();
    }
  }

  showError(message) {
    this.container.innerHTML = '';
    this.container.appendChild(
      el('div', { className: 'wc-clink-container wc-clink-error-container' }, [
        el('div', { className: 'wc-clink-error-icon' }, ['\u2715']),
        el('p', { className: 'wc-clink-error-msg', textContent: message }),
        el('button', {
          className: 'wc-clink-btn wc-clink-btn-primary',
          textContent: wcClinkData.i18n.tryAgain,
          onClick: () => this.render(),
        }),
      ])
    );
  }

  showExpired() {
    this.container.innerHTML = '';
    this.container.appendChild(
      el('div', { className: 'wc-clink-container wc-clink-error-container' }, [
        el('div', { className: 'wc-clink-error-icon' }, ['\u23F0']),
        el('p', { className: 'wc-clink-error-msg', textContent: wcClinkData.i18n.expired }),
        el('button', {
          className: 'wc-clink-btn wc-clink-btn-primary',
          textContent: wcClinkData.i18n.tryAgain,
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
