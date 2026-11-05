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

    /** @return list<array{token:string, user:int, label:string, started:string, ended:?string}> */
    public static function all(): array
    {
        $raw = get_option( self::OPTION, [] );
        $out = [];
        foreach ( is_array( $raw ) ? $raw : [] as $s ) {
            if ( is_array( $s ) && is_string( $s['token'] ?? null ) && is_int( $s['user'] ?? null ) && is_string( $s['started'] ?? null ) ) {
                $out[] = [ 'token' => $s['token'], 'user' => $s['user'], 'label' => (string) ( $s['label'] ?? '' ), 'started' => $s['started'], 'ended' => is_string( $s['ended'] ?? null ) ? $s['ended'] : null ];
            }
        }

        return $out;
    }

    /** Starts a session for the user; one that is still open is closed first. @return array{token:string, user:int, label:string, started:string, ended:?string} */
    public static function start( int $user, string $label, ?string $now = null, ?string $token = null ): array
    {
        $now      = $now ?? gmdate( 'Y-m-d\TH:i:s\Z' );
        $sessions = self::all();
        foreach ( $sessions as &$s ) {
            if ( $s['user'] === $user && null === $s['ended'] ) {
                $s['ended'] = $now;
            }
        }
        unset( $s );
        $new        = [ 'token' => $token ?? bin2hex( random_bytes( 4 ) ), 'user' => $user, 'label' => self::label( $label ), 'started' => $now, 'ended' => null ];
        $sessions[] = $new;
        update_option( self::OPTION, array_slice( $sessions, -self::KEEP ), false );

        return $new;
    }

    /** Ends the user's open session. Null when there is none. @return ?array{token:string, user:int, label:string, started:string, ended:?string} */
    public static function end( int $user, ?string $now = null ): ?array
    {
        $now      = $now ?? gmdate( 'Y-m-d\TH:i:s\Z' );
        $sessions = self::all();
        $ended    = null;
        foreach ( $sessions as &$s ) {
            if ( $s['user'] === $user && null === $s['ended'] ) {
                $s['ended'] = $now;
                $ended      = $s;
            }
        }
        unset( $s );
        if ( null !== $ended ) {
            update_option( self::OPTION, $sessions, false );
        }

        return $ended;
    }

    private static function label( string $label ): string
    {
        $label = trim( (string) preg_replace( '/[\x00-\x1F\x7F<>]+/', ' ', $label ) );

        return '' === $label ? 'Agent session' : mb_substr( $label, 0, 80 );
    }
}
