=== CLINK Gateway for WooCommerce ===
Contributors: WooClink
Donate link: https://woo-clink.wasmer.app/product/donate/
Tags: lightning, bitcoin, clink, nostr, woocommerce
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.7
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept Bitcoin Lightning Network payments via the CLINK protocol. Customers pay with ShockWallet, ZEUS, Amethyst, or any CLINK-compatible wallet.

== Description ==

Accept **Bitcoin Lightning Network** payments on your WooCommerce store via the **CLINK protocol**. No web server required for your Lightning node — all communication flows over Nostr relays.

Customers can pay with **ShockWallet**, **ZEUS**, **Amethyst**, or any CLINK-compatible Lightning wallet.

= How It Works =

1. **Merchant** generates a CLINK Offer string (`noffer1...`) from their CLINK-compatible wallet
2. **Customer** checks out and selects "Lightning (CLINK)" as payment method
3. The plugin uses the noffer to request a BOLT 11 Lightning invoice over Nostr
4. Customer scans the QR code and pays with any Lightning wallet
5. Payment is confirmed via CLINK receipt — order is marked complete

= Features =

* Supports simple, variable, digital, and subscription products
* Works with both classic checkout and WooCommerce Cart/Checkout Blocks
* Live BTC price via CoinGecko (5-minute cache) or optional fixed rate
* Display prices in sats, BTC, or ₿ (bip-0177) on product pages, cart, and checkout
* Configurable invoice timeout and poll interval
* Automatic sats conversion from your store currency
* QR code display on the order-received page
* Full refund support (manual Lightning refund)
* Compatible with Custom Price for WooCommerce Pro and other price-display plugins
* Client-side price conversion fallback for maximum plugin compatibility

== Installation ==

1. Download the plugin `.zip` from WordPress.org
2. Go to **Plugins > Add New > Upload Plugin**
3. Upload the `.zip` and activate
4. Go to **WooCommerce > Settings > Payments > CLINK (Lightning)**
5. Enable the gateway and paste your `noffer1...` string
6. Save changes

== Frequently Asked Questions ==

= What is a "noffer"? =

A noffer is a CLINK Offer string (`noffer1...`) that advertises your Lightning node's ability to receive payments. Generate one from ShockWallet, Lightning.Pub, or any CLINK-compatible wallet.

= Which wallets can customers use? =

Any CLINK-compatible Lightning wallet: **ShockWallet**, **ZEUS**, **Amethyst**, and others.

= Does this need a Lightning node on my server? =

No. All communication flows over Nostr relays. Your Lightning node only needs a Nostr connection.

= Are subscriptions supported? =

Yes. Subscriptions work with WooCommerce Subscriptions, Subscriptions for WooCommerce, YITH WooCommerce Subscription, Flexible Subscriptions, and others. After the first payment, customers can authorize auto-renewal via CLINK ndebit — future payments are processed automatically without scanning QR codes.

= How is the BTC price determined? =

The plugin fetches the live BTC price from CoinGecko with a 5-minute cache. You can optionally set a fixed rate in the settings.

= Does this plugin work with Custom Price for WooCommerce Pro? =

Yes. The plugin uses PHP_INT_MAX priority for all price filters and includes a client-side JavaScript fallback that converts prices in the browser, ensuring compatibility with Custom Price for WooCommerce Pro and similar price-display plugins.

== Third Party Services ==

This plugin communicates with the following third-party services:

= Nostr Relays (CLINK Protocol) =

* **Purpose**: Request and receive Lightning invoices from the merchant's CLINK-compatible wallet
* **When**: At checkout, when a customer places an order using the CLINK payment method
* **Data sent**: Merchant's noffer offer data, payment amount in satoshis, order description, and ephemeral public keys
* **Relay URL**: Determined by the merchant's noffer string (configured in the gateway settings)
* **Privacy note**: No personal customer data (name, email, address) is transmitted to Nostr relays. Only the payment amount and a short order description are shared. Customers choosing wallet-based auto-renewal (ndebit) authorize the merchant to pull future subscription payments; the ndebit string and subscription amount are known to the Nostr relay.

= CoinGecko =

* **Purpose**: Fetch the current BTC-to-fiat exchange rate for price display
* **When**: On checkout page load, every 5 minutes (result is cached in WordPress transients)
* **Data sent**: The store's currency code (e.g., `usd`, `eur`)
* **Privacy policy**: https://www.coingecko.com/en/privacy
* **Note**: If configured, a fixed exchange rate in the plugin settings bypasses this service entirely.

