<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\LanguageAdapters;
use AiEditorDivi5\WP\LanguageResolver;
use AiEditorDivi5\WP\LayoutGate;
use AiEditorDivi5\WP\RecipeTokens;
use Divi5Validator\Tests\Support\DeadOnModule;
use Divi5Validator\Tests\Support\ShapePaths;
use Divi5Validator\Tests\Support\ShapeRegistry;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/DesignLanguages.php';
require_once __DIR__ . '/../wp-plugin/src/RecipeTokens.php';
require_once __DIR__ . '/../wp-plugin/src/BlockAttrs.php';
require_once __DIR__ . '/../wp-plugin/src/LanguageAdapters.php';
require_once __DIR__ . '/../wp-plugin/src/LanguageResolver.php';
require_once __DIR__ . '/../wp-plugin/src/CodePolicy.php';
require_once __DIR__ . '/../wp-plugin/src/LayoutGate.php';
require_once __DIR__ . '/Support/ShapePaths.php';
require_once __DIR__ . '/Support/ShapeRegistry.php';
require_once __DIR__ . '/Support/DeadOnModule.php';

/**
 * The rhythm adapter (rhythm adapter plan Task 3): a design language's `section_space` (role `section`) and the two roles
 * DesignLanguages::sectionSpace() derives from it (`open`, `tight`) become the top and bottom padding of a `divi/section` at
 * desktop, tablet and phone, with `syncVertical` on. Only the paths and values the effect probe passed ON the section are
 * written (fixtures/shapes/language-rhythm-vocabulary.md, fixtures/shapes/probe-results-language-rhythm.json); the six
 * decisions (LanguageAdapters::RHYTHM_PROVEN) are pinned to that file's "Adapter decisions" table.
 */
class LanguageRhythmAdapterTest extends TestCase
{
    private const VOCABULARY = __DIR__ . '/../fixtures/shapes/language-rhythm-vocabulary.md';
    private const CANDIDATES = __DIR__ . '/../fixtures/shapes/candidates/language-rhythm.json';
    private const RESULTS    = __DIR__ . '/../fixtures/shapes/probe-results-language-rhythm.json';

    private const DECISIONS = [ 'section-padding', 'section-sync', 'section-image', 'section-first', 'section-later', 'section-overlap' ];

    /** Decision => the candidate that proves it beside the 30 language candidates. */
    private const CONDITION_CANDIDATES = [
        'section-image'   => 'lr-section-image',
        'section-first'   => 'lr-section-first',
        'section-later'   => 'lr-section-later',
        'section-overlap' => 'lr-section-overlap',
    ];

    /** @return array<string,array<string,mixed>> every shipped language by id */
    private function languages(): array
    {
        $out = [];
        foreach ( DesignLanguages::ids() as $id ) {
            $lang = DesignLanguages::get( $id );
            $this->assertIsArray( $lang );
            $this->assertSame( [], DesignLanguages::sectionSpaceProblems( $lang ), "$id section_space is valid" );
            $out[ $id ] = $lang;
        }
        $this->assertCount( 10, $out );
        return $out;
    }

    /** @param array<string,mixed> $lang @return array<string,mixed> */
    private function patch( string $role, array $lang ): array
    {
        $patch = LanguageAdapters::patch( 'divi/section', 'rhythm', $role, $lang );
        $this->assertIsArray( $patch, "{$lang['id']} $role on divi/section must have a patch" );
        return $patch;
    }

    /** Dotted path => value of every leaf of $node. @return array<string,mixed> */
    private static function leaves( array $node, string $prefix = '' ): array
    {
        $out = [];
        foreach ( $node as $k => $v ) {
            $path = '' === $prefix ? (string) $k : $prefix . '.' . $k;
            if ( is_array( $v ) && [] !== $v ) {
                $out += self::leaves( $v, $path );
            } else {
                $out[ $path ] = $v;
            }
        }
        return $out;
    }

    /** @return array<string,bool> decision => proven, from the "Adapter decisions" table of the vocabulary file */
    private function vocabularyDecisions(): array
    {
        $text = (string) file_get_contents( self::VOCABULARY );
        $at   = strpos( $text, '## Adapter decisions' );
        $this->assertNotFalse( $at, 'the vocabulary file has an Adapter decisions table' );
        preg_match_all( '/^\|\s*([a-z-]+)\s*\|\s*(PASS|DEAD)\s*\|/m', substr( $text, (int) $at ), $m, PREG_SET_ORDER );
        $out = [];
        foreach ( $m as $row ) {
            $out[ $row[1] ] = 'PASS' === $row[2];
        }
        return $out;
    }

