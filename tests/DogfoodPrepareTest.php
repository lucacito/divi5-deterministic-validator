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
    /** sha256 of the prototype's edit-glass-edge/page.html (54,860 bytes since the rhythm markers, rhythm adapter Task 5), which is exactly editBase('glass-edge', 'glass-dark'). */
    private const EDIT_BASE_SHA256 = 'f9e97add8ba219e70744d4a25e7c684919a33bd4d387f86947f7430e3eba944f';

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
        $this->assertSame( 54860, strlen( $base ) );
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
            // The bias guards: without them the assistant may read the repository or the recipes and the run measures nothing.
            $this->assertStringContainsString( 'do not look at the repository', $task_md, $task );
            $this->assertStringContainsString( 'Do not use recipes', $task_md, $task );
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
                if ( 'prepare.json' !== $name && 'TASK.md' !== $name ) {
                    $expect[ $name ] = strlen( $content );
                }
            }
            $this->assertSame( $expect, $json['files'] );
            $this->assertSame( array_sum( array_map( static fn ( string $n ): int => strlen( $files[ $n ] ), self::GUIDE_NAMES ) ), $json['guide_bytes'] );
            $this->assertStringEndsWith( "}\n", $files['prepare.json'] );
            $this->assertStringNotContainsString( '/x', $files['prepare.json'] );
            $this->assertArrayNotHasKey( 'TASK.md', $json['files'], 'its size depends on the out path' );
            $this->assertSame( $files['prepare.json'], Prepare::files( 'glass-edge', 'glass-dark', $task, '/a/much/longer/out/directory/than/the/first' )['prepare.json'], 'prepare.json does not depend on --out' );
        }
    }

    public function testWriteRefusesAForeignFileOfTheSameNameAndWritesNothing(): void
    {
        $dir = $this->tmpDir();
        mkdir( $dir, 0777, true );
        $files = Prepare::files( 'hairline-dashed', 'tech-mono', 'freehand', $dir );
        file_put_contents( $dir . '/05-controls.md', 'somebody else\'s notes' );
        try {
            Prepare::write( $files, $dir );
            $this->fail( 'expected a RuntimeException' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( '05-controls.md', $e->getMessage() );
            $this->assertStringContainsString( 'different content', $e->getMessage() );
        }
        $this->assertSame( 'somebody else\'s notes', file_get_contents( $dir . '/05-controls.md' ) );
        $this->assertSame( [ $dir . '/05-controls.md' ], glob( $dir . '/*' ), 'nothing else was written, even the files before the clash' );

        $root = dirname( __DIR__ );
        [ $code, $out ] = $this->runScript( [ 'hairline-dashed', 'tech-mono', '--out', $dir ], $root );
        $this->assertSame( 2, $code, $out );
        $this->assertStringContainsString( 'different content', $out );
        $this->assertSame( 'somebody else\'s notes', file_get_contents( $dir . '/05-controls.md' ) );

        // Identical content may be rewritten: a second run into a clean dir twice is fine.
        $clean = $this->tmpDir();
        Prepare::write( Prepare::files( 'hairline-dashed', 'tech-mono', 'freehand', $clean ), $clean );
        $again = Prepare::write( Prepare::files( 'hairline-dashed', 'tech-mono', 'freehand', $clean ), $clean );
        $this->assertCount( 8, $again );
    }

    public function testRePreparingTheSameDirRefreshesOurFilesAndNeverTouchesTheAssistantsPage(): void
    {
        $dir = $this->tmpDir();
        // An earlier run, then a guide change (simulated by stale guide files, a stale TASK.md and a stale prepare.json of OUR format).
        $old = Prepare::files( 'hairline-dashed', 'tech-mono', 'freehand', $dir );
        Prepare::write( $old, $dir );
        file_put_contents( $dir . '/05-controls.md', 'an older guide' );
        file_put_contents( $dir . '/TASK.md', 'an older task' );
        $stale = json_decode( $old['prepare.json'], true );
        $stale['guide_bytes'] = 1;
        file_put_contents( $dir . '/prepare.json', json_encode( $stale ) );
        file_put_contents( $dir . '/page.html', 'the assistant page' );
        file_put_contents( $dir . '/foreign-notes.txt', 'mine' );

        $paths = Prepare::write( $old, $dir );
        $this->assertCount( count( $old ), $paths );
        foreach ( $old as $name => $content ) {
            $this->assertSame( $content, file_get_contents( $dir . '/' . $name ), $name );
        }
        $this->assertSame( 'the assistant page', file_get_contents( $dir . '/page.html' ) );
        $this->assertSame( 'mine', file_get_contents( $dir . '/foreign-notes.txt' ) );

        // Same through the script.
        file_put_contents( $dir . '/06-forms.md', 'an older forms topic' );
        [ $code, $out ] = $this->runScript( [ 'hairline-dashed', 'tech-mono', '--out', $dir ], dirname( __DIR__ ) );
        $this->assertSame( 0, $code, $out );
        $this->assertSame( $old['06-forms.md'], file_get_contents( $dir . '/06-forms.md' ) );
        $this->assertSame( 'the assistant page', file_get_contents( $dir . '/page.html' ) );

        // An edit dir: page.html is OURS (listed in the old files map), page-edited.html is the assistant's.
        $edir = $this->tmpDir();
        $edit = Prepare::files( 'glass-edge', 'glass-dark', 'edit', $edir );
        Prepare::write( $edit, $edir );
        file_put_contents( $edir . '/page.html', 'a stale base' );
        file_put_contents( $edir . '/page-edited.html', 'the assistant edit' );
        Prepare::write( $edit, $edir );
        $this->assertSame( $edit['page.html'], file_get_contents( $edir . '/page.html' ) );
        $this->assertSame( 'the assistant edit', file_get_contents( $edir . '/page-edited.html' ) );
    }

    public function testRePreparingAnotherKitOrLanguageIntoTheSameDirIsRefused(): void
    {
        $dir = $this->tmpDir();
        Prepare::write( Prepare::files( 'hairline-dashed', 'tech-mono', 'freehand', $dir ), $dir );
        file_put_contents( $dir . '/page.html', 'the assistant page' );
        $before = [];
        foreach ( glob( $dir . '/*' ) ?: [] as $f ) {
            $before[ basename( $f ) ] = hash_file( 'sha256', $f );
        }
        $root = dirname( __DIR__ );

        foreach ( [ [ 'glass-edge', 'tech-mono' ], [ 'hairline-dashed', 'glass-dark' ] ] as [ $kit, $language ] ) {
            try {
                Prepare::write( Prepare::files( $kit, $language, 'freehand', $dir ), $dir );
                $this->fail( 'another kit or language is refused' );
            } catch ( \RuntimeException $e ) {
                $this->assertStringContainsString( 'prepared for kit "hairline-dashed" and language "tech-mono"', $e->getMessage() );
                $this->assertStringContainsString( 'another --out', $e->getMessage() );
            }
            [ $code, $out ] = $this->runScript( [ $kit, $language, '--out', $dir ], $root );
            $this->assertSame( 2, $code, $out );
            $this->assertStringContainsString( 'prepared for kit', $out );
        }
        $after = [];
        foreach ( glob( $dir . '/*' ) ?: [] as $f ) {
            $after[ basename( $f ) ] = hash_file( 'sha256', $f );
        }
        $this->assertSame( $before, $after, 'nothing was written' );

        // the same kit and language still refreshes; the edit-into-freehand rule is unchanged
        [ $code ] = $this->runScript( [ 'hairline-dashed', 'tech-mono', '--out', $dir ], $root );
        $this->assertSame( 0, $code );
        $this->assertSame( 'the assistant page', file_get_contents( $dir . '/page.html' ) );
        $this->assertSame( 2, $this->runScript( [ 'hairline-dashed', 'tech-mono', '--task', 'edit', '--out', $dir ], $root )[0], 'an edit prepare still refuses the freehand page' );
    }

    public function testAnEditPrepareIntoAFreehandDirRefusesToOverwriteTheAssistantsPage(): void
    {
        $dir = $this->tmpDir();
        Prepare::write( Prepare::files( 'glass-edge', 'glass-dark', 'freehand', $dir ), $dir );
        file_put_contents( $dir . '/page.html', 'the assistant page' );
        file_put_contents( $dir . '/01-style-guide.md', 'older' );
        try {
            Prepare::write( Prepare::files( 'glass-edge', 'glass-dark', 'edit', $dir ), $dir );
            $this->fail( 'expected a RuntimeException' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'page.html', $e->getMessage() );
        }
        $this->assertSame( 'the assistant page', file_get_contents( $dir . '/page.html' ) );
        $this->assertSame( 'older', file_get_contents( $dir . '/01-style-guide.md' ), 'all or nothing: our older file was not refreshed either' );
    }

    public function testACorruptOrForeignPrepareJsonMakesNothingOurs(): void
    {
        $files = Prepare::files( 'hairline-dashed', 'tech-mono', 'freehand', '/x' );
        foreach ( [ 'not json', '{"kit":"a","language":"b","task":"freehand"}', '{"kit":1,"language":"b","task":"c","files":{}}', '[]', '"x"' ] as $marker ) {
            $dir = $this->tmpDir();
            mkdir( $dir, 0777, true );
            file_put_contents( $dir . '/prepare.json', $marker );
            file_put_contents( $dir . '/05-controls.md', 'foreign' );
            try {
                Prepare::write( $files, $dir );
                $this->fail( 'expected a RuntimeException for ' . $marker );
            } catch ( \RuntimeException $e ) {
                $this->assertStringContainsString( 'different content', $e->getMessage() );
            }
            $this->assertSame( $marker, file_get_contents( $dir . '/prepare.json' ) );
            $this->assertSame( 'foreign', file_get_contents( $dir . '/05-controls.md' ) );
        }
        // A foreign file is also refused in a directory that IS ours, when its name is not in the old files map.
        $dir = $this->tmpDir();
        Prepare::write( Prepare::files( 'hairline-dashed', 'tech-mono', 'freehand', $dir ), $dir );
        $this->assertSame( [], array_diff( [ 'prepare.json' ], array_map( 'basename', glob( $dir . '/*' ) ?: [] ) ) );
        $stale = json_decode( (string) file_get_contents( $dir . '/prepare.json' ), true );
        unset( $stale['files']['06-forms.md'] );
        file_put_contents( $dir . '/prepare.json', json_encode( $stale ) );
        file_put_contents( $dir . '/06-forms.md', 'foreign' );
        $this->expectException( \RuntimeException::class );
        Prepare::write( Prepare::files( 'hairline-dashed', 'tech-mono', 'freehand', $dir ), $dir );
    }

    public function testADirectoryInTheWayIsAPlainRuntimeExceptionNotAWarning(): void
    {
        $dir = $this->tmpDir();
        mkdir( $dir . '/TASK.md', 0777, true );
        try {
            Prepare::write( Prepare::files( 'hairline-dashed', 'tech-mono', 'freehand', $dir ), $dir );
            $this->fail( 'expected a RuntimeException' );
        } catch ( \RuntimeException $e ) {
            $this->assertStringContainsString( 'is a directory', $e->getMessage() );
        }
        $this->assertSame( [ $dir . '/TASK.md' ], glob( $dir . '/*' ), 'nothing was written' );
        [ $code, $out ] = $this->runScript( [ 'hairline-dashed', 'tech-mono', '--out', $dir ], dirname( __DIR__ ) );
        $this->assertSame( 2, $code, $out );
        $this->assertStringContainsString( 'is a directory', $out );
        $this->assertStringNotContainsString( 'Warning', $out );
        rmdir( $dir . '/TASK.md' );
    }

    public function testAnOptionValueThatLooksLikeAnOptionIsRefused(): void
    {
        $root = dirname( __DIR__ );
        $dir  = $this->tmpDir();
        foreach ( [ [ '--out', '--task', 'edit' ], [ '--task', '--out', $dir ] ] as $tail ) {
            [ $code, $out ] = $this->runScript( array_merge( [ 'hairline-dashed', 'tech-mono' ], $tail ), $root );
            $this->assertSame( 2, $code, implode( ' ', $tail ) . ' ' . $out );
            $this->assertStringContainsString( 'needs a value', $out );
        }
        $this->assertFalse( is_dir( '--task' ), 'no directory named after an option' );
        $this->assertFalse( is_dir( $root . '/--task' ) );
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
