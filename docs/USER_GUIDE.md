# Next Day Shipping Eligibility — User Guide

A complete reference for configuring and using the module. If you're just installing, start with `README.md` and `docs/installation_guide.html`.

---

## What the module actually does

In one sentence: **keeps a flag on every product saying whether it can ship next-day, automatically updates that flag whenever stock changes, and removes next-day shipping at checkout when any cart item can't make the cutoff.**

In more detail:

1. **Adds two product attributes** to every product in your catalogue:
   - `next_day_eligible` — auto-calculated (1 = can ship next-day, 0 = cannot)
   - `drop_ship_eligible` — manual checkbox you tick for products your suppliers ship directly
2. **Watches stock changes** — whenever stock saves (admin, ERP sync, REST API), the module recomputes `next_day_eligible` for the affected product
3. **Propagates to parent products** — if any child of a configurable/bundle/grouped product is eligible, the parent shows as eligible too
4. **At checkout**, when shipping rates are calculated, the module checks if any cart item is ineligible. If so, it removes your configured next-day shipping methods from the rate list — silently, without an error message
5. **Shows a badge on the product detail page** so customers know upfront whether next-day is available

## Admin configuration

Navigate to **Stores → Configuration → eTechFlow → Next Day Eligibility**.

### General section

| Field | Default | What it does |
|---|---|---|
| **Enabled** | No | Turn the module on/off globally. Off = module silently no-ops. |
| **Shipping Method Codes** | (none) | The methods you want REMOVED for ineligible carts. Multi-select. Format: `carrier_method` (e.g. `ups_NextDayAir`, `royalmail_tracked24`, `dpd_NextDay`). Only methods listed here are filtered — others always remain available. |

### Display section

| Field | Default | What it does |
|---|---|---|
| **Eligible Label** | "Next Day Eligible" | Text shown on the product page badge when the product can ship next-day. Translatable. |
| **Ineligible Label** | "Standard Delivery Only" | Text shown when the product cannot ship next-day. Translatable. |

### License section

| Field | What to enter |
|---|---|
| **License Key** | Paste the key from your purchase email |

## Force Standard Shipping Only — per-product override (v1.4.0+)

A merchant-controlled override that hard-disables next-day eligibility for a specific product *regardless of stock state*. The auto-calculation (qty > 0 → eligible) is bypassed entirely.

### When to use it

| Product category | Why force-standard |
|---|---|
| Bulky / oversized (furniture, large appliances, mattresses) | Couriers won't carry these next-day, no matter how much stock you have |
| Hazardous goods (lithium batteries, aerosols, chemicals) | Air-freight restricted by IATA / IMDG; must ship by ground |
| Fragile items (glass, ceramics, lab equipment) | Merchant only trusts specific slower carriers for these |
| Made-to-order / pre-order items | Not technically backorder, but the merchant won't ship same-day either |
| Promotional / discounted lines | Merchant doesn't want to subsidise express shipping on margin-thin items |

### How to use it

1. Open the product in admin: *Catalog → Products → [edit]*
2. Scroll to the **eTechFlow Shipping** attribute group (same place as *Drop-Ship Eligible*)
3. Tick **Force Standard Shipping Only = Yes**
4. Save

The module re-evaluates the product's `next_day_eligible` flag on save. The PDP badge will switch to "Standard Delivery Only", and any next-day shipping methods configured under *Stores → Configuration → eTechFlow → Next Day Eligibility → Next Day Shipping Methods* will disappear at checkout whenever this product is in the cart.

### Bulk-edit (many products at once)

1. *Catalog → Products*
2. Filter the grid by Force Standard Shipping Only = No, narrow further by category / weight / SKU pattern as needed
3. Tick the products
4. *Actions → Update Attributes → Force Standard Shipping Only = Yes* → Submit

Magento applies it to every selected product. The module's observer fires on each, recomputing eligibility.

### Precedence — what wins when flags conflict

Inside `EligibilityEvaluator`:

```
1. force_standard_shipping_only = 1   →  ALWAYS ineligible    (merchant override wins)
2. drop_ship_eligible           = 1   →  ALWAYS eligible      (supplier ships direct)
3. stock check                        →  qty > 0 = eligible
```

