<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Companion;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Explicit batches: an agent calls start-batch, works with any tool, then calls end-batch. Only the token, the user, a label and
 * the two times are kept (one Pro option, the last 20); the changes themselves are the free plugin's history rows.
 */
final class AgentSessions
{
    public const OPTION = 'aied_pro_agent_sessions';
    public const KEEP   = 20;

    /** @return list<array{token:string, user:int, label:string, started:string, ended:?string, start_row:?int, end_row:?int}> */
    public static function all(): array
    {
        $raw = get_option( self::OPTION, [] );
        $out = [];
        foreach ( is_array( $raw ) ? $raw : [] as $s ) {
            if ( is_array( $s ) && is_string( $s['token'] ?? null ) && is_int( $s['user'] ?? null ) && is_string( $s['started'] ?? null ) ) {
                $out[] = [ 'token' => $s['token'], 'user' => $s['user'], 'label' => (string) ( $s['label'] ?? '' ), 'started' => $s['started'], 'ended' => is_string( $s['ended'] ?? null ) ? $s['ended'] : null, 'start_row' => is_int( $s['start_row'] ?? null ) ? $s['start_row'] : null, 'end_row' => is_int( $s['end_row'] ?? null ) ? $s['end_row'] : null ];
            }
        }

        return $out;
    }

    /** Starts a session for the user; one that is still open is closed first. @return array{token:string, user:int, label:string, started:string, ended:?string, start_row:?int, end_row:?int} */
    public static function start( int $user, string $label, ?string $now = null, ?string $token = null, ?int $row = null ): array
    {
        $now      = $now ?? gmdate( 'Y-m-d\TH:i:s\Z' );
        $row      = $row ?? self::latestRow();
        $sessions = self::all();
        foreach ( $sessions as &$s ) {
            if ( $s['user'] === $user && null === $s['ended'] ) {
                $s['ended']   = $now;
                $s['end_row'] = $row;
            }
        }
        unset( $s );
        // start_row is the newest history row at this moment: the session owns the rows numbered above it. Row numbers order
        // two changes made in the same second, which the one-second clock cannot.
        $new        = [ 'token' => $token ?? bin2hex( random_bytes( 4 ) ), 'user' => $user, 'label' => self::label( $label ), 'started' => $now, 'ended' => null, 'start_row' => $row, 'end_row' => null ];
        $sessions[] = $new;
        update_option( self::OPTION, array_slice( $sessions, -self::KEEP ), false );

        return $new;
    }

    /** Ends the user's open session. Null when there is none. @return ?array{token:string, user:int, label:string, started:string, ended:?string, start_row:?int, end_row:?int} */
    public static function end( int $user, ?string $now = null, ?int $row = null ): ?array
    {
        $now      = $now ?? gmdate( 'Y-m-d\TH:i:s\Z' );
        $row      = $row ?? self::latestRow();
        $sessions = self::all();
        $ended    = null;
        foreach ( $sessions as &$s ) {
            if ( $s['user'] === $user && null === $s['ended'] ) {
                $s['ended']   = $now;
                $s['end_row'] = $row;
                $ended        = $s;
            }
        }
        unset( $s );
        if ( null !== $ended ) {
            update_option( self::OPTION, $sessions, false );
        }

        return $ended;
    }

    /** The newest history row number now, or null when the free plugin cannot say (the time is used instead). */
    private static function latestRow(): ?int
    {
        if ( ! method_exists( \AiEditorDivi5\WP\HistoryStore::class, 'latestRowId' ) ) {
            return null;
        }
        try {
            return \AiEditorDivi5\WP\HistoryStore::latestRowId();
        } catch ( \Throwable $e ) {
            return null;
        }
    }

    private static function label( string $label ): string
    {
        $label = trim( (string) preg_replace( '/[\x00-\x1F\x7F<>]+/', ' ', $label ) );

        return '' === $label ? 'Agent session' : mb_substr( $label, 0, 80 );
    }
}