    /**
     * The candidates that PASSED (they changed the section's computed styles at every width; not css-only, no phone
     * overflow): id => candidate, and every path of their `set` on the section.
     *
     * @return array{0:array<string,array<string,mixed>>,1:array<string,true>}
     */
    private function probed(): array
    {
        $candidates = json_decode( (string) file_get_contents( self::CANDIDATES ), true );
        $results    = [];
        foreach ( (array) json_decode( (string) file_get_contents( self::RESULTS ), true ) as $r ) {
            $results[ $r['id'] ] = $r;
        }
        $this->assertIsArray( $candidates );
        $this->assertCount( 37, $candidates );
        $passed = [];
        $paths  = [];
        foreach ( $candidates as $candidate ) {
            $this->assertSame( 'section', $candidate['block'], $candidate['id'] . ' was rendered on the section' );
            $r = $results[ $candidate['id'] ] ?? null;
            $this->assertNotNull( $r, $candidate['id'] . ' has a result' );
            $widths = (array) ( $r['widths'] ?? [] );
            if ( true !== ( $r['effect'] ?? false ) || true === ( $r['cssOnly'] ?? false ) || true === ( $r['phoneOverflow'] ?? false )
                || [ true, true, true ] !== [ $widths['1280'] ?? false, $widths['900'] ?? false, $widths['400'] ?? false ] ) {
                continue;
            }
            $passed[ (string) $candidate['id'] ] = $candidate;
            foreach ( array_keys( (array) ( $candidate['set'] ?? [] ) ) as $key ) {
                $paths[ (string) $key ] = true;
            }
        }
        return [ $passed, $paths ];
    }

    private const WRAP_ROW = '<!-- wp:divi/row {"builderVersion":"5.15.1"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.15.1"} --><!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<p>Body copy</p>"}},"decoration":{"bodyFont":{"body":{"font":{"desktop":{"value":{"color":"{{aied:color:text}}"}}}}}}},"builderVersion":"5.15.1"} /--><!-- /wp:divi/column --><!-- /wp:divi/row -->';

    /** A real layout: one section marked with $role, holding a row, a column and a text. */
    private static function markup( string $role ): string
    {
        return '<!-- wp:divi/placeholder --><!-- wp:divi/section {"module":{"decoration":{"background":{"desktop":{"value":{"color":"{{aied:color:bg}}"}}}}},"builderVersion":"5.15.1","aied":{"rhythm":"' . $role . '"}} -->'
            . self::WRAP_ROW . '<!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';
    }

    public function testRhythmProvenMatchesTheVocabularyTable(): void
    {
        $table = $this->vocabularyDecisions();
        $this->assertSame( self::DECISIONS, array_keys( $table ), 'six decisions, in the table\'s order' );
        $this->assertSame( $table, LanguageAdapters::RHYTHM_PROVEN, 'LanguageAdapters::RHYTHM_PROVEN is exactly the Adapter decisions table of language-rhythm-vocabulary.md' );
        $this->assertSame( array_fill_keys( self::DECISIONS, true ), LanguageAdapters::RHYTHM_PROVEN, 'all six passed on the real Divi 5.15.1' );

        [ $passed ] = $this->probed();
        $this->assertArrayHasKey( 'lr-section-control', $passed, 'the control changed the section: the run is trusted' );
        foreach ( self::CONDITION_CANDIDATES as $decision => $id ) {
            $this->assertArrayHasKey( $id, $passed, "$decision rests on $id, which passed" );
        }
    }

    public function testTheConstants(): void
    {
        $this->assertSame( [ 'divi/section' => [ 'section', 'open', 'tight' ] ], LanguageAdapters::APPLIED['rhythm'] );
        $this->assertSame( DesignLanguages::RHYTHM_ROLES, LanguageAdapters::APPLIED['rhythm']['divi/section'], 'the roles are the language\'s roles' );
        $this->assertSame( array_keys( LanguageAdapters::APPLIED['rhythm'] ), array_keys( LanguageAdapters::RHYTHM_PATHS ), 'every carrier has its path' );
        $this->assertSame( '{"aied":{"rhythm":...}}', LanguageResolver::LEFT['rhythm'] );
        $this->assertSame( LanguageAdapters::KINDS, array_keys( LanguageResolver::LEFT ), 'every kind has its leftover description' );
    }

