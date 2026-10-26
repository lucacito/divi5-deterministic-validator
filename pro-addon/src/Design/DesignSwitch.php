<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Design;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use AiEditorDivi5\WP\BlockAttrs;
use AiEditorDivi5\WP\RecipeTokens;
use AiEditorDivi5\WP\SectionRecipes;
use AiEditorDivi5\WP\StyleKits;

/**
 * The deterministic design switch of one page (spec 2026-10-25-pro-design-switch-design.md section 5; plan Task 5).
 *
 * The page is UNRESOLVED under the source design into recipe-like markup, then resolved by the free pipeline under the
 * target (`SectionRecipes::resolveMarkup`), so every patch, merge rule and re-application order is the free plugin's own
 * (controller Ruling 2). Unresolving, block by block in document order (`PageScan`, bands under the SOURCE palette):
 * 1. marker recovery (`MarkerRecovery`): a full match puts the marker back as a top-level `aied` and strips the matched
 *    leaves; a `drifted` or `unclassified` block keeps its decoration (`leave` mode) and is listed. In the opt-in
 *    `conform` mode (spec 5.6, plan Task 7) a `drifted` block is conformed instead: EVERY leaf at a path its nearest
 *    candidate's source patch writes is removed whatever its value (`Leaves::strip`), the candidate's marker is put
 *    back, so the target kit styles it, and each removed leaf whose value differed from the source patch is reported
 *    under `overridden` (`MarkerRecovery::overridden`); an `unclassified` block is left in both modes;
 * 2. the media token: an object at a path where the installed recipes carry `{{aied:kit:radius-media}}` (read from the
 *    library at run time, never spelled here) that equals the source kit's `StyleKits::valueToken('radius-media')`
 *    becomes the token again (D2: under a source kit whose media radius is zero a flush image reads as a kit image and is
 *    reported under `assumed`);
 * 3. every inspected string leaf (see `SwitchResult`) through `RoleReverse` with the block's band and recovered type role:
 *    a decided role becomes its token again (a colour with alpha that is exactly one role's alpha form becomes the alpha token).
 * A block that got a marker or the media token is re-encoded with `RecipeTokens::encodeJson` (the resolvers re-encode such
 * blocks anyway); any other block has only its decided leaves rewritten in place (`JsonLeaves`), every other byte of the
 * page is copied verbatim.
 *
 * A target pipeline that refuses the unresolved page (a leftover marker or token) gives the ORIGINAL page with
 * `error` = `SwitchResult::ERROR_UNRESOLVED`, never a partial page. A target equal to the source returns the page
 * unchanged. Pure and deterministic: no clock, random number, locale or hash order; memoised expectations only cache the
 * free pipeline's own answers.
 */
final class DesignSwitch
{
    /** The modes the tool accepts: `leave` (default, full matches only) and the opt-in `conform` (spec 5.6). */
    public const MODES = [ 'leave', 'conform' ];

    /** The whole-value kit token of the media radius (the free `KitResolver` token form). */
    public const MEDIA_TOKEN = '{{aied:kit:radius-media}}';

    /** The value-token name of the media radius (`StyleKits::valueToken`). */
    private const MEDIA = 'radius-media';

    /** Keys whose string leaves are colours or fonts. */
    private const COLOUR_KEYS = [ 'color', 'value' ];
    private const FONT_KEY    = 'family';

    /** A value that refers to a Divi global colour or variable: never a palette value, left and counted. */
    private const GLOBAL_REF = '/gcid-|\$variable\(/';

    public const ASSUMED_FLUSH_IMAGE = 'a square image read as a kit image (the source kit rounds media by 0)';

    /** How many designs' expectations stay memoised (least recently used first out). */
    public const MEMO_DESIGNS = 64;

    /** @var array<string,Expectations> design key => expectations (the free pipeline's answers, shared across runs) */
    private static array $expectations = [];

    /** @var array{0:string,1:array<string,list<list<string>>>}|null [recipes hash, block name => media token paths] */
    private static ?array $mediaPaths = null;

