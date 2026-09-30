<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Compares the installed Divi against the newest version this plugin was verified on. */
final class DiviCompat
{
    /** Newest Divi major.minor whose modules have been render-verified (see docs/module-verification-*.json). */
    public const TESTED = '5.14';

    /** @return array{level:string, message:string} */
    public static function status( ?string $installed, string $tested = self::TESTED ): array
    {
        $inst = self::majorMinor( $installed );
        $test = self::majorMinor( $tested );
        if ( null === $inst || null === $test ) {
            return [ 'level' => 'unknown', 'message' => '' ];
        }
        if ( version_compare( $inst, $test, '>' ) ) {
            return [
                'level'   => 'newer',
                'message' => sprintf(
                    /* translators: 1: installed Divi version, 2: newest tested Divi version */
                    __( 'Divi %1$s is newer than the newest version this plugin was verified on (%2$s). Editing still works, but pages that use brand-new Divi modules may be rejected until a plugin update adds them.', 'ai-editor-for-divi-5' ),
                    $inst,
                    $test
                ),
            ];
        }

        return [ 'level' => 'ok', 'message' => '' ];
    }

    public static function installedVersion(): ?string
    {
        if ( defined( 'ET_CORE_VERSION' ) ) {
            return (string) ET_CORE_VERSION;
        }
        $theme = wp_get_theme( 'Divi' );
        if ( $theme->exists() ) {
            $v = $theme->get( 'Version' );

            return is_string( $v ) && '' !== $v ? $v : null;
        }

        return null;
    }

    private static function majorMinor( ?string $version ): ?string
    {
        if ( null === $version || 1 !== preg_match( '/^(\d+)\.(\d+)/', $version, $m ) ) {
            return null;
        }

        return $m[1] . '.' . $m[2];
    }
}
