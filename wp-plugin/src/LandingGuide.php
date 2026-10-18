<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AI-facing CONVERSION blueprint for a single landing page. Where StyleGuide
 * teaches "how to make it valid + styled" and SiteGuide teaches "how to wire a
 * multi-page site", this teaches "how to make a page that SELLS" — the strategic
 * persuasion structure an agency uses so every section earns its place and moves
 * the visitor toward one action.
 *
 * Served by the get_landing_guide tool and GET /landing-guide. Pure — testable.
 */
final class LandingGuide
{
    public static function markdown(): string
    {
        return <<<'MD'
# Divi 5 Landing Page Conversion Blueprint

A landing page is not a pretty brochure — it is a single argument that moves one
visitor toward one action. Build every page as a persuasion flow: each section
answers the next question in the visitor's head and removes one more reason to
leave. Pair this with get_style_guide (how to style it), get_section_recipes
(proven section markup to fill), and get_image_guide (a relevant, role-appropriate
image per section). The goal: pages that look agency-built AND are
structured to convert.

## Step 0 — Decide before you build (template intelligence)
Never start laying out blocks until you can answer three things from the brief.
If the brief doesn't say, infer the most likely answer and state your assumption
in the copy choices.
1. **Business type** — SaaS, agency/service, local business (restaurant, clinic,
   gym), e-commerce/product, course/coaching, event, portfolio, non-profit…
2. **Target audience** — who they are, their job/role, their sophistication.
3. **Conversion goal** — ONE primary action (book a call, start free trial, buy,
   sign up, request a quote, reserve). Everything bends toward this one goal.

The structure, copy, CTA wording, and visual tone all adapt to these. A SaaS trial
page, a restaurant reservation page, and an agency lead page are NOT the same
skeleton:
- **SaaS**: hero → problem → solution/product → benefits → how it works →
  social proof (logos + stats) → feature detail → FAQ → final CTA (start trial).
- **Service / agency**: hero → problem → outcomes/benefits → process (how it
  works) → testimonials/case studies → about/credibility → FAQ → final CTA
  (book a call).
- **Local business**: hero (offer + location) → why us → services → gallery →
  reviews → hours/location → final CTA (reserve / call now).
- **Product / e-commerce**: hero (product shot + value) → problem → benefits →
  features → reviews → guarantee → FAQ → final CTA (buy now).
- **Course / coaching**: hero (transformation promise) → pain → method → what you
  get/curriculum → results/testimonials → instructor → FAQ → final CTA (enroll).
Reorder and drop sections to fit — but keep the spine: **attention → problem →
solution → proof → action.**

## The conversion flow (in order)
Each item: its job and where to start. `role=<role>` means call get_section_recipes
{"role":"<role>"} and pick by layout and mood; the named recipes are good first picks
(fetch one with {"name":"…"}). Skip a section only when the brief makes it irrelevant.

1. **Hero — "Am I in the right place?"** `role=hero` (e.g. `hero-split-right`,
   `hero-image-scrim`); an inner page opens with `role=header`.
   First viewport must say WHAT is offered, WHO it's for, WHY it matters, and the
   ONE action. Include: benefit headline, supporting sub-paragraph, primary CTA,
   a relevant visual/mockup, and (if available) a small trust signal.
2. **Problem / pain — "They get my situation."** `list-icon-dividers` or
   `icon-features` (3 sharp pains beat one vague paragraph), `compare-two-panels`
   (before vs after). Build emotional resonance before pitching anything.
3. **Solution / value proposition — "Here's the better way."** `split-image-text`
   or `section-intro` + visual. Frame old-way-vs-new-way: the problem is hard →
   our approach solves it by X. Sell the mechanism in plain language.
4. **Benefits — outcomes, not features.** `blurb-grid`, `features-bento`,
   `checklist-two-column`. Each: icon + short title + one line + the outcome for
   the visitor. Write "Save hours every week", not "Powerful dashboard". 3–6 benefits.
5. **Social proof / credibility — reduce risk.** `role=proof` (`proof-logo-wall`
   right under the hero, `proof-rating-strip` near a price, `proof-press-quotes`),
   `role=stats` (`stats-counter-band`) and `role=testimonials` (`quote-single-large`,
   `testimonials-avatar-grid`). Use realistic placeholders, clearly marked for
   replacement — NEVER fabricate specific named testimonials or invented metrics as
   if real. Mark them e.g. "[Replace with a real client quote]". A counter needs a
   number: the recipes show demo values; use a figure the owner can prove, or remove it.
6. **How it works — make it feel easy.** `role=process`: `steps-numbered`,
   `steps-horizontal` or `timeline-vertical`. Use for any product/service with a
   process. Keep it to 3–4 steps, visually simple.
7. **Feature / detail — substance after value.** `features-zigzag`,
   `features-accordion-image` or alternating `split-image-text`. For each: what it
   does → why it matters → what changes for the customer. Only after the visitor
   already wants the outcome.
8. **Pricing — "What does it cost?"** `role=pricing`: `pricing-three-tier`,
   `pricing-two-plan-highlight`, `pricing-comparison-table`. Every price and plan
   name is the owner's; mark the recommended plan with a label, not colour alone.
9. **Objection handling / FAQ — remove friction before the ask.** `role=faq`:
   `faq-accordion`, `faq-two-column` or `faq-toggle-list`.
   Answer the real doubts: Is it hard? How long? Who's it for? What happens next?
   What does it cost / is there a guarantee?
10. **Final conversion — close.** `role=cta`: `cta-band`, `cta-split-form` (lead
   capture), `cta-card-over-image`; `cta-countdown` only for a real deadline the
   owner gives. Restate the promise, ONE clear action, minimal distractions (no
   nav-like clutter, no competing links). This is where lead capture forms belong.

**Transitions — change the rhythm.** `role=transition`: `transition-wave-band`
between two sections on the page colour; `transition-overlap-panel` after a hero
or image-led section, followed by a section on the page colour.

## Copywriting rules (the page lives or dies here)
- **Headline formula:** "Get [desired result] without [main frustration]" or
  "[Outcome] for [audience]". Specific and benefit-led.
- **Banned:** "Welcome to our website", "Welcome to our amazing company",
  generic "Lorem ipsum", and feature-noise like "Advanced analytics",
  "Powerful platform", "Modern technology", "Cutting-edge solutions".
- **Prefer outcomes:** "Know exactly what's working", "Win back 5 hours a week",
  "Turn more visitors into customers".
- **Realistic placeholders, marked for replacement.** When you don't have a real
  fact (a stat, a client name, a quote, an address), write a plausible, on-brand
  placeholder and bracket it: "[Replace: client name]". Don't ship lorem ipsum
  and don't pass invented specifics off as real.
- **Recipe placeholders are not copy.** Replace every bracketed text, demo counter
  value and image alt text in a recipe with the owner's real content, or remove it.
- Speak to ONE reader in second person ("you"), in the brief's language.

## CTA strategy
- Place CTAs at: **hero**, **after the benefits**, **after social proof**, and
  the **final section**. That's enough — don't litter every section with buttons.
- Use ONE consistent primary action and label across the page (e.g. always
  "Start Free Trial" or always "Book a Call"). Repetition of the same ask
  compounds; competing asks dilute.
- The primary CTA gets the accent color; secondary actions are quieter (ghost /
  text link), never two loud buttons fighting.

## Visual hierarchy, rhythm & responsiveness
Use get_style_guide for the exact attribute shapes; this is the strategy:
- **Hierarchy:** hero headline largest (one h1), section titles h2, card titles
  h3–h4, body smaller and muted. Size encodes importance.
- **Rhythm & separation:** generous section padding (5–8vw); alternate
  background tones (e.g. light → tinted → dark CTA) so sections read as distinct
  beats. Vary layouts — don't repeat the same 3-card row four times in a row;
  alternate split/grid/stats/quote so the eye keeps moving.
- **Pick a design language first**: read the audience and brief, call get_style_guide
  topic=design-languages, choose one and state it before building. Then pick one recipe per
  persuasion stage: browse get_section_recipes by `role` (and `layout`/`mood`), varying `layout`,
  then fetch the chosen recipe with `name` plus `design_language` so its colours and fonts follow
  (`design_language` works only together with `name`). Keep one accent across the page.
- **Composition rules:**
  - never the same layout in adjacent sections
  - alternate background treatment
  - at most one card grid per three sections
  - give the first section a focal element (image, gradient or large type)
- **Act on the checker:** if a save or validate result carries `advisories.design_variety`,
  if a finding's `try` has names, swap that section for one of them; if `try` is empty, follow the
  finding's `message`. Then save again. It is a hint only: it never blocks a save, and the page is saved as it is.
- **Mobile:** every multi-column row stacks to one column on phone, the hero CTA
  stays above the fold, type scales down. Follow the style guide's mobile rules.

## Assembly checklist (run before create_page / update_page_layout)
- [ ] Business type, audience, and the ONE conversion goal are decided.
- [ ] Page opens with a benefit hero (not "Welcome to…") + one primary CTA.
- [ ] Flow is attention → problem → solution → benefits → proof → (how it
      works / features / FAQ) → final CTA. Sections you kept each have a job.
- [ ] Benefits are outcomes; no feature-noise or lorem ipsum; placeholders are
      bracketed for replacement; no fabricated specific testimonials/stats.
- [ ] Same primary CTA wording repeated at hero, post-benefits, post-proof,
      and final section — not a button in every block.
- [ ] One h1, clear h2/h3 hierarchy, alternating section tones, varied layouts,
      all rows stack on mobile.
- [ ] Validated (create_page / update_page_layout validate automatically).
MD;
    }
}
