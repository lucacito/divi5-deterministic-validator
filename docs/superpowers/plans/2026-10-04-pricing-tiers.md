# Pro pricing tiers Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Sell the AI Editor for Divi 5 Pro as annual per-site-count licences (Personal / Freelancer / Agency, founding offer, capped Lifetime), enforce site limits on the licence server, show tier/sites/expiry in the Pro add-on, never disable features on expiry, and make divi5lab.com about this plugin.

**Architecture:** divi5lab.com (repo `layoutlab`, Next.js + Drizzle + Stripe) is the only place a rule can be enforced: the add-on is GPL and editable, so it displays and obeys the server verdict. One JSON file, `config/pricing.json` in layoutlab, holds every price, tier, site limit and cap; the store, checkout, webhook, pricing page, product page and the draft doc read it. The add-on carries NO price: it shows the tier label, limits and upgrade URL the server returns. The shared PHP licence client stays untouched (five other plugins use it); the add-on adapter captures the raw activate/validate JSON through the `http_response` filter, as it already does for verdicts.

**Tech Stack:** Next.js 15 / TypeScript / Drizzle (Postgres) / Stripe / Vitest (layoutlab); PHP 8.1 / PHPUnit (pro-addon).

**Spec:** the owner's pricing brief of 2026-10-04 (in the conversation) and `docs/superpowers/specs/2026-10-02-pro-addon-design.md`. Exploration report: WP1 of this session.

## Global Constraints

