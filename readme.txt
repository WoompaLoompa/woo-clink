=== CLINK Gateway for WooCommerce ===
Contributors: woompaloompa
Donate link: https://woo-clink.wasmer.app/product/donate/
Tags: lightning, bitcoin, clink, nostr, woocommerce
Requires at least: 5.8
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.4
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
* Configurable invoice timeout and poll interval
* Automatic sats conversion from your store currency
* QR code display on the order-received page
* Full refund support (manual Lightning refund)

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

Yes. Initial subscription payments and manual renewals are supported. Automatic renewal requires the customer to return and pay.

= How is the BTC price determined? =

The plugin fetches the live BTC price from CoinGecko with a 5-minute cache. You can optionally set a fixed rate in the settings.

== Screenshots ==

1. Payment method at checkout
2. QR code on order-received page
3. Gateway settings page

== Changelog ==

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

= 1.0.4 =
Removes the redundant Store Currency setting. Your WooCommerce global currency is used automatically.

== Development ==

Source code for the built JavaScript bundles is included in the plugin:

* `assets/js/clink-checkout.js` — source for the order-received page bundle
* `assets/js/clink-blocks.js` — source for the blocks checkout registration
* `build.mjs` — esbuild configuration
* `package.json` — npm dependencies

To rebuild the minified bundles:

```
npm install
npm run build
```
