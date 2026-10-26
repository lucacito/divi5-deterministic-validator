<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Design;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The outcome of one design switch of one page (spec 2026-10-25-pro-design-switch-design.md section 5.5). A value
 * object: `DesignSwitch` builds it, the tool reports `toArray()` and saves `content` (never returned to the client).
 *
 * Every inspected leaf (a string at key `color`, a colour-readable or global-reference string at key `value`, a string at
 * key `family`) is counted exactly once: under `switched` (`colours` / `fonts`, by role), under `assumed` (kind
 * `colour` / `font`) or under `left` (`unknown_colours`, `unknown_fonts`, `ambiguous`, `global_refs`). `inspected` is
 * their number, so the report never hides a decision. Markers, images and the blocks that were not recovered are
 * counted besides (`switched.markers|type|rhythm|images`, `assumed` kind `marker` / `image`, `left.drifted|unclassified`).
 * In `conform` mode (spec 5.6) a drifted block that was conformed is counted under `switched.markers` and listed under
 * `conformed` (block, name, marker, the differing leaf paths, how many leaves it overrode) instead of `left.drifted`;
 * every removed leaf whose value differed from the source patch is listed under `overridden` (block, name, path, old
 * value). Both are empty in `leave` mode.
 * Pure: every map is in a fixed order (roles and values sorted, lists in document order).
 */
final class SwitchResult
{
    /** The `error` of a page the target pipeline did not resolve (the original page is returned). */
    public const ERROR_UNRESOLVED = 'unresolved';

    /**
     * @param array{markers:array<string,int>, type:array<string,int>, rhythm:array<string,int>, colours:array<string,int>, fonts:array<string,int>, images:int} $switched
     * @param array{unknown_colours:array<string,int>, unknown_fonts:array<string,int>, ambiguous:list<array<string,mixed>>, drifted:list<array<string,mixed>>, unclassified:list<array<string,mixed>>, global_refs:int} $left
     * @param list<array<string,mixed>> $assumed
     * @param list<array<string,mixed>> $conformed  `conform` mode: the drifted blocks that got their marker
     * @param list<array<string,mixed>> $overridden `conform` mode: every removed leaf whose value differed (path, old value)
     */
    public function __construct(
        public readonly string $content,
        public readonly bool $changed,
        public readonly array $switched,
        public readonly array $left,
        public readonly array $assumed,
        public readonly int $inspected,
        public readonly ?string $error = null,
        public readonly array $conformed = [],
        public readonly array $overridden = []
    ) {
    }

    /** An empty report (nothing inspected). */
    public static function untouched( string $page, ?string $error = null ): self
    {
        return new self( $page, false, self::emptySwitched(), self::emptyLeft(), [], 0, $error );
    }

    /** @return array{markers:array<string,int>, type:array<string,int>, rhythm:array<string,int>, colours:array<string,int>, fonts:array<string,int>, images:int} */
    public static function emptySwitched(): array
    {
        return [ 'markers' => [], 'type' => [], 'rhythm' => [], 'colours' => [], 'fonts' => [], 'images' => 0 ];
    }

    /** @return array{unknown_colours:array<string,int>, unknown_fonts:array<string,int>, ambiguous:list<array<string,mixed>>, drifted:list<array<string,mixed>>, unclassified:list<array<string,mixed>>, global_refs:int} */
    public static function emptyLeft(): array
    {
        return [ 'unknown_colours' => [], 'unknown_fonts' => [], 'ambiguous' => [], 'drifted' => [], 'unclassified' => [], 'global_refs' => 0 ];
    }

    /** How many roles the switch recovered (markers, images, colour and font tokens, assumed ones included). */
    public function recovered(): int
    {
        $n = $this->switched['images'] + count( $this->assumed );
        foreach ( [ 'markers', 'type', 'rhythm', 'colours', 'fonts' ] as $k ) {
            $n += array_sum( $this->switched[ $k ] );
        }

        return $n;
    }

    /**
     * The report as the tool returns it: every list and value map cut to `$max` entries, each with a `<name>_count`
     * beside it (the full number of entries). `content` is not part of it.
     *
     * @return array<string,mixed>
     */
    public function toArray( int $max = 25 ): array
    {
        $max  = max( 0, $max );
        $left = [];
        foreach ( $this->left as $name => $value ) {
            if ( is_array( $value ) ) {
                $left[ $name ]            = array_is_list( $value ) ? array_slice( $value, 0, $max ) : array_slice( $value, 0, $max, true );
                $left[ $name . '_count' ] = count( $value );
            } else {
                $left[ $name ] = $value;
            }
        }

        return [
            'changed'       => $this->changed,
            'switched'      => $this->switched,
            'left'          => $left,
            'assumed'       => array_slice( $this->assumed, 0, $max ),
            'assumed_count' => count( $this->assumed ),
            'inspected'     => $this->inspected,
            'conformed'        => array_slice( $this->conformed, 0, $max ),
            'conformed_count'  => count( $this->conformed ),
            'overridden'       => array_slice( $this->overridden, 0, $max ),
            'overridden_count' => count( $this->overridden ),
            'error'         => $this->error,
        ];
    }
}