**Example**: a product with `drop_ship_eligible = Yes` AND `force_standard_shipping_only = Yes` is **ineligible**. The merchant override beats the drop-ship rule. This is intentional: if you've explicitly said "ship this standard only", a drop-ship supplier exception shouldn't override it.

### What this does NOT do

- Does **not** affect the product's saleability — customers can still buy it and the Add-to-Cart button stays visible. It only restricts which shipping methods appear at checkout.
- Does **not** affect Magento's Backorders setting (unlike Drop-Ship Eligible, which optionally syncs the Backorders flag).
- Does **not** override Magento's own carrier-level shipping rules. If you've set carrier filters in *Stores → Configuration → Sales → Shipping Methods → [Carrier] → Applicable Countries* etc., those still apply.

### Verifying the feature on your install

A console command bundled with the module runs an end-to-end check without needing a browser:

```bash
bin/magento etechflow:nde:verify --sku=<any-simple-product-sku>
```

It picks the product you specify, captures its current state, flips `force_standard_shipping_only` on and off, and asserts that `next_day_eligible` correctly tracks the change. **Original state is always restored** at the end (success or failure) so the product isn't left in a dirty state.

Output looks like:

```
Verifying v1.4.0 force-standard flow on SKU 'TEST-001' (entity_id 42)...

  Initial: force_standard_shipping_only=0, next_day_eligible=1

  Test 1: setting force_standard_shipping_only = 1
    Read back: next_day_eligible = 0
    PASS — next_day_eligible flipped to 0 as expected

  Test 2: setting force_standard_shipping_only = 0
    Read back: next_day_eligible = 1
    PASS — flag toggled off cleanly; evaluator recomputed natural eligibility

  Cleanup: restoring original attribute values
    Restored force_standard_shipping_only = 0

====================================
ALL CHECKS PASSED. v1.4.0 verified.
====================================
```

Exits 0 on PASS, 1 on FAIL — drop it into your CI / deployment script if you want continuous verification. If anything fails, check `var/log/system.log` for `ETechFlow_NextDayEligibility` error entries.

## Drop-Ship Eligible — and automatic Backorders sync (v1.0.3+)

When you flag a product as **Drop-Ship Eligible = Yes**, the module **automatically sets the product's Backorders to "Allow Qty Below 0"** so that customers can add it to cart even when local stock is zero. This keeps the storefront UX consistent — products that show as "Next Day Eligible" are also purchasable.

### Default behaviour (recommended)

| What you do | What the module does automatically |
|---|---|
| Tick **Drop-Ship Eligible = Yes** on a product → Save | Module sets that product's Backorders to "Allow Qty Below 0" |
| Untick **Drop-Ship Eligible** (= No) → Save | Module reverts that product's Backorders to "Use Config" (store-wide default) |

After this:
- Customer sees the product available (or with a backorder note)
- Add to Cart button visible
- Drop-ship override keeps next-day shipping available
- Order goes through, you fulfil via the supplier

### Want manual control? Disable the auto-toggle

If you want to manage backorders yourself (e.g. supplier reliability concerns), go to:

**Stores → Configuration → eTechFlow → Next Day Eligibility → Drop-Ship Exception**

Set **"Auto-Enable Backorders for Drop-Ship Products"** to **No**. Now drop-ship saves don't touch the product's backorder settings — you manage them manually via Advanced Inventory.

### Scenario matrix

For a Drop-Ship Eligible product when local stock = 0:

| Auto-Enable setting | Backorders state | Add to Cart button | Next-day shipping at checkout |
|---|---|---|---|
| **Yes (default)** | Auto-set to Allow Qty Below 0 | ✅ Visible | ✅ Available (drop-ship override) |
| **No** | Whatever you set manually | Depends on your manual setting | ✅ Available (drop-ship override) |

## Per-product attributes

Navigate to **Catalog → Products** → edit any product. Two new fields appear under the **eTechFlow Shipping** attribute group:

### Drop-ship Eligible

Tick this for products your suppliers ship directly (rather than products in your warehouse). When ticked, the product is treated as next-day eligible **regardless of in-warehouse stock**. Useful for:

