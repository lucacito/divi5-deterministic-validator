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
     * Render-critical content field rules.
     *
     * Each entry: [ key, required, mustBeObject ]
     *   key           — top-level attr key whose innerContent.desktop.value is checked
     *   required      — true: missing key is a violation; false: only checked when present
     *   mustBeObject  — true: value must be a JSON object (scalar causes PHP fatal on render)
     *
     * @return array<string, list<array{string, bool, bool}>>
     */
    public function contentKeyRules(): array
    {
        return [
            // Basic modules — key required, type must match
            'divi/heading'           => [['title',   true,  false]],
            'divi/text'              => [['content', true,  false]],
            'divi/image'             => [['image',   true,  true]],
            'divi/button'            => [['button',  true,  true]],

            // Media — key required, value must be object
            'divi/video'             => [['video',       true,  true]],
            'divi/before-after-image'=> [
                ['beforeImage', true,  true],
                ['afterImage',  true,  true],
            ],

            // Content — object fields optional but must be object when present
            'divi/blurb'             => [
                ['title',     false, true],
                ['imageIcon', false, true],
            ],
            'divi/cta'               => [['button', true, true]],
            'divi/team-member'       => [['image',  false, true]],
            'divi/breadcrumbs'       => [
                ['home',      false, true],
                ['separator', false, true],
            ],

            // Compound children — object fields must be object when present
            'divi/slide'             => [['button', false, true]],
            'divi/icon-list-item'    => [['icon',   true,  true]],
            'divi/pricing-table'     => [['currencyFrequency', false, true]],
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