    public function testEveryLeafIsAProbedPathOnTheSection(): void
    {
        [ , $probed ] = $this->probed();
        $this->assertCount( 9, $probed, 'top, bottom and syncVertical at desktop, tablet and phone' );
        $unprobed = [];
        $checked  = 0;
        foreach ( $this->languages() as $id => $lang ) {
            foreach ( DesignLanguages::RHYTHM_ROLES as $role ) {
                foreach ( array_keys( self::leaves( $this->patch( $role, $lang ) ) ) as $path ) {
                    if ( ! isset( $probed[ $path ] ) ) {
                        $unprobed[ $path ] = "$id $role";
                    }
                }
                ++$checked;
            }
        }
        $this->assertSame( 30, $checked );
        $this->assertSame( [], $unprobed, 'every leaf the rhythm adapter writes was measured to PASS on the section' );

        // A planted leaf fails the check: left padding was never probed (Ruling 4: never written).
        $patch = $this->patch( 'section', $this->languages()['editorial'] );
        $patch['module']['decoration']['spacing']['desktop']['value']['padding']['left'] = '24px';
        $bad = array_filter( array_keys( self::leaves( $patch ) ), static fn ( string $p ): bool => ! isset( $probed[ $p ] ) );
        $this->assertSame( [ 'module.decoration.spacing.desktop.value.padding.left' ], array_values( $bad ) );
    }

    public function testEveryLanguageRoleWasProbed(): void
    {
        [ $passed ] = $this->probed();
        $missing = [];
        foreach ( $this->languages() as $id => $lang ) {
            foreach ( DesignLanguages::RHYTHM_ROLES as $role ) {
                $cid = "lr-$id-$role";
                $c   = $passed[ $cid ] ?? null;
                if ( null === $c ) {
                    $missing[] = "$cid did not pass";
                    continue;
                }
                if ( self::leaves( $this->patch( $role, $lang ) ) !== (array) $c['set'] ) {
                    $missing[] = "$cid: its set is not the patch of $id $role (the candidates file must be sectionSpace())";
                }
            }
        }
        $this->assertSame( [], $missing, 'every (language, role) value set the adapter writes was rendered as its own PASS candidate on the section' );
    }

    public function testThePatchWritesTopBottomAndSyncOnly(): void
    {
        $expected = [];
        foreach ( [ 'desktop', 'tablet', 'phone' ] as $bp ) {
            foreach ( [ 'top', 'bottom', 'syncVertical' ] as $key ) {
                $expected[] = "module.decoration.spacing.$bp.value.padding.$key";
            }
        }
        foreach ( $this->languages() as $id => $lang ) {
            foreach ( DesignLanguages::RHYTHM_ROLES as $role ) {
                $leaves = self::leaves( $this->patch( $role, $lang ) );
                $this->assertSame( $expected, array_keys( $leaves ), "$id $role: top, bottom and syncVertical at desktop, tablet and phone, in that order" );
                foreach ( [ 'desktop', 'tablet', 'phone' ] as $bp ) {
                    $base = "module.decoration.spacing.$bp.value.padding.";
                    $this->assertMatchesRegularExpression( '/^[1-9][0-9]*px\z/', $leaves[ $base . 'top' ], "$id $role $bp" );
                    $this->assertSame( $leaves[ $base . 'top' ], $leaves[ $base . 'bottom' ], "$id $role $bp: top equals bottom" );
                    $this->assertSame( 'on', $leaves[ $base . 'syncVertical' ] );
                }
            }
        }
    }

    public function testThePatchIsTheLanguagesRhythm(): void
    {
        $seen = [];
        foreach ( $this->languages() as $id => $lang ) {
            foreach ( DesignLanguages::RHYTHM_ROLES as $role ) {
                $space = DesignLanguages::sectionSpace( $lang, $role );
                $this->assertIsArray( $space );
                $spacing = $this->patch( $role, $lang )['module']['decoration']['spacing'];
                $this->assertSame( [ 'desktop', 'tablet', 'phone' ], array_keys( $spacing ) );
                foreach ( $space as $bp => $px ) {
                    $this->assertSame( [ 'value' => [ 'padding' => [ 'top' => $px . 'px', 'bottom' => $px . 'px', 'syncVertical' => 'on' ] ] ], $spacing[ $bp ], "$id $role $bp" );
                }
                $seen[ implode( '/', $space ) ] = true;
            }
            $this->assertSame( DesignLanguages::sectionSpace( $lang, 'section' ), $lang['section_space'], "$id: section is the data itself" );
        }
        $this->assertGreaterThan( 15, count( $seen ), 'the languages write different rhythms' );
    }

