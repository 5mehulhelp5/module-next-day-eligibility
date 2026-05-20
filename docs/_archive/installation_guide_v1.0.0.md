---
title: "Next Day Eligibility for Magento 2"
subtitle: "Installation Guide — Version 1.0.0"
author: "eTechFlow Pvt. Ltd."
date: "2026"
---

# Next Day Eligibility for Magento 2

**Module:** ETechFlow_NextDayEligibility
**Version:** 1.0.0
**Compatibility:** Magento Open Source & Adobe Commerce 2.4.4 – 2.4.8
**PHP:** 8.1, 8.2, 8.3
**License:** Proprietary
**Support:** info@etechflow.com

---

> **[INSERT IMAGE: 00-cover-logo.png]**
> *Designer note: place the eTechFlow logo on the cover page, centered, with brand purple background. Source file: `_shared_assets/logo.png`*

---

## Table of Contents

1. About this Extension
2. System Requirements
3. Pre-Installation Checklist
4. Installation — Method A: Manual Upload
5. Installation — Method B: Composer
6. Where to Find the Configuration in the Magento Admin
7. Configuration Settings — Field by Field
8. Setting Drop-Ship Eligible Per Product
9. How Eligibility Shows on the Storefront
10. Checkout Behaviour
11. Using This Extension Together With Backorder Shipping Restrictor
12. Uninstallation
13. Troubleshooting
14. Support & Contact

---

## Hyvä Theme Compatibility

✅ **Fully compatible with Hyvä Theme.** All backend logic (observer, plugin, attributes) is theme-agnostic. The product page badge uses self-contained phtml + scoped CSS — no Knockout, jQuery, or Luma dependencies — so it loads cleanly on Hyvä without modifications.

| Component | Luma | Hyvä Theme | Hyvä Checkout |
|---|---|---|---|
| Eligibility logic | ✅ | ✅ | ✅ |
| Drop-Ship attribute | ✅ | ✅ | ✅ |
| Product page badge | ✅ | ✅ | ✅ |
| Shipping restriction | ✅ | ✅ | ✅ |

To use Hyvä's design system fonts/spacing on the badge, override the badge phtml from your Hyvä child theme.

---

## 1. About this Extension

**Next Day Eligibility** automates a Yes/No *Next Day Eligible* attribute on every product based on real-time stock quantity, and restricts configured next-day shipping methods at checkout when any cart item is not eligible.

Products fulfilled by a drop-ship supplier can be exempted from this restriction via a per-product **Drop-Ship Eligible** flag — even when local stock is zero, those products remain next-day eligible because the supplier ships directly.

### Key Features

- Auto-managed `next_day_eligible` attribute, recomputed on stock save and product save
- Per-product `drop_ship_eligible` override for supplier-shipped goods
- Green / grey eligibility badge on the product detail page
- Configurable shipping method codes blocked at checkout when any cart item is ineligible
- Multi-shipping aware — eligibility evaluated per shipping address
- Configurable, grouped, and bundle parents propagate from their child items
- Per-store-view configurable badge labels and method codes

---

## 2. System Requirements

| Component | Supported Version |
|---|---|
| Magento Open Source / Adobe Commerce | 2.4.4, 2.4.5, 2.4.6, 2.4.7, 2.4.8 |
| PHP | 8.1, 8.2, 8.3 |
| Composer | 2.x (only required for Method B) |
| MySQL / MariaDB | As required by your Magento version |
| Search engine | OpenSearch / Elasticsearch (no extra config) |

---

## 3. Pre-Installation Checklist

Complete these steps before extracting any files. They protect your store from broken caches and partial installs.

**Step 1 — Back up your Magento installation.** Take a full backup of `app/code`, `app/etc`, and your database.

**Step 2 — Set Magento to developer or default mode.**
```
php bin/magento deploy:mode:show
php bin/magento deploy:mode:set developer
```

**Step 3 — Disable cache before installing.**
```
php bin/magento cache:disable
```

**Step 4 — Verify maintenance access.** You need SSH or terminal access to the server.

> **Note:** Running `setup:upgrade` while customers are checking out can cause errors on their carts. Schedule the install during a low-traffic window or enable maintenance mode (`php bin/magento maintenance:enable`).

---

