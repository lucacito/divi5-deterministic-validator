<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AI-facing blueprint for generating an ENTIRE multi-page website from one brief
 * (not just a single page). Served by the get_site_guide tool. Pure — testable.
 */
final class SiteGuide
{
    public static function markdown(): string
    {
        return <<<'MD'
# Divi 5 Full-Site Blueprint

Build a cohesive multi-page website from one brief. The key to a site that feels
custom (not a set of unrelated pages) is ONE shared design system applied to
every page, plus wired navigation.

## 1. Plan the page set
From the brief, choose pages. A typical business site:
- **Home** (slug `home` — will be the front page)
- **About** (`about`)
- **Services** (`services`)
- **Contact** (`contact`)
Add others the brief implies (Pricing, Case Studies, Blog, Careers…). Keep it
focused — 4–6 pages is a strong v1.

## 2. Lock ONE design system (reuse on EVERY page)
Decide these once and never drift across pages:
- Palette: background, alt background, dark-section, text, muted, ONE accent
- Fonts: heading family + body family (and heading weight)
- Card style (radius, shadow, padding) and button style
Pull the exact attribute shapes from get_style_guide. Every page must use the
same tokens so the site reads as one brand.

## 3. Build each page (call create_page per page)
- Pass a stable `slug` to create_page so URLs are predictable for linking.
- Reuse section recipes (get_section_recipes) and the same hero/card/CTA styling.
- Each page: one h1 (its main headline), h2 section titles, number-counters for
  stats, all-four-corner radii, side-by-side button pairs, multi-column rows that
  stack on phone — exactly as in get_style_guide.
- End every page with the SAME footer section (consistent across the site).
- **Cross-link**: buttons/links point to sibling pages by root-relative slug:
  `/`, `/about/`, `/services/`, `/contact/`. CTAs like "Book a Call" link to
  `/contact/`.

## 4. Wire the site (after all pages exist)
- `set_front_page` {"page_id": <home id>} — make Home the site's front page.
- `set_primary_menu` {"items":[{"title":"Home","page_id":<id>}, …]} — build the
  nav menu the theme header shows site-wide. Order: Home, then inner pages,
  Contact last.

## Notes
- Header/footer are currently the active theme's (the nav menu drives the header).
  Global Divi Theme Builder header/footer templates are a later phase.
- create_page is premium; so are set_front_page and set_primary_menu.
- Build pages first, capture each returned page id, then wire front page + menu.
MD;
    }
}
