Audience: the owner only (every step here needs the owner's hands, accounts or decisions)
Status: DRAFT checklist; nothing has been done, sent or uploaded by this draft
Date: 2026-10-25

# Revenue launch checklist (free 4.15.0 + Pro push)

Order matters: the free release first (Pro and the sales copy cite it), then the store and Pro, then the announcement.
Each step says how you know it worked. "Blocked on owner" = nobody else can do it.

## 0. Decisions first (blocked on owner)

- [ ] **Pricing response to the market.** Divi Cake states unlimited sites on every licence at a $49/yr or $99 lifetime
      launch price until 8 November 2026; Divi Connect Pro $89/yr for 1,000 sites; DiviOps Studio €149/yr unlimited
      (`competitor-refresh-2026-10-25.md`). Decide whether `layoutlab/config/pricing.json` stays as is. This draft does not
      propose numbers.
- [ ] **Open TODO(owner) items in `docs/pricing-page.md`:** what Lifetime includes; whether the no-card trial keeping tools
      after it ends is acceptable.
- [ ] **What to call "in development"** in public: the post-save render check with rollback is PARKED (`demo-script.md`
      placeholder; say nothing, or "planned", until built). The design switch (`switch_design`) is BUILT and merged to local main
      but NOT released: say nothing until the Pro release ships it (`docs/superpowers/specs/2026-10-25-pro-design-switch-design.md` Outcome).

## 1. Free 4.15.0 on WordPress.org (blocked on owner: SVN password, upload)

Main is 81 commits past 4.14.0 (`git log 0c1773d..HEAD`): render-fatal guards in the validator, type/rhythm/carriers adapters,
wording gaps, dogfood fixes. All unreleased.

- [ ] Decide the release scope (everything on main, or a subset).
- [ ] Bump `wp-plugin/jhmg-ai-editor-for-divi-5.php` (header + `AI_EDITOR_DIVI5_VERSION`) and `wp-plugin/readme.txt` (Stable
      tag + Changelog + Upgrade Notice). Draft lines: `readme-and-store-listing-suggestions.md` A7.
- [ ] Apply the readme suggestions you accept (A1-A6); run `make test` (the compliance test checks readme claims).
- [ ] `bash scripts/build-plugin-zip.sh`; Plugin Check (Docker) on the dev copy AND on the extracted zip; never
      `wp plugin delete` a test copy.
- [ ] Re-run the live gates the release touches (at least `make verify-kits` or its smoke, the recipe render, `verify-shapes`,
      `verify-html-to-divi`); record the numbers in the spec Outcomes.
- [ ] SVN: sync trunk from the tracked zip, commit, `svn copy trunk tags/4.15.0`. Check: the WordPress.org API and the
      download show 4.15.0 and the zip is byte-identical.
- [ ] Merge, push main.

## 2. Directory assets (blocked on owner: review of every image)

- [ ] Regenerate `wporg-assets/` with `scripts/build-wporg-art.cjs` if the Features screen changed; add the new screenshots
      from suggestion A6 (keys and URLs masked).
- [ ] Upload assets to SVN `assets/`. Check the directory page shows them.

## 3. SVN password rotation (blocked on owner)

- [ ] Rotate the WordPress.org SVN password that was given in chat for recent releases (CLAUDE.md
      "Current state" notes it as owner-only after 4.11.0, 4.12.0 and 4.13.0; check whether 4.14.0 too). Do it AFTER the 4.15.0 upload, and do not paste the new
      one into a chat.

## 4. Store and Stripe (blocked on owner: Stripe live keys, Vercel)

`docs/pricing-launch-guide.md` (2026-10-04) has the full steps. The live store already shows the three tiers and the 45-day
trial (checked 2026-10-25), so A and B there were probably done; verify rather than redo:

- [ ] Stripe live: the three yearly prices, the lifetime price and the founding coupon exist; the coupon's redemption limit
      matches `founding.cap`; the webhook has `checkout.session.async_payment_succeeded` and the four subscription events;
      the customer portal allows card updates and cancellation.
- [ ] Vercel production env vars present (`STRIPE_PRICE_AI_EDITOR_*`, `STRIPE_COUPON_AI_EDITOR_FOUNDING`, `CRON_SECRET`).
- [ ] One real purchase with your own card, key email received, then refund and cancel (guide step D9).
- [ ] Update the store page: tool counts, the stale claims (suggestions B1), the new copy (`pro-sales-page-copy.md`), the
      evidence page. Fix the AI Engine row in `docs/pricing-page.md` ($69 Starter, not $79) if that table is reused.

## 5. Pro release (blocked on owner)

- [ ] Decide whether Pro needs a release at all: Pro is 0.12.1 and has no code change since. If 4.15.0 changes nothing Pro
      depends on, no Pro release is needed. Check `pro-addon/tests/FreeApiCompatTest.php` passes against 4.15.0
      (`make test-pro`) and run `make verify-pro-rest` on the dev site.
- [ ] If releasing: build (`make build-pro`), `layoutlab/scripts/release-aied-pro.sh` dry run, then `--confirm`, AFTER the
      free version is live (the compat guard).
- [ ] End-to-end: trial key, download, activate, see all 21 tools (guide section D).

## 6. Announcement (blocked on owner: accounts, voice)

Targets (rules of each community unverified; read each group's rules before posting, many forbid self-promotion except on
set days):

- [ ] Divi-focused Facebook groups (the original feature-request thread came from one: `docs/feature-requests-2026-10-04.md`).
- [ ] Reddit: r/divi, r/Wordpress, r/ClaudeAI, r/mcp (verify each subreddit exists and allows tool posts).
- [ ] Product Hunt launch (prepare the demo video from `demo-script.md`, the evidence page and five screenshots).
- [ ] Divi Marketplace (elegantthemes.com/marketplace): unverified whether it accepts plugins that are free on WordPress.org
      with a paid add-on, and what share it takes; read its vendor terms first. Note the marketplace belongs to the company
      that now sells its own Divi AI Agent.
- [ ] MCP directories (for example lobehub.com/mcp appeared in search; others such as mcp.so or Smithery are unverified):
      list the free plugin with an honest description.
- [ ] divi5lab.com blog post: "We tested every Divi 5 module with wrong values: 92 crash the page" (from
      `docs/render-fatal-sweep.md`; it is a useful, shareable finding on its own, and does not need to name competitors).
- [ ] Newsletter/email to the trial users and existing contacts, if you have consent to email them.

## 7. Reviews

- [ ] The plugin already asks once for a review after 5 saved changes (`ReviewPrompt`, changelog 4.13.0). Do NOT add
      incentives (discounts, Pro keys) for reviews: WordPress.org forbids review manipulation, and an incentive would risk
      the listing. Ask satisfied users in support replies, with the plain link, no reward.
- [ ] Answer every WordPress.org support topic within a few days; unanswered topics hurt conversion more than missing features.

## Risks

| Risk | Why it matters | Mitigation |
|---|---|---|
| Elegant Themes ships an official MCP | Its agent already creates/publishes pages and manages menus and Theme Builder [ET-AI]; a comment reply says "the official MCP is on the way" (unverified) | Position on the proven gate, undo, BYO assistant and WooCommerce tools; keep `make divi-currency` ready for each Divi release |
| Cheaper Divi 5 MCP tools with similar safety claims | Divi Cake, Divi Connect, DiviOps state validation, drafts, undo, dry runs | Lead with evidence (numbers, live renders), not with "we validate" |
| WordPress.org guidelines | Guideline 5 rejected in-plugin licensing before; the readme must not become an upsell; claims must be backed | Only the neutral add-on paragraph and the one Dashboard card; run `WpOrgComplianceTest`; use the suggested softer "broken page" wording |
| Overclaiming | A refund or a public correction costs more than a weaker headline | Every number from `evidence-page.md`; "not stated" is never "they lack it" |
| Divi version drift | A new Divi can change shapes; "verified on" must stay true | Re-gate before claiming a new version |
| SVN credential exposure | The password was given in chat for several releases | Rotate (step 3) |
| Trademark | "Divi" is Elegant Themes' trademark | Keep "Works with Divi 5" and the non-affiliation line on every page and listing |
