<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Design;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use AiEditorDivi5\WP\LanguageAdapters;

/**
 * Which marker produced a block's current values under the SOURCE design (spec 2026-10-25-pro-design-switch-design.md
 * section 5.2). Pure and deterministic: candidates come from `MarkerSpace` in its fixed order, every patch is the free
 * pipeline's (`Expectations`), and every tie is broken by a fixed rule, never by a hash order.
 *
 * The rules:
 * - A candidate MATCHES when every leaf of its source patch is in the block with the same JSON value (`Leaves::count`).
 *   The match with the most leaves wins.
 * - Several candidates with that most leaves whose TARGET patches all agree: the first in candidate order (whichever is
 *   taken, the target writes the same).
 * - Otherwise the tie is narrowed, each step only when it keeps at least one candidate, until the target patches agree:
 *   (1) `on` equal to the band the block sits on (a button on `surface` reads `light`, the button adapter's own
 *   substitution); (2) a plain candidate before a ground one; (3) for type markers, the role whose
 *   `LanguageAdapters::TYPE_HEADING_LEVELS` lists the block's heading level. Still not agreeing: the first in candidate
 *   order, `assumed` = "band unknown" (the band is `unknown`), "band not taken" (no candidate names the known band) or,
 *   for type markers, "heading level unknown".
 * - A smaller candidate that also matched fully with a different TARGET patch than the winner: `assumed` gains
 *   "larger match preferred" (D4: a block indistinguishable from a kit-driven one is switched as one).
 * - No full match on a KIT carrier: the nearest candidate (most agreeing leaves, ties by the band rule, then candidate
 *   order) agreeing on at least half of its leaves makes the block `drifted`, with the differing leaf paths; otherwise a
 *   block that holds anything at a path some source patch of its name writes is `unclassified`; else `none`.
 *
 * The kit and language carriers are disjoint (`MarkerSpace`), so a block gets at most one marker.
 */
final class MarkerRecovery
{
    public const ASSUMED_BAND_UNKNOWN = 'band unknown';
    public const ASSUMED_BAND_NOT_TAKEN = 'band not taken';
    public const ASSUMED_LEVEL_UNKNOWN = 'heading level unknown';
    public const ASSUMED_LARGER = 'larger match preferred';

    /** The band of an unreadable paint (`Bands`). */
    private const UNKNOWN_BAND = 'unknown';

    /** The button adapter reads a `surface` band as `light` (KitAdapters' own substitution for controls). */
    private const BUTTON = 'divi/button';

    /**
     * @param string      $block        the block name (`divi/button`)
     * @param \stdClass   $attrs        the block's decoded attributes (read only)
     * @param string      $band         the band the block sits on (`PageScan`, under the source language)
     * @param string|null $headingLevel the block's heading level (`h1`..`h6`, see `headingLevel()`), or null
     *
     * @return array{status:string, marker:?array<string,string>, patch:?\stdClass, assumed:?string, differing:list<string>}
     *         status `kit` | `language` | `drifted` | `unclassified` | `none`; on `kit` / `language` the caller strips
     *         `patch` (the source expectation, shared: do not change it) and sets `aied` = `marker`; on `drifted`
     *         `marker` / `patch` are the nearest candidate's
     */
    public static function recover( string $block, \stdClass $attrs, string $band, ?string $headingLevel, Expectations $src, Expectations $tgt ): array
    {
        $kit       = MarkerSpace::kitCandidates( $block );
        $language  = MarkerSpace::languageCandidates( $block );
        $best      = 0;
        $winners   = [];
        $matched   = []; // every full match: [candidate, leaf count]
        foreach ( [ ...$kit, ...$language ] as $candidate ) {
            $prepared = $src->prepared( $block, $candidate );
            if ( null === $prepared ) {
                continue;
            }
            $n = Leaves::countPrepared( $attrs, $prepared );
            if ( $n <= 0 ) {
                continue;
            }
            $matched[] = [ $candidate, $n ];
            if ( $n > $best ) {
                $best    = $n;
                $winners = [ $candidate ];
            } elseif ( $n === $best ) {
                $winners[] = $candidate;
            }
        }

        if ( [] !== $winners ) {
            [ $pick, $assumed ] = self::breakTie( $block, $winners, $band, $headingLevel, $tgt );
            foreach ( $matched as [ $candidate, $n ] ) {
                if ( $n < $best && $tgt->json( $block, $candidate ) !== $tgt->json( $block, $pick ) ) {
                    $assumed = null === $assumed ? self::ASSUMED_LARGER : $assumed . '; ' . self::ASSUMED_LARGER;
                    break;
                }
            }

            return [
                'status'    => isset( $pick['kit'] ) ? 'kit' : 'language',
                'marker'    => $pick,
                'patch'     => $src->attrs( $block, $pick ),
                'assumed'   => $assumed,
                'differing' => [],
            ];
        }

        if ( [] !== $kit ) {
            $near  = [];
            $most  = 0;
            foreach ( $kit as $candidate ) {
                $prepared = $src->prepared( $block, $candidate );
                if ( null === $prepared ) {
                    continue;
                }
                $agree = Leaves::agreeingPrepared( $attrs, $prepared );
                $total = count( $prepared );
                if ( $agree <= 0 || 2 * $agree < $total ) {
                    continue;
                }
                if ( $agree > $most ) {
                    $most = $agree;
                    $near = [ $candidate ];
                } elseif ( $agree === $most ) {
                    $near[] = $candidate;
                }
            }
            if ( [] !== $near ) {
                $pick  = self::narrow( $near, self::wantedOn( $block, $band ) )[0];
                $patch = $src->attrs( $block, $pick );

                return [
                    'status'    => 'drifted',
                    'marker'    => $pick,
                    'patch'     => $patch,
                    'assumed'   => null,
                    'differing' => null === $patch ? [] : Leaves::differing( $attrs, $patch ),
                ];
            }
        }

        return [
            'status'    => [] !== $kit && self::isDecorated( $block, $attrs, $src ) ? 'unclassified' : 'none',
            'marker'    => null,
            'patch'     => null,
            'assumed'   => null,
            'differing' => [],
        ];
    }

