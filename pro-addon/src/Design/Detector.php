<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Design;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use AiEditorDivi5\WP\BlockAttrs;
use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\StyleKits;

/**
 * Which design a saved page was built with, when the caller does not say (spec 2026-10-25-pro-design-switch-design.md
 * section 5.4). Pure and deterministic; detection never changes a page.
 *
 * Language: the one with the most matches, a match being an inspected leaf (`color` / colour `value` / `family`) whose
 * value is in the language's palette (normalised colour keys) or font set, or a block that fully matches one of the
 * language's rhythm markers. A tie for the top, no match at all, or evidence below the confidence floor (a score under
 * `LANGUAGE_MIN_SCORE`, or a share of matched colour/font leaves under `LANGUAGE_MIN_SHARE`) is refused.
 *
 * Kit (under that language): for every kit, the blocks that fully match one of its kit markers (the best candidate per
 * block) or carry its media radius at a library media path (a zero radius is evidence of nothing: a flush image), and the
 * leaves those matches cover; kits are ranked by blocks, then leaves, then `StyleKits::ids()` order. The pick is the top
 * kit, or, when no kit matches any block (`source.kit` = `none`), the target's kit (the language's own without a
 * target). The pick is refused (`kit_tie`, both kits named, a request to pass `from_kit`) when another kit within the
 * floor of matched leaves (`KIT_MARGIN_FLOOR`, or `KIT_SINGLE_BLOCK_FLOOR` when the pick explains at most one block)
 * unresolves the page DIFFERENTLY for the same target (`DesignSwitch::unresolve`). Kits that unresolve it the same way
 * are harmless: the switch writes the same page whichever is taken.
 */
final class Detector
{
    /**
     * A kit that unresolves the page differently and matches fewer than this many leaves less than the pick refuses the
     * detection. Fixed from the Task 5 measurement on the library (`DetectorTest::testDetectionOnTheLibrary`, 284 pages):
     * the wrong picks there were exact ties (0 leaves) and one-leaf differences; with 2 and the single-block floor
     * below, no wrong kit is accepted on that set.
     */
    public const KIT_MARGIN_FLOOR = 2;

    /**
     * The floor when the pick explains at most one block: one plain block that happens to equal a kit patch (7 leaves
     * in proof-logo-wall and stats-big-captions, measured) must not decide the kit of the whole page.
     */
    public const KIT_SINGLE_BLOCK_FLOOR = 8;

    /**
     * The language confidence floor: a detected language with a score below this is refused `low_confidence` (pass
     * `from_language`; a given language is never floored, the caller vouches for it). Measured on the 284 library
     * detection pages (`DetectorTest::LANGUAGE_FLOOR_TABLE`): the lowest score there is 6 and the lowest share 0.688,
     * so neither floor refuses a library page; a hand-made page with one palette colour among two colour leaves (score 1,
     * share 0.5) would otherwise be read as that language and its one colour rewritten by role.
     */
    public const LANGUAGE_MIN_SCORE = 2;

    /** The second floor: a smaller share of the inspected colour and font leaves in the palette is refused too. */
    public const LANGUAGE_MIN_SHARE = 0.6;

    public const REFUSED_LANGUAGE_TIE  = 'language_tie';
    public const REFUSED_LOW_CONFIDENCE = 'low_confidence';
    public const REFUSED_NO_LANGUAGE   = 'no_language';
    public const REFUSED_KIT_TIE       = 'kit_tie';

    private const COLOUR_KEYS = [ 'color', 'value' ];
    private const FONT_KEY    = 'family';

