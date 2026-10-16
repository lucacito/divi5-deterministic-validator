<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

use Divi5Validator\Violation;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * How the validator's violations are shown to the AI. The validator itself is never changed; this only shortens what is sent back
 * so a refusal costs few tokens. A refusal of a badly nested page used to repeat the same 70-type "Allowed: [...]" list in every
 * violation (about 5,400 tokens for 96 violations on a real page). Now:
 *  - the first violation for a parent type keeps its full allowed list; later ones for the same parent point back to it;
 *  - at most MAX_SHOWN violations are listed, then one MORE_VIOLATIONS entry says how many were left out and of which kinds.
 * The verdict (`valid`) is decided before this and is never affected, and the MORE_VIOLATIONS entry carries the true count.
 */
final class ViolationReport
{
    public const MAX_SHOWN = 25;

    /** @param list<Violation> $violations @return list<array{code:string,message:string,path:string}> */
    public static function compact( array $violations ): array
    {
        $shown = [];
        $seen  = [];
        $more  = [];
        foreach ( $violations as $i => $v ) {
            $row = $v->toArray();
            if ( $i >= self::MAX_SHOWN ) {
                $more[ (string) $row['code'] ] = ( $more[ (string) $row['code'] ] ?? 0 ) + 1;
                continue;
            }
            if ( 1 === preg_match( "/valid child of '([^']+)'\. Allowed: \[.*\]\.\$/s", (string) $row['message'], $m ) ) {
                if ( isset( $seen[ $m[1] ] ) ) {
                    $row['message'] = (string) preg_replace( '/ Allowed: \[.*\]\.$/s', " Allowed: the same list as the first violation about '{$m[1]}'.", (string) $row['message'] );
                }
                $seen[ $m[1] ] = true;
            }
            $shown[] = $row;
        }
        if ( [] !== $more ) {
            arsort( $more );
            $kinds   = implode( ', ', array_map( static fn ( string $c, int $n ): string => "{$n} {$c}", array_keys( $more ), $more ) );
            $shown[] = [ 'code' => 'MORE_VIOLATIONS', 'message' => array_sum( $more ) . " more violations are not listed ({$kinds}). Fix the ones above and check again.", 'path' => '' ];
        }

        return $shown;
    }
}