    /** Does the block hold anything at a leaf path some source kit patch of its name writes? */
    public static function isDecorated( string $block, \stdClass $attrs, Expectations $src ): bool
    {
        foreach ( MarkerSpace::kitCandidates( $block ) as $candidate ) {
            $prepared = $src->prepared( $block, $candidate );
            if ( null !== $prepared && Leaves::touchesPrepared( $attrs, $prepared ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * The heading level a type-marker block states (`h1`..`h6`), read at the free plugin's own type path for the block
     * (`LanguageAdapters::TYPE_PATHS`, then `desktop.value.headingLevel`), or null.
     */
    public static function headingLevel( string $block, \stdClass $attrs ): ?string
    {
        $base = LanguageAdapters::TYPE_PATHS[ $block ] ?? null;
        if ( ! is_string( $base ) ) {
            return null;
        }
        $node = $attrs;
        foreach ( [ ...explode( '.', $base ), 'desktop', 'value', 'headingLevel' ] as $key ) {
            if ( ! $node instanceof \stdClass || ! property_exists( $node, $key ) ) {
                return null;
            }
            $node = $node->{$key};
        }

        return is_string( $node ) ? $node : null;
    }

    /**
     * @param list<array<string,string>> $winners every candidate with the most matched leaves, in candidate order
     *
     * @return array{0:array<string,string>,1:?string} the pick and its `assumed` note
     */
    private static function breakTie( string $block, array $winners, string $band, ?string $headingLevel, Expectations $tgt ): array
    {
        if ( self::agree( $block, $winners, $tgt ) ) {
            return [ $winners[0], null ];
        }
        $set = $winners;
        $on  = self::wantedOn( $block, $band );
        foreach ( [ 'on', 'plain', 'level' ] as $step ) {
            $kept = array_values( array_filter( $set, static function ( array $c ) use ( $step, $on, $headingLevel ): bool {
                return match ( $step ) {
                    'on'    => isset( $c['on'] ) && $c['on'] === $on,
                    'plain' => ! isset( $c['ground'] ),
                    default => isset( $c['type'] ) && null !== $headingLevel
                        && in_array( $headingLevel, LanguageAdapters::TYPE_HEADING_LEVELS[ $c['type'] ] ?? [], true ),
                };
            } ) );
            if ( [] !== $kept ) {
                $set = $kept;
            }
            if ( self::agree( $block, $set, $tgt ) ) {
                return [ $set[0], null ];
            }
        }
        $hasOn = [] !== array_filter( $winners, static fn ( array $c ): bool => isset( $c['on'] ) );

        if ( ! $hasOn ) {
            return [ $set[0], self::ASSUMED_LEVEL_UNKNOWN ];
        }

        return [ $set[0], self::UNKNOWN_BAND === $band ? self::ASSUMED_BAND_UNKNOWN : self::ASSUMED_BAND_NOT_TAKEN ];
    }

    /**
     * The drifted pick's tie rule: `on` equal to the band, then plain before ground; candidate order otherwise.
     *
     * @param list<array<string,string>> $set
     *
     * @return list<array<string,string>>
     */
    private static function narrow( array $set, string $on ): array
    {
        $kept = array_values( array_filter( $set, static fn ( array $c ): bool => ( $c['on'] ?? null ) === $on ) );
        $set  = [] === $kept ? $set : $kept;
        $kept = array_values( array_filter( $set, static fn ( array $c ): bool => ! isset( $c['ground'] ) ) );

        return [] === $kept ? $set : $kept;
    }

    /** The `on` a candidate needs to agree with the band (a button on `surface` reads `light`). */
    private static function wantedOn( string $block, string $band ): string
    {
        return self::BUTTON === $block && 'surface' === $band ? 'light' : $band;
    }

    /** @param list<array<string,string>> $set */
    private static function agree( string $block, array $set, Expectations $tgt ): bool
    {
        $first = $tgt->json( $block, $set[0] );
        foreach ( $set as $c ) {
            if ( $tgt->json( $block, $c ) !== $first ) {
                return false;
            }
        }

        return true;
    }
}
