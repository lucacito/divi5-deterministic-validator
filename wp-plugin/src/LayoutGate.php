<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

use Divi5Validator\ValidationResult;
use Divi5Validator\Validator;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The one gate every layout write and preview goes through: the deterministic validator first, then the
 * no-custom-code policy. Returns the validator's own result type so every existing caller keeps working.
 */
final class LayoutGate
{
    /** @param ?string $baseline the page's current stored content (code already there is preserved), or null for new content */
    public static function check( string $content, ?string $baseline = null ): ValidationResult
    {
        // Oversized content never reaches the validator: only the policy's single "too large" violation is returned.
        if ( CodePolicy::tooLarge( $content ) ) {
            return new ValidationResult( CodePolicy::violations( $content ) );
        }

        return self::merge( ( new Validator() )->validateContent( $content ), CodePolicy::violations( $content, $baseline ) );
    }

    /** A full layout envelope (JSON with a `post_content` string). */
    public static function checkJson( string $json ): ValidationResult
    {
        $decoded = json_decode( $json, true );
        $content = is_array( $decoded ) && isset( $decoded['post_content'] ) && is_string( $decoded['post_content'] ) ? $decoded['post_content'] : null;
        if ( null !== $content && CodePolicy::tooLarge( $content ) ) {
            return new ValidationResult( CodePolicy::violations( $content ) );
        }
        $result = ( new Validator() )->validate( $json );

        return null === $content ? $result : self::merge( $result, CodePolicy::violations( $content ) );
    }

    /** @param list<\Divi5Validator\Violation> $policy */
    private static function merge( ValidationResult $result, array $policy ): ValidationResult
    {
        return [] === $policy ? $result : new ValidationResult( array_merge( $result->violations(), $policy ) );
    }
}