- Furniture or large items shipped direct from the manufacturer
- Personalised goods (printed on demand)
- White-label SKUs your supplier holds stock for

Default: unticked.

### Next Day Eligible (read-only — managed automatically)

This is the computed flag. The module updates it whenever:
- Stock changes for the product
- You tick or untick "Drop-ship Eligible" and save
- A child product's stock changes (for configurable/bundle/grouped parents)

**Do not edit manually unless you have a specific reason** — the next stock save will overwrite your change.

## What customers see

### On the product detail page

| Eligibility | Badge text | Colour |
|---|---|---|
| `next_day_eligible = 1` | "Next Day Eligible" (or your custom Eligible Label) | Green |
| `next_day_eligible = 0` or NULL | "Standard Delivery Only" (or your custom Ineligible Label) | Grey |

The badge appears beneath the price, above the Add to Cart button.

**On Hyvä themes:** the badge uses Tailwind classes with rounded-pill styling, includes a check/x SVG icon, supports dark mode, and fades in with a subtle animation that respects the user's reduced-motion preference.

### At the checkout shipping step

Next-day shipping methods you configured in **Shipping Method Codes** are simply **absent** from the rates list when any cart item is ineligible. There's no error message, no banner, no friction — the customer sees only the shipping methods they can actually use.

If you want a customer-facing notice ("Express shipping unavailable because…"), install our **Backorder Shipping Restrictor** module alongside this one — it pairs.

## How parent products work

Magento has three "container" product types: configurable, bundle, grouped. Their eligibility is derived from their children:

- If **any** child is eligible → parent shows as eligible
- If **all** children are ineligible → parent shows as ineligible

The module recalculates parent eligibility automatically whenever:
- A child product's stock changes
- A child product's drop-ship flag flips

You don't need to manually trigger this — the observer chain handles it.

## Bulk imports — the escape hatch

If you import 10,000+ products at once (CSV import, ERP sync, custom migration script), the save observer would normally fire 10,000 times — slow.

The module honours a **skip flag** that your import code can set:

```php
// Inside your custom import script
foreach ($productsToImport as $product) {
    $product->setData('_etechflow_skip_eligibility', true);
    $productRepository->save($product);
}

// Then after the import, recompute in batch
$evaluator = $objectManager->get(\ETechFlow\NextDayEligibility\Model\EligibilityEvaluator::class);
foreach ($importedProductIds as $id) {
    $evaluator->evaluateById((int) $id);
}
```

The module also automatically skips when Magento sets `_indexer_processing` on a product (during the async reindex flow).

## Known limitations

A short, honest list of things this module does **not** do, so you don't have wrong expectations:

- **Partial-stock-shortage as a backorder trigger.** The Backorder Express Restriction rule fires when ordered qty exceeds available qty (e.g. customer adds qty 5 of a product with 3 in stock). That's correct but can surprise merchants who only expected the "Backorders enabled" flag to matter. Disable the backorder rule if you don't want that case to count.
- **Configurable / bundle / grouped parent badge on listing pages.** The PDP badge for parent products reflects "any child eligible" (so the parent shows green if at least one variant is eligible). On the PDP, the customer can still pick a variant that's out of stock — the badge will stay green even after that pick, because re-rendering on swatch click requires JS we don't ship. Merchants who care about this should hide the PDP badge entirely (General Settings → Show Badge on Product Page → Never) and rely on the checkout-time restriction.
- **Split shipments not supported.** If a cart has 2 in-stock items + 1 backorder item, the module treats the cart as a whole — it doesn't offer to ship the in-stock items now and the backorder item later. That's a separate Magento feature (split-shipment / multi-shipping) outside this module's scope.
- **No `Test Licence` button.** The licence is verified against the ETechFlow portal (the portal holds the signing secret; the module ships none), with the answer cached briefly so it isn't checked on literally every request. If the portal is briefly unreachable, a recent successful validation keeps the store live for a grace window. The Module Status banner at the top of the admin config section tells you whether the current key is valid for the current host.

## Configuration trap — don't restrict your only fallback method

