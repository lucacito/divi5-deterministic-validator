<?php

declare(strict_types=1);

namespace Divi5Validator\Tests;

use AiEditorDivi5\WP\DesignLanguages;
use AiEditorDivi5\WP\SectionRecipes;
use AiEditorDivi5\WP\StudioBrief;
use AiEditorDivi5\WP\StudioCompiler;
use AiEditorDivi5\WP\StudioSlots;
use AiEditorDivi5\WP\StudioStack;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../wp-plugin/src/autoload.php';

/** Studio screen task 2: the default section stack per goal, and same-role variants. */
class StudioStackTest extends TestCase
{
    /** @return list<string> */
    private static function roles( array $stack ): array
    {
        return array_map( static fn ( string $r ): string => (string) ( SectionRecipes::tags( $r )['role'] ?? '' ), $stack );
    }

    public function testEveryGoalStackIsValid(): void
    {
        foreach ( StudioBrief::GOALS as $goal ) {
            $stack = StudioStack::forGoal( $goal );
            $this->assertNotSame( [], $stack, $goal );
            $this->assertLessThanOrEqual( StudioBrief::MAX_SECTIONS, count( $stack ), $goal );
            foreach ( $stack as $id ) {
                $this->assertContains( $id, SectionRecipes::names(), "$goal: $id exists" );
            }
            $roles = self::roles( $stack );
            $this->assertSame( 'hero', $roles[0], "$goal starts with a hero" );
            if ( in_array( $goal, [ 'sell', 'book', 'signup' ], true ) ) {
                $this->assertSame( 'cta', end( $roles ), "$goal ends with a closing call to action" );
            }
            foreach ( DesignLanguages::ids() as $language ) {
                $brief = [
                    'title'    => 'Stack ' . $goal,
                    'goal'     => $goal,
                    'language' => $language,
                    'sections' => array_map( static fn ( string $r ): array => [ 'recipe' => $r ], $stack ),
                ];
                $r = StudioCompiler::compile( $brief );
                $this->assertTrue( $r['ok'], "$goal under $language: " . json_encode( [ $r['problems'], $r['violations'] ] ) );
            }
        }
    }

    public function testVariantsShareTheRoleAndCountItems(): void
    {
        foreach ( SectionRecipes::names() as $name ) {
            $role     = SectionRecipes::tags( $name )['role'] ?? null;
            $variants = StudioStack::variants( $name );
            $this->assertContains( $name, array_column( $variants, 'recipe' ), "$name lists itself" );
            foreach ( $variants as $v ) {
                $this->assertSame( [ 'recipe', 'layout', 'items', 'needs_images' ], array_keys( $v ) );
                $this->assertSame( $role, SectionRecipes::tags( $v['recipe'] )['role'] ?? null );
                $items = 0;
                foreach ( StudioSlots::extract( (string) SectionRecipes::rawMarkup( $v['recipe'] ) ) as $s ) {
                    $items += 'item_title' === $s['role'] ? 1 : 0;
                }
                $this->assertSame( $items, $v['items'], $v['recipe'] );
                $this->assertSame( $items, StudioStack::itemCount( $v['recipe'] ) );
                $this->assertIsBool( $v['needs_images'] );
                $this->assertSame( SectionRecipes::tags( $v['recipe'] )['layout'] ?? '', $v['layout'] );
            }
        }
        $this->assertSame( [], StudioStack::variants( 'no-such-recipe' ) );
        $this->assertSame( 0, StudioStack::itemCount( 'no-such-recipe' ) );
    }

    public function testStacksDiffer(): void
    {
        $seen = [];
        foreach ( StudioBrief::GOALS as $goal ) {
            $key = implode( ',', StudioStack::forGoal( $goal ) );
            $this->assertArrayNotHasKey( $key, $seen, "$goal repeats another goal's stack" );
            $seen[ $key ] = $goal;
        }
    }

    public function testDensityOnlyReordersWithinTheSameRoles(): void
    {
        $changed = false;
        foreach ( StudioBrief::GOALS as $goal ) {
            $base = StudioStack::forGoal( $goal, 'balanced' );
            $this->assertSame( $base, StudioStack::forGoal( $goal ), 'balanced is the default' );
            foreach ( [ 'airy', 'compact' ] as $density ) {
                $stack = StudioStack::forGoal( $goal, $density );
                $this->assertSame( self::roles( $base ), self::roles( $stack ), "$goal $density keeps the roles" );
                $changed = $changed || $stack !== $base;
                $this->assertSame( $stack, StudioStack::forGoal( $goal, $density ), 'deterministic' );
            }
            $this->assertNotSame( StudioStack::forGoal( $goal, 'airy' ), StudioStack::forGoal( $goal, 'compact' ), "$goal airy differs from compact" );
        }
        $this->assertTrue( $changed );
        $this->assertSame( StudioStack::forGoal( 'sell' ), StudioStack::forGoal( 'sell', 'nonsense' ), 'an unknown density is balanced' );
        $this->assertSame( StudioStack::forGoal( 'sell', 'compact' ), StudioStack::forGoal( 'sell', 'dense' ) );
        $this->assertSame( [], StudioStack::forGoal( 'no-such-goal' ) );
    }

    public function testNoRecipeRepeatsInAStack(): void
    {
        foreach ( StudioBrief::GOALS as $goal ) {
            foreach ( [ 'airy', 'balanced', 'compact' ] as $density ) {
                $stack = StudioStack::forGoal( $goal, $density );
                $this->assertSame( $stack, array_values( array_unique( $stack ) ), "$goal $density" );
            }
        }
    }
}
