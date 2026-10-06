# Plan: NIP / Invoice-vs-Receipt Field for Block Checkout

Date: 2026-09-30
Status: Planning only — nothing implemented yet.

## 1. Goal

Bring the existing classic-checkout NIP/invoice feature (`../src/Company.php`) to the WooCommerce
**Checkout block**, with identical business rules:

- Customer picks **receipt** or **VAT invoice** (currently the `fv_vat` radio: `0` = receipt,
  `1` = VAT invoice).
- If **VAT invoice**: company name (`billing_company`) and NIP (`billing_nip`) become required;
  first/last name visibility/required-ness is controlled by the
  `netivo/woocommerce/fv/hide-name` / `netivo/woocommerce/fv/name-required` filters (same as
  today).
- If **receipt**: only first/last name are required, no company/NIP.
- The whole thing must render inside **"Adres rozliczeniowy" (Billing address)** only, and must
  **not** appear in **"Adres do wysyłki" (Shipping address)**.
- Data must land in the same places it does today (`_fv_vat` / `_nip` order meta, `billing_nip`
  user meta) so admin order screens, emails, and `Company::add_nip_to_order_address()` /
  `add_nip_to_customer_address()` keep working unmodified for orders placed through either
  checkout.

## 2. Platform constraints discovered

Before picking an approach I checked WooCommerce's own docs for the "Additional Checkout Fields"
API (the officially supported way to add fields to the Checkout block), current as of the block
checkout docs:

