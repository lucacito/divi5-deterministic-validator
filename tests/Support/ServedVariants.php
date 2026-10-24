<?php

declare(strict_types=1);

namespace Divi5Validator\Tests\Support;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\KitResolver;
use AiEditorDivi5\WP\LanguageResolver;
use AiEditorDivi5\WP\RecipeTokens;
use AiEditorDivi5\WP\StyleKits;

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' ); // the plugin classes refuse to load outside WordPress without it
}
require_once __DIR__ . '/../../wp-plugin/src/DesignLanguages.php';
require_once __DIR__ . '/../../wp-plugin/src/RecipeTokens.php';
require_once __DIR__ . '/../../wp-plugin/src/BlockAttrs.php';
require_once __DIR__ . '/../../wp-plugin/src/StyleKits.php';
require_once __DIR__ . '/../../wp-plugin/src/KitAdapters.php';
require_once __DIR__ . '/../../wp-plugin/src/KitResolver.php';
require_once __DIR__ . '/../../wp-plugin/src/LanguageAdapters.php';
require_once __DIR__ . '/../../wp-plugin/src/LanguageResolver.php';

/**
 * The markups a raw recipe is SERVED as, for the attribute-path linters (dev/test-side only, not shipped): a marker is not a
 * Divi attribute, so a linter must see the paths the adapters write instead. Every style kit's resolution under the default
 * language (RecipeTokens::default(), editorial), plus every other language's resolution under its own kit; each through the
 * language step (LanguageResolver) and then the kit step (KitResolver). Colour and font tokens are left unresolved, as the
 * linters expect. A variant that still carries a marker after either step throws: a linter must never see `aied.*`.
 */
final class ServedVariants
{
    /** @return list<string> */
    public static function of( string $raw ): array
    {
        $default = RecipeTokens::default();
        if ( null === $default ) {
            throw new \RuntimeException( 'the default design language could not be read' );
        }
        $kits = StyleKits::ids();
        if ( [] === $kits ) {
            throw new \RuntimeException( 'no style kits could be read (wp-plugin/data/style-kits.json)' );
        }
        $out  = [];
        $base = self::language( $raw, $default );
        foreach ( $kits as $kit ) {
            $out[] = self::kit( $base, $kit, (string) $default['id'] );
        }
        foreach ( DesignLanguages::ids() as $id ) {
            if ( $id === $default['id'] ) {
                continue;
            }
            $lang = (array) DesignLanguages::get( $id );
            $out[] = self::kit( self::language( $raw, $lang ), (string) ( $lang['kit'] ?? '' ), $id );
        }

        return $out;
    }

    /** @param array<string,mixed> $lang */
    private static function language( string $raw, array $lang ): string
    {
        $markup = LanguageResolver::resolve( $raw, $lang );
        if ( [] !== LanguageResolver::leftovers( $markup ) ) {
            throw new \RuntimeException( 'the language step of ' . (string) ( $lang['id'] ?? '?' ) . ' left a language marker' );
        }

        return $markup;
    }

    private static function kit( string $markup, string $kitId, string $language ): string
    {
        $kit = StyleKits::get( $kitId );
        if ( null === $kit ) {
            throw new \RuntimeException( "unknown style kit $kitId ($language)" );
        }
        $out = KitResolver::resolve( $markup, $kit );
        if ( [] !== KitResolver::leftovers( $out ) ) {
            throw new \RuntimeException( "the kit step of $kitId under $language left a marker" );
        }

        return $out;
    }
}
