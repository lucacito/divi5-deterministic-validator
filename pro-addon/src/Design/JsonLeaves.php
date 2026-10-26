<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Design;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Byte-preserving access to the STRING leaves of a JSON object text (spec 2026-10-25-pro-design-switch-design.md section
 * 5.3: "Leaves are rewritten in place"). A real tokenizer (RFC 8259: objects, arrays, strings with every escape, numbers,
 * literals, whitespace), never a regex alignment, so a leaf's byte span is exact whatever its neighbours hold.
 * `replace()` copies every byte outside the edited spans verbatim: an untouched block stays byte-identical.
 *
 * What is valid here is what `json_decode()` accepts for a top-level object: valid UTF-8, no control character inside a
 * string, no lone UTF-16 surrogate escape, at most `MAX_DEPTH` nested containers, only whitespace after the object.
 * Invalid text gives no leaves and is never rewritten. Pure and deterministic.
 */
final class JsonLeaves
{
    /** json_decode()'s default depth. */
    public const MAX_DEPTH = 512;

    /** The bytes that end a run of plain string content: the quote, the backslash and the control characters. */
    private const STOP = "\"\\\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0a\x0b\x0c\x0d\x0e\x0f\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1a\x1b\x1c\x1d\x1e\x1f";

    private const WS = " \t\n\r";

    /**
     * Every string leaf (object member values and list elements, never keys) in document order. `path` is the JSON path
     * (`.a.b[].c`: `.key` per object member, `[]` per list element), `key` the member's key (`''` for a list element),
     * `value` the decoded string, `offset`/`length` the byte span of the raw string content inside `$json` (the quotes
     * excluded). Invalid JSON, or a text that is not one JSON object, gives `[]`.
     *
     * @return list<array{path:string, key:string, value:string, offset:int, length:int}>
     */
    public static function strings( string $json ): array
    {
        return self::parse( $json ) ?? [];
    }

    /**
     * `$json` with the string leaves at the given offsets replaced. `$edits` maps a leaf's `offset` (as `strings()` gives it)
     * to its new RAW content, already JSON-escaped and without quotes. Every byte outside the edited spans is copied
     * verbatim. Invalid JSON is returned unchanged.
     *
     * @param array<int,string> $edits
     *
     * @throws \InvalidArgumentException when an offset is not the start of a string leaf, or new content is not the raw
     *                                   content of one JSON string
     */
    public static function replace( string $json, array $edits ): string
    {
        $leaves = self::parse( $json );
        if ( null === $leaves || [] === $edits ) {
            return $json;
        }
        $spans = [];
        foreach ( $leaves as $leaf ) {
            $spans[ $leaf['offset'] ] = $leaf['length'];
        }
        ksort( $edits );
        $out = '';
        $at  = 0;
        foreach ( $edits as $offset => $raw ) {
            if ( ! is_int( $offset ) || ! isset( $spans[ $offset ] ) ) {
                throw new \InvalidArgumentException( 'Not the start of a string leaf: ' . $offset );
            }
            if ( ! is_string( $raw ) || ! self::isRawString( $raw ) ) {
                throw new \InvalidArgumentException( 'Not the raw content of one JSON string at offset ' . $offset );
            }
            $out .= substr( $json, $at, $offset - $at ) . $raw;
            $at   = $offset + $spans[ $offset ];
        }

        return $out . substr( $json, $at );
    }

    /**
     * The end (exclusive byte offset) of the JSON object that starts at `$start` in `$text` (after optional whitespace),
     * or -1 when no valid object starts there. Only the syntax is checked (no UTF-8 or surrogate check): the page scan uses
     * it to find where a block comment's attributes end, even when a string inside them holds the comment terminator.
     */
    public static function objectEnd( string $text, int $start ): int
    {
        $i = $start;
        self::ws( $text, $i );
        if ( ( $text[ $i ] ?? '' ) !== '{' ) {
            return -1;
        }
        $leaves = null;

        return self::value( $text, $i, '', '', $leaves, 0 ) ? $i : -1;
    }

    /** @return list<array{path:string, key:string, value:string, offset:int, length:int}>|null null when invalid */
    private static function parse( string $json ): ?array
    {
        if ( 1 !== preg_match( '//u', $json ) ) {
            return null;
        }
        $i = 0;
        self::ws( $json, $i );
        if ( ( $json[ $i ] ?? '' ) !== '{' ) {
            return null;
        }
        $leaves = [];
        if ( ! self::value( $json, $i, '', '', $leaves, 0 ) ) {
            return null;
        }
        self::ws( $json, $i );

        return $i === strlen( $json ) ? $leaves : null;
    }

