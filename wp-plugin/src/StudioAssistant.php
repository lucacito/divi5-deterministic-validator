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
 * `catalog` (what the library offers) and `open` (a stored brief and what it still lacks) only read. `fill` writes words
 * into a stored brief, guarded by the brief's hash so it can never overwrite what a person saved in the meantime.
 * `build` is an accepted action name that answers "not built yet" until it lands.
 */
final class StudioAssistant
{
    public const ENDPOINT = 'lab_build';

    /** Keys the tool accepts. Anything else is refused by name rather than ignored. */
    private const KNOWN = [ 'action', 'recipe', 'role', 'brief_id', 'base_hash', 'sections', 'brief', 'create' ];

    private const ACTIONS = [ 'catalog', 'open', 'fill', 'build' ];

    /** A sample text is cut to this many characters. */
    private const SAMPLE_CHARS = 80;

    /** A hash is 64 lowercase hex characters (sha256). */
    private const HASH_PATTERN = '/^[0-9a-f]{64}$/D';

    private const HOW = 'Lab builds a page from a short brief: a design language, a goal and an ordered list of ready-made sections, each with its words, links and pictures. '
        . 'Four actions: catalog lists the goals, languages, style kits and sections by role (add recipe to see one section\'s slots); open reads a stored brief, returns its base_hash and shows which slots are still empty; '
        . 'fill writes words into a brief (send brief_id, the base_hash from open and sections); build previews or creates a validated draft page from a brief. '
        . 'If fill answers 409 CONFLICT, the person changed the brief meanwhile and nothing was written: open again and fill again with the new base_hash. '
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
        if ( array_key_exists( 'sections', $args ) && null !== $args['sections'] ) {
            if ( ! is_array( $args['sections'] ) || ! self::isList( $args['sections'] ) ) {
                return self::fail( 400, 'sections must be a list of objects.' );
            }
            foreach ( $args['sections'] as $entry ) {
                if ( ! is_array( $entry ) ) {
                    return self::fail( 400, 'sections must be a list of objects.' );
                }
            }
        }
        // `brief` is only used by build (a later task): it must be an object.
        if ( array_key_exists( 'brief', $args ) && null !== $args['brief'] && ( ! is_array( $args['brief'] ) || ( array() !== $args['brief'] && self::isList( $args['brief'] ) ) ) ) {
            return self::fail( 400, 'brief must be an object.' );
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
            case 'fill':
                // `create` is a known key, but fill does not act on it yet: Task 5 makes `create:true` build the page after
                // the words are saved (and checks publish_pages). Until then it is accepted and ignored.
                return self::fill( $briefId, $baseHash, $args['sections'] ?? null );
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

        $brief = $stored['brief'];
        $view  = self::sectionView( $brief );
        $page  = self::pageOf( $stored['page_id'] );
        UsageTracker::log( self::ENDPOINT, null === $page ? null : $page['id'], 'read' );

        return [ 'status' => 200, 'body' => [
            'brief_id'       => $stored['id'],
            'base_hash'      => $stored['brief_hash'],
            'brief'          => $brief,
            'sections'       => $view['sections'],
            'unfilled_count' => $view['unfilled'],
            'page'           => $page,
            'rules'          => self::RULES,
        ] ];
    }

