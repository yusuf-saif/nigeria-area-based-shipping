=== Nigeria Area-Based Shipping (WooCommerce) ===
Contributors: ngabs
Tags: woocommerce, shipping, nigeria, areas, states
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.3.7
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Nigeria-only WooCommerce shipping method with State + Area pricing, custom tables, admin UI, CSV import, setup wizard, and checkout recalculation.

== Changelog ==
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
