# Refactor & Code Quality Review — `netivo/woocommerce`

Date: 2026-09-30
Scope: `../src` (`Company.php`, `WooCommerce.php`, `Admin/Product/Tab.php`, `Product/Type.php`)

This is a review only — no code was changed. PHPCS/WordPress-VIP findings (nonces, escaping,
sanitization) were already fixed separately; everything below is about design, duplication, and
maintainability, not lint errors.

---

## `../src/Company.php`

### 1. `apply_filters()` called redundantly for the same value (Medium)
`add_invoice_field()` calls `apply_filters( 'netivo/woocommerce/fv/hide-name', true )` twice
(lines 178 and 185) and `apply_filters( 'netivo/woocommerce/fv/name-required', false )` twice
(lines 183 and 190) within the same method call — same filter, same args, evaluated twice per
request. `validate_nip_field()` calls `hide-name` a third time independently. If a theme's filter
callback is non-trivial (e.g. reads an option, checks a condition), this repeats work
unnecessarily, and if the callback isn't pure, the two reads could theoretically disagree.

**Suggestion:** resolve each filter once into a local variable and reuse it:
```php
public function add_invoice_field( $fields ): array {
    $hide_name     = apply_filters( 'netivo/woocommerce/fv/hide-name', true );
    $name_required = apply_filters( 'netivo/woocommerce/fv/name-required', false );

    $fields['billing']['billing_first_name']['class']    = $hide_name
        ? [ 'form-row form-row-first js-fvat-to-hide' ]
        : [ 'form-row form-row-first' ];
    $fields['billing']['billing_first_name']['required'] = $name_required;
    // ...
}
```

### 2. Duplicated `$_POST` sanitization pattern across three methods (Medium)
`validate_nip_field()`, `save_nip_field()`, and `validate_customer_nip()` each repeat:
```php
isset( $_POST['x'] ) ? sanitize_text_field( wp_unslash( $_POST['x'] ) ) : ''
```
five times combined. A small private helper would remove the duplication and centralize the
sanitization rule:
```php
private function sanitized_post( string $key ): string {
    return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
}
```

### 3. NIP/invoice data is read and sanitized twice per checkout (Low/Medium)
`validate_nip_field()` (hooked to `woocommerce_checkout_process`) and `save_nip_field()` (hooked
to `woocommerce_checkout_create_order`) both independently read and sanitize
`$_POST['fv_vat']` / `$_POST['billing_nip']`. WooCommerce guarantees `checkout_process` runs
before `checkout_create_order` in the same request, so the sanitized values could be computed
once and cached on the instance (e.g. a `protected ?array $checkout_data = null;` populated in
`validate_nip_field()` and read in `save_nip_field()`), avoiding the duplicate parsing and
keeping the two methods in sync if the sanitization rule ever changes.

### 4. Repeated magic strings for meta/option keys and filter names (Low)
`'_fv_vat'`, `'_nip'`, `'billing_nip'`, `'netivo/woocommerce/fv/hide-name'`, and
`'netivo/woocommerce/fv/name-required'` are each hand-typed in multiple places. A typo in any one
occurrence (e.g. order meta vs. user meta key) would silently break persistence without any
static check catching it. Extracting them as class constants would remove that risk and give
IDEs autocomplete:
```php
private const META_FV_VAT   = '_fv_vat';
private const META_NIP      = '_nip';
private const USER_META_NIP = 'billing_nip';
private const FILTER_HIDE_NAME     = 'netivo/woocommerce/fv/hide-name';
private const FILTER_NAME_REQUIRED = 'netivo/woocommerce/fv/name-required';
```

### 5. Typo in the enqueued script handle (Low, cosmetic but worth fixing)
`add_javascript_to_page()` registers the handle `'nt-wooocommerce-checkout'` (three `o`s in
"wooocommerce"). It works today because nothing else references the handle by name, but it will
confuse anyone who later tries to `wp_dequeue_script()`/`wp_script_is()` it, or filter the tag by
handle from the consuming theme. Worth a one-line rename to `'nt-woocommerce-checkout'`.

