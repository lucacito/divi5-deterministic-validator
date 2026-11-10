<?php

declare(strict_types=1);

namespace AiEditorDivi5\WP;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * The one implementation of the lab_build tool (the assistant's way into Lab), called by both the MCP handler and the
 * REST controller so the two can never drift: argument checks first, then one private method per action.
 *
 * This task builds `catalog` (what the library offers) and `open` (a stored brief and what it still lacks); both only
 * read. `fill` and `build` are accepted action names that answer "not built yet" until they land.
 */
final class StudioAssistant
{
    public const ENDPOINT = 'lab_build';

    /** Keys the tool accepts. Anything else is refused by name rather than ignored. */
    private const KNOWN = [ 'action', 'recipe', 'role', 'brief_id', 'base_hash', 'sections', 'brief', 'create' ];

    private const ACTIONS = [ 'catalog', 'open', 'fill', 'build' ];

    /** A sample text is cut to this many characters. */
    private const SAMPLE_CHARS = 80;

    private const HOW = 'Lab builds a page from a short brief: a design language, a goal and an ordered list of ready-made sections, each with its words, links and pictures. '
        . 'Four actions: catalog lists the goals, languages, style kits and sections by role (add recipe to see one section\'s slots); open reads a stored brief and shows which slots are still empty; '
        . 'fill writes words into a brief; build compiles the brief into a validated draft page. '
        . 'Write only real words you were given and never invent facts, prices, names or numbers; leave a slot empty when you do not know it, because an empty slot keeps its placeholder and is reported as unfilled. '
        . 'Everything is saved as a draft that the owner reviews and publishes.';

    private const RULES = 'Fill only slots whose words you actually know. Leave the rest empty; they stay as placeholders and are listed as unfilled. '
        . 'Links are https:// addresses, mailto:, tel:, #anchors or paths on this site starting with /. Pictures are a built-in pack token or an https:// address. '
        . 'Do not invent facts. The result is always a draft.';

    /**
     * @param array<string,mixed> $args
     * @return array{status:int, body:array<string,mixed>} status: 200 ok, 400 bad input, 403 not allowed, 404 brief not found, 409 conflict, 422 brief or layout refused, 500 failure
     */
    public static function run( array $args ): array
    {
        if ( ! current_user_can( PageAccess::CAP ) ) {
            UsageTracker::log( self::ENDPOINT, null, 'error' );

            return self::fail( 403, 'You do not have permission to use Lab.' );
        }

        $unknown = array_diff( array_keys( $args ), self::KNOWN );
        if ( array() !== $unknown ) {
            return self::fail( 400, 'Unknown argument(s): ' . implode( ', ', array_map( 'strval', $unknown ) ) . '. Use: ' . implode( ', ', self::KNOWN ) . '.' );
        }

        $action   = PageAccess::stringArg( $args, 'action' );
        $recipe   = PageAccess::stringArg( $args, 'recipe' );
        $role     = PageAccess::stringArg( $args, 'role' );
        $baseHash = PageAccess::stringArg( $args, 'base_hash' );
        $create   = PageAccess::boolArg( $args, 'create' );
        if ( null === $action || null === $recipe || null === $role || null === $baseHash ) {
            return self::fail( 400, 'action, recipe, role and base_hash must be strings.' );
        }
        if ( null === $create ) {
            return self::fail( 400, 'create must be a boolean (true or false).' );
        }
        foreach ( [ 'sections', 'brief' ] as $key ) {
            if ( array_key_exists( $key, $args ) && null !== $args[ $key ] && ! is_array( $args[ $key ] ) ) {
                return self::fail( 400, $key . ' must be ' . ( 'sections' === $key ? 'a list' : 'an object' ) . '.' );
            }
        }
        $briefId = null;
        if ( array_key_exists( 'brief_id', $args ) && null !== $args['brief_id'] ) {
            if ( ! is_int( $args['brief_id'] ) || $args['brief_id'] < 1 ) {
                return self::fail( 400, 'brief_id must be a whole number of 1 or more.' );
            }
            $briefId = $args['brief_id'];
        }
        if ( '' === $action ) {
            return self::fail( 400, 'action is required. Use one of: ' . implode( ', ', self::ACTIONS ) . '.' );
        }
        if ( ! in_array( $action, self::ACTIONS, true ) ) {
            return self::fail( 400, 'Unknown action. Use one of: ' . implode( ', ', self::ACTIONS ) . '.' );
        }

        switch ( $action ) {
            case 'catalog':
                return self::catalog( $recipe, $role );
            case 'open':
                return self::open( $briefId );
            default:
                return self::fail( 400, 'This action is not built yet.' );
        }
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private static function catalog( string $recipe, string $role ): array
    {
        if ( '' !== $recipe ) {
            if ( ! in_array( $recipe, SectionRecipes::names(), true ) ) {
                return self::fail( 400, 'Unknown recipe "' . SectionRecipes::plain( $recipe ) . '". Call catalog without recipe to list the names.' );
            }
            UsageTracker::log( self::ENDPOINT, null, 'read' );

            return [ 'status' => 200, 'body' => [
                'recipe' => $recipe,
                'role'   => self::roleOf( $recipe ),
                'slots'  => self::slotList( $recipe ),
            ] ];
        }

        $recipes = [];
        foreach ( SectionRecipes::names() as $name ) {
            $recipes[ self::roleOf( $name ) ][] = $name;
        }
        if ( '' !== $role ) {
            if ( ! isset( $recipes[ $role ] ) ) {
                return self::fail( 400, 'Unknown role "' . SectionRecipes::plain( $role ) . '". Use one of: ' . implode( ', ', array_keys( $recipes ) ) . '.' );
            }
            $recipes = [ $role => $recipes[ $role ] ];
        }
        $languages = [];
        foreach ( DesignLanguages::ids() as $id ) {
            $languages[] = [ 'id' => $id, 'name' => (string) ( DesignLanguages::get( $id )['title'] ?? $id ) ];
        }
        UsageTracker::log( self::ENDPOINT, null, 'read' );

        return [ 'status' => 200, 'body' => [
            'goals'     => StudioBrief::GOALS,
            'languages' => $languages,
            'kits'      => StyleKits::ids(),
            'recipes'   => $recipes,
            'how'       => self::HOW,
        ] ];
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private static function open( ?int $briefId ): array
    {
        if ( null === $briefId ) {
            return self::fail( 400, 'brief_id is required for open.' );
        }
        $stored = StudioStore::get( get_current_user_id(), $briefId );
        if ( null === $stored ) {
            UsageTracker::log( self::ENDPOINT, null, 'error' );

            return self::fail( 404, 'Brief not found.' );
        }

        $brief    = $stored['brief'];
        $unfilled = 0;
        $sections = [];
        foreach ( (array) ( $brief['sections'] ?? [] ) as $i => $section ) {
            $section = (array) $section;
            $name    = (string) ( $section['recipe'] ?? '' );
            $words   = (array) ( $section['slots'] ?? [] );
            $images  = (array) ( $section['pictures'] ?? [] );
            $slots   = [];
            foreach ( self::slotList( $name ) as $slot ) {
                $value  = 'picture' === $slot['kind'] ? ( $images[ $slot['id'] ] ?? '' ) : ( $words[ $slot['id'] ] ?? '' );
                $filled = '' !== $value && [] !== $value;
                $unfilled += $filled ? 0 : 1;
                $slots[]   = $slot + [ 'value' => $value, 'filled' => $filled ];
            }
            $sections[] = [ 'section' => (int) $i, 'recipe' => $name, 'slots' => $slots ];
        }

        $page = null;
        if ( null !== $stored['page_id'] ) {
            $post = get_post( $stored['page_id'] );
            if ( $post ) {
                $page = [ 'id' => $stored['page_id'], 'status' => (string) ( $post->post_status ?? '' ) ];
            }
        }
        UsageTracker::log( self::ENDPOINT, $stored['page_id'], 'read' );

        return [ 'status' => 200, 'body' => [
            'brief_id'       => $stored['id'],
            'base_hash'      => $stored['brief_hash'],
            'brief'          => $brief,
            'sections'       => $sections,
            'unfilled_count' => $unfilled,
            'page'           => $page,
            'rules'          => self::RULES,
        ] ];
    }

    private static function roleOf( string $recipe ): string
    {
        $role = (string) ( SectionRecipes::tags( $recipe )['role'] ?? '' );

        return '' === $role ? 'other' : $role;
    }

    /** @return list<array{id:string, kind:string, role:string, sample:string}> */
    private static function slotList( string $recipe ): array
    {
        $out = [];
        foreach ( StudioSlots::extract( (string) SectionRecipes::rawMarkup( $recipe ) ) as $slot ) {
            $sample = 'picture' === $slot['kind'] ? '' : mb_substr( trim( wp_strip_all_tags( (string) $slot['sample'] ) ), 0, self::SAMPLE_CHARS );
            $out[]  = [ 'id' => $slot['id'], 'kind' => $slot['kind'], 'role' => $slot['role'], 'sample' => $sample ];
        }

        return $out;
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private static function fail( int $status, string $message ): array
    {
        return [ 'status' => $status, 'body' => [ 'error' => $message ] ];
    }
}