Catches first-time users often enough that it deserves its own section.

### What goes wrong

The module works by **removing** specific shipping methods from the rate list at checkout. The "Next Day Shipping Methods" multi-select tells it *which* methods to remove. If you tick every method your store offers, an ineligible cart ends up with **zero shipping methods at checkout** — the customer can't pick anything, can't complete the order, and bounces.

Since v1.2.0 the field is a multi-select populated from your active shipping methods (so you no longer have to know technical codes), but the trap is still possible: tick every box in the list and you'll trigger it.

### Concrete example — UK store with "Free Delivery" only

A merchant offers only "Free Delivery" and thinks "next-day = free delivery, that's the one I want to restrict". They tick it in the multi-select.

Result for an ineligible cart:
- NDE removes Free Delivery → no methods left → checkout dead

### The safety net (v1.1.0+)

The shipping plugin detects this case. If filtering would leave the customer with zero shipping methods, it **aborts the filter and returns the original rates unchanged**, plus logs a warning to `var/log/system.log`:

```
WARNING: ETechFlow_NextDayEligibility: Shipping restriction would leave no
methods available — configured codes likely match every method offered.
Returning original rates to keep checkout usable.
{"codes_to_remove":["freeshipping_freeshipping"]}
```

The customer can still check out (with the "wrong" speed — better than a broken cart), but the merchant gets a clear signal in the logs that their config is wrong.

**The safety net is a last-resort guardrail, not a substitute for correct configuration.** With it active, ineligible items DO ship on next-day. Fix your config.

### The correct pattern

Always offer at least two shipping tiers and only tick the *faster/paid* one(s):

| Real-world UK setup | What to tick |
|---|---|
| Free Delivery + Royal Mail Tracked 24 | Royal Mail Tracked 24 only |
| Standard Shipping + UPS Next Day Air | UPS Next Day Air only |
| Free Delivery + DPD Next Day + UPS Saver | DPD Next Day and UPS Saver |
| **Only** Free Delivery, nothing else | **Don't install this module** — there's nothing to restrict and no fallback available |

### Same trap applies to Backorder Express Restriction

The "Express Methods to Restrict on Backorder" multi-select has the identical risk. Same rules apply — keep at least one method unticked so backorder carts can still check out.

## Backorder Express Restriction (v1.1.0+)

Introduced in v1.1.0, this is a **separate, opt-in shipping rule** that absorbs the entire feature set of the now-deprecated `ETechFlow_BackorderShippingRestrictor` module.

### What it does

Where the next-day rule fires when items are *not eligible* (out-of-stock-and-not-drop-ship), this rule fires when items are *on backorder*. Three cart conditions count as backorder:

1. The product is flagged out of stock entirely (`is_in_stock = 0`).
2. Backorders are enabled (Advanced Inventory → Backorders = Allow Qty Below 0) AND stock has been depleted at or below the min-qty threshold.
3. The ordered quantity exceeds available saleable stock (partial-stock backorder — e.g. customer adds qty 5 of a product with qty 3 in stock).

When any cart item meets one of these conditions, the module removes a **separate configured list of express method codes** from the checkout. The notice banner is the same banner used by the next-day rule — one banner, customisable wording.

### How to enable it

**Stores → Configuration → eTechFlow → Next Day Eligibility → Backorder Express Restriction**

| Field | What to enter |
|---|---|
| **Restrict Express Methods on Backorder** | Set to **Yes**. Default is No (the feature is opt-in). |
| **Express Method Codes to Restrict** | Comma-separated `carrier_method` codes — for example `ups_NextDayAir, ups_2DayAir, fedex_FEDEX_2_DAY`. Kept separate from the next-day codes so you can target different methods per rule. |
| **Skip Drop-Ship Products** | Yes (default). Drop-ship-eligible products bypass this restriction because the supplier ships them directly. |

### Scenario matrix

For a cart containing **product A** (in stock, next-day eligible) and **product B** (on backorder):

