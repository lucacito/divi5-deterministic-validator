<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Design;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use AiEditorDivi5\WP\RecipeTokens;

/**
 * The role a colour or font value of a saved page played in its SOURCE design, so the switch can write the role's token
 * back (spec 2026-10-25-pro-design-switch-design.md section 5.3; plan Ruling 3). Pure and deterministic.
 *
 * A value is compared with the source palette as a normalised colour key (`Bands::colourKey`, so a three-digit hex equals its six-digit form)
 * or, for a font, as the exact family string. `surface-glass` is a role only where the source language sets
 * `surface_glass` (elsewhere the free resolver writes the surface colour for it, which is the `surface` role). Then:
 * - no role has the value: `unknown` (left);
 * - one role has it: `exact`;
 * - several roles have it (a collision): the library context (`ContextTable`) at this block, path and band (colours) or
 *   type role (fonts) decides. Exactly one of the colliding roles used there: `context`. Otherwise, when every
 *   candidate (the colliding roles the context uses, or all colliding roles when it uses none) gives the same value in
 *   the TARGET design: `same-target` (whichever is written, the result is the same). Otherwise, when the role the
 *   context uses most often (strictly more often than any other role) is one of the colliding roles: `assumed`, that
 *   role (the caller reports it). Otherwise `ambiguous` (left, reported).
 *
 * Every result names the source roles that have the value (`roles`), so no decision is silent.
 */
final class RoleReverse
{
    /** Colour token role => key in a design language's `colors` (the free `RecipeTokens::COLOR_ROLES`, pinned equal by the tests). */
    public const COLOR_ROLES = [
        'bg'            => 'bg',
        'surface'       => 'surface',
        'surface-glass' => 'surface_glass',
        'text'          => 'text',
        'muted'         => 'muted',
        'accent'        => 'accent',
        'accent-text'   => 'accent_text',
        'border'        => 'border',
        'inverse'       => 'inverse',
        'overlay'       => 'overlay',
    ];

    /** Font token role => key in a design language's `fonts` (the free `RecipeTokens::FONT_ROLES`, pinned equal by the tests). */
    public const FONT_ROLES = [ 'display' => 'display', 'body' => 'body', 'eyebrow' => 'eyebrow' ];

    /** The alpha percents `RecipeTokens` writes (`{{aied:color:<role>:<NN>}}`): at least ALPHA_MIN, below ALPHA_ABOVE. */
    private const ALPHA_MIN   = 1;
    private const ALPHA_ABOVE = 100;

    /** @var array<string,list<string>> source colour key => roles, in COLOR_ROLES order */
    private array $sourceColours = [];

    /** @var array<string,list<string>> source family => roles, in FONT_ROLES order */
    private array $sourceFonts = [];

    /** @var array<string,string> role => target colour key */
    private array $targetColours = [];

    /** @var array<string,string> role => target family */
    private array $targetFonts = [];

    public function __construct( private readonly Design $from, Design $to, private readonly ContextTable $table )
    {
        foreach ( self::colours( $from->language ) as $role => $key ) {
            $this->sourceColours[ $key ][] = $role;
        }
        $this->targetColours = self::colours( $to->language, true );
        foreach ( self::fonts( $from->language ) as $role => $family ) {
            $this->sourceFonts[ $family ][] = $role;
        }
        $this->targetFonts = self::fonts( $to->language );
    }

    /**
     * The token for a colour value found at a block's JSON path on the given band.
     *
     * @return array{token:?string, status:'exact'|'context'|'same-target'|'assumed'|'ambiguous'|'unknown', roles:list<string>}
     */
    public function colour( string $value, string $block, string $path, string $band ): array
    {
        $key   = Bands::colourKey( $value );
        $roles = null === $key ? [] : ( $this->sourceColours[ $key ] ?? [] );

        return self::decide( 'color', $roles, $this->table->colour( $block, $path, $band ), $this->targetColours );
    }

    /**
     * The token for a font family found at a block's JSON path, the block's type role being `$typeRole` (`-` for none).
     *
     * @return array{token:?string, status:'exact'|'context'|'same-target'|'assumed'|'ambiguous'|'unknown', roles:list<string>}
     */
    public function font( string $value, string $block, string $path, string $typeRole ): array
    {
        return self::decide( 'font', $this->sourceFonts[ $value ] ?? [], $this->table->font( $block, $path, $typeRole ), $this->targetFonts );
    }

