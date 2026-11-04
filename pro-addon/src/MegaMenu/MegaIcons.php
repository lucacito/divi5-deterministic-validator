<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\MegaMenu;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The icons the owner can choose for the cards of the icon layouts: 48 general-purpose Font Awesome 5 solid glyphs, the font Divi's Icon
 * module draws with (`type: fa`, `weight: 900`, written by Blocks::icon). Every code is checked against the font file the Divi theme ships
 * (`core/admin/fonts/fontawesome/fa-solid-900.svg`) by `make verify-pro-megamenu`, so a choice can never be a missing glyph.
 */
final class MegaIcons
{
    /** The arrow every card had before an icon could be chosen. */
    public const DEFAULT = 'arrow-right';

    /** id, label, code, group */
    private const SET = [
        [ 'arrow-right', 'Arrow', 'f061', 'General' ], [ 'star', 'Star', 'f005', 'General' ], [ 'heart', 'Heart', 'f004', 'General' ],
        [ 'check', 'Check', 'f00c', 'General' ], [ 'bolt', 'Lightning', 'f0e7', 'General' ], [ 'lightbulb', 'Idea', 'f0eb', 'General' ],
        [ 'home', 'Home', 'f015', 'General' ], [ 'user', 'Person', 'f007', 'General' ],
        [ 'users', 'Team', 'f0c0', 'People and business' ], [ 'handshake', 'Partnership', 'f2b5', 'People and business' ],
        [ 'briefcase', 'Briefcase', 'f0b1', 'People and business' ], [ 'building', 'Company', 'f1ad', 'People and business' ],
        [ 'graduation-cap', 'Learning', 'f19d', 'People and business' ], [ 'trophy', 'Trophy', 'f091', 'People and business' ],
        [ 'shopping-cart', 'Cart', 'f07a', 'Shop' ], [ 'tag', 'Tag', 'f02b', 'Shop' ], [ 'gift', 'Gift', 'f06b', 'Shop' ],
        [ 'credit-card', 'Payment', 'f09d', 'Shop' ], [ 'box', 'Box', 'f466', 'Shop' ], [ 'store', 'Store', 'f54e', 'Shop' ],
        [ 'envelope', 'Email', 'f0e0', 'Contact' ], [ 'phone', 'Phone', 'f095', 'Contact' ], [ 'map-marker-alt', 'Location', 'f3c5', 'Contact' ],
        [ 'comment', 'Chat', 'f075', 'Contact' ], [ 'life-ring', 'Support', 'f1cd', 'Contact' ],
        [ 'book', 'Book', 'f02d', 'Content' ], [ 'file-alt', 'Document', 'f15c', 'Content' ], [ 'image', 'Image', 'f03e', 'Content' ],
        [ 'camera', 'Photo', 'f030', 'Content' ], [ 'play', 'Video', 'f04b', 'Content' ],
        [ 'code', 'Code', 'f121', 'Tech and tools' ], [ 'cog', 'Settings', 'f013', 'Tech and tools' ], [ 'wrench', 'Tools', 'f0ad', 'Tech and tools' ],
        [ 'laptop', 'Laptop', 'f109', 'Tech and tools' ], [ 'rocket', 'Launch', 'f135', 'Tech and tools' ], [ 'chart-line', 'Growth', 'f201', 'Tech and tools' ],
        [ 'chart-bar', 'Statistics', 'f080', 'Tech and tools' ], [ 'globe', 'Web', 'f0ac', 'Tech and tools' ], [ 'lock', 'Security', 'f023', 'Tech and tools' ],
        [ 'shield-alt', 'Protection', 'f3ed', 'Tech and tools' ], [ 'search', 'Search', 'f002', 'Tech and tools' ],
        [ 'paint-brush', 'Design', 'f1fc', 'Life and services' ], [ 'leaf', 'Nature', 'f06c', 'Life and services' ], [ 'truck', 'Delivery', 'f0d1', 'Life and services' ],
        [ 'clock', 'Time', 'f017', 'Life and services' ], [ 'calendar-alt', 'Calendar', 'f073', 'Life and services' ], [ 'heartbeat', 'Health', 'f21e', 'Life and services' ],
        [ 'car', 'Car', 'f1b9', 'Life and services' ], [ 'music', 'Music', 'f001', 'Life and services' ],
    ];

    /** @return list<array{id:string,label:string,code:string,group:string}> */
    public static function all(): array
    {
        return array_map( static fn ( array $r ): array => [ 'id' => $r[0], 'label' => $r[1], 'code' => $r[2], 'group' => $r[3] ], self::SET );
    }

    /** @return array<string,list<array{id:string,label:string,code:string,group:string}>> group => icons, in the order of the set */
    public static function groups(): array
    {
        $out = [];
        foreach ( self::all() as $i ) {
            $out[ $i['group'] ][] = $i;
        }

        return $out;
    }

    public static function exists( string $id ): bool
    {
        foreach ( self::SET as $r ) {
            if ( $r[0] === $id ) {
                return true;
            }
        }

        return false;
    }

    /** The character reference Blocks::icon writes (`&#xf061;`), or null for an id that is not in the set. */
    public static function unicode( string $id ): ?string
    {
        foreach ( self::SET as $r ) {
            if ( $r[0] === $id ) {
                return '&#x' . $r[2] . ';';
            }
        }

        return null;
    }

    public static function label( string $id ): string
    {
        foreach ( self::SET as $r ) {
            if ( $r[0] === $id ) {
                return $r[1];
            }
        }

        return '';
    }
}
