<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AI-facing IMAGE-INTELLIGENCE guide. Teaches the AI to assign the RIGHT visual
 * to each section by role (not random images everywhere), using keyless,
 * verified image sources — so generated pages look like finished premium
 * marketplace demos instead of empty templates.
 *
 * Every URL pattern here is confirmed reachable without an API key. Served by the
 * get_image_guide tool and GET /image-guide. Pure — testable.
 */
final class ImageGuide
{
    public static function markdown(): string
    {
        return <<<'MD'
# Divi 5 Image Intelligence Guide

Images are not decoration — each one does a job for its section. Pick visuals by
ROLE, never at random. A page where the hero shows a real product, testimonials
have real faces, and cards share one consistent ratio reads as a premium
template; the same layout with random unrelated photos reads as an empty
generator. Pair this with get_style_guide (the image module's attribute shape)
and get_landing_guide (the section's conversion job).

## Step 0 — read the context, then derive keywords
Before choosing any image, fix five things (from the brief / the page you're
building): **page type, industry, section purpose, tone/style, audience.** Turn
them into 1–3 concrete search keywords per image. Examples:
- SaaS hero → `dashboard,laptop`  ·  SaaS team → `startup,team`
- Restaurant hero → `restaurant,interior`  ·  menu → `food,plating`
- Architecture → `modern,building`  ·  Fitness → `gym,training`
- Public transport → `tram,city` / `bus,commute` / `station,people`
Use the most specific noun that still returns photos (city + subject beats a
proper place name a stock search won't have).

## The source toolkit (all keyless — no API key, work as-is)
Choose the source by the image's ROLE:

| Role / need | Source | URL pattern (verified) |
|---|---|---|
| **Relevant real photo** (hero, lifestyle, industry, blog/card) | **LoremFlickr** | `https://loremflickr.com/{w}/{h}/{kw1},{kw2}?lock={n}` |
| Generic / abstract / texture / safe fallback | **Picsum** | `https://picsum.photos/seed/{keyword}/{w}/{h}` |
| **Avatar / face** (testimonial, team, profile) | **Random User** | `https://randomuser.me/api/portraits/{men\|women}/{0-99}.jpg` |
| Avatar (alt, sized square) | **Pravatar** | `https://i.pravatar.cc/{size}?img={1-70}` |
| **Labeled placeholder** (feature shot, screenshot, wireframe, when no real photo fits) | **Placehold.co** | `https://placehold.co/{w}x{h}/{bg}/{fg}?text={Descriptive+Label}` |

Notes that make images look intentional, not random:
- **LoremFlickr returns a keyword-matched photo.** Comma-separate up to ~3 tags.
  Grayscale variant: `https://loremflickr.com/g/{w}/{h}/{kw}?lock={n}`.
- **Picsum has NO keyword search** — the seed only makes it *stable*, not
  *relevant*. Use Picsum only where the subject doesn't matter (abstract
  backgrounds, textures, fallback). For a subject that matters, use LoremFlickr.
- `source.unsplash.com` is **retired (503)** — never use it.

## Stable images — pin every one (critical)
A generated page must show the SAME image on every load. Always pin:
- LoremFlickr → add `?lock={n}` (a fixed integer per image; vary n so different
  images differ).
- Picsum → use `/seed/{keyword}/` (never the random `/{w}/{h}` form).
- Avatars → fixed index (`/men/32.jpg`, `?img=12`).
Give each distinct image a distinct lock/seed/index so a grid isn't all the same
photo, but keep them fixed so nothing reshuffles.

## Section-by-section rules
- **Hero** — one large, real, on-topic visual (product/UI, lifestyle, or
  industry scene). LoremFlickr with the page's core keywords. Never a generic
  grey placeholder here. Request it wide (see ratios).
- **Testimonials** — real face + name + role + company per quote. Avatar source
  (Random User or Pravatar). Keep avatars clearly marked as placeholder people
  ("[Photo: sample customer]") — do NOT imply a real named person endorsed the
  product.
- **Team** — professional portraits, ONE consistent avatar source and style
  across all members (don't mix Random User men with cartoon avatars).
- **Blog / news / cards** — category-relevant photos (LoremFlickr by topic), all
  at the SAME dimensions/ratio so the grid is even.
- **Feature / "how it works"** — prefer an icon or a labeled product-screenshot
  Placehold.co panel over stock photography; not every feature needs a photo.
- **Logos / clients** — labeled Placehold.co wordmarks (`?text=Client+1`) clearly
  standing in for real logos.

## Sizes & aspect ratios — request at the display ratio
Pick one ratio per section and request the image at it, so Divi never has to
stretch/crop awkwardly:
- Full-width hero/CTA bg: `1600x900` (16:9) or `1920x1080`
- Split-section image: `1000x800` (5:4) or `1000x1100` (portrait)
- Card / blog grid: `800x600` (4:3) **or** `800x534` (3:2) — pick ONE and reuse
  it for every card in that grid
- Avatar: `300x300` (1:1)  ·  Client logo: `400x200`
Keep every card in a grid identical in size; mismatched ratios are the #1 tell of
an auto-generated page.

## Smart placeholder replacement (when a real photo isn't right)
Don't ship blank or meaningless images. If no real category photo fits, use a
Placehold.co panel whose text DESCRIBES the intended asset, so the draft is
self-documenting for the site owner:
- ❌ `image1.jpg` / empty src
- ✅ `https://placehold.co/1600x900/0A1B3D/FFFFFF?text=Hero:+modern+SaaS+dashboard`
- ✅ `https://placehold.co/800x600/EEEEEE/333333?text=Team:+marketing+lead`
Match the placeholder's `{bg}`/`{fg}` hex to the page palette so it still looks
designed.

## Fallback order (per image)
1. User-supplied URL / media asset → always use it.
2. Subject matters → **LoremFlickr** (`?lock`).
3. A real face → **Random User / Pravatar**.
4. Subject doesn't matter → **Picsum** (`/seed/`).
5. Nothing fits / needs a label → **Placehold.co** (descriptive text).
Never leave an image module without a `src`.

## Premium: real curated stock (Unsplash / Pexels / Pixabay)
For brand-grade, hand-curated photography, those libraries need an API key and a
server-side fetch that sideloads the file into the WordPress media library (so
the image is permanent, not hot-linked or rate-limited). That is the premium
`find_image` path. Until a key is configured, prefer LoremFlickr for relevance —
it needs nothing.

## Quality
Use the divi/image module shape from get_style_guide (its required
`image.innerContent.{bp}.value.src`). Keep ratios consistent within a section,
round corners to match the page's card radius, and let Divi's native lazy-loading
handle performance — request images at display size rather than oversized.
MD;
    }
}