## 4. Installation — Method A: Manual Upload

Use this method if you received the module as a `.zip` archive.

**Step 1 — Unpack the archive.** Extract `etechflow-module-next-day-eligibility-1.0.0.zip` on your local machine.

**Step 2 — Create the module directory on your server.**
```
mkdir -p app/code/ETechFlow
```

**Step 3 — Upload the extension files.** Upload the `NextDayEligibility` folder into `app/code/ETechFlow/` so the final path is:
```
app/code/ETechFlow/NextDayEligibility/registration.php
app/code/ETechFlow/NextDayEligibility/etc/module.xml
… (rest of the module files)
```

**Step 4 — Enable the module.**
```
php bin/magento module:enable ETechFlow_NextDayEligibility
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento setup:static-content:deploy -f
php bin/magento cache:flush
```

For multi-language stores, append every locale code: `setup:static-content:deploy -f en_US en_GB`.

> **Done. Skip ahead to section 6 (Where to Find the Configuration).**

---

## 5. Installation — Method B: Composer

**Step 1 — Add the repository (skip if already configured).**
```
composer config repositories.etechflow composer https://repo.etechflow.com
```

**Step 2 — Require the package.**
```
composer require etechflow/module-next-day-eligibility:^1.0
```

**Step 3 — Run the same enable / upgrade commands.**
```
php bin/magento module:enable ETechFlow_NextDayEligibility
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento setup:static-content:deploy -f
php bin/magento cache:flush
```

> **Tip:** If you use Magento Marketplace access keys, place them in `auth.json` at your Magento root before running `composer require`.

---

## 6. Where to Find the Configuration in the Magento Admin

After installation, the module's settings live inside the standard Magento configuration panel. Follow these steps once you've logged in to the admin.

### Step 1 — Open the Stores menu

In the left sidebar of the admin, click the **Stores** icon.

> **[INSERT IMAGE: 01-stores-menu.png]**
> *Caption: The Stores menu in the admin sidebar opens a flyout panel showing Settings, Inventory, Taxes, Currency, and Attributes.*

### Step 2 — Click "Configuration"

Inside the Stores flyout, under the **Settings** column, click **Configuration**.

> **[INSERT IMAGE: 02-configuration-link.png]**
> *Caption: The Configuration link is highlighted in the Settings column of the Stores menu.*

### Step 3 — Find the eTechFlow group in the left sidebar

The Configuration page loads with a left-side accordion of configuration groups (General, Security, Catalog, …). Scroll down until you see **ETECHFLOW** and click it to expand.

> **[INSERT IMAGE: 03-config-sidebar-etechflow-collapsed.png]**
> *Caption: The full Configuration page with collapsed sections in the left sidebar — ETECHFLOW is one of the groups.*

### Step 4 — Click "Next Day Eligibility"

When ETECHFLOW is expanded, you see two links: **Next Day Eligibility** and **Backorder Shipping Restrictor**. Click **Next Day Eligibility**.

> **[INSERT IMAGE: 04-etechflow-expanded-nextday.png]**
> *Caption: The ETECHFLOW group expanded in the left sidebar, with the two module entries "Next Day Eligibility" and "Backorder Shipping Restrictor". Next Day Eligibility is highlighted as the active selection.*

### Step 5 — You are now on the Next Day Eligibility configuration page

The right pane shows two collapsible sections: **General Settings** and **Drop-Ship Exception**. Click each section header to expand it.

> **[INSERT IMAGE: 05-nextday-config-full.png]**
> *Caption: Full Next Day Eligibility configuration page with General Settings and Drop-Ship Exception both expanded, showing every field described in section 7.*

---

## 7. Configuration Settings — Field by Field

### 7.1 General Settings

> **[INSERT IMAGE: 06-nextday-general-settings.png]**
> *Caption: The General Settings section of the Next Day Eligibility configuration, showing four fields.*

| Field | Description | Default |
|---|---|---|
| **Enable Module** | Master on/off switch. | Yes |
| **Next Day Shipping Method Codes** | Comma-separated `carrier_method` codes that should be hidden when any cart item is not eligible. | `flatrate_flatrate` |
| **Label: Next Day Eligible (Yes)** | Green badge text shown on eligible products. | Next Day Eligible |
| **Label: Next Day Eligible (No)** | Grey badge text shown on non-eligible products. | Standard Delivery Only |