    public function testTheSyncFallbackOmitsTheToggle(): void
    {
        $planted = array_merge( LanguageAdapters::RHYTHM_PROVEN, [ 'section-sync' => false ] );
        foreach ( $this->languages() as $id => $lang ) {
            foreach ( DesignLanguages::RHYTHM_ROLES as $role ) {
                $without = LanguageAdapters::rhythmPatch( 'divi/section', $role, $lang, $planted );
                $this->assertIsArray( $without, "$id $role: section-sync false still pads the section" );
                $want = array_filter( self::leaves( $this->patch( $role, $lang ) ), static fn ( string $p ): bool => ! str_ends_with( $p, '.syncVertical' ), ARRAY_FILTER_USE_KEY );
                $this->assertSame( $want, self::leaves( $without ), "$id $role: the same values, no syncVertical" );
                $this->assertStringNotContainsString( 'syncVertical', (string) json_encode( $without ) );
            }
        }
    }

    public function testUnprovenPaddingRefusesTheSection(): void
    {
        $lang = $this->languages()['editorial'];
        foreach ( [ 'section-padding', 'section-image', 'section-first', 'section-later', 'section-overlap' ] as $decision ) {
            $planted = array_merge( LanguageAdapters::RHYTHM_PROVEN, [ $decision => false ] );
            foreach ( DesignLanguages::RHYTHM_ROLES as $role ) {
                $this->assertNull( LanguageAdapters::rhythmPatch( 'divi/section', $role, $lang, $planted ), "$decision false: no $role section" );
            }
        }
        $missing = LanguageAdapters::RHYTHM_PROVEN;
        unset( $missing['section-padding'] );
        $this->assertNull( LanguageAdapters::rhythmPatch( 'divi/section', 'section', $lang, $missing ), 'a missing decision is not proven' );
        $this->assertNull( LanguageAdapters::rhythmPatch( 'divi/section', 'section', $lang, [] ) );
        $this->assertSame( LanguageAdapters::patch( 'divi/section', 'rhythm', 'open', $lang ), LanguageAdapters::rhythmPatch( 'divi/section', 'open', $lang ), 'patch() is rhythmPatch() with the shipped decisions' );
    }

    public function testUnknownCombinationsAreNull(): void
    {
        $lang = $this->languages()['editorial'];
        foreach ( [ 'divi/row', 'divi/column', 'divi/heading', 'divi/group', 'divi/text', 'section', '' ] as $block ) {
            foreach ( DesignLanguages::RHYTHM_ROLES as $role ) {
                $this->assertNull( LanguageAdapters::patch( $block, 'rhythm', $role, $lang ), "$role on $block" );
            }
        }
        $this->assertNull( LanguageAdapters::patch( 'divi/row', 'rhythm', 'section', $lang ) );
        $this->assertNull( LanguageAdapters::patch( 'divi/heading', 'rhythm', 'open', $lang ) );
        foreach ( [ 'hero', 'Section', '', 'display', 'band' ] as $role ) {
            $this->assertNull( LanguageAdapters::patch( 'divi/section', 'rhythm', $role, $lang ), "unknown role '$role'" );
        }
        foreach ( [ 'kit', 'Rhythm', 'type', '' ] as $kind ) {
            $this->assertNull( LanguageAdapters::patch( 'divi/section', $kind, 'section', $lang ), "kind '$kind' on a section" );
        }

        // A section_space problem refuses every rhythm role; the type roles still resolve.
        $badSpace = $lang;
        $badSpace['section_space']['desktop'] = '120';
        $noSpace  = $lang;
        unset( $noSpace['section_space'] );
        foreach ( [ $badSpace, $noSpace, [] ] as $language ) {
            foreach ( DesignLanguages::RHYTHM_ROLES as $role ) {
                $this->assertNull( LanguageAdapters::patch( 'divi/section', 'rhythm', $role, $language ) );
            }
        }
        $this->assertIsArray( LanguageAdapters::patch( 'divi/heading', 'type', 'display', $badSpace ), 'a section_space problem does not refuse type' );
        $this->assertIsArray( LanguageAdapters::patch( 'divi/heading', 'type', 'display', $noSpace ) );

        // And vice versa: a type problem does not refuse rhythm.
        $badType = $lang;
        $badType['type']['display']['weight'] = '650';
        $this->assertNull( LanguageAdapters::patch( 'divi/heading', 'type', 'display', $badType ) );
        $this->assertSame( $this->patch( 'tight', $lang ), LanguageAdapters::patch( 'divi/section', 'rhythm', 'tight', $badType ), 'a type problem does not refuse rhythm' );
    }

