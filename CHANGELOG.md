# Changelog — Next Day Shipping Eligibility

All notable changes to this module. Adheres to [Semantic Versioning](https://semver.org/).

---

## [1.6.5] — 2026-05-24 — Fix v1.6.4 setup-patch early-bail bug (never wrote the legacy supplier-mode pin)

### Fixed

- **v1.6.4 setup patch silently bailed without writing the legacy pin.** `SetSupplierMatchModeLegacyForUpgrades` was supposed to pin upgrade installs to `any_active_qualifying` mode so their storefront behaviour wouldn't silently flip. The patch class registered in `patch_list` (Magento ran it), but never wrote a row to `core_config_data`. Upshot: existing installs upgrading from pre-v1.6.0 silently flipped to first-active-wins semantics on next `setup:upgrade` — exactly the regression the patch was supposed to prevent.

  **Root cause:** the patch's early-exit check used `ScopeConfigInterface::getValue()`, which returns the **merged** config including `config.xml` defaults. Since v1.6.4's `config.xml` defaults `supplier_match_mode` to `first_active_wins`, `getValue()` always returned that string → patch saw a truthy value, thought the merchant had explicitly set it, bailed without writing.

  **Fix:** new class `PinLegacySupplierMatchModeForUpgrades` queries `core_config_data` directly via the connection. That table only contains rows that were explicitly saved (admin or stored-config); config.xml defaults are NEVER persisted to it. So "row exists" correctly answers "merchant explicitly set this".

  **Why a new class instead of fixing the old one in-place:** Magento tracks patch execution by class name in `patch_list`. On installs that already ran the buggy v1.6.4 patch (patch_list row present), updating the v1.6.4 class's logic wouldn't cause Magento to re-run it — patch_list says it ran, Magento skips it forever. A new class name guarantees re-execution on those installs.

  The old class is kept on disk, marked `@deprecated`, its `apply()` method now a no-op. Deleting the file would cause "patch class not found" errors on `setup:upgrade` for installs whose `patch_list` references the old class name.

### Verified

Reproduced on local Magento 2.4.8 Docker:

1. Cleared the manual workaround row from `core_config_data`
2. Deployed v1.6.5 files
3. Wiped `generated/` + ran `setup:upgrade`
4. Confirmed `core_config_data` now contains: `etechflow_nextdayeligibility/drop_ship/supplier_match_mode = any_active_qualifying`
5. Confirmed `patch_list` has both `199` (old, deprecated) + `200` (new, working) coexisting cleanly
6. Confirmed `data_version` bumped to `1.6.5`

### Migration

```
composer update etechflow/module-next-day-eligibility
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
```

For Keystation specifically (currently on v1.6.0): on next `setup:upgrade`, the new patch will detect upgrade-from-pre-v1.6.5 and write `supplier_match_mode = any_active_qualifying` to `core_config_data`. Storefront behaviour stays at legacy semantics until you flip the admin field to `first_active_wins`.

For merchants who already deployed v1.6.4 and unknowingly had their behaviour flipped to first-active-wins: install v1.6.5, run `setup:upgrade`, the new patch detects no explicit row → writes the legacy pin → behaviour reverts. Storefront pricing / shipping eligibility returns to the pre-v1.6.0 logic.

---

## [1.6.4] — 2026-05-23 — First-active-wins supplier mode + dropdown attr support + fix v1.6.2 admin-page fatal

Three changes bundled. v1.6.3 was tagged internally with the first two but never published — v1.6.4 supersedes it with the visibility fix included.

### Fixed

- **Admin page fatal carried over from v1.6.2.** `Block/Adminhtml/Form/Field/MethodStatusDisplay.php` declared `escapeHtml()` as `private`, which reduced visibility vs `AbstractBlock::escapeHtml()` (public). PHP refused to load the class → fatal on every admin page that renders the system-config form. Caught Keystation v1.6.2 deploy; rolled back to v1.6.0. v1.6.3 inherited the broken class. **Fix:** removed the private override; the three call sites in the file now resolve to the parent's public `escapeHtml()` which uses `Magento\Framework\Escaper` (the canonical escape path). No behaviour change in admin output. The visibility bug wasn't caught by `php -l` (syntax-only) or `setup:di:compile` (the block isn't in the DI graph at compile time — it's registered via system.xml's `frontend_model`). Lesson logged as `feedback-magento-block-escape-visibility` memory for future blocks.

### Added

- **Supplier Match Mode admin switch.** New config field at Stores → Configuration → eTechFlow → Next Day Eligibility → Drop-Ship Settings → "Supplier Match Mode". Two modes:
  - **First active wins (recommended for new installs)** — walk slots in priority order; STOP at the first active slot; eligibility = is THAT slot's supplier name in the qualifying list. Models real fulfillment honestly — the supplier you'd actually ship from drives the next-day badge.
  - **Any active qualifying (legacy)** — pre-v1.6.4 behaviour. Iterate ALL active slots; eligible if any matches the qualifying list. Loose-OR semantics.
  
  **Why this matters:** with the legacy mode, if S1 = Onlyda (active, not next-day-capable) and S2 = Auto Remote (active, next-day-capable), the product was marked eligible — but you'd ship from S1 (Onlyda) and the customer would NOT get next-day service. First-active-wins fixes this by tying eligibility to the supplier that's actually fulfilling the order.
  
  Existing installs upgrading from < v1.6.4 are pinned to "Any active qualifying" by a one-time setup patch (`SetSupplierMatchModeLegacyForUpgrades`) so storefront behaviour doesn't silently flip. New installs default to "First active wins". Flip from the admin field when you're ready.

- **Dropdown / multi-select supplier name attribute support.** Pre-v1.6.4 the resolver had a hard `is_string($name)` guard that bailed on any attribute returning a non-string value (i.e. any dropdown attribute returning option IDs like `42`). Result: supplier mode silently never matched on stores using dropdown attributes for supplier names — which is the common case. **Fix:** new `resolveSupplierNames()` method handles three attribute shapes:
  - Text attribute storing a literal supplier name ("Auto remote man") → uses raw value
  - Single-select dropdown returning an option ID (`42`) → looks up the option label via `EavConfig::getAttribute()->getSource()->getOptionText()`
  - Multi-select returning multiple option IDs → resolves each label and matches if ANY is qualifying
  
  EavConfig added to `SupplierDropShipResolver` constructor. Text-attribute stores keep working unchanged.

### Migration from v1.6.0

```
composer update etechflow/module-next-day-eligibility
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
```

The setup patch detects existing installs via `setup_module.data_version` (which is `1.6.0` for you post-rollback) and pins to "Any active qualifying" mode. Storefront behaviour stays unchanged on upgrade. The admin pages that crashed under v1.6.2 now load cleanly. Flip to first-active-wins from admin when ready — comparison test plan available in the monorepo at `qa/NextDayEligibility/`.

### About v1.6.3

Tagged internally with the first-active-wins + dropdown changes but never published — superseded by v1.6.4 which adds the visibility fix on top. v1.6.3 is skipped on the public version timeline; treat v1.6.0 → v1.6.4 as the upgrade path.

---

## [1.6.2] — 2026-05-23 — Auto-invalidate FPC after writes + admin UX for misconfigured method codes

Two related quality-of-life fixes shipped together because they both address "the module is doing the right thing but customers can't tell".

### Fixed

- **FPC layer kept serving stale HTML after eligibility writes.** `EligibilityEvaluator::updateProductAttribute()` calls `ProductAction::updateAttributes()`. Magento Core's `catalog_product_attribute_update_after` observer is supposed to invalidate FPC tags when that runs — but it doesn't fire reliably from CLI / cron / plugin contexts (area-not-set issues), so the old `next_day_eligible` value stayed cached at the FPC / Varnish layer until a manual `bin/magento cache:flush`. **Fix:** inject `Magento\Framework\App\CacheInterface` into the evaluator and explicitly `clean(['cat_p_<id>'])` after every attribute update. Surgical per-product — doesn't touch the rest of the FPC namespace. Works for both built-in FPC and Varnish (Magento's `CacheInterface` translates tag-cleans into Varnish BAN requests). No more manual `cache:flush` needed for NDE updates.

### Added

- **Misconfigured-method-code detection — three new surfaces** to catch the silent-no-op class of bug where a merchant configures NDE to restrict shipping codes that aren't actually enabled on the store. (Real example from a Keystation install: `shipping_method_codes` was set to `tablerate_bestway,ups_01,ups_14` — none of those methods were enabled. NDE silently restricted nothing.)
  - **Admin header banner** (`Model/AdminNotice/ShippingMethodMismatchNotice`) — persistent red banner on every admin page when configured codes don't match active carriers. Lists which codes are unmatched. Hidden when module is disabled or all codes match. Registered via `Magento\AdminNotification\Model\System\MessageList`.
  - **Inline status panel** under each method-codes field in admin Stores → Configuration. Three render states: muted "Not configured", green "All N codes match", red "X codes unmatched (list)". Pairs with the banner — banner tells you a problem exists from any admin page, inline panel shows the diagnosis where you fix it. Implemented as `Block/Adminhtml/Form/Field/MethodStatusDisplay` (abstract) + three thin subclasses (NextDay / Standard / ClickCollect).
  - **Extended `bin/magento etechflow:nde:list-methods`** with a "NDE configuration vs active methods" diff after the existing table. `[OK]` / `[WARN]` markers per configured code, final summary line highlighting mismatches.
  - **Shared helper** `Model/ShippingMethodAvailability.php` — flattens Magento's `Allmethods` source into a Set of active codes and diffs against configured codes for any given rule type (nextday / standard / click-collect). Used by all three surfaces.

- **New docs page `docs/cache-and-cdn.md`** documenting what NDE invalidates automatically (FPC / Varnish per-product tag) and what it can't (Cloudflare — Magento has no native CF awareness). Three CF strategy options with our recommendation (bypass HTML caching at the CF edge).

### Changed

- **`etc/adminhtml/system.xml` sortOrder renumber.** Pre-existing collisions (multiple fields at 23 / 24) in the general group. Renumbered the methods block with clean gaps so the new status displays land in sequence: `shipping_method_codes(20)`, `additional_method_codes(22)`, `nextday_status_display(25)`, `standard_method_codes(30)`, `additional_standard_codes(32)`, `standard_status_display(35)`, `click_collect_method_codes(40)`, `click_collect_additional_codes(42)`, `click_collect_status_display(45)`, `badge_visibility(50)` (was 25). No data migration — sortOrder only affects field display order, stored config values use field paths which are unchanged.

### Migration

```
composer update etechflow/module-next-day-eligibility
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
```

After this, you should no longer need `cache:flush` after NDE updates (for the Magento layer — see `docs/cache-and-cdn.md` for Cloudflare). If your admin header shows a red banner about mismatched shipping codes, run `bin/magento etechflow:nde:list-methods` to see what's actually enabled on your store and fix the config at Stores → Configuration → eTechFlow → Next Day Eligibility → General Settings.

### Note on version numbering

Skipped v1.6.1 because v1.6.0 (MSI gap fix) shipped earlier the same day; bundling the FPC fix + admin UX into 1.6.2 keeps the release count tidy.

---

## [1.6.0] — 2026-05-23 — Close the MSI stock-event propagation gap

A customer found a product (KS00926) with `qty=0` but `next_day_eligible=1` — the existing observer pipeline never recomputed because the stock dropped to zero through a path that doesn't fire `cataloginventory_stock_item_save_after`. Investigation showed this is a systematic gap on Magento 2.3+ with MSI installed, affecting most modern stock-change flows.

### Root cause

NDE's original observers in `etc/events.xml` listen for two events:

- `cataloginventory_stock_item_save_after` — fires on direct legacy `cataloginventory_stock_item` saves
- `catalog_product_save_after` — fires on product entity save

On Magento 2.3+ with MSI installed, most stock changes go through `Magento\InventoryApi\Api\SourceItemsSaveInterface`, which does NOT trigger `cataloginventory_stock_item_save_after`. The legacy table is updated later by MSI's sync indexer, but that sync runs without firing a save event our observer could hook. Flows that silently bypassed the recompute:

- Order shipment → MSI reservation collapse → indexer-only update
- Refund / credit memo restocking
- Source-items save via REST / SOAP / GraphQL API
- Admin "Manage Stock" screen on a single source
- Bulk import via MSI source-items endpoint

### Added

- **`Plugin/RecomputeOnMsiSourceItemsSave.php`** — `afterExecute` plugin on `Magento\InventoryApi\Api\SourceItemsSaveInterface`. For each saved source item, resolves SKU → product ID and calls `EligibilityEvaluator::evaluateById()`. Soft-installed: if MSI isn't present (rare on Magento 2.3+ but possible on stripped builds), Magento silently ignores the `<type>` declaration in `etc/di.xml` because the target interface doesn't exist — no fatal, plugin just never runs.

- **`Cron/RecomputeStaleEligibility.php` + `etc/crontab.xml`** — hourly safety-net cron. Iterates simple products (up to 5000 per run), calls `EligibilityEvaluator::evaluateById()` on each. Belt-and-braces backstop for any propagation hole the plugin doesn't cover. Evaluator is idempotent so re-running on a fresh row is a cheap no-op. Schedule: `0 * * * *`.

- **`Console/Command/ResyncEligibilityCommand.php`** — `bin/magento etechflow:nde:resync`. One-shot full-catalogue re-evaluation. Supports `--sku=A,B,C` to narrow to specific SKUs and `--dry-run` to count without writing. Use cases: cleanup right after upgrading to v1.6.0; manual fix for known-stale SKUs; CI / health-check via dry-run.

### Unchanged

- Existing observers (`cataloginventory_stock_item_save_after`, `catalog_product_save_after`) stay — they still catch direct legacy saves and product saves. v1.6.0 is purely additive coverage.
- `EligibilityEvaluator` logic unchanged. Precedence rules (force_standard > drop_ship > supplier > stock) stay identical.
- StockRegistryInterface reads — still legacy. If we find STILL-stale state after v1.6.0, switch evaluator reads to MSI's `IsProductSalableInterface` as a v1.7 follow-up. Out of scope here.

### Migration

```
composer update etechflow/module-next-day-eligibility
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy -f
bin/magento cache:flush
bin/magento etechflow:nde:resync   # one-shot cleanup of any pre-existing stale rows
```

The hourly cron takes over from there. No schema changes, no breaking changes.

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
