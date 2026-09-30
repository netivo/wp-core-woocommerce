# AGENTS.md

Guidance for AI coding agents working in this repository.

## What this is

`netivo/woocommerce` — a Composer package (PSR-4: `Netivo\WooCommerce\` → `src/`) that adds base classes for building WooCommerce support into Netivo WordPress themes. It is not a standalone plugin/theme and depends on `netivo/wp-core` (`Netivo\Core\Theme`) being present in the consuming theme.

## Commands

- `npm run develop` — watch/build JS assets for development (via `@netivo/scripts`).
- `npm run build` — production build of JS assets into `dist/`.
- `npm run lint` — lint JS sources.

There is no PHP test suite, linter config, or build step in this repo — PHP under `src/` is consumed directly by the parent theme via Composer autoload. There are no unit tests.

JS source lives in `sources/javascript/entries/checkout.js` and is bundled to `dist/netivo-woocommerce-checkout.js` (checked into git — rebuild with `npm run build` after editing the source, and commit the updated `dist/` output).

## Architecture

### Bootstrapping pattern

`WooCommerce` (`src/WooCommerce.php`) is an abstract class meant to be extended by the consuming theme. Its constructor:
1. Receives the theme's main `Theme` instance (`$main_class`) and reads a `modules.woocommerce` config array off it (`$main_class->get_configuration()`).
2. Always instantiates `Company` (billing/NIP/invoice logic — always on).
3. Calls `init_vars()`, `init_product_types()`, then the abstract `init_child()` hook for subclass-specific setup.
4. On `is_admin()`, also calls `init_product_tabs()` and the abstract `init_child_admin()`.

Subclasses (defined in the parent theme, not in this repo) must implement `init_vars()`, `init_child()`, and `init_child_admin()`.

### Extension points via config arrays

Two of the `modules` config entries drive dynamic class loading by fully-qualified class name string, then `class_exists()`-checked before use:
- `modules['product_type']` — array of class names extending `Product\Type` (itself extending `\WC_Product_Simple`); each must implement a static `register(): void` used to register the custom WooCommerce product type.
- `modules['product_tabs']` — array of class names extending `Admin\Product\Tab`; each is instantiated with the theme's view path and hooks itself into `woocommerce_product_data_tabs` / `woocommerce_product_data_panels` / `save_post`.

### Admin\Product\Tab view resolution

`Tab::display()` resolves its `.phtml` view by reflection: it first looks for a `Netivo\Attributes\View` PHP attribute on the subclass for an explicit view name; if absent, it derives the name from the subclass's filename (lowercased). The resolved path is always `{$path}/woocommerce/product/tabs/{$name}.phtml`, where `$path` is the theme's view/admin path passed in from `WooCommerce::init_product_tabs()`. Missing view files throw.

### Company: checkout/invoice logic

`Company` (`src/Company.php`) is the concrete, always-loaded piece of WooCommerce integration. It hooks into WooCommerce billing fields, address formatting, checkout, and account pages to support Polish invoicing requirements:
- Adds a `billing_nip` field (Polish tax ID / NIP) and a `fv_vat` radio (receipt vs. VAT invoice) to checkout.
- Two filters control name-field behavior when invoice is selected, both defaulting to `true`/`false` and overridable by the consuming theme: `netivo/woocommerce/fv/hide-name` and `netivo/woocommerce/fv/name-required`.
- Validates NIP checksum (`validate_nip()` — Polish 10-digit mod-11 algorithm) during checkout and account address saves.
- Persists `_fv_vat`/`_nip` order meta and `billing_nip` user meta.
- Enqueues `dist/netivo-woocommerce-checkout.js` on the checkout page only, resolved from the theme's `template_directory` (not this package's own path — it assumes `dist/` is deployed under the active theme's directory).

The `checkout.js` source toggles visibility between `.js-fvat-to-show` (company/NIP fields) and `.js-fvat-to-hide` (personal name fields) based on the `.js-fv-checkbox` radio selection — this is the client-side counterpart to the `hide-name` filter logic in `Company::add_invoice_field()`.
