# Changelog — Next Day Shipping Eligibility

All notable changes to this module. Adheres to [Semantic Versioning](https://semver.org/).

---

## [1.9.0] — Reservation-aware stock check (MSI salable quantity)

The checkout stock check is now reservation-aware. Previously eligibility and
the Click & Collect / backorder rules read the **legacy** stock quantity
(`cataloginventory_stock_item.qty`), which does **not** subtract MSI
reservations — so next-day and pickup could be offered for units that were
physically present but already reserved by other unshipped orders (or when a
line asked for more than was actually available). The order would go through
promising a delivery speed the shelf couldn't meet.

### Added

- **`Model/SalableStockChecker`** — compares each cart line's requested quantity
  against the MSI **salable** quantity (`GetProductSalableQtyInterface` = stock −
  reservations − out-of-stock threshold). When any line can't be fully satisfied
  from the shelf, the shipping-restriction plugin pulls **both** the Express /
  Next-Day methods and the Click & Collect methods, so checkout falls back to a
  standard/tracked option (the short units ship when the backorder arrives).
- **New setting** *General → "Restrict when salable stock is insufficient"*
  (`general/restrict_insufficient_salable`, **default Yes**). Turn it off to keep
  the previous behaviour.
- The Luma (`ConfigProvider`) and Hyvä (`HyvaCheckoutNotice`) checkout notices
  fire on the same condition, so the banner stays in sync with the restriction.
- The Hyvä checkout notice now ships **inline critical CSS** (zero-specificity
  `:where()` rules + a semantic `etechflow-nextday-notice--*` modifier class) so
  it stays correctly coloured even when the store's compiled Tailwind build does
  not include this module's utility classes. A present Tailwind class always
  wins, so existing themes are visually unchanged.

### Requirements

- Adds a dependency on `magento/module-inventory-sales-api` (MSI), which ships
  with Adobe Commerce and Magento Open Source 2.4.x by default. If a SKU isn't
  assigned to a stock (or MSI is disabled at runtime), the line is treated as
  satisfiable and checkout is never blocked.

---

## [1.8.1] — 2026-07-03 — Security: portal-only licensing (removes forgeable key path)

Closes a licensing bypass. Previous versions shipped the HMAC signing secret
inside `LicenseValidator` and validated a locally-computed key against it, so
anyone with the module source could compute a valid key for their own domain
and run the module unlicensed. A second bypass let the client-settable
"locally issued" 48-hour grace (`issued_key`/`issued_domain`/
`stripe_session_id`/`issued_at`) activate the module whenever the portal was
unreachable — all fields the customer controls.

### Changed (security)

- **Validation is now portal-only.** `isValid()` honours a key only when the
  ETechFlow portal confirms it. The module ships no signing secret.
- **Removed** `computeKey()`, `computeBundleKey()`, the `SECRET_FRAGMENTS` /
  `BUNDLE_SECRET_FRAGMENTS` constants, and the legacy HMAC/bundle validation
  branches.
- **Offline grace is now un-forgeable.** It derives solely from a cached,
  genuine portal "valid" response (refreshed on every success, 48 h TTL), never
  from admin config. An explicit portal reject clears it immediately.
- Bundle subscriptions continue to work via a portal-issued `SP-` bundle key.

> Upgrade note: existing HMAC (`v1.7.x`) licences are no longer accepted —
> re-activate via the portal to obtain an `SP-` key. Dev-host detection remains
> for the admin status banner only and grants no bypass.

---

## [1.8.0] — 2026-06-03 — Stripe portal licensing + admin gate page

Adds Stripe Checkout subscription flow gated by the eTechFlow licensing
portal. Customers can now subscribe directly from inside Magento Admin
without leaving the panel — plan selection, card entry, and SP-XXXX key
activation happen in the same embedded frame. HMAC + bundle key
validation is retained as a fallback so existing v1.7.x licences
continue to work without re-activation.

### Added

- **Admin subscription gate** under *Stores → Configuration → ETECHFLOW →
  Next Day Eligibility* — when no licence is detected on a production
  environment, the configuration page is replaced with three plan cards
  (Starter $19/mo, Professional $49/mo, Enterprise $99/mo) and an
  embedded customer-details form.
- **`Controller/Adminhtml/License/Checkout.php`** — builds a Stripe
  Checkout session against the selected plan and redirects the browser
  to Stripe's hosted payment page.
- **`Controller/Adminhtml/License/Activated.php`** — Stripe success-URL
  endpoint. Calls the portal's `/license/activate` route with the
  session ID, receives an SP-XXXX licence in the response, and writes
  it to `core_config_data`.
- **`Controller/Adminhtml/License/Gate.php`** — admin route that renders
  the gate page (redirects to config when licensed).
- **`Block/Adminhtml/License/{Gate,Activated}.php`** — view-model helpers
  for the gate and activated templates.
- **`view/adminhtml/templates/license/{gate,activated}.phtml`** — dark
  navy gate page with 3 plan cards + Stripe checkout form, and the
  License Activated success page with the SP-XXXX key + Copy button.
- **`etc/adminhtml/routes.xml`** — new admin route
  `etechflow_nextdayeligibility`.
- **`etc/adminhtml/menu.xml`** — *Stores → Settings → Next Day
  Eligibility* menu item that opens the gate page.
- **`etc/adminhtml/di.xml`** — registers the missing
  `EligibilityPanel` UI form modifier (was shipped without DI wiring
  in v1.7.0 - v1.7.1, so the "Why eligible?" panel never rendered).
- **License section expansion** in `etc/adminhtml/system.xml` — adds
  `portal_url` and `portal_api_url` fields.
- **Payment (Stripe) section** in `etc/adminhtml/system.xml` —
  `stripe_secret_key` (obscure + Encrypted backend model),
  `stripe_publishable_key`, `stripe_currency`.
- **Tri-state portal validation** in `Model/LicenseValidator.php` — the
  validator's portal call returns `?bool`: `true` (valid), `false`
  (explicit reject, HTTP 200+valid:false / 401 / 403), or `null`
  (unreachable). Only `null` falls back to the 48h local grace, so
  admin IP-removal or subscription suspension locks the storefront
  within 60 seconds rather than waiting for the grace window.
- **Split cache TTLs** — `CACHE_TTL_VALID = 60` and
  `CACHE_TTL_REJECT = 60` so re-authorisation propagates within 1 minute
  in both directions.

### Changed

- `Model/LicenseValidator.php` constructor goes from 2 arguments to 4
  (`ScopeConfigInterface`, `StoreManagerInterface`, `CacheInterface`,
  `Curl`). Callers using `ObjectManager::get()` are unaffected. Direct
  instantiation in tests or DI overrides needs updating.
- `Model/BackorderManager.php::syncBackordersWithDropShip()` now also
  sets `is_in_stock = true` on the stock item when Drop-Ship Eligible
  is turned on. Without this, Magento's auto-OOS flip (qty=0 +
  backorders=No at save time) left drop-ship products silently
  unsalable despite backorders=Allow being set later by the observer.
