# Free 4.14.0 release draft (NOT applied: no version bump, no zip rebuild, nothing pushed)

Prepared 2026-10-21 from main after the rules and chips adapter, its guide polish and the small follow-ups. Applying it is the
owner's call (CLAUDE.md: no version bump, zip rebuild, push or release without the owner's say-so).

## readme.txt changelog entry (paste above `= 4.13.0 =`)

```
= 4.14.0 =
* New: style kits now also set the look of divider lines, the rules between list items and price columns, small tag labels and icon badges (line style, width and corners), so a dashed, dotted or heavy kit reads as one design all the way down.
* Improved: the guide now tells your assistant exactly how to write those by hand (widths, which settings to use, and that the kit wins when a design language says something different). Found by having fresh assistants build pages from the guide alone.
* Improved: in the built-in section patterns the thin rules and the icon badges follow the chosen kit. With the default look some lines are a little heavier and icon badges are squarer.
* Improved: verified on Divi 5.15.1.
```

## readme.txt upgrade notice (paste above `= 4.13.0 =` in `== Upgrade Notice ==`)

```
= 4.14.0 =
Style kits now also style divider lines, rules, tag labels and icon badges, so a page keeps one look. In the default look a few lines are slightly heavier and icon badges squarer. Nothing changes unless your assistant uses them.
```

## What a visible change looks like (so the changelog is honest)

Under editorial / quiet-solid, 18 one-pixel `text` or `inverse` rules become 2px (faq-two-column 6, values-list 4, before-after-compare 2,
pricing-two-plan-highlight 2, hero-image-scrim 1, quote-over-image 1, card-grid-4 2), three steps-horizontal dividers go from 2px to a 1px
quiet rule, and all nine icon chips take the kit's control radius (icon-values 5px to 4px; the round 999px badges of features-carousel-strip
become 4px squares). The two accent dividers (blog-feed, icon-values), step badges and timeline dots are unchanged.

## Checklist (owner-run steps, in the order of the earlier releases)

1. Version: `wp-plugin/jhmg-ai-editor-for-divi-5.php` (header + `AI_EDITOR_DIVI5_VERSION`) and `wp-plugin/readme.txt` (Stable tag, the two entries above) to 4.14.0. `Tested up to` only in readme.txt.
2. Re-read the readme's claims against `tests/WpOrgComplianceTest.php` (it pins the tool list, the block-type number and the allowed hosts); this release adds no tool, route or host.
3. `make test` and `make test-pro`; `bash scripts/build-plugin-zip.sh` (the tracked root zip is the 4.13.0 build until then).
4. Plugin Check (Docker env) on the dev copy and on the extracted zip; never `wp plugin delete` a test copy.
5. SVN: sync trunk from the tracked zip, commit, `svn copy trunk tags/4.14.0`. The SVN password is never stored: the owner types it.
6. Pro add-on: unchanged (0.12.1 works with the free plugin; no `Compat::MIN_FREE` change needed).
7. Optional: the divi5lab.com copy that lists what style kits cover (buttons, cards, accordions, toggles, forms, image corners) can add rules, dividers and chips.

## Not in this release

Blurb, testimonial and counter adapters; a `rule-accent` role; heading and text typography per kit; table-cell rules; `divi/signup`.
