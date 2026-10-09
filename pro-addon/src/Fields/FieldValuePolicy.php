<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Fields;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * What a custom field value may contain. A value is plain text or the post-content HTML WordPress already allows; it is never
 * custom code. The same kinds of code the free plugin refuses in layouts are refused here, in plain text. This is a refusal
 * list in front of `wp_kses_post()` (the tool also refuses any value kses would change), not a sanitizer.
 */
final class FieldValuePolicy
{
    public const MAX_CHARS = 10000;
    private const UNCHECKED = 'could not be checked safely';

    private const PATTERNS = [
        'contains a script, style, iframe, object or embed tag' => '/<\s*(?:script|style|iframe|object|embed)\b/i',
        'contains a PHP tag'                                    => '/<\s*\?/',
        'contains an HTML event-handler attribute'              => '/<[a-z][^>]*\son[a-z]{3,}\s*=/is',
    ];

    private const SCHEME_REASON  = 'contains a javascript:, vbscript: or data:text/html URL';
    private const SCHEME_PATTERN = '/(?:java|vb)script\s*:|data\s*:\s*text\s*\/\s*html/i';

    /** A reason when the pattern matches, and also when it could not be evaluated (preg_match returned false): fail closed. */
    private static function scan( string $reason, string $pattern, string $subject ): ?string
    {
        $found = preg_match( $pattern, $subject );

        return 0 === $found ? null : ( 1 === $found ? $reason : self::UNCHECKED );
    }

    /** @return ?string a reason that completes "the value ...", or null when the value is allowed */
    public static function refusal( string $value ): ?string
    {
        if ( 1 !== preg_match( '//u', $value ) ) {
            return 'is not valid UTF-8';
        }
        if ( mb_strlen( $value ) > self::MAX_CHARS ) {
            return 'is longer than 10,000 characters';
        }
        // The scheme patterns also run on the value as a browser would read it: entities decoded, then every whitespace and control byte
        // dropped ("&#106;avascript:", "java&#x09;script:", a tab or newline inside the word).
        $decoded    = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $normalised = preg_replace( '/[\x00-\x20]+/', '', $decoded );
        $checks     = [ [ self::SCHEME_REASON, self::SCHEME_PATTERN, $value ], [ self::SCHEME_REASON, self::SCHEME_PATTERN, is_string( $normalised ) ? $normalised : null ] ];
        foreach ( self::PATTERNS as $reason => $pattern ) {
            $checks[] = [ $reason, $pattern, $value ];
        }
        foreach ( $checks as [ $reason, $pattern, $subject ] ) {
            if ( null === $subject ) {
                return self::UNCHECKED; // the normalised form could not be built: fail closed
            }
            $hit = self::scan( $reason, $pattern, $subject );
            if ( null !== $hit ) {
                return $hit;
            }
        }

        return null;
    }
}