    /**
     * Write words (and pictures) into sections of a stored brief. Everything is checked before anything is written; the
     * one write is a compare-and-set on the brief's hash, so a brief the person saved meanwhile is never overwritten.
     *
     * @param mixed $sections the raw `sections` argument (already known to be a list of arrays, or null)
     * @return array{status:int, body:array<string,mixed>}
     */
    private static function fill( ?int $briefId, string $baseHash, mixed $sections ): array
    {
        if ( null === $briefId ) {
            return self::fail( 400, 'brief_id is required for fill.' );
        }
        if ( 1 !== preg_match( self::HASH_PATTERN, $baseHash ) ) {
            return self::fail( 400, 'base_hash is required for fill: the 64-character base_hash that open returned.' );
        }
        $entries = self::fillEntries( $sections );
        if ( is_string( $entries ) ) {
            return self::fail( 400, $entries );
        }

        $user   = get_current_user_id();
        $stored = StudioStore::get( $user, $briefId );
        if ( null === $stored ) {
            UsageTracker::log( self::ENDPOINT, null, 'error' );

            return self::fail( 404, 'Brief not found.' );
        }
        $brief = $stored['brief'];
        $count = count( (array) ( $brief['sections'] ?? [] ) );
        foreach ( $entries as $entry ) {
            if ( $entry['section'] >= $count ) {
                return self::fail( 400, 'Section ' . $entry['section'] . ' does not exist: this brief has ' . $count . ' section(s), numbered from 0.' );
            }
        }

        $applied = [];
        $ignored = [];
        foreach ( $entries as $entry ) {
            $merged  = StudioMerge::slots( $brief, $entry['section'], $entry['incoming'] );
            $brief   = $merged['brief'];
            foreach ( $merged['applied'] as $id ) {
                $applied[] = [ 'section' => $entry['section'], 'slot' => $id ];
            }
            foreach ( $merged['ignored'] as $id ) {
                $ignored[] = [ 'section' => $entry['section'], 'slot' => $id ];
            }
        }

        if ( array() === $applied ) {
            // Nothing could be written: do not touch the row (a pointless save would change the hash and make the person's
            // open screen see a conflict), but still refuse a stale hash so the assistant learns it is out of date.
            if ( ! hash_equals( $stored['brief_hash'], $baseHash ) ) {
                return self::conflict( $stored['brief_hash'] );
            }
            $fresh = $stored;
        } else {
            $checked = StudioBrief::check( $brief );
            if ( ! $checked['ok'] ) {
                UsageTracker::log( self::ENDPOINT, null, 'invalid' );

                return [ 'status' => 422, 'body' => [
                    'code'     => StudioService::INVALID_BRIEF,
                    'error'    => 'The brief would have problems with these words; nothing was saved. See problems.',
                    'problems' => array_slice( array_map( static fn ( array $p ): array => [
                        'path'    => (string) ( $p['path'] ?? '' ),
                        'code'    => (string) ( $p['code'] ?? '' ),
                        'message' => (string) ( $p['message'] ?? '' ),
                    ], $checked['problems'] ), 0, StudioBrief::MAX_PROBLEMS ),
                ] ];
            }
            $saved = StudioStore::save( $user, (array) $checked['brief'], $briefId, $baseHash );
            if ( ! $saved['ok'] ) {
                UsageTracker::log( self::ENDPOINT, null, 'error' );
                if ( StudioStore::CONFLICT === $saved['code'] ) {
                    $current = StudioStore::get( $user, $briefId );

                    return self::conflict( null === $current ? '' : $current['brief_hash'] );
                }
                if ( StudioStore::NOT_FOUND === $saved['code'] ) {
                    return self::fail( 404, 'Brief not found.' );
                }
                if ( StudioStore::TOO_LARGE === $saved['code'] ) {
                    return self::fail( 422, 'The brief would be too large; nothing was saved.' );
                }

                return self::fail( 500, 'The brief could not be saved.' );
            }
            $fresh = StudioStore::get( $user, $briefId );
            if ( null === $fresh ) {
                return self::fail( 500, 'The brief could not be read back.' );
            }
        }
        $view    = self::sectionView( $fresh['brief'] );
        $preview = StudioService::preview( $user, $fresh['brief'] );
        $page    = self::pageOf( $fresh['page_id'] );
        UsageTracker::log( self::ENDPOINT, null === $page ? null : $page['id'], 'valid' );

        return [ 'status' => 200, 'body' => [
            'brief_id'       => $fresh['id'],
            'base_hash'      => $fresh['brief_hash'],
            'applied'        => $applied,
            'ignored'        => $ignored,
            'unfilled_count' => $view['unfilled'],
            'report'         => $preview['report'],
            'page'           => $page,
            'message'        => 'Saved ' . count( $applied ) . ' slot(s)' . ( array() === $ignored ? '' : '; ' . count( $ignored ) . ' could not be used (see ignored)' ) . '. '
                . $view['unfilled'] . ' slot(s) are still empty. The page itself was not changed.',
        ] ];
    }