= ShockWallet.app =

* **Purpose**: Informational links for customers to generate CLINK noffer/ndebit strings
* **When**: When the customer clicks the link (no data sent by the plugin automatically)
* **Privacy policy**: https://docs.shock.network/privacy

== Screenshots ==

1. Payment method at checkout
2. QR code on order-received page
3. Gateway settings page

== Changelog ==

= 1.0.7 =
* Stripped remote gist.github.com URL from built JavaScript bundle (WordPress.org review compliance)
* Fixed contributors list to match plugin owner's WordPress.org username
* Added Third Party Services section documenting Nostr relays, CoinGecko, and ShockWallet.app
* "Payment:" row on View Subscription page shows "Auto-Renewal" (ndebit saved) or "Activate Auto-Renewal" link pointing to the parent order's order-received page for ndebit setup

= 1.0.6 =
* Subscription "Payment:" row now shows "Via Auto-Renewal" or "Via Manual Renewal" based on ndebit status
* Added "Activate Auto-Renewal" action link on My Account subscription items for subscriptions without ndebit
* Added "Get your nDebit string" link pointing to my.shockwallet.app/lapps at checkout and on My Account
* QR codes now generated client-side with bundled qrcode-generator library (no remote API dependency)
* Fixed "Auto-Renewal" title changed to "Setup Auto-Renewal" on the order-received page

= 1.0.5 =
* Added ndebit auto-renewal for subscriptions — customers authorize recurring payments via CLINK
* Auto-renewal is active by default after first subscription payment; titled "Setup Auto-Renewal" on the order-received page
* Customers can disable auto-renewal from My Account > Subscriptions
* Added "Get your nDebit string" link at checkout and on My Account subscription items pointing to https://my.shockwallet.app/lapps
* QR codes are now generated client-side using the bundled qrcode-generator library (no remote API dependency)
* Added BTC / sats / ₿ (bip-0177) currency display option — overrides all frontend prices
* Price display runs at PHP_INT_MAX priority to avoid conflicts with other plugins
* Added client-side price conversion fallback for maximum compatibility with Custom Price for WooCommerce Pro and similar plugins
* Fixed BTC/bip-0177 price formatting — now correctly shows 8 decimal places
* Compatibility improvements for third-party subscription plugins
* Fixed PCP warnings (text domain, non-prefixed hook ignore)

= 1.0.4 =
* Removed redundant Store Currency setting (uses WooCommerce default)
* External links in description now open in new tabs with rel="noopener noreferrer"
* Fixed blocks checkout description rendering (HTML no longer escaped)
* Fixed blocks checkout icon (was passing HTML instead of URL)
* Added loading="lazy" to gateway icon
* Updated repo and author URLs

= 1.0.3 =
* Fixed blocks checkout support with dedicated JS bundle
* Fixed WPCS violations from audit
* Added SCRIPT_DEBUG conditional for .min suffix
* Added subscriptions support

= 1.0.2 =
* Improved error handling and logging
* Better mobile QR code layout

= 1.0.1 =
* Fixed classic checkout redirect flow
* Added i18n support

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.0.7 =
Compliance fixes for WordPress.org review and subscription My Account improvements: "Payment" row now shows "Auto-Renewal" or links "Activate Auto-Renewal" to the order-received page for ndebit setup.

= 1.0.6 =
Subscription items now show "Via Auto-Renewal" / "Via Manual Renewal" with "Activate Auto-Renewal" link, client-side QR code generation, and "Get your nDebit string" link at checkout and My Account.

= 1.0.5 =
Adds ndebit auto-renewal for subscriptions with BTC/sats/₿ price display on all frontend pages, and improved compatibility with Custom Price for WooCommerce Pro.

= 1.0.4 =
Removes the redundant Store Currency setting. Your WooCommerce global currency is used automatically.

== Development ==

Source code for the built JavaScript bundles is included in the plugin:

* `assets/js/clink-checkout.js` — source for the order-received page bundle
* `assets/js/clink-blocks.js` — source for the blocks checkout registration
* `assets/js/clink-price-converter.js` — source for the client-side price conversion fallback
* `build.mjs` — esbuild configuration
* `package.json` — npm dependencies

To rebuild the minified bundles:

```
npm install
npm run build
```