    /**
     * @param string|null $languageId the source language when the caller gives it (validated by the caller)
     * @param string|null $kitId      the source kit when the caller gives it
     * @param Design|null $to         the target design (decides the kit for a page with no kit block, and the
     *                                unresolve two candidate kits are compared by); the language's own design otherwise
     *
     * @return array{language:?string, kit:?string, source:array{language:string, kit:string}, confidence:array<string,mixed>, runner_up:?array<string,mixed>, refused:?string, message:?string}
     */
    public static function detect( string $page, ?string $languageId, ?string $kitId, ?Design $to = null, ?ContextTable $table = null ): array
    {
        $blocks = self::blocks( $page );
        $result = [
            'language'   => null,
            'kit'        => null,
            'source'     => [ 'language' => null === $languageId ? 'detected' : 'given', 'kit' => null === $kitId ? 'detected' : 'given' ],
            'confidence' => [],
            'runner_up'  => null,
            'refused'    => null,
            'message'    => null,
        ];

        if ( null === $languageId ) {
            $scores = [];
            $counts = [];
            foreach ( DesignLanguages::ids() as $id ) {
                $counts[ $id ] = self::languageScore( $blocks, $id );
                $scores[ $id ] = $counts[ $id ]['score'];
            }
            $ranked = self::rank( $scores );
            $top    = $ranked[0];
            $second = $ranked[1] ?? null;
            $inspected = $counts[ $top ]['inspected'];
            $result['confidence']['language'] = [
                'score'     => $scores[ $top ],
                'margin'    => $scores[ $top ] - ( null === $second ? 0 : $scores[ $second ] ),
                'matched'   => $counts[ $top ]['matched'],
                'inspected' => $inspected,
                'share'     => $inspected > 0 ? round( $counts[ $top ]['matched'] / $inspected, 3 ) : 0.0,
            ];
            if ( 0 === $scores[ $top ] ) {
                $result['refused'] = self::REFUSED_NO_LANGUAGE;
                $result['message'] = 'No colour or font of any design language was found on this page. Pass from_language.';

                return $result;
            }
            if ( null !== $second && $scores[ $second ] === $scores[ $top ] ) {
                $result['refused']   = self::REFUSED_LANGUAGE_TIE;
                $result['runner_up'] = [ 'language' => $second, 'score' => $scores[ $second ] ];
                $result['message']   = sprintf( 'The page matches the design languages %s and %s equally. Pass from_language.', $top, $second );

                return $result;
            }
            if ( $scores[ $top ] < self::LANGUAGE_MIN_SCORE || $result['confidence']['language']['share'] < self::LANGUAGE_MIN_SHARE ) {
                $result['refused'] = self::REFUSED_LOW_CONFIDENCE;
                $result['message'] = sprintf( 'Too little of this page matches a design language to tell which one it was built with (best: %s, %d of %d colours and fonts). Pass from_language.', $top, $counts[ $top ]['matched'], $inspected );

                return $result;
            }
            $languageId = $top;
            if ( null !== $second ) {
                $result['runner_up'] = [ 'language' => $second, 'score' => $scores[ $second ] ];
            }
        }
        $result['language'] = $languageId;
        $language           = DesignLanguages::get( $languageId );
        $own                = is_array( $language ) ? (string) ( $language['kit'] ?? '' ) : '';

        if ( null !== $kitId ) {
            $result['kit'] = $kitId;

            return $result;
        }

        $table  = $table ?? ContextTable::current();
        $scores = self::kitScores( $page, $languageId, $table );
        $ids    = array_keys( $scores );
        $top    = $ids[0];
        $none   = 0 === $scores[ $top ]['blocks'];
        $pick   = $none ? ( null !== $to ? $to->kitId : $own ) : $top;
        $second = null;
        foreach ( $ids as $id ) {
            if ( $id !== $pick ) {
                $second = $id;
                break;
            }
        }
        $result['confidence']['kit'] = [
            'blocks'    => $scores[ $pick ]['blocks'],
            'leaves'    => $scores[ $pick ]['leaves'],
            'decorated' => $scores[ $pick ]['decorated'],
            'share'     => $scores[ $pick ]['decorated'] > 0 ? round( $scores[ $pick ]['blocks'] / $scores[ $pick ]['decorated'], 3 ) : 0.0,
            'margin'    => null === $second ? $scores[ $pick ]['leaves'] : $scores[ $pick ]['leaves'] - $scores[ $second ]['leaves'],
        ];
        if ( null !== $second ) {
            $result['runner_up'] = ( $result['runner_up'] ?? [] ) + [ 'kit' => $second, 'blocks' => $scores[ $second ]['blocks'], 'leaves' => $scores[ $second ]['leaves'] ];
        }

        $floor = $scores[ $pick ]['blocks'] <= 1 ? self::KIT_SINGLE_BLOCK_FLOOR : self::KIT_MARGIN_FLOOR;
        $result['confidence']['kit']['floor'] = $floor;
        // Every kit within the floor that would unresolve the page differently makes the detection unsafe.
        $target   = $to ?? Design::make( $languageId, $pick );
        $pickPage = DesignSwitch::unresolve( $page, Design::make( $languageId, $pick ), $target, $table );
        foreach ( $ids as $id ) {
            if ( $id === $pick || $scores[ $pick ]['leaves'] - $scores[ $id ]['leaves'] >= $floor ) {
                continue;
            }
            if ( DesignSwitch::unresolve( $page, Design::make( $languageId, $id ), $target, $table ) === $pickPage ) {
                continue; // harmless: the switch writes the same page whichever is taken
            }
            $result['refused']   = self::REFUSED_KIT_TIE;
            $result['runner_up'] = [ 'kit' => $id, 'blocks' => $scores[ $id ]['blocks'], 'leaves' => $scores[ $id ]['leaves'] ] + ( $result['runner_up'] ?? [] );
            $result['message']   = sprintf( 'The style kits %s and %s explain this page almost equally well and would restyle it differently. Pass from_kit.', $pick, $id );

            return $result;
        }
        $result['kit'] = $pick;
        if ( $none ) {
            $result['source']['kit'] = 'none';
        }

        return $result;
    }

