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
every page, plus cross-links between pages (the owner adds the menu).

## 1. Plan the page set
From the brief, choose pages. A typical business site:
- **Home** (slug `home` — the owner will set it as the front page)
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

## 4. Hand over to the owner (after all pages exist)
Pages are created as drafts and this plugin does not change site settings, so
finish by telling the owner exactly what to do in WordPress:
- Review and publish each draft page.
- **Front page**: set the Home page under Settings → Reading ("A static page").
- **Menu**: add the pages to the navigation menu under Appearance → Menus,
  in this order: Home, then inner pages, Contact last. Link each item to its page.
List the page titles and slugs you created so the owner can tick them off.

## Notes
- Header/footer: the owner's existing Theme Builder header and footer can be previewed and
  edited (list_theme_layouts, then pass the layout_id as page_id; always `dry_run` first,
  they appear on every page; only entries marked `editable: true` can be edited). Creating them and choosing where they apply is done by the
  owner in Divi's Theme Builder. Otherwise they are the active theme's (the nav menu
  drives the header).
- Build pages first and keep each returned page id and link, then give the owner
  the Settings → Reading and Appearance → Menus steps above.
- Undo: Every save through this plugin (update_page_layout / edit_page_content) keeps the
  page's previous content as a snapshot when it can (the result's `history.stored`
  says whether it did). Up to the last 10 previous versions are kept per page, fewer
  for very large pages (a per-page budget of about 768 KB keeps the saved history
  small). If an edit was wrong and a snapshot was kept, `list_page_history` then
  `restore_page_version` undoes it; the restore is itself undoable when its own
  `history.stored` is true. Prefer small `edit_page_content` edits over rewriting a
  whole page.
- Preview: update_page_layout and edit_page_content accept `dry_run: true` to return the
  checker's verdict and a diff of what would change without saving anything. Preview big
  or risky edits first, then repeat the call without `dry_run` to save. A preview of an
  invalid layout still returns normally, with `valid: false` and the violations; fix them
  before saving.
MD;
    }
}
