# Cache & CDN considerations

NDE writes the `next_day_eligible` attribute on product entities whenever
stock state, drop-ship state, or force-standard state changes. For the
storefront to reflect those updates, every cache layer that holds rendered
product HTML has to invalidate or expire.

This page covers what NDE handles automatically and what you (the
merchant) still need to configure.

---

## What NDE handles automatically (v1.6.1+)

After every `next_day_eligible` write — observer fires, plugin fires,
cron sweep, manual CLI — NDE explicitly invalidates the Magento cache tag
for the affected product (`cat_p_<id>`).

That covers:

- **Magento Full Page Cache** (built-in / Varnish): the per-product tag
  is cleaned, so the next storefront request rebuilds the page from
  fresh data. Works with both the built-in FPC backend and Varnish (the
  `CacheInterface` translates tag-cleans into Varnish BAN requests when
  Varnish is configured).
- **Hyvä Theme's tag cache**: same mechanism — cleans by Magento cache tag.
- **Magento's block / collection cache** for that product.

You don't need to run `bin/magento cache:flush` after NDE updates. The
clean is surgical (one product's tag), not a blanket FPC reset.

---

## What NDE can't handle automatically — Cloudflare

If your store sits behind Cloudflare (or any other CDN with HTML caching),
the CDN holds its own cached copy of the page that NDE can't touch.
Magento has zero native Cloudflare awareness — it only knows how to talk
to its own FPC / Varnish.

So when NDE updates `next_day_eligible`:

1. Magento's FPC / Varnish: ✅ invalidated immediately (v1.6.1+)
2. Cloudflare: ⏸ keeps serving the old cached page until its TTL expires
   or the page is manually purged

This means a customer hitting a Cloudflare edge between an NDE update
and the TTL expiry can still see the stale next-day badge / shipping
options. Three ways to fix it permanently, ranked by recommendation:

### Option A — Bypass HTML caching at Cloudflare (recommended)

**What:** configure Cloudflare to NOT cache HTML responses from your
Magento store. Only cache static assets (CSS, JS, images, fonts).

**How:** in the Cloudflare dashboard for your zone:
1. Rules → Page Rules → Create
2. URL pattern: `yourstore.com/*` (or whatever pattern matches your
   product pages — typically `*` covers everything except wp-admin /
   subdomains)
3. Settings:
   - Cache Level: Bypass
   - (Leave other settings default)

Alternative path on Cloudflare's new Cache Rules engine: create a rule
matching `(http.request.uri.path matches ".*\.html" or
http.request.uri.path matches "/[a-z\-_]+.html")` with Cache eligibility
= Bypass cache.

**Trade-off:** Cloudflare no longer accelerates HTML delivery — Magento
FPC / Varnish handles that. You still get Cloudflare's DDoS protection,
WAF, and asset CDN. This is what most Magento stores running CF do.

**Why this is the recommendation:** Magento FPC already gives you the
HTML speed win. Letting Cloudflare cache HTML too is doubling up and is
the root cause of the exact staleness this section is about. Bypassing
HTML caching at the edge eliminates the class of problem entirely.

### Option B — Cloudflare API auto-purge plugin

**What:** install a small CF integration that listens for product saves
(or NDE's evaluator output) and POSTs to Cloudflare's purge API.

**How:** there are existing Magento CF modules (Fastly's official module,
Mageplaza, third-party). NDE doesn't ship one (out of scope), but the
hook pattern is straightforward — listen to
`catalog_product_attribute_update_after`, build a list of affected URLs,
POST to `https://api.cloudflare.com/client/v4/zones/<zone>/purge_cache`.

**Trade-off:** requires merchant to provide CF API token + zone ID in
admin config. Costs CF API quota (free tier is generous — 1200 purges
per day — but a busy store doing thousands of NDE updates per day could
exceed it). Each MSI source-item save triggers up to one purge per
affected product URL.

### Option C — Short TTL on HTML

**What:** keep CF caching HTML, but with a very short cache TTL (e.g.
60 seconds).

**How:** CF dashboard → Caching → Edge Cache TTL → set to 1 minute (or
similar). Or use a Cache Rule with `Edge cache TTL: 1 minute`.

**Trade-off:** simple. Cheap. But customers can still see stale data for
up to 60 seconds after an NDE update — usually acceptable for a low-
volume catalogue, less so for a busy one where every minute of staleness
is multiple wrong orders.

---

## Verify NDE's auto-invalidation is working

After updating a product's `next_day_eligible` via the admin (e.g. tick
"Drop-Ship Eligible: Yes"), check the storefront with a fresh request:

```bash
# Magento FPC / Varnish should serve a fresh page immediately
curl -sI https://yourstore.com/some-product.html | grep -i x-magento
```

Look for `X-Magento-Cache-Debug: MISS` on the first request after the
update — that's evidence FPC built a fresh page. Subsequent requests
will show `HIT` until the next update.

If you keep seeing `HIT` with the old `next_day_eligible` value after
an update, double-check:

1. Module version is `>= 1.6.1` (run `bin/magento module:status
   ETechFlow_NextDayEligibility`)
2. `bin/magento cache:status` shows `full_page` enabled
3. Varnish (if used) is configured to accept BAN requests from your
   Magento server's IP

---

## Quick-reference deploy / config checklist

When upgrading to NDE v1.6.1+:

- [x] Composer update — `composer update etechflow/module-next-day-eligibility`
- [x] setup:upgrade + setup:di:compile + setup:static-content:deploy
- [x] cache:flush (last time you'll need this for NDE updates)
- [x] One-shot cleanup: `bin/magento etechflow:nde:resync`
- [ ] (optional but recommended) Configure Cloudflare per Option A above
- [ ] Confirm the hourly cron is running: `bin/magento cron:status` (or
      check `crontab -l` on the host)

After this, NDE updates are end-to-end automatic up to the FPC / Varnish
layer. Cloudflare is your responsibility per the strategy you chose.