    /**
     * Every kit's score on the page under the language, ranked: matched blocks, then matched leaves (high first), then
     * `StyleKits::ids()` order.
     *
     * @return array<string,array{blocks:int, leaves:int, decorated:int}>
     */
    public static function kitScores( string $page, string $languageId, ?ContextTable $table = null ): array
    {
        $blocks = self::blocks( $page );
        $paths  = DesignSwitch::mediaPaths( $table );
        $scores = [];
        foreach ( StyleKits::ids() as $id ) {
            $scores[ $id ] = self::kitScore( $blocks, Design::make( $languageId, $id ), $paths );
        }
        $order = array_flip( StyleKits::ids() );
        $ids   = array_keys( $scores );
        usort( $ids, static function ( string $a, string $b ) use ( $scores, $order ): int {
            return [ $scores[ $b ]['blocks'], $scores[ $b ]['leaves'], $order[ $a ] ] <=> [ $scores[ $a ]['blocks'], $scores[ $a ]['leaves'], $order[ $b ] ];
        } );
        $out = [];
        foreach ( $ids as $id ) {
            $out[ $id ] = $scores[ $id ];
        }

        return $out;
    }

    /**
     * The page's blocks with attributes: [name, decoded attrs, string leaves].
     *
     * @return list<array{0:string,1:\stdClass,2:list<array{path:string,key:string,value:string,offset:int,length:int}>}>
     */
    private static function blocks( string $page ): array
    {
        $out = [];
        foreach ( PageScan::scan( $page, [] ) as $block ) {
            if ( null === $block['json'] ) {
                continue;
            }
            $attrs = BlockAttrs::decode( $block['json'] );
            if ( null !== $attrs ) {
                $out[] = [ $block['name'], $attrs, JsonLeaves::strings( $block['json'] ) ];
            }
        }

        return $out;
    }

