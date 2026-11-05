<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Companion;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Groups the free plugin's page-history rows into "batches": what one agent did in one sitting, whichever tool it used.
 *
 * Nothing is recorded for this. A batch is worked out from rows that already exist (who saved, from which source, when), so
 * there is no new table. Two ways to group:
 *  - an explicit session: the agent calls start-batch, works, and calls end-batch (or just stops). Every change by that user
 *    between the two belongs to the session, whatever the source.
 *  - inferred: changes by the same user from the same source (the same application password, the same ability, the plugin's
 *    own tools) with no gap longer than WINDOW seconds belong together.
 *
 * Pure: rows in, batches out. AgentBatchUndo decides what an undo may touch.
 */
final class AgentBatches
{
    /** Longest silence inside one inferred batch. */
    public const WINDOW = 600;

    /** Restores are undo operations, not changes to undo. */
    private const IGNORED_TOOLS = [ 'restore' ];

    /** The free plugin's own tools, all one source for grouping. */
    private const SOURCES = [ 'ability', 'application_password', 'rest', 'builder', 'unknown' ];

    /**
     * @param list<array{page_id:int, version_id:int, saved_at:string, tool:string, actor:int, label:?string}> $rows any order
     * @param list<array{token:string, user:int, label:string, started:string, ended:?string}> $sessions explicit sessions
     * @return list<array<string,mixed>> newest batch first
     */
    public static function group( array $rows, array $sessions = [], ?string $now = null ): array
    {
        $rows = array_values( array_filter( $rows, static fn ( array $r ): bool => ! in_array( $r['tool'], self::IGNORED_TOOLS, true ) ) );
        usort( $rows, static fn ( array $a, array $b ): int => [ $a['saved_at'], $a['page_id'], $a['version_id'] ] <=> [ $b['saved_at'], $b['page_id'], $b['version_id'] ] );

        $now     = $now ?? gmdate( 'Y-m-d\TH:i:s\Z' );
        $batches = [];
        $taken   = [];

        foreach ( $sessions as $session ) {
            $end    = $session['ended'] ?? $now;
            $member = [];
            foreach ( $rows as $i => $r ) {
                if ( isset( $taken[ $i ] ) || $r['actor'] !== (int) $session['user'] || $r['saved_at'] < $session['started'] || $r['saved_at'] > $end ) {
                    continue;
                }
                $member[ $i ] = $r;
            }
            if ( [] === $member ) {
                continue;
            }
            foreach ( array_keys( $member ) as $i ) {
                $taken[ $i ] = true;
            }
            $batches[] = self::describe( 'session-' . $session['token'], 'session', (int) $session['user'], $session['label'], array_values( $member ) );
        }

        $open = []; // "actor|source" => index in $groups
        $groups = [];
        foreach ( $rows as $i => $r ) {
            if ( isset( $taken[ $i ] ) ) {
                continue;
            }
            $key = $r['actor'] . '|' . self::sourceKey( $r );
            $at  = strtotime( $r['saved_at'] );
            if ( isset( $open[ $key ] ) && false !== $at && $at - $groups[ $open[ $key ] ]['last'] <= self::WINDOW ) {
                $groups[ $open[ $key ] ]['rows'][] = $r;
                $groups[ $open[ $key ] ]['last']   = $at;
                continue;
            }
            $groups[]      = [ 'rows' => [ $r ], 'last' => (int) $at, 'key' => $key ];
            $open[ $key ]  = array_key_last( $groups );
        }
        foreach ( $groups as $g ) {
            $first     = $g['rows'][0];
            $source    = self::sourceName( $first );
            $batches[] = self::describe( 'inferred-' . $first['actor'] . '-' . substr( md5( $g['key'] ), 0, 6 ) . '-' . strtotime( $first['saved_at'] ), 'inferred', (int) $first['actor'], $source, $g['rows'] );
        }

        usort( $batches, static fn ( array $a, array $b ): int => strcmp( (string) $b['ended'], (string) $a['ended'] ) );

        return $batches;
    }

    /** The grouping key of one row's source. @param array<string,mixed> $r */
    public static function sourceKey( array $r ): string
    {
        $tool = (string) $r['tool'];

        return in_array( $tool, self::SOURCES, true ) ? $tool . ':' . (string) ( $r['label'] ?? '' ) : 'plugin';
    }

    /** A plain-English source. @param array<string,mixed> $r */
    public static function sourceName( array $r ): string
    {
        $tool  = (string) $r['tool'];
        $label = trim( (string) ( $r['label'] ?? '' ) );

        return match ( $tool ) {
            'ability'              => 'ability' . ( '' !== $label ? ' ' . $label : '' ),
            'application_password' => 'application password' . ( '' !== $label ? ' ' . $label : '' ),
            'rest'                 => 'REST API',
            'builder'              => 'Divi builder',
            'unknown'              => 'unknown source',
            default                => 'JHMG AI Editor tools',
        };
    }

    /**
     * @param list<array<string,mixed>> $rows oldest first
     * @return array<string,mixed>
     */
    private static function describe( string $id, string $kind, int $actor, string $label, array $rows ): array
    {
        $pages = [];
        foreach ( $rows as $r ) {
            $p = $r['page_id'];
            if ( ! isset( $pages[ $p ] ) ) {
                // The oldest snapshot of a page in the batch holds the page as it was before the batch touched it.
                $pages[ $p ] = [ 'page_id' => $p, 'restore_version_id' => $r['version_id'], 'last_version_id' => $r['version_id'], 'first_saved_at' => $r['saved_at'], 'last_saved_at' => $r['saved_at'], 'changes' => 0 ];
            }
            ++$pages[ $p ]['changes'];
            $pages[ $p ]['last_saved_at']   = max( $pages[ $p ]['last_saved_at'], $r['saved_at'] );
            $pages[ $p ]['last_version_id'] = max( $pages[ $p ]['last_version_id'], $r['version_id'] );
        }

        return [
            'id'         => $id,
            'kind'       => $kind,
            'actor'      => $actor,
            'label'      => $label,
            'started'    => $rows[0]['saved_at'],
            'ended'      => max( array_column( $rows, 'saved_at' ) ),
            'changes'    => count( $rows ),
            'page_count' => count( $pages ),
            'pages'      => array_values( $pages ),
        ];
    }
}
