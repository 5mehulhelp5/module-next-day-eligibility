# Next Day Shipping Eligibility for Magento 2

**Comprehensive stock-aware shipping restrictions in one module.**

Two independent rules, one admin section, one license:

1. **Next-day eligibility** (auto-managed) — removes configured next-day shipping methods from checkout when any cart item is out of stock or otherwise ineligible. Eligibility is auto-maintained by a stock observer; nothing for the merchant to flip per product.
2. **Backorder express restriction** (opt-in, v1.1.0+) — removes any express methods you list when the cart contains backorder items (out of stock with backorders enabled, or partially short). Useful for merchants who explicitly flag pre-order / made-to-order products and don't want them sold with express delivery.

Both rules raise a single dismissible checkout banner with merchant-customisable wording. Both respect the Drop-Ship Eligible exemption.

Stock-aware. Drop-ship aware. Hyvä compatible. Works with Magento Open Source and Adobe Commerce.

---

## What's new

The version-by-version history lives in `CHANGELOG.md`. Highlights of the most recent releases:

- **v1.4.0** — New per-product `Force Standard Shipping Only` flag. Tick it on the product edit page (under *eTechFlow Shipping*) to hard-disable next-day shipping for that product regardless of stock state. For bulky / hazmat / fragile / made-to-order items. Ships with a CLI verification command: `bin/magento etechflow:nde:verify --sku=<sku>` runs an end-to-end check that the observer + evaluator pipeline is wired correctly.
- **v1.3.0** — Module status banner at the top of admin config (shows whether the module is actually active), PDP badge visibility toggle, drop-ship grid filter, inline tooltips on every field.
- **v1.2.0** — Shipping method fields are now multi-select dropdowns auto-populated from your active shipping methods — merchants no longer need to know technical codes.
- **v1.1.0** — Absorbed the deprecated `ETechFlow_BackorderShippingRestrictor` module's features as an opt-in "Backorder Express Restriction" toggle. The bundle is now 2 modules (NDE + BackorderEtaDisplay).

---

## What it solves

Merchants who advertise next-day delivery hit the same problem from two directions:

| Scenario | Without the module | With the module |
|---|---|---|
| Customer picks "Next Day" on an out-of-stock item | Order ships a week late, refund + 1-star review | Next-day automatically hidden at checkout; banner explains why |
| Customer picks "Express" on a pre-order / backorder item | Same problem — supplier ETA is two weeks, customer paid £15 for next-day | Express methods automatically hidden when the toggle is enabled |
| Drop-shipped products with zero local stock | Marked out of stock, no Add to Cart button | Stay eligible — supplier ships direct, backorders auto-enabled |
| Customer asks "where is my order?" | Volume support tickets | Banner sets the expectation at checkout |

## Requirements

| | |
|---|---|
| **Magento** | Open Source 2.4.4+ OR Adobe Commerce 2.4.4+ |
| **PHP** | 8.1, 8.2, 8.3, or 8.4 |
| **Compatible themes** | Luma (default) + Hyvä |

## Installation

### Option A — Composer (recommended)

