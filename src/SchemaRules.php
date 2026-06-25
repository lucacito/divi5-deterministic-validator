<?php

declare(strict_types=1);

namespace Divi5Validator;

/**
 * Schema constants derived empirically from Divi 5.8.0 exports.
 * See docs/SCHEMA.md for the full observations.
 */
class SchemaRules
{
    // Structural blocks — have children, appear as open/close pairs
    public const STRUCTURAL_BLOCKS = [
        'divi/placeholder',
        'divi/section',
        'divi/row',
        'divi/column',
        // Compound modules — structural but live inside columns
        'divi/accordion',
        'divi/contact-form',
        'divi/counters',
        'divi/icon-list',
        'divi/pricing-tables',
        'divi/slider',
        'divi/tabs',
    ];

    // Leaf modules — self-closing, carry actual content
    public const LEAF_MODULES = [
        // Basic
        'divi/heading',
        'divi/text',
        'divi/image',
        'divi/button',
        'divi/shortcode-module',
        // Media
        'divi/audio',
        'divi/video',
        'divi/before-after-image',
        // Content
        'divi/blurb',
        'divi/cta',
        'divi/icon',
        'divi/team-member',
        'divi/toggle',
        'divi/search',
        'divi/blog',
        'divi/breadcrumbs',
        'divi/map',
        'divi/signup',
        'divi/canvas-portal',
        // Counters / timers
        'divi/circle-counter',
        'divi/countdown-timer',
        // Compound module children (self-closing items inside structural parents)
        'divi/accordion-item',
        'divi/contact-field',
        'divi/counter',
        'divi/icon-list-item',
        'divi/pricing-table',
        'divi/slide',
        'divi/tab',
    ];

    // All known block types
    public const ALL_KNOWN_TYPES = [
        // Page structure
        'divi/placeholder',
        'divi/section',
        'divi/row',
        'divi/column',
        'divi/layout',
        // Compound structural modules
        'divi/accordion',
        'divi/contact-form',
        'divi/counters',
        'divi/icon-list',
        'divi/pricing-tables',
        'divi/slider',
        'divi/tabs',
        // Basic leaf modules
        'divi/heading',
        'divi/text',
        'divi/image',
        'divi/button',
        'divi/shortcode-module',
        // Media
        'divi/audio',
        'divi/video',
        'divi/before-after-image',
        // Content
        'divi/blurb',
        'divi/cta',
        'divi/icon',
        'divi/team-member',
        'divi/toggle',
        'divi/search',
        'divi/blog',
        'divi/breadcrumbs',
        'divi/map',
        'divi/signup',
        'divi/canvas-portal',
        // Counters / timers
        'divi/circle-counter',
        'divi/countdown-timer',
        // Compound module children
        'divi/accordion-item',
        'divi/contact-field',
        'divi/counter',
        'divi/icon-list-item',
        'divi/pricing-table',
        'divi/slide',
        'divi/tab',
    ];

    // Valid direct children for each structural block type
    public const ALLOWED_CHILDREN = [
        'divi/placeholder'    => ['divi/section'],
        'divi/section'        => ['divi/row'],
        'divi/row'            => ['divi/column'],
        'divi/column'         => [
            // Basic leaf modules
            'divi/heading',
            'divi/text',
            'divi/image',
            'divi/button',
            'divi/shortcode-module',
            // Media
            'divi/audio',
            'divi/video',
            'divi/before-after-image',
            // Content
            'divi/blurb',
            'divi/cta',
            'divi/icon',
            'divi/team-member',
            'divi/toggle',
            'divi/search',
            'divi/blog',
            'divi/breadcrumbs',
            'divi/map',
            'divi/signup',
            'divi/canvas-portal',
            // Counters / timers
            'divi/circle-counter',
            'divi/countdown-timer',
            // Compound structural modules (live inside columns)
            'divi/accordion',
            'divi/contact-form',
            'divi/counters',
            'divi/icon-list',
            'divi/pricing-tables',
            'divi/slider',
            'divi/tabs',
        ],
        // Compound module children
        'divi/accordion'      => ['divi/accordion-item'],
        'divi/contact-form'   => ['divi/contact-field'],
        'divi/counters'       => ['divi/counter'],
        'divi/icon-list'      => ['divi/icon-list-item'],
        'divi/pricing-tables' => ['divi/pricing-table'],
        'divi/slider'         => ['divi/slide'],
        'divi/tabs'           => ['divi/tab'],
    ];

    /**
     * The content key and whether its innerContent value must be an object (not scalar).
     *
     * Key   = block type
     * Value = [content_key, value_must_be_object]
     *
     * Scalar-where-object is the deep-merge fatal case documented in SCHEMA.md §7.
     *
     * @return array<string, array{string, bool}>
     */
    public function contentKeyRules(): array
    {
        return [
            'divi/heading' => ['title',   false],   // value is a string
            'divi/text'    => ['content', false],   // value is an HTML string
            'divi/image'   => ['image',   true],    // value must be an object {src: ...}
            'divi/button'  => ['button',  true],    // value must be an object {text: ...}
        ];
    }

    public function isKnownType(string $type): bool
    {
        return in_array($type, self::ALL_KNOWN_TYPES, true);
    }

    public function isLeafModule(string $type): bool
    {
        return in_array($type, self::LEAF_MODULES, true);
    }

    public function isStructural(string $type): bool
    {
        return in_array($type, self::STRUCTURAL_BLOCKS, true);
    }

    /** @return string[] */
    public function allowedChildrenOf(string $parentType): array
    {
        return self::ALLOWED_CHILDREN[$parentType] ?? [];
    }
}
