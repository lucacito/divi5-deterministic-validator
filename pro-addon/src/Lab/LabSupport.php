<?php

declare(strict_types=1);

namespace AiEditorDivi5\Pro\Lab;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The per-tool guard for the Lab features (`lab_restyle`, `build_site`'s brief option): they need the free plugin's Divi 5
 * Lab classes. These are deliberately NOT in `Compat::REQUIRED_CLASSES` (that would switch every Pro tool off on an older free
 * plugin); a tool that needs them refuses alone, with a plain "update the free plugin" message (same pattern as
 * `SwitchDesign`).
 */
final class LabSupport
{
    /** The free classes the Lab features call. */
    public const REQUIRED_CLASSES = [
        'AiEditorDivi5\\WP\\StudioCompiler',
        'AiEditorDivi5\\WP\\StudioBrief',
        'AiEditorDivi5\\WP\\StudioStore',
        'AiEditorDivi5\\WP\\StudioTable',
        'AiEditorDivi5\\WP\\PageHistory',
        'AiEditorDivi5\\WP\\HistoryService',
        'AiEditorDivi5\\WP\\DesignLanguages', // LabLook reads a language's own kit
    ];

    /** @var list<array{string,string}> [class, method] pairs the Lab features call. */
    public const REQUIRED_METHODS = [
        [ 'AiEditorDivi5\\WP\\StudioStore', 'linked' ],
        [ 'AiEditorDivi5\\WP\\StudioStore', 'forPage' ],
        [ 'AiEditorDivi5\\WP\\StudioStore', 'get' ],
        [ 'AiEditorDivi5\\WP\\StudioStore', 'save' ],
        [ 'AiEditorDivi5\\WP\\StudioStore', 'link' ],
        [ 'AiEditorDivi5\\WP\\StudioStore', 'countFor' ],
        [ 'AiEditorDivi5\\WP\\StudioStore', 'delete' ],
        [ 'AiEditorDivi5\\WP\\StudioBrief', 'check' ],
        [ 'AiEditorDivi5\\WP\\StudioBrief', 'resolveRecipe' ],
    ];

    public static function message( string $tool ): string
    {
        return $tool . ' needs a newer version of the free plugin "JHMG AI Editor for Divi 5" (one with Divi 5 Lab). Update the free plugin; the other Pro tools keep working.';
    }

    /**
     * @param (\Closure(string):bool)|null        $classExists  seam: whether a class is installed
     * @param (\Closure(string,string):bool)|null $methodExists seam: whether a class has a method
     * @return string|null the refusal message, or null when everything is there
     */
    public static function unsupported( string $tool, ?\Closure $classExists = null, ?\Closure $methodExists = null ): ?string
    {
        $classExists  ??= static fn ( string $class ): bool => class_exists( $class );
        $methodExists ??= static fn ( string $class, string $method ): bool => method_exists( $class, $method );

        foreach ( self::REQUIRED_CLASSES as $class ) {
            if ( ! $classExists( $class ) ) {
                return self::message( $tool );
            }
        }
        foreach ( self::REQUIRED_METHODS as [ $class, $method ] ) {
            if ( ! $methodExists( $class, $method ) ) {
                return self::message( $tool );
            }
        }

        return null;
    }
}
