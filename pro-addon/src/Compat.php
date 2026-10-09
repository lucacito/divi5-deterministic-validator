<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Whether the free plugin is there and new enough for the add-on to run. Pure: the caller passes
 * the free plugin's version and a class-existence callback, so it is unit-testable.
 */
final class Compat
{
    public const MIN_FREE = '4.4.0';

    /** The free plugin's classes the add-on calls directly (kept in step by tests/FreeApiCompatTest.php). */
    public const REQUIRED_CLASSES = [
        'AiEditorDivi5\\WP\\HistoryService',
        'AiEditorDivi5\\WP\\EditTarget',
        'AiEditorDivi5\\WP\\PageEditor',
        'AiEditorDivi5\\WP\\DryRun',
        'AiEditorDivi5\\WP\\StyleReferences',
        'AiEditorDivi5\\WP\\GlobalStyles',
        'AiEditorDivi5\\WP\\ExtensionGuard',
        'AiEditorDivi5\\WP\\LayoutGate',
        'AiEditorDivi5\\WP\\LayoutDiff',
        'AiEditorDivi5\\WP\\CodePolicy',
        'AiEditorDivi5\\WP\\RestController',
        'AiEditorDivi5\\WP\\FieldTarget',
        'AiEditorDivi5\\WP\\CustomFieldReader',
        'AiEditorDivi5\\WP\\CustomFields',
    ];

    /**
     * @param callable(string):bool $classExists
     * @return array{ok:bool, reason:?string, message:string}
     */
    public static function check( ?string $freeVersion, callable $classExists, string $min = self::MIN_FREE ): array
    {
        if ( null === $freeVersion || '' === $freeVersion ) {
            return self::fail( 'free_missing', 'needs the free plugin "JHMG AI Editor for Divi 5" to be installed and active. The Pro tools stay off until it is.' );
        }
        if ( version_compare( $freeVersion, $min, '<' ) ) {
            return self::fail( 'free_too_old', sprintf( 'needs the free plugin "JHMG AI Editor for Divi 5" version %s or newer (you have %s). Update the free plugin; the Pro tools stay off until then.', $min, $freeVersion ) );
        }
        foreach ( self::REQUIRED_CLASSES as $class ) {
            if ( ! $classExists( $class ) ) {
                return self::fail( 'class_missing', sprintf( 'cannot find %s in the free plugin, so the Pro tools stay off. Update both plugins to their latest versions.', $class ) );
            }
        }

        return [ 'ok' => true, 'reason' => null, 'message' => '' ];
    }

    /** @return array{ok:bool, reason:?string, message:string} */
    private static function fail( string $reason, string $message ): array
    {
        return [ 'ok' => false, 'reason' => $reason, 'message' => $message ];
    }
}