    /**
     * Shape-check the `sections` argument of fill.
     *
     * @return list<array{section:int, incoming:array<string,mixed>}>|string the entries, or the 400 message
     */
    private static function fillEntries( mixed $sections ): array|string
    {
        if ( ! is_array( $sections ) || array() === $sections ) {
            return 'sections is required for fill: a list of 1 to ' . StudioBrief::MAX_SECTIONS . ' objects like {"section":0,"slots":{...},"pictures":{...}}.';
        }
        if ( count( $sections ) > StudioBrief::MAX_SECTIONS ) {
            return 'At most ' . StudioBrief::MAX_SECTIONS . ' sections per call.';
        }
        $seen = [];
        $out  = [];
        foreach ( $sections as $entry ) {
            if ( ! is_array( $entry ) || ( array() !== $entry && self::isList( $entry ) ) ) {
                return 'Each entry of sections must be an object like {"section":0,"slots":{...},"pictures":{...}}.';
            }
            $unknown = array_diff( array_keys( $entry ), [ 'section', 'slots', 'pictures' ] );
            if ( array() !== $unknown ) {
                return 'Unknown key in a sections entry: ' . implode( ', ', array_map( 'strval', $unknown ) ) . '. Use section, slots, pictures.';
            }
            if ( ! isset( $entry['section'] ) || ! is_int( $entry['section'] ) || $entry['section'] < 0 ) {
                return 'Each sections entry needs "section": a whole number of 0 or more.';
            }
            if ( isset( $seen[ $entry['section'] ] ) ) {
                return 'Section ' . $entry['section'] . ' appears twice; send each section once.';
            }
            $seen[ $entry['section'] ] = true;
            $incoming = [];
            foreach ( [ 'slots', 'pictures' ] as $key ) {
                if ( ! array_key_exists( $key, $entry ) ) {
                    continue;
                }
                if ( ! is_array( $entry[ $key ] ) || ( array() !== $entry[ $key ] && self::isList( $entry[ $key ] ) ) ) {
                    return $key . ' must be an object of slot id => value.';
                }
                foreach ( $entry[ $key ] as $id => $value ) {
                    if ( array_key_exists( (string) $id, $incoming ) ) {
                        return 'Slot "' . SectionRecipes::plain( (string) $id ) . '" appears in both slots and pictures.';
                    }
                    $incoming[ (string) $id ] = $value;
                }
            }
            if ( array() === $incoming ) {
                return 'Section ' . $entry['section'] . ' has no slots or pictures to write.';
            }
            $out[] = [ 'section' => $entry['section'], 'incoming' => $incoming ];
        }

        return $out;
    }

    /**
     * Every section of a brief with its slots, and how many slots are still empty.
     *
     * @param array<string,mixed> $brief
     * @return array{sections:list<array<string,mixed>>, unfilled:int}
     */
    private static function sectionView( array $brief ): array
    {
        $unfilled = 0;
        $sections = [];
        foreach ( (array) ( $brief['sections'] ?? [] ) as $i => $section ) {
            $section  = (array) $section;
            $name     = (string) ( $section['recipe'] ?? '' );
            $resolved = StudioBrief::resolveRecipe( $name );
            $words    = (array) ( $section['slots'] ?? [] );
            $images   = (array) ( $section['pictures'] ?? [] );
            $slots    = [];
            foreach ( null === $resolved ? [] : self::slotList( $resolved ) as $slot ) {
                $value  = 'picture' === $slot['kind'] ? ( $images[ $slot['id'] ] ?? '' ) : ( $words[ $slot['id'] ] ?? '' );
                $filled = '' !== $value && [] !== $value;
                $unfilled += $filled ? 0 : 1;
                $slots[]   = $slot + [ 'value' => $value, 'filled' => $filled ];
            }
            $entry = [ 'section' => (int) $i, 'recipe' => $name, 'slots' => $slots ];
            if ( null === $resolved ) {
                $entry['recipe_missing'] = true;
            }
            $sections[] = $entry;
        }

        return [ 'sections' => $sections, 'unfilled' => $unfilled ];
    }

    /**
     * The linked page, only when it exists and the current user may edit it (a page someone else wrote must not leak its
     * id or status through a brief that happens to link to it).
     *
     * @return array{id:int,status:string}|null
     */
    private static function pageOf( ?int $pageId ): ?array
    {
        if ( null === $pageId || ! current_user_can( 'edit_post', $pageId ) ) {
            return null;
        }
        $post = get_post( $pageId );

        return $post ? [ 'id' => $pageId, 'status' => (string) ( $post->post_status ?? '' ) ] : null;
    }

    /** True for an array whose keys are 0..n-1 (array_is_list needs WordPress 6.5). */
    private static function isList( array $a ): bool
    {
        return array_keys( $a ) === range( 0, count( $a ) - 1 ) || array() === $a;
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
    private static function conflict( string $currentHash ): array
    {
        return [ 'status' => 409, 'body' => [
            'code'              => StudioStore::CONFLICT,
            'current_base_hash' => $currentHash,
            'message'           => 'The brief was changed after you opened it, so nothing was written. Open it again and fill again with the new base_hash.',
        ] ];
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private static function fail( int $status, string $message ): array
    {
        return [ 'status' => $status, 'body' => [ 'error' => $message ] ];
    }
}