```bash
composer require etechflow/module-next-day-eligibility:^1.1
bin/magento module:enable ETechFlow_NextDayEligibility
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

### Option B — Manual (from zip)

1. Unzip `etechflow-module-next-day-eligibility-1.1.0.zip` into:
   ```
   <magento-root>/app/code/ETechFlow/NextDayEligibility/
   ```
   **The directory MUST be named `ETechFlow` (capital E, capital T, capital F) — case-sensitive on Linux servers.**

2. Enable and set up:
   ```bash
   bin/magento module:enable ETechFlow_NextDayEligibility
   bin/magento setup:upgrade
   bin/magento setup:di:compile
   bin/magento cache:flush
   ```

3. Verify:
   ```bash
   bin/magento module:status | grep NextDayEligibility
   ```

## After install — quick setup

Open the admin and go to **Stores → Configuration → eTechFlow → Next Day Eligibility**.

### Step 1 — License

**License → License Key**: paste the key from your purchase email and save.

> **Don't have a key yet?** Dev/staging hosts are free. Any URL matching `localhost`, `*.test`, `*.local`, `staging.*`, `dev.*`, `*.magento.cloud`, ngrok tunnels, or RFC 1918 IPs runs at full features without a key. For non-standard dev domains, set **License → Production Environment = No** instead.

### Step 2 — Enable the module

**General Settings → Enable Module = Yes** → save.

### Step 3 — Pick your next-day shipping methods

**General Settings → Next Day Shipping Methods** is a multi-select dropdown. The list is auto-populated from your store's currently active shipping methods (the same list shown under Stores → Configuration → Sales → Shipping Methods). Tick the methods you want removed for ineligible carts — typically just your paid next-day or express options. Hold <kbd>Ctrl</kbd>/<kbd>Cmd</kbd> to select multiple. Save.

> **⚠️ Important — keep at least one fallback method unticked.** Don't tick every option. The intended pattern is to tick only your paid express / next-day methods so a standard or free option always stays available for ineligible carts. If you accidentally select everything, a safety net returns the original rates and logs a warning to `var/log/system.log` to prevent a stuck checkout — but that's a guardrail, not a config strategy. See `docs/USER_GUIDE.md` "Configuration trap" for the typical UK setups.

That's the core feature done. Browse to a product detail page — you'll see the green "Next Day Eligible" / grey "Standard Delivery Only" badge under the price.

### Step 4 — (optional) Enable Backorder Express Restriction

If you also want to block express shipping on backorder items:

**Backorder Express Restriction**:
- Set **Restrict Express Methods on Backorder = Yes**
- **Express Methods to Restrict on Backorder** — same multi-select pattern as the next-day field above, but stored independently. Tick whichever methods should disappear when a backorder item is in the cart. Can overlap with or differ from your next-day selection.
- Leave **Skip Drop-Ship Products = Yes** (default) so drop-ship products bypass this rule.

### Step 5 — (optional) Customise the checkout banner

**Checkout Notice**:
- **Show Notice at Checkout = Yes** (default)
- **Notice Style** — warning (amber), info (blue), or error (red)
- **Notice Title** — bold heading, e.g. *"Next day delivery unavailable"*
- **Notice Message** — body copy, e.g. *"One or more items in your cart is not eligible for next day delivery."*

All four fields can be overridden per store view for multi-brand setups.

### Step 6 — (optional) Hide or simplify the product-page badge

**General Settings → Show Badge on Product Page**:
- **Both** (default) — green "Next Day Eligible" + grey "Standard Delivery Only" badges
- **Eligible only** — show the green badge, hide the grey one (recommended if you don't want to draw negative attention to ineligible products)
- **Never** — no PDP badge at all; the shipping restriction at checkout still works

## Per-product overrides (v1.4.0+)

Two checkboxes on the product edit page (under the **eTechFlow Shipping** attribute group) let you override the auto-calculation per product:

| Attribute | What it does | When to use |
|---|---|---|
| **Drop-Ship Eligible** | Always eligible regardless of local stock — supplier ships direct | Products fulfilled by a same-day-shipping supplier |
| **Force Standard Shipping Only** *(new in v1.4.0)* | Always ineligible regardless of stock — only standard shipping shown at checkout | Bulky / hazmat / fragile / made-to-order / promotional items |

Precedence inside the eligibility evaluator:

1. `Force Standard Shipping Only = Yes` → always ineligible (merchant override wins)
2. `Drop-Ship Eligible = Yes` → always eligible
3. Otherwise: stock check (`qty > 0 AND in stock` → eligible)

Both flags work as Magento mass-action targets — see "Tips" below.

## Tips for managing the catalogue

**Bulk-flag drop-ship products.** In *Catalog → Products*, filter the grid (now filterable by Drop-Ship Eligible — new in v1.3.0), tick the products, and use *Actions → Update Attributes → Drop-Ship Eligible = Yes*. Magento applies it to every selected product in one click.

**Bulk-flag force-standard-only products.** Same workflow with the new attribute: filter by *Force Standard Shipping Only = No*, narrow further (e.g. by category = "Furniture" or by weight), tick all, *Actions → Update Attributes → Force Standard Shipping Only = Yes*. Useful for one-pass classification of your bulky-item or hazmat catalog.

**Bulk imports.** If you're using a CSV import or custom script to load thousands of products, set `$product->setData('_etechflow_skip_eligibility', true)` before each save to bypass the per-product observer. Then batch-evaluate after the import completes via `\ETechFlow\NextDayEligibility\Model\EligibilityEvaluator::evaluateById(...)`. See `docs/USER_GUIDE.md` "Bulk imports — the escape hatch" for the full pattern.

**Module status banner.** At the top of the NDE admin config section is a coloured banner showing whether the module is active, idle, or has a licence problem. Read it first if anything seems off — most "I configured it but nothing happens" cases are answered by that banner.

## Documentation

| File | Read when |
|---|---|
| `README.md` (this file) | First — overview + install + quick setup |
| `docs/USER_GUIDE.md` | Full reference: every admin field, every scenario, troubleshooting, known limitations |
| `CHANGELOG.md` | What changed in each version |
| `LICENSE.txt` | Licence terms |

## Support

- **Email:** support@etechflow.com — typically responds within one business day
- **Website:** https://etechflow.com

## License

Proprietary — see `LICENSE.txt`. Licensed per Magento installation, with unlimited dev/staging environments under the same business entity.

To change your production domain (e.g. site migration), email `support@etechflow.com` with your old + new domain and order number. New key issued same business day.
