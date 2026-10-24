<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\SectionRecipes;
use Divi5Validator\Tests\Support\ShapePaths;
use Divi5Validator\Tests\Support\ShapeRegistry;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/ShapePaths.php';
require_once __DIR__ . '/Support/ShapeRegistry.php';
require_once __DIR__ . '/Support/ServedVariants.php';
foreach ( [ 'ImagePack', 'ImageTokens', 'SectionRecipes', 'StyleKits', 'KitAdapters', 'KitResolver' ] as $recipeModuleSettingsClass ) {
    require_once __DIR__ . '/../wp-plugin/src/' . $recipeModuleSettingsClass . '.php';
}

/**
 * The legacy recipes' module settings (carousel, blog, contact form, testimonial, text lists, blurb icon, hover translate,
 * htmlAttributes) were each measured on the real Divi 5.15.1 (fixtures/shapes/candidates/recipe-modules.json, results in
 * probe-results-recipe-modules.json). A path that rendered something is registered (how: probe); a path that rendered nothing
 * is listed in dead-recipe-paths.txt with the reason, and the tokenising tasks remove it from the recipes. This test keeps the
 * three files coherent and checks every claim against the committed probe results, not against prose.
 */
final class RecipeModuleSettingsTest extends TestCase
{
    /** The 38 recipe paths no committed export contains that Task 11a started from (frozen: this list never changes). */
    private const ORIGINAL = [
        'arrows.advanced.showArrows.{bp}.value',
        'blogGrid.decoration.layout.{bp}.value.gridColumnCount',
        'captcha.decoration.font.font.{bp}.value.family',
        'company.innerContent',
        'content.decoration.bodyFont.ul.font.{bp}.value.color',
        'content.decoration.bodyFont.ul.font.{bp}.value.size',
        'content.decoration.bodyFont.ul.font.{bp}.value.weight',
        'content.decoration.headingFont.h2.font.{bp}.value.textAlign',
        'content.decoration.headingFont.h3.font.{bp}.value.textAlign',
        'dotNav.advanced.showDots.{bp}.value',
        'email.advanced.receiver.{bp}.value',
        'field.decoration.background.{bp}.value.color',
        'field.decoration.font.font.{bp}.value.color',
        'field.decoration.font.font.{bp}.value.family',
        'imageIcon.advanced.alignment.{bp}.value',
        'imageIcon.advanced.width.{bp}.value.icon',
        'imageIcon.advanced.width.{bp}.value.image',
        'jobTitle.innerContent',
        'meta.advanced.showAuthor.{bp}.value',
        'meta.advanced.showDate.{bp}.value',
        'module.advanced.auto.{bp}.value',
        'module.advanced.centerMode.{bp}.value',
        'module.advanced.htmlAttributes.{bp}.value.class',
        'module.advanced.htmlAttributes.{bp}.value.id',
        'module.advanced.slidesToScroll.{bp}.value',
        'module.advanced.slidesToShow.{bp}.value',
        'module.decoration.transform.{bp}.hover.translate.linked',
        'module.decoration.transform.{bp}.hover.translate.x',
        'pagination.advanced.enable.{bp}.value',
        'post.advanced.categories.{bp}.value.{n}',
        'post.advanced.type.{bp}.value',
        'post.decoration.border.{bp}.value.radius.bottomLeft',
        'post.decoration.border.{bp}.value.radius.bottomRight',
        'post.decoration.border.{bp}.value.radius.sync',
        'post.decoration.border.{bp}.value.radius.topLeft',
        'post.decoration.border.{bp}.value.radius.topRight',
        'post.decoration.border.{bp}.value.styles.all.width',
        'readMore.advanced.enable.{bp}.value',
    ];

