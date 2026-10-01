<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AI-facing IMAGE-INTELLIGENCE guide. Teaches the AI to assign the RIGHT visual
 * to each section by role (not random images everywhere): the site's Media
 * Library first (list_media_images), then the plugin's bundled, original image
 * pack. The pack is shown as a catalogue of REAL site-local URLs, because the AI
 * cannot use {{aied:…}} tokens itself. Nothing here ever points at a third-party
 * image host.
 *
 * Served by the get_image_guide tool and GET /image-guide. Pure — testable.
 * Add-ons may append their own sourcing instructions via the
 * `jhmg_aied_image_guide` filter (receives and returns Markdown).
 */
final class ImageGuide
{
    public static function markdown(): string
    {
        $base = defined( 'AI_EDITOR_DIVI5_FILE' ) ? plugins_url( 'assets/images/', AI_EDITOR_DIVI5_FILE ) : '';

        $markdown = self::intro() . "\n" . ImagePack::catalogMarkdown( $base ) . "\n\n" . self::rules();

        // A misbehaving add-on filter (null, array, empty string) must never blank the guide.
        $filtered = apply_filters( 'jhmg_aied_image_guide', $markdown );

        return is_string( $filtered ) && '' !== $filtered ? $filtered : $markdown;
    }

    private static function intro(): string
    {
        return <<<'MD'
# Divi 5 Image Intelligence Guide

Images are not decoration — each one does a job for its section. Pick visuals by
ROLE, never at random. A page where the hero shows an on-topic visual,
testimonials have a face, and cards share one consistent aspect ratio reads as a
finished template; the same layout with random unrelated images reads as an
empty generator. Pair this with get_style_guide (the image module's attribute
shape) and get_landing_guide (the section's conversion job).

Two sources, in this order: the site's own **Media Library** first
(`list_media_images`), then the plugin's **built-in image pack**. Nothing else —
never hotlink or invent an image URL.

## Step 0 — read the context
Before choosing any image, fix: **page type, industry, section purpose, tone,
audience.** Turn them into 1–3 concrete keywords per image (a specific noun beats
a vague theme: `dashboard,laptop` for a SaaS hero, `restaurant,interior` for a
restaurant hero, `team,office` for an about page).

## Step 1 — use the Media Library (always try this first)
Call `list_media_images` before using any built-in image:
- Search by subject/keywords from Step 0.
- Pass `orientation` per role: **hero** → `landscape`, **avatar / team** →
  `square`, **card / blog** → `landscape`.
- Pick by the attachment's alt text and title (they say what the image shows).
- Put the returned `url` in the Divi image module (`image.innerContent.{bp}.value.src`)
  and set the module's alt text from the attachment's alt.
- If a library image fits the role, use it. Real photos the owner uploaded beat
  any stock or generated image.

## Step 2 — the built-in image pack (only when the library has no suitable image)
Use these only when the Media Library has no suitable image for the role (or it is
empty). They are original, locally hosted illustrations (gradients and abstract
shapes, not photographs) served from this site, so they always load and are safe
to publish. Copy the full `URL` from the table into the image `src`. Choose by
**Role** and **Ratio**, and keep ONE **Palette** for the whole page.

MD;
    }

    private static function rules(): string
    {
        return <<<'MD'
## Which role for which section
- **Hero** — one large visual, request a `hero` image (16:9). Full-width section
  or CTA backgrounds use `section-bg` (16:9) so text stays readable over it.
- **Testimonials / Team** — a face per person: `avatar` images (1:1), ONE style
  across all members. Built-in avatars are placeholders: mark them
  "[Photo: sample customer]" and never imply a real named person endorsed the
  product.
- **Cards / blog / feature grids** — `card` images (4:3), the SAME ratio for
  every card in a grid. Use a different image per card.
- **Galleries / split sections** — `square` (1:1) or `card` (4:3); different image
  per slot.
- **Logos / clients** — `logo` images (3:1), clearly standing in for real logos
  ("[Replace: client logo]").

## Sizes & aspect ratios
Pick one ratio per row of cards and keep every image in it identical (hero/bg
16:9, card 4:3, avatar/square 1:1, logo 3:1). Mismatched aspect ratio between
neighbours is the #1 tell of an auto-generated page.

## Rules
1. **Never hotlink third-party images** and never invent a URL. Only a Media
   Library `url` from `list_media_images`, or a URL copied from the table above.
2. **Always give every image alt text** — describe what it shows (from the
   attachment alt, or the "What it shows" column for built-in images).
3. **Never leave an image module without a `src`.**
4. Keep ONE ratio per row of cards and ONE palette per page.
5. Use a different image per slot so a grid is not the same picture repeated.
6. If the owner supplied an image URL or asked for specific photos, use theirs.

## Quality
Use the divi/image module shape from get_style_guide (its required
`image.innerContent.{bp}.value.src`). Round corners to match the page's card
radius and let Divi's native lazy-loading handle performance.
MD;
    }
}