> **Finding shipping method codes:** Method codes follow the pattern `carrier_method` (e.g. `flatrate_flatrate`, `ups_NEXTDA`). Inspect the `quote_shipping_rate` table for the exact codes used by your carriers, or read them from **Stores → Configuration → Sales → Delivery Methods**.

### 7.2 Drop-Ship Exception

> **[INSERT IMAGE: 07-nextday-dropship-exception.png]**
> *Caption: The Drop-Ship Exception group with the explanatory note about how drop-ship products bypass the OOS rule.*

This section contains an informational note explaining how the per-product Drop-Ship Eligible flag overrides stock-based eligibility. The actual flag is on the product edit page (see section 8).

---

## 8. Setting Drop-Ship Eligible Per Product

For products that are fulfilled by a supplier (drop-ship), you can mark them as always next-day eligible — even when local stock is zero.

### Step 1 — Open the product

Go to **Catalog → Products** and edit any product.

> **[INSERT IMAGE: 08-catalog-products-grid.png]**
> *Caption: The Catalog → Products grid. Click any product row to open its edit page.*

### Step 2 — Scroll to "eTechFlow Shipping" section

Scroll down the product edit page until you see the **eTechFlow Shipping** attribute group.

> **[INSERT IMAGE: 09-product-edit-etechflow-shipping.png]**
> *Caption: The eTechFlow Shipping section on the product edit page, showing the Drop-Ship Eligible toggle (Yes/No) and Next Day Eligible (read-only).*

### Step 3 — Set Drop-Ship Eligible to Yes and save

Toggle **Drop-Ship Eligible** to **Yes** and click **Save** at the top right.

The next time stock is saved for that product (or immediately on save), the observer re-evaluates eligibility and sets `next_day_eligible = Yes` regardless of local stock level.

**Use case:** Products sourced from a third-party supplier (e.g. Auto Remote) ship the same day directly from the supplier. Setting Drop-Ship Eligible = Yes ensures customers can always choose next-day delivery, even when your local quantity is zero.

---

## 9. How Eligibility Shows on the Storefront

A coloured badge is rendered just below the product price on the product detail page.

### 9.1 Eligible product (green badge)

When a product is in stock with qty ≥ 1, **OR** has `drop_ship_eligible = Yes`, the badge reads "**Next Day Eligible**" (green).

> **[INSERT IMAGE: 10-storefront-badge-eligible.png]**
> *Caption: Storefront product detail page showing the green "Next Day Eligible" badge below the price.*

### 9.2 Non-eligible product (grey badge)

When a product is out of stock AND drop-ship is No, the badge reads "**Standard Delivery Only**" (grey).

> **[INSERT IMAGE: 11-storefront-badge-not-eligible.png]**
> *Caption: Storefront product detail page showing the grey "Standard Delivery Only" badge below the price.*

### 9.3 Eligibility logic

```
next_day_eligible = (qty > 0 AND stock_status = in_stock)
                    OR (drop_ship_eligible = Yes)
```

| Stock state | Drop-Ship Eligible | Result |
|---|---|---|
| In stock with qty ≥ 1 | — | **Eligible** |
| Out of stock or qty = 0 | No | Not eligible |
| Out of stock or qty = 0 | Yes | **Eligible** (supplier ships directly) |

---

## 10. Checkout Behaviour

If any item in the customer's cart has `next_day_eligible = No`, all method codes listed in section 7.1 are removed from the available shipping rates. This applies to the standard one-page checkout, the REST API, and any headless integration.

> **[INSERT IMAGE: 12-checkout-without-nextday.png]**
> *Caption: Checkout shipping step when a non-eligible item is in the cart — the next-day method has been removed; only standard delivery options remain.*

> **Multi-shipping checkout:** When a customer ships items to multiple addresses, each address is evaluated independently. An address with all-eligible items keeps next-day shipping options, even if a different address in the same order has a non-eligible item.

---

## 11. Using This Extension Together With Backorder Shipping Restrictor

If you also install **ETechFlow_BackorderShippingRestrictor**, both modules can work together to give you precise control over which orders qualify for fast shipping. The two modules look at different signals — Next Day Eligibility looks at *stock and drop-ship*; Backorder Shipping Restrictor looks at *current backorder status* — so when a product is OOS-but-drop-ship, the two modules can disagree.

