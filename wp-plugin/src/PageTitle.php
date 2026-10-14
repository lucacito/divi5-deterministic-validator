<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The rules for the optional `title` argument of update_page_layout / PUT /pages/{id}: a plain-text, one-line
 * WordPress page title. Pure (no WordPress calls). A failure is an input error (400 / -32602), never a validator verdict.
 * WordPress's own sanitising still runs when the title is saved; callers report the re-read stored value.
 */
final class PageTitle
{
    public const MAX_LENGTH = 200;

    /** @return array{ok:bool, present:bool, title:?string, error:?string} */
    public static function parse( array $args, string $key = 'title' ): array
    {
        if ( ! array_key_exists( $key, $args ) || null === $args[ $key ] ) {
            return [ 'ok' => true, 'present' => false, 'title' => null, 'error' => null ];
        }
        $raw = $args[ $key ];
        if ( ! is_string( $raw ) ) {
            return self::fail( "{$key} must be a string." );
        }
        if ( 1 !== preg_match( '//u', $raw ) ) {
            return self::fail( "{$key} must be valid UTF-8 text." );
        }
        // Trim ASCII whitespace and the Unicode space separators (including NBSP) at both ends.
        $title = preg_replace( '/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $raw );
        if ( null === $title || '' === $title ) {
            return self::fail( "{$key} must not be empty." );
        }
        if ( 1 === preg_match( '/[\p{Cc}\p{Zl}\p{Zp}]/u', $title ) ) {
            return self::fail( "{$key} must be one line of text: no line breaks, tabs or control characters." );
        }
        if ( false !== strpbrk( $title, '<>' ) ) {
            return self::fail( "{$key} is plain text: no HTML or markup (the characters < and > are not allowed)." );
        }
        $length = preg_match_all( '/./us', $title );
        if ( $length > self::MAX_LENGTH ) {
            return self::fail( sprintf( '%s is too long: %d characters (the limit is %d).', $key, $length, self::MAX_LENGTH ) );
        }

        return [ 'ok' => true, 'present' => true, 'title' => $title, 'error' => null ];
    }

    /** @return array{changed:bool, before:string, after:string, restorable?:false} */
    public static function report( string $before, string $after, bool $changed ): array
    {
        $out = [ 'changed' => $changed, 'before' => $before, 'after' => $after ];
        if ( $changed ) {
            $out['restorable'] = false;
        }

        return $out;
    }

    public static function notRestorableMessage( string $before ): string
    {
        return 'The page title is not part of the saved versions: restore_page_version will not bring the old title back. The old title was "'
            . $before . '"; to undo this, call again with that title.';
    }

    public static function unchangedMessage( string $title ): string
    {
        return 'The page title is already "' . $title . '". Nothing was changed.';
    }

    public static function restoreNote( string $currentTitle ): string
    {
        return 'Restoring brings back the page content only. The page title was left as it is: "' . $currentTitle . '".';
    }

    /** @return array{ok:false, present:false, title:null, error:string} */
    private static function fail( string $error ): array
    {
        return [ 'ok' => false, 'present' => false, 'title' => null, 'error' => $error ];
    }
}