| Settings | Methods removed from checkout |
|---|---|
| Next-day codes: `flatrate_flatrate`. Backorder rule OFF. | `flatrate_flatrate` (because B's backorder state also means it's not next-day eligible) |
| Next-day codes: `flatrate_flatrate`. Backorder rule ON, express codes: `ups_NextDayAir`. | `flatrate_flatrate` + `ups_NextDayAir` (both rules fire, union of codes removed) |
| Both rules ON, but product B is flagged Drop-Ship Eligible. | Nothing — drop-ship exemption is honoured by *both* rules. |
| Both rules ON, but Skip Drop-Ship for backorder set to No. | `flatrate_flatrate` + `ups_NextDayAir` (drop-ship still exempts the next-day rule but no longer exempts the backorder rule) |

### When to use this rule vs the next-day rule

| Use the next-day rule when… | Use the backorder rule when… |
|---|---|
| You want fully automatic stock-driven blocking of specific next-day methods | You want manual control via Magento's native `backorders` flag |
| You only care about one fast method (e.g. one Royal Mail Tracked 24 service) | You want to block multiple express methods (NextDay, 2Day, Express, etc.) |
| Your products are normal stock items, in or out | You sell explicitly-flagged pre-order / made-to-order items |
| You don't want merchants flipping a Magento setting per product | You're OK with merchants opting products in via Advanced Inventory |

Most merchants will use both — the next-day rule for the common case, the backorder rule as a catch-all for items they manually flag.

### Migration from the deprecated BackorderShippingRestrictor module

If you previously installed `ETechFlow_BackorderShippingRestrictor`:

1. **Before disabling BSR, copy its settings**:
   - BSR's `Restricted Shipping Method Codes` → NDE's `Express Method Codes to Restrict`
   - BSR's `Skip Drop-Ship Products` → NDE's `Skip Drop-Ship Products` (same setting, new home)
   - BSR's `Show Notice / Style / Title / Message` → NDE's `Checkout Notice` group (already set if you used the v1.0.4 next-day banner — same fields drive both rules)
2. **Set NDE's `Restrict Express Methods on Backorder` to Yes** to activate the rule.
3. **Disable + remove BSR**:
   ```bash
   bin/magento module:disable ETechFlow_BackorderShippingRestrictor
   composer remove etechflow/module-backorder-shipping-restrictor
   bin/magento setup:upgrade
   bin/magento cache:flush
   ```
4. **Test**: add a backorder item to a cart, go to checkout, verify the configured express methods are gone and the banner fires.

The deprecated module's database settings (under `etechflow_backorderrestrictor/*`) remain in `core_config_data` after removal — harmless, ignored. If you want them cleaned, delete those rows manually.

## Cross-module integration

The **eTechFlow 2-Module Bundle** (NDE + Backorder ETA Display) is the recommended way to ship both customer-facing improvements together. Paste your bundle license key into NDE's License Key field — it'll automatically activate Backorder ETA Display too.

## License behaviour

The module checks its license on every page load. If invalid, all behaviour silently stops:

- Observers skip
- Plugin returns shipping rates unchanged
- Badge block doesn't render

No error banner, no crash. The merchant just sees the module isn't doing anything, contacts support, fixes the key.

### Dev/staging environments

These hostnames automatically bypass licensing — install and test for free:

| Pattern | Examples |
|---|---|
| Loopback / RFC 1918 IPs | `localhost`, `127.x`, `10.x`, `172.16-31.x`, `192.168.x` |
| Reserved TLDs | `*.test`, `*.local`, `*.localhost`, `*.dev`, `*.example`, `*.invalid` |
| Staging subdomain prefixes | `staging.*`, `stage.*`, `dev.*`, `qa.*`, `uat.*`, `test.*`, `preview.*`, `sandbox.*` |
| Hyphen variants | `*-staging.*`, `*-dev.*`, `*-uat.*` |
| Adobe Commerce Cloud staging | `*.magento.cloud`, `*.magentocloud.com` |
| Developer tunnels | `*.ngrok.io`, `*.ngrok-free.app`, `*.loca.lt`, `*.serveo.net` |

### Domain transfers

Need to move from `keystation.co.uk` to `keystation.uk` (e.g. site migration)? Email `support@etechflow.com` with both domains and your order number. We'll issue a new key — no charge.

## Troubleshooting

