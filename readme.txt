=== Nigeria Area-Based Shipping (WooCommerce) ===
Contributors: ngabs
Tags: woocommerce, shipping, nigeria, areas, states
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.6.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Nigeria-only WooCommerce shipping method with State + Area pricing, custom tables, admin UI, CSV import, setup wizard, and checkout recalculation.

== Changelog ==
= 1.6.0 =
* Fix: Admin menu now appears under WooCommerce → Nigeria Shipping.
* Fix: Checkout Block integration now registers the Area field on woocommerce_blocks_loaded for reliable field rendering.
* Fix: More robust Area fee lookup (normalizes whitespace/case; fallback matching) so Area-specific fees override State defaults consistently.
* Fix: CSV importer normalizes Area names to avoid mismatches.
* UX: Hide WooCommerce Shipping Debug Mode banner on the frontend.
* Code: General cleanup and additional inline comments.

= 1.5.3 =
* Fixed Blocks Additional Checkout Field registration: select options now use required value/label format.

= 1.5.2 =
* Fixed Checkout Block notice: select field now registers with a placeholder option.
* Fixed pricing: include selected Area in WooCommerce shipping package hash so rates recalculate when Area changes (Classic + Block).

= 1.5.1 =
* Blocks JS: prevent repeated cart updates; apply options to late-mounted fields safely.
* Blocks script: declare wc-blocks-checkout dependency to avoid console warnings.

= 1.5.0 =
* Fixed fatal parse error in Checkout Blocks integration.
* Rebuilt Checkout Block updating to use wc.blocksCheckout.extensionCartUpdate() so Area selection updates shipping totals instantly.
* Fixed area fee override by trimming and consistent session handling.
* Delayed plugin initialization to woocommerce_init to avoid WP 6.7+ early i18n notices.

= 1.4.3 =
* Fixed Blocks Area fee pricing: support multiple setExtensionData signatures and payload shapes, ensuring selected Area reaches the server.

= 1.4.2 =
* Fixed Blocks Area mismatch: script now targets ngabs/area field and sends selected area to Store API.
* Store API callback now stores area consistently in session (ngabs_area + shipping/billing aliases).

= 1.4.1 =
* Fixed Blocks notice: prevent registering ngabs/area field twice.

= 1.4.0 =
* Fixed Blocks field registration: use valid location "address" (shows in billing + shipping).
* Consolidated Blocks field to single id ngabs/area.

= 1.3.9 =
* Fixed fatal error: prevent NGABS_Blocks_Integration from being declared twice.
* Avoid early translation loading warnings by removing 'woocommerce' textdomain usage and loading plugin textdomain on init.

= 1.3.8 =
* Fixed fatal error on activation by implementing required WooCommerce Blocks IntegrationInterface methods.
* Load plugin translations on init to avoid WP 6.7+ notice.

= 1.3.7 =
* Added Billing + Shipping Area fields for both Classic checkout and Checkout Block.
* Fixed Blocks: Areas reload on State change, selection resets, and shipping recalculates.
* Fixed pricing: Area fee now correctly overrides State fee; missing config returns ₦0.

= 1.3.6 =
* Fixed state mapping across Classic + Checkout Block: accept both state codes (LA) and labels (Lagos).
* Shipping now correctly applies Area fee when selected.

= 1.3.5 =
* Improved Checkout Block Area dropdown: options refresh on State change and selection triggers shipping recalculation.
* Blocks now sends selected Area to Store API extension data (fixes area fee mapping).

= 1.3.4 =
* Fixed Areas admin state switching (state codes were lowercased).
* Added modal popup for Add/Edit Area.
* Fixed Classic checkout Area field name/selector so it displays and persists.

= 1.3.3 =
* Fixed setup wizard crash by enabling zone method instance via settings option (no save() calls).

= 1.3.2 =
* Fixed activation parse errors by hardening admin delete confirmation output.
* Added setup wizard (preload Abuja+Lagos + auto-create Nigeria shipping zone + enable method).
* Added Classic checkout Area dropdown + instant shipping recalculation.
* Added Checkout Block support (defensive integration).
* Added State fees + Areas CRUD + CSV import.
