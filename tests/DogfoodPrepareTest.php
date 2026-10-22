<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\LayoutGate;
use AiEditorDivi5\WP\StyleGuide;
use Divi5Validator\Tests\Support\Dogfood\Prepare;
use PHPUnit\Framework\TestCase;

/**
 * dogfood-prepare (dogfood harness plan 2026-10-22, Task 1): the guide files and the task a fresh assistant gets, written
 * from the real StyleGuide and SectionRecipes, byte-identical on repeat. Dev tooling only: nothing here is shipped.
 */
class DogfoodPrepareTest extends TestCase
{
    /** sha256 of the prototype's edit-glass-edge/page.html (54,131 bytes), which is exactly editBase('glass-edge', 'glass-dark'). */
    private const EDIT_BASE_SHA256 = '31a20120028f79be6b5a71640b9e45bc9397a9822d024a6d2913016d2efee2bf';

    private const GUIDE_NAMES = [ '01-style-guide.md', '02-kit-rules.md', '03-style-kit.md', '04-design-language.md', '05-controls.md', '06-forms.md' ];

    /** @var list<string> */
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ( $this->tmp as $dir ) {
            foreach ( glob( $dir . '/*' ) ?: [] as $f ) {
                @unlink( $f );
            }
            @rmdir( $dir );
        }
        $this->tmp = [];
    }

    private function tmpDir(): string
    {
        $dir = sys_get_temp_dir() . '/dogfood-prepare-' . bin2hex( random_bytes( 6 ) );
        $this->tmp[] = $dir;

        return $dir;
    }

    public function testPrepareWritesTheGuideTopicsAKitPageNeeds(): void
    {
        $files = Prepare::files( 'hairline-dashed', 'tech-mono', 'freehand', '/x' );
        $this->assertSame( array_merge( self::GUIDE_NAMES, [ 'TASK.md', 'prepare.json' ] ), array_keys( $files ) );
        $topics = [
            '01-style-guide.md'     => null,
            '02-kit-rules.md'       => 'kit-rules',
            '03-style-kit.md'       => 'style-kit:hairline-dashed',
            '04-design-language.md' => 'design-language:tech-mono',
            '05-controls.md'        => 'controls',
            '06-forms.md'           => 'forms',
        ];
        foreach ( $topics as $name => $topic ) {
            $this->assertSame( StyleGuide::markdown( $topic ), $files[ $name ], $name );
            $this->assertNotSame( '', $files[ $name ] );
        }
        $this->assertStringContainsString( 'Topics:', $files['01-style-guide.md'] );
        $this->assertStringNotContainsString( 'Unknown topic', $files['03-style-kit.md'] );
    }

    public function testTheEditTaskAddsTheBasePage(): void
    {
        $files = Prepare::files( 'glass-edge', 'glass-dark', 'edit', '/x' );
        $this->assertSame( array_merge( self::GUIDE_NAMES, [ 'TASK.md', 'page.html', 'prepare.json' ] ), array_keys( $files ) );
        $this->assertSame( Prepare::editBase( 'glass-edge', 'glass-dark' ), $files['page.html'] );
    }

    public function testTheEditBaseIsTheSixRecipesUnderTheKit(): void
    {
        $this->assertSame( [ 'hero-cta', 'card-grid-3', 'pricing-three-tier', 'faq-accordion', 'team-grid', 'contact-form' ], Prepare::EDIT_RECIPES );
        $base = Prepare::editBase( 'glass-edge', 'glass-dark' );
        $this->assertStringStartsWith( '<!-- wp:divi/placeholder -->', $base );
        $this->assertStringEndsWith( "\n<!-- /wp:divi/placeholder -->", $base );
        $this->assertStringNotContainsString( '{{aied:', $base );
        $this->assertStringNotContainsString( '"aied"', $base );
        $result = LayoutGate::check( $base );
        $this->assertTrue( $result->isValid(), json_encode( array_map( static fn ( $v ) => $v->code(), $result->violations() ) ) );
        $this->assertSame( self::EDIT_BASE_SHA256, hash( 'sha256', $base ) );
        $this->assertSame( 54131, strlen( $base ) );
    }

    public function testRecipePageRefusesARecipeThatDoesNotResolve(): void
    {
        $this->expectException( \RuntimeException::class );
        Prepare::recipePage( [ 'hero-cta', 'no-such-recipe' ], 'glass-edge', 'glass-dark' );
    }

    public function testPrepareIsByteIdenticalOnRepeat(): void
    {
        $this->assertSame(
            Prepare::files( 'hairline-dashed', 'tech-mono', 'freehand', '/x' ),
            Prepare::files( 'hairline-dashed', 'tech-mono', 'freehand', '/x' )
        );
        $this->assertSame(
            Prepare::files( 'glass-edge', 'glass-dark', 'edit', '/x' ),
            Prepare::files( 'glass-edge', 'glass-dark', 'edit', '/x' )
        );

        $root = dirname( __DIR__ );
        foreach ( [ [ 'freehand', [] ], [ 'edit', [ '--task', 'edit' ] ] ] as [ $task, $extra ] ) {
            $dir    = $this->tmpDir();
            $hashes = [];
            for ( $run = 0; $run < 2; ++$run ) {
                [ $code, $out ] = $this->runScript( array_merge( [ 'hairline-dashed', 'tech-mono', '--out', $dir ], $extra ), $root );
                $this->assertSame( 0, $code, $out );
                $this->assertStringContainsString( 'TASK.md ', $out );
                $hash = [];
                foreach ( glob( $dir . '/*' ) ?: [] as $f ) {
                    $hash[ basename( $f ) ] = hash_file( 'sha256', $f );
                }
                ksort( $hash );
                $hashes[] = $hash;
            }
            $this->assertSame( $hashes[0], $hashes[1], $task );
            $this->assertArrayHasKey( 'prepare.json', $hashes[0] );
        }
    }

    public function testTaskFilesHaveNoPlaceholderLeft(): void
    {
        foreach ( [ 'freehand' => 'page.html', 'edit' => 'page-edited.html' ] as $task => $target ) {
            $task_md = Prepare::files( 'hairline-dashed', 'tech-mono', $task, '/some/out' )['TASK.md'];
            $this->assertStringContainsString( 'hairline-dashed', $task_md );
            $this->assertStringContainsString( 'tech-mono', $task_md );
            $this->assertStringContainsString( '/some/out/' . $target, $task_md );
            $this->assertStringContainsString( 'Use ONLY these files', $task_md );
            foreach ( [ '%KIT%', '%LANGUAGE%', '%OUT%' ] as $placeholder ) {
                $this->assertStringNotContainsString( $placeholder, $task_md );
            }
        }
        $freehand = Prepare::files( 'hairline-dashed', 'tech-mono', 'freehand', '/o' )['TASK.md'];
        $this->assertStringContainsString( 'Northwind Coffee Roasters', $freehand );
        $edit = Prepare::files( 'glass-edge', 'glass-dark', 'edit', '/o/' )['TASK.md'];
        $this->assertStringContainsString( 'save the result as /o/page-edited.html', $edit );
        $this->assertStringContainsString( '4. Add a row of four small round icon badges', $edit );
    }

    public function testUnknownIdsAreRefusedWithTheValidList(): void
    {
        $cases = [
            [ 'no-such-kit', 'tech-mono', 'freehand', 'hairline-dashed' ],
            [ 'hairline-dashed', 'no-such-language', 'freehand', 'tech-mono' ],
            [ 'hairline-dashed', 'tech-mono', 'no-such-task', 'freehand' ],
        ];
        foreach ( $cases as [ $kit, $language, $task, $valid ] ) {
            try {
                Prepare::files( $kit, $language, $task, '/x' );
                $this->fail( 'expected InvalidArgumentException for ' . $kit . ' ' . $language . ' ' . $task );
            } catch ( \InvalidArgumentException $e ) {
                $this->assertStringContainsString( $valid, $e->getMessage() );
            }
        }

        $root = dirname( __DIR__ );
        $dir  = $this->tmpDir();
        $args = [
            [ 'no-such-kit', 'tech-mono', '--out', $dir ],
            [ 'hairline-dashed', 'no-such-language', '--out', $dir ],
            [ 'hairline-dashed', 'tech-mono', '--task', 'no-such-task', '--out', $dir ],
            [ 'hairline-dashed', 'tech-mono', '--bogus', '--out', $dir ],
            [ 'hairline-dashed' ],
        ];
        foreach ( $args as $a ) {
            [ $code, $out ] = $this->runScript( $a, $root );
            $this->assertSame( 2, $code, implode( ' ', $a ) . ' ' . $out );
            $this->assertStringContainsString( 'Usage:', $out );
        }
        $this->assertSame( [], glob( $dir . '/*' ) ?: [] );
    }

    public function testDefaultOutKeepsTheTwoTasksApart(): void
    {
        $this->assertSame( 'dist/dogfood/dotted-pill-playful-pastel', Prepare::defaultOut( 'dotted-pill', 'playful-pastel', 'freehand' ) );
        $this->assertSame( 'dist/dogfood/dotted-pill-playful-pastel-edit', Prepare::defaultOut( 'dotted-pill', 'playful-pastel', 'edit' ) );
    }

    public function testPrepareJsonListsTheBytesOfEveryFile(): void
    {
        foreach ( [ 'freehand', 'edit' ] as $task ) {
            $files = Prepare::files( 'glass-edge', 'glass-dark', $task, '/x' );
            $json  = json_decode( $files['prepare.json'], true );
            $this->assertSame( [ 'kit', 'language', 'task', 'files', 'guide_bytes' ], array_keys( $json ) );
            $this->assertSame( 'glass-edge', $json['kit'] );
            $this->assertSame( 'glass-dark', $json['language'] );
            $this->assertSame( $task, $json['task'] );
            $expect = [];
            foreach ( $files as $name => $content ) {
                if ( 'prepare.json' !== $name ) {
                    $expect[ $name ] = strlen( $content );
                }
            }
            $this->assertSame( $expect, $json['files'] );
            $this->assertSame( array_sum( array_map( static fn ( string $n ): int => strlen( $files[ $n ] ), self::GUIDE_NAMES ) ), $json['guide_bytes'] );
            $this->assertStringEndsWith( "}\n", $files['prepare.json'] );
            $this->assertStringNotContainsString( '/x', $files['prepare.json'] );
        }
    }

    public function testWriteWritesOnlyItsFilesAndDeletesNothing(): void
    {
        $dir = $this->tmpDir() . '/nested';
        $this->tmp[] = $dir;
        mkdir( $dir, 0777, true );
        file_put_contents( $dir . '/page.html', 'the assistant output' );
        $files = Prepare::files( 'hairline-dashed', 'tech-mono', 'freehand', $dir );
        $paths = Prepare::write( $files, $dir );
        $this->assertCount( count( $files ), $paths );
        $this->assertSame( 'the assistant output', file_get_contents( $dir . '/page.html' ) );
        foreach ( $files as $name => $content ) {
            $this->assertSame( $content, file_get_contents( $dir . '/' . $name ) );
        }
        @unlink( $dir . '/page.html' );
        foreach ( $paths as $p ) {
            @unlink( $p );
        }
        @rmdir( $dir );
    }

    /** @param list<string> $args @return array{0:int,1:string} */
    private function runScript( array $args, string $root ): array
    {
        $cmd  = array_merge( [ PHP_BINARY, $root . '/scripts/dogfood-prepare.php' ], $args );
        $proc = proc_open( $cmd, [ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes, $root );
        $this->assertIsResource( $proc );
        $out  = (string) stream_get_contents( $pipes[1] ) . (string) stream_get_contents( $pipes[2] );
        fclose( $pipes[1] );
        fclose( $pipes[2] );

        return [ proc_close( $proc ), $out ];
    }
}