    /**
     * Switch a page from one design to another.
     *
     * @throws \InvalidArgumentException for a mode outside MODES
     */
    public static function run( string $page, Design $from, Design $to, string $mode = 'leave', ?ContextTable $table = null ): SwitchResult
    {
        self::checkMode( $mode );
        if ( $from->equals( $to ) ) {
            return SwitchResult::untouched( $page );
        }
        $table = $table ?? ContextTable::current();
        [ $unresolved, $parts ] = self::unresolveWithReport( $page, $from, $to, $table, $mode );
        $resolved = SectionRecipes::resolveMarkup( $unresolved, $to->language, $to->kit );
        if ( null === $resolved ) {
            return SwitchResult::untouched( $page, SwitchResult::ERROR_UNRESOLVED );
        }

        return new SwitchResult( $resolved, $resolved !== $page, $parts['switched'], $parts['left'], $parts['assumed'], $parts['inspected'], null, $parts['conformed'], $parts['overridden'] );
    }

    /**
     * The page unresolved under `$from` for a switch to `$to` (markers and tokens back in place), before the target
     * pipeline runs. The target only breaks ties (a tie whose target patches agree, a collision whose roles give one
     * target value). Used by `Detector` to compare two source kits.
     */
    public static function unresolve( string $page, Design $from, Design $to, ?ContextTable $table = null, string $mode = 'leave' ): string
    {
        self::checkMode( $mode );

        return self::unresolveWithReport( $page, $from, $to, $table ?? ContextTable::current(), $mode )[0];
    }

    /**
     * The memoised expectations of a design (the free pipeline's one-block answers), the `MEMO_DESIGNS` most recently used
     * designs kept (detection reads the 40 kits of one language and the ten languages' own designs, a switch two).
     */
    public static function expectations( Design $d ): Expectations
    {
        $key = $d->languageId . "\0" . $d->kitId;
        if ( isset( self::$expectations[ $key ] ) ) {
            $e = self::$expectations[ $key ];
            unset( self::$expectations[ $key ] );
        } else {
            $e = new Expectations( $d );
            if ( count( self::$expectations ) >= self::MEMO_DESIGNS ) {
                unset( self::$expectations[ array_key_first( self::$expectations ) ] );
            }
        }
        self::$expectations[ $key ] = $e;

        return $e;
    }

    private static function checkMode( string $mode ): void
    {
        if ( ! in_array( $mode, self::MODES, true ) ) {
            throw new \InvalidArgumentException( sprintf( 'Unknown mode "%s". Valid modes: %s.', $mode, implode( ', ', self::MODES ) ) );
        }
    }

