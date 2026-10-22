<?php

declare(strict_types=1);

namespace Divi5Validator\Tests\Support\Dogfood;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\SectionRecipes;
use AiEditorDivi5\WP\StyleGuide;
use AiEditorDivi5\WP\StyleKits;

/**
 * The dogfood harness's first half (plan 2026-10-22): the files a fresh assistant gets to build or edit a Divi 5 page from
 * the served guide only. Pure and deterministic: the guide files are the real `StyleGuide::markdown()` topics, the edit
 * base is real recipes resolved under the kit, nothing reads a clock, a path other than the out dir, or the network.
 * Dev tooling: never shipped in `wp-plugin/`.
 */
final class Prepare
{
    /** The recipes of the edit base, in page order (the prototype's). */
    public const EDIT_RECIPES = [ 'hero-cta', 'card-grid-3', 'pricing-three-tier', 'faq-accordion', 'team-grid', 'contact-form' ];

    /** Guide file => StyleGuide topic (null = the default guide); '%KIT%' / '%LANGUAGE%' substituted. Order is the file order. */
    public const GUIDE_FILES = [
        '01-style-guide.md'     => null,
        '02-kit-rules.md'       => 'kit-rules',
        '03-style-kit.md'       => 'style-kit:%KIT%',
        '04-design-language.md' => 'design-language:%LANGUAGE%',
        '05-controls.md'        => 'controls',
        '06-forms.md'           => 'forms',
    ];

    public const TASKS = [ 'freehand', 'edit' ];

    /**
     * filename => content, in write order: the 6 guide files, TASK.md, page.html (edit only), prepare.json.
     * Pure; throws \InvalidArgumentException (the message lists the valid ids) on an unknown kit, language or task.
     *
     * @return array<string,string>
     */
    public static function files( string $kit, string $language, string $task, string $outDir ): array
    {
        if ( ! in_array( $kit, StyleKits::ids(), true ) ) {
            throw new \InvalidArgumentException( sprintf( 'Unknown kit "%s". Valid kits: %s.', $kit, implode( ', ', StyleKits::ids() ) ) );
        }
        if ( ! in_array( $language, DesignLanguages::ids(), true ) ) {
            throw new \InvalidArgumentException( sprintf( 'Unknown language "%s". Valid languages: %s.', $language, implode( ', ', DesignLanguages::ids() ) ) );
        }
        if ( ! in_array( $task, self::TASKS, true ) ) {
            throw new \InvalidArgumentException( sprintf( 'Unknown task "%s". Valid tasks: %s.', $task, implode( ', ', self::TASKS ) ) );
        }

        $files = [];
        foreach ( self::GUIDE_FILES as $name => $topic ) {
            $files[ $name ] = StyleGuide::markdown( null === $topic ? null : str_replace( [ '%KIT%', '%LANGUAGE%' ], [ $kit, $language ], $topic ) );
        }

        $template = (string) file_get_contents( dirname( __DIR__, 3 ) . '/scripts/dogfood/TASK-' . $task . '.md' );
        $files['TASK.md'] = str_replace( [ '%KIT%', '%LANGUAGE%', '%OUT%' ], [ $kit, $language, rtrim( $outDir, '/' ) ], $template );
        if ( 'edit' === $task ) {
            $files['page.html'] = self::editBase( $kit, $language );
        }

        // TASK.md is left out of the sizes: its length depends on the length of the out path, and prepare.json must not.
        $sizes = [];
        foreach ( $files as $name => $content ) {
            if ( 'TASK.md' !== $name ) {
                $sizes[ $name ] = strlen( $content );
            }
        }
        $guideBytes = 0;
        foreach ( array_keys( self::GUIDE_FILES ) as $name ) {
            $guideBytes += $sizes[ $name ];
        }
        $files['prepare.json'] = json_encode(
            [ 'kit' => $kit, 'language' => $language, 'task' => $task, 'files' => $sizes, 'guide_bytes' => $guideBytes ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ) . "\n";

        return $files;
    }

    /** '<!-- wp:divi/placeholder -->' . the recipes joined by a newline . "\n<!-- /wp:divi/placeholder -->"; throws \RuntimeException if a recipe resolves to null. */
    public static function recipePage( array $names, string $kit, string $language ): string
    {
        $parts = [];
        foreach ( $names as $name ) {
            $recipe = SectionRecipes::recipe( (string) $name, $language, $kit );
            if ( null === $recipe ) {
                throw new \RuntimeException( sprintf( 'The recipe "%s" did not resolve under kit "%s" and language "%s".', $name, $kit, $language ) );
            }
            $parts[] = $recipe;
        }

        return '<!-- wp:divi/placeholder -->' . implode( "\n", $parts ) . "\n<!-- /wp:divi/placeholder -->";
    }

    /** recipePage(EDIT_RECIPES, ...). */
    public static function editBase( string $kit, string $language ): string
    {
        return self::recipePage( self::EDIT_RECIPES, $kit, $language );
    }

    /** The default out dir relative to the repo root: the edit task gets a "-edit" suffix so the two never overwrite each other. */
    public static function defaultOut( string $kit, string $language, string $task ): string
    {
        return 'dist/dogfood/' . $kit . '-' . $language . ( 'edit' === $task ? '-edit' : '' );
    }

    /**
     * Writes files() into $outDir (mkdir -p), only those names, never deletes anything. A target that already exists with
     * DIFFERENT content (a same-name foreign file, or output of another run) is refused before anything is written
     * (\RuntimeException); identical content is simply rewritten.
     *
     * @param array<string,string> $files
     * @return list<string> the written paths
     */
    public static function write( array $files, string $outDir ): array
    {
        $outDir = rtrim( $outDir, '/' );
        foreach ( $files as $name => $content ) {
            $path = $outDir . '/' . $name;
            if ( is_file( $path ) && (string) file_get_contents( $path ) !== $content ) {
                throw new \RuntimeException( sprintf( '%s already exists with different content; nothing was written. Remove it or choose another --out directory.', $path ) );
            }
        }
        if ( ! is_dir( $outDir ) && ! mkdir( $outDir, 0777, true ) && ! is_dir( $outDir ) ) {
            throw new \RuntimeException( 'Could not create ' . $outDir );
        }
        $paths = [];
        foreach ( $files as $name => $content ) {
            $path = $outDir . '/' . $name;
            if ( false === file_put_contents( $path, $content ) ) {
                throw new \RuntimeException( 'Could not write ' . $path );
            }
            $paths[] = $path;
        }

        return $paths;
    }
}
