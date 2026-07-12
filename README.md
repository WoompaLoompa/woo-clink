# Bitcoin Lightning Payment Gateway for WooCommerce (via CLINK)

Accept **[Bitcoin](https://bitcoin.org)** **Lightning** payments on your WooCommerce store via the **CLINK protocol** ([clinkme.dev](https://clinkme.dev)). Customers pay with **[ShockWallet.app](https://ShockWallet.app)**, [ZEUS](https://ZEUSln.com), **[Amethyst](https://amethyst.social) [Electrum](https://github.com/BareBits/electrum_clink)or any other CLINK-compatible Lightning wallet. All transmitted privately and anonymously via relays of the Nostr protocol.

> **Demo**: [woo-clink.wasmer.app](https://woo-clink.wasmer.app) 
> **Plugin**: [github.com/WoompaLoompa/woo-clink](https://github.com/WoompaLoompa/woo-clink)
> **Wordpress**: [https://wordpress.org/plugins/clink-gateway-for-woocommerce/](https://wordpress.org/plugins/clink-gateway-for-woocommerce/)


## How It Works

1. **Merchant** generates a CLINK Offer string (`noffer1...`) from their CLINK-compatible Lightning node (ShockWallet, Lightning.Pub, etc.)
2. **Customer** checks out and selects "Lightning (CLINK)" as payment method
3. The plugin uses the `noffer` to request a BOLT 11 Lightning invoice from the merchant's node over Nostr
4. Customer scans the QR code and pays with any Lightning wallet
5. Payment is confirmed via CLINK protocol receipt — order is marked complete

No web server required for the Lightning node. All communication flows over Nostr relays.

## Requirements

- WordPress 5.8+
- WooCommerce 6.0+
- PHP 7.4+
- A CLINK-compatible Lightning wallet/node (ShockWallet, Lightning.Pub, ZEUS, Amethyst)

## Installation

### From WordPress Admin

1. Download the latest release `.zip` from the [releases page](https://github.com/WoompaLoompa/woo-clink/releases)
2. Go to **Plugins > Add New > Upload Plugin**
3. Upload the `.zip` and activate
4. Go to **WooCommerce > Settings > Payments > CLINK (Lightning)** to configure

### Manual

```bash
# Navigate to your WordPress plugins directory
cd wp-content/plugins/

# Clone the repository
git clone https://github.com/WoompaLoompa/woo-clink.git woocommerce-clink-gateway

# Install JS dependencies and build (optional — pre-built bundle included)
cd woocommerce-clink-gateway
npm install && npm run build
```

Then activate the plugin from the WordPress admin.

## Configuration

Navigate to **WooCommerce > Settings > Payments > CLINK (Lightning)**.

| Setting | Description |
|---------|-------------|
| **Enable/Disable** | Turn the gateway on/off |
| **Title** | Payment method title shown at checkout |
| **Description** | Payment method description shown at checkout |
| **CLINK Offer String** | Your `noffer1...` string from ShockWallet / Lightning.Pub |
| **Fixed BTC Rate** | Optional — set a fixed BTC price in your currency instead of live CoinGecko rate |
| **Display Amount As** | Choose how prices appear on product pages, cart, and checkout: sats, BTC, or ₿ (bip-0177). Overrides WooCommerce frontend prices. |
| **Invoice Timeout** | Seconds before the Lightning invoice expires (default: 600) |
| **Poll Interval** | Milliseconds between payment status checks (default: 5000) |

## Product Support

| Product Type | Supported |
|-------------|-----------|
| Simple | Yes |
| Variable | Yes |
| Digital / Virtual | Yes |
| Subscription (initial) | Yes |
| Subscription (auto-renewal) | Yes — via CLINK ndebit authorization |
| Bookings / Appointments | Yes (via WooCommerce core) |

## Auto-Renewal (Subscriptions)

After the first subscription payment, customers are prompted to **Setup Auto-Renewal** on the order-received page:

1. Open their CLINK wallet (ShockWallet, ZEUS, Amethyst)
2. Visit **Get your nDebit string** link pointing to [my.shockwallet.app/lapps](https://my.shockwallet.app/lapps)
3. Copy the `ndebit1...` string and paste it into the auto-renewal field
4. Future payments are processed automatically — no QR scanning required

The **My Account > View Subscription** page reflects the ndebit status in the "Payment" row:

- **No ndebit saved**: Shows "Activate Auto-Renewal" as a link back to the parent order's order-received page for setup
- **Ndebit saved**: Shows "Auto-Renewal" (plain text)

Customers can **disable** auto-renewal from **My Account > Subscriptions** at any time. Subscriptions without ndebit show a "Get nDebit String" action link to enable auto-renewal later.

## Generating a `noffer`

### ShockWallet (mobile)

1. Open ShockWallet
2. Go to **Receive > CLINK Offer**
3. Copy the `noffer1...` string
4. Paste into the plugin settings

### Lightning.Pub (self-hosted)

1. Log into your Lightning.Pub dashboard
2. Navigate to **Offers**
3. Copy the generated `noffer1...` string

## Development

```bash
# Install dependencies
npm install

# Build JS bundle
npm run build

# Watch for changes
npm run watch
```

Three JavaScript bundles are built with esbuild:

- **`assets/js/clink-checkout.js`** — uses `@shocknet/clink-sdk` to request Lightning invoices via the CLINK protocol on the order-received page. QR codes are generated client-side with `qrcode-generator` (no remote API). Built to `clink-checkout.min.js`.
- **`assets/js/clink-blocks.js`** — registers the gateway with WooCommerce Cart/Checkout Blocks via `registerPaymentMethod()`. Built to `clink-blocks.min.js`.
- **`assets/js/clink-price-converter.js`** — client-side price conversion fallback that converts `.woocommerce-Price-amount` elements to sats/BTC/₿ on all frontend pages. Ensures compatibility with price-display plugins like Custom Price for WooCommerce Pro. Built to `clink-price-converter.min.js`.

## Architecture

```
Checkout Flow:
  Customer                    WooCommerce                  Nostr Relay          Merchant Node
     │                           │                           │                     │
     │── Place Order ───────────▶│                           │                     │
     │                           │── Create Order (pending)  │                     │
     │                           │── Redirect to thank-you   │                     │
     │                           │                           │                     │
     │── Page Load ─────────────▶│                           │                     │
     │                           │── Localize noffer + sats  │                     │
     │                           │                           │                     │
     │── JS: Decode noffer ──────┤                           │                     │
     │── JS: Generate ephemeral  │                           │                     │
     │     Nostr key             │                           │                     │
     │── JS: Send kind:21001 ────┼──────────────────────────▶│── Request Invoice ─▶│
     │                           │                           │                     │
     │                           │◀── BOLT 11 Invoice ──────│◀─ Response ─────────│
     │── Display QR code ◀──────┤                           │                     │
     │── Customer pays ◀─────────┼──────────────────────────▶│                     │
     │                           │                           │── Receipt ─────────▶│
     │◀── Receipt callback ──────┤◀──────────────────────────│                     │
     │── AJAX: mark_paid ───────▶│                           │                     │
     │                           │── Order complete ────────▶│                     │
```

## Files

```
woocommerce-clink-gateway/
├── woocommerce-clink-gateway.php        # Plugin bootstrap, AJAX handlers, scripts
├── includes/
│   ├── class-wc-gateway-clink.php       # WC_Payment_Gateway implementation
│   ├── class-wc-clink-subscriptions.php # WooCommerce Subscriptions integration
│   └── class-wc-clink-blocks-support.php# Cart/Checkout Blocks support
├── assets/
│   ├── js/
│   │   ├── clink-checkout.js            # Source — order-received page (ES module, @shocknet/clink-sdk)
│   │   ├── clink-checkout.min.js        # Built bundle
│   │   ├── clink-blocks.js             # Source — blocks checkout registration
│   │   ├── clink-blocks.min.js         # Built bundle
│   │   ├── clink-price-converter.js    # Source — client-side price conversion fallback
│   │   ├── clink-price-converter.min.js# Built bundle
│   │   └── clink-checkout.asset.php    # Asset metadata
│   ├── css/clink-checkout.css           # Checkout page styles
│   └── images/lightning-icon.svg        # Payment method icon
├── build.mjs                            # esbuild config
└── package.json                         # NPM dependencies
```

## Security

- **Nonces**: All AJAX endpoints use `wp_create_nonce()` / `check_ajax_referer()`
- **Escaping**: All output uses WordPress escaping functions (`esc_html`, `esc_url`, `esc_js`, `wp_kses_post`)
- **Sanitization**: All input uses `sanitize_text_field()` / `absint()`
- **No direct DB queries**: All data access through WooCommerce APIs
- **Ephemeral keys**: Each checkout generates a fresh Nostr key pair — never reuses keys

## Third Party Services

This plugin communicates with the following third-party services:

### Nostr Relays (CLINK Protocol)

- **Purpose**: Request and receive Lightning invoices from the merchant's CLINK-compatible wallet.
- **When**: At checkout, when a customer places an order using the CLINK payment method.
- **Data sent**: Merchant's noffer offer data, payment amount in satoshis, order description, and ephemeral public keys.
- **Relay URL**: Determined by the merchant's noffer string (configured in gateway settings).
- **Privacy note**: No personal customer data (name, email, address) is transmitted to Nostr relays. Only payment amount and a short order description are shared. Customers choosing wallet-based auto-renewal (ndebit) authorize the merchant to pull future subscription payments; the ndebit string and subscription amount are known to the Nostr relay.

### CoinGecko

- **Purpose**: Fetch the current BTC-to-fiat exchange rate for price display.
- **When**: On checkout page load, every 5 minutes (result cached in WordPress transients).
- **Data sent**: The store's currency code (e.g., `usd`, `eur`).
- **Privacy policy**: https://www.coingecko.com/en/privacy
- **Note**: If configured, a fixed exchange rate in the plugin settings bypasses this service entirely.

### ShockWallet.app

- **Purpose**: Informational links for customers to generate CLINK noffer/ndebit strings.
- **When**: When the customer clicks the link (no data sent by the plugin automatically).
- **Privacy policy**:https://docs.shock.network/privacy

## License

GPL3 or later