    /**
     * @return array{0:string, 1:array{switched:array, left:array, assumed:list<array<string,mixed>>, inspected:int, conformed:list<array<string,mixed>>, overridden:list<array<string,mixed>>}}
     */
    private static function unresolveWithReport( string $page, Design $from, Design $to, ContextTable $table, string $mode ): array
    {
        $src      = self::expectations( $from );
        $tgt      = self::expectations( $to );
        $reverse  = new RoleReverse( $from, $to, $table );
        $media    = StyleKits::valueToken( $from->kit, self::MEDIA );
        $mediaKey = null === $media ? null : Leaves::encode( (object) $media );
        $flush    = null !== $media && self::isZero( $media );
        $paths    = self::mediaPaths( $table );

        $switched  = SwitchResult::emptySwitched();
        $left      = SwitchResult::emptyLeft();
        $assumed   = [];
        $inspected = 0;
        $conform   = 'conform' === $mode;
        $conformed = [];
        $overridden = [];

        $out = '';
        $at  = 0;
        foreach ( PageScan::scan( $page, $from->language ) as $index => $block ) {
            if ( null === $block['json'] ) {
                continue;
            }
            $name  = $block['name'];
            $attrs = BlockAttrs::decode( $block['json'] );
            if ( null === $attrs ) {
                continue;
            }
            $changed  = false;
            $typeRole = '-';

            $r = MarkerRecovery::recover( $name, $attrs, $block['band'], MarkerRecovery::headingLevel( $name, $attrs ), $src, $tgt );
            if ( ( 'kit' === $r['status'] || 'language' === $r['status'] ) && null !== $r['patch'] && null !== $r['marker'] ) {
                Leaves::strip( $attrs, $r['patch'] );
                $attrs->aied = (object) $r['marker'];
                $changed     = true;
                if ( 'kit' === $r['status'] ) {
                    self::bump( $switched['markers'], (string) $r['marker']['kit'] );
                } elseif ( isset( $r['marker']['type'] ) ) {
                    $typeRole = (string) $r['marker']['type'];
                    self::bump( $switched['type'], $typeRole );
                } else {
                    foreach ( $r['marker'] as $kind => $role ) {
                        if ( 'rhythm' === $kind ) {
                            self::bump( $switched['rhythm'], (string) $role );
                        }
                    }
                }
                if ( null !== $r['assumed'] ) {
                    $assumed[] = [ 'block' => $index, 'name' => $name, 'kind' => 'marker', 'marker' => $r['marker'], 'reason' => $r['assumed'] ];
                }
            } elseif ( 'drifted' === $r['status'] && $conform && null !== $r['patch'] && null !== $r['marker'] ) {
                $over = MarkerRecovery::overridden( $attrs, $r['patch'] );
                Leaves::strip( $attrs, $r['patch'] );
                $attrs->aied = (object) $r['marker'];
                $changed     = true;
                self::bump( $switched['markers'], (string) $r['marker']['kit'] );
                $conformed[] = [ 'block' => $index, 'name' => $name, 'marker' => $r['marker'], 'differing' => $r['differing'], 'overridden' => count( $over ) ];
                foreach ( $over as $o ) {
                    $overridden[] = [ 'block' => $index, 'name' => $name, 'path' => $o['path'], 'old' => $o['old'] ];
                }
            } elseif ( 'drifted' === $r['status'] ) {
                $left['drifted'][] = [ 'block' => $index, 'name' => $name, 'marker' => $r['marker'], 'differing' => $r['differing'] ];
            } elseif ( 'unclassified' === $r['status'] ) {
                $left['unclassified'][] = [ 'block' => $index, 'name' => $name ];
            }

            if ( null !== $mediaKey ) {
                foreach ( $paths[ $name ] ?? [] as $path ) {
                    if ( self::replaceAt( $attrs, $path, $mediaKey ) ) {
                        $changed = true;
                        ++$switched['images'];
                        if ( $flush ) {
                            $assumed[] = [ 'block' => $index, 'name' => $name, 'kind' => 'image', 'path' => '.' . implode( '.', $path ), 'reason' => self::ASSUMED_FLUSH_IMAGE ];
                        }
                    }
                }
            }

            $json  = $changed ? RecipeTokens::encodeJson( $attrs ) : $block['json'];
            $edits = [];
            foreach ( JsonLeaves::strings( $json ) as $leaf ) {
                if ( str_starts_with( $leaf['path'], '.aied.' ) ) {
                    continue;
                }
                $value  = $leaf['value'];
                $isFont = self::FONT_KEY === $leaf['key'];
                if ( ! $isFont && ! in_array( $leaf['key'], self::COLOUR_KEYS, true ) ) {
                    continue;
                }
                $isRef = 1 === preg_match( self::GLOBAL_REF, $value );
                if ( '' === $value || ( 'value' === $leaf['key'] && ! $isRef && null === Bands::colourKey( $value ) ) ) {
                    continue; // nothing to decide, or a `value` that is not a colour (text, a size...)
                }
                ++$inspected;
                if ( $isRef ) {
                    ++$left['global_refs'];
                    continue;
                }
                $d = $isFont
                    ? $reverse->font( $value, $name, $leaf['path'], $typeRole )
                    : $reverse->colour( $value, $name, $leaf['path'], $block['band'] );
                $token = $d['token'];
                if ( 'unknown' === $d['status'] && ! $isFont ) {
                    $token = $reverse->alpha( $value );
                }
                $kind = $isFont ? 'font' : 'colour';
                if ( null === $token ) {
                    if ( 'ambiguous' === $d['status'] ) {
                        $left['ambiguous'][] = [ 'block' => $index, 'name' => $name, 'kind' => $kind, 'path' => $leaf['path'], 'value' => $value, 'roles' => $d['roles'] ];
                    } else {
                        self::bump( $left[ $isFont ? 'unknown_fonts' : 'unknown_colours' ], $value );
                    }
                    continue;
                }
                $role = self::roleOf( $token );
                if ( 'assumed' === $d['status'] ) {
                    $assumed[] = [ 'block' => $index, 'name' => $name, 'kind' => $kind, 'path' => $leaf['path'], 'value' => $value, 'role' => $role, 'roles' => $d['roles'], 'reason' => 'library majority' ];
                } else {
                    self::bump( $switched[ $isFont ? 'fonts' : 'colours' ], $role );
                }
                $edits[ $leaf['offset'] ] = $token;
            }
            if ( [] !== $edits ) {
                $json = JsonLeaves::replace( $json, $edits );
            }
            if ( $json !== $block['json'] ) {
                $out .= substr( $page, $at, $block['offset'] - $at ) . $json;
                $at   = $block['offset'] + $block['length'];
            }
        }
        $out .= substr( $page, $at );

        foreach ( [ 'markers', 'type', 'rhythm', 'colours', 'fonts' ] as $k ) {
            ksort( $switched[ $k ], SORT_STRING );
        }
        ksort( $left['unknown_colours'], SORT_STRING );
        ksort( $left['unknown_fonts'], SORT_STRING );

        return [ $out, [ 'switched' => $switched, 'left' => $left, 'assumed' => $assumed, 'inspected' => $inspected, 'conformed' => $conformed, 'overridden' => $overridden ] ];
    }

