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

    private const PATTERNS = [
        'contains a script, style, iframe, object or embed tag' => '/<\s*(?:script|style|iframe|object|embed)\b/i',
        'contains a PHP tag'                                    => '/<\s*\?/',
        'contains a javascript:, vbscript: or data:text/html URL' => '/(?:java|vb)script\s*:|data\s*:\s*text\s*\/\s*html/i',
        'contains an HTML event-handler attribute'              => '/<[a-z][^>]*\son[a-z]{3,}\s*=/is',
    ];

    /** @return ?string a reason that completes "the value ...", or null when the value is allowed */
    public static function refusal( string $value ): ?string
    {
        if ( 1 !== preg_match( '//u', $value ) ) {
            return 'is not valid UTF-8';
        }
        if ( mb_strlen( $value ) > self::MAX_CHARS ) {
            return 'is longer than 10,000 characters';
        }
        foreach ( self::PATTERNS as $reason => $pattern ) {
            if ( 1 === preg_match( $pattern, $value ) ) {
                return $reason;
            }
        }

        return null;
    }
}
