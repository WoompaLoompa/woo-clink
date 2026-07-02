# WooCommerce CLINK Gateway

Accept **Lightning Network** payments on your WooCommerce store via the **CLINK protocol** ([clinkme.dev](https://clinkme.dev)). Customers pay with **ShockWallet**, **ZEUS**, **Amethyst**, or any CLINK-compatible Lightning wallet.

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

1. Download the latest release `.zip` from the [releases page](https://github.com/shocknet/woo-clink/releases)
2. Go to **Plugins > Add New > Upload Plugin**
3. Upload the `.zip` and activate
4. Go to **WooCommerce > Settings > Payments > CLINK (Lightning)** to configure

### Manual

```bash
# Navigate to your WordPress plugins directory
cd wp-content/plugins/

# Clone the repository
git clone https://github.com/shocknet/woo-clink.git woocommerce-clink-gateway

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
| **Store Currency** | Your store's base currency (for BTC price conversion) |
| **Fixed BTC Rate** | Optional — set a fixed BTC price in your currency instead of live CoinGecko rate |
| **Invoice Timeout** | Seconds before the Lightning invoice expires (default: 600) |
| **Poll Interval** | Milliseconds between payment status checks (default: 5000) |

## Product Support

| Product Type | Supported |
|-------------|-----------|
| Simple | Yes |
| Variable | Yes |
| Digital / Virtual | Yes |
| Subscription (initial) | Yes |
| Subscription (auto-renewal) | Manual — customer returns to pay |
| Bookings / Appointments | Yes (via WooCommerce core) |

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

Two JavaScript bundles are built with esbuild:

- **`assets/js/clink-checkout.js`** — uses `@shocknet/clink-sdk` to request Lightning invoices via the CLINK protocol on the order-received page. Built to `clink-checkout.min.js`.
- **`assets/js/clink-blocks.js`** — registers the gateway with WooCommerce Cart/Checkout Blocks via `registerPaymentMethod()`. Built to `clink-blocks.min.js`.

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
│   │   └── clink-checkout.asset.php     # Asset metadata
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

## License

MIT — see [LICENSE](LICENSE).