    /**
     * `{{aied:color:<role>:<NN>}}` when the value is exactly what `RecipeTokens` writes for that alpha token of ONE source
     * role (whitespace and case aside); null otherwise, and null when two roles share the colour's channels.
     */
    public function alpha( string $value ): ?string
    {
        $key = Bands::colourKey( $value );
        if ( null === $key ) {
            return null;
        }
        $parts   = explode( ',', $key );
        $percent = (int) round( (float) $parts[3] * 100 );
        if ( $percent < self::ALPHA_MIN || $percent >= self::ALPHA_ABOVE ) {
            return null;
        }
        $wanted = self::squash( $value );
        $found  = [];
        foreach ( array_keys( self::colours( $this->from->language ) ) as $role ) {
            $token = '{{aied:color:' . $role . ':' . $percent . '}}';
            if ( self::squash( RecipeTokens::resolve( $token, $this->from->language ) ) === $wanted ) {
                $found[] = $token;
            }
        }

        return 1 === count( $found ) ? $found[0] : null;
    }

    /**
     * @param list<string>          $roles   the source roles that have the value
     * @param array<string,int>     $context role => count in the library at this context
     * @param array<string,string>  $target  role => the comparable target value
     *
     * @return array{token:?string, status:'exact'|'context'|'same-target'|'assumed'|'ambiguous'|'unknown', roles:list<string>}
     */
    private static function decide( string $kind, array $roles, array $context, array $target ): array
    {
        if ( [] === $roles ) {
            return [ 'token' => null, 'status' => 'unknown', 'roles' => [] ];
        }
        if ( 1 === count( $roles ) ) {
            return [ 'token' => self::token( $kind, $roles[0] ), 'status' => 'exact', 'roles' => $roles ];
        }
        $attested = array_values( array_filter( $roles, static fn ( string $r ): bool => ( $context[ $r ] ?? 0 ) > 0 ) );
        if ( 1 === count( $attested ) ) {
            return [ 'token' => self::token( $kind, $attested[0] ), 'status' => 'context', 'roles' => $roles ];
        }
        $candidates = [] === $attested ? $roles : $attested;
        $values     = [];
        foreach ( $candidates as $r ) {
            $values[ $target[ $r ] ?? "\0missing:" . $r ] = true;
        }
        if ( 1 === count( $values ) ) {
            return [ 'token' => self::token( $kind, $candidates[0] ), 'status' => 'same-target', 'roles' => $roles ];
        }
        $top = self::strictTop( $context );
        if ( null !== $top && in_array( $top, $roles, true ) ) {
            return [ 'token' => self::token( $kind, $top ), 'status' => 'assumed', 'roles' => $roles ];
        }

        return [ 'token' => null, 'status' => 'ambiguous', 'roles' => $roles ];
    }

    /**
     * The role used strictly more often than every other one, or null (an empty context or a tie for the top).
     *
     * @param array<string,int> $counts
     */
    private static function strictTop( array $counts ): ?string
    {
        $top   = null;
        $best  = 0;
        $tied  = false;
        foreach ( $counts as $role => $n ) {
            if ( $n > $best ) {
                [ $top, $best, $tied ] = [ (string) $role, $n, false ];
            } elseif ( $n === $best && $n > 0 ) {
                $tied = true;
            }
        }

        return $tied ? null : $top;
    }

    private static function token( string $kind, string $role ): string
    {
        return '{{aied:' . $kind . ':' . $role . '}}';
    }

    /** A colour text without whitespace, lower case (for comparing a written alpha form). */
    private static function squash( string $value ): string
    {
        return strtolower( (string) preg_replace( '/\s+/', '', $value ) );
    }

    /**
     * Role => normalised colour key of a language's palette, in COLOR_ROLES order. `surface-glass` is listed only where
     * the language sets `surface_glass`, unless `$withFallback` (a TARGET value: the free resolver then writes the
     * surface colour for it). A role whose value cannot be read is not listed.
     *
     * @param array<string,mixed> $language
     *
     * @return array<string,string>
     */
    private static function colours( array $language, bool $withFallback = false ): array
    {
        $colors = isset( $language['colors'] ) && is_array( $language['colors'] ) ? $language['colors'] : [];
        $out    = [];
        foreach ( self::COLOR_ROLES as $role => $key ) {
            $value = $colors[ $key ] ?? null;
            if ( ( ! is_string( $value ) || '' === $value ) && 'surface_glass' === $key ) {
                if ( ! $withFallback ) {
                    continue;
                }
                $value = $colors['surface'] ?? null;
            }
            $ck = is_string( $value ) ? Bands::colourKey( $value ) : null;
            if ( null !== $ck ) {
                $out[ $role ] = $ck;
            }
        }

        return $out;
    }

    /**
     * Role => family of a language's fonts, in FONT_ROLES order.
     *
     * @param array<string,mixed> $language
     *
     * @return array<string,string>
     */
    private static function fonts( array $language ): array
    {
        $fonts = isset( $language['fonts'] ) && is_array( $language['fonts'] ) ? $language['fonts'] : [];
        $out   = [];
        foreach ( self::FONT_ROLES as $role => $key ) {
            if ( is_string( $fonts[ $key ] ?? null ) && '' !== $fonts[ $key ] ) {
                $out[ $role ] = $fonts[ $key ];
            }
        }

        return $out;
    }
}