    /**
     * The language's score (colour/font leaf matches plus rhythm blocks), the colour/font leaves matched and the leaves
     * inspected (every `family` leaf and every colour leaf that reads as a colour).
     *
     * @param list<array{0:string,1:\stdClass,2:list<array<string,mixed>>}> $blocks
     *
     * @return array{score:int, matched:int, inspected:int}
     */
    private static function languageScore( array $blocks, string $id ): array
    {
        $language = DesignLanguages::get( $id );
        if ( null === $language ) {
            return [ 'score' => 0, 'matched' => 0, 'inspected' => 0 ];
        }
        $colours = [];
        foreach ( (array) ( $language['colors'] ?? [] ) as $value ) {
            $key = is_string( $value ) ? Bands::colourKey( $value ) : null;
            if ( null !== $key ) {
                $colours[ $key ] = true;
            }
        }
        $fonts = [];
        foreach ( (array) ( $language['fonts'] ?? [] ) as $family ) {
            if ( is_string( $family ) && '' !== $family ) {
                $fonts[ $family ] = true;
            }
        }
        $exp   = DesignSwitch::expectations( Design::make( $id, (string) $language['kit'] ) );
        $score     = 0;
        $matched   = 0;
        $inspected = 0;
        foreach ( $blocks as [ $name, $attrs, $leaves ] ) {
            foreach ( $leaves as $leaf ) {
                if ( self::FONT_KEY === $leaf['key'] ) {
                    ++$inspected;
                    $matched += isset( $fonts[ $leaf['value'] ] ) ? 1 : 0;
                } elseif ( in_array( $leaf['key'], self::COLOUR_KEYS, true ) ) {
                    $key = Bands::colourKey( $leaf['value'] );
                    if ( null !== $key ) {
                        ++$inspected;
                        $matched += isset( $colours[ $key ] ) ? 1 : 0;
                    }
                }
            }
            foreach ( MarkerSpace::languageCandidates( $name ) as $candidate ) {
                if ( ! isset( $candidate['rhythm'] ) ) {
                    continue;
                }
                $prepared = $exp->prepared( $name, $candidate );
                if ( null !== $prepared && Leaves::countPrepared( $attrs, $prepared ) > 0 ) {
                    ++$score;
                    break;
                }
            }
        }

        return [ 'score' => $score + $matched, 'matched' => $matched, 'inspected' => $inspected ];
    }

    /**
     * @param list<array{0:string,1:\stdClass,2:list<array<string,mixed>>}> $blocks
     *
     * @return array{blocks:int, leaves:int, decorated:int}
     */
    private static function kitScore( array $blocks, Design $d, array $paths ): array
    {
        $exp       = DesignSwitch::expectations( $d );
        $media     = StyleKits::valueToken( $d->kit, 'radius-media' );
        $mediaKey  = null === $media || self::isFlush( $media ) ? null : Leaves::encode( (object) $media ); // a flush image is evidence of nothing
        $matched   = 0;
        $leaves    = 0;
        $decorated = 0;
        foreach ( $blocks as [ $name, $attrs ] ) {
            $best = 0;
            foreach ( MarkerSpace::kitCandidates( $name ) as $candidate ) {
                $prepared = $exp->prepared( $name, $candidate );
                if ( null === $prepared ) {
                    continue;
                }
                $best = max( $best, Leaves::countPrepared( $attrs, $prepared ) );
            }
            foreach ( $paths[ $name ] ?? [] as $path ) {
                $value = self::at( $attrs, $path );
                if ( null !== $mediaKey && $value instanceof \stdClass && Leaves::encode( $value ) === $mediaKey ) {
                    $best += count( Leaves::of( $value ) );
                }
            }
            if ( $best > 0 ) {
                ++$matched;
                $leaves += $best;
                ++$decorated;
            } elseif ( [] !== MarkerSpace::kitCandidates( $name ) && MarkerRecovery::isDecorated( $name, $attrs, $exp ) ) {
                ++$decorated;
            }
        }

        return [ 'blocks' => $matched, 'leaves' => $leaves, 'decorated' => $decorated ];
    }

    /** @param array<string,string> $radius */
    private static function isFlush( array $radius ): bool
    {
        foreach ( $radius as $v ) {
            if ( 1 !== preg_match( '/^0(?:px|%|em|rem)?$/', (string) $v ) ) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $path */
    private static function at( \stdClass $attrs, array $path ): mixed
    {
        $node = $attrs;
        foreach ( $path as $key ) {
            if ( ! $node instanceof \stdClass || ! property_exists( $node, $key ) ) {
                return null;
            }
            $node = $node->{$key};
        }

        return $node;
    }

    /**
     * Ids sorted by score (high first), ties in the given order.
     *
     * @param array<string,int> $scores
     *
     * @return list<string>
     */
    private static function rank( array $scores ): array
    {
        $order = array_flip( array_keys( $scores ) );
        $ids   = array_keys( $scores );
        usort( $ids, static fn ( string $a, string $b ): int => [ $scores[ $b ], $order[ $a ] ] <=> [ $scores[ $a ], $order[ $b ] ] );

        return $ids;
    }
}