### 6. `realpath()` result passed to `file_exists()` without a falsy check (Low)
```php
$file = realpath( __DIR__ . '/../dist/netivo-woocommerce-checkout.js' );
if ( is_checkout() && file_exists( $file ) ) {
```
`realpath()` returns `false` when the path doesn't exist, which is then passed straight to
`file_exists()`. It happens to work (PHP coerces `false` to `''`, and `file_exists('')` is
`false`), but it's relying on implicit type coercion rather than an explicit guard, and reads as
a bug at a glance. Prefer:
```php
if ( $file !== false && is_checkout() && file_exists( $file ) ) {
```

### 7. `wp_enqueue_script()` uses the WP 6.3+ array-args signature while the ruleset targets WP 6.0 (Low — consistency check)
`phpcs.xml.dist` sets `minimum_supported_wp_version` to `6.0`, but `add_javascript_to_page()`
already calls `wp_enqueue_script( ..., array( 'in_footer' => true, 'defer' => true ) )`, which is
only valid from WordPress 6.3 onward (`in_footer`/`strategy` as an array). Either bump the
`minimum_supported_wp_version` config to `6.3` to reflect the real floor, or note the
discrepancy if the codebase intentionally targets something else.

### 8. `validate_nip()` visibility (Low)
`Company` is a concrete (non-abstract) class with no evidence of subclassing; `validate_nip()`
is `protected` but only ever called from within the class itself. Could be `private` to make the
actual API surface clearer, unless subclassing is a deliberate extension point.

### 9. Minor modernization in `validate_nip()` (Low, style)
- `$weights = [ '6', '5', '7', ... ]` stores digits as strings that are immediately `intval()`'d — could just be an `int[]` literal (`[ 6, 5, 7, 2, 3, 4, 5, 6, 7 ]`).
- `str_split( $nip, 1 )` — the `1` is the default length and can be omitted: `str_split( $nip )`.
- The `array_map( function ( $x, $y ) { ... }, ... )` closure could be a short arrow function now
  that the package requires PHP ≥ 8.4: `array_map( fn( $x, $y ) => (int) $x * (int) $y, $digits, $weights )`.

---

## `../src/Admin/Product/Tab.php`

