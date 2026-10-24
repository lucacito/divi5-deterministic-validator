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
 * The type adapter (type adapter plan Task 3): a design language's `type` becomes the weight, letter spacing and (only for an
 * `uppercase` role) capitalisation of a heading (`display`, `title`, `eyebrow`) or an eyebrow text (`eyebrow`), at the block's
 * desktop font path. Only the paths and values the effect probe passed ON each carrier are written
 * (fixtures/shapes/language-type-vocabulary.md, fixtures/shapes/probe-results-language-type.json); the ten conditional decisions
 * (LanguageAdapters::TYPE_PROVEN) are pinned to that file's "Adapter decisions" table.
 */
class LanguageAdaptersTest extends TestCase
{
    private const VOCABULARY = __DIR__ . '/../fixtures/shapes/language-type-vocabulary.md';
    private const CANDIDATES = __DIR__ . '/../fixtures/shapes/candidates/language-type.json';
    private const RESULTS    = __DIR__ . '/../fixtures/shapes/probe-results-language-type.json';

    /** The four (block, role) pairs the adapter answers. */
    private const PAIRS = [
        [ 'divi/heading', 'display' ],
        [ 'divi/heading', 'title' ],
        [ 'divi/heading', 'eyebrow' ],
        [ 'divi/text', 'eyebrow' ],
    ];

    private const PATHS = [
        'divi/heading' => 'title.decoration.font.font',
        'divi/text'    => 'content.decoration.bodyFont.body.font',
    ];

    /** @return array<string,array<string,mixed>> every shipped language by id */
    private function languages(): array
    {
        $out = [];
        foreach ( DesignLanguages::ids() as $id ) {
            $lang = DesignLanguages::get( $id );
            $this->assertIsArray( $lang );
            $this->assertSame( [], DesignLanguages::typeProblems( $lang ), "$id type data is valid" );
            $out[ $id ] = $lang;
        }
        $this->assertCount( 10, $out );
        return $out;
    }