- Removed the hyphen-suffix dev-host bypass (e.g. `*-dev.com`,
  `*-staging.com`). It false-matched legitimate production hosts like
  `magento-dev.etechflow.com` and gave them a free unlimited bypass.
- Added `.ngrok-free.dev` to the recognised tunnel suffixes.

### Test changes

- `Test/Unit/Model/LicenseValidatorTest.php` constructor calls updated
  to 4-arg form with mocked `CacheInterface` and `Curl`.
- Removed 3 dev-host bypass entries that tested the now-removed
  hyphen-suffix behaviour. Added one entry for `.ngrok-free.dev`.

### Upgrade notes

- `bin/magento setup:upgrade` may need a generated/code wipe before it
  runs cleanly: `rm -rf generated/code/* generated/metadata/* var/cache/*`
  — the validator's constructor arity changed from 2 to 4 and stale
  metadata causes `ArgumentCountError`.
- Set your Stripe keys after upgrade: *Stores → Configuration →
  ETECHFLOW → Next Day Eligibility → Payment*. Secret key is stored
  encrypted via Magento's Encrypted backend model.
- Existing v1.7.x HMAC licences are NOT affected — `isValid()` only
  enters the portal branch for SP-prefixed keys.

### Deferred (queued for v1.8.1)

- `Service/EligibilityExplainer.php` — does not currently check
  `force_standard_shipping_only`. The Why-box reports a product as
  eligible when the override is active (the stored attribute is
  correctly 0; only the explainer's verdict text is misleading). Fix
  is to add a force_standard branch at the top of the explainer.
- `Model/IneligibilityChecker::hasItemsWithoutLocalStock()` — uses
  `joinField` + `getSize()` which drops the JOIN clause in COUNT
  queries, throwing `SQLSTATE[42S22]: Column not found: 'qty'`. The
  exception is caught silently by the plugin so it doesn't break
  checkout, but Click & Collect filtering does not work. Fix is to
  rewrite the method to use a raw `ResourceConnection` query.

---

## [1.7.1] — 2026-05-25 — Hardening after the v1.7.0 deploy incident

v1.7.0 shipped with no new data patches. On the Keystation production
deploy, `setup:upgrade` hit a `FilesystemIterator` warning during a
post-patch phase. Because no patches ran, the run aborted before
`setup_module.data_version` could be advanced. The DbStatusValidator
then saw `module.xml = 1.7.0` vs `DB = 1.6.5`, treated every request as
an upgrade-required state, and returned 500. Site down for ~20 minutes
until rolled back to 1.6.5.

Three defensive changes, ranked by impact:

### Added

- **`Setup/Patch/Data/V171ReleaseMarker.php`** — no-op release marker
  patch. Establishes the discipline that every NDE release ships at
  least one data patch, even if it has nothing to do. Future releases
  rename/copy this template (`V172ReleaseMarker`, etc). Existence of a
  `patch_list` row makes the patch phase always run something —
  surfaces FS / permissions / DI errors earlier and more visibly.
- **Active-but-no-name warning in the live "Why?" panel.** When a
  supplier slot has its active flag ticked but the name attribute is
  null/empty, the explainer now adds a yellow "Data inconsistency"
  note to the merchant's product edit page panel — e.g. "slot 3 ticked
  active but no supplier name is set." No save blocking, no API
  surprises — just visible feedback in the place merchants already look.

### Changed

- **`Model/Config/Backend/QualifyingSuppliers.php`** — new backend
  model wired to the Qualifying Supplier Names textarea. Trims
  whitespace, drops blank/comment lines on save. Does NOT fuzzy-match
  against EAV options (that's lossy and confusing); the resolver's
  existing case-insensitive trim-on-read still handles "Onlyda" vs
  "OnlyDa" the same as before.

### Not changed (deliberately)

- **No schema changes, no API changes** — drop-in upgrade from 1.7.0
- No reject-on-save validation added (would break bulk-import paths
  and the SupplierAutoflow plugin's flag-flipping flow). Soft warnings
  in the Why? panel are the chosen UX.
- No fuzzy-matching of qualifying supplier names against EAV options
  (would silently mutate merchant input).

### Files added

```
Setup/Patch/Data/V171ReleaseMarker.php
Model/Config/Backend/QualifyingSuppliers.php
```

### Files modified

```
Service/EligibilityExplainer.php   (orphaned-slot warning)
etc/adminhtml/system.xml           (qualifying_suppliers backend_model)
etc/module.xml                     (1.7.0 → 1.7.1)
composer.json                      (1.7.0 → 1.7.1)
```

### Upgrade — and the new procedure

```bash
composer require etechflow/module-next-day-eligibility:^1.7.1
bin/magento setup:upgrade
# Watch this output. If you see ANY warnings here, stop and investigate
# before flushing cache — DbStatusValidator will block requests if the
# upgrade didn't reach data_version bump.
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
```

**Pre-flight check after upgrade:**
```sql
SELECT module, schema_version, data_version FROM setup_module
WHERE module='ETechFlow_NextDayEligibility';
```
If `data_version < module.xml setup_version`, the upgrade did NOT
complete. Re-run `setup:upgrade` — do NOT flush cache yet, or you'll
trigger the same 500 the v1.7.0 incident produced.

---

## [1.7.0] — 2026-05-24 — Plain-English supplier mode + live "Why?" panel

The supplier mode worked but was hard to reason about. Merchants had to mentally trace through three settings (Supplier Pairs, Qualifying Suppliers, Match Mode) plus per-product flags to figure out why a given product was eligible or not. This release keeps every capability and makes them readable.

### Added

- **Live "Next Day Eligibility — Why?" panel on the product edit page.** A new collapsible fieldset injected by `Ui/DataProvider/Product/Form/Modifier/EligibilityPanel.php`. For every product, shows:
  - Big green ✅ or red ❌ status banner with one-line verdict
  - Bullet list of every factor that fed into the verdict (stock state, manual flag, drop-ship source, each supplier slot's status, match mode)
  - When ineligible: a "What to do" hint with concrete fix options
  - Saves merchants from having to mentally trace config + product attributes to figure out why eligibility is what it is
- **New `Service/EligibilityExplainer.php`** — pure service that takes a Product and returns a structured explanation (`{eligible, headline, reasons[], notes[]}`). Reusable from anywhere that needs to explain eligibility. Powers the panel; consumable from CLI/REST in future.

### Changed — tooltip rewrites (plain English, no jargon)

Every tooltip in the Drop-Ship Exception group rewritten so a merchant with no developer background can understand it on first read:

- **Drop-Ship Source** — was "Where the module reads drop-ship status from"; now "How the module decides if a product is drop-ship. Pick 'Manual tickbox' if your store is simple. Pick 'Supplier-based' if products have multiple suppliers."
- **Supplier Attribute Pairs** — was "Format: active_attr_code:name_attr_code"; now "Tell the module which product attributes hold supplier info. One line per supplier slot. Format: tickbox-attribute:name-attribute" + concrete worked example.
- **Qualifying Supplier Names** — was "Supplier names that count as same-day"; now "List the supplier names that can ship next-day. One per line. Capitalisation doesn't matter."
- **Supplier Match Mode** — was "How the resolver walks the supplier slots... models real fulfillment"; now "Choose how to read multiple suppliers on a product. 'First active wins' matches real fulfillment. 'Any active qualifying' is more generous for marketing." + plain-English explanation of when to pick which.
- **Badge Visibility** — removed "PDP" jargon.

### Not changed (deliberately)

- **No settings removed.** Every existing config field still works the same way. The fix is communication, not capability.
- **No DB schema changes, no migration patches.** Drop-in compatible with 1.6.x installs.
- **No breaking API changes.** `EligibilityEvaluator`, `SupplierDropShipResolver`, `Config` all unchanged.

### Files added

```
Service/EligibilityExplainer.php
Ui/DataProvider/Product/Form/Modifier/EligibilityPanel.php
view/adminhtml/ui_component/product_form.xml
```

### Files modified

```
etc/adminhtml/system.xml         (tooltip rewrites)
etc/module.xml                   (1.6.5 → 1.7.0)
composer.json                    (1.6.5 → 1.7.0)
```

### Upgrade

```bash
composer require etechflow/module-next-day-eligibility:^1.7.0
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

Verified end-to-end on local Magento 2.4.8 + PHP 8.4 Docker. Explainer service tested against product with both eligible and ineligible supplier configurations; output is human-readable in both cases.

---

## [1.5.1] — 2026-05-19

### Click & Collect / In-Store Pickup filter

For merchants with physical shops. Hides Click & Collect (pickup) shipping methods from any cart whose items lack local stock — independently of the next-day rules.

The Keystation rule that drove it:

| Local stock | Supplier match | Next day? | C&C? |
|---|---|---|---|
| Yes | (any) | ✓ | ✓ |
| No | Yes (Auto remote man) | ✓ (via supplier) | ✗ (no stock to pick up) |
| No | No | ✗ | ✗ |

Supplier-based drop-ship can keep next-day shipping alive when local stock is zero (the v1.5.0 supplier mode). But pickup is physical — you can't collect what isn't on the shelf. This release adds the matching filter.

#### Added

- **New admin field**: *Click & Collect / In-Store Pickup Methods* (multi-select) under General Settings. Tick which method codes the module should treat as pickup. Companion *Custom Click & Collect Method Codes* free-text input for codes that don't appear in the dropdown (same merge semantics as the existing Express + Standard custom fields).
- **New service method** `IneligibilityChecker::hasItemsWithoutLocalStock(items): bool` — single batched query joining `cataloginventory_stock_item` for qty + is_in_stock. Treats `qty <= 0`, `is_in_stock = 0`, or missing stock row as out-of-local-stock.
- **`ShippingRestriction` plugin** now layers the C&C filter on top of the existing next-day filter, in both blacklist and whitelist modes. Pickup methods are stripped whenever any cart item lacks local stock — regardless of whether a supplier-based drop-ship rule would otherwise have made the product next-day-eligible.

#### Performance

- The local-stock check is only run when the C&C method list is **non-empty**. Merchants without physical shops pay zero extra DB cost — the filter is short-circuited before any query fires.
- When run, the check is a single batched collection query — same cost shape as the existing `hasIneligibleItems` check. No N+1 regardless of cart size.

#### Backwards compatibility

- Default C&C method list = empty. Every existing install behaves exactly as before v1.5.1.
- No new product attributes. No schema changes. No new dependencies.

#### Migration tip

Stores that already use Click & Collect / In-Store Pickup methods (e.g. Magento's native In-Store Pickup or a third-party module): add their method codes to the new field. From the next stock save, the C&C filter automatically hides those methods on out-of-local-stock carts.

---

## [1.5.0] — 2026-05-19

### Supplier-based drop-ship detection

Some stores carry products that are split across multiple suppliers per SKU, where only specific suppliers ship same-day. Keeping the manual `Drop-Ship Eligible` flag in sync with which supplier currently fulfils each product was a UX trap — one stale value silently turned the next-day badge into a lie.

This release adds an opt-in detection mode that reads the suppliers directly. No store schema is hard-coded: merchants tell the module which product attributes hold supplier state and which supplier names count as same-day. Existing stores see no behaviour change — the default is still the manual flag.

#### Added

- **`Drop-Ship Source` admin dropdown** (under *Stores → Configuration → eTechFlow → Next Day Eligibility → Drop-Ship Exception*). Two options:
  - **Manual flag only (default)** — reads the per-product `drop_ship_eligible` attribute. Identical to pre-v1.5 behaviour.
  - **Supplier-based** — also checks supplier attributes configured below. The manual flag still works as an override.
- **`Supplier Attribute Pairs` config field** — multi-line text, one pair per line in the format `active_attr_code:name_attr_code`. Tells the module which product attributes hold each supplier slot's active flag + name. Example for a store with three supplier slots: `s1_active:s1`, `s2_active:s2`, `s3_active:s3`. Lines starting with `#` are ignored as comments.
- **`Qualifying Supplier Names` config field** — multi-line text, one supplier name per line. Names are the values your `<name>` attribute can hold (e.g. `Auto remote man`, `FastFreight Inc.`). Match against product data is case-insensitive and trims whitespace.
- **New service class `Model/SupplierDropShipResolver`** — the per-request resolver that walks the configured pairs and returns true if any pair has `active = 1` AND `name` is in the qualifying list. Schema-agnostic — works for any merchant's supplier attribute structure. Per-request memoisation keeps the cost low on cascaded saves. Silent failure modes (missing attribute, no config, non-string name value) so a merchant misconfiguration never crashes checkout.
- **New source model `Model/Source/DropShipSource`** — backs the dropdown.

#### Changed

- **`EligibilityEvaluator::isStockEligible` precedence updated** to: (1) `force_standard_shipping_only` wins; (2) manual `drop_ship_eligible` wins; (3) supplier match wins (only when supplier mode is on); (4) real stock check. The manual flag is **always** honoured regardless of source mode — admin-set Yes is an override.

#### Backwards compatibility

- Default Drop-Ship Source = `flag`. Every existing install behaves exactly as before v1.5.
- The `drop_ship_eligible` attribute is unchanged and continues to work as a per-product manual override even when supplier mode is on.
- No new product attributes are added by this module — supplier attributes are merchant-owned.

#### Migration tip

To enable supplier mode on Keystation's setup (three supplier slots S1/S2/S3, with "Auto remote man" being the same-day supplier):

1. Set **Drop-Ship Source** to *Supplier-based*.
2. Paste into **Supplier Attribute Pairs**:
   ```
   s1_active:s1
   s2_active:s2
   s3_active:s3
   ```
3. Paste into **Qualifying Supplier Names**: `Auto remote man`.
4. Save. The next time a stock save fires for any product (or on a manual reindex of the catalog), eligibility recomputes against the supplier attributes.

---

## [1.4.3] — 2026-05-16

### Unified shipping picker + Standard allow-list mode + plain-language tooltips

Three real merchant issues, fixed in one patch.

#### Added

- **Unified shipping-method source model** — all NDE dropdowns now show **every shipping option your store offers**, including custom-carrier-plugin methods that don't register through Magento's standard list (Hyvä Shipping Page, marketplace shippers, third-party rate engines). Paste a code into ANY of the `Custom * Method Codes` text inputs and it appears in every dropdown on the page under a "Custom / Additional Codes" group — pick which group it belongs to with the same UI as the built-in carriers. No more "I have 6 methods but only 3 show up".
- **Standard / Slow Shipping Methods allow-list (`standard_method_codes`)** — clearer alternative to the original Express block-list. Tick the slow / standard shipping options you want customers to see when their cart can't ship next day; everything else is hidden automatically. Useful when you have multiple "standard" speeds (e.g. Royal Mail Tracked 24, Tracked 48, Free Delivery) and want to enumerate the SHORT list of "things to keep" instead of the long list of "things to hide". Future-proof — adding a new carrier to your store later won't accidentally expose it on ineligible carts.
- **Companion `additional_standard_codes` text input** — same merge semantics as `additional_method_codes`. Codes pasted here count as part of the Standard allow list AND publish into every other dropdown.
- **New `Model/Source/AllShippingMethods`** — merges Magento's `Allmethods` source with custom codes harvested from all NDE additional-codes config paths, de-duped. Used as the source for all three multi-selects.

#### Changed

- **Plain-language tooltips and labels throughout the General Settings group.** All technical jargon stripped from merchant-facing copy:
  - "blacklist" / "whitelist" → "block list" / "allow list"
  - "Allmethods source" / "registers through" / "`collectRates()`" → "shipping options your store offers"
  - "`carrier_method`" → "shipping option code"
  - "opt-in" / "fallback" / "no-op" → plain English equivalents
  - Field labels rewritten to describe outcome, not internals: "Next Day Shipping Methods" → "Express / Next-Day Shipping Methods", "Additional Next Day Codes (advanced)" → "Custom Express Method Codes", "Standard Methods (whitelist)" → "Standard / Slow Shipping Methods (allow list)", etc.
  - Tooltips now answer "what does this do for my customers?" instead of "what does this do in the database".
- **Backorder Express Restriction group** — same plain-language rewrite applied.

#### Implementation

- `ShippingRestriction::afterGetGroupedAllShippingRates()` now branches: if `getStandardMethodCodes()` returns a non-empty list AND the cart has ineligible items, it uses the new `keepOnly()` helper (whitelist semantics) and layers the backorder rule on top. If the standard list is empty, it falls back to the original block-list path — full backward compatibility.
- Safety net unchanged: if any filtering would leave the cart with zero shipping options, the plugin returns the original rates and logs a warning. The whitelist branch has its own warning text (`"Standard Methods list does not match any of the carrier rates returned"`) so misconfigurations are easy to spot in `var/log/system.log`.

#### Verified

- **113 NDE unit tests pass** — 4 new whitelist-mode tests cover: keep-only-standard-on-ineligible-cart, bypass-when-cart-all-eligible, safety-net-when-whitelist-matches-nothing, layered-backorder-restriction-on-whitelisted-methods.
- `setup:di:compile` clean (new source model + new constructor injections resolve cleanly).
- PHPStan level 4 clean on `AllShippingMethods.php`, updated `ShippingRestriction.php`, and updated `Config.php`.

---

## [1.4.2] — 2026-05-16

### Shipping-method picker UX patch

Three real gaps surfaced by merchant testing of v1.4.1 — corrected.

#### Added

- **New CLI: `bin/magento etechflow:nde:list-methods`** — enumerates every shipping method code visible to NDE's Next Day Methods dropdown (sourced from Magento's `Allmethods` pipeline), printed as a table or CSV (`--format=csv`). Solves the "I have 6 methods in admin but only 3 show up in NDE's dropdown" puzzle: the CLI shows you exactly what's available and prints instructions for discovering codes of any custom carriers that register at runtime (Hyvä Shipping Page, marketplace shippers) so you can paste them into the *Additional Method Codes* field.
- **"Clear all" affordance** beneath both shipping-method multi-selects (next-day and backorder-express). A red hyperlink under the field that deselects every option in one click. Dispatches a `change` event so Magento's form-save dirty-tracking sees the reset. Vanilla JS — no jQuery / RequireJS coupling, works on stock admin theme and Hyvä admin theme. Plus an inline italic hint reminding merchants that the native multi-select uses `Ctrl` / `Cmd` + click for per-option deselect.
- **New `Block/Adminhtml/System/Config/Form/Field/ShippingMethodMultiselect`** — extends Magento's stock `Field` renderer, delegates element rendering to the parent so the underlying `<select multiple>` semantics are unchanged, then appends the Clear-all affordance. Wired into both `shipping_method_codes` and `express_method_codes` via `<frontend_model>` in `system.xml`.

#### Fixed

- **Misleading "info-only" claim removed from the Next Day Methods comment.** v1.4.1's comment told merchants that Hyvä Shipping Page entries are info-only content blocks. That was wrong for stores where the Shipping Page module actually registers carriers at runtime via `collectRates()`. The new comment correctly explains: these methods DO fire as real shipping options at checkout, they just don't surface in the standard `Allmethods` source — so the *Additional Method Codes* field (still the right escape hatch) is the way to target them. The CLI command above closes the loop on method discovery.

#### Verified

- 109/109 NDE unit tests pass
- New `ListMethodsCommand` registered via `etc/di.xml`, callable as `bin/magento etechflow:nde:list-methods`
- New `ShippingMethodMultiselect` frontend model wired to both multi-select fields — Clear-all link visible underneath, native multi-select semantics preserved
- `phpstan` level 4 — clean on the two new files

---

## [1.4.1] — 2026-05-16

### UX polish patch — closes 8 gaps surfaced by merchant testing

A single focused patch addressing every UX issue raised since v1.4.0 ship. No feature changes, no breaking changes — pure ergonomic improvements to the admin experience.

#### Added

- **Additional Method Codes (advanced) — text input** under both *Next Day Shipping Methods* and *Backorder Express Restriction → Express Methods*. Lets merchants paste `carrier_method` codes for custom shipping modules that don't register through Magento's standard `Allmethods` source (e.g. Hyvä Shipping Page modules where custom carriers live outside the standard `carriers/<code>/` config). Merged with the multi-select selection at lookup time.
- **`isBuyable()` method** on `Block/Product/NextDayBadge` — wraps `Product::isSalable()` for a clean saleability check.
- **`<tooltip>` element on every admin field** in `system.xml` — short hover-help (1 sentence) alongside the existing longer `<comment>` (paragraph). Reduces "what does this do?" friction.
- **Admin stock-state diagnostic notice** in `UpdateOnProductSave` observer. Fires only on adminhtml saves. When a product is saved in a contradictory state — `qty <= 0` AND Stock Status = "In Stock" AND Backorders = "No" AND Manage Stock = "Yes" — surfaces an admin notice explaining the consequences and naming the fixes. Drop-ship-eligible products get a more specific warning suggesting Auto-Enable Backorders. Gated to adminhtml area only; cron / API / import saves silently skip.

#### Changed

- **Default `label_no` value**: "Standard Delivery Only" → **"Standard Delivery"**. The "Only" implied a negative framing that read poorly next to the green eligible badge. (Existing installs keep their saved value; only fresh installs see the new default.)
- **Grey "ineligible" badge is now hidden** when a product can't be added to cart (`isBuyable() === false`). Previously, an out-of-stock product with no backorders would display "Out of Stock" label AND the grey shipping-promise badge — visually confusing. The badge now suppresses itself when the shipping promise is irrelevant. Green "eligible" badge unaffected.
- **EAV attribute notes** on `drop_ship_eligible` and `force_standard_shipping_only` rewritten with:
  - Full explanation of the side-effects (drop-ship triggering auto-backorders; force-standard overriding drop-ship)
  - The precedence rule (force-standard > drop-ship > stock)
  - Saleability impact (force-standard doesn't affect cart visibility; drop-ship does via the backorder side-effect)
- **Multi-select comment text** updated to explicitly mention the custom-carrier escape hatch:
  > *"Custom shipping modules: if your store uses a Hyvä Shipping Page or similar info-only module, its entries are NOT shipping carriers and won't appear here. Only carriers that actually fire at checkout are listed. Use the Additional Method Codes field below for custom carriers outside Magento's standard Allmethods source."*

#### Fixed

- **Hyvä Checkout notice now actually renders on mixed-eligibility carts.** Previously the customer-facing "Next Day Delivery Unavailable" notice injected into only `checkout.shipping.before` — a container that exists in Magento's Knockout checkout but is NOT reliably present in Hyvä Checkout (different Hyvä Checkout versions expose different container names). The notice silently no-op'd on Hyvä Checkout one-step storefronts. v1.4.1 references the notice block from THREE Hyvä Checkout containers (`before-form-shipping-address`, `shipping-method-list.before-text`, and the legacy `checkout.shipping.before` fallback). A server-side `static` guard in `hyva-ineligible-notice.phtml` short-circuits any duplicate render so only the first container that fires shows the notice — works regardless of which Hyvä Checkout version the store runs.

#### Implementation

- New `Config::mergeCodeSources()` private helper. `getShippingMethodCodes()` and `getBackorderExpressMethodCodes()` both delegate to it — merge the multi-select primary + free-text additional input, de-dupe, trim, return.
- New `Setup/Patch/Data/UpdateAttributeNotesV141.php` data patch — idempotent migration that rewrites the EAV `note` columns on existing installs. Fresh installs get the new notes via the updated `AddDropShipEligibleAttribute.php` and `AddForceStandardShippingOnlyAttribute.php`.
- `UpdateOnProductSave` observer constructor now injects `MessageManagerInterface`, `AppState`, `StockRegistryInterface`. Diagnostic logic gated to `Area::AREA_ADMINHTML` so no leaked messages outside the admin context.
- `NextDayBadge::shouldRenderBadge()` now consults `isBuyable()` for the ineligible-badge suppression.

#### Verified

- `bin/magento etechflow:nde:verify` — passes (existing test product behaviour unchanged)
- All 105 NDE unit tests — pass
- `setup:upgrade` — clean, new migration patch runs
- `setup:di:compile` — clean (new observer DI dependencies resolve correctly)
- `phpstan` level 4 — clean

---

## [1.4.0] — 2026-05-16

### Added — `Force Standard Shipping Only` per-product flag

Closes a real gap: a merchant who wants a specific in-stock product restricted to standard shipping (bulky / hazmat / fragile / made-to-order) previously had no way to express that. The auto-calculated `next_day_eligible` attribute would flip back to "eligible" every time stock saved. v1.4.0 adds a manual override.

- New per-product attribute **`force_standard_shipping_only`** — boolean checkbox under the *eTechFlow Shipping* attribute group on the product edit page. When ticked, the product is **always treated as not next-day-eligible regardless of stock state**.
- New `Setup/Patch/Data/AddForceStandardShippingOnlyAttribute.php` — idempotent data patch, fresh installs get the attribute via `setup:upgrade`.
- `EligibilityEvaluator::isStockEligible()` now applies a three-step precedence:
  1. `force_standard_shipping_only = 1` → **always ineligible** (merchant override)
  2. `drop_ship_eligible = 1` → **always eligible** (supplier ships direct)
  3. Stock check (`is_in_stock = 1 AND qty > 0`) → eligible
- `EligibilityEvaluator::loadAttributeFlags()` — single collection query loads both `drop_ship_eligible` and `force_standard_shipping_only` together, saving one DB round-trip per evaluation.
- `Observer/UpdateOnProductSave` extended to also re-evaluate when `force_standard_shipping_only` toggles (previously only watched `drop_ship_eligible`). The backorder-sync side-effect is gated to drop-ship changes only — force-standard doesn't affect saleability, so the merchant's existing backorder settings stay untouched.
- Attribute visible + filterable in the *Catalog → Products* grid for bulk operations. Magento's standard *Actions → Update Attributes* mass-action works on it — flip many products at once.

### Use cases this unlocks

| Product category | Why force-standard |
|---|---|
| Bulky / oversized (furniture, large appliances) | Couriers won't carry these next-day |
| Hazardous goods (batteries, aerosols, chemicals) | Air-freight restricted; ground-only |
| Fragile items (glass, electronics) | Merchant trusts specific slower carriers |
| Made-to-order / pre-order items | Not technically backorder, but not same-day either |
| Promotional pricing | Merchant doesn't want to subsidise express on discounted lines |

### Changed

- 4 new unit tests in `UpdateOnProductSaveTest` covering the force-standard precedence (flipped on, flipped off, neither changed, both changed simultaneously).
- New `EligibilityEvaluatorTest` (11 tests) covering each precedence step + conflict resolution + `evaluateById` entry point.
- README + USER_GUIDE updated with the new flag and the precedence table.
- Suite count: **172 tests / 230 assertions, all green.**

### Added — headless verification CLI command

A new console command lets merchants confirm the install is working end-to-end without opening a browser:

```bash
bin/magento etechflow:nde:verify --sku=<any-simple-product-sku>
```

What it does:
1. Captures the product's current `force_standard_shipping_only` + `next_day_eligible` values
2. Sets force_standard = 1, saves → observer fires → evaluator runs → DB updated
3. Reads back `next_day_eligible` and asserts it flipped to 0
4. Toggles force_standard = 0, confirms the evaluator recomputes natural eligibility
5. **Always restores** the original state (success or failure)
6. Exits 0 on PASS, 1 on FAIL — suitable for CI / monitoring scripts

Output is structured and human-readable so merchants can paste it into a support email if anything fails. Errors point at `var/log/system.log` and `var/log/exception.log` for diagnostic details.

---

## [1.3.0] — 2026-05-15

### Added — UX overhaul
A round of admin-UX improvements based on first-time-merchant feedback. All backward compatible.

- **Module Status banner** — a new full-width callout at the top of the NDE admin config section shows the current state in plain language:
  - ✅ **Module is active** (green) — licence valid + module enabled
  - ⚪ **Licence valid, module is disabled** (grey) — Enable Module toggled off
  - ⚠️ **Licence key missing** (amber) — production host, no key entered
  - ⚠️ **Licence key invalid for this host** (amber) — key entered but doesn't match domain
  - ℹ️ **Dev host bypass active** (blue) — current host matches `*.test`/`localhost`/`staging.*`/etc.
  - ℹ️ **Production Environment = No** (blue) — toggle off, licence not enforced
  Each state explains *why* and *what to do*, so first-time installers don't have to test checkout to find out whether the module is doing anything.
- **PDP badge visibility toggle** — new field "Show Badge on Product Page" with three options: **Both** (default — show eligible AND ineligible badges), **Eligible only** (green only, hide the grey "Standard Delivery Only"), **Never** (no PDP badge at all; shipping restriction still works).
- **`drop_ship_eligible` column now filterable** in the Catalog → Products grid. Merchants can filter the grid by drop-ship status. New `EnableDropShipGridFilter` data patch upgrades existing installs; fresh installs get it via `AddDropShipEligibleAttribute`.
- **Inline tooltips on every admin field** — expanded the `<comment>` text on every toggle/dropdown to explain *exactly* what happens when each option is selected. Customers see "Yes = … / No = …" guidance inline without needing to read external docs.

### Changed — defaults
- **`shipping_method_codes` default is now empty** (was `flatrate_flatrate`). The module is a no-op until the merchant explicitly ticks methods. Previously a fresh install with the wrong shipping setup could trigger the safety net immediately and look broken.
- **README + USER_GUIDE** updated with the new badge visibility setting and bulk-edit guidance.

### Implementation
- New `Block/Adminhtml/System/Config/ModuleStatus.php` renders the status banner; wired via the new `module_status` group with `<frontend_model>` in `system.xml`.
- New `Model/Source/BadgeVisibility.php` source model + new `Config::getBadgeVisibility()` method.
- `Block/Product/NextDayBadge.php` gained a `shouldRenderBadge()` method; both phtml templates (Luma + Hyvä) now consult it before rendering.
- `LicenseValidator::isDevHost()` exposed as public so the status block can show bypass status.

---

## [1.2.0] — 2026-05-15

### Added — auto-populated multi-select shipping method fields
Merchants no longer have to know or look up their Magento method codes. Both shipping-method fields are now multi-select dropdowns that auto-populate from the store's currently active shipping methods.

- **Stores → Configuration → eTechFlow → Next Day Eligibility → General Settings → Next Day Shipping Methods** — now a multi-select grouped by carrier ("Flat Rate / Free Shipping / UPS / DHL / …"). Tick the methods you want removed for ineligible carts. List is fed by `Magento\Shipping\Model\Config\Source\Allmethods`, the same source Magento's core "Allowed Methods" config uses.
- **Stores → Configuration → eTechFlow → Next Day Eligibility → Backorder Express Restriction → Express Methods to Restrict on Backorder** — same multi-select pattern, separate list.
- Saved values are identical in format (comma-separated method codes), so any merchant who previously typed codes into the v1.1.0 text field sees them pre-ticked after upgrade. No data migration required.
- The safety net introduced in v1.1.0 (return original rates if filter would empty every method) is unchanged — still active, still recommended.

### Why
Customer feedback: "How is the merchant supposed to know that 'Free UK Delivery' has the code `freeshipping_freeshipping`?" Asking the merchant to dig through dev tools, the Sales → Shipping Methods admin, or `bin/magento dev:di:info` was a real friction point. The multi-select removes the guesswork entirely.

### Changed
- `etc/adminhtml/system.xml` field types updated from `text` to `multiselect` for both `shipping_method_codes` and `backorder_restriction/express_method_codes`. Labels also shortened ("Next Day Shipping Methods" instead of "Next Day Shipping Method Codes" — codes are no longer a merchant concept).
- Admin field help text rewritten to describe the dropdown interaction.
- `README.md` and `docs/USER_GUIDE.md` updated to reflect the dropdown UX. "How to find your method codes" guidance removed (no longer needed).

---

## [1.1.0] — 2026-05-15

### Added — "Backorder Express Restriction" (folded in from deprecated BackorderShippingRestrictor)
This release absorbs the entire feature set of the deprecated `ETechFlow_BackorderShippingRestrictor`
module into NextDayEligibility, eliminating an overlap that previously had two modules filtering
checkout shipping rates on similar conditions. Customers now get one module that handles both
stock-driven next-day rules AND merchant-flagged backorder rules with separate method-code lists.

- New `Model/BackorderChecker.php` — detects cart items that are out of stock, depleted past min-qty
  with backorders enabled, or partially short. Drop-ship eligible products are exempted by default.
- New admin group: **Stores → Configuration → eTechFlow → Next Day Eligibility → Backorder
  Express Restriction** with three fields:
  - **Restrict Express Methods on Backorder** (Yes/No, default No — opt-in)
  - **Express Method Codes to Restrict** (separate list from next-day codes — e.g. `ups_NextDayAir, ups_2DayAir`)
  - **Skip Drop-Ship Products** (Yes/No, default Yes)
- `Plugin/ShippingRestriction.php` now applies *both* rules in a single pass: collects the union of
  method codes to remove (next-day codes when ineligibility detected + express codes when
  backorder detected), then filters once.
- `Model/ConfigProvider.php` and `ViewModel/HyvaCheckoutNotice.php` show the same checkout banner
  for either trigger. Merchant copy is shared between rules; if you want context-aware messaging
  per rule, raise a feature request.

### Why this merge
The deprecated `BackorderShippingRestrictor` module solved a real problem (block express on backorder
items) but in practice overlapped heavily with NDE's restriction logic — both filtered the same
shipping rates plugin on similar cart-scan conditions. Two notices, two banner stacks, two licence
keys, two sets of admin settings. Customer feedback pointed at the duplication. Folding them into
one module gives merchants a single, comprehensive "shipping eligibility & restrictions" product.

### Migration for existing installations
- Before upgrading: copy your `etechflow_backorderrestrictor/general/shipping_method_codes` value
  into the new NDE field **Express Method Codes to Restrict**, and toggle **Restrict Express Methods
  on Backorder = Yes**. Settings do not auto-migrate (different config namespace).
- After upgrading: disable the `ETechFlow_BackorderShippingRestrictor` module
  (`bin/magento module:disable ETechFlow_BackorderShippingRestrictor`) and remove its composer
  package (`composer remove etechflow/module-backorder-shipping-restrictor`).
- The previously-installed BSR's notice/checkout assets will no longer load once disabled.

### Bundle
The eTechFlow 3-Module Bundle is now a 2-Module Bundle (bumped to v2.0.0) containing only
NextDayEligibility + BackorderEtaDisplay.

---

## [1.0.4] — 2026-05-15

### Added
- **Customer-facing checkout notice** when next-day shipping is removed because of mixed-eligibility carts. Previously the module silently removed next-day shipping methods at checkout when one or more items in the cart was not eligible — the customer saw standard shipping but had no explanation. Now a dismissible notice appears at the top of the checkout shipping step: *"One or more items in your cart is not eligible for next day delivery. Standard shipping options are available."*
- **Hyvä Checkout support** for the notice via a server-side phtml block (Tailwind + Alpine.js, dark-mode + reduced-motion variants, screen-reader announcements).
- **Standard Magento checkout support** for the notice via a Knockout/UI component (KO template + plain CSS).
- New admin group: **Stores → Configuration → eTechFlow → Next Day Eligibility → Checkout Notice** with four fields: Show Notice (default Yes), Notice Style (warning/info/error), Notice Title, Notice Message. Each store view can override the wording.
- New `Model/IneligibilityChecker.php` — shared eligibility-check logic now used by both the shipping-restriction plugin AND the new ConfigProvider/ViewModel, so the notice can never drift out of sync with the restriction.
- New `Model/ConfigProvider.php` (registered in `etc/frontend/di.xml`) and `ViewModel/HyvaCheckoutNotice.php` for the two checkout flavours.
- New `Model/Source/NoticeStyle.php` source model powering the Notice Style dropdown.

### Changed
- `Plugin/ShippingRestriction.php` refactored to delegate cart-eligibility evaluation to `IneligibilityChecker`. Behaviour is identical; the EAV query and product-type handling now live in one place.

---

## [1.0.3] — 2026-05-15

### Added
- **Auto-Enable Backorders for Drop-Ship Products** — closes a real UX gap. When a merchant ticks Drop-Ship Eligible = Yes on a product, the module now also sets the product's Advanced Inventory → Backorders to "Allow Qty Below 0" automatically. This keeps the storefront UX consistent: products that display a "Next Day Eligible" badge are also purchasable when local stock is zero.
  Previously, the merchant had to manually enable backorders on every drop-ship product, otherwise customers saw the green eligibility badge but no Add to Cart button.
- New admin toggle: **Stores → Configuration → eTechFlow → Next Day Eligibility → Drop-Ship Exception → "Auto-Enable Backorders for Drop-Ship Products"** (default: Yes). Set to No if you prefer manual control of backorder settings.
- New `Model/BackorderManager.php` for safely updating stock-item backorder flags (used internally by the observer).
- 3 new unit tests covering: auto-enable when drop-ship flips on, auto-revert when drop-ship flips off, skip when config disabled.

### Changed
- `UpdateOnProductSave` observer constructor now takes `BackorderManager` as a 3rd parameter (DI auto-resolves).
- `Config` model has a new `isAutoEnableBackorders()` method.

---

## [1.0.2] — 2026-05-15

### Added
- **"Production Environment" toggle** in admin (Stores → Configuration → eTechFlow → Next Day Eligibility → License). Set to "No" on any dev/staging install to run the module at full features without a licence key — useful for non-standard dev domains that aren't auto-detected. Default: Yes. Industry-standard pattern (Amasty, Aheadworks).
- 4 new unit tests covering toggle behaviour: bypass when off, require key when on, default-to-on when unset, off-overrides-valid-key.

### Changed
- Admin License section now has two fields: Production Environment (new) + License Key (existing).
- Customer documentation updated to explain the toggle.

---

## [1.0.1] — 2026-05-15

### Fixed
- **NULL eligibility values now correctly treated as ineligible.** Newly imported products with no eligibility evaluation no longer get free next-day shipping until they're stock-checked. (Previously, `addAttributeToFilter('next_day_eligible', ['neq' => 1])` silently missed rows where the value was NULL, allowing them through.)
- **Parent product eligibility recalc now reads directly from EAV** instead of via a product collection. Eliminates a race condition where the catalog flat index could return stale child values mid-save, causing incorrect parent eligibility.
- **Shipping rates plugin wrapped in try/catch.** A bad EAV query or DB hiccup can no longer crash the customer's checkout — the plugin logs the error and returns the original rates unchanged.
- **Grouped product type** added to container-types list (was missing — harmless in practice but inconsistent with sibling types).

### Changed
- **Default value of `next_day_eligible` attribute is now 0** (was 1). Pessimistic default — a brand-new product hasn't been stock-checked yet, so we shouldn't claim it's next-day eligible.
- **`drop_ship_eligible` attribute group** changed to "eTechFlow Shipping" (was "General") — consistent with the sister attribute, easier to find in admin.

### Added
- **`www.` prefix normalization** in license validation. One key now works for both `coolstore.com` and `www.coolstore.com`.
- **Expanded dev-host pattern detection** — auto-bypass for `staging.*`, `dev.*`, `qa.*`, `uat.*`, `test.*`, `preview.*`, `sandbox.*` subdomains, hyphen-staging patterns (`*-staging.*`, etc.), Adobe Commerce Cloud staging (`*.magento.cloud`), full RFC 1918 IPv4 ranges (10/8, 172.16-31/12, 192.168/16), and developer tunnels (ngrok, loca.lt, serveo).
- **Bundle license key support.** If you also bought the eTechFlow 3-Module Bundle, one bundle key activates all three modules.
- **Bulk import escape hatch.** Custom import scripts can set `$product->setData('_etechflow_skip_eligibility', true)` to bypass the save observer, avoiding 10K observer invocations on large CSV imports.

### Composer
- Magento framework version range extended to `^103.0||^104.0` so the same zip installs on Magento 2.4.6 through future 2.4.9+ releases.

---

## [1.0.0] — 2026-05-11

Initial release.

- `next_day_eligible` product attribute (auto-managed by stock observer)
- `drop_ship_eligible` product attribute (manual checkbox)
- Stock-driven eligibility evaluation on `cataloginventory_stock_item_save_after`
- Drop-ship-driven eligibility re-evaluation on `catalog_product_save_after`
- Parent product propagation (configurable, bundle, grouped)
- Shipping method restriction at checkout (plugin on `Quote\Address::afterGetGroupedAllShippingRates`)
- Product detail page badge (Luma + Hyvä variants)
- Admin config for module enable, license key, shipping method codes, badge labels
- HMAC-based per-domain license key system