- Free plugin `wp-plugin/` does NOT change at all (WordPress.org Guideline 5).
- The validator is deterministic; every layout write still goes through `LayoutGate::check`. Nothing in this plan touches layout writes.
- Prices (cents, per year): Personal 4900 (1 site), Freelancer 9900 (10 sites), Agency 14900 (unlimited). Founding: 30% off any tier for the first 100 buyers, discounted renewal price locked while the licence stays active. Lifetime: Agency/unlimited only, 44900, cap 50 then removed. Upgrades between tiers prorated for the remaining term. Renewal reminders 30 and 7 days before expiry. These numbers appear ONLY in `layoutlab/config/pricing.json`.
- An expired, cancelled or past-due licence never disables any Pro tool (all 14 keep working). Only `revoked` / `invalid` lock. Updates and support stop. One dismissible admin notice.
- Trial: 45 days, Personal tier, NO card up front (owner's ruling 2026-10-04). Ending the trial cancels the licence; tools stay on already-activated sites (consequence accepted by the owner's "expired never disables" rule).
- A new site cannot activate an expired key (owner's ruling).
- One Stripe product / licence product id `ai-editor-divi5-pro`; tier is derived from the Stripe Price id and stored on the licence row.
- Never type backslash-u escape sequences in tool calls or files. Every `wp` command in the container needs `php -d memory_limit=1G`. No push, no deploy, no prod DB, no live Stripe from any task: the lead does the release gates.
- Co-author trailer on every commit: `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.

## The server contract (both sides build against this)

`config/pricing.json` (layoutlab), exact shape:

```json
{
  "version": 1,
  "product": "ai-editor-divi5-pro",
  "currency": "USD",
  "tiers": [
    { "id": "personal",   "label": "Personal",   "sites": 1,    "priceCents": 4900,  "priceEnv": "STRIPE_PRICE_AI_EDITOR_PERSONAL" },
    { "id": "freelancer", "label": "Freelancer", "sites": 10,   "priceCents": 9900,  "priceEnv": "STRIPE_PRICE_AI_EDITOR_FREELANCER" },
    { "id": "agency",     "label": "Agency",     "sites": null, "priceCents": 14900, "priceEnv": "STRIPE_PRICE_AI_EDITOR_AGENCY" }
  ],
  "founding": { "percentOff": 30, "cap": 100, "couponEnv": "STRIPE_COUPON_AI_EDITOR_FOUNDING" },
  "lifetime": { "tier": "agency", "priceCents": 44900, "cap": 50, "priceEnv": "STRIPE_PRICE_AI_EDITOR_LIFETIME" },
  "trial": { "tier": "personal", "days": 45, "requireCard": false },
  "renewalReminderDays": [30, 7],
  "pastDueGraceDays": 7,
  "urls": { "pricing": "/pricing", "account": "/account/licenses" }
}
```

`sites: null` = unlimited. `licenses` gains: `tier text null` (null = legacy, unlimited, no limit fields returned), `founding boolean not null default false`, `lifetime boolean not null default false`. A lifetime licence has `currentPeriodEnd = null`, `stripeSubscriptionId = null`, `status = active`.

HTTP bodies for product `ai-editor-divi5-pro` with a non-null tier (other products unchanged):

- `POST /api/license/activate` and `/validate` 200: `{ status, product, expires, tier, tier_label, sites_used, sites_allowed, founding, lifetime, upgrade_url, manage_url }`. `expires` is null for lifetime. `sites_allowed` is null for unlimited. `upgrade_url` = `<origin>/pricing`, `manage_url` = `<origin>/account/licenses`.
- New site over the limit (activate AND validate; validate must no longer create an activation for a new site beyond the cap): HTTP 403 `{ "error": "site_limit_reached", tier, tier_label, sites_used, sites_allowed, upgrade_url, manage_url }`. A site that already has an active activation always succeeds. Re-activating a deactivated site counts as new.
- `license_not_usable`, `invalid_key`, `product_mismatch`, 429 unchanged.
- `sites_used` counts active (not deactivated) activations including the one just recorded.

Checkout: `POST /api/checkout` `{ kind: 'plugin', product, tier?: 'personal'|'freelancer'|'agency', lifetime?: true }`. For `ai-editor-divi5-pro`, `tier` is required unless `lifetime`. Unknown or unconfigured tier price env: 400 `plugin_unavailable`. Session metadata: `{ kind:'plugin', product, tier, founding:'1'|'0', lifetime:'1'|'0' }` (also on `subscription_data.metadata`). Founding: applied automatically when `count(licenses where founding) < cap` and the coupon env is set: `discounts: [{ coupon }]`; Stripe's coupon redemption limit (100) is the hard stop; if session creation fails because of the coupon, retry without it and with `founding:'0'`. Founding does not combine with `allow_promotion_codes` (Stripe rejects both), so for founding sessions omit `allow_promotion_codes`.

Webhook: `checkout.session.completed` mints the licence with `tier`, `founding`, `lifetime` from the metadata. Lifetime sessions are `mode: 'payment'`. `customer.subscription.updated` for a plugin sub re-derives the tier from the item's Price id (`tierForPriceId`) and updates the row.

## Review Focus

- A site with an existing active activation must keep validating when the licence is at its limit (no false lock-out).
- Deactivate then activate the same site must not leak a slot (unique `(licenseId, siteUrl)` row is reused).
- `www.` / trailing-slash / scheme variants of one site count once (existing `normalizeSiteUrl`).
- Two simultaneous activations at limit-1 must not both succeed (count + insert in one transaction, or a conditional insert).
- Founding and lifetime caps at the boundary: the 100th founding / 50th lifetime sell, the next does not; a failed coupon never blocks a purchase.
- Tier downgrade attempt through the upgrade route is refused; an upgrade on a lifetime or cancelled licence is refused.
- Plugin: a garbled / HTML / missing-field validate answer never changes the displayed tier, never locks, never throws.
- Plugin: a 403 `site_limit_reached` on refresh is shown, never locks the tools.
- Plugin: the lapse notice is shown once, can be dismissed, stays dismissed for that lapse, and returns for a later one.
- No price literal exists outside `config/pricing.json` (test greps both repos).

---

## Task 1 (layoutlab): pricing config and loader

**Files:** Create `config/pricing.json`, `lib/pricing/config.ts`, `tests/pricing-config.test.ts`.

**Interfaces — Produces:**
```ts
export type TierId = 'personal' | 'freelancer' | 'agency';
export interface Tier { id: TierId; label: string; sites: number | null; priceCents: number; priceEnv: string }
export const PRICING: PricingConfig;               // parsed config/pricing.json, validated with zod at import
export function getTier(id: string): Tier | undefined;
export function tierSiteLimit(id: string | null): number | null; // null id or unlimited -> null
export function formatUsd(cents: number): string;                // 4900 -> "$49"
export function proratedUpgradeCents(oldCents: number, newCents: number, remainingMs: number, periodMs: number): number; // max(0, round((new-old) * remaining/period)), clamps remaining to [0, period]
```
- [ ] Write failing tests: config parses; tiers ascending by price; agency is unlimited; `getTier('nope')` undefined; `formatUsd(14900) === '$149'`; `proratedUpgradeCents(4900, 9900, half, full) === 2500`; zero remaining -> 0; remaining > period clamps to full difference; new <= old -> 0.
- [ ] Implement; `npx vitest run tests/pricing-config.test.ts` green; commit `feat(pricing): central pricing config`.

## Task 2 (layoutlab): schema and migration

**Files:** Modify `db/schema.ts` (licenses: `tier`, `founding`, `lifetime`); generate `db/migrations/0013_*.sql` with `npm run db:generate`; add table `license_reminders` (`license_id` text fk cascade, `days` integer, `period_end` timestamp, `sent_at` timestamp default now, unique `(license_id, days, period_end)`); test `tests/license-schema.test.ts` extended.

- [ ] Failing schema test for the new columns/table, implement, generate the migration (additive only, defaults set, no data rewrite), commit. Do NOT run migrate against any database other than a throwaway local one.

## Task 3 (layoutlab): activation cap and verdict fields

**Files:** Modify `lib/license-server/core.ts` (`LicenseRecord` gains `tier: string | null; founding: boolean; lifetime: boolean`), `handlers.ts` (`LicenseStore` gains `countActiveActivations(licenseId): Promise<number>` and `upsertActivationWithinLimit(a, limit: number | null): Promise<{ ok: true; used: number } | { ok: false; used: number }>`; keep `upsertActivation` for products without a tier), `store.ts` (Drizzle implementation with a transaction: lock the license row `for update`, count active, insert/reactivate only if the site is already active or `used < limit`), `getLicensesForUser` (add tier, founding, lifetime, activeSites unchanged). Tests: `tests/license-handlers.test.ts` (existing in-memory store updated), new cases per Review Focus.

**Interfaces:** `licenseBody(l, now, used, origin)` adds the contract fields only when `l.productSlug === PRICING.product && l.tier`. Handlers take `origin: string` (the store's public origin) as a new last-but-one parameter via an options object `{ now?: Date; origin: string }`; routes pass `env.NEXT_PUBLIC_SITE_URL`.

- [ ] Failing tests: Personal at 1 site: second distinct site -> 403 site_limit_reached with used 1/allowed 1; same site validates fine; deactivate then another site activates; Freelancer 10th ok, 11th refused; Agency and lifetime and legacy (tier null) never refused; validate of a NEW site over the limit refused and creates no row; `www.`/slash variants count once; expired key still `license_not_usable`; converter products' bodies unchanged (no new keys); lifetime body has `expires: null`.
- [ ] Implement; whole `npx vitest run` green; `npx tsc --noEmit`; commit.

## Task 4 (layoutlab): checkout, founding, lifetime, trial, webhook, upgrade

**Files:** Modify `lib/stripe/checkout.ts`, `app/api/checkout/route.ts`, `lib/stripe/fulfillment.ts`, `lib/stripe/fulfillment-store.ts`, `lib/env.ts` (+ `.env.example`: the four price envs, lifetime price env, founding coupon env, `CRON_SECRET`), `scripts/stripe-plugin-products.ts` (create the three yearly Prices + the lifetime one-time Price from PRICING for slug `ai-editor-divi5-pro`, and the founding coupon: `percent_off` from config, `duration: forever`, `max_redemptions` = cap, `applies_to` the product; print env lines; idempotent), create `lib/pricing/stripe.ts` (`tierForPriceId(priceId, env)`, `priceIdForTier`, `foundingAvailable(count)`, `lifetimeAvailable(count)`), create `app/api/billing/change-tier/route.ts` (authenticated; body `{ licenseId, tier }`; refuses non-owner, lifetime, cancelled, same or lower tier; `stripe.subscriptions.update(subId, { items:[{ id: itemId, price }], proration_behavior: 'always_invoice' })`; returns `{ ok, tier }`), create `lib/license-server/slots.ts` + `app/api/license/free-site/route.ts` (authenticated owner frees one site: `markDeactivated`). Tests alongside in `tests/`.

- [ ] Failing tests with a mocked Stripe client for: trial only on personal with `payment_method_collection: 'if_required'` when `requireCard` is false and never on other tiers or lifetime; lifetime session is `mode: 'payment'` with no `subscription_data`; founding adds the discount and metadata only below the cap and drops `allow_promotion_codes`; coupon failure retries without; webhook mints tier/founding/lifetime; subscription update re-derives tier; payment-mode webhook mints a lifetime licence with null period end; `change-tier` refusals; free-site refuses a non-owner.
- [ ] Implement, `npx vitest run`, `npx tsc --noEmit`, commit per concern.

## Task 5 (layoutlab): renewal reminders

**Files:** Create `app/api/cron/renewal-reminders/route.ts` (GET, `Authorization: Bearer ${CRON_SECRET}` required, else 401), `lib/license-server/reminders.ts`, `lib/email/renewal-reminder.ts`, `vercel.json` cron entry (daily 09:00 UTC), tests.

- [ ] Failing tests: pure `dueReminders(licenses, now, days=[30,7])` picks active subscription licences of `PRICING.product` whose period end is within each window and not yet recorded in `license_reminders`; never lifetime, never cancelled/expired, never other products; each `(license, days, periodEnd)` sent once; a renewed period end (new date) is eligible again; email text states the date, tier, that features keep working if not renewed, and the manage link. Route 401 without secret.
- [ ] Implement; commit.

## Task 6 (layoutlab): account page

**Files:** Modify `app/(account)/account/licenses/page.tsx` + create `components/account/LicenseTierCard.tsx`: tier label, "sites used of allowed", each active site with a "Free this slot" button (calls `/api/license/free-site`), lifetime/founding badges, upgrade buttons for higher tiers showing `formatUsd(proratedUpgradeCents(...))` "due today (approx.)" and calling `/api/billing/change-tier`. Tests: render tests with the existing testing-library setup.

## Task 7 (layoutlab): storefront refocus on the AI Editor

**Files:** Modify `lib/site/pro-status.ts` (set `AI_EDITOR_PRO_AVAILABLE = true`), delete `tests/ai-editor-pro-pause-guard.test.ts`, create `tests/pricing-literals.test.ts` (greps `app components content lib` for `$49|$99|$149|$449|4900|9900|14900|44900` outside `config/pricing.json` and the tests, and fails on any hit), modify `components/plugins/BuyProButton.tsx` (props `tier?`, `lifetime?`), `app/(catalog)/pricing/page.tsx` (AI Editor leads: three tier cards + lifetime + founding banner with "N of 100 left" from the DB count; converters move below under "Converters"), `app/(marketing)/plugins/divi-5-ai-editor/page.tsx` (rewrite: value-first, free plugin + Pro add-on described accurately from `pro-addon/README.md` and `pro-addon/readme.txt` in the validator repo: 16 free tools, 14 Pro tools, undo, Theme Builder, whole-site build, validator gate, works with Claude/ChatGPT/Cursor/VS Code, no metered AI credits; FAQ: expiry, GPL and what the licence pays for, sites, upgrading, trial), `app/(marketing)/page.tsx` (homepage), `components/marketing/ProductDoors.tsx`, `lib/nav/menu-data.ts`, `app/llms.txt/route.ts`, `lib/seo/jsonld` call sites (Offers from PRICING), `app/(marketing)/about`, metadata/OG copy, `lib/email/license-email.ts` ("unlimited sites" -> per-tier), `scripts/stripe-plugin-products.ts` description text. Buy buttons render only when the tier's `priceEnv` is set on the server; otherwise a waitlist form (`WaitlistForm`) so nothing 400s before the owner creates the Stripe prices.
- Converters keep their pages and prices unchanged (their $25 literals are not part of this config and are out of scope for the literal test: the test only targets the AI Editor amounts).
- Refund wording stays as the existing `REFUND_POLICY` (owner will revise); do not invent a new policy.
- [ ] Tests: pricing page lists three tiers from config; no buy button when env missing; JSON-LD offers equal config; homepage first product door is the AI Editor; nav lists it first. Full `npx vitest run` and `npx tsc --noEmit` green; commit.

## Task 8 (pro-addon): adapter, tab, notice

**Files:** Modify `pro-addon/src/Licensing.php`, `pro-addon/src/ProPage.php`, `pro-addon/src/Plugin.php`; create `pro-addon/src/Licensing/LicenseExtras.php` (parse and sanitise the captured body), `pro-addon/src/Admin/LapseNotice.php`; tests `LicenseExtrasTest.php`, `LapseNoticeTest.php`, extend `LicensingTest.php`, `ProPageTest.php`, new `ExpiryKeepsAllToolsTest.php`, `NoPriceLiteralsTest.php`; docs/version in Task 9.

**Interfaces — Produces:**
```php
final class LicenseExtras {
    /** @return array{tier:?string,tier_label:?string,sites_used:?int,sites_allowed:?int,founding:bool,lifetime:bool,upgrade_url:?string,manage_url:?string} */
    public static function fromBody(mixed $decoded, string $apiBase): array; // missing/mistyped fields -> null/false; URLs kept only if https and on the licence host
    public static function empty(): array;
}
Licensing::extras(): array;        // stored option aied_pro_license_extras, LicenseExtras::empty() when none
Licensing::siteLimitHit(): bool;   // last answer was 403 site_limit_reached (option aied_pro_site_limit)
```
Capture rule: in `activate()` and `refresh()` add the `http_response` filter for `/api/license/activate` and `/api/license/validate`. A 200 whose JSON has a string `status` stores the extras (replacing the old ones) and clears `aied_pro_site_limit`. A 403 with `error === 'site_limit_reached'` stores the extras from that body and sets `aied_pro_site_limit`; it never changes the licence state or the unlock. Anything else leaves the extras alone. `clear()` and `deactivate()` delete both options.
- `ProPage` licence card shows: status label, tier label (if any), "Sites: N of M" or "Sites: N (unlimited)", "Lifetime" instead of an expiry date, "Founding price locked" badge, expiry date, an Upgrade link (`upgrade_url`), a Manage sites link (`manage_url`), and the site-limit message when `siteLimitHit()`. `failureNotice('site_limit_reached')` -> notice key `site_limit` with text naming the limit and pointing to upgrade/manage. No price anywhere.
- `LapseNotice`: replaces the shared client's `status_notice` hook (remove that `admin_notices` action from `Plugin::wire`). Shows one `notice-warning is-dismissible` on admin screens for users with `manage_options` when the status is `expired`, `canceled` or `past_due` (or an update is blocked): "Your licence has ended. Everything keeps working; updates and support stop. Renew to get them back." with a Renew link. Dismissal: admin-post handler with nonce, stored in user meta `aied_pro_lapse_dismissed` = the lapse key (`status|expires`); a different lapse key shows it again. Not shown when there is no key.
- Tests: for each of `expired`, `canceled`, `past_due`, and a lifetime state, `ToolRegistry` lists all 14 tools and `claim()` runs one; `NoPriceLiteralsTest` scans `pro-addon/src` and `pro-addon/readme.txt` for `\$\s?\d` and the amounts 49/99/149/449 as prices, and fails on any.

## Task 9 (pro-addon): version, docs, state

Version bump to the owner-ruled **0.6.0** (header + `AIED_PRO_VERSION` + readme Stable tag + Changelog), `pro-addon/README.md`, `CLAUDE.md` Current state, `ProSafetyTest` unaffected, memory notes. Rebuild `dist/` zip with `make build-pro`.

## Task 10 (research): `docs/pricing-page.md` draft

Draft only, tokens instead of prices (`{{price:personal}}`, `{{sites:freelancer}}`, `{{cap:founding}}`, `{{price:lifetime}}`, `{{cap:lifetime}}`), a comparison table versus AI Engine Pro, WPVibe and Divi Pro with only facts verified by web search, each "as of October 2026" with the source URL in an HTML comment, `TODO(owner)` markers where unverifiable, FAQ (expiry, refund policy `TODO(owner)`, GPL and what the licence pays for, upgrading tiers).

## Task 11 (release gates, lead only)

Run all suites; whole-branch opus review of both repos; one fix wave; merge to local main in both repos; then the owner-gated steps in order: prod migration, Stripe live products/prices/coupon, Vercel env, push + deploy, Pro zip release. Each production-affecting step is confirmed with the owner right before it runs.
