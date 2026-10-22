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
     * Writes files() into $outDir (mkdir -p), only those names, never deletes anything.
     *
     * Re-running into the same directory refreshes OUR files: a directory is ours when it holds a prepare.json written by this
     * tool (valid JSON with `kit`, `language`, `task` and a `files` object). Then prepare.json, TASK.md and every name listed in
     * its old `files` map (the guide files, and page.html only if the earlier run was an edit prepare) may be overwritten. Any
     * other existing target with DIFFERENT content (the assistant's page.html or page-edited.html of a freehand dir, a same-name
     * foreign file, or anything in a directory without a valid prepare.json) is refused before anything is written
     * (\RuntimeException, nothing written). A directory prepared for a DIFFERENT kit or language is refused as well (a refresh
     * would leave a page of one design under the guide of another); the task (freehand or edit) may differ. Identical content is
     * simply rewritten. A directory in the way of a target is refused too.
     *
     * @param array<string,string> $files
     * @return list<string> the written paths
     */
    public static function write( array $files, string $outDir ): array
    {
        $outDir = rtrim( $outDir, '/' );
        $ours   = self::oursIn( $outDir );
        $has    = self::preparedFor( $outDir );
        $new    = isset( $files['prepare.json'] ) ? json_decode( $files['prepare.json'], true ) : null;
        if ( null !== $has && is_array( $new ) && ( $has['kit'] !== ( $new['kit'] ?? null ) || $has['language'] !== ( $new['language'] ?? null ) ) ) {
            throw new \RuntimeException( sprintf(
                '%s was prepared for kit "%s" and language "%s"; this run is for kit "%s" and language "%s". Nothing was written: the guide files would no longer match the page there. Use another --out directory (or remove this one).',
                $outDir,
                $has['kit'],
                $has['language'],
                (string) ( $new['kit'] ?? '' ),
                (string) ( $new['language'] ?? '' )
            ) );
        }
        foreach ( $files as $name => $content ) {
            $path = $outDir . '/' . $name;
            if ( is_dir( $path ) ) {
                throw new \RuntimeException( sprintf( '%s is a directory; nothing was written. Remove it or choose another --out directory.', $path ) );
            }
            if ( is_file( $path ) && ! in_array( $name, $ours, true ) && (string) file_get_contents( $path ) !== $content ) {
                throw new \RuntimeException( sprintf( '%s already exists with different content and is not a file of an earlier prepare run; nothing was written. Move it away or choose another --out directory.', $path ) );
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

    /**
     * The kit and language an earlier run prepared $outDir for, or null when there is no valid prepare.json there.
     *
     * @return ?array{kit:string,language:string}
     */
    private static function preparedFor( string $outDir ): ?array
    {
        $path = $outDir . '/prepare.json';
        $json = is_file( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null;
        if ( ! is_array( $json ) || ! is_string( $json['kit'] ?? null ) || ! is_string( $json['language'] ?? null ) || ! is_string( $json['task'] ?? null ) || ! is_array( $json['files'] ?? null ) ) {
            return null;
        }

        return [ 'kit' => $json['kit'], 'language' => $json['language'] ];
    }

    /**
     * The names an earlier run of this tool wrote into $outDir: [] when there is no valid prepare.json there.
     *
     * @return list<string>
     */
    private static function oursIn( string $outDir ): array
    {
        $path = $outDir . '/prepare.json';
        if ( ! is_file( $path ) ) {
            return [];
        }
        $json = json_decode( (string) file_get_contents( $path ), true );
        if ( ! is_array( $json ) || ! is_string( $json['kit'] ?? null ) || ! is_string( $json['language'] ?? null ) || ! is_string( $json['task'] ?? null ) || ! is_array( $json['files'] ?? null ) ) {
            return [];
        }

        return array_merge( [ 'prepare.json', 'TASK.md' ], array_map( 'strval', array_keys( $json['files'] ) ) );
    }
}
