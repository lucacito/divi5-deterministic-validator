# Pricing launch: what you do, step by step

State when this was written (2026-10-04): the new store code is merged on layoutlab's local `main`, the production
database migration is DONE, and the Pro add-on 0.6.0 zip is built. Nothing is deployed yet and no Stripe objects exist.

Order matters: **A (deploy) -> B (Stripe) -> C (publish the add-on) -> D (check it works end to end).**
Each step says what you should see, so you know it worked.

---

## A. Deploy the store code

1. In a terminal: `cd "/Users/Lucas/Documents/JHMG-Local/layoutlab"`.
2. Check: `git status -sb` shows `main` ahead of `origin/main` and nothing else except `public/banner-youtube-divi5lab.jpg`
   (an untracked file of yours; leave it).
3. `git push origin main`. Vercel starts a production deployment by itself.
4. Open Vercel, project `divi5lab`, Deployments. Wait for the newest one to say **Ready**.
5. Check the site: open `https://divi5lab.com/pricing`. Until step B is done you will see "Coming soon" instead of buy
   buttons (that is intended: no Stripe prices exist yet).
6. Check the licence API still answers for the converters:
   `curl -s -X POST https://divi5lab.com/api/license/validate -H 'content-type: application/json' -d '{"key":"JHMG-XXXX-XXXX-XXXX-XXXX","site_url":"https://x.example.com","product":"elementor-to-divi5-pro"}'`
   Expect `{"error":"invalid_key"}` (a 404). Anything with `500` means stop and tell me.

## B. Stripe (live mode): create the prices and wire the site to them

You need your **live** secret key (Stripe Dashboard -> Developers -> API keys; the one starting `sk_live_`). Never paste
it in a chat or commit it.

1. Create the products, prices and the founding coupon (one command, safe to run twice):
   ```
   cd "/Users/Lucas/Documents/JHMG-Local/layoutlab"
   STRIPE_SECRET_KEY=sk_live_xxx npx tsx scripts/stripe-plugin-products.ts
   ```
   It prints lines like `STRIPE_PRICE_AI_EDITOR_PERSONAL=price_...`. Expect **six** AI Editor lines: three yearly plans,
   one lifetime price, one coupon (`STRIPE_COUPON_AI_EDITOR_FOUNDING=ai-editor-divi5-pro-founding`), plus the converter lines.
   If it prints `WARNING: existing yearly price ... is 3000c`, that is the old $30 AI Editor price: ignore it for now.
2. In Stripe Dashboard -> Product catalogue, open **AI Editor for Divi 5 - Pro**. Archive the old yearly price from the
   earlier plan if one is listed (the new prices are separate and have the plan names in their lookup keys).
3. In Vercel -> project -> Settings -> Environment Variables, add these for **Production** (values from step 1):
   `STRIPE_PRICE_AI_EDITOR_PERSONAL`, `STRIPE_PRICE_AI_EDITOR_FREELANCER`, `STRIPE_PRICE_AI_EDITOR_AGENCY`,
   `STRIPE_PRICE_AI_EDITOR_LIFETIME`, `STRIPE_COUPON_AI_EDITOR_FOUNDING`.
   Also add `CRON_SECRET` with a random value: run `openssl rand -hex 32` and paste the result. (Vercel Cron sends it
   automatically; it is what lets the renewal-reminder job run.)
4. Redeploy so the site picks them up: Vercel -> Deployments -> the latest -> menu -> **Redeploy**.
5. Stripe Dashboard -> Developers -> Webhooks -> your existing endpoint for `https://divi5lab.com/api/stripe/webhook`
   -> **Add events**: add `checkout.session.async_payment_succeeded`. Confirm these are already listed too:
   `checkout.session.completed`, `customer.subscription.created`, `customer.subscription.updated`,
   `customer.subscription.deleted`.
6. Stripe Dashboard -> Settings -> Billing -> **Customer portal**: switch on "Customers can update payment methods",
   "Customers can cancel subscriptions" and "invoice history". (The site's billing page opens this portal.)
7. Check: reload `https://divi5lab.com/pricing`. You should see three plans with buy buttons, the Lifetime card, the
   founding banner "N of 100 left" and the trial button.

## C. Publish the Pro add-on so customers can download it

1. Dry run first (checks everything, publishes nothing):
   `cd "/Users/Lucas/Documents/JHMG-Local/layoutlab" && bash scripts/release-aied-pro.sh`
   Expect `version: 0.6.0`, a database line and `DRY RUN: nothing published`.
2. Publish: `bash scripts/release-aied-pro.sh --confirm`. Expect an update-check answer at the end that says
   `"update":true` and `"version":"0.6.0"`.
3. The zip can only be downloaded with a valid licence key (from `/account/licenses` or by the WordPress updater).
   The old public free-download route is switched off on purpose, so nobody can fetch the paid zip without a key.

## D. The real end-to-end check (about 20 minutes)

You need a WordPress site with the free plugin active (your Docker dev site at `http://localhost:8181` works).

1. **Get a licence key without paying:** on `https://divi5lab.com/pricing` press **Start the 45-day trial** and finish
   Checkout with your email (no card asked). Within a minute an email arrives with a key like `JHMG-XXXX-XXXX-XXXX-XXXX`.
   It says "Personal licence covers 1 site".
2. **Download the add-on:** sign in at `https://divi5lab.com/login` with that email (a magic link arrives), open
   **Account -> Licences**, press **Download Pro**. You get `ai-editor-divi5-pro-0.6.0.zip`.
3. **Install it:** WordPress -> Plugins -> Add New -> Upload Plugin -> choose the zip -> Install -> Activate.
4. **Activate the licence:** wp-admin -> AI Editor -> **Pro** tab -> paste the key -> Activate licence.
   You should see "Licence activated", the plan **Personal**, **Sites: 1 of 1**, an expiry date, and the list of 14 Pro tools.
5. **Check the site limit:** on a second WordPress site (or by changing the URL), install the same zip and paste the same
   key. You should get the message that the licence is already on all of its sites, and no Pro tools.
6. **Free a slot:** Account -> Licences -> **Free this slot** next to the first site. Activate on the second site again;
   it now works.
7. **Check expiry is gentle:** Stripe Dashboard -> Customers -> that customer -> the subscription -> Cancel immediately.
   On the first WordPress site run
   `docker compose exec -T wpcli php -d memory_limit=1G /usr/local/bin/wp eval 'AiEditorDivi5\Pro\Licensing::refresh(true);'`
   (or just wait a day). The Pro tab now says the licence has ended, **all 14 tools are still listed**, and one dismissible
   notice appears in wp-admin. Dismiss it: it stays gone.
8. **Confirm the server's answers match what the add-on expects:**
   `curl -s -X POST https://divi5lab.com/api/license/validate -H 'content-type: application/json' -d '{"key":"YOUR-KEY","site_url":"https://your-site.example","product":"ai-editor-divi5-pro"}'`
   For a live licence expect `status`, `expires`, `tier`, `tier_label`, `sites_used`, `sites_allowed`, `founding`, `lifetime`,
   `upgrade_url`, `manage_url`. For an ended one: `{"error":"license_not_usable","status":"canceled"}` (a 403).
9. **A real purchase, once:** buy Personal with your own card (the founding coupon applies by itself), check the key
   email, then in Stripe refund the payment and cancel the subscription. This proves money flows end to end.

If any step does not show what it says, stop there and send me the step number and what you saw.