- Fields registered with `location: 'address'` **always render in both the shipping and billing
  address forms** — "Billing-only or shipping-only restrictions are not supported; address fields
  apply to both." There is no parameter to scope a field to one address type only.
  ([Additional checkout fields](https://developer.woocommerce.com/docs/block-development/extensible-blocks/cart-and-checkout-blocks/additional-checkout-fields/))
- This exact limitation is tracked as an open issue upstream:
  [woocommerce/woocommerce#64099](https://github.com/woocommerce/woocommerce/issues/64099) —
  "additional checkout fields appear in both billing and shipping."
- The Additional Fields API's `type` is one of `text`, `select`, `checkbox`, `date` — **there is
  no `radio` type**, so the current `fv_vat` radio can't be reproduced 1:1 via that API.
- The API's JSON-Schema `required`/`hidden` conditional logic can reference **other fields
  registered through the same API** (e.g. `checkout.additional_fields.netivo/fv-vat`), but it
  cannot make **core fields** (`billing_first_name`, `billing_last_name`, `billing_company`)
  conditionally required/hidden — that only works for fields you register yourself.
  ([conditional-visibility guide via codebytom.blog](https://codebytom.blog/2025/05/how-to-make-your-woocommerce-additional-checkout-fields-conditionally-visible/))
- Dynamically changing a **core** field's required/visible state in response to another field's
  value is not exposed as a simple PHP filter; it needs client-side JS against the Blocks data
  stores (`registerCheckoutFilters` / `wc/store/validation`), confirmed by community sources
  describing this as requiring "custom JavaScript using the WooCommerce Blocks extensibility API."
  ([litextension.com summary via search](https://litextension.com/blog/woocommerce-checkout-fields/))
- WooCommerce Blocks **does** support registering a fully custom inner block scoped to a specific
  parent block via `block.json`'s `"parent"` key, e.g.
  `"parent": ["woocommerce/checkout-billing-address-block"]` — a block registered this way can
  only ever be inserted inside the Billing Address section, never Shipping.
  ([Tyche Softwares: custom inner blocks in Checkout](https://www.tychesoftwares.com/how-to-add-custom-innner-blocks-to-woocommerce-checkout-block/))

**Conclusion:** the Additional Fields API is the "supported, low-effort" route, but it structurally
cannot satisfy the "billing only" requirement. To meet the requirement as stated, this needs a
**custom Checkout block integration** (a real, if small, block-development effort), not just a
PHP field registration call.

## 3. Recommended architecture

A small **Blocks integration** (`Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface`)
that registers one custom inner block, restricted to the Billing Address section, containing:

1. The receipt/invoice choice (rendered as a two-option toggle — see §6.1 on the radio-vs-select
   question).
2. The NIP text input (shown only when "VAT invoice" is selected).
3. JS-side logic that also toggles the **core** `billing_company` field's visibility/required
   state and the core `billing_first_name`/`billing_last_name` required state, mirroring what
   `checkout.js` + `Company::add_invoice_field()` do today in classic checkout.

Data is submitted to the server via the **Store API extension data** mechanism
(`woocommerce_store_api_register_endpoint_data()` + `checkoutExtensionData.setExtensionData()`),
validated and copied onto the order via `woocommerce_store_api_checkout_update_order_from_request`,
writing to the **same** `_fv_vat` / `_nip` order-meta keys and `billing_nip` user-meta key the
classic path already uses.

This is additive: nothing about `Company`'s existing classic-checkout hooks changes, so sites
still on the shortcode checkout keep working exactly as today. The two code paths are independent
and only share the NIP-checksum logic (see §5.4).

### Why not the Additional Fields API (rejected as primary approach)

Documented and confirmed above: it cannot avoid duplicating the field into the Shipping address
form, which directly violates the stated requirement. It's flagged in §7 as a fallback only if,
after seeing the custom-block effort estimate, the duplication is judged acceptable.

## 4. New files / structure

```
src/
  Blocks/
    CheckoutIntegration.php     # IntegrationInterface: registers scripts/styles, wires Store API data
  Nip.php                        # extracted, checkout-type-agnostic NIP checksum validator (see 5.4)
sources/javascript/entries/
  checkout-blocks.js             # new bundle: registers the inner block + validation wiring
dist/
  netivo-woocommerce-checkout-blocks.js   # built output (checked into git, same convention as today)
```

`Company.php` stays as-is for the classic checkout; `WooCommerce.php` gains one line to
conditionally boot `Blocks\CheckoutIntegration` (see §5.1).

## 5. Step-by-step

### 5.1 Feature-detect and bootstrap
In `WooCommerce::__construct()`, alongside `new Company();`, add:
```php
if ( class_exists( '\Automattic\WooCommerce\Blocks\Package' ) ) {
    add_action( 'woocommerce_blocks_checkout_block_registration', function ( $registry ) {
        $registry->register( new Blocks\CheckoutIntegration() );
    } );
}
```
This makes the whole feature a no-op on sites without WooCommerce Blocks active, with no config
flag needed — consistent with how `Company` is always-on today.

### 5.2 `Blocks\CheckoutIntegration` (IntegrationInterface)
Responsibilities:
- `get_name()` → a stable handle, e.g. `netivo-woocommerce-invoice`.
- `initialize()` → register the editor (`build/index.js`) and frontend
  (`dist/netivo-woocommerce-checkout-blocks.js`) scripts as **block editor / frontend script
  handles** (not via `wp_enqueue_scripts`, since Blocks needs the handles registered through
  `get_script_handles()` / `get_editor_script_handles()` so they load inside the block's own
  asset pipeline with the correct `wp-element` / `@woocommerce/blocks-checkout` dependencies).
- Register the Store API extension schema via `woocommerce_store_api_register_endpoint_data()`:
  exposes `fv_vat` (bool) and `nip` (string|null) on the Checkout endpoint under our own
  namespace, e.g. `netivo-woocommerce`.
- Hook `woocommerce_store_api_checkout_update_order_from_request` to:
  1. Read `$request['extensions']['netivo-woocommerce']`.
  2. Re-run the **same** validation as classic (§5.4) — required fields, NIP checksum — and throw
     a `Automattic\WooCommerce\StoreApi\Exceptions\RouteException` with a translated message on
     failure, so the block checkout surfaces the same errors `wc_add_notice()` gives today.
  3. On success, write `_fv_vat` / `_nip` order meta and `billing_nip` user meta exactly like
     `Company::save_nip_field()` does.

This hook is the block-checkout equivalent of the classic
`woocommerce_checkout_process` + `woocommerce_checkout_create_order` pair, so it's the natural
place to reuse the validation/persistence rules — **server-side enforcement doesn't depend on the
JS having worked correctly**, same principle as classic checkout.

### 5.3 The inner block (JS)
`block.json`:
```json
{
  "name": "netivo/invoice-fields",
  "parent": ["woocommerce/checkout-billing-address-block"],
  "category": "woocommerce",
  "attributes": { "lock": { "type": "object", "default": { "remove": true, "move": true } } },
  "editorScript": "file:./build/index.js"
}
```
Because a custom inner block does **not** auto-insert, whoever maintains the checkout page's block
template (the consuming theme, e.g. `woocommerce-theme`, which is already a block/FSE theme) needs
to add it once inside the Billing Address block via the Site Editor. This is a one-time manual
step per theme, not per request — flagged in §7 as an operational cost.

Component behavior (mirrors `checkout.js` / `Company::add_invoice_field()`):
- Renders the invoice choice + (conditionally) the NIP input, using the Checkout block's
  `ValidatedTextInput` / form components for consistent styling.
- On change, calls `setExtensionData( 'netivo-woocommerce', 'fv_vat', value )` /
  `...'nip', value )` (via `checkoutExtensionData` from `@woocommerce/blocks-checkout`) so the
  value travels to the server in the Store API request.
- Also dispatches to the `wc/store/validation` data store to mark `billing_company`,
  `billing_first_name`, `billing_last_name` as required/hidden/valid in reaction to the invoice
  choice, replicating `.js-fvat-to-show` / `.js-fvat-to-hide` from `checkout.js` and the
  `netivo/woocommerce/fv/hide-name` / `fv/name-required` filter outcomes (those two filters need a
  JS-readable counterpart — see §6.2).

### 5.4 Shared NIP validation
Extract the checksum algorithm out of `Company::validate_nip()` into a small standalone utility so
both checkout paths call the exact same logic instead of maintaining two copies:
```php
namespace Netivo\WooCommerce;

final class Nip {
    public static function is_valid( string $nip ): bool { /* moved verbatim from Company::validate_nip() */ }
}
```
`Company::validate_nip()` becomes a one-line delegate to `Nip::is_valid()` (keeps the public method
for backwards compatibility with any theme code calling it), and `Blocks\CheckoutIntegration` calls
`Nip::is_valid()` directly. This also happens to address refactor-review item #8/#9 from the
earlier code review.

### 5.5 Build tooling
- Add a new webpack entry (`checkout-blocks.js`) to whatever `@netivo/scripts` config drives
  `npm run build` today. **Needs verification**: confirm `@netivo/scripts` can externalize
  `@wordpress/element`, `@wordpress/i18n`, and `@woocommerce/blocks-checkout` the way
  `@wordpress/scripts`' `wp-dependency-extraction-webpack-plugin` does — if it can't yet, that's a
  prerequisite change to the shared `@netivo/scripts` package before this feature can build
  correctly (a script that bundles React itself instead of relying on WP's shared `wp-element`
  will bloat the bundle and risk two copies of React on the page).
- `../package.json` gains `@woocommerce/blocks-checkout` (and possibly `@woocommerce/blocks-registry`)
  as a dev dependency for TypeScript/JS types and the `checkoutExtensionData` import.

## 6. Open questions (need a decision before implementation)

### 6.1 Radio vs. two-option control
The Additional Fields API has no `radio` type, and a hand-built radio pair is easy enough in a
custom block (we're writing the component ourselves either way, so this isn't actually blocked by
the platform — just flagging that if we ever fall back to the Additional Fields API for this field,
it would have to become a `select` or `checkbox` instead of a true radio pair).
**Recommendation:** keep the exact current two-radio-button UI in the custom block; no compromise
needed here given we're building a real component.

### 6.2 Making `netivo/woocommerce/fv/hide-name` / `fv/name-required` usable client-side
These are PHP filters (`apply_filters()`), evaluated per request. The JS component needs their
resolved boolean values to decide how to render/require the name fields. Plan: `Blocks\CheckoutIntegration::initialize()`
resolves both filters once server-side and passes them to JS via
`wp_add_inline_script()`/`wp_localize_script()`-equivalent (Blocks' script registration supports
passing initial config the same way), so the JS never needs to re-implement filter logic — it just
reads two booleans that PHP already computed. Consuming themes that override these filters keep
working without any JS-side change.

### 6.3 Manual block insertion requirement
Since the inner block must be manually placed in the Site Editor once per theme, decide whether
that's acceptable (it's a one-time step, similar to how the theme already has to include
`woocommerce/checkout` in its templates at all) or whether we additionally want a
`woocommerce_blocks_checkout_block_registration`-time check that warns in the admin (e.g. an admin
notice) if the block isn't present in the Checkout page's content, to prevent silently shipping a
theme where invoicing is broken on the block checkout.

## 7. Fallback option (not recommended, documented for completeness)

If the custom-block effort is judged too large for now: use the Additional Fields API with
`location: 'address'` for NIP + a `select` for the invoice choice, accept that both fields will
appear in Shipping too, and hide the Shipping copies with a small CSS rule
(`.wc-block-checkout__shipping-fields [data-block-name="netivo/..."] { display: none }`) as an
interim visual patch. **This does not satisfy the "billing only" requirement at the data level**
(a shipping-address value would still be technically collected/stored per the API's billing+shipping
duplication design) and is only worth doing as a stop-gap if the full block is deferred.

## 8. Testing checklist

- [ ] Receipt path: only name/surname required; company/NIP absent; order meta has no `_nip`.
- [ ] Invoice path: company + NIP required; invalid NIP checksum blocks order placement with a
      translated error, matching the classic message.
- [ ] Field does not appear anywhere in the Shipping Address section, including when "Ship to a
      different address" is toggled on.
- [ ] `netivo/woocommerce/fv/hide-name` / `fv/name-required` overrides from a consuming theme are
      respected identically on both checkouts.
- [ ] Resulting order: `_fv_vat`, `_nip` order meta and `billing_nip` user meta match what classic
      checkout would have produced for the same input, and `Company::add_nip_to_order_address()` /
      `add_nip_to_customer_address()` render correctly regardless of which checkout placed the order.
- [ ] Classic checkout (shortcode) continues to work unchanged on a page still using it.
- [ ] Confirm My Account → address book NIP field/validation (`add_nip_field_to_address`,
      `validate_customer_nip`) is unaffected, since that form is out of scope for this change.

## 9. Effort estimate

- `Nip` extraction + `Company::validate_nip()` delegate: trivial, ~30 min.
- `Blocks\CheckoutIntegration` (Store API schema, order-meta write, validation): moderate,
  needs a working local WooCommerce Blocks checkout to test against.
- JS inner block + `wc/store/validation` wiring for core fields: the bulk of the effort — this is
  genuine block-development work (new build entry, React component, data-store interaction) and
  should be scoped as its own task once this plan is approved.
- Build-tooling verification for `@netivo/scripts` externals: unknown until checked; possible
  blocker worth investigating first, before writing the component.

## Sources

- [Additional checkout fields — WooCommerce Developer docs](https://developer.woocommerce.com/docs/block-development/extensible-blocks/cart-and-checkout-blocks/additional-checkout-fields/)
- [Block checkout additional checkout fields appear in both billing and shipping — woocommerce/woocommerce#64099](https://github.com/woocommerce/woocommerce/issues/64099)
- [How to make your WooCommerce additional checkout fields conditionally visible in the Checkout Block — codebytom.blog](https://codebytom.blog/2025/05/how-to-make-your-woocommerce-additional-checkout-fields-conditionally-visible/)
- [How to Customize WooCommerce Checkout Fields (2026) — litextension.com](https://litextension.com/blog/woocommerce-checkout-fields/)
- [How to Add Custom Inner Blocks to WooCommerce Checkout Block — Tyche Softwares](https://www.tychesoftwares.com/how-to-add-custom-innner-blocks-to-woocommerce-checkout-block/)