### 10. Unused globals in `display()` (Low — dead code)
```php
public function display(): void {
    global $post, $thepostid, $product_object;
    ...
```
None of `$post`, `$thepostid`, `$product_object` are read anywhere in the method body. They look
like leftovers from a copy-pasted WooCommerce admin template. Safe to remove unless a subclass's
included `.phtml` view relies on them being pulled into global scope as a side effect of calling
`global` here (worth double-checking against actual theme `.phtml` templates before removing,
since `include`d files do see the caller's local/global scope).

### 11. Attribute lookup compares against a hardcoded string instead of `::class` (Medium)
```php
foreach ( $data as $attribute ) {
    if ( $attribute->getName() === 'Netivo\Attributes\View' ) {
        $name = $attribute->getArguments()[0];
    }
}
```
- Comparing against the literal string `'Netivo\Attributes\View'` means a rename of that
  attribute class won't be caught by static analysis or IDE refactoring tools. Prefer
  `\Netivo\Attributes\View::class` (if that class is available to this package/reachable at
  runtime) — or better, use PHP's built-in filtering: `$obj->getAttributes( \Netivo\Attributes\View::class )`, which returns only matching attributes directly.
- The loop doesn't `break` after finding a match; if a subclass ever had multiple `View`-like
  attributes (unlikely, but not prevented by the type system), the *last* one silently wins.

**Suggestion:**
```php
$views = $obj->getAttributes( \Netivo\Attributes\View::class );
$name  = ! empty( $views ) ? $views[0]->getArguments()[0] : null;

if ( empty( $name ) ) {
    $name = strtolower( basename( str_replace( '.php', '', $obj->getFileName() ) ) );
}
```

### 12. `empty( $name )` fallback also triggers on a falsy-but-explicit attribute argument (Low — edge case)
If a subclass declares `#[View('')]` (empty string) or `#[View('0')]`, `empty( $name )` is `true`
and the code silently falls through to deriving the name from the filename instead of respecting
the explicit (if unusual) attribute value. Using `$name === null` (as in the suggestion above)
instead of `empty()` avoids this edge case and is also cleaner since `$name` is otherwise
undefined before the loop, which currently relies on PHP's implicit "undefined variable is
falsy" behavior rather than an explicit `null` initialization.

---

## `../src/WooCommerce.php`

### 13. Constructor parameter has no type hint (Low)
```php
protected ?Theme $main_class = null;
...
public function __construct( $main_class ) {
```
The property is typed `?Theme`, but the constructor parameter isn't type-hinted at all. Adding
`Theme $main_class` would let PHP and static analysis tools catch misuse at the call site instead
of only via the docblock.

### 14. `get_configuration()` called twice for the same lookup (Low)
```php
if ( ! empty( $this->main_class->get_configuration()['modules']['woocommerce'] ) ) {
    $this->modules = $this->main_class->get_configuration()['modules']['woocommerce'];
}
```
Calls `get_configuration()` (and re-indexes into `['modules']['woocommerce']`) twice. If that
method does any nontrivial work (parses a file, merges arrays, etc.) this doubles the cost for no
reason:
```php
$config = $this->main_class->get_configuration();
if ( ! empty( $config['modules']['woocommerce'] ) ) {
    $this->modules = $config['modules']['woocommerce'];
}
```

### 15. `init_product_tabs()` / `init_product_types()` share an identical shape (Low — optional DRY)
Both methods are: "if the config key is non-empty, loop over it, `class_exists()`-guard, then act
on the class." Given there are only two of these and their bodies differ (`new $tab(...)` vs.
`$type::register()`), extracting a generic helper is optional and could even reduce readability
for two call sites — flagged for awareness, not a strong recommendation.

---

## `../src/Product/Type.php`

### 16. Redundant constructor override (Low — dead code)
```php
public function __construct( $product ) {
    parent::__construct( $product );
}
```
This constructor does nothing beyond what PHP already does if it were omitted entirely (a class
without its own constructor inherits the parent's). Unless it's intentionally kept as a
documented extension point for subclasses to call `parent::__construct()` explicitly, it can be
deleted along with its docblock, and `\WC_Product_Simple::__construct()` would be used directly.

---

## Summary / suggested priority

| # | File | Issue | Priority |
|---|------|-------|----------|
| 1 | Company.php | Duplicate `apply_filters()` calls for same value | Medium |
| 11 | Tab.php | String comparison instead of `::class` for attribute name | Medium |
| 2 | Company.php | Duplicated `$_POST` sanitization pattern | Medium |
| 3 | Company.php | NIP data sanitized twice per checkout request | Low/Medium |
| 10 | Tab.php | Unused globals in `display()` | Low |
| 16 | Product/Type.php | Redundant no-op constructor | Low |
| 4 | Company.php | Magic strings for meta keys/filter names | Low |
| 5 | Company.php | Typo in script handle (`wooocommerce`) | Low |
| 6 | Company.php | `realpath()` result passed to `file_exists()` unchecked | Low |
| 7 | Company.php | `wp_enqueue_script` array-args needs WP 6.3, ruleset says 6.0 | Low |
| 12 | Tab.php | `empty()` fallback masks explicit falsy attribute value | Low |
| 13 | WooCommerce.php | Missing constructor type hint | Low |
| 14 | WooCommerce.php | `get_configuration()` called twice | Low |
| 8, 9 | Company.php | Visibility/style modernization in `validate_nip()` | Low |
| 15 | WooCommerce.php | Optional DRY between the two `init_*` loops | Optional |

None of these are functional bugs in current usage (the codebase behaves correctly today); they're
maintainability, duplication, and robustness improvements. Happy to implement any subset of these
on request.