    /** @return list<string> non-empty lines that are not comments */
    private function paths( string $file ): array
    {
        $lines = file( __DIR__ . '/../fixtures/shapes/' . $file, FILE_IGNORE_NEW_LINES );
        $this->assertIsArray( $lines, $file );
        $out = [];
        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( $line !== '' && $line[0] !== '#' ) {
                $out[] = $line;
            }
        }
        return $out;
    }

    /** @return array<string,mixed>|list<mixed> */
    private function json( string $file ): array
    {
        $data = json_decode( (string) file_get_contents( __DIR__ . '/../fixtures/shapes/' . $file ), true );
        $this->assertIsArray( $data, $file );
        return $data;
    }

    /**
     * @param array<string,mixed> $set dotted path => value, as a probe candidate writes it
     * @return list<string> the normalised attribute paths of the values
     */
    private function setPaths( array $set ): array
    {
        $attrs = [];
        foreach ( $set as $dotted => $value ) {
            $node =& $attrs;
            $keys = explode( '.', (string) $dotted );
            $last = array_pop( $keys );
            foreach ( $keys as $k ) {
                if ( ! isset( $node[ $k ] ) || ! is_array( $node[ $k ] ) ) {
                    $node[ $k ] = [];
                }
                $node =& $node[ $k ];
            }
            $node[ $last ] = $value;
            unset( $node );
        }
        return ShapePaths::fromBlockAttrs( $attrs );
    }

    /** @return array<string,array<string,mixed>> candidate id => candidate */
    private function candidates(): array
    {
        $out = [];
        foreach ( $this->json( 'candidates/recipe-modules.json' ) as $c ) {
            $out[ (string) $c['id'] ] = $c;
        }
        return $out;
    }

    /** @return array<string,array<string,mixed>> candidate id => probe result */
    private function results(): array
    {
        $out = [];
        foreach ( $this->json( 'probe-results-recipe-modules.json' ) as $r ) {
            $out[ (string) $r['id'] ] = $r;
        }
        return $out;
    }

    /** @param array<string,mixed> $r */
    private function measuredEffect( array $r ): bool
    {
        return ! isset( $r['error'] ) && ! empty( $r['effect'] ) && empty( $r['phoneOverflow'] ) && empty( $r['cssOnly'] );
    }

    public function testNoOriginalPathIsLeftUnexamined(): void
    {
        $this->assertCount( 38, self::ORIGINAL );
        $dead       = $this->paths( 'dead-recipe-paths.txt' );
        $registry   = ShapeRegistry::load();
        $registered = [];
        foreach ( $registry->all() as $e ) {
            if ( str_contains( $e['evidence'], '#rm-' ) ) {
                $registered[ $e['path'] ] = true;
            }
        }
        $left = [];
        foreach ( self::ORIGINAL as $path ) {
            if ( ! in_array( $path, $dead, true ) && ! isset( $registered[ $path ] ) ) {
                $left[] = $path;
            }
            $this->assertFalse( in_array( $path, $dead, true ) && isset( $registered[ $path ] ), "$path is both dead and registered" );
        }
        $this->assertSame( [], $left, "paths neither proven nor marked dead:\n" . implode( "\n", $left ) );
        // Nothing else is in the dead list.
        $this->assertSame( [], array_values( array_diff( $dead, self::ORIGINAL ) ), 'dead-recipe-paths.txt lists a path that is not one of the 38' );
    }

    public function testDeadPathsStayUnregisteredAndCoherentWithTheFrozenList(): void
    {
        $dead     = $this->paths( 'dead-recipe-paths.txt' );
        $frozen   = $this->paths( 'ungrounded-recipe-paths.txt' );
        $registry = ShapeRegistry::load();
        $used     = [];
        foreach ( SectionRecipes::names() as $name ) {
            // As served: every language's and every style kit's markers resolved, so a dead path an adapter wrote would be
            // caught too.
            foreach ( \Divi5Validator\Tests\Support\ServedVariants::of( (string) SectionRecipes::rawMarkup( $name ) ) as $served ) {
                foreach ( ShapePaths::fromMarkup( $served ) as $p ) {
                    $used[ $p ] = true;
                }
            }
        }
        $this->assertSame( array_values( array_unique( $dead ) ), $dead, 'dead-recipe-paths.txt lists a path twice' );
        foreach ( $dead as $path ) {
            $this->assertFalse( $registry->coversLeaf( $path ), "$path is registered but listed as dead" );
            // Task 11c finished the tokenising: every dead setting was removed from the recipes, so no recipe uses one any more.
            $this->assertFalse( isset( $used[ $path ] ), "$path is dead and a recipe still uses it" );
        }
        // The frozen list is empty: nothing in it can be unexamined, and nothing may be added.
        $this->assertSame( [], $frozen, 'ungrounded-recipe-paths.txt must stay empty' );
    }

    public function testEveryDeadPathHasAMeasuredDeadCandidateAndNoPassingOne(): void
    {
        $candidates = $this->candidates();
        $results    = $this->results();
        foreach ( $this->paths( 'dead-recipe-paths.txt' ) as $path ) {
            $found = [];
            foreach ( $candidates as $id => $c ) {
                if ( in_array( $path, $this->setPaths( (array) $c['set'] ), true ) ) {
                    $found[] = $id;
                }
            }
            $this->assertNotSame( [], $found, "$path has no candidate" );
            foreach ( $found as $id ) {
                $this->assertArrayHasKey( $id, $results, "$id has no probe result" );
                $this->assertFalse( $this->measuredEffect( $results[ $id ] ), "$id measured an effect but $path is listed as dead" );
                $this->assertArrayNotHasKey( 'error', $results[ $id ], "$id errored; an error proves nothing" );
                $this->assertSame( 'DEAD', $candidates[ $id ]['expect'], "$id: expect must say DEAD" );
            }
        }
    }

    public function testEveryRegisteredPathRestsOnAPassingCandidate(): void
    {
        $candidates = $this->candidates();
        $results    = $this->results();
        $registry   = ShapeRegistry::load();
        $seen       = 0;
        foreach ( $registry->all() as $e ) {
            if ( ! str_contains( $e['evidence'], '#rm-' ) ) {
                continue;
            }
            ++$seen;
            $this->assertSame( 'probe', $e['how'], $e['path'] );
            $id = substr( $e['evidence'], strpos( $e['evidence'], '#' ) + 1 );
            $this->assertArrayHasKey( $id, $candidates, $e['path'] . ' cites a candidate that does not exist' );
            $this->assertTrue( in_array( $e['path'], $this->setPaths( (array) $candidates[ $id ]['set'] ), true ), "$id does not set {$e['path']}" );
            $this->assertTrue( $this->measuredEffect( $results[ $id ] ?? [] ), "$id has no measured computed-style effect" );
            $this->assertSame( 'PASS', $candidates[ $id ]['expect'], "$id: expect must say PASS" );
            $this->assertContains( $e['path'], self::ORIGINAL, $e['path'] . ' is not one of the 38' );
        }
        $this->assertGreaterThan( 0, $seen );
    }

    public function testEveryCandidateIsAnnotatedAndSafe(): void
    {
        $candidates = $this->candidates();
        $results    = $this->results();
        $this->assertGreaterThan( 30, count( $candidates ) );
        foreach ( $candidates as $id => $c ) {
            $this->assertMatchesRegularExpression( '/^rm-[a-z0-9-]+$/', $id );
            $this->assertContains( $c['expect'] ?? null, [ 'PASS', 'DEAD', 'PASS(css-only)' ], "$id needs expect PASS, DEAD or PASS(css-only)" );
            $this->assertStringContainsString( 'Divi 5.15.1', (string) ( $c['note'] ?? '' ), "$id: the note must cite the Divi 5.15.1 source" );
            $this->assertArrayHasKey( $id, $results, "$id was not probed" );
            // The probe agrees with what the candidate expects (a later change of outcome must be noticed here too).
            $r       = $results[ $id ];
            $verdict = isset( $r['error'] ) ? 'ERROR' : ( empty( $r['effect'] ) ? 'DEAD' : ( ! empty( $r['phoneOverflow'] ) ? 'OVERFLOW' : ( ! empty( $r['cssOnly'] ) ? 'PASS(css-only)' : 'PASS' ) ) );
            $this->assertSame( $c['expect'], $verdict, "$id: measured $verdict" );
            // No custom code, no remote host.
            $json = (string) json_encode( [ $c['base'] ?? [], $c['set'], $c['children'] ?? [] ] );
            $this->assertStringNotContainsString( '"css"', $json, $id );
            $this->assertStringNotContainsString( 'freeForm', $json, $id );
            $stripped = str_replace( '@SITE@', '', $json );
            $this->assertSame( 0, preg_match( '#https?:\\\\?/\\\\?/#', $stripped ), "$id carries a remote URL" );
        }
        foreach ( array_diff( array_keys( $results ), array_keys( $candidates ) ) as $id ) {
            $this->fail( "probe result $id has no candidate" );
        }
    }

    public function testEveryOriginalPathHasACandidate(): void
    {
        $covered = [];
        foreach ( $this->candidates() as $c ) {
            foreach ( $this->setPaths( (array) $c['set'] ) as $p ) {
                $covered[ $p ] = true;
            }
        }
        $missing = array_values( array_filter( self::ORIGINAL, static fn ( string $p ): bool => ! isset( $covered[ $p ] ) ) );
        $this->assertSame( [], $missing, "paths with no candidate:\n" . implode( "\n", $missing ) );
    }
}