### "I configured everything but badges aren't appearing"

Check, in this order:

1. **Is the module enabled?** Admin → Stores → Configuration → eTechFlow → Next Day Eligibility → Enabled = Yes
2. **Is the license valid?** Confirm the key in the License section matches the key emailed for your current production domain. Bear in mind `www.` is normalised — `www.coolstore.com` and `coolstore.com` use the same key.
3. **Did setup:upgrade run?** Run `bin/magento setup:upgrade` again. The patches that add the attributes are idempotent — safe to re-run.
4. **Is the cache flushed?** `bin/magento cache:flush`
5. **Check `var/log/exception.log`** — search for "etechflow". Any errors there are diagnostic.

### "Next-day shipping methods aren't being removed"

Check:

1. **Are the right method codes selected?** Admin → Shipping Method Codes. Format is `carrier_method` — e.g. for "UPS Next Day Air" the code is usually `ups_NextDayAir`. You can find your exact codes via `bin/magento dev:di:info Magento\Shipping\Model\Config` or by inspecting checkout in browser dev tools.
2. **Are you testing with an actually-ineligible product?** A simple "Out of Stock" save flips `next_day_eligible` to 0. Verify in the DB:
   ```sql
   SELECT entity_id, value FROM catalog_product_entity_int
   WHERE attribute_id = (SELECT attribute_id FROM eav_attribute WHERE attribute_code = 'next_day_eligible')
   AND entity_id = <your-product-id>;
   ```
3. **Are you on a recognised dev host?** If yes, licensing is bypassed and the module SHOULD work. If you're on production and the license key is wrong, it won't.

### "The module made my checkout crash"

This shouldn't happen — the shipping rates plugin is wrapped in `try/catch` and returns original rates on any exception. If it does:

1. Capture the error from `var/log/exception.log` and `var/log/system.log`
2. Email `support@etechflow.com` with the error + your Magento version + module version

We'll patch within 24 hours.

### "Bulk import is slow"

See the "Bulk imports — the escape hatch" section above. Set `_etechflow_skip_eligibility` on each product before save, then batch-evaluate after the import completes.

## Frequently asked questions

**Does it work with multi-source inventory (MSI)?**
Yes. We use `StockRegistryInterface` which respects whatever stock-source resolution Magento has configured.

**Does it work with Adobe Commerce?**
Yes. The module uses base Magento APIs (no Adobe-Commerce-specific dependencies) so it works identically on both Open Source and Commerce. Tested compatibility with Magento 2.4.8 / Adobe Commerce 2.4.8.

**Does it work on Hyvä themes?**
Yes. We ship Tailwind-styled product page badge variants under `view/frontend/templates/hyva/`. Magento auto-uses the Hyvä variant when the active theme inherits from the Hyvä parent.

**Does the badge appear on category / search / listing pages?**
Not by default — only on the product detail page. The `next_day_eligible` attribute is marked `used_in_product_listing = true` so it's available, but rendering it in listings requires a small template override in your theme.

**Can I customise the badge appearance?**
Yes — override the template at `view/frontend/templates/product/next-day-badge.phtml` (or `view/frontend/templates/hyva/product/next-day-badge.phtml` for Hyvä) in your theme.

**Does it support multi-language stores?**
Yes — the badge labels are translatable via the standard Magento translation CSV (`i18n/en_US.csv`). Add your own `<locale>.csv` files in your theme.

**What's the performance impact?**
- Stock save observer: ~5-15ms per product (one EAV write + possible parent recalc)
- Drop-ship save observer: ~5ms (short-circuits if drop_ship didn't change)
- Checkout shipping rates plugin: ~5-15ms per call (one collection query)
- Product page badge render: ~0.5ms

Negligible on real traffic.

**Can I uninstall cleanly?**
Yes:
```bash
bin/magento module:disable ETechFlow_NextDayEligibility
composer remove etechflow/module-next-day-eligibility
bin/magento setup:upgrade
```
The EAV attributes (`next_day_eligible`, `drop_ship_eligible`) remain in your database by default — we don't drop them so you don't lose any data accidentally. If you want them removed, the patch's `revert()` method handles it manually.