    public function testThePathIsTheLanguagesConstant(): void
    {
        $this->assertSame( DesignLanguages::SECTION_SPACE_PATH, LanguageAdapters::RHYTHM_PATHS['divi/section'], 'one source for the path' );
        $this->assertSame( [ 'divi/section' => 'module.decoration.spacing' ], LanguageAdapters::RHYTHM_PATHS );
        foreach ( $this->languages() as $id => $lang ) {
            $this->assertStringContainsString( LanguageAdapters::RHYTHM_PATHS['divi/section'], DesignLanguages::sectionSpaceLine( $lang ), "$id: the guide line names the path the adapter writes" );
            $this->assertStringStartsWith( LanguageAdapters::RHYTHM_PATHS['divi/section'] . '.', (string) array_key_first( self::leaves( $this->patch( 'section', $lang ) ) ) );
        }
    }

    public function testPatchValidatesInsideARealLayout(): void
    {
        $checked = 0;
        foreach ( $this->languages() as $id => $lang ) {
            foreach ( DesignLanguages::RHYTHM_ROLES as $role ) {
                $markup = LanguageResolver::resolve( self::markup( $role ), $lang );
                $this->assertSame( [], LanguageResolver::leftovers( $markup ), "$id $role" );
                $this->assertStringNotContainsString( '"aied"', $markup );
                $resolved = RecipeTokens::resolve( $markup, $lang );
                $this->assertSame( [], RecipeTokens::found( $resolved ), "$id $role: every token resolved" );
                $result = LayoutGate::check( $resolved );
                $codes  = implode( ', ', array_map( static fn ( $v ) => $v->toArray()['code'], $result->violations() ) );
                $this->assertTrue( $result->isValid(), "$id $role on divi/section must pass the gate, got: $codes" );
                $this->assertStringContainsString( self::WRAP_ROW, $markup, 'the row, column and text inside stay byte-identical' );
                ++$checked;
            }
        }
        $this->assertSame( 30, $checked );
    }

    public function testTheRhythmPatchesAreNotDeadOnTheSection(): void
    {
        $registry = ShapeRegistry::load();
        $dead     = DeadOnModule::load();
        $served   = '';
        foreach ( $this->languages() as $id => $lang ) {
            foreach ( DesignLanguages::RHYTHM_ROLES as $role ) {
                $markup = LanguageResolver::resolve( self::markup( $role ), $lang );
                $this->assertSame( [], $dead->violations( $markup ), "$id $role" );
                foreach ( ShapePaths::fromMarkup( $markup ) as $path ) {
                    $this->assertTrue( $registry->coversLeaf( $path ), "$path ($id $role) is in the shape registry" );
                }
                $served .= $markup;
            }
        }

        // A planted dead line for the section's top padding fails the sections, and only the sections.
        $row  = '<!-- wp:divi/row {"module":{"decoration":{"spacing":{"desktop":{"value":{"padding":{"top":"40px","bottom":"40px"}}}}}},"builderVersion":"5.15.1"} --><!-- /wp:divi/row -->';
        $file = tempnam( sys_get_temp_dir(), 'aied-dead' );
        $this->assertIsString( $file );
        try {
            // DeadOnModule matches normalised paths (ShapePaths: a breakpoint key is `{bp}`).
            file_put_contents( $file, "divi/section|module.decoration.spacing.{bp}.value.padding.top|planted|test\n" );
            $planted = DeadOnModule::load( $file );
            $this->assertNotSame( [], $planted->violations( $served ) );
            $this->assertSame( [], $planted->violations( $row ), 'scoped to the section carrier only' );
        } finally {
            unlink( $file );
        }
    }

    public function testThePatchIsDeterministic(): void
    {
        foreach ( $this->languages() as $id => $lang ) {
            foreach ( DesignLanguages::RHYTHM_ROLES as $role ) {
                $this->assertSame( json_encode( $this->patch( $role, $lang ) ), json_encode( $this->patch( $role, $lang ) ), "$id $role" );
                $this->assertSame( LanguageResolver::resolve( self::markup( $role ), $lang ), LanguageResolver::resolve( self::markup( $role ), $lang ) );
            }
        }
    }
}