    /**
     * Replace the object at `$path` with the media token when it equals the source kit's media radius.
     *
     * @param list<string> $path
     */
    private static function replaceAt( \stdClass $attrs, array $path, string $mediaKey ): bool
    {
        $node = $attrs;
        $last = array_pop( $path );
        foreach ( $path as $key ) {
            if ( ! $node instanceof \stdClass || ! property_exists( $node, $key ) ) {
                return false;
            }
            $node = $node->{$key};
        }
        if ( ! $node instanceof \stdClass || null === $last || ! property_exists( $node, $last ) ) {
            return false;
        }
        $value = $node->{$last};
        if ( ! $value instanceof \stdClass || Leaves::encode( $value ) !== $mediaKey ) {
            return false;
        }
        $node->{$last} = self::MEDIA_TOKEN;

        return true;
    }

    /**
     * Block name => the attribute paths (segments) where the recipes carry the whole-value media token, read from the
     * library, so no path is spelled here. Memoised by the context table's recipe hash (the table describes the installed
     * recipes; a table built from other recipes reads the installed ones again).
     *
     * @return array<string,list<list<string>>>
     */
    public static function mediaPaths( ?ContextTable $table = null ): array
    {
        $table = $table ?? ContextTable::current();
        if ( null !== self::$mediaPaths && self::$mediaPaths[0] === $table->hash() ) {
            return self::$mediaPaths[1];
        }
        $found = [];
        foreach ( ContextTable::installedRecipes() as $raw ) {
            foreach ( PageScan::scan( $raw, [] ) as $block ) {
                if ( null === $block['json'] || ! str_contains( $block['json'], self::MEDIA_TOKEN ) ) {
                    continue;
                }
                foreach ( JsonLeaves::strings( $block['json'] ) as $leaf ) {
                    if ( self::MEDIA_TOKEN !== $leaf['value'] || str_contains( $leaf['path'], '[]' ) ) {
                        continue;
                    }
                    $found[ $block['name'] ][ $leaf['path'] ] = array_values( array_filter( explode( '.', $leaf['path'] ), static fn ( string $s ): bool => '' !== $s ) );
                }
            }
        }
        ksort( $found, SORT_STRING );
        $paths = [];
        foreach ( $found as $name => $byPath ) {
            ksort( $byPath, SORT_STRING );
            $paths[ $name ] = array_values( $byPath );
        }
        self::$mediaPaths = [ $table->hash(), $paths ];

        return $paths;
    }

    /** @param array<string,string> $radius */
    private static function isZero( array $radius ): bool
    {
        foreach ( $radius as $v ) {
            if ( 1 !== preg_match( '/^0(?:px|%|em|rem)?$/', (string) $v ) ) {
                return false;
            }
        }

        return [] !== $radius;
    }

    /** The role of a `{{aied:color|font:<role>[:NN]}}` token (with `:NN` for an alpha token). */
    private static function roleOf( string $token ): string
    {
        return (string) preg_replace( '/^\{\{aied:(?:color|font):([^}]+)\}\}$/', '$1', $token );
    }

    /** @param array<string,int> $map */
    private static function bump( array &$map, string $key ): void
    {
        $map[ $key ] = ( $map[ $key ] ?? 0 ) + 1;
    }
}