    /**
     * Reads one JSON value at `$i` (after whitespace) and moves `$i` past it. Collects string leaves when `$leaves` is an array.
     *
     * @param list<array{path:string, key:string, value:string, offset:int, length:int}>|null $leaves
     */
    private static function value( string $s, int &$i, string $path, string $key, ?array &$leaves, int $depth ): bool
    {
        self::ws( $s, $i );
        $c = $s[ $i ] ?? '';
        if ( '{' === $c || '[' === $c ) {
            if ( $depth >= self::MAX_DEPTH ) {
                return false;
            }

            return '{' === $c ? self::object( $s, $i, $path, $leaves, $depth + 1 ) : self::list( $s, $i, $path, $leaves, $depth + 1 );
        }
        if ( '"' === $c ) {
            $start = $i + 1;
            if ( ! self::string( $s, $i ) ) {
                return false;
            }
            if ( null !== $leaves ) {
                $raw     = substr( $s, $start, $i - 1 - $start );
                $decoded = self::decode( $raw );
                if ( null === $decoded ) {
                    return false;
                }
                $leaves[] = [ 'path' => $path, 'key' => $key, 'value' => $decoded, 'offset' => $start, 'length' => $i - 1 - $start ];
            }

            return true;
        }
        foreach ( [ 'true', 'false', 'null' ] as $literal ) {
            if ( $c === $literal[0] ) {
                if ( substr( $s, $i, strlen( $literal ) ) !== $literal ) {
                    return false;
                }
                $i += strlen( $literal );

                return true;
            }
        }
        if ( 1 === preg_match( '/\G-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?/', $s, $m, 0, $i ) ) {
            $i += strlen( $m[0] );

            return true;
        }

        return false;
    }

    /** @param list<array{path:string, key:string, value:string, offset:int, length:int}>|null $leaves */
    private static function object( string $s, int &$i, string $path, ?array &$leaves, int $depth ): bool
    {
        ++$i; // {
        self::ws( $s, $i );
        if ( ( $s[ $i ] ?? '' ) === '}' ) {
            ++$i;

            return true;
        }
        while ( true ) {
            self::ws( $s, $i );
            if ( ( $s[ $i ] ?? '' ) !== '"' ) {
                return false;
            }
            $start = $i + 1;
            if ( ! self::string( $s, $i ) ) {
                return false;
            }
            $key = '';
            if ( null !== $leaves ) {
                $decoded = self::decode( substr( $s, $start, $i - 1 - $start ) );
                if ( null === $decoded ) {
                    return false;
                }
                $key = $decoded;
            }
            self::ws( $s, $i );
            if ( ( $s[ $i ] ?? '' ) !== ':' ) {
                return false;
            }
            ++$i;
            if ( ! self::value( $s, $i, $path . '.' . $key, $key, $leaves, $depth ) ) {
                return false;
            }
            self::ws( $s, $i );
            $c = $s[ $i ] ?? '';
            ++$i;
            if ( '}' === $c ) {
                return true;
            }
            if ( ',' !== $c ) {
                return false;
            }
        }
    }

    /** @param list<array{path:string, key:string, value:string, offset:int, length:int}>|null $leaves */
    private static function list( string $s, int &$i, string $path, ?array &$leaves, int $depth ): bool
    {
        ++$i; // [
        self::ws( $s, $i );
        if ( ( $s[ $i ] ?? '' ) === ']' ) {
            ++$i;

            return true;
        }
        while ( true ) {
            if ( ! self::value( $s, $i, $path . '[]', '', $leaves, $depth ) ) {
                return false;
            }
            self::ws( $s, $i );
            $c = $s[ $i ] ?? '';
            ++$i;
            if ( ']' === $c ) {
                return true;
            }
            if ( ',' !== $c ) {
                return false;
            }
        }
    }

    /** Moves `$i` from an opening quote to just past the closing one; false on a bad escape, a control byte or no end. */
    private static function string( string $s, int &$i ): bool
    {
        $n = strlen( $s );
        ++$i; // "
        while ( $i < $n ) {
            $i += strcspn( $s, self::STOP, $i );
            $c = $s[ $i ] ?? '';
            if ( '"' === $c ) {
                ++$i;

                return true;
            }
            if ( '\\' !== $c ) {
                return false; // a control byte, or the end of the text
            }
            $e = $s[ $i + 1 ] ?? '';
            if ( 'u' === $e ) {
                if ( 1 !== preg_match( '/\G[0-9a-fA-F]{4}/', $s, $m, 0, $i + 2 ) ) {
                    return false;
                }
                $i += 6;
            } elseif ( '' !== $e && str_contains( '"\\/bfnrt', $e ) ) {
                $i += 2;
            } else {
                return false;
            }
        }

        return false;
    }

    private static function ws( string $s, int &$i ): void
    {
        if ( $i < strlen( $s ) ) {
            $i += strspn( $s, self::WS, $i );
        }
    }

    /** The decoded value of a raw string's content (already syntax-checked), or null (a lone surrogate escape). */
    private static function decode( string $raw ): ?string
    {
        if ( ! str_contains( $raw, '\\' ) ) {
            return $raw;
        }
        $v = json_decode( '"' . $raw . '"' );

        return is_string( $v ) ? $v : null;
    }

    /** Is `$raw` exactly the content of one valid JSON string (valid UTF-8, escapes, no bare quote or control byte)? */
    private static function isRawString( string $raw ): bool
    {
        if ( 1 !== preg_match( '//u', $raw ) ) {
            return false;
        }
        $text = '"' . $raw . '"';
        $i    = 0;

        return self::string( $text, $i ) && strlen( $text ) === $i && null !== self::decode( $raw );
    }
}