To resolve this cleanly, the Backorder Shipping Restrictor module ships with a **Skip Drop-Ship Products** option that respects the `drop_ship_eligible` flag set by this module.

### Recommended setup when both modules are installed

1. Install both modules following each module's installation guide.
2. Mark all supplier-fulfilled products with **Drop-Ship Eligible = Yes** on their product edit page (section 8).
3. In **Stores → Configuration → eTechFlow → Backorder Shipping Restrictor → General Settings**, set **Skip Drop-Ship Products = Yes**.
4. Save and flush cache.

With this setup, drop-ship products always:

- Show the green "Next Day Eligible" badge on the product page (this module)
- Keep all shipping methods at checkout, even when local stock is zero (Backorder module respects the drop-ship flag)

### Use case — "Auto Remote" supplier

A retailer sells parts. Half the catalog is stocked in their warehouse; the other half is drop-shipped from a supplier called Auto Remote, who ships the same day directly to the customer.

| Without combined config | With combined config |
|---|---|
| OOS local stock + drop-ship product → **grey badge**, **express shipping blocked** by Backorder module → bad UX, lost express revenue | OOS local stock + drop-ship product → **green badge**, **all shipping methods available** → customer gets next-day delivery from supplier |

### Decision matrix

| Cart contents | Skip Drop-Ship = No (default) | Skip Drop-Ship = Yes |
|---|---|---|
| Only in-stock items | All methods visible | All methods visible |
| In-stock + backorder (no drop-ship) | Backorder methods removed, notice shown | Backorder methods removed, notice shown |
| In-stock + backorder (drop-ship Yes) | Methods removed, notice shown | **All methods visible, no notice** |
| Only drop-ship items, all OOS | Methods removed, notice shown | **All methods visible, no notice** |

> **Recommendation:** If you use this Next Day Eligibility module, also turn on **Skip Drop-Ship Products = Yes** in the Backorder module so the two modules agree.

---

## 12. Uninstallation

### Remove product attributes (optional)

If you want to delete the eligibility attributes and their stored data:
```
php bin/magento eav:attribute:remove next_day_eligible
php bin/magento eav:attribute:remove drop_ship_eligible
```

### Disable and remove the module

```
php bin/magento module:disable ETechFlow_NextDayEligibility
composer remove etechflow/module-next-day-eligibility   # Composer install only
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento cache:flush
```

If installed manually, also delete the directory:
```
rm -rf app/code/ETechFlow/NextDayEligibility
```

---

## 13. Troubleshooting

### "Next Day Eligible" badge does not appear
- Hard-refresh the page (Cmd+Shift+R or Ctrl+Shift+R) — your browser may have cached the old page
- Run `php bin/magento cache:flush`
- Verify the module is enabled: `php bin/magento module:status ETechFlow_NextDayEligibility`
- Check `var/log/exception.log` for module-related errors

### Next-day method is still showing despite OOS items
- Verify the method code matches the carrier/method code (e.g. `ups_NEXTDA` not `ups_nextda`) — codes are case-sensitive
- Confirm the OOS product is not flagged **Drop-Ship Eligible = Yes** (that intentionally bypasses the restriction)
- Reindex: `php bin/magento indexer:reindex`

### Eligibility didn't update after I changed Drop-Ship
- The product save observer recomputes eligibility on every save where the drop-ship value changes. If your save did not actually change the value, no recompute runs.
- Force a refresh by saving the stock item (any qty change works).

---

## 14. Support & Contact

| | |
|---|---|
| **Email** | info@etechflow.com |
| **Website** | https://etechflow.com |
| **Module** | ETechFlow_NextDayEligibility 1.0.0 |

### When reporting an issue, please include:

- Magento version and edition (Open Source / Commerce)
- PHP version (`php -v`)
- Module version (from `composer.json` or `etc/module.xml`)
- The relevant excerpt from `var/log/exception.log` or `var/log/system.log`
- Steps to reproduce the issue

---

> © 2026 eTechFlow Pvt. Ltd. — All rights reserved. This document and the associated extension are licensed under the eTechFlow Proprietary License.