    /** @param array<string,mixed> $lang @return array<string,mixed> */
    private function patch( string $block, string $role, array $lang ): array
    {
        $patch = LanguageAdapters::patch( $block, 'type', $role, $lang );
        $this->assertIsArray( $patch, "{$lang['id']} $role on $block must have a patch" );
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
     * The candidates that PASSED (they changed the carrier's computed styles at every width; not css-only, no phone overflow):
     * id => candidate, and per carrier ('heading', 'text') every path of their `set`.
     *
     * @return array{0:array<string,array<string,mixed>>,1:array<string,array<string,true>>}
     */
    private function probed(): array
    {
        $candidates = json_decode( (string) file_get_contents( self::CANDIDATES ), true );
        $results    = [];
        foreach ( (array) json_decode( (string) file_get_contents( self::RESULTS ), true ) as $r ) {
            $results[ $r['id'] ] = $r;
        }
        $this->assertIsArray( $candidates );
        $passed = [];
        $paths  = [];
        foreach ( $candidates as $candidate ) {
            $r = $results[ $candidate['id'] ] ?? null;
            $this->assertNotNull( $r, $candidate['id'] . ' has a result' );
            $widths = (array) ( $r['widths'] ?? [] );
            if ( true !== ( $r['effect'] ?? false ) || true === ( $r['cssOnly'] ?? false ) || true === ( $r['phoneOverflow'] ?? false )
                || [ true, true, true ] !== [ $widths['1280'] ?? false, $widths['900'] ?? false, $widths['400'] ?? false ] ) {
                continue;
            }
            $passed[ (string) $candidate['id'] ] = $candidate;
            foreach ( array_keys( (array) ( $candidate['set'] ?? [] ) ) as $key ) {
                $paths[ (string) $candidate['block'] ][ (string) $key ] = true;
            }
        }
        return [ $passed, $paths ];
    }

    private static function carrier( string $block ): string
    {
        return substr( $block, strlen( 'divi/' ) );
    }

    private const WRAP_OPEN  = '<!-- wp:divi/placeholder --><!-- wp:divi/section {"builderVersion":"5.15.1"} --><!-- wp:divi/row {"builderVersion":"5.15.1"} --><!-- wp:divi/column {"module":{"advanced":{"type":{"desktop":{"value":"4_4"}}}},"builderVersion":"5.15.1"} -->';
    private const WRAP_CLOSE = '<!-- /wp:divi/column --><!-- /wp:divi/row --><!-- /wp:divi/section --><!-- /wp:divi/placeholder -->';

    /** A real layout with one marked block of $role. */
    private static function markup( string $block, string $role ): string
    {
        $marker = '"aied":{"type":"' . $role . '"}';
        if ( 'divi/heading' === $block ) {
            $level = [ 'display' => 'h2', 'title' => 'h3', 'eyebrow' => 'h4' ][ $role ];
            $font  = 'eyebrow' === $role ? 'eyebrow' : 'display';
            $body  = '<!-- wp:divi/heading {"title":{"innerContent":{"desktop":{"value":"A headline"}},"decoration":{"font":{"font":{"desktop":{"value":{"headingLevel":"' . $level . '","size":"40px","family":"{{aied:font:' . $font . '}}","color":"{{aied:color:text}}"}}}}}},"builderVersion":"5.15.1",' . $marker . '} /-->';
        } else {
            $body = '<!-- wp:divi/text {"content":{"innerContent":{"desktop":{"value":"<p>Label</p>"}},"decoration":{"bodyFont":{"body":{"font":{"desktop":{"value":{"size":"13px","family":"{{aied:font:eyebrow}}","color":"{{aied:color:muted}}"}}}}}}},"builderVersion":"5.15.1",' . $marker . '} /-->';
        }
        return self::WRAP_OPEN . $body . self::WRAP_CLOSE;
    }

    public function testTypeProvenMatchesTheVocabularyTable(): void
    {
        $table = $this->vocabularyDecisions();
        $this->assertSame(
            [ 'heading-weight', 'heading-tracking', 'heading-caps', 'heading-levels', 'heading-in-card', 'heading-cascade', 'text-weight', 'text-tracking', 'text-caps', 'text-cascade' ],
            array_keys( $table ),
            'ten decisions, in the table\'s order'
        );
        $this->assertSame( $table, LanguageAdapters::TYPE_PROVEN, 'LanguageAdapters::TYPE_PROVEN is exactly the Adapter decisions table of language-type-vocabulary.md' );
        $this->assertSame( array_fill_keys( array_keys( $table ), true ), LanguageAdapters::TYPE_PROVEN, 'all ten passed on the real Divi 5.15.1' );
    }

    public function testTheConstantsAgree(): void
    {
        $this->assertSame( [ 'type' ], LanguageAdapters::KINDS );
        $this->assertSame( [ 'type' => [ 'divi/heading' => [ 'display', 'title', 'eyebrow' ], 'divi/text' => [ 'eyebrow' ] ] ], LanguageAdapters::APPLIED );
        $this->assertSame( DesignLanguages::TYPE_KEYS, LanguageAdapters::TYPE_ROLES );
        $this->assertSame( [ 'display' => 'display', 'title' => 'display', 'eyebrow' => 'eyebrow' ], LanguageAdapters::TYPE_FONT );
        $this->assertSame( self::PATHS, LanguageAdapters::TYPE_PATHS );
        $this->assertSame( array_keys( LanguageAdapters::APPLIED['type'] ), array_keys( LanguageAdapters::TYPE_PATHS ), 'every carrier has its path' );
        $this->assertSame( LanguageAdapters::TYPE_ROLES, array_keys( LanguageAdapters::TYPE_HEADING_LEVELS ) );
        $this->assertSame( [ 'display' => [ 'h1', 'h2' ], 'title' => [ 'h3' ], 'eyebrow' => [ 'h4' ] ], LanguageAdapters::TYPE_HEADING_LEVELS, 'Ruling 2' );
    }

    public function testEveryLeafIsAProbedPathOnItsCarrier(): void
    {
        [ , $probed ] = $this->probed();
        foreach ( [ 'heading', 'text' ] as $carrier ) {
            $this->assertCount( 3, $probed[ $carrier ] ?? [], "$carrier has three probed paths" );
        }
        $unprobed = [];
        $checked  = 0;
        foreach ( $this->languages() as $id => $lang ) {
            foreach ( self::PAIRS as [ $block, $role ] ) {
                foreach ( array_keys( self::leaves( $this->patch( $block, $role, $lang ) ) ) as $path ) {
                    if ( ! isset( $probed[ self::carrier( $block ) ][ $path ] ) ) {
                        $unprobed[ self::carrier( $block ) . ' ' . $path ] = "$id $role";
                    }
                }
                ++$checked;
            }
        }
        $this->assertSame( 40, $checked );
        $this->assertSame( [], $unprobed, 'every leaf the type adapter writes was measured to PASS on its own carrier' );

        // A planted leaf fails the check: a size was never probed by the type probe.
        $patch = $this->patch( 'divi/heading', 'display', $this->languages()['editorial'] );
        $patch['title']['decoration']['font']['font']['desktop']['value']['size'] = '40px';
        $bad = array_filter( array_keys( self::leaves( $patch ) ), static fn ( string $p ): bool => ! isset( $probed['heading'][ $p ] ) );
        $this->assertSame( [ 'title.decoration.font.font.desktop.value.size' ], array_values( $bad ) );
    }

    public function testEveryLanguageValueWasProbedOnItsCarrier(): void
    {
        [ $passed ] = $this->probed();
        $missing = [];
        foreach ( $this->languages() as $id => $lang ) {
            foreach ( self::PAIRS as [ $block, $role ] ) {
                $carrier = self::carrier( $block );
                foreach ( self::leaves( $this->patch( $block, $role, $lang ) ) as $path => $value ) {
                    $key = substr( $path, (int) strrpos( $path, '.' ) + 1 );
                    $ids = match ( $key ) {
                        'weight'         => '300' === $value ? [ "lt-$carrier-weight-300", "lt-$carrier-control" ] : [ "lt-$carrier-weight-$value" ],
                        'letterSpacing'  => [ "lt-$carrier-tracking-$value" ],
                        'capitalization' => 'uppercase' === $value ? [ "lt-$carrier-caps-uppercase" ] : [],
                        default          => [],
                    };
                    $proved = false;
                    foreach ( $ids as $cid ) {
                        $c = $passed[ $cid ] ?? null;
                        if ( null !== $c && [ $path => $value ] === array_intersect_key( (array) $c['set'], [ $path => true ] ) ) {
                            $proved = true;
                        }
                    }
                    if ( ! $proved ) {
                        $missing[] = "$id $role on $block: $path = " . var_export( $value, true );
                    }
                }
            }
        }
        $this->assertSame( [], $missing, 'every weight, letter spacing and capitalisation the ten languages write has its own PASS candidate on its carrier' );
    }

    public function testThePatchWritesTheThreeKeysOnly(): void
    {
        foreach ( $this->languages() as $id => $lang ) {
            foreach ( self::PAIRS as [ $block, $role ] ) {
                $base     = self::PATHS[ $block ] . '.desktop.value.';
                $expected = [ $base . 'weight', $base . 'letterSpacing' ];
                if ( 'uppercase' === $lang['type'][ $role ]['capitalization'] ) {
                    $expected[] = $base . 'capitalization';
                }
                $this->assertSame( $expected, array_keys( self::leaves( $this->patch( $block, $role, $lang ) ) ), "$id $role on $block: weight, letterSpacing and (uppercase only) capitalization, in that order, at desktop" );
            }
        }
    }

    public function testANoneRoleWritesNoCapitalisationKey(): void
    {
        $nones = 0;
        foreach ( $this->languages() as $id => $lang ) {
            foreach ( self::PAIRS as [ $block, $role ] ) {
                if ( 'none' !== $lang['type'][ $role ]['capitalization'] ) {
                    continue;
                }
                $json = (string) json_encode( $this->patch( $block, $role, $lang ) );
                $this->assertStringNotContainsString( 'capitalization', $json, "$id $role on $block: Divi has no `none` (Ruling 5)" );
                $this->assertStringNotContainsString( 'none', $json );
                ++$nones;
            }
        }
        $this->assertGreaterThan( 0, $nones, 'the ten languages have none roles' );
    }

    public function testTheCascadeFallbackWritesTheThreeBreakpoints(): void
    {
        $lang = $this->languages()['brutalist-flat'];
        foreach ( [ [ 'heading-cascade', 'divi/heading', 'divi/text' ], [ 'text-cascade', 'divi/text', 'divi/heading' ] ] as [ $decision, $fallback, $other ] ) {
            $planted = array_merge( LanguageAdapters::TYPE_PROVEN, [ $decision => false ] );
            $patch   = LanguageAdapters::typePatch( $fallback, 'eyebrow', $lang, $planted );
            $this->assertIsArray( $patch );
            $leaves = self::leaves( $patch );
            $base   = self::PATHS[ $fallback ];
            $want   = [];
            foreach ( [ 'desktop', 'tablet', 'phone' ] as $bp ) {
                $want[ "$base.$bp.value.weight" ]         = $lang['type']['eyebrow']['weight'];
                $want[ "$base.$bp.value.letterSpacing" ]  = $lang['type']['eyebrow']['letterSpacing'];
                $want[ "$base.$bp.value.capitalization" ] = 'uppercase';
            }
            $this->assertSame( $want, $leaves, "$decision false: desktop, tablet and phone carry the same three values" );
            $this->assertSame(
                LanguageAdapters::typePatch( $other, 'eyebrow', $lang ),
                LanguageAdapters::typePatch( $other, 'eyebrow', $lang, $planted ),
                "$decision false leaves the other carrier unchanged"
            );
        }
    }

    public function testUnprovenDecisionsRefuseTheCarrier(): void
    {
        $lang    = $this->languages()['editorial'];
        $this->assertSame( 'uppercase', $lang['type']['eyebrow']['capitalization'] );
        $this->assertSame( 'none', $lang['type']['display']['capitalization'] );
        $noCaps  = array_merge( LanguageAdapters::TYPE_PROVEN, [ 'text-caps' => false ] );
        $this->assertNull( LanguageAdapters::typePatch( 'divi/text', 'eyebrow', $lang, $noCaps ), 'an uppercase eyebrow text needs text-caps' );
        $this->assertIsArray( LanguageAdapters::typePatch( 'divi/heading', 'eyebrow', $lang, $noCaps ), 'the heading still resolves' );
        $this->assertNull( LanguageAdapters::typePatch( 'divi/heading', 'eyebrow', $lang, array_merge( LanguageAdapters::TYPE_PROVEN, [ 'heading-caps' => false ] ) ) );
        $this->assertIsArray( LanguageAdapters::typePatch( 'divi/heading', 'display', $lang, array_merge( LanguageAdapters::TYPE_PROVEN, [ 'heading-caps' => false ] ) ), 'a none role needs no caps decision' );
        foreach ( [ 'heading-weight', 'heading-tracking', 'heading-levels' ] as $decision ) {
            $planted = array_merge( LanguageAdapters::TYPE_PROVEN, [ $decision => false ] );
            foreach ( [ 'display', 'title', 'eyebrow' ] as $role ) {
                $this->assertNull( LanguageAdapters::typePatch( 'divi/heading', $role, $lang, $planted ), "$decision false: no $role heading" );
            }
            $this->assertIsArray( LanguageAdapters::typePatch( 'divi/text', 'eyebrow', $lang, $planted ), "$decision false: the text still resolves" );
        }
        foreach ( [ 'text-weight', 'text-tracking' ] as $decision ) {
            $planted = array_merge( LanguageAdapters::TYPE_PROVEN, [ $decision => false ] );
            $this->assertNull( LanguageAdapters::typePatch( 'divi/text', 'eyebrow', $lang, $planted ) );
            $this->assertIsArray( LanguageAdapters::typePatch( 'divi/heading', 'eyebrow', $lang, $planted ) );
        }
        $this->assertNull( LanguageAdapters::typePatch( 'divi/heading', 'display', $lang, [] ), 'a missing decision is not proven' );
    }

    public function testThePatchIsTheLanguagesData(): void
    {
        foreach ( $this->languages() as $id => $lang ) {
            foreach ( self::PAIRS as [ $block, $role ] ) {
                $value = $this->patch( $block, $role, $lang );
                foreach ( explode( '.', self::PATHS[ $block ] . '.desktop.value' ) as $key ) {
                    $this->assertIsArray( $value[ $key ] ?? null, "$id $role on $block: $key" );
                    $value = $value[ $key ];
                }
                $want = $lang['type'][ $role ];
                if ( 'none' === $want['capitalization'] ) {
                    unset( $want['capitalization'] );
                }
                $this->assertSame( $want, $value, "$id $role on $block is the language's type.$role" );
            }
        }
    }

    public function testUnknownCombinationsAreNull(): void
    {
        $lang = $this->languages()['editorial'];
        $this->assertNull( LanguageAdapters::patch( 'divi/text', 'type', 'display', $lang ) );
        $this->assertNull( LanguageAdapters::patch( 'divi/text', 'type', 'title', $lang ) );
        $this->assertNull( LanguageAdapters::patch( 'divi/button', 'type', 'eyebrow', $lang ) );
        foreach ( [ 'divi/blurb', 'divi/section', 'divi/group', 'divi/accordion', 'heading', '' ] as $block ) {
            foreach ( LanguageAdapters::TYPE_ROLES as $role ) {
                $this->assertNull( LanguageAdapters::patch( $block, 'type', $role, $lang ), "$role on $block" );
            }
        }
        $this->assertNull( LanguageAdapters::patch( 'divi/heading', 'rhythm', 'display', $lang ) );
        $this->assertNull( LanguageAdapters::patch( 'divi/heading', 'kit', 'display', $lang ) );
        $this->assertNull( LanguageAdapters::patch( 'divi/heading', 'Type', 'display', $lang ) );
        foreach ( [ 'body', 'Display', '', 'h1' ] as $role ) {
            $this->assertNull( LanguageAdapters::patch( 'divi/heading', 'type', $role, $lang ), "unknown role '$role'" );
        }
        $broken = $lang;
        $broken['type']['display']['weight'] = '650';
        $this->assertNull( LanguageAdapters::patch( 'divi/heading', 'type', 'display', $broken ), 'a language with a type problem' );
        $this->assertNull( LanguageAdapters::patch( 'divi/text', 'type', 'eyebrow', $broken ), 'a type problem anywhere refuses every role' );
        $noType = $lang;
        unset( $noType['type'] );
        $this->assertNull( LanguageAdapters::patch( 'divi/heading', 'type', 'title', $noType ) );
        $this->assertNull( LanguageAdapters::typePatch( 'divi/heading', 'title', $noType ) );
        $this->assertNull( LanguageAdapters::patch( 'divi/heading', 'type', 'title', [] ) );
    }

    public function testPatchValidatesInsideARealLayout(): void
    {
        $checked = 0;
        foreach ( $this->languages() as $id => $lang ) {
            foreach ( self::PAIRS as [ $block, $role ] ) {
                $markup = LanguageResolver::resolve( self::markup( $block, $role ), $lang );
                $this->assertSame( [], LanguageResolver::leftovers( $markup ), "$id $role $block" );
                $this->assertStringNotContainsString( '"aied"', $markup );
                $resolved = RecipeTokens::resolve( $markup, $lang );
                $this->assertSame( [], RecipeTokens::found( $resolved ), "$id $role $block: every token resolved" );
                $result = LayoutGate::check( $resolved );
                $codes  = implode( ', ', array_map( static fn ( $v ) => $v->toArray()['code'], $result->violations() ) );
                $this->assertTrue( $result->isValid(), "$id $role on $block must pass the gate, got: $codes" );
                ++$checked;
            }
        }
        $this->assertSame( 40, $checked );
    }

    public function testTheTypePatchesAreNotDeadOnTheirCarriers(): void
    {
        $registry = ShapeRegistry::load();
        $dead     = DeadOnModule::load();
        $served   = [];
        foreach ( $this->languages() as $id => $lang ) {
            foreach ( self::PAIRS as [ $block, $role ] ) {
                $markup = LanguageResolver::resolve( self::markup( $block, $role ), $lang );
                $this->assertSame( [], $dead->violations( $markup ), "$id $role on $block" );
                foreach ( ShapePaths::fromMarkup( $markup ) as $path ) {
                    $this->assertTrue( $registry->coversLeaf( $path ), "$path ($id $role on $block) is in the shape registry" );
                }
                $served[ $block ] = ( $served[ $block ] ?? '' ) . $markup;
            }
        }

        // A planted dead line for the heading's weight fails the heading, and only the heading.
        $file = tempnam( sys_get_temp_dir(), 'aied-dead' );
        $this->assertIsString( $file );
        try {
            // DeadOnModule matches normalised paths (ShapePaths: a breakpoint key is `{bp}`).
            file_put_contents( $file, "divi/heading|title.decoration.font.font.{bp}.value.weight|planted|test\n" );
            $planted = DeadOnModule::load( $file );
            $this->assertNotSame( [], $planted->violations( $served['divi/heading'] ) );
            $this->assertSame( [], $planted->violations( $served['divi/text'] ), 'scoped to the heading carrier only' );
        } finally {
            unlink( $file );
        }
    }

    public function testThePatchIsDeterministic(): void
    {
        foreach ( $this->languages() as $id => $lang ) {
            foreach ( self::PAIRS as [ $block, $role ] ) {
                $this->assertSame( json_encode( $this->patch( $block, $role, $lang ) ), json_encode( $this->patch( $block, $role, $lang ) ), "$id $role $block" );
            }
        }
    }
}
